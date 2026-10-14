package com.playndgain.sdk.internal

import org.json.JSONArray
import org.json.JSONObject

/** The `/sdk/v1` envelope, parsed once. The UI never touches raw JSON. */
internal data class WallResponse(
    val status: String,
    val http: Int,
    val app: AppInfo?,
    val strings: Strings,
    val notice: Notice?,
    val offers: List<OfferCard> = emptyList(),
    val categories: List<String> = emptyList(),
    val detail: OfferDetail? = null,
    val history: List<HistoryRow> = emptyList(),
)

internal data class AppInfo(
    val name: String,
    val currencyName: String,
    val rtl: Boolean,
    val theme: ThemeInfo,
    val balance: String?,
    val testMode: Boolean,
    /** The wall's language (`en`, `ar`), for dates written the player's way. */
    val locale: String = "en",
)

internal data class ThemeInfo(
    val primary: Int,
    val onPrimary: Int,
    val background: Int,
    val card: Int,
    val text: Int,
    val radius: Int,
    val dark: Boolean,
)

internal data class Notice(val key: String, val title: String, val body: String)

internal data class OfferCard(
    val id: Int,
    val name: String,
    val instructions: String,
    val rewardLabel: String,
    val multiReward: Boolean,
    val stepsLabel: String?,
    val peopleLabel: String?,
    val featured: Boolean,
    val test: Boolean,
    val categories: List<String>,
    val devices: List<String>,
    val iconUrl: String?,
    val clickUrl: String,
    /** "Up to": the steps pay separately. `up_to` from a newer wall, else `multi_reward`. */
    val upTo: Boolean = multiReward,
    /** Truly repeatable. A 1.0 wall only had `multi_reward` for both meanings. */
    val repeatable: Boolean = multiReward,
    /** The cover's finished sentence ("Opening Royal Match…"), when the wall sends it. */
    val openingLabel: String? = null,
)

/** One step of an offer. [required]: it has to be done but pays nothing on its own. */
internal data class OfferStep(val name: String, val reward: String, val required: Boolean = false)

internal data class OfferDetail(
    val id: Int,
    val name: String,
    val rewardLabel: String,
    val steps: List<OfferStep>,
    val stepsNote: String?,
    val notes: List<String>,
    val lead: String,
    val devices: List<String>,
    val countries: List<String>,
    val osMin: String?,
    val important: List<String>,
    val iconUrl: String?,
    val clickUrl: String,
    val upTo: Boolean = steps.size > 1,
    /** "iOS", "Android" in the wall's language, when the wall sends them. */
    val deviceLabels: List<String> = emptyList(),
    val openingLabel: String? = null,
)

internal data class HistoryRow(
    val name: String,
    val iconUrl: String?,
    val at: String?,
    val status: String,
    val statusLabel: String,
    val rewardLabel: String?,
    val reason: String?,
    val partner: String?,
)

/** Dotted lookups into the server's `strings`, with a fallback for an old server. */
internal class Strings(private val root: JSONObject?) {
    operator fun get(path: String): String = lookup(path) ?: ""

    fun or(path: String, fallback: String): String = lookup(path)?.takeIf { it.isNotEmpty() } ?: fallback

    private fun lookup(path: String): String? {
        var node: Any? = root ?: return null
        for (part in path.split('.')) {
            node = (node as? JSONObject)?.opt(part) ?: return null
        }
        return node as? String
    }
}

internal object Parser {
    fun parse(http: Int, body: String): WallResponse {
        val json = JSONObject(body)
        val status = json.optString("status", "invalid")
        val data = json.optJSONObject("data")

        return WallResponse(
            status = status,
            http = http,
            app = json.optJSONObject("app")?.let(::app),
            strings = Strings(json.optJSONObject("strings")),
            notice = json.optJSONObject("notice")?.let {
                Notice(it.optString("key"), it.optString("title"), it.optString("body"))
            },
            offers = data?.optJSONArray("offers")?.objects()?.map(::offer).orEmpty(),
            categories = data?.optJSONArray("categories")?.strings().orEmpty(),
            detail = if (data != null && data.has("steps")) detail(data) else null,
            history = data?.optJSONArray("rows")?.objects()?.map(::row).orEmpty(),
        )
    }

    private fun app(o: JSONObject): AppInfo {
        val t = o.optJSONObject("theme") ?: JSONObject()
        return AppInfo(
            name = o.optString("name"),
            currencyName = o.optString("currency_name"),
            rtl = o.optBoolean("rtl"),
            theme = ThemeInfo(
                primary = Colors.parse(t.optString("primary"), 0xFFFFB020.toInt()),
                onPrimary = Colors.parse(t.optString("on_primary"), 0xFF0B0D10.toInt()),
                background = Colors.parse(t.optString("background"), 0xFF0A0B14.toInt()),
                card = Colors.parse(t.optString("card"), 0xFF11131F.toInt()),
                text = Colors.parse(t.optString("text"), 0xFFF4F5FA.toInt()),
                radius = t.optInt("radius", 14),
                dark = t.optBoolean("dark", true),
            ),
            balance = o.optNullableString("balance"),
            testMode = o.optBoolean("test_mode"),
            locale = o.optString("locale").ifEmpty { "en" },
        )
    }

