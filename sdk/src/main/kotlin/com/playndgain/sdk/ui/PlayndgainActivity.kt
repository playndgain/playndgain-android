package com.playndgain.sdk.ui

import android.annotation.SuppressLint
import android.app.Activity
import android.content.Context
import android.content.Intent
import android.content.res.ColorStateList
import android.content.res.Configuration
import android.graphics.Color
import android.graphics.drawable.ColorDrawable
import android.graphics.drawable.GradientDrawable
import android.graphics.drawable.LayerDrawable
import android.os.Build
import android.os.Bundle
import android.text.Editable
import android.text.TextUtils
import android.text.InputType
import android.text.SpannableStringBuilder
import android.text.Spanned
import android.text.style.ImageSpan
import android.text.TextWatcher
import android.text.format.DateFormat
import android.util.TypedValue
import android.view.HapticFeedbackConstants
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.ViewGroup.LayoutParams.MATCH_PARENT
import android.view.ViewGroup.LayoutParams.WRAP_CONTENT
import android.view.WindowInsets
import android.view.WindowInsetsController
import android.view.WindowManager
import android.view.inputmethod.EditorInfo
import android.view.inputmethod.InputMethodManager
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.HorizontalScrollView
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.window.OnBackInvokedCallback
import android.window.OnBackInvokedDispatcher
import androidx.annotation.RequiresApi
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.playndgain.sdk.Playndgain
import com.playndgain.sdk.PlayndgainError
import com.playndgain.sdk.PlayndgainOptions
import com.playndgain.sdk.PlayndgainTheme
import com.playndgain.sdk.R
import com.playndgain.sdk.internal.ApiClient
import com.playndgain.sdk.internal.ApiException
import com.playndgain.sdk.internal.Colors
import com.playndgain.sdk.internal.HistoryRow
import com.playndgain.sdk.internal.ImageLoader
import com.playndgain.sdk.internal.OfferCard
import com.playndgain.sdk.internal.OfferDetail
import com.playndgain.sdk.internal.Responses
import com.playndgain.sdk.internal.Strings
import com.playndgain.sdk.internal.WallResponse
import java.text.ParseException
import java.text.SimpleDateFormat
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

/**
 * The whole offerwall: Offers and History, a detail sheet, and the consent,
 * notice and offline screens. Views are built in code so the SDK ships no
 * layouts a host app's resources could collide with.
 */
public class PlayndgainActivity : Activity() {

    internal companion object {
        private const val EXTRA_THEME = "com.playndgain.sdk.theme"
        private const val EXTRA_LANGUAGE = "com.playndgain.sdk.language"
        private const val EXTRA_BALANCE = "com.playndgain.sdk.balance"
        private const val EXTRA_TITLE = "com.playndgain.sdk.title"
        private const val EXTRA_SUB_IDS = "com.playndgain.sdk.sub_ids"
        private const val STATE_REWARD_CHECK = "pg.reward_check"
        private const val STATE_TAB = "pg.tab"
        private const val STATE_SORT = "pg.sort"
        private const val STATE_CATEGORY = "pg.category"
        private const val STATE_QUERY = "pg.query"
        private const val STATE_OPENED = "pg.opened"
        private const val PREFS = "com.playndgain.sdk"
        private const val SCROLL_TAG = "pg.sheet.scroll"
        private const val PREF_BG = "wall.bg"
        private const val PREF_DARK = "wall.dark"

        /** The options ride in the intent, so recreation and process death keep them. */
        fun intent(context: Context, options: PlayndgainOptions): Intent =
            Intent(context, PlayndgainActivity::class.java)
                .putExtra(EXTRA_THEME, options.theme.name)
                .putExtra(EXTRA_LANGUAGE, options.language)
                .putExtra(EXTRA_BALANCE, options.balance)
                .putExtra(EXTRA_TITLE, options.title)
                .putExtra(EXTRA_SUB_IDS, options.subIds.toTypedArray())

        fun options(extras: Bundle?): PlayndgainOptions {
            if (extras == null) return PlayndgainOptions()
            val theme = PlayndgainTheme.entries.firstOrNull { it.name == extras.getString(EXTRA_THEME) } ?: PlayndgainTheme.APP
            return PlayndgainOptions(
                theme = theme,
                language = extras.getString(EXTRA_LANGUAGE),
                balance = extras.getString(EXTRA_BALANCE),
                title = extras.getString(EXTRA_TITLE),
                subIds = extras.getStringArray(EXTRA_SUB_IDS)?.toList().orEmpty(),
            )
        }
    }

    private enum class Tab { OFFERS, HISTORY }

    private lateinit var options: PlayndgainOptions
    private lateinit var api: ApiClient
    private lateinit var fonts: Fonts
    private val io: ExecutorService = Executors.newSingleThreadExecutor()

    private var palette: Palette? = null
    private var strings = Strings(null)
    private var last: WallResponse? = null
    private var tab = Tab.OFFERS
    private var sort: String? = null
    private var category: String? = null
    private var queryText = ""
    private var opened = false
    private var closedEmitted = false
    private var pendingRewardCheck = false
    private var sheet: View? = null
    private var sheetPanel: View? = null
    /**
     * The rows' entrance is owed: set by an answer from the wall, spent by
     * the first screen of rows, cancelled by typing or a drag.
     */
    private var entrance = false
    private val entered = HashSet<Int>()
    private var offerFlow: OfferFlow? = null
    /** Bumped per page request; a response from an older one is dropped. */
    private var generation = 0
    private var detailGeneration = 0

    private lateinit var root: FrameLayout
    private lateinit var page: LinearLayout
    private lateinit var body: FrameLayout
    /**
     * Offers and History each keep their own list, alive for as long as the
     * wall is open: switching crossfades between them and keeps each one's
     * scroll position; nothing above them is rebuilt.
     */
    private lateinit var offersHost: FrameLayout
    private lateinit var historyHost: FrameLayout
    private var tabs: SegmentedTabs? = null
    private var historyRows: List<HistoryRow> = emptyList()
    private var historyAdapter: RecyclerView.Adapter<HistoryHolder>? = null
    /**
     * History is fetched in the background once the wall has loaded, and
     * again on a switch only when it is older than 30 s or a reward check has
     * happened since.
     */
    private var historyAt = 0L
    private var historyStale = false
    private var historyLoading = false
    private var historyGeneration = 0
    private val historyFresh: Boolean
        get() = historyAt > 0 && !historyStale && android.os.SystemClock.elapsedRealtime() - historyAt < 30_000

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        options = options(intent?.extras)
        savedInstanceState?.let { st ->
            pendingRewardCheck = st.getBoolean(STATE_REWARD_CHECK)
            tab = if (st.getString(STATE_TAB) == Tab.HISTORY.name) Tab.HISTORY else Tab.OFFERS
            sort = st.getString(STATE_SORT)
            category = st.getString(STATE_CATEGORY)
            queryText = st.getString(STATE_QUERY).orEmpty()
            opened = st.getBoolean(STATE_OPENED)
        }
        fonts = Fonts(this)
        try {
            api = ApiClient(applicationContext, options)
        } catch (e: ApiException) {
            Playndgain.emitError(e.error)
            finish()
            return
        }

