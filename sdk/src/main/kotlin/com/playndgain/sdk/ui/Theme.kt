package com.playndgain.sdk.ui

import android.animation.TimeInterpolator
import android.content.Context
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.graphics.drawable.InsetDrawable
import android.graphics.drawable.RippleDrawable
import android.os.Build
import android.provider.Settings
import android.util.TypedValue
import android.view.View
import android.view.animation.PathInterpolator
import android.widget.TextView
import androidx.core.content.res.ResourcesCompat
import com.playndgain.sdk.R
import com.playndgain.sdk.internal.Colors
import com.playndgain.sdk.internal.ThemeInfo

/**
 * The wall's palette, derived the way `resources/css/wall-ui.css` derives it,
 * from the six values the publisher controls. Nothing here is a new colour.
 */
internal class Palette(t: ThemeInfo, val radiusDp: Int) {
    val primary = t.primary
    val onPrimary = t.onPrimary
    val bg = t.background
    val card = t.card
    val text = t.text
    val dark = t.dark

    /** --wall-muted / --wall-faint: text mixed into the page. */
    val muted = Colors.mix(t.text, t.background, 0.74f)
    val faint = Colors.mix(t.text, t.background, 0.64f)
    /** --wall-line: text at 10% over the surface. */
    val line = Colors.alpha(t.text, 0.10f)
    val lineStrong = Colors.alpha(t.text, 0.18f)
    /** --wall-tile: text 8% into the card. */
    val tile = Colors.mix(t.text, t.card, 0.08f)
    /** --wall-raise. */
    val raise = Colors.mix(t.text, t.card, 0.04f)
    /** --wall-ink: the filled disc on a card. */
    val ink = t.text
    val onInk = t.background
    /** --wall-primary-soft: the active tab's tint. */
    val primarySoft = Colors.mix(t.primary, t.card, 0.14f)
    /** The pressed ripple: the text colour, faint, over whatever it sits on. */
    val ripple = Colors.alpha(t.text, 0.12f)
}

/** Motion tokens from `resources/css/wall.css`. */
internal object Motion {
    const val FAST = 150L
    const val BASE = 260L
    const val ENTER = 640L
    const val EXIT = 200L
    const val STAGGER = 85L
    const val STAGGER_CAP = 8

    /** --ease-out: cubic-bezier(0.22, 1, 0.36, 1). */
    val easeOut: TimeInterpolator = PathInterpolator(0.22f, 1f, 0.36f, 1f)
    /** --ease-in: cubic-bezier(0.4, 0, 1, 1). */
    val easeIn: TimeInterpolator = PathInterpolator(0.4f, 0f, 1f, 1f)

    fun enabled(context: Context): Boolean =
        Settings.Global.getFloat(context.contentResolver, Settings.Global.ANIMATOR_DURATION_SCALE, 1f) != 0f
}

/**
 * Sora for Latin. Sora has no Arabic cut, so an RTL wall uses the system face,
 * the same rule `resources/css/wall-ui.css` applies to `[dir='rtl']`.
 */
internal class Fonts(context: Context) {
    private val family: Typeface? = try {
        ResourcesCompat.getFont(context, R.font.sora)
    } catch (_: Exception) { null }

    @Volatile var rtl: Boolean = false

    fun weight(w: Int): Typeface {
        val base = if (rtl) Typeface.DEFAULT else family ?: Typeface.DEFAULT
        return if (android.os.Build.VERSION.SDK_INT >= 28) Typeface.create(base, w, false)
        else Typeface.create(base, if (w >= 600) Typeface.BOLD else Typeface.NORMAL)
    }
}

internal fun Context.dp(v: Number): Int =
    TypedValue.applyDimension(TypedValue.COMPLEX_UNIT_DIP, v.toFloat(), resources.displayMetrics).toInt()

internal fun rounded(color: Int, radiusPx: Float, stroke: Int? = null, strokePx: Int = 0) = GradientDrawable().apply {
    setColor(color)
    cornerRadius = radiusPx
    if (stroke != null) setStroke(strokePx, stroke)
}

internal fun TextView.style(fonts: Fonts, sizeSp: Float, weight: Int, color: Int) {
    setTextSize(TypedValue.COMPLEX_UNIT_SP, sizeSp)
    typeface = fonts.weight(weight)
    setTextColor(color)
    includeFontPadding = fonts.rtl
}

/** Tracking breaks Arabic joins, so it is zeroed on an RTL wall. */
internal fun TextView.tracking(fonts: Fonts, em: Float) {
    letterSpacing = if (fonts.rtl) 0f else em
}

internal fun View.fadeRise(index: Int, enabled: Boolean, risePx: Float) {
    if (!enabled) { alpha = 1f; translationY = 0f; return }
    alpha = 0f
    translationY = risePx
    animate()
        .alpha(1f)
        .translationY(0f)
        .setStartDelay(Motion.STAGGER * index.coerceAtMost(Motion.STAGGER_CAP))
        .setDuration(Motion.ENTER)
        .setInterpolator(Motion.easeOut)
        .start()
}

/** The primary action's fill: the publisher's colour, solid. No gradient, no glow. */
internal fun primaryFill(p: Palette, radiusPx: Float) = rounded(p.primary, radiusPx)

/**
 * The pressed state every tappable shares: a ripple of the text colour,
 * clipped to the control's own shape.
 */
internal fun pressable(p: Palette, content: Drawable?, radiusPx: Float): Drawable =
    RippleDrawable(ColorStateList.valueOf(p.ripple), content, rounded(Color.WHITE, radiusPx))

