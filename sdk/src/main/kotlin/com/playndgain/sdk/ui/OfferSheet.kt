package com.playndgain.sdk.ui

import android.annotation.SuppressLint
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Intent
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup.LayoutParams.MATCH_PARENT
import android.view.ViewGroup.LayoutParams.WRAP_CONTENT
import android.webkit.WebView
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.TextView
import com.playndgain.sdk.internal.StoreLink
import java.net.URISyntaxException

/** How one click ended, for the wall's reward check. */
internal enum class OfferFlowEnd {
    /** The player closed the in-app browser sheet: check now. */
    RETURNED,
    /** The click left the app: the check waits for onResume. */
    LEFT_APP,
    /** Nothing loaded: the wall stays and says the offer could not be opened. */
    FAILED,
    /** Back while the click was still resolving: nothing was shown. */
    CANCELLED,
}

/**
 * One offer click inside the app: a loading cover while [OfferOpener] follows
 * the click, then the Play Store, another app, or the opener's own web view
 * as a browser sheet. Never a Custom Tab or browser for the click: that would
 * request the click chain again in another cookie jar.
 *
 * `leaving(true)` comes just before the click leaves the app, `leaving(false)`
 * if the system then refuses it. `ended` is called once.
 */
internal class OfferFlow(
    private val activity: Activity,
    private val root: FrameLayout,
    private val palette: Palette,
    private val fonts: Fonts,
    private val closeLabel: String,
    private val leaving: (Boolean) -> Unit,
    private val ended: (OfferFlowEnd) -> Unit,
) {
    private var opener: OfferOpener? = null
    private var sheet: OfferSheet? = null
    private var finished = false

    fun start(url: String, base: String): Boolean {
        if (opener != null) return false
        val o = OfferOpener(activity, onResolve = ::resolved, onIntercept = ::intercepted)
        o.onGone = { finish(OfferFlowEnd.RETURNED) }
        val s = OfferSheet(activity, o.webView, palette, fonts, closeLabel) { finish(OfferFlowEnd.RETURNED) }
        root.addView(s, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        opener = o
        sheet = s
        if (!o.start(url, base)) {
            finished = true
            o.cancel()
            s.remove(animated = false)
            return false
        }
        return true
    }

    /** Back walks the page's history, then closes. While resolving it cancels. */
    fun handleBack(): Boolean {
        val s = sheet ?: return false
        if (finished) return false
        when {
            !s.revealed -> finish(OfferFlowEnd.CANCELLED)
            s.webView.canGoBack() -> s.webView.goBack()
            else -> finish(OfferFlowEnd.RETURNED)
        }
        return true
    }

    /** The activity is going away: drop the web view without reporting. */
    fun destroy() {
        finished = true
        opener?.cancel()
        sheet?.remove(animated = false)
    }

    private fun resolved(o: OfferOutcome) {
        when (o) {
            is OfferOutcome.Store -> {
                leaving(true)
                if (openStore(o.pkg)) finish(OfferFlowEnd.LEFT_APP) else { leaving(false); finish(OfferFlowEnd.FAILED) }
            }
            is OfferOutcome.App -> {
                leaving(true)
                if (openApp(o.url)) finish(OfferFlowEnd.LEFT_APP) else { leaving(false); opener?.appUnopened() }
            }
            OfferOutcome.Landing, OfferOutcome.Timeout -> sheet?.reveal()
            OfferOutcome.Failed -> finish(OfferFlowEnd.FAILED)
        }
    }

    /** A store or app link tapped in the revealed page. The sheet stays under it. */
    private fun intercepted(o: OfferOutcome) {
        when (o) {
            is OfferOutcome.Store -> openStore(o.pkg)
            is OfferOutcome.App -> openApp(o.url)
            else -> Unit
        }
    }

    /** The Play Store app; without it, the same listing on the web. */
    private fun openStore(pkg: String): Boolean =
        launch(Intent(Intent.ACTION_VIEW, Uri.parse(StoreLink.marketUri(pkg))).setPackage(StoreLink.PLAY_STORE_PACKAGE)) ||
            launch(Intent(Intent.ACTION_VIEW, Uri.parse(StoreLink.webUri(pkg))).addCategory(Intent.CATEGORY_BROWSABLE))

    /**
     * A deep link, or an `intent://` reduced to what a browser would allow:
     * no explicit component or selector, browsable only.
     */
    private fun openApp(url: String): Boolean {
        val intent = try {
            if (url.startsWith("intent:", ignoreCase = true)) {
                Intent.parseUri(url, Intent.URI_INTENT_SCHEME).apply { component = null; selector = null }
            } else {
                Intent(Intent.ACTION_VIEW, Uri.parse(url))
            }
        } catch (_: URISyntaxException) {
            return false
        }
        return launch(intent.addCategory(Intent.CATEGORY_BROWSABLE))
    }

    /** startActivity and catch, rather than resolveActivity: no `<queries>` needed. */
    private fun launch(intent: Intent): Boolean = try {
        activity.startActivity(intent)
        true
    } catch (_: ActivityNotFoundException) {
        false
    } catch (_: SecurityException) {
        false
    }

    private fun finish(end: OfferFlowEnd) {
        if (finished) return
        finished = true
        opener?.cancel()
        sheet?.remove(animated = true)
        ended(end)
    }
}

/**
 * The loading cover and the in-app browser sheet for one click, as one view
 * over the wall. While the click resolves, the web view sits in the sheet at
 * almost zero opacity under the cover: attached and drawn, so the page keeps
 * running the timers its redirects use.
 */
@SuppressLint("ViewConstructor") // Built in code for one click, never inflated.
internal class OfferSheet(
    activity: Activity,
    val webView: WebView,
    private val palette: Palette,
    fonts: Fonts,
    closeLabel: String,
    onClose: () -> Unit,
) : FrameLayout(activity) {
    var revealed: Boolean = false
        private set

    private val panel = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
    private val cover = FrameLayout(activity)

    init {
        val p = palette
        // The scrim the wall's other sheets use; it also keeps taps off the wall.
        setBackgroundColor(Color.argb(140, 0, 0, 0))
        isClickable = true

        val r = context.dp(p.radiusDp * 1.8f).toFloat()
        panel.background = GradientDrawable().apply { setColor(p.card); cornerRadii = floatArrayOf(r, r, r, r, 0f, 0f, 0f, 0f) }
        panel.clipToOutline = true
        panel.alpha = HIDDEN_ALPHA
        panel.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO_HIDE_DESCENDANTS

        val header = LinearLayout(activity).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.END or Gravity.CENTER_VERTICAL
        }
        header.addView(TextView(activity).apply {
            text = closeLabel
            gravity = Gravity.CENTER
            style(fonts, 15f, 600, p.text)
            setPadding(context.dp(20), 0, context.dp(20), 0)
            setOnClickListener { onClose() }
        }, LinearLayout.LayoutParams(WRAP_CONTENT, MATCH_PARENT))
        panel.addView(header, LinearLayout.LayoutParams(MATCH_PARENT, context.dp(52)))
        panel.addView(View(activity).apply { setBackgroundColor(p.line) }, LinearLayout.LayoutParams(MATCH_PARENT, context.dp(1)))
        webView.setBackgroundColor(p.card)
        panel.addView(webView, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
        addView(panel, LayoutParams(MATCH_PARENT, MATCH_PARENT).apply { topMargin = context.dp(12) })

        // The wall's own loading spinner, on a card so it reads on the scrim.
        cover.background = rounded(p.card, context.dp(p.radiusDp).toFloat())
        cover.addView(ProgressBar(activity), LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
        addView(cover, LayoutParams(context.dp(64), context.dp(64)).apply { gravity = Gravity.CENTER })
    }

    /** Until revealed, the page under the cover takes no touches. */
    override fun onInterceptTouchEvent(ev: MotionEvent): Boolean = !revealed || super.onInterceptTouchEvent(ev)

    /** The click landed on a web page: the sheet rises with it. */
    fun reveal() {
        if (revealed) return
        revealed = true
        removeView(cover)
        panel.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_AUTO
        if (!Motion.enabled(context)) { panel.alpha = 1f; return }
        panel.translationY = height.toFloat()
        panel.alpha = 1f
        panel.animate().translationY(0f).setDuration(Motion.BASE).setInterpolator(Motion.easeOut).start()
    }

    /** Off the wall, and the web view destroyed: it is never reused. */
    fun remove(animated: Boolean) {
        val done = {
            (parent as? FrameLayout)?.removeView(this)
            panel.removeView(webView)
            webView.destroy()
        }
        if (animated && Motion.enabled(context)) {
            animate().alpha(0f).setDuration(Motion.EXIT).setInterpolator(Motion.easeIn).withEndAction(done).start()
        } else done()
    }

    private companion object {
        /** Drawn, so not throttled, and invisible under the cover. */
        const val HIDDEN_ALPHA = 0.01f
    }
}