        root = FrameLayout(this)
        // Draw behind the bars, and into a camera cutout in landscape, in the
        // wall's colour, but keep the content clear of all of them.
        if (Build.VERSION.SDK_INT >= 28) {
            window.attributes = window.attributes.apply { layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES }
            root.setOnApplyWindowInsetsListener { v, insets -> padForInsets(v, insets) }
        } else {
            root.fitsSystemWindows = true
        }
        page = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        body = FrameLayout(this)
        offersHost = FrameLayout(this)
        historyHost = FrameLayout(this)
        body.addView(offersHost, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        body.addView(historyHost, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        root.addView(page, pageParams())
        // The first frame in the palette this app last used, so a light wall
        // does not open on a black window.
        val prefs = getSharedPreferences(PREFS, MODE_PRIVATE)
        val bg = prefs.getInt(PREF_BG, 0xFF0A0B14.toInt())
        window.setBackgroundDrawable(ColorDrawable(bg))
        root.setBackgroundColor(bg)
        setContentView(root)
        // After setContentView: the insets controller needs the decor view.
        systemBars(bg, prefs.getBoolean(PREF_DARK, true))
        syncBackCallback()
        showLoading()
        // onResume reloads when a reward check is owed; one request is enough.
        if (!pendingRewardCheck) reload()
    }

    /** The bars, the keyboard and the cutout, whichever is larger on each side. */
    @RequiresApi(28)
    @Suppress("DEPRECATION") // systemWindowInsets is the one form that also works below API 30.
    private fun padForInsets(v: View, insets: WindowInsets): WindowInsets {
        val bars = if (Build.VERSION.SDK_INT >= 30) {
            insets.getInsets(WindowInsets.Type.systemBars() or WindowInsets.Type.ime() or WindowInsets.Type.displayCutout())
                .let { intArrayOf(it.left, it.top, it.right, it.bottom) }
        } else {
            val cut = insets.displayCutout
            intArrayOf(
                maxOf(insets.systemWindowInsetLeft, cut?.safeInsetLeft ?: 0), maxOf(insets.systemWindowInsetTop, cut?.safeInsetTop ?: 0),
                maxOf(insets.systemWindowInsetRight, cut?.safeInsetRight ?: 0), maxOf(insets.systemWindowInsetBottom, cut?.safeInsetBottom ?: 0),
            )
        }
        v.setPadding(bars[0], bars[1], bars[2], bars[3])
        return insets
    }

    /** On a tablet the wall keeps a phone's reading width, centred. */
    private fun pageParams() = FrameLayout.LayoutParams(
        if (resources.configuration.screenWidthDp > 640) dp(600) else MATCH_PARENT, MATCH_PARENT, Gravity.CENTER_HORIZONTAL,
    )

    override fun onConfigurationChanged(newConfig: Configuration) {
        super.onConfigurationChanged(newConfig)
        page.layoutParams = pageParams()
        (sheet as? ViewGroup)?.getChildAt(0)?.let { it.layoutParams = sheetParams() }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putBoolean(STATE_REWARD_CHECK, pendingRewardCheck)
        outState.putString(STATE_TAB, tab.name)
        outState.putString(STATE_SORT, sort)
        outState.putString(STATE_CATEGORY, category)
        outState.putString(STATE_QUERY, queryText)
        outState.putBoolean(STATE_OPENED, opened)
    }

    override fun onResume() {
        super.onResume()
        if (pendingRewardCheck) {
            pendingRewardCheck = false
            Playndgain.emitRewardCheck()
            historyStale = true
            reload()
            if (tab == Tab.HISTORY) loadHistory()
        }
    }

    override fun onDestroy() {
        io.shutdownNow()
        offerFlow?.destroy()
        if (isFinishing) emitClosedOnce()
        super.onDestroy()
    }

    @Deprecated("Activity back handling, kept for API 23")
    override fun onBackPressed() {
        if (!handleBack()) { emitClosedOnce(); @Suppress("DEPRECATION") super.onBackPressed() }
    }

    /** Closes the sheet, or leaves History. False when back should close the wall. */
    private fun handleBack(): Boolean = when {
        offerFlow?.handleBack() == true -> true
        sheet != null -> { closeSheet(); true }
        tab == Tab.HISTORY -> { switchTab(Tab.OFFERS); true }
        else -> false
    }

    /**
     * On API 33+ a host with `enableOnBackInvokedCallback="true"` never calls
     * onBackPressed, so back would close the wall from inside a sheet. The
     * same rules run from a callback there, registered only while there is
     * something to step back from (a sheet, the click, History). At the top
     * level nothing is registered, so the system's predictive back closes the
     * wall and shows what is behind it.
     */
    private var backCallback: Any? = null

    private fun syncBackCallback() {
        if (Build.VERSION.SDK_INT < 33) return
        val wanted = sheet != null || offerFlow != null || tab == Tab.HISTORY
        val current = backCallback as? OnBackInvokedCallback
        if (wanted && current == null) {
            val cb = OnBackInvokedCallback { if (!handleBack()) { emitClosedOnce(); finish() } }
            onBackInvokedDispatcher.registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT, cb)
            backCallback = cb
        } else if (!wanted && current != null) {
            onBackInvokedDispatcher.unregisterOnBackInvokedCallback(current)
            backCallback = null
        }
    }

    private fun emitClosedOnce() {
        if (closedEmitted) return
        closedEmitted = true
        Playndgain.emitClosed()
    }

    // ---- Loading ----------------------------------------------------------

    private fun reload() = loadOffers()
    private fun loadOffers() = loadPage { api.wall(sort) }

    /**
     * History, in the background or on a switch. A failure while History is
     * not on screen stays quiet; it is fetched again on the switch.
     */
    private fun loadHistory() {
        historyLoading = true
        val g = ++historyGeneration
        if (historyAt == 0L) showHistoryLoading()
        io.execute {
            val result = try { Result.success(api.history()) } catch (e: Throwable) { Result.failure(e) }
            runOnUiThread {
                if (isFinishing || isDestroyed || g != historyGeneration) return@runOnUiThread
                historyLoading = false
                result.onSuccess { r ->
                    if (r.status == "ok" || r.status == "unplaced") {
                        historyRows = r.history
                        historyAt = android.os.SystemClock.elapsedRealtime()
                        historyStale = false
                        showHistory()
                    } else if (tab == Tab.HISTORY) {
                        render(r)
                    }
                }.onFailure { e ->
                    if (tab != Tab.HISTORY || historyAt != 0L) return@onFailure
                    val error = (e as? ApiException)?.error ?: PlayndgainError.INTERNAL
                    Playndgain.emitError(error)
                    showError(error)
                }
            }
        }
    }

    /** A request that redraws the page. Only the newest one is drawn. */
    private fun loadPage(call: () -> WallResponse) {
        val g = ++generation
        detailGeneration++
        run(call, { g == generation }, ::showError) { render(it) }
    }

    private fun run(
        call: () -> WallResponse,
        current: () -> Boolean,
        failed: (PlayndgainError) -> Unit,
        then: (WallResponse) -> Unit,
    ) {
        io.execute {
            val result = try { Result.success(call()) } catch (e: Throwable) { Result.failure(e) }
            runOnUiThread {
                if (isFinishing || isDestroyed || !current()) return@runOnUiThread
                result.onSuccess(then).onFailure { e ->
                    val error = (e as? ApiException)?.error ?: PlayndgainError.INTERNAL
                    Playndgain.emitError(error)
                    failed(error)
                }
            }
        }
    }

    private fun showError(error: PlayndgainError) {
        if (error == PlayndgainError.NETWORK) showOffline() else showNotice(null, error)
    }

    private fun render(r: WallResponse) {
        // Every screen below needs the app's theme. An envelope without it
        // would leave nothing to draw, so it reads as unavailable.
        if (r.app == null && r.status in setOf("ok", "unplaced", "consent_required")) {
            strings = r.strings
            Playndgain.emitError(PlayndgainError.WALL_UNAVAILABLE)
            showNotice(null, PlayndgainError.WALL_UNAVAILABLE)
            return
        }
        last = r
        strings = r.strings
        r.app?.let { applyTheme(it.theme, it.rtl) }

        when (r.status) {
            "ok", "unplaced" -> {
                if (!opened) { opened = true; Playndgain.emitOpened() }
                entrance = true
                entered.clear()
                buildChrome(r)
                showOffers(r)
                showHistory()
                show(tab, animated = false)
                if (!historyFresh && !historyLoading) loadHistory()
            }
            "consent_required" -> showConsent()
            "rate_limited" -> {
                // The server's own notice and strings are shown; the host hears why.
                Playndgain.emitError(PlayndgainError.RATE_LIMITED)
                showNotice(r, PlayndgainError.RATE_LIMITED)
            }
            else -> showNotice(r, null)
        }
    }

    private fun applyTheme(t: com.playndgain.sdk.internal.ThemeInfo, rtl: Boolean) {
        val p = Palette(t, t.radius)
        palette = p
        root.setBackgroundColor(p.bg)
        root.layoutDirection = if (rtl) View.LAYOUT_DIRECTION_RTL else View.LAYOUT_DIRECTION_LTR
        fonts.rtl = rtl
        window.setBackgroundDrawable(ColorDrawable(p.bg))
        systemBars(p.bg, p.dark)
        getSharedPreferences(PREFS, MODE_PRIVATE).edit().putInt(PREF_BG, p.bg).putBoolean(PREF_DARK, p.dark).apply()
    }

