package com.playndgain.sdk.ui

import android.annotation.SuppressLint
import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.text.TextUtils
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup.LayoutParams.MATCH_PARENT
import android.view.ViewGroup.LayoutParams.WRAP_CONTENT
import android.view.inputmethod.InputMethodManager
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
    /** Back, or Close on the cover, while the click was still resolving: nothing was shown. */
    CANCELLED,
}

/** The words on the loading cover, from the wall's strings with bundled English and Arabic behind them. */
internal data class CoverCopy(
    val opening: String,
    val stalledTitle: String,
    val stalledBody: String,
    val keepWaiting: String,
    val close: String,
)

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
    private val copy: CoverCopy,
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
        val s = OfferSheet(
            activity, o.webView, palette, fonts, copy,
            onClose = { finish(OfferFlowEnd.RETURNED) },
            onCancel = { finish(OfferFlowEnd.CANCELLED) },
            onKeepWaiting = { opener?.keepWaiting() },
        )
        // The wall's search field keeps focus under the sheet and would bring
        // its keyboard up over the page: hide it and take the focus here.
        activity.currentFocus?.let { v ->
            (activity.getSystemService(Context.INPUT_METHOD_SERVICE) as? InputMethodManager)?.hideSoftInputFromWindow(v.windowToken, 0)
            v.clearFocus()
        }
        root.addView(s, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        s.requestFocus()
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
                // Resolved under a "Still opening" card: back to the spinner
                // while the store takes over.
                sheet?.showOpening()
                leaving(true)
                if (openStore(o.pkg)) finish(OfferFlowEnd.LEFT_APP) else { leaving(false); finish(OfferFlowEnd.FAILED) }
            }
            is OfferOutcome.App -> {
                sheet?.showOpening()
                leaving(true)
                if (openApp(o.url)) finish(OfferFlowEnd.LEFT_APP) else { leaving(false); opener?.appUnopened() }
            }
            OfferOutcome.Landing -> sheet?.reveal()
            OfferOutcome.Timeout -> sheet?.showStalled()
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
 *
 * The cover names the offer it is opening. When a wait runs out it says the
 * offer is still opening, with Keep waiting (the same load, waited on longer)
 * and Close; a page is only revealed once it has settled.
 */
