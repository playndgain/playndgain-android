package com.playndgain.sdk.ui

import android.animation.TimeInterpolator
import android.content.Context
import android.content.res.ColorStateList
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
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
    /** The two stops of --wall-primary-fill. */
    val primaryHi = Colors.mix(t.primary, Color.WHITE, 0.84f)
    val primaryLo = Colors.mix(t.primary, Color.BLACK, 0.82f)
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

/** --wall-primary-fill: the publisher's colour as two stops at 135 degrees. */
internal fun primaryFill(p: Palette, radiusPx: Float, ring: Boolean = false, ringPx: Int = 0) =
    GradientDrawable(GradientDrawable.Orientation.TL_BR, intArrayOf(p.primaryHi, p.primaryLo)).apply {
        cornerRadius = radiusPx
        if (ring) setStroke(ringPx, Colors.alpha(p.primary, 0.25f))
    }

/**
 * The pressed state every tappable shares: a ripple of the text colour,
 * clipped to the control's own shape.
 */
internal fun pressable(p: Palette, content: Drawable?, radiusPx: Float): Drawable =
    RippleDrawable(ColorStateList.valueOf(p.ripple), content, rounded(Color.WHITE, radiusPx))

/**
 * The wall's one glow, under Start: the primary as the shadow colour where
 * the platform can tint one (API 28), none below, as the web wall has none
 * without color-mix.
 */
internal fun View.glow(p: Palette, elevationPx: Float) {
    if (Build.VERSION.SDK_INT < 28) return
    outlineSpotShadowColor = p.primary
    outlineAmbientShadowColor = p.primary
    elevation = elevationPx
}