    /** The bars in the wall's colour, with dark icons on a light wall. */
    @Suppress("DEPRECATION") // The flags are the only way below API 30.
    private fun systemBars(bg: Int, dark: Boolean) {
        window.addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS)
        window.statusBarColor = bg
        window.navigationBarColor = bg
        if (Build.VERSION.SDK_INT >= 30) {
            val light = WindowInsetsController.APPEARANCE_LIGHT_STATUS_BARS or WindowInsetsController.APPEARANCE_LIGHT_NAVIGATION_BARS
            window.insetsController?.setSystemBarsAppearance(if (dark) 0 else light, light)
        } else {
            var flags = 0
            if (!dark) {
                flags = View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR
                if (Build.VERSION.SDK_INT >= 26) flags = flags or View.SYSTEM_UI_FLAG_LIGHT_NAVIGATION_BAR
            }
            window.decorView.systemUiVisibility = flags
        }
    }

    // ---- Chrome -----------------------------------------------------------

    private fun buildChrome(r: WallResponse) {
        val p = palette ?: return
        val app = r.app ?: return
        page.removeAllViews()

        // The app's name as a small label over "Earn Coins", the close at the
        // trailing edge, and the balance before it when there is one.
        val bar = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPaddingRelative(dp(16), dp(12), dp(12), dp(10))
        }
        val name = options.title ?: app.name
        val titles = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPaddingRelative(0, 0, dp(12), 0) }
        titles.addView(TextView(this).apply { text = name; style(fonts, Role.LABEL, 600, p.muted); maxLines = 1; ellipsize = TextUtils.TruncateAt.END; reads() })
        titles.addView(TextView(this).apply { text = strings.or("earn", app.currencyName); style(fonts, Role.TITLE, 700, p.text); heading(); reads(); setPadding(0, dp(2), 0, 0) })
        bar.addView(titles, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))

        (options.balance ?: app.balance)?.let { bal ->
            val pill = balancePill(bal, app.currencyName)
            // At the largest font sizes the balance goes under the title.
            if (bigText) titles.addView(pill, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { topMargin = dp(8) })
            else bar.addView(pill, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { marginEnd = dp(8) })
        }

        bar.addView(ImageView(this).apply {
            setImageResource(R.drawable.pg_ic_close)
            imageTintList = ColorStateList.valueOf(p.muted)
            scaleType = ImageView.ScaleType.CENTER_INSIDE
            setPadding(dp(15), dp(15), dp(15), dp(15))
            contentDescription = strings.or("offer.close", getString(R.string.pg_close))
            background = pressable(p, rounded(p.raise, dp(999).toFloat()), dp(999).toFloat())
            isClickable = true
            isFocusable = true
            setOnClickListener { emitClosedOnce(); finish() }
        }, LinearLayout.LayoutParams(dp(48), dp(48)))
        page.addView(bar)

        val segments = SegmentedTabs(this, listOf(strings.or("tabs.offers", "Offers"), strings.or("tabs.history", "History")),
            if (tab == Tab.OFFERS) 0 else 1, p, fonts) { i -> switchTab(if (i == 0) Tab.OFFERS else Tab.HISTORY) }
        segments.contentDescription = strings.or("tabs.label", "Offerwall sections")
        tabs = segments
        page.addView(segments, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { setMargins(dp(16), dp(2), dp(16), dp(10)) })
        page.addView(View(this).apply { setBackgroundColor(p.line) }, LinearLayout.LayoutParams(MATCH_PARENT, dp(1)))

        (body.parent as? ViewGroup)?.removeView(body)
        page.addView(body, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
    }

    /** The user's money as a solid primary pill: the figure, then the currency. */
    private fun balancePill(balance: String, currency: String): View {
        val p = palette!!
        return LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            isBaselineAligned = true
            background = primaryFill(p, dp(999).toFloat())
            setPadding(dp(12), dp(6), dp(12), dp(6))
            contentDescription = "$balance $currency"
            addView(figure(balance, Role.ROW_TITLE, p.onPrimary).apply { importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO }, wrap())
            addView(TextView(this@PlayndgainActivity).apply {
                text = currency
                style(fonts, Role.LABEL, 600, p.onPrimary)
                importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO
                setPaddingRelative(dp(4), 0, 0, 0)
            }, wrap())
        }
    }

    /**
     * The thumb has already moved; the lists crossfade over --motion-fast and
     * History is fetched only when what is in hand is out of date.
     */
    private fun switchTab(t: Tab) {
        if (t == tab) return
        tab = t
        syncBackCallback()
        hideKeyboard()
        show(t, animated = true)
        root.announceForAccessibility(if (t == Tab.OFFERS) strings.or("tabs.offers", "Offers") else strings.or("tabs.history", "History"))
        if (t == Tab.HISTORY && !historyFresh && !historyLoading) loadHistory()
    }

    private fun show(t: Tab, animated: Boolean) {
        tabs?.select(if (t == Tab.OFFERS) 0 else 1, animated)
        val incoming = if (t == Tab.OFFERS) offersHost else historyHost
        val outgoing = if (t == Tab.OFFERS) historyHost else offersHost
        incoming.visibility = View.VISIBLE
        outgoing.animate().cancel()
        incoming.animate().cancel()
        if (!animated || !Motion.enabled(this)) {
            incoming.alpha = 1f
            outgoing.alpha = 0f
            outgoing.visibility = View.GONE
            return
        }
        incoming.animate().alpha(1f).setDuration(Motion.FAST).start()
        outgoing.animate().alpha(0f).setDuration(Motion.FAST).withEndAction { if (tab == t) outgoing.visibility = View.GONE }.start()
    }

    // ---- Offers -----------------------------------------------------------

    private fun showOffers(r: WallResponse) {
        offersHost.removeAllViews()
        val adapter = OfferAdapter()
        val list = rowList()
        list.adapter = adapter
        offersHost.addView(list, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))

        fun refilter() {
            val q = queryText.trim().lowercase()
            val visible = r.offers.filter { o ->
                (category == null || o.categories.contains(category)) &&
                    (q.isEmpty() || o.name.lowercase().contains(q) || o.instructions.lowercase().contains(q))
            }
            // Test offer first, then a featured rail when there are two or more
            // featured offers and no filter is on, as the wall does.
            val test = visible.filter { it.test }
            val rest = visible.filterNot { it.test }
            val featured = rest.filter { it.featured }
            val showRail = featured.size >= 2 && category == null && q.isEmpty()
            val items = buildList<Any> {
                addAll(test)
                if (showRail) add(Rail(featured))
                addAll(if (showRail) rest.filterNot { it.featured } else rest)
            }
            adapter.submit(items, emptyState(r, q.isNotEmpty() || category != null))
        }

        adapter.header = controls(r, onQuery = { entrance = false; refilter() }, onCategory = {
            refilter()
            list.scrollToPosition(0)
        })
        refilter()
    }

    /** The list both tabs use: the keyboard and the entrance end when it is dragged. */
    private fun rowList() = RecyclerView(this).apply {
        layoutManager = LinearLayoutManager(this@PlayndgainActivity)
        clipToPadding = false
        setPadding(0, 0, 0, dp(40))
        // Rows arrive with the wall's own rise; no second, default animation.
        itemAnimator = null
        addOnScrollListener(object : RecyclerView.OnScrollListener() {
            override fun onScrollStateChanged(rv: RecyclerView, state: Int) {
                if (state == RecyclerView.SCROLL_STATE_DRAGGING) { entrance = false; hideKeyboard() }
            }
        })
    }

    /**
     * The entrance plays once per answer from the wall, for the rows on the
     * first screen only: never on scroll, never on a keystroke. A reused row
     * always starts at rest.
     */
    private fun enter(v: View, index: Int) {
        v.animate().cancel()
        v.alpha = 1f
        v.translationY = 0f
        if (entrance && index < Motion.STAGGER_CAP && entered.add(index)) {
            v.fadeRise(index, Motion.enabled(this), dp(20).toFloat())
        }
    }

    /**
     * Search, categories and sort: the list's first item, so they scroll
     * away and the offers get the screen, in landscape most of all. A picked
     * category restyles the chips where they are; nothing is rebuilt.
     */
    private fun controls(r: WallResponse, onQuery: () -> Unit, onCategory: () -> Unit): View {
        val p = palette!!
        val column = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(0, dp(8), 0, dp(4)) }
        val pill = dp(999).toFloat()

        // The same pill and glyph as the iOS wall; no spelling marks on a search.
        val search = EditText(this).apply {
            hint = strings.or("search_placeholder", "Search…")
            setText(queryText)
            style(fonts, Role.BODY, 400, p.text)
            setHintTextColor(p.faint)
            background = rounded(p.card, pill, p.line, dp(1))
            setPadding(dp(16), dp(12), dp(16), dp(12))
            minHeight = dp(48)
            getDrawable(R.drawable.pg_ic_search)?.mutate()?.let { glass ->
                glass.setTint(p.faint)
                glass.setBounds(0, 0, dp(18), dp(18))
                setCompoundDrawablesRelative(glass, null, null, null)
                compoundDrawablePadding = dp(10)
            }
            setSingleLine()
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_FLAG_NO_SUGGESTIONS or InputType.TYPE_TEXT_VARIATION_FILTER
            imeOptions = EditorInfo.IME_ACTION_SEARCH
            contentDescription = strings.or("search", "Search offers")
            setOnEditorActionListener { _, action, _ ->
                if (action == EditorInfo.IME_ACTION_SEARCH) { hideKeyboard(); true } else false
            }
            addTextChangedListener(object : TextWatcher {
                override fun afterTextChanged(s: Editable?) { queryText = s?.toString().orEmpty(); onQuery() }
                override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
                override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
            })
        }
        column.addView(search, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { setMargins(dp(16), 0, dp(16), dp(4)) })

        // The selected category takes the primary fill, as on the web wall;
        // the rest are hairline pills. Each is drawn 36dp tall in a 48dp target.
        fun styleChip(chip: TextView, on: Boolean, ink: Boolean) {
            chip.setTextColor(if (on) p.onPrimary else p.faint)
            chip.background = when {
                on -> insetPill(p, primaryFill(p, pill), dp(6), pill)
                ink -> insetPill(p, rounded(Color.TRANSPARENT, pill, p.line, dp(1)), dp(6), pill)
                else -> insetPill(p, null, dp(6), pill)
            }
            // An InsetDrawable hands the view its own padding (the insets
            // only), which drops the label's side padding: set it back every
            // time, so a chip restyled after a tap keeps its size.
            chip.setPaddingRelative(dp(16), dp(14), dp(16), dp(14))
            chip.isSelected = on
        }
        fun chip(label: String, on: Boolean, ink: Boolean, click: () -> Unit) = TextView(this).apply {
            text = label
            gravity = Gravity.CENTER
            style(fonts, Role.LABEL, 600, p.text)
            maxLines = 1
            minHeight = dp(48)
            styleChip(this, on, ink)
            setOnClickListener { click() }
        }
        fun row(views: List<View>, label: String? = null): View {
            val line = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(dp(16), 0, dp(8), 0); contentDescription = label }
            views.forEach { line.addView(it, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { marginEnd = dp(8) }) }
            return HorizontalScrollView(this).apply { isHorizontalScrollBarEnabled = false; addView(line) }
        }

        if (r.categories.isNotEmpty()) {
            val keys: List<String?> = listOf(null) + r.categories
            val chips = mutableListOf<TextView>()
            keys.forEach { key ->
                val label = key?.replaceFirstChar { it.uppercase() } ?: strings.or("all", "All")
                chips += chip(label, category == key, true) {
                    if (category != key) {
                        category = key
                        chips.forEachIndexed { i, c -> styleChip(c, keys[i] == key, true) }
                        onCategory()
                    }
                }
            }
            column.addView(row(chips))
        }

        // Sort is its own row, leading, as on the iOS wall.
        val sorts = listOf(null to strings.or("sort.featured", "Featured"), "reward" to strings.or("sort.reward", "Top reward"), "trending" to strings.or("sort.trending", "Trending"))
            .map { (key, label) -> chip(label, sort == key, false) { if (sort != key) { sort = key; showLoadingBody(); loadOffers() } } }
        column.addView(row(sorts, strings.or("sort.label", "Sort offers")))
        return column
    }

    private fun hideKeyboard() {
        val v = currentFocus ?: return
        (getSystemService(INPUT_METHOD_SERVICE) as? InputMethodManager)?.hideSoftInputFromWindow(v.windowToken, 0)
        v.clearFocus()
    }

    private data class Rail(val offers: List<OfferCard>)

    private fun emptyState(r: WallResponse, filtered: Boolean): Pair<String, String> = when {
        filtered -> strings.or("no_results.title", "No offers match that") to strings["no_results.body"]
        r.status == "unplaced" -> strings["unplaced.title"] to strings["unplaced.body"]
        else -> strings["empty.title"] to strings["empty.body"]
    }

    /**
     * Position 0 is the controls, the same view for as long as the screen
     * lives, so typing in the search never rebinds it and the keyboard stays.
     * The rows after it are replaced as a range, and every row is a holder
     * built once and bound to whichever offer it shows.
     */
    private inner class OfferAdapter : RecyclerView.Adapter<RecyclerView.ViewHolder>() {
        var header: View? = null
            set(value) { field = value; notifyItemChanged(0) }
        private var items: List<Any> = emptyList()
        private var empty: Pair<String, String> = "" to ""

        fun submit(items: List<Any>, empty: Pair<String, String>) {
            val before = bodyCount()
            this.items = items
            this.empty = empty
            notifyItemRangeRemoved(1, before)
            notifyItemRangeInserted(1, bodyCount())
        }

        private fun bodyCount() = if (items.isEmpty()) 1 else items.size

        override fun getItemCount() = 1 + bodyCount()
        override fun getItemViewType(position: Int) = when {
            position == 0 -> 3
            items.isEmpty() -> 2
            items[position - 1] is Rail -> 1
            else -> 0
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder = when (viewType) {
            0 -> OfferHolder()
            1 -> RailHolder()
            2 -> EmptyHolder()
            else -> object : RecyclerView.ViewHolder(FrameLayout(parent.context).apply { layoutParams = RecyclerView.LayoutParams(MATCH_PARENT, WRAP_CONTENT) }) {}
        }

        override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
            when (holder) {
                is OfferHolder -> holder.bind(items[position - 1] as OfferCard)
                is RailHolder -> holder.bind((items[position - 1] as Rail).offers)
                is EmptyHolder -> holder.bind(empty.first, empty.second)
                else -> {
                    val frame = holder.itemView as FrameLayout
                    val h = header ?: return
                    if (h.parent !== frame) {
                        (h.parent as? ViewGroup)?.removeView(h)
                        frame.removeAllViews()
                        frame.addView(h)
                    }
                    return
                }
            }
            enter(holder.itemView, position - 1)
        }
    }

    private fun rewardSpeech(name: String, reward: String, upTo: Boolean): String {
        val up = if (upTo) strings.or("up_to", "Up to") + " " else ""
        return "$name, $up$reward ${last?.app?.currencyName.orEmpty()}"
    }

    private val bigText: Boolean get() = resources.configuration.fontScale >= 1.5f

    /** Children of a ledger column keep their own width, so the column's END gravity lines them up. */
    private fun wrap() = LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT)

    /** A figure reads the same in both directions: "+6,125" keeps its sign in front on the Arabic wall too. */
    private fun figure(text: String, sizeSp: Float, color: Int) = TextView(this).apply {
        this.text = text
        style(fonts, sizeSp, 700, color)
        fontFeatureSettings = "tnum"
        textDirection = View.TEXT_DIRECTION_LTR
        maxLines = 1
    }

    /** 11/600 small capitals in the faint colour: the currency and "Up to". */
    private fun caps(text: String) = TextView(this).apply {
        this.text = if (fonts.rtl) text else text.uppercase()
        style(fonts, Role.CAPS, 600, palette!!.faint)
        tracking(fonts, 0.06f)
        maxLines = 1
    }

    /**
     * The ledger: "Up to", "+6,125" and the currency, stacked and flush to the
     * row's end in a column at least 72dp wide, or on one baseline when the
     * row is stacked at the largest font sizes.
     */
    private inner class Ledger {
        val view = LinearLayout(this@PlayndgainActivity)
        private val upTo = caps("")
        private val fig = figure("", Role.REWARD, 0)
        private val unit = caps("")

        init {
            view.addView(upTo, wrap())
            view.addView(fig, wrap())
            view.addView(unit, wrap())
        }

        // The label is the wall's own formatted figure; the SDK only adds the sign.
        @SuppressLint("SetTextI18n")
        fun bind(label: String, up: Boolean, inline: Boolean, size: Float = Role.REWARD, color: Int = palette!!.text, sign: String = "+") {
            val currency = last?.app?.currencyName.orEmpty()
            upTo.visibility = if (up) View.VISIBLE else View.GONE
            upTo.text = strings.or("up_to", "Up to").let { if (fonts.rtl) it else it.uppercase() }
            fig.text = "$sign$label"
            fig.setTextSize(TypedValue.COMPLEX_UNIT_SP, size)
            fig.setTextColor(color)
            unit.text = if (fonts.rtl) currency else currency.uppercase()
            if (inline) {
                view.orientation = LinearLayout.HORIZONTAL
                view.isBaselineAligned = true
                view.gravity = Gravity.START
                view.minimumWidth = 0
                (upTo.layoutParams as LinearLayout.LayoutParams).marginEnd = dp(6)
                (fig.layoutParams as LinearLayout.LayoutParams).marginEnd = dp(6)
            } else {
                view.orientation = LinearLayout.VERTICAL
                view.gravity = Gravity.END
                view.minimumWidth = dp(72)
                (upTo.layoutParams as LinearLayout.LayoutParams).marginEnd = 0
                (fig.layoutParams as LinearLayout.LayoutParams).marginEnd = 0
            }
            view.requestLayout()
        }
    }

    /** An icon over its letter, at a fixed size. */
    private inner class Tile(sizeDp: Int) {
        val view = FrameLayout(this@PlayndgainActivity).apply { clipToOutline = true }
        private val letter = TextView(this@PlayndgainActivity).apply { gravity = Gravity.CENTER }
        private val img = ImageView(this@PlayndgainActivity).apply { scaleType = ImageView.ScaleType.CENTER_CROP }
        private val size = sizeDp

        init {
            view.addView(letter, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
            view.addView(img, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        }

        fun bind(name: String, url: String?) {
            val p = palette!!
            view.background = rounded(p.tile, dp(p.radiusDp * 0.6f).toFloat())
            letter.text = name.take(1).uppercase()
            letter.setTextSize(TypedValue.COMPLEX_UNIT_DIP, size * 0.4f)
            letter.typeface = fonts.weight(700)
            letter.setTextColor(p.muted)
            if (img.tag == url && img.drawable != null) return
            img.setImageDrawable(null)
            img.visibility = View.GONE
            letter.visibility = View.VISIBLE
            ImageLoader.load(url, img) { ok -> img.visibility = if (ok) View.VISIBLE else View.GONE; letter.visibility = if (ok) View.GONE else View.VISIBLE }
        }
    }

    /** One offer in the list, built once and bound to whichever offer it shows. */
    private inner class OfferHolder : RecyclerView.ViewHolder(FrameLayout(this@PlayndgainActivity)) {
        private val card = LinearLayout(this@PlayndgainActivity).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(12), dp(12), dp(12), dp(12))
            minimumHeight = dp(72)
            isFocusable = true
            isClickable = true
        }
        private val tile = Tile(48)
        private val text = LinearLayout(this@PlayndgainActivity).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(12), 0, dp(10), 0) }
        private val name = TextView(this@PlayndgainActivity).apply { maxLines = 2; ellipsize = TextUtils.TruncateAt.END; reads() }
        private val note = TextView(this@PlayndgainActivity).apply { maxLines = 2; ellipsize = TextUtils.TruncateAt.END; setPadding(0, dp(4), 0, 0); setLineSpacing(0f, 1.15f); reads() }
        private val meta = TextView(this@PlayndgainActivity).apply { setPadding(0, dp(5), 0, 0); maxLines = 1; ellipsize = TextUtils.TruncateAt.END; reads() }
        private val ledger = Ledger()
        private val chevron = ImageView(this@PlayndgainActivity).apply {
            setImageResource(R.drawable.pg_ic_chevron)
            setPadding(dp(6), dp(6), dp(6), dp(6))
            // Decoration only: the whole row is the button, and this never starts the offer.
            importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO
            isClickable = false
            isFocusable = false
        }

        init {
            itemView.layoutParams = RecyclerView.LayoutParams(MATCH_PARENT, WRAP_CONTENT)
            (itemView as FrameLayout).addView(card, FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { setMargins(dp(16), dp(4), dp(16), dp(4)) })
            card.addView(tile.view, LinearLayout.LayoutParams(dp(48), dp(48)))
            text.addView(name)
            text.addView(note)
            text.addView(meta)
            card.addView(text, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            card.addView(chevron, LinearLayout.LayoutParams(dp(28), dp(28)).apply { marginStart = dp(8) })
        }

        fun bind(o: OfferCard) {
            val p = palette!!
            val r = dp(p.radiusDp).toFloat()
            var bg: android.graphics.drawable.Drawable = pressable(p, rounded(p.card, r, p.line, dp(1)), r)
            // A pinned offer carries the primary on its leading edge, inset like
            // the web wall's, so the row's words stay on every other row's axis.
            if (o.featured && !o.test) {
                bg = LayerDrawable(arrayOf(bg, rounded(p.primary, dp(2).toFloat()))).apply {
                    setLayerGravity(1, Gravity.START or Gravity.FILL_VERTICAL)
                    setLayerWidth(1, dp(3))
                    setLayerInsetRelative(1, dp(2), dp(12), 0, dp(12))
                }
            }
            card.background = bg
            card.contentDescription = rewardSpeech(o.name, o.rewardLabel, o.upTo)
            card.setOnClickListener { openDetail(o.id) }
            tile.bind(o.name, o.iconUrl)
            name.text = o.name
            name.style(fonts, Role.ROW_TITLE, 600, p.text)
            note.text = o.instructions
            note.style(fonts, Role.BODY, 400, p.muted)
            note.visibility = if (o.instructions.isEmpty()) View.GONE else View.VISIBLE
            val parts = listOfNotNull(
                if (o.test) strings.or("test_badge", "Test offer") else null,
                if (!o.test && o.featured) strings["featured"].ifEmpty { null } else null,
                o.stepsLabel ?: if (o.repeatable) strings["multi_reward"].ifEmpty { null } else null,
            )
            meta.style(fonts, Role.LABEL, 400, p.faint)
            meta.text = metaLine(parts, o.peopleLabel, p, meta.textSize)
            meta.visibility = if (parts.isEmpty() && o.peopleLabel == null) View.GONE else View.VISIBLE
            chevron.imageTintList = ColorStateList.valueOf(p.text)
            chevron.background = rounded(p.tile, dp(999).toFloat())

            // At the largest font sizes the figure goes under the words, so
            // neither has to squeeze the other into a column of letters.
            ledger.bind(o.rewardLabel, o.upTo, inline = bigText)
            (ledger.view.parent as? ViewGroup)?.removeView(ledger.view)
            if (bigText) text.addView(ledger.view, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { topMargin = dp(6) })
            else card.addView(ledger.view, card.indexOfChild(chevron))
        }
    }

    /** The featured rail. Its cards are rebuilt only when its offers change. */
    private inner class RailHolder : RecyclerView.ViewHolder(HorizontalScrollView(this@PlayndgainActivity)) {
        private val title = TextView(this@PlayndgainActivity).apply { setPadding(dp(16), dp(8), dp(16), dp(8)) }
        private val row = LinearLayout(this@PlayndgainActivity).apply { orientation = LinearLayout.HORIZONTAL; setPadding(dp(16), 0, dp(8), dp(8)) }
        private var key = ""

        init {
            val scroll = itemView as HorizontalScrollView
            scroll.isHorizontalScrollBarEnabled = false
            scroll.layoutParams = RecyclerView.LayoutParams(MATCH_PARENT, WRAP_CONTENT)
            val col = LinearLayout(this@PlayndgainActivity).apply { orientation = LinearLayout.VERTICAL }
            col.addView(title)
            col.addView(row)
            scroll.addView(col)
        }

        fun bind(offers: List<OfferCard>) {
            val p = palette!!
            title.text = strings.or("featured_offers", "Featured offers")
            title.style(fonts, Role.LABEL, 600, p.muted)
            val k = offers.joinToString(",") { it.id.toString() } + "|${p.bg}|${fonts.rtl}|$bigText"
            if (k == key) return
            key = k
            row.removeAllViews()
            offers.forEach { o -> row.addView(railCard(o), LinearLayout.LayoutParams(dp(220), WRAP_CONTENT).apply { marginEnd = dp(8) }) }
        }
    }

    /** The featured rail's card: icon and reward on one line, the name below in full. */
    private fun railCard(o: OfferCard): View {
        val p = palette!!
        val card = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            val r = dp(p.radiusDp).toFloat()
            background = pressable(p, rounded(p.card, r, p.line, dp(1)), r)
            setPadding(dp(14), dp(14), dp(14), dp(14))
            isFocusable = true
            isClickable = true
            contentDescription = rewardSpeech(o.name, o.rewardLabel, o.upTo)
            setOnClickListener { openDetail(o.id) }
        }
        val top = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        val tile = Tile(44).also { it.bind(o.name, o.iconUrl) }
        top.addView(tile.view, LinearLayout.LayoutParams(dp(44), dp(44)))
        top.addView(View(this), LinearLayout.LayoutParams(0, 1, 1f))
        top.addView(Ledger().also { it.bind(o.rewardLabel, o.upTo, inline = false) }.view)
        card.addView(top)
        card.addView(TextView(this).apply { text = o.name; style(fonts, Role.ROW_TITLE, 600, p.text); maxLines = 2; ellipsize = TextUtils.TruncateAt.END; setPadding(0, dp(12), 0, 0); reads() })
        if (o.instructions.isNotEmpty()) {
            card.addView(TextView(this).apply { text = o.instructions; style(fonts, Role.BODY, 400, p.muted); maxLines = 2; ellipsize = TextUtils.TruncateAt.END; setPadding(0, dp(4), 0, 0); reads() })
        }
        return card
    }

    /**
     * "Featured · 4 steps · (person) 12": the people count wears a person
     * glyph, so "+12" reads as people who joined rather than a reward.
     */
    private fun metaLine(parts: List<String>, people: String?, p: Palette, sizePx: Float): CharSequence {
        val out = SpannableStringBuilder(parts.joinToString("  ·  "))
        val count = people?.trim('+', ' ')?.takeIf { it.isNotEmpty() } ?: return out
        if (out.isNotEmpty()) out.append("  ·  ")
        getDrawable(R.drawable.pg_ic_person)?.mutate()?.let { glyph ->
            glyph.setTint(p.faint)
            val size = (sizePx * 0.9f).toInt()
            glyph.setBounds(0, 0, size, size)
            val at = out.length
            out.append("\u00A0")
            out.setSpan(ImageSpan(glyph, ImageSpan.ALIGN_BASELINE), at, at + 1, Spanned.SPAN_EXCLUSIVE_EXCLUSIVE)
        }
        out.append("\u2009").append(count)
        return out
    }

    /** A title and a line under it, centred: the empty and no-results states. */
    private inner class EmptyHolder : RecyclerView.ViewHolder(LinearLayout(this@PlayndgainActivity)) {
        private val title = TextView(this@PlayndgainActivity).apply { gravity = Gravity.CENTER }
        private val text = TextView(this@PlayndgainActivity).apply { gravity = Gravity.CENTER; setPadding(0, dp(8), 0, 0); setLineSpacing(0f, 1.25f) }

        init {
            val v = itemView as LinearLayout
            v.orientation = LinearLayout.VERTICAL
            v.gravity = Gravity.CENTER_HORIZONTAL
            v.setPadding(dp(32), dp(56), dp(32), dp(32))
            v.layoutParams = RecyclerView.LayoutParams(MATCH_PARENT, WRAP_CONTENT)
            v.addView(title)
            v.addView(text)
        }

        fun bind(t: String, b: String) {
            val p = palette!!
            title.text = t
            title.style(fonts, Role.SHEET_TITLE, 700, p.text)
            title.heading()
            text.text = b
            text.style(fonts, Role.BODY, 400, p.muted)
        }
    }

    // ---- Detail sheet -----------------------------------------------------

    private fun openDetail(id: Int) {
        val g = ++detailGeneration
        run({ api.offer(id) }, { g == detailGeneration }, { error -> showSheetNotice(null, error) }) { r ->
            val d = r.detail
            // The host hears a rate limit on this path too, as on a page load.
            if (r.status == "rate_limited") Playndgain.emitError(PlayndgainError.RATE_LIMITED)
            // Never asked (the answer was reset since the list loaded): ask now,
            // and retire any page load in flight so it cannot replace the screen.
            if (r.status == "consent_required") { generation++; closeSheet(animated = false); showConsent(); return@run }
            if (r.status != "ok" || d == null) { showSheetNotice(r, null); return@run }
            showSheet(detailView(d), d.name)
        }
    }

    // Step figures are set left to right on every wall, so their edge is named
    // outright (LEFT on an RTL wall) rather than as START or END.
    @SuppressLint("RtlHardcoded")
    private fun detailView(d: OfferDetail): View {
        val p = palette!!
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20), dp(8), dp(20), dp(16)) }

        val head = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        head.addView(Tile(56).also { it.bind(d.name, d.iconUrl) }.view, LinearLayout.LayoutParams(dp(56), dp(56)))
        val ht = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(14), 0, 0, 0) }
        ht.addView(TextView(this).apply { text = d.name; style(fonts, Role.SHEET_TITLE, 700, p.text); heading(); reads() })
        // The figure as the row has it: "Up to", then "+6,125", then the currency.
        val reward = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; isBaselineAligned = true; setPadding(0, dp(4), 0, 0) }
        if (d.upTo) reward.addView(caps(strings.or("up_to", "Up to")), LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { marginEnd = dp(6) })
        reward.addView(figure("+${d.rewardLabel}", Role.REWARD, p.text), LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { marginEnd = dp(6) })
        reward.addView(caps(last?.app?.currencyName.orEmpty()))
        ht.addView(reward)
        head.addView(ht, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
        col.addView(head)

        fun section(title: String) = col.addView(TextView(this).apply { text = title; style(fonts, Role.LABEL, 600, p.muted); setPadding(0, dp(20), 0, dp(8)); heading() })

        section(strings.or("offer.steps", "What you have to do"))
        d.steps.forEachIndexed { i, step ->
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL; setPadding(0, dp(6), 0, dp(6)) }
            row.addView(TextView(this).apply {
                text = (i + 1).toString(); gravity = Gravity.CENTER
                style(fonts, Role.LABEL, 700, p.text)
                background = rounded(p.tile, dp(999).toFloat())
            }, LinearLayout.LayoutParams(dp(26), dp(26)))
            row.addView(TextView(this).apply { text = step.name; style(fonts, Role.BODY, 400, p.text); setPaddingRelative(dp(12), 0, dp(8), 0); reads() }, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            // The same trailing column as the list, so the step figures line
            // up. A step that pays nothing on its own says so in words.
            val figureView = if (step.required) {
                TextView(this).apply { text = step.reward.ifEmpty { strings.or("offer.required", wallString(R.string.pg_required)) }; style(fonts, Role.LABEL, 600, p.muted) }
            } else figure("+${step.reward}", Role.BODY, p.text)
            // A figure is set left to right, so "the row's end" is named outright.
            row.addView(figureView.apply { gravity = if (step.required) Gravity.END else if (fonts.rtl) Gravity.LEFT else Gravity.RIGHT; minWidth = dp(72) })
            col.addView(row)
        }
        d.stepsNote?.let { col.addView(TextView(this).apply { text = it; style(fonts, Role.BODY, 400, p.faint); setPadding(0, dp(6), 0, 0) }) }

        if (d.notes.isNotEmpty()) {
            section(strings.or("offer.how", "Before you start"))
            d.notes.forEach { n -> col.addView(TextView(this).apply { text = "•  $n"; style(fonts, Role.BODY, 400, p.text); setPadding(0, dp(3), 0, dp(3)); setLineSpacing(0f, 1.2f); reads() }) }
        }

        section(strings.or("offer.requirements", "Who this is for"))
        val devices = when {
            d.deviceLabels.isNotEmpty() -> d.deviceLabels.joinToString(", ")
            d.devices.isEmpty() -> strings.or("offer.any_device", "Any device")
            else -> d.devices.joinToString(", ") { strings.or("device.${it.lowercase()}", it) }
        }
        val countries = if (d.countries.isEmpty()) strings.or("offer.worldwide", "Worldwide") else d.countries.take(8).joinToString(", ") +
            if (d.countries.size > 8) " +${d.countries.size - 8}" else ""
        listOfNotNull(
            strings.or("offer.devices", "Devices") to devices,
            strings.or("offer.countries", "Countries") to countries,
            d.osMin?.let { strings.or("offer.os", "Minimum OS") to it },
        ).forEach { (k, v) ->
            // Side by side, or the value under its name at the largest sizes.
            val row = LinearLayout(this).apply { orientation = if (bigText) LinearLayout.VERTICAL else LinearLayout.HORIZONTAL; setPadding(0, dp(4), 0, dp(4)) }
            row.addView(TextView(this).apply { text = k; style(fonts, Role.BODY, 400, p.muted) }, if (bigText) LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT) else LinearLayout.LayoutParams(dp(120), WRAP_CONTENT))
            row.addView(TextView(this).apply { text = v; style(fonts, Role.BODY, 400, p.text); reads() }, if (bigText) LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT) else LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            col.addView(row)
        }

        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            background = rounded(p.raise, dp(p.radiusDp).toFloat(), p.line, dp(1))
            setPadding(dp(14), dp(12), dp(14), dp(12))
        }
        box.addView(TextView(this).apply { text = strings.or("offer.notes", "Important"); style(fonts, Role.LABEL, 600, p.text) })
        d.important.forEach { n -> box.addView(TextView(this).apply { text = n; style(fonts, Role.BODY, 400, p.muted); setPadding(0, dp(6), 0, 0); setLineSpacing(0f, 1.2f) }) }
        col.addView(box, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = dp(20) })

        val wrap = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        wrap.addView(ScrollView(this).apply { addView(col); tag = SCROLL_TAG }, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
        // The one primary on the sheet.
        val start = TextView(this).apply {
            text = strings.or("offer.start", "Start this offer")
            gravity = Gravity.CENTER
            style(fonts, Role.ROW_TITLE, 600, p.onPrimary)
            val pill = dp(999).toFloat()
            background = pressable(p, primaryFill(p, pill), pill)
            minHeight = dp(52)
            setPadding(dp(16), dp(14), dp(16), dp(14))
            isHapticFeedbackEnabled = true
            setOnClickListener {
                it.performHapticFeedback(HapticFeedbackConstants.CONTEXT_CLICK)
                closeSheet(animated = false)
                startOffer(d.clickUrl, d.name, d.openingLabel)
            }
        }
        wrap.addView(start, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { setMargins(dp(20), dp(12), dp(20), dp(20)) })
        wrap.addView(TextView(this).apply {
            text = strings["offer.terms"]
            style(fonts, Role.CAPS, 400, p.faint); gravity = Gravity.CENTER
            setPadding(dp(24), 0, dp(24), dp(16))
        })
        return wrap
    }

    /** Always says something: the server's notice, else its strings, else the bundled text. */
    private fun showSheetNotice(r: WallResponse?, error: PlayndgainError?) {
        val s = r?.strings ?: strings
        val n = r?.notice
        val (title, body) = when {
            n != null && (n.title.isNotEmpty() || n.body.isNotEmpty()) -> n.title to n.body
            error == PlayndgainError.NETWORK -> getString(R.string.pg_offline_title) to getString(R.string.pg_offline_body)
            error == PlayndgainError.RATE_LIMITED || r?.status == "rate_limited" ->
                s.or("notice.unavailable.title", getString(R.string.pg_unavailable_title)) to s.or("too_many", getString(R.string.pg_unavailable_body))
            else -> s.or("notice.unavailable.title", getString(R.string.pg_unavailable_title)) to s.or("notice.unavailable.body", getString(R.string.pg_unavailable_body))
        }
        showSheetMessage(title, body)
    }

    /** A short sheet with one way out, back to the offers, as its primary. */
    private fun showSheetMessage(title: String, body: String) {
        val p = palette ?: return
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(24), dp(16), dp(24), dp(24)) }
        col.addView(TextView(this).apply { text = title; style(fonts, Role.SHEET_TITLE, 700, p.text); heading() })
        col.addView(TextView(this).apply { text = body; style(fonts, Role.BODY, 400, p.muted); setPadding(0, dp(8), 0, 0); setLineSpacing(0f, 1.25f) })
        val pill = dp(999).toFloat()
        col.addView(TextView(this).apply {
            text = strings.or("notice.back", wallString(R.string.pg_back_to_offers))
            gravity = Gravity.CENTER
            style(fonts, Role.BUTTON, 600, p.onPrimary)
            background = pressable(p, primaryFill(p, pill), pill)
            minHeight = dp(52)
            setPadding(dp(16), dp(14), dp(16), dp(14))
            setOnClickListener { closeSheet() }
        }, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = dp(24) })
        showSheet(col, title)
    }

    /** The sheet's panel: full width on a phone, a phone's width on a tablet. */
    private fun sheetParams() = FrameLayout.LayoutParams(
        if (resources.configuration.screenWidthDp > 640) dp(600) else MATCH_PARENT, WRAP_CONTENT, Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL,
    )

    private fun showSheet(content: View, title: String) {
        val p = palette ?: return
        closeSheet(animated = false)
        val scrim = FrameLayout(this).apply {
            setBackgroundColor(Color.argb(140, 0, 0, 0))
            // TalkBack reads the scrim as what it does.
            contentDescription = strings.or("offer.close", getString(R.string.pg_close))
            setOnClickListener { closeSheet() }
        }
        val panel = DraggablePanel(this) { closeSheet() }.apply {
            val r = dp(p.radiusDp * 1.8f).toFloat()
            background = GradientDrawable().apply { setColor(p.card); cornerRadii = floatArrayOf(r, r, r, r, 0f, 0f, 0f, 0f) }
            isClickable = true
            setPadding(0, dp(10), 0, 0)
            if (Build.VERSION.SDK_INT >= 28) accessibilityPaneTitle = title
        }
        panel.addView(View(this).apply { background = rounded(p.lineStrong, dp(3).toFloat()); importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO },
            FrameLayout.LayoutParams(dp(36), dp(4)).apply { gravity = Gravity.CENTER_HORIZONTAL })
        panel.addView(content, FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = dp(14) })
        panel.scrollTarget = content.findViewWithTag(SCROLL_TAG)
        panel.maxHeight = (resources.displayMetrics.heightPixels * 0.88f).toInt()
        scrim.addView(panel, sheetParams())
        root.addView(scrim, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        sheet = scrim
        sheetPanel = panel
        // The page behind is out of reach for TalkBack while the sheet is up,
        // so its focus lands in the sheet; the pane title names it (API 28),
        // an announcement does below that.
        page.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO_HIDE_DESCENDANTS
        if (Build.VERSION.SDK_INT < 28) panel.announceForAccessibility(title)
        syncBackCallback()

        if (Motion.enabled(this)) {
            scrim.alpha = 0f
            scrim.animate().alpha(1f).setDuration(Motion.BASE).setInterpolator(Motion.easeOut).start()
            panel.translationY = resources.displayMetrics.heightPixels.toFloat()
            panel.animate().translationY(0f).setDuration(Motion.BASE).setInterpolator(Motion.easeOut).start()
        }
    }

    /** The sheet leaves the way it came: down, with the scrim fading. */
    private fun closeSheet(animated: Boolean = true) {
        val s = sheet ?: return
        val panel = sheetPanel
        sheet = null
        sheetPanel = null
        page.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_AUTO
        syncBackCallback()
        if (animated && Motion.enabled(this)) {
            panel?.animate()?.translationY(panel.height.toFloat())?.setDuration(Motion.EXIT)?.setInterpolator(Motion.easeIn)?.start()
            s.animate().alpha(0f).setDuration(Motion.EXIT).setInterpolator(Motion.easeIn).withEndAction { root.removeView(s) }.start()
        } else root.removeView(s)
    }

    // ---- Clicks -----------------------------------------------------------

    /**
     * Inside the app: [OfferFlow] follows `/click/{offer}` once, so the wall's
     * own pre-click checks, the click row and the redirect to the advertiser
     * all happen exactly as in the iframe, then opens the Play Store or shows
     * the page it landed on.
     */
    private fun startOffer(url: String, name: String, opening: String?) {
        // Only the wall's own click route, over the scheme the wall itself
        // was reached on (https outside a debuggable host).
        val base = Playndgain.config.baseUrl
        val p = palette
        if (offerFlow != null || p == null || !Responses.clickAllowed(url, base)) {
            Playndgain.emitError(PlayndgainError.INTERNAL)
            return
        }
        val flow = OfferFlow(this, root, p, fonts, coverCopy(name, opening), { pendingRewardCheck = it }) { end ->
            offerFlow = null
            page.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_AUTO
            syncBackCallback()
            when (end) {
                OfferFlowEnd.RETURNED -> { Playndgain.emitRewardCheck(); reload() }
                // The wall stays; the sheet says this offer could not be opened.
                OfferFlowEnd.FAILED -> {
                    Playndgain.emitError(PlayndgainError.NETWORK)
                    showSheetMessage(
                        strings.or("notice.blocked.title", getString(R.string.pg_unavailable_title)),
                        strings.or("notice.blocked.body", getString(R.string.pg_unavailable_body)),
                    )
                }
                OfferFlowEnd.LEFT_APP, OfferFlowEnd.CANCELLED -> Unit
            }
        }
        offerFlow = flow
        if (!flow.start(url, base)) { offerFlow = null; Playndgain.emitError(PlayndgainError.INTERNAL) }
        // The cover and the page over the wall are all TalkBack reaches.
        else page.importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO_HIDE_DESCENDANTS
        syncBackCallback()
    }

    /**
     * The cover's words: the offer's own finished `opening_label`, else the
     * wall's nameless `offer.opening_any`, else the bundled English or Arabic
     * with the name; then `offer.still_opening`, `offer.still_opening_body`
     * and `offer.keep_waiting` the same way.
     */
    private fun coverCopy(name: String, label: String?): CoverCopy {
        return CoverCopy(
            opening = label ?: strings.or("offer.opening_any", wallString(R.string.pg_opening, name)),
            stalledTitle = strings.or("offer.still_opening", wallString(R.string.pg_still_opening_title)),
            stalledBody = strings.or("offer.still_opening_body", wallString(R.string.pg_still_opening_body)),
            keepWaiting = strings.or("offer.keep_waiting", wallString(R.string.pg_keep_waiting)),
            close = strings.or("offer.close", wallString(R.string.pg_close)),
        )
    }

    // ---- History ----------------------------------------------------------

    private fun showHistoryLoading() {
        if (historyHost.childCount > 0) return
        historyHost.addView(ProgressBar(this), FrameLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
    }

    /**
     * The History list from what is in hand: rows, the empty state, or the
     * spinner while the first answer is on its way. The list itself stays,
     * so its scroll position does too.
     */
    private fun showHistory() {
        if (historyAt == 0L) { historyHost.removeAllViews(); showHistoryLoading(); return }
        val rows = historyRows
        if (rows.isEmpty()) {
            historyHost.removeAllViews()
            historyAdapter = null
            val holder = EmptyHolder()
            holder.bind(strings["history.empty.title"], strings["history.empty.body"])
            historyHost.addView(holder.itemView, FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT))
            return
        }
        historyAdapter?.let {
            @Suppress("NotifyDataSetChanged") it.notifyDataSetChanged()
            return
        }
        historyHost.removeAllViews()
        val list = rowList().apply { setPadding(0, dp(8), 0, dp(24)) }
        val adapter = object : RecyclerView.Adapter<HistoryHolder>() {
            override fun getItemCount() = historyRows.size
            override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = HistoryHolder()
            override fun onBindViewHolder(holder: HistoryHolder, position: Int) = holder.bind(historyRows[position])
        }
        historyAdapter = adapter
        list.adapter = adapter
        historyHost.addView(list, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    /** One History row, built once: the offer, a status pill and the date, and the money. */
    private inner class HistoryHolder : RecyclerView.ViewHolder(LinearLayout(this@PlayndgainActivity)) {
        private val row = LinearLayout(this@PlayndgainActivity).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(16), dp(12), dp(16), dp(12))
            isFocusable = true
        }
        private val tile = Tile(40)
        private val name = TextView(this@PlayndgainActivity).apply { maxLines = 1; ellipsize = TextUtils.TruncateAt.END; reads() }
        private val pill = TextView(this@PlayndgainActivity).apply { setPadding(dp(8), dp(3), dp(8), dp(3)); maxLines = 1 }
        private val whenText = TextView(this@PlayndgainActivity).apply { maxLines = 1; ellipsize = TextUtils.TruncateAt.END; setPaddingRelative(dp(8), 0, 0, 0); reads() }
        private val reason = TextView(this@PlayndgainActivity).apply { setPadding(0, dp(4), 0, 0); reads() }
        private val ledger = Ledger()
        private val line = View(this@PlayndgainActivity)

        init {
            val v = itemView as LinearLayout
            v.orientation = LinearLayout.VERTICAL
            v.layoutParams = RecyclerView.LayoutParams(MATCH_PARENT, WRAP_CONTENT)
            row.addView(tile.view, LinearLayout.LayoutParams(dp(40), dp(40)))
            val t = LinearLayout(this@PlayndgainActivity).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(12), 0, dp(10), 0) }
            t.addView(name)
            val meta = LinearLayout(this@PlayndgainActivity).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL; setPadding(0, dp(4), 0, 0) }
            meta.addView(pill)
            meta.addView(whenText, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            t.addView(meta)
            t.addView(reason)
            row.addView(t, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            row.addView(ledger.view)
            v.addView(row)
            v.addView(line, LinearLayout.LayoutParams(MATCH_PARENT, dp(1)).apply { setMargins(dp(16), 0, dp(16), 0) })
        }

        fun bind(r: HistoryRow) {
            val p = palette!!
            val currency = last?.app?.currencyName.orEmpty()
            val reversed = r.status == "reversed"
            val sign = if (reversed) "\u2212" else "+"
            row.contentDescription = listOfNotNull(r.name, r.statusLabel, r.rewardLabel?.let { "$sign$it $currency" }).joinToString(", ")
            tile.bind(r.name, r.iconUrl)
            name.text = r.name
            name.style(fonts, Role.ROW_TITLE, 600, p.text)
            // Status is a word on a quiet pill, never a new colour: credited
            // takes the primary, waiting the faint text, refused the muted text.
            pill.text = r.statusLabel
            pill.style(fonts, Role.CAPS, 600, statusColor(p, r.status))
            pill.background = rounded(p.tile, dp(999).toFloat())
            val w = listOfNotNull(r.partner, r.at?.let(::shortDate)).joinToString("  ·  ")
            whenText.text = w
            whenText.style(fonts, Role.LABEL, 400, p.muted)
            whenText.visibility = if (w.isEmpty()) View.GONE else View.VISIBLE
            reason.text = r.reason
            reason.style(fonts, Role.LABEL, 400, p.faint)
            reason.visibility = if (r.reason == null) View.GONE else View.VISIBLE
            // Money keeps the primary. A reversed amount is taken back: a
            // minus sign and the muted colour, with the word beside it.
            val reward = r.rewardLabel
            ledger.view.visibility = if (reward == null) View.GONE else View.VISIBLE
            if (reward != null) ledger.bind(reward, false, inline = false, size = Role.ROW_TITLE, color = if (reversed) p.muted else p.primary, sign = sign)
            line.setBackgroundColor(p.line)
        }
    }

    private fun statusColor(p: Palette, status: String): Int = when (status) {
        "approved" -> p.primary
        "rejected", "reversed" -> p.muted
        else -> p.faint
    }

    /**
     * "14 Oct" the way the wall's language writes it, not the device's. The
     * day is the wall's own (the date part it sent), as on the web wall.
     */
    private fun shortDate(iso: String): String {
        val day = iso.take(10)
        val utc = TimeZone.getTimeZone("UTC")
        val date = try {
            SimpleDateFormat("yyyy-MM-dd", Locale.US).apply { timeZone = utc }.parse(day)
        } catch (_: ParseException) { null } ?: return day
        val locale = Locale(last?.app?.locale ?: "en")
        return SimpleDateFormat(DateFormat.getBestDateTimePattern(locale, "dMMM"), locale).apply { timeZone = utc }.format(date)
    }

    // ---- Consent, notice, offline, loading ---------------------------------

    private fun showConsent() {
        val p = palette ?: fallbackPalette()
        page.removeAllViews()
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(24), dp(40), dp(24), dp(24)) }
        col.addView(TextView(this).apply { text = strings["consent.title"]; style(fonts, Role.TITLE, 700, p.text) })
        col.addView(TextView(this).apply { text = strings["consent.intro"]; style(fonts, Role.BODY, 400, p.muted); setPadding(0, dp(10), 0, dp(8)); setLineSpacing(0f, 1.25f) })
        listOf("what", "why", "who").forEach { k ->
            col.addView(TextView(this).apply { text = strings["consent.${k}_label"]; style(fonts, Role.LABEL, 600, p.text); setPadding(0, dp(14), 0, dp(4)) })
            col.addView(TextView(this).apply { text = strings["consent.$k"]; style(fonts, Role.BODY, 400, p.muted); setLineSpacing(0f, 1.25f) })
        }
        val buttons = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(0, dp(28), 0, dp(12)) }
        // Two buttons of equal weight. Decline is a real answer.
        listOf(true to strings["consent.accept"], false to strings["consent.decline"]).forEach { (accept, label) ->
            buttons.addView(TextView(this).apply {
                text = label; gravity = Gravity.CENTER
                style(fonts, Role.ROW_TITLE, 600, p.text)
                background = pressable(p, rounded(p.card, dp(999).toFloat(), p.line, dp(1)), dp(999).toFloat())
                setOnClickListener { showLoading(); loadPage { api.consent(accept) } }
                minHeight = dp(52)
                setPadding(dp(12), dp(12), dp(12), dp(12))
            }, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f).apply { marginEnd = if (accept) dp(10) else 0 })
        }
        col.addView(buttons)
        col.addView(TextView(this).apply { text = strings["consent.note"]; style(fonts, Role.LABEL, 400, p.faint); setLineSpacing(0f, 1.2f) })
        page.addView(ScrollView(this).apply { addView(col) }, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    /** The palette for screens drawn before, or without, the app's theme. */
    private fun fallbackPalette(): Palette =
        Palette(com.playndgain.sdk.internal.ThemeInfo(0xFFFFB020.toInt(), 0xFF0B0D10.toInt(), 0xFF0A0B14.toInt(), 0xFF11131F.toInt(), 0xFFF4F5FA.toInt(), 14, true), 14)
            .also { palette = it; root.setBackgroundColor(it.bg) }

    /**
     * A full-page notice. Errors and rate limits can pass, so they get a retry
     * as well as the close button; a refusal from the wall gets close only.
     */
    private fun showNotice(r: WallResponse?, error: PlayndgainError?) {
        val p = palette ?: fallbackPalette()
        page.removeAllViews()
        val title: String
        val text: String
        val n = r?.notice
        when {
            n != null && (n.title.isNotEmpty() || n.body.isNotEmpty()) -> { title = n.title; text = n.body }
            r?.status == "unplaced" -> { title = strings["unplaced.title"]; text = strings["unplaced.body"] }
            error == PlayndgainError.RATE_LIMITED -> {
                title = strings.or("notice.unavailable.title", getString(R.string.pg_unavailable_title))
                text = strings.or("too_many", getString(R.string.pg_unavailable_body))
            }
            else -> {
                title = strings.or("notice.unavailable.title", getString(R.string.pg_unavailable_title))
                text = strings.or("notice.unavailable.body", getString(R.string.pg_unavailable_body))
            }
        }
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(32), dp(32), dp(32), dp(32)) }
        col.addView(TextView(this).apply { this.text = title; style(fonts, Role.TITLE, 700, p.text); gravity = Gravity.CENTER; heading() })
        root.announceForAccessibility("$title. $text")
        col.addView(TextView(this).apply { this.text = text; style(fonts, Role.BODY, 400, p.muted); gravity = Gravity.CENTER; setPadding(0, dp(10), 0, dp(24)); setLineSpacing(0f, 1.25f) })
        if (error != null) col.addView(retryButton(p), LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { bottomMargin = dp(12) })
        col.addView(TextView(this).apply {
            this.text = getString(R.string.pg_close); gravity = Gravity.CENTER
            style(fonts, Role.BUTTON, 600, p.text)
            background = pressable(p, rounded(p.card, dp(999).toFloat(), p.line, dp(1)), dp(999).toFloat())
            setPadding(dp(28), dp(12), dp(28), dp(12))
            minHeight = dp(48)
            setOnClickListener { emitClosedOnce(); finish() }
        }, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT))
        page.addView(col, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    private fun showOffline() {
        val p = palette ?: fallbackPalette()
        page.removeAllViews()
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(32), dp(32), dp(32), dp(32)) }
        col.addView(TextView(this).apply { text = getString(R.string.pg_offline_title); style(fonts, Role.TITLE, 700, p.text); gravity = Gravity.CENTER; heading() })
        root.announceForAccessibility(getString(R.string.pg_offline_title))
        col.addView(TextView(this).apply { text = getString(R.string.pg_offline_body); style(fonts, Role.BODY, 400, p.muted); gravity = Gravity.CENTER; setPadding(0, dp(10), 0, dp(24)) })
        col.addView(retryButton(p), LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { bottomMargin = dp(12) })
        col.addView(TextView(this).apply {
            text = getString(R.string.pg_close); gravity = Gravity.CENTER
            style(fonts, Role.BUTTON, 600, p.text)
            background = pressable(p, rounded(p.card, dp(999).toFloat(), p.line, dp(1)), dp(999).toFloat())
            setPadding(dp(28), dp(12), dp(28), dp(12))
            minHeight = dp(48)
            setOnClickListener { emitClosedOnce(); finish() }
        }, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT))
        page.addView(col, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    private fun retryButton(p: Palette) = TextView(this).apply {
        text = getString(R.string.pg_retry); gravity = Gravity.CENTER
        style(fonts, Role.BUTTON, 600, p.onPrimary)
        background = pressable(p, primaryFill(p, dp(999).toFloat()), dp(999).toFloat())
        setPadding(dp(28), dp(12), dp(28), dp(12))
        minHeight = dp(48)
        setOnClickListener { showLoading(); reload() }
    }

    private fun showLoading() {
        page.removeAllViews()
        page.addView(FrameLayout(this).apply {
            addView(ProgressBar(this@PlayndgainActivity), FrameLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
        }, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    private fun showLoadingBody() {
        offersHost.removeAllViews()
        offersHost.addView(ProgressBar(this), FrameLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
    }

    /**
     * The bundled text in the wall's language rather than the phone's, for
     * words drawn inside a wall the server has already named the language of.
     * Where a store's language split left that language out, Android falls
     * back to the English text, as it did before.
     */
    @SuppressLint("AppBundleLocaleChanges")
    private fun wallString(id: Int, vararg args: Any): String {
        val lang = last?.app?.locale ?: return getString(id, *args)
        val config = Configuration(resources.configuration).apply { setLocale(Locale(lang)) }
        return createConfigurationContext(config).getString(id, *args)
    }

    /** Words on an RTL wall start at the reading edge, Latin names included. */
    private fun TextView.reads() {
        textAlignment = View.TEXT_ALIGNMENT_VIEW_START
    }

    /** A heading for TalkBack's heading navigation, where the platform has it (API 28). */
    private fun TextView.heading() {
        if (Build.VERSION.SDK_INT >= 28) isAccessibilityHeading = true
    }

    private fun dp(v: Number) = (this as android.content.Context).dp(v)
}