@SuppressLint("ViewConstructor") // Built in code for one click, never inflated.
internal class OfferSheet(
    activity: Activity,
    val webView: WebView,
    private val palette: Palette,
    private val fonts: Fonts,
    private val copy: CoverCopy,
    onClose: () -> Unit,
    onCancel: () -> Unit,
    private val onKeepWaiting: () -> Unit,
) : FrameLayout(activity) {
    var revealed: Boolean = false
        private set

    private val panel = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }
    private val cover = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER_HORIZONTAL }
    private val spinner = ProgressBar(activity)
    private val title = TextView(activity)
    private val body = TextView(activity)
    private val actions = LinearLayout(activity).apply { orientation = LinearLayout.VERTICAL }

    init {
        val p = palette
        // The scrim the wall's other sheets use; it also keeps taps off the wall.
        setBackgroundColor(Color.argb(140, 0, 0, 0))
        isClickable = true
        isFocusable = true
        isFocusableInTouchMode = true

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
            text = copy.close
            gravity = Gravity.CENTER
            style(fonts, Role.BUTTON, 600, p.text)
            setPadding(context.dp(20), 0, context.dp(20), 0)
            minWidth = context.dp(48)
            background = pressable(p, null, context.dp(999).toFloat())
            setOnClickListener { onClose() }
        }, LinearLayout.LayoutParams(WRAP_CONTENT, MATCH_PARENT))
        panel.addView(header, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT))
        header.minimumHeight = context.dp(52)
        panel.addView(View(activity).apply { setBackgroundColor(p.line) }, LinearLayout.LayoutParams(MATCH_PARENT, context.dp(1)))
        webView.setBackgroundColor(p.card)
        panel.addView(webView, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
        addView(panel, LayoutParams(MATCH_PARENT, MATCH_PARENT).apply { topMargin = context.dp(12) })

        // The wall's own loading state, on a card so it reads on the scrim.
        cover.background = rounded(p.card, context.dp(p.radiusDp).toFloat(), p.line, context.dp(1))
        cover.setPadding(context.dp(20), context.dp(24), context.dp(20), context.dp(20))
        spinner.indeterminateTintList = ColorStateList.valueOf(p.muted)
        cover.addView(spinner, LinearLayout.LayoutParams(context.dp(32), context.dp(32)))
        title.apply {
            text = copy.opening
            style(fonts, Role.ROW_TITLE, 600, p.text)
            gravity = Gravity.CENTER
            maxLines = 3
            ellipsize = TextUtils.TruncateAt.END
            setPadding(0, context.dp(12), 0, 0)
            accessibilityLiveRegion = View.ACCESSIBILITY_LIVE_REGION_POLITE
        }
        cover.addView(title, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT))
        body.apply {
            text = copy.stalledBody
            style(fonts, Role.BODY, 400, p.muted)
            gravity = Gravity.CENTER
            setLineSpacing(0f, 1.25f)
            setPadding(0, context.dp(8), 0, 0)
            visibility = View.GONE
        }
        cover.addView(body, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT))
        val pill = context.dp(999).toFloat()
        actions.addView(TextView(activity).apply {
            text = copy.keepWaiting
            gravity = Gravity.CENTER
            style(fonts, Role.BUTTON, 600, p.onPrimary)
            background = pressable(p, primaryFill(p, pill), pill)
            minHeight = context.dp(48)
            setOnClickListener { keepWaiting() }
        }, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT))
        actions.addView(TextView(activity).apply {
            text = copy.close
            gravity = Gravity.CENTER
            style(fonts, Role.BUTTON, 600, p.text)
            background = pressable(p, rounded(Color.TRANSPARENT, pill, p.lineStrong, context.dp(1)), pill)
            minHeight = context.dp(48)
            setOnClickListener { onCancel() }
        }, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = context.dp(10) })
        actions.visibility = View.GONE
        cover.addView(actions, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = context.dp(20) })
        val width = minOf(context.dp(300), context.resources.displayMetrics.widthPixels - context.dp(64))
        addView(cover, LayoutParams(width, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
    }

    /** Until revealed, the page under the cover takes no touches. */
    override fun onInterceptTouchEvent(ev: MotionEvent): Boolean =
        if (revealed) super.onInterceptTouchEvent(ev) else !isInCover(ev)

    private fun isInCover(ev: MotionEvent): Boolean =
        cover.parent != null && ev.x >= cover.left && ev.x <= cover.right && ev.y >= cover.top && ev.y <= cover.bottom

    /** A wait ran out with nothing settled: say so, and offer to keep waiting. */
    fun showStalled() {
        if (revealed) return
        spinner.visibility = View.GONE
        title.text = copy.stalledTitle
        title.style(fonts, Role.SHEET_TITLE, 700, palette.text)
        body.visibility = View.VISIBLE
        actions.visibility = View.VISIBLE
        announceForAccessibility("${copy.stalledTitle} ${copy.stalledBody}")
    }

    /** Back to the spinner, waiting longer on the same load. */
    private fun keepWaiting() {
        if (revealed) return
        showOpening()
        onKeepWaiting()
    }

    /**
     * The spinner and the offer's name again: after Keep waiting, or when the
     * click resolved to the store or an app under the stalled card.
     */
    fun showOpening() {
        if (revealed) return
        spinner.visibility = View.VISIBLE
        title.text = copy.opening
        title.style(fonts, Role.ROW_TITLE, 600, palette.text)
        body.visibility = View.GONE
        actions.visibility = View.GONE
    }

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

/**
 * The detail sheet's panel: dragged down from anywhere while its content is
 * scrolled to the top, it follows the finger and closes past a quarter of its
 * height or on a quick fling; otherwise it settles back.
 */
@SuppressLint("ViewConstructor") // Built in code, never inflated.
internal class DraggablePanel(context: Context, private val onDismiss: () -> Unit) : FrameLayout(context) {
    /** The scrolling content: the drag starts only once it is at its top. */
    var scrollTarget: View? = null
    /** The tallest the sheet gets; past it the content scrolls inside. */
    var maxHeight = Int.MAX_VALUE

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val size = MeasureSpec.getSize(heightMeasureSpec).let { if (MeasureSpec.getMode(heightMeasureSpec) == MeasureSpec.UNSPECIFIED) maxHeight else minOf(it, maxHeight) }
        super.onMeasure(widthMeasureSpec, MeasureSpec.makeMeasureSpec(size, MeasureSpec.AT_MOST))
    }

    private val slop = android.view.ViewConfiguration.get(context).scaledTouchSlop
    private val flingPx = context.dp(1200).toFloat()
    private var startY = 0f
    private var lastY = 0f
    private var lastT = 0L
    private var velocity = 0f

    override fun onInterceptTouchEvent(ev: MotionEvent): Boolean {
        when (ev.actionMasked) {
            MotionEvent.ACTION_DOWN -> track(ev, reset = true)
            MotionEvent.ACTION_MOVE -> {
                val dy = ev.rawY - startY
                if (dy > slop && scrollTarget?.canScrollVertically(-1) != true) {
                    startY = ev.rawY
                    track(ev)
                    return true
                }
            }
        }
        return false
    }

    @SuppressLint("ClickableViewAccessibility") // A drag, not a click: the scrim and Back close it for everyone else.
    override fun onTouchEvent(ev: MotionEvent): Boolean {
        when (ev.actionMasked) {
            MotionEvent.ACTION_DOWN -> track(ev, reset = true)
            MotionEvent.ACTION_MOVE -> {
                track(ev)
                translationY = (ev.rawY - startY).coerceAtLeast(0f)
            }
            MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                if (ev.actionMasked == MotionEvent.ACTION_UP && (translationY > height * 0.25f || velocity > flingPx)) onDismiss()
                else animate().translationY(0f).setDuration(if (Motion.enabled(context)) Motion.BASE else 0L).setInterpolator(Motion.easeOut).start()
            }
        }
        return true
    }

    private fun track(ev: MotionEvent, reset: Boolean = false) {
        if (reset) { startY = ev.rawY; velocity = 0f }
        val dt = ev.eventTime - lastT
        if (!reset && dt > 0) velocity = (ev.rawY - lastY) / dt * 1000f
        lastY = ev.rawY
        lastT = ev.eventTime
    }
}
