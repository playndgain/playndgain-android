package com.playndgain.sdk.ui

import android.animation.TimeInterpolator
import android.content.Context
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
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
    /** --wall-tile: text 8% into the card. */
    val tile = Colors.mix(t.text, t.card, 0.08f)
    /** --wall-raise. */
    val raise = Colors.mix(t.text, t.card, 0.04f)
    /** --wall-ink: the filled disc on a card. */
    val ink = t.text
    val onInk = t.background
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
