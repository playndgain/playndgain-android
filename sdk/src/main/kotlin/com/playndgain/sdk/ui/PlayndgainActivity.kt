package com.playndgain.sdk.ui

import android.app.Activity
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Bundle
import android.text.Editable
import android.text.TextUtils
import android.text.TextWatcher
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.view.ViewGroup.LayoutParams.MATCH_PARENT
import android.view.ViewGroup.LayoutParams.WRAP_CONTENT
import android.view.WindowManager
import android.view.inputmethod.EditorInfo
import android.widget.EditText
import android.widget.FrameLayout
import android.widget.HorizontalScrollView
import android.widget.ImageView
import android.widget.LinearLayout
import android.widget.ProgressBar
import android.widget.ScrollView
import android.widget.TextView
import android.window.OnBackInvokedDispatcher
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.playndgain.sdk.Playndgain
import com.playndgain.sdk.PlayndgainError
import com.playndgain.sdk.PlayndgainOptions
import com.playndgain.sdk.PlayndgainTheme
import com.playndgain.sdk.R
import com.playndgain.sdk.internal.ApiClient
import com.playndgain.sdk.internal.ApiException
import com.playndgain.sdk.internal.HistoryRow
import com.playndgain.sdk.internal.ImageLoader
import com.playndgain.sdk.internal.OfferCard
import com.playndgain.sdk.internal.OfferDetail
import com.playndgain.sdk.internal.Responses
import com.playndgain.sdk.internal.Strings
import com.playndgain.sdk.internal.WallResponse
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
    private var offerFlow: OfferFlow? = null
    /** Bumped per page request; a response from an older one is dropped. */
    private var generation = 0
    private var detailGeneration = 0

    private lateinit var root: FrameLayout
    private lateinit var page: LinearLayout
    private lateinit var body: FrameLayout

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
        // Draw behind the bars in the wall's colour, but keep content clear of
        // them on every API level from 23 up.
        root.fitsSystemWindows = true
        page = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        body = FrameLayout(this)
        root.addView(page, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        setContentView(root)
        registerBackCallback()
        showLoading()
        // onResume reloads when a reward check is owed; one request is enough.
        if (!pendingRewardCheck) reload()
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
            reload()
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
     * same rules run from a registered callback there.
     */
    private fun registerBackCallback() {
        if (Build.VERSION.SDK_INT < 33) return
        onBackInvokedDispatcher.registerOnBackInvokedCallback(OnBackInvokedDispatcher.PRIORITY_DEFAULT) {
            if (!handleBack()) { emitClosedOnce(); finish() }
        }
    }

    private fun emitClosedOnce() {
        if (closedEmitted) return
        closedEmitted = true
        Playndgain.emitClosed()
    }

    // ---- Loading ----------------------------------------------------------

    private fun reload() = if (tab == Tab.OFFERS) loadOffers() else loadHistory()
    private fun loadOffers() = loadPage { api.wall(sort) }
    private fun loadHistory() = loadPage { api.history() }

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
                buildChrome(r)
                if (tab == Tab.OFFERS) showOffers(r) else showHistory(r.history)
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
        window.statusBarColor = p.bg
        window.navigationBarColor = p.bg
        if (Build.VERSION.SDK_INT >= 23) {
            window.decorView.systemUiVisibility = if (p.dark) 0 else View.SYSTEM_UI_FLAG_LIGHT_STATUS_BAR
        }
        window.addFlags(WindowManager.LayoutParams.FLAG_DRAWS_SYSTEM_BAR_BACKGROUNDS)
    }

    // ---- Chrome -----------------------------------------------------------

    private fun buildChrome(r: WallResponse) {
        val p = palette ?: return
        val app = r.app ?: return
        page.removeAllViews()

        val bar = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(dp(16), dp(14), dp(12), dp(10))
        }
        val name = options.title ?: app.name
        val mark = TextView(this).apply {
            text = name.take(1).uppercase()
            gravity = Gravity.CENTER
            style(fonts, 16f, 700, p.primary)
            background = rounded(p.tile, dp(p.radiusDp * 0.6f).toFloat())
            importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO
        }
        bar.addView(mark, LinearLayout.LayoutParams(dp(36), dp(36)))

        val titles = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            setPadding(dp(10), 0, dp(10), 0)
        }
        titles.addView(TextView(this).apply { text = name; style(fonts, 15f, 600, p.text); maxLines = 1; ellipsize = TextUtils.TruncateAt.END })
        titles.addView(TextView(this).apply { text = strings["earn"]; style(fonts, 12f, 400, p.muted); setPadding(0, dp(3), 0, 0) })
        bar.addView(titles, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))

        (options.balance ?: app.balance)?.let { bal ->
            bar.addView(TextView(this).apply {
                text = bal
                style(fonts, 13f, 600, p.onPrimary)
                background = rounded(p.primary, dp(999).toFloat())
                setPadding(dp(12), dp(6), dp(12), dp(6))
            }, LinearLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { marginEnd = dp(8) })
        }

        bar.addView(TextView(this).apply {
            text = "✕"
            gravity = Gravity.CENTER
            style(fonts, 16f, 600, p.muted)
            contentDescription = strings.or("offer.close", getString(R.string.pg_close))
            background = rounded(p.raise, dp(999).toFloat())
            setOnClickListener { emitClosedOnce(); finish() }
        }, LinearLayout.LayoutParams(dp(44), dp(44)))
        page.addView(bar)

        val tabs = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding(dp(16), dp(4), dp(16), dp(8))
        }
        listOf(Tab.OFFERS to strings.or("tabs.offers", "Offers"), Tab.HISTORY to strings.or("tabs.history", "History")).forEach { (t, label) ->
            val on = t == tab
            tabs.addView(TextView(this).apply {
                text = label
                gravity = Gravity.CENTER
                style(fonts, 14f, if (on) 600 else 400, if (on) p.text else p.muted)
                background = if (on) rounded(Colorsx.primarySoft(p), dp(999).toFloat()) else null
                setPadding(dp(16), 0, dp(16), 0)
                minHeight = dp(40)
                isSelected = on
                setOnClickListener { if (!on) switchTab(t) }
            }, LinearLayout.LayoutParams(WRAP_CONTENT, dp(40)).apply { marginEnd = dp(6) })
        }
        page.addView(tabs)
        page.addView(View(this).apply { setBackgroundColor(p.line) }, LinearLayout.LayoutParams(MATCH_PARENT, dp(1)))

        (body.parent as? ViewGroup)?.removeView(body)
        page.addView(body, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
    }

    private fun switchTab(t: Tab) {
        tab = t
        last?.let { buildChrome(it) }
        showLoadingBody()
        reload()
    }

    // ---- Offers -----------------------------------------------------------

    private fun showOffers(r: WallResponse) {
        val p = palette ?: return
        body.removeAllViews()

        val column = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }

        // Search, chips and sort sit above the list.
        val search = EditText(this).apply {
            hint = strings.or("search_placeholder", "Search...")
            setText(queryText)
            style(fonts, 14f, 400, p.text)
            setHintTextColor(p.faint)
            background = rounded(p.card, dp(p.radiusDp * 0.6f).toFloat(), p.line, dp(1))
            setPadding(dp(14), dp(10), dp(14), dp(10))
            setSingleLine()
            imeOptions = EditorInfo.IME_ACTION_SEARCH
            contentDescription = strings.or("search", "Search offers")
        }
        column.addView(search, LinearLayout.LayoutParams(MATCH_PARENT, dp(44)).apply { setMargins(dp(16), dp(12), dp(16), dp(8)) })

        val chipRow = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(dp(16), 0, dp(16), 0) }
        fun chip(label: String, on: Boolean, click: () -> Unit) = TextView(this).apply {
            text = label
            gravity = Gravity.CENTER
            style(fonts, 13f, if (on) 600 else 400, if (on) p.onInk else p.muted)
            background = if (on) rounded(p.ink, dp(999).toFloat()) else rounded(p.card, dp(999).toFloat(), p.line, dp(1))
            setPadding(dp(14), 0, dp(14), 0)
            minHeight = dp(36)
            setOnClickListener { click() }
        }
        chipRow.addView(chip(strings.or("all", "All"), category == null) { category = null; showOffers(r) },
            LinearLayout.LayoutParams(WRAP_CONTENT, dp(36)).apply { marginEnd = dp(8) })
        r.categories.forEach { c ->
            chipRow.addView(chip(c.replaceFirstChar { it.uppercase() }, category == c) { category = c; showOffers(r) },
                LinearLayout.LayoutParams(WRAP_CONTENT, dp(36)).apply { marginEnd = dp(8) })
        }
        if (r.categories.isNotEmpty()) {
            column.addView(HorizontalScrollView(this).apply { isHorizontalScrollBarEnabled = false; addView(chipRow) })
        }

        // Sort is a separate control from the category chips, as on the wall:
        // a label, then the three orders as a segmented row.
        val sortRow = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL or Gravity.END
            setPadding(dp(14), dp(10), dp(14), 0)
        }
        sortRow.contentDescription = strings.or("sort.label", "Sort offers")
        listOf(null to strings.or("sort.featured", "Featured"), "reward" to strings.or("sort.reward", "Top reward"), "trending" to strings.or("sort.trending", "Trending"))
            .forEach { (key, label) ->
                val on = sort == key
                sortRow.addView(TextView(this).apply {
                    text = label
                    gravity = Gravity.CENTER
                    style(fonts, 12f, if (on) 600 else 400, if (on) p.text else p.muted)
                    background = if (on) rounded(p.tile, dp(999).toFloat()) else null
                    setPadding(dp(10), 0, dp(10), 0)
                    minHeight = dp(32)
                    setOnClickListener { if (sort != key) { sort = key; showLoadingBody(); loadOffers() } }
                }, LinearLayout.LayoutParams(WRAP_CONTENT, dp(32)).apply { marginStart = dp(2) })
            }
        column.addView(sortRow)

        val list = RecyclerView(this).apply {
            layoutManager = LinearLayoutManager(this@PlayndgainActivity)
            clipToPadding = false
            setPadding(0, dp(8), 0, dp(40))
        }
        val adapter = OfferAdapter()
        list.adapter = adapter
        column.addView(list, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
        body.addView(column)

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

        search.addTextChangedListener(object : TextWatcher {
            override fun afterTextChanged(s: Editable?) { queryText = s?.toString().orEmpty(); refilter() }
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) {}
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) {}
        })
        refilter()
    }

    private data class Rail(val offers: List<OfferCard>)

    private fun emptyState(r: WallResponse, filtered: Boolean): Pair<String, String> = when {
        filtered -> strings.or("no_results.title", "No offers match that") to strings["no_results.body"]
        r.status == "unplaced" -> strings["unplaced.title"] to strings["unplaced.body"]
        else -> strings["empty.title"] to strings["empty.body"]
    }

    private inner class OfferAdapter : RecyclerView.Adapter<RecyclerView.ViewHolder>() {
        private var items: List<Any> = emptyList()
        private var empty: Pair<String, String> = "" to ""
        private val animate = Motion.enabled(this@PlayndgainActivity)
        private var animated = 0

        fun submit(items: List<Any>, empty: Pair<String, String>) {
            this.items = items
            this.empty = empty
            animated = 0
            @Suppress("NotifyDataSetChanged") notifyDataSetChanged()
        }

        override fun getItemCount() = if (items.isEmpty()) 1 else items.size
        override fun getItemViewType(position: Int) = when {
            items.isEmpty() -> 2
            items[position] is Rail -> 1
            else -> 0
        }

        override fun onCreateViewHolder(parent: ViewGroup, viewType: Int): RecyclerView.ViewHolder {
            val v: View = when (viewType) {
                1 -> HorizontalScrollView(parent.context).apply { isHorizontalScrollBarEnabled = false }
                2 -> LinearLayout(parent.context).apply { orientation = LinearLayout.VERTICAL }
                else -> FrameLayout(parent.context)
            }
            v.layoutParams = RecyclerView.LayoutParams(MATCH_PARENT, WRAP_CONTENT)
            return object : RecyclerView.ViewHolder(v) {}
        }

        override fun onBindViewHolder(holder: RecyclerView.ViewHolder, position: Int) {
            val v = holder.itemView
            when (getItemViewType(position)) {
                2 -> bindEmpty(v as LinearLayout, empty.first, empty.second)
                1 -> bindRail(v as HorizontalScrollView, (items[position] as Rail).offers)
                else -> {
                    val frame = v as FrameLayout
                    frame.removeAllViews()
                    frame.addView(offerCard(items[position] as OfferCard))
                }
            }
            if (position >= animated) {
                v.fadeRise(position, animate, dp(20).toFloat())
                animated = position + 1
            }
        }
    }

    private fun bindRail(scroll: HorizontalScrollView, offers: List<OfferCard>) {
        val p = palette ?: return
        scroll.removeAllViews()
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        col.addView(TextView(this).apply {
            text = strings.or("featured_offers", "Featured Offers")
            style(fonts, 13f, 600, p.muted)
            setPadding(dp(16), dp(8), dp(16), dp(8))
        })
        val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(dp(16), 0, dp(8), dp(8)) }
        offers.forEach { o ->
            row.addView(railCard(o), LinearLayout.LayoutParams(dp(220), WRAP_CONTENT).apply { marginEnd = dp(8) })
        }
        col.addView(row)
        scroll.addView(col)
    }

    /** The featured rail's card: icon and reward on one line, the name below in full. */
    private fun railCard(o: OfferCard): View {
        val p = palette!!
        val card = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            background = rounded(p.card, dp(p.radiusDp).toFloat(), p.line, dp(1))
            setPadding(dp(14), dp(14), dp(14), dp(14))
            isFocusable = true
            isClickable = true
            contentDescription = "${o.name}, ${o.rewardLabel} ${last?.app?.currencyName.orEmpty()}"
            setOnClickListener { openDetail(o.id) }
        }
        val top = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        top.addView(iconTile(o.name, o.iconUrl, 44), LinearLayout.LayoutParams(dp(44), dp(44)))
        top.addView(View(this), LinearLayout.LayoutParams(0, 1, 1f))
        val reward = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.END }
        if (o.multiReward) reward.addView(TextView(this).apply { text = strings.or("up_to", "Up to"); style(fonts, 10.5f, 400, p.faint) })
        reward.addView(TextView(this).apply { text = o.rewardLabel; style(fonts, 17f, 700, p.text); fontFeatureSettings = "tnum"; textDirection = View.TEXT_DIRECTION_LTR })
        reward.addView(TextView(this).apply { text = last?.app?.currencyName.orEmpty().let { if (fonts.rtl) it else it.uppercase() }; style(fonts, 10f, 600, p.faint); tracking(fonts, 0.06f) })
        top.addView(reward)
        card.addView(top)
        card.addView(TextView(this).apply { text = o.name; style(fonts, 15f, 600, p.text); maxLines = 2; ellipsize = TextUtils.TruncateAt.END; setPadding(0, dp(12), 0, 0) })
        if (o.instructions.isNotEmpty()) {
            card.addView(TextView(this).apply { text = o.instructions; style(fonts, 12.5f, 400, p.muted); maxLines = 2; ellipsize = TextUtils.TruncateAt.END; setPadding(0, dp(4), 0, 0) })
        }
        return card
    }

    private fun offerCard(o: OfferCard, compact: Boolean = false): View {
        val p = palette!!
        val card = LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            gravity = Gravity.CENTER_VERTICAL
            background = rounded(p.card, dp(p.radiusDp).toFloat(), p.line, dp(1))
            setPadding(dp(12), dp(12), dp(12), dp(12))
            minimumHeight = dp(72)
            isFocusable = true
            isClickable = true
            contentDescription = "${o.name}, ${o.rewardLabel} ${last?.app?.currencyName.orEmpty()}"
            setOnClickListener { openDetail(o.id) }
        }
        val lp = if (compact) FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT)
        else FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { setMargins(dp(16), dp(4), dp(16), dp(4)) }
        card.layoutParams = lp

        card.addView(iconTile(o.name, o.iconUrl, 48), LinearLayout.LayoutParams(dp(48), dp(48)))

        val text = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(12), 0, dp(10), 0) }
        text.addView(TextView(this).apply { this.text = o.name; style(fonts, 15f, 600, p.text); maxLines = 1; ellipsize = TextUtils.TruncateAt.END })
        if (o.instructions.isNotEmpty()) {
            text.addView(TextView(this).apply {
                this.text = o.instructions
                style(fonts, 12.5f, 400, p.muted)
                maxLines = 2
                ellipsize = TextUtils.TruncateAt.END
                setPadding(0, dp(4), 0, 0)
                setLineSpacing(0f, 1.15f)
            })
        }
        val meta = listOfNotNull(
            if (o.test) strings.or("test_badge", "Test offer") else null,
            if (!o.test && o.featured) strings["featured"].ifEmpty { null } else null,
            o.stepsLabel ?: if (o.multiReward) strings["multi_reward"].ifEmpty { null } else null,
            o.peopleLabel,
        )
        if (meta.isNotEmpty()) {
            text.addView(TextView(this).apply { this.text = meta.joinToString("  ·  "); style(fonts, 11.5f, 400, p.faint); setPadding(0, dp(5), 0, 0); maxLines = 1 })
        }
        card.addView(text, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))

        val reward = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.END }
        if (o.multiReward) reward.addView(TextView(this).apply { this.text = strings.or("up_to", "Up to"); style(fonts, 10.5f, 400, p.faint) })
        reward.addView(TextView(this).apply {
            this.text = o.rewardLabel
            style(fonts, 16f, 700, p.text)
            fontFeatureSettings = "tnum"
            textDirection = View.TEXT_DIRECTION_LTR
        })
        reward.addView(TextView(this).apply { this.text = last?.app?.currencyName.orEmpty().let { if (fonts.rtl) it else it.uppercase() }; style(fonts, 10f, 600, p.faint); tracking(fonts, 0.06f) })
        card.addView(reward)

        card.addView(TextView(this).apply {
            // A bidi-mirrored glyph: the RTL layout turns it to point the way
            // the row reads, with no manual flip.
            this.text = "›"
            gravity = Gravity.CENTER
            style(fonts, 18f, 700, p.onInk)
            background = rounded(p.ink, dp(999).toFloat())
            importantForAccessibility = View.IMPORTANT_FOR_ACCESSIBILITY_NO
            setOnClickListener { startOffer(o.clickUrl) }
        }, LinearLayout.LayoutParams(dp(32), dp(32)).apply { marginStart = dp(10) })

        return card
    }

    private fun iconTile(name: String, url: String?, sizeDp: Int): View {
        val p = palette!!
        val frame = FrameLayout(this).apply { background = rounded(p.tile, dp(p.radiusDp * 0.6f).toFloat()); clipToOutline = true }
        val letter = TextView(this).apply {
            text = name.take(1).uppercase()
            gravity = Gravity.CENTER
            style(fonts, (sizeDp * 0.4f), 700, p.muted)
        }
        val img = ImageView(this).apply { scaleType = ImageView.ScaleType.CENTER_CROP; visibility = View.GONE }
        frame.addView(letter, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        frame.addView(img, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        ImageLoader.load(url, img) { ok -> img.visibility = if (ok) View.VISIBLE else View.GONE; letter.visibility = if (ok) View.GONE else View.VISIBLE }
        return frame
    }

    private fun bindEmpty(v: LinearLayout, title: String, text: String) {
        val p = palette ?: return
        v.removeAllViews()
        v.gravity = Gravity.CENTER_HORIZONTAL
        v.setPadding(dp(32), dp(56), dp(32), dp(32))
        v.addView(TextView(this).apply { this.text = title; style(fonts, 17f, 600, p.text); gravity = Gravity.CENTER })
        v.addView(TextView(this).apply { this.text = text; style(fonts, 13.5f, 400, p.muted); gravity = Gravity.CENTER; setPadding(0, dp(8), 0, 0); setLineSpacing(0f, 1.25f) })
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
            showSheet(detailView(d))
        }
    }

    private fun detailView(d: OfferDetail): View {
        val p = palette!!
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(20), dp(8), dp(20), dp(16)) }

        val head = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL }
        head.addView(iconTile(d.name, d.iconUrl, 56), LinearLayout.LayoutParams(dp(56), dp(56)))
        val ht = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(14), 0, 0, 0) }
        ht.addView(TextView(this).apply { text = d.name; style(fonts, 18f, 700, p.text) })
        ht.addView(TextView(this).apply {
            text = "${if (d.steps.size > 1) strings.or("up_to", "Up to") + " " else ""}${d.rewardLabel} ${last?.app?.currencyName.orEmpty()}"
            style(fonts, 14f, 600, p.muted)
            setPadding(0, dp(4), 0, 0)
        })
        head.addView(ht, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
        col.addView(head)

        fun section(title: String) = col.addView(TextView(this).apply { text = title; style(fonts, 13f, 600, p.muted); setPadding(0, dp(20), 0, dp(8)) })

        section(strings.or("offer.steps", "What you have to do"))
        d.steps.forEachIndexed { i, (name, reward) ->
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; gravity = Gravity.CENTER_VERTICAL; setPadding(0, dp(6), 0, dp(6)) }
            row.addView(TextView(this).apply {
                text = (i + 1).toString(); gravity = Gravity.CENTER
                style(fonts, 12f, 700, p.text)
                background = rounded(p.tile, dp(999).toFloat())
            }, LinearLayout.LayoutParams(dp(26), dp(26)))
            row.addView(TextView(this).apply { text = name; style(fonts, 14f, 400, p.text); setPadding(dp(12), 0, dp(8), 0) }, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            row.addView(TextView(this).apply { text = reward; style(fonts, 14f, 600, p.text); fontFeatureSettings = "tnum"; textDirection = View.TEXT_DIRECTION_LTR })
            col.addView(row)
        }
        d.stepsNote?.let { col.addView(TextView(this).apply { text = it; style(fonts, 12.5f, 400, p.faint); setPadding(0, dp(6), 0, 0) }) }

        if (d.notes.isNotEmpty()) {
            section(strings.or("offer.how", "Before you start"))
            d.notes.forEach { n -> col.addView(TextView(this).apply { text = "•  $n"; style(fonts, 13.5f, 400, p.text); setPadding(0, dp(3), 0, dp(3)); setLineSpacing(0f, 1.2f) }) }
        }

        section(strings.or("offer.requirements", "Who this is for"))
        val devices = if (d.devices.isEmpty()) strings.or("offer.any_device", "Any device")
        else d.devices.joinToString(", ") { strings.or("device.$it", it) }
        val countries = if (d.countries.isEmpty()) strings.or("offer.worldwide", "Worldwide") else d.countries.take(8).joinToString(", ") +
            if (d.countries.size > 8) " +${d.countries.size - 8}" else ""
        listOfNotNull(
            strings.or("offer.devices", "Devices") to devices,
            strings.or("offer.countries", "Countries") to countries,
            d.osMin?.let { strings.or("offer.os", "Minimum OS") to it },
        ).forEach { (k, v) ->
            val row = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(0, dp(4), 0, dp(4)) }
            row.addView(TextView(this).apply { text = k; style(fonts, 13f, 400, p.muted) }, LinearLayout.LayoutParams(dp(120), WRAP_CONTENT))
            row.addView(TextView(this).apply { text = v; style(fonts, 13f, 400, p.text) }, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            col.addView(row)
        }

        val box = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            background = rounded(p.raise, dp(p.radiusDp).toFloat(), p.line, dp(1))
            setPadding(dp(14), dp(12), dp(14), dp(12))
        }
        box.addView(TextView(this).apply { text = strings.or("offer.notes", "Important"); style(fonts, 13f, 600, p.text) })
        d.important.forEach { n -> box.addView(TextView(this).apply { text = n; style(fonts, 12.5f, 400, p.muted); setPadding(0, dp(6), 0, 0); setLineSpacing(0f, 1.2f) }) }
        col.addView(box, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = dp(20) })

        val wrap = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
        wrap.addView(ScrollView(this).apply { addView(col) }, LinearLayout.LayoutParams(MATCH_PARENT, 0, 1f))
        val start = TextView(this).apply {
            text = strings.or("offer.start", "Start this offer")
            gravity = Gravity.CENTER
            style(fonts, 15f, 600, p.onPrimary)
            background = rounded(p.primary, dp(999).toFloat())
            setOnClickListener { closeSheet(animated = false); startOffer(d.clickUrl) }
        }
        wrap.addView(start, LinearLayout.LayoutParams(MATCH_PARENT, dp(52)).apply { setMargins(dp(20), dp(8), dp(20), dp(20)) })
        wrap.addView(TextView(this).apply {
            text = strings["offer.terms"]
            style(fonts, 11f, 400, p.faint); gravity = Gravity.CENTER
            setPadding(dp(24), 0, dp(24), dp(16))
        })
        return wrap
    }

    /** Always says something: the server's notice, else its strings, else the bundled text. */
    private fun showSheetNotice(r: WallResponse?, error: PlayndgainError?) {
        val p = palette ?: return
        val s = r?.strings ?: strings
        val n = r?.notice
        val (title, body) = when {
            n != null && (n.title.isNotEmpty() || n.body.isNotEmpty()) -> n.title to n.body
            error == PlayndgainError.NETWORK -> getString(R.string.pg_offline_title) to getString(R.string.pg_offline_body)
            error == PlayndgainError.RATE_LIMITED || r?.status == "rate_limited" ->
                s.or("notice.unavailable.title", getString(R.string.pg_unavailable_title)) to s.or("too_many", getString(R.string.pg_unavailable_body))
            else -> s.or("notice.unavailable.title", getString(R.string.pg_unavailable_title)) to s.or("notice.unavailable.body", getString(R.string.pg_unavailable_body))
        }
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(24), dp(16), dp(24), dp(32)) }
        col.addView(TextView(this).apply { text = title; style(fonts, 17f, 700, p.text) })
        col.addView(TextView(this).apply { text = body; style(fonts, 13.5f, 400, p.muted); setPadding(0, dp(8), 0, 0) })
        showSheet(col)
    }

    private fun showSheet(content: View) {
        val p = palette ?: return
        closeSheet(animated = false)
        val scrim = FrameLayout(this).apply {
            setBackgroundColor(Color.argb(140, 0, 0, 0))
            setOnClickListener { closeSheet() }
        }
        val panel = FrameLayout(this).apply {
            val r = dp(p.radiusDp * 1.8f).toFloat()
            background = GradientDrawable().apply { setColor(p.card); cornerRadii = floatArrayOf(r, r, r, r, 0f, 0f, 0f, 0f) }
            isClickable = true
            setPadding(0, dp(10), 0, 0)
        }
        panel.addView(View(this).apply { background = rounded(p.line, dp(3).toFloat()) },
            FrameLayout.LayoutParams(dp(36), dp(4)).apply { gravity = Gravity.CENTER_HORIZONTAL })
        panel.addView(content, FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { topMargin = dp(14) })
        val maxH = (resources.displayMetrics.heightPixels * 0.88f).toInt()
        scrim.addView(panel, FrameLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT).apply { gravity = Gravity.BOTTOM })
        panel.post { if (panel.height > maxH) panel.layoutParams = panel.layoutParams.apply { height = maxH } }
        root.addView(scrim, FrameLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
        sheet = scrim

        if (Motion.enabled(this)) {
            scrim.alpha = 0f
            scrim.animate().alpha(1f).setDuration(Motion.BASE).setInterpolator(Motion.easeOut).start()
            panel.translationY = resources.displayMetrics.heightPixels.toFloat()
            panel.animate().translationY(0f).setDuration(Motion.BASE).setInterpolator(Motion.easeOut).start()
        }
    }

    private fun closeSheet(animated: Boolean = true) {
        val s = sheet ?: return
        sheet = null
        if (animated && Motion.enabled(this)) {
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
    private fun startOffer(url: String) {
        // Only the wall's own click route, over the scheme the wall itself
        // was reached on (https outside a debuggable host).
        val base = Playndgain.config.baseUrl
        val p = palette
        if (offerFlow != null || p == null || !Responses.clickAllowed(url, base)) {
            Playndgain.emitError(PlayndgainError.INTERNAL)
            return
        }
        val flow = OfferFlow(this, root, p, fonts, strings.or("offer.close", getString(R.string.pg_close)), { pendingRewardCheck = it }) { end ->
            offerFlow = null
            when (end) {
                OfferFlowEnd.RETURNED -> { Playndgain.emitRewardCheck(); reload() }
                OfferFlowEnd.FAILED -> { Playndgain.emitError(PlayndgainError.NETWORK); showOffline() }
                OfferFlowEnd.LEFT_APP, OfferFlowEnd.CANCELLED -> Unit
            }
        }
        offerFlow = flow
        if (!flow.start(url, base)) { offerFlow = null; Playndgain.emitError(PlayndgainError.INTERNAL) }
    }

    // ---- History ----------------------------------------------------------

    private fun showHistory(rows: List<HistoryRow>) {
        val p = palette ?: return
        body.removeAllViews()
        if (rows.isEmpty()) {
            val v = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL }
            bindEmpty(v, strings["history.empty.title"], strings["history.empty.body"])
            body.addView(v)
            return
        }
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(0, dp(8), 0, dp(24)) }
        val animate = Motion.enabled(this)
        rows.forEachIndexed { i, r ->
            val row = LinearLayout(this).apply {
                orientation = LinearLayout.HORIZONTAL
                gravity = Gravity.CENTER_VERTICAL
                setPadding(dp(16), dp(12), dp(16), dp(12))
                isFocusable = true
                contentDescription = listOfNotNull(r.name, r.statusLabel, r.rewardLabel).joinToString(", ")
            }
            row.addView(iconTile(r.name, r.iconUrl, 40), LinearLayout.LayoutParams(dp(40), dp(40)))
            val t = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(12), 0, dp(10), 0) }
            t.addView(TextView(this).apply { text = r.name; style(fonts, 14.5f, 600, p.text); maxLines = 1; ellipsize = TextUtils.TruncateAt.END })
            val meta = listOfNotNull(r.partner, r.at?.take(10), r.statusLabel).joinToString("  ·  ")
            // Status is a word, never a colour: the one colour on this screen is
            // the credited amount, because it is the money.
            t.addView(TextView(this).apply { text = meta; style(fonts, 11.5f, 400, p.muted); setPadding(0, dp(3), 0, 0); textDirection = View.TEXT_DIRECTION_LOCALE })
            r.reason?.let { t.addView(TextView(this).apply { text = it; style(fonts, 11f, 400, p.faint); setPadding(0, dp(3), 0, 0) }) }
            row.addView(t, LinearLayout.LayoutParams(0, WRAP_CONTENT, 1f))
            r.rewardLabel?.let {
                row.addView(TextView(this).apply {
                    text = "+$it"
                    style(fonts, 15f, 700, p.primary)
                    fontFeatureSettings = "tnum"
                    textDirection = View.TEXT_DIRECTION_LTR
                })
            }
            col.addView(row)
            col.addView(View(this).apply { setBackgroundColor(p.line) }, LinearLayout.LayoutParams(MATCH_PARENT, dp(1)).apply { setMargins(dp(16), 0, dp(16), 0) })
            row.fadeRise(i, animate, dp(20).toFloat())
        }
        body.addView(ScrollView(this).apply { addView(col) })
    }

    // ---- Consent, notice, offline, loading ---------------------------------

    private fun showConsent() {
        val p = palette ?: fallbackPalette()
        page.removeAllViews()
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(dp(24), dp(40), dp(24), dp(24)) }
        col.addView(TextView(this).apply { text = strings["consent.title"]; style(fonts, 22f, 700, p.text) })
        col.addView(TextView(this).apply { text = strings["consent.intro"]; style(fonts, 14f, 400, p.muted); setPadding(0, dp(10), 0, dp(8)); setLineSpacing(0f, 1.25f) })
        listOf("what", "why", "who").forEach { k ->
            col.addView(TextView(this).apply { text = strings["consent.${k}_label"]; style(fonts, 13f, 600, p.text); setPadding(0, dp(14), 0, dp(4)) })
            col.addView(TextView(this).apply { text = strings["consent.$k"]; style(fonts, 13.5f, 400, p.muted); setLineSpacing(0f, 1.25f) })
        }
        val buttons = LinearLayout(this).apply { orientation = LinearLayout.HORIZONTAL; setPadding(0, dp(28), 0, dp(12)) }
        // Two buttons of equal weight. Decline is a real answer.
        listOf(true to strings["consent.accept"], false to strings["consent.decline"]).forEach { (accept, label) ->
            buttons.addView(TextView(this).apply {
                text = label; gravity = Gravity.CENTER
                style(fonts, 15f, 600, p.text)
                background = rounded(p.card, dp(999).toFloat(), p.line, dp(1))
                setOnClickListener { showLoading(); loadPage { api.consent(accept) } }
            }, LinearLayout.LayoutParams(0, dp(52), 1f).apply { marginEnd = if (accept) dp(10) else 0 })
        }
        col.addView(buttons)
        col.addView(TextView(this).apply { text = strings["consent.note"]; style(fonts, 12f, 400, p.faint); setLineSpacing(0f, 1.2f) })
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
        col.addView(TextView(this).apply { this.text = title; style(fonts, 19f, 700, p.text); gravity = Gravity.CENTER })
        col.addView(TextView(this).apply { this.text = text; style(fonts, 14f, 400, p.muted); gravity = Gravity.CENTER; setPadding(0, dp(10), 0, dp(24)); setLineSpacing(0f, 1.25f) })
        if (error != null) col.addView(retryButton(p), LinearLayout.LayoutParams(WRAP_CONTENT, dp(48)).apply { bottomMargin = dp(12) })
        col.addView(TextView(this).apply {
            this.text = getString(R.string.pg_close); gravity = Gravity.CENTER
            style(fonts, 14f, 600, p.text)
            background = rounded(p.card, dp(999).toFloat(), p.line, dp(1))
            setPadding(dp(28), 0, dp(28), 0)
            setOnClickListener { emitClosedOnce(); finish() }
        }, LinearLayout.LayoutParams(WRAP_CONTENT, dp(48)))
        page.addView(col, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    private fun showOffline() {
        val p = palette ?: fallbackPalette()
        page.removeAllViews()
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; gravity = Gravity.CENTER; setPadding(dp(32), dp(32), dp(32), dp(32)) }
        col.addView(TextView(this).apply { text = getString(R.string.pg_offline_title); style(fonts, 19f, 700, p.text); gravity = Gravity.CENTER })
        col.addView(TextView(this).apply { text = getString(R.string.pg_offline_body); style(fonts, 14f, 400, p.muted); gravity = Gravity.CENTER; setPadding(0, dp(10), 0, dp(24)) })
        col.addView(retryButton(p), LinearLayout.LayoutParams(WRAP_CONTENT, dp(48)).apply { bottomMargin = dp(12) })
        col.addView(TextView(this).apply {
            text = getString(R.string.pg_close); gravity = Gravity.CENTER
            style(fonts, 14f, 600, p.text)
            background = rounded(p.card, dp(999).toFloat(), p.line, dp(1))
            setPadding(dp(28), 0, dp(28), 0)
            setOnClickListener { emitClosedOnce(); finish() }
        }, LinearLayout.LayoutParams(WRAP_CONTENT, dp(48)))
        page.addView(col, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    private fun retryButton(p: Palette) = TextView(this).apply {
        text = getString(R.string.pg_retry); gravity = Gravity.CENTER
        style(fonts, 14f, 600, p.onPrimary)
        background = rounded(p.primary, dp(999).toFloat())
        setPadding(dp(28), 0, dp(28), 0)
        setOnClickListener { showLoading(); reload() }
    }

    private fun showLoading() {
        page.removeAllViews()
        page.addView(FrameLayout(this).apply {
            addView(ProgressBar(this@PlayndgainActivity), FrameLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
        }, LinearLayout.LayoutParams(MATCH_PARENT, MATCH_PARENT))
    }

    private fun showLoadingBody() {
        body.removeAllViews()
        body.addView(ProgressBar(this), FrameLayout.LayoutParams(WRAP_CONTENT, WRAP_CONTENT).apply { gravity = Gravity.CENTER })
    }

    private fun dp(v: Number) = (this as android.content.Context).dp(v)
}

/** --wall-primary-soft: the active tab's tint, the same place the wall spends it. */
internal object Colorsx {
    fun primarySoft(p: Palette): Int = com.playndgain.sdk.internal.Colors.mix(p.primary, p.card, 0.14f)
}