/**
 * The type roles, and nothing between them, in sp so they follow the reader's
 * font size: title 22/700, sheet title 18/700, row title 15/600, body 13/400,
 * label 12/600, reward 19/700, the currency's small capitals 11/600, and
 * buttons 15/600 (design.md §3).
 */
internal object Role {
    const val TITLE = 22f
    const val SHEET_TITLE = 18f
    const val ROW_TITLE = 15f
    const val BODY = 13f
    const val LABEL = 12f
    const val REWARD = 19f
    const val CAPS = 11f
    const val BUTTON = 15f
}

/**
 * A pill drawn [insetPx] inside its view on top and bottom: the control keeps
 * the wall's 36dp look while its touch target is the full 48dp.
 */
internal fun insetPill(p: Palette, content: Drawable?, insetPx: Int, radiusPx: Float): Drawable =
    InsetDrawable(pressable(p, content, radiusPx), 0, insetPx, 0, insetPx)

/**
 * Offers and History as one segmented control: a tile track with a thumb in
 * the primary tint that slides to the chosen segment over --motion-base (at
 * once when animations are off). TalkBack hears each segment as selected or
 * not, and as "1 of 2" from the collection the track reports.
 */
// Built in code, never inflated; the thumb is placed in absolute pixels on purpose.
@android.annotation.SuppressLint("ViewConstructor", "RtlHardcoded")
internal class SegmentedTabs(
    context: Context,
    titles: List<String>,
    selected: Int,
    private val palette: Palette,
    fonts: Fonts,
    private val onChange: (Int) -> Unit,
) : android.widget.FrameLayout(context) {
    private val thumb = View(context)
    private val row = android.widget.LinearLayout(context)
    private val segments = mutableListOf<TextView>()
    var selectedIndex = selected
        private set
    private var sliding = false

    init {
        val pill = context.dp(999).toFloat()
        background = rounded(palette.tile, pill)
        setPadding(context.dp(3), context.dp(3), context.dp(3), context.dp(3))
        thumb.background = rounded(palette.primarySoft, pill, Colors.alpha(palette.primary, 0.35f), context.dp(1))
        // Sized and placed from the chosen segment, never from the track: a
        // full-height child would make the track as tall as its parent.
        // Positioned from the left in absolute terms, so the same offset works
        // on an RTL wall, where the segments themselves are mirrored.
        addView(thumb, LayoutParams(0, 0, android.view.Gravity.LEFT or android.view.Gravity.TOP))
        titles.forEachIndexed { i, title ->
            val t = TextView(context).apply {
                text = title
                gravity = android.view.Gravity.CENTER
                style(fonts, Role.BODY, 600, palette.muted)
                minHeight = context.dp(42)
                setPadding(context.dp(12), context.dp(8), context.dp(12), context.dp(8))
                background = pressable(palette, null, pill)
                setOnClickListener { choose(i) }
                accessibilityDelegate = object : View.AccessibilityDelegate() {
                    override fun onInitializeAccessibilityNodeInfo(host: View, info: android.view.accessibility.AccessibilityNodeInfo) {
                        super.onInitializeAccessibilityNodeInfo(host, info)
                        info.className = android.widget.Button::class.java.name
                        info.isSelected = i == selectedIndex
                        info.collectionItemInfo = android.view.accessibility.AccessibilityNodeInfo.CollectionItemInfo.obtain(0, 1, i, 1, false, i == selectedIndex)
                    }
                }
            }
            segments += t
            row.addView(t, android.widget.LinearLayout.LayoutParams(0, LayoutParams.WRAP_CONTENT, 1f))
        }
        addView(row, LayoutParams(LayoutParams.MATCH_PARENT, LayoutParams.WRAP_CONTENT))
        accessibilityDelegate = object : View.AccessibilityDelegate() {
            override fun onInitializeAccessibilityNodeInfo(host: View, info: android.view.accessibility.AccessibilityNodeInfo) {
                super.onInitializeAccessibilityNodeInfo(host, info)
                info.collectionInfo = android.view.accessibility.AccessibilityNodeInfo.CollectionInfo.obtain(1, titles.size, false)
            }
        }
        restyle()
    }

    override fun onLayout(changed: Boolean, left: Int, top: Int, right: Int, bottom: Int) {
        super.onLayout(changed, left, top, right, bottom)
        if (!sliding) place(animated = false)
    }

    private fun place(animated: Boolean) {
        val target = segments.getOrNull(selectedIndex) ?: return
        val x = (row.left + target.left - paddingLeft).toFloat()
        if (thumb.width != target.width || thumb.height != target.height) {
            thumb.layoutParams = (thumb.layoutParams as LayoutParams).apply {
                width = target.width
                height = target.height
                topMargin = row.top - paddingTop + target.top
            }
        }
        if (animated && Motion.enabled(context)) {
            sliding = true
            thumb.animate().translationX(x).setDuration(Motion.BASE).setInterpolator(Motion.easeOut).withEndAction { sliding = false }.start()
        } else {
            thumb.animate().cancel()
            thumb.translationX = x
        }
    }

    private fun choose(i: Int) {
        if (i == selectedIndex) return
        select(i, animated = true)
        onChange(i)
    }

    fun select(i: Int, animated: Boolean) {
        selectedIndex = i
        restyle()
        place(animated)
    }

    private fun restyle() {
        segments.forEachIndexed { i, t ->
            val on = i == selectedIndex
            t.setTextColor(if (on) palette.text else palette.muted)
            t.isSelected = on
        }
    }
}
