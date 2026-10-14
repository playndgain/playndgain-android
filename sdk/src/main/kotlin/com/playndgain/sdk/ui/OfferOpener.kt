package com.playndgain.sdk.ui

import android.annotation.SuppressLint
import android.content.Context
import android.os.Handler
import android.os.Looper
import android.webkit.CookieManager
import android.webkit.RenderProcessGoneDetail
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import com.playndgain.sdk.internal.Responses
import com.playndgain.sdk.internal.StoreLink
import java.util.Locale

/**
 * What following a click found. The packages and URLs are for the caller to
 * act on only: past the wall's own host they belong to a partner, so they are
 * never sent to the listener, logged or shown.
 */
internal sealed interface OfferOutcome {
    /** A Play listing: open the Play Store on [pkg]. */
    data class Store(val pkg: String) : OfferOutcome

    /** Any other app link (a deep link or `intent://`): hand it to the system. */
    data class App(val url: String) : OfferOutcome

    /** A web page finished loading and stayed: reveal the web view. */
    data object Landing : OfferOutcome

    /**
     * Nothing settled in time: the cover says it is still opening. The click
     * keeps resolving; a page is only shown once it has settled.
     */
    data object Timeout : OfferOutcome

    /** Nothing usable loaded: the wall's error path. */
    data object Failed : OfferOutcome
}

/**
 * The decisions behind [OfferOpener], without a web view, so the JVM tests
 * can drive every navigation. It only ever allows or cancels what the page
 * asks for; it has no way to start a load, which is what keeps `/click` to
 * one request.
 */
internal class OfferResolution {
    internal enum class Phase {
        /** Following the chain, web view not yet visible. */
        RESOLVING,
        /** A settled landing: the user sees the web view and browses. */
        REVEALED,
        /** Resolved to the store, an app, a failure, or cancelled. */
        DONE,
    }

    internal sealed interface Step {
        data object Allow : Step
        data object Cancel : Step
        /** Cancel, and this is the one resolution of the click. */
        data class Resolve(val outcome: OfferOutcome) : Step
        /** Cancel: a store or app link tapped in the revealed page. */
        data class Intercept(val outcome: OfferOutcome) : Step
    }

    internal sealed interface Kind {
        data object Web : Kind
        data object Quiet : Kind
        data object Blocked : Kind
        data class Store(val pkg: String) : Kind
        data class App(val url: String) : Kind
    }

    var phase: Phase = Phase.RESOLVING
        private set
    var committed: Boolean = false
        private set

    /**
     * Main-frame web navigations so far. A settle wait carries the count it
     * started at, and any later navigation makes it stale.
     */
    private var navigations = 0

    /** Resolved to an app link and waiting to hear whether the system opened it. */
    private var awaitingApp = false

    fun navigate(url: String, mainFrame: Boolean): Step {
        if (phase == Phase.DONE) return Step.Cancel
        return when (val k = classify(url)) {
            Kind.Quiet -> Step.Allow
            Kind.Blocked -> Step.Cancel
            Kind.Web -> {
                if (mainFrame) navigations++
                Step.Allow
            }
            is Kind.Store -> conclude(OfferOutcome.Store(k.pkg))
            // A frame probing for installed apps is not the offer.
            is Kind.App -> if (mainFrame) conclude(OfferOutcome.App(k.url)) else Step.Cancel
        }
    }

    /** The main frame showed its first content. */
    fun didCommit() {
        committed = true
    }

    /**
     * The main frame finished loading. Returns the ticket to pass to
     * [settled] after a short wait, or null when nothing is pending.
     */
    fun didFinish(): Int? = if (phase == Phase.RESOLVING) navigations else null

    /**
     * The wait after a finished page ran out. Still on that page: it is the
     * landing. A redirect since then made the ticket stale.
     */
    fun settled(ticket: Int): OfferOutcome? {
        if (phase != Phase.RESOLVING || ticket != navigations) return null
        phase = Phase.REVEALED
        return OfferOutcome.Landing
    }

    /**
     * A main-frame load error. Failed even after an earlier page: the web
     * view's error page would print a partner's address. Once revealed, the
     * page is the user's.
     */
    fun didFail(): OfferOutcome? {
        if (phase != Phase.RESOLVING) return null
        phase = Phase.DONE
        return OfferOutcome.Failed
    }

    /**
     * The wait ran out with nothing settled. The phase does not change: a
     * store, an app or a settled page after this still resolves the click,
     * and each wait that runs out is reported again.
     */
    fun timedOut(): OfferOutcome? = if (phase == Phase.RESOLVING) OfferOutcome.Timeout else null

    /**
     * The system could not open the app link the click resolved to: show
     * what has loaded, if anything did. Null when the link was tapped in an
     * already revealed page, which simply stays.
     */
    fun appUnopened(): OfferOutcome? {
        if (phase != Phase.DONE || !awaitingApp) return null
        awaitingApp = false
        phase = if (committed) Phase.REVEALED else Phase.DONE
        return if (committed) OfferOutcome.Landing else OfferOutcome.Failed
    }

    fun cancel() {
        phase = Phase.DONE
        awaitingApp = false
    }

    private fun conclude(outcome: OfferOutcome): Step {
        if (phase == Phase.REVEALED) return Step.Intercept(outcome)
        phase = Phase.DONE
        awaitingApp = outcome is OfferOutcome.App
        return Step.Resolve(outcome)
    }