    private fun offer(o: JSONObject) = OfferCard(
        id = o.optInt("id"),
        name = o.optString("name"),
        instructions = o.optNullableString("subtitle") ?: o.optString("instructions"),
        rewardLabel = o.optString("reward_label"),
        multiReward = o.optBoolean("multi_reward"),
        stepsLabel = o.optNullableString("steps_label"),
        peopleLabel = o.optNullableString("people_label"),
        featured = o.optBoolean("featured"),
        test = o.optBoolean("test"),
        categories = o.optJSONArray("categories")?.strings().orEmpty(),
        devices = o.optJSONArray("devices")?.strings().orEmpty(),
        iconUrl = o.optNullableString("icon_url"),
        clickUrl = o.optString("click_url"),
        upTo = if (o.has("up_to")) o.optBoolean("up_to") else o.optBoolean("multi_reward"),
        repeatable = if (o.has("repeatable")) o.optBoolean("repeatable") else o.optBoolean("multi_reward"),
        openingLabel = o.optNullableString("opening_label"),
    )

    private fun detail(o: JSONObject) = OfferDetail(
        id = o.optInt("id"),
        name = o.optString("name"),
        rewardLabel = o.optString("reward_label"),
        steps = o.optJSONArray("steps")?.objects()?.map { OfferStep(it.optString("name"), it.optString("reward"), it.optBoolean("required")) }.orEmpty(),
        stepsNote = o.optNullableString("steps_note"),
        notes = o.optJSONArray("notes")?.strings().orEmpty(),
        lead = o.optString("lead"),
        devices = o.optJSONArray("devices")?.strings().orEmpty(),
        countries = o.optJSONArray("countries")?.objects()?.map { it.optString("name") }.orEmpty(),
        osMin = o.optNullableString("os_min_version"),
        important = o.optJSONArray("important")?.strings().orEmpty(),
        iconUrl = o.optNullableString("icon_url"),
        clickUrl = o.optString("click_url"),
    ).let { d ->
        d.copy(
            upTo = if (o.has("up_to")) o.optBoolean("up_to") else d.steps.size > 1,
            deviceLabels = o.optJSONArray("device_labels")?.strings().orEmpty(),
            openingLabel = o.optNullableString("opening_label"),
        )
    }

    private fun row(o: JSONObject) = HistoryRow(
        name = o.optString("name"),
        iconUrl = o.optNullableString("icon_url"),
        at = o.optNullableString("at"),
        status = o.optString("status"),
        statusLabel = o.optString("status_label"),
        rewardLabel = o.optNullableString("reward_label"),
        reason = o.optNullableString("reason"),
        partner = o.optNullableString("partner"),
    )

    private fun JSONObject.optNullableString(key: String): String? =
        if (isNull(key)) null else optString(key).takeIf { it.isNotEmpty() }

    private fun JSONArray.objects(): List<JSONObject> = (0 until length()).mapNotNull { optJSONObject(it) }
    private fun JSONArray.strings(): List<String> = (0 until length()).mapNotNull { opt(it) as? String }
}

internal object Colors {
    /** `#RGB`, `#RRGGBB` or `#RRGGBBAA`, the forms the wall's theme allows. */
    fun parse(hex: String?, fallback: Int): Int {
        val h = hex?.removePrefix("#") ?: return fallback
        return try {
            when (h.length) {
                3 -> (0xFF shl 24) or (h.map { "$it$it" }.joinToString("").toInt(16))
                6 -> (0xFF shl 24) or h.toInt(16)
                8 -> {
                    val rgb = h.substring(0, 6).toInt(16)
                    val a = h.substring(6, 8).toInt(16)
                    (a shl 24) or rgb
                }
                else -> fallback
            }
        } catch (_: NumberFormatException) {
            fallback
        }
    }

    /** `color-mix(in srgb, a p%, b)`, the way the wall derives its greys. */
    fun mix(a: Int, b: Int, p: Float): Int {
        fun ch(c: Int, s: Int) = (c shr s) and 0xFF
        fun m(s: Int) = (ch(a, s) * p + ch(b, s) * (1 - p)).toInt().coerceIn(0, 255)
        return (0xFF shl 24) or (m(16) shl 16) or (m(8) shl 8) or m(0)
    }

    fun alpha(c: Int, a: Float): Int = ((a * 255).toInt().coerceIn(0, 255) shl 24) or (c and 0xFFFFFF)
}
