package com.playndgain.sdk.internal

import java.io.ByteArrayOutputStream
import java.util.Locale

/**
 * Which URLs are a Google Play listing. Pure string handling, no
 * `android.net.Uri`, so the JVM unit tests can pin it.
 */
internal object StoreLink {
    const val PLAY_STORE_PACKAGE = "com.android.vending"
    private const val PLAY_HOST = "play.google.com"
    private val PACKAGE = Regex("^[A-Za-z][A-Za-z0-9_]*(\\.[A-Za-z][A-Za-z0-9_]*)+$")

    /**
     * The package the URL lists on Play, or null when it is not a listing.
     * Web links count only on Play's own host, compared whole, so
     * `play.google.com.evil.example` is not one. `intent://` links count when
     * they target Play (`scheme=market`, or the Play Store package), or when
     * their `S.browser_fallback_url` is itself a Play listing.
     */
    fun playPackage(url: String): String? {
        val parts = Parts.of(url.trim()) ?: return null
        return when (parts.scheme) {
            "https", "http" -> parts.takeIf { it.host == PLAY_HOST && it.path.trimEnd('/') == "/store/apps/details" }?.packageId()
            "market" -> parts.takeIf { it.host == "details" }?.packageId()
            "intent" -> intent(parts)
            else -> null
        }
    }

    /** Opens the listing in the Play Store app. */
    fun marketUri(pkg: String): String = "market://details?id=$pkg"

    /** The same listing on the web, for a device without the Play Store. */
    fun webUri(pkg: String): String = "https://$PLAY_HOST/store/apps/details?id=$pkg"

    private fun intent(parts: Parts): String? {
        val extras = intentFields(parts.fragment) ?: return null
        val target = extras["package"]
        val scheme = extras["scheme"]?.lowercase(Locale.ROOT)
            ?: if (target == PLAY_STORE_PACKAGE) "market" else null
        // The data the intent carries is everything before `#`, under its own scheme.
        val inner = scheme?.let { "$it:" + parts.body.substringBefore('#') }
        val direct = if (scheme == "market" || scheme == "https" || scheme == "http") inner?.let(::playPackage) else null
        // The fallback is a web page by contract; nothing else is followed.
        return direct ?: extras["S.browser_fallback_url"]
            ?.takeIf { Parts.of(it)?.scheme.let { s -> s == "https" || s == "http" } }
            ?.let(::playPackage)
    }

    /** `Intent;key=value;...;end`, values percent-decoded. Null when not that shape. */
    private fun intentFields(fragment: String?): Map<String, String>? {
        if (fragment == null || !fragment.startsWith("Intent;")) return null
        val out = HashMap<String, String>()
        for (field in fragment.removePrefix("Intent;").split(';')) {
            if (field == "end") return out
            val eq = field.indexOf('=')
            if (eq > 0) out[field.substring(0, eq)] = decode(field.substring(eq + 1))
        }
        return null
    }

    /** `%XX` decoding only: `+` stays a plus, as in a URI. Bad escapes stay as they are. */
    private fun decode(s: String): String {
        if ('%' !in s) return s
        val out = ByteArrayOutputStream()
        var i = 0
        while (i < s.length) {
            val c = s[i]
            val hex = if (c == '%' && i + 2 < s.length) s.substring(i + 1, i + 3).toIntOrNull(16) else null
            if (hex != null) {
                out.write(hex)
                i += 3
            } else {
                out.write(c.toString().toByteArray(Charsets.UTF_8))
                i++
            }
        }
        return out.toString(Charsets.UTF_8.name())
    }

    /** Scheme, host, path, query and fragment, split by hand: lenient where `java.net.URI` throws. */
    private class Parts(val scheme: String, val body: String, val host: String?, val path: String, val query: String?, val fragment: String?) {
        fun packageId(): String? = query
            ?.split('&')
            ?.firstOrNull { it.substringBefore('=') == "id" }
            ?.substringAfter('=', "")
            ?.let(::decode)
            ?.takeIf { it.length <= 255 && PACKAGE.matches(it) }

        companion object {
            fun of(url: String): Parts? {
                val colon = url.indexOf(':')
                if (colon <= 0) return null
                val scheme = url.substring(0, colon).lowercase(Locale.ROOT)
                if (!scheme.all { it.isLetterOrDigit() || it == '+' || it == '-' || it == '.' }) return null
                val body = url.substring(colon + 1)
                val fragment = body.substringAfter('#', "").takeIf { '#' in body }
                val beforeFragment = body.substringBefore('#')
                var rest = beforeFragment
                var host: String? = null
                if (rest.startsWith("//")) {
                    val authority = rest.substring(2).takeWhile { it != '/' && it != '?' }
                    host = authority.substringAfterLast('@').substringBefore(':').lowercase(Locale.ROOT)
                    rest = rest.substring(2 + authority.length)
                }
                val query = rest.substringAfter('?', "").takeIf { '?' in rest }
                return Parts(scheme, body, host, rest.substringBefore('?'), query, fragment)
            }
        }
    }
}