    internal companion object {
        fun classify(url: String): Kind {
            val scheme = url.substringBefore(':', "").trim().lowercase(Locale.ROOT)
            if (scheme.isEmpty()) return Kind.Quiet
            StoreLink.playPackage(url)?.let { return Kind.Store(it) }
            return when (scheme) {
                "https", "http" -> Kind.Web
                // Frames and scripts use these without leaving the page.
                "about", "data", "blob", "javascript" -> Kind.Quiet
                "file", "content" -> Kind.Blocked
                else -> Kind.App(url)
            }
        }
    }
}

/**
 * Follows one offer click inside the app. Loads the allowed click URL once in
 * a web view that is not shown, watches every navigation, and reports where
 * it ended: the store, another app, a web landing or a failure, and says when
 * a wait ran out first. The caller shows [webView] itself for a landing, never
 * a Custom Tab or browser: that would request the click chain again in another
 * cookie jar. The caller attaches [webView] (invisible) before [start] and
 * destroys it when done.
 *
 * Main thread only. [onResolve] resolves the click once unless the opener is
 * cancelled first; after an [OfferOutcome.App] it may be called once more,
 * from [appUnopened]. Before that it hears [OfferOutcome.Timeout] for each
 * wait that runs out. [onIntercept] is called for each store or app link the
 * user follows from a revealed page.
 */
@SuppressLint("SetJavaScriptEnabled") // Trackers redirect with script; no JS bridge is added.
internal class OfferOpener(
    context: Context,
    private val timeoutMs: Long = 8_000,
    private val settleMs: Long = 1_500,
    private val onResolve: (OfferOutcome) -> Unit,
    private val onIntercept: (OfferOutcome) -> Unit,
) {
    val webView: WebView = WebView(context)
    val phase: OfferResolution.Phase get() = resolution.phase

    private val resolution = OfferResolution()
    private val handler = Handler(Looper.getMainLooper())
    private val timeout = Runnable { resolution.timedOut()?.let(onResolve) }
    private var started = false

    /** The revealed page's renderer died: the caller drops this web view. */
    var onGone: (() -> Unit)? = null

    init {
        webView.settings.javaScriptEnabled = true
        webView.settings.domStorageEnabled = true
        // `target=_blank` and `window.open` stay in this one web view.
        webView.settings.setSupportMultipleWindows(false)
        // Chains set cookies on more than one host; a revealed page needs them.
        CookieManager.getInstance().setAcceptThirdPartyCookies(webView, true)
        webView.webViewClient = Client()
    }

    /**
     * The one load of the click. Returns false, and loads nothing, for a
     * second call or a URL that is not the wall's own click route.
     */
    fun start(url: String, base: String): Boolean {
        if (started || resolution.phase != OfferResolution.Phase.RESOLVING || !Responses.clickAllowed(url, base)) return false
        started = true
        handler.postDelayed(timeout, timeoutMs)
        webView.loadUrl(url)
        return true
    }

    /**
     * The player chose to keep waiting after [OfferOutcome.Timeout]: one more
     * wait on the same load. Never loads anything.
     */
    fun keepWaiting() {
        if (!started || resolution.phase != OfferResolution.Phase.RESOLVING) return
        handler.removeCallbacks(timeout)
        handler.postDelayed(timeout, timeoutMs)
    }

    /** The system refused the app link from [OfferOutcome.App]: fall back to the page. */
    fun appUnopened() {
        if (!started) return
        resolution.appUnopened()?.let(onResolve)
    }

    /** Stops everything. Nothing is reported after this. */
    fun cancel() {
        handler.removeCallbacksAndMessages(null)
        resolution.cancel()
        webView.stopLoading()
    }

    private fun resolved(o: OfferOutcome) {
        handler.removeCallbacks(timeout)
        // Store, app and failure end the chain here; the page is never shown.
        if (resolution.phase == OfferResolution.Phase.DONE) webView.stopLoading()
        onResolve(o)
    }

    /** True cancels the navigation. Callbacks run after WebView has its answer. */
    private fun navigate(url: String, mainFrame: Boolean): Boolean {
        val step = resolution.navigate(url, mainFrame)
        when (step) {
            is OfferResolution.Step.Resolve -> handler.post { resolved(step.outcome) }
            is OfferResolution.Step.Intercept -> handler.post { onIntercept(step.outcome) }
            else -> Unit
        }
        return step != OfferResolution.Step.Allow
    }

    private fun failed() {
        resolution.didFail()?.let(::resolved)
    }

    private inner class Client : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean =
            navigate(request.url.toString(), request.isForMainFrame)

        // API 23 calls only this one, for the main frame.
        @Deprecated("Replaced by the WebResourceRequest overload from API 24")
        override fun shouldOverrideUrlLoading(view: WebView, url: String): Boolean = navigate(url, true)

        override fun onPageCommitVisible(view: WebView, url: String) {
            resolution.didCommit()
        }

        override fun onPageFinished(view: WebView, url: String) {
            val ticket = resolution.didFinish() ?: return
            handler.postDelayed({ resolution.settled(ticket)?.let(::resolved) }, settleMs)
        }

        // An HTTP error status still shows its page (the wall's own notices
        // are 4xx), so only a load that failed outright counts.
        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            if (!request.isForMainFrame) return
            // A redirect to `market://` or an app scheme can land here instead
            // of in shouldOverrideUrlLoading: it is still where the chain ended.
            val url = request.url.toString()
            when (OfferResolution.classify(url)) {
                is OfferResolution.Kind.Store, is OfferResolution.Kind.App -> navigate(url, true)
                else -> failed()
            }
        }

        // Handled, so the host app is not taken down with the renderer. The
        // caller drops this web view on Failed, or on [onGone] once revealed.
        override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail): Boolean {
            val revealed = resolution.phase == OfferResolution.Phase.REVEALED
            failed()
            if (revealed) onGone?.invoke()
            return true
        }
    }
}
