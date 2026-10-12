package com.playndgain.sdk.internal

import android.app.ActivityManager
import android.content.Context
import android.os.Build
import android.util.Base64
import android.webkit.WebSettings
import com.playndgain.sdk.Playndgain
import com.playndgain.sdk.PlayndgainOptions
import com.playndgain.sdk.PlayndgainTheme
import org.json.JSONObject
import java.net.URLEncoder
import java.util.Locale

/**
 * Everything the SDK sends about the device, in one place.
 *
 * Fraud parity with the iframe is the rule here. The wall's checks read the
 * User-Agent, so the SDK sends the exact UA a WebView in this app would send.
 * The build string also goes as `dh` for the emulator rule: before 1.1 an
 * offer click opened in the system browser, whose reduced UA has none.
 */
internal data class DeviceInfo(
    val userAgent: String,
    val model: String,
    val hint: String,
    val ds: String,
) {
    companion object {
        @Volatile private var cached: DeviceInfo? = null

        fun of(context: Context): DeviceInfo = cached ?: build(context).also { cached = it }

        private fun build(context: Context): DeviceInfo {
            val model = Validation.model(Build.MODEL)
            val webViewUa = try {
                WebSettings.getDefaultUserAgent(context)
            } catch (_: Throwable) {
                // No WebView provider installed. Still a UA the wall can place.
                "Mozilla/5.0 (Linux; Android ${Build.VERSION.RELEASE}; $model)"
            }
            val hint = Validation.hint(
                listOf(Build.MODEL, Build.PRODUCT, Build.DEVICE, Build.HARDWARE, Build.FINGERPRINT),
            )
            return DeviceInfo(
                userAgent = Fields.userAgent(webViewUa, Playndgain.VERSION),
                model = model,
                hint = hint,
                ds = Fields.ds(
                    renderer = "android:" + Build.HARDWARE.orEmpty(),
                    cores = Runtime.getRuntime().availableProcessors(),
                    memoryGb = memoryGb(context),
                    ratio = context.resources.displayMetrics.density.toDouble(),
                ),
            )
        }

        private fun memoryGb(context: Context): Double {
            val am = context.getSystemService(Context.ACTIVITY_SERVICE) as? ActivityManager ?: return 0.0
            val info = ActivityManager.MemoryInfo().also { am.getMemoryInfo(it) }
            return Fields.bucketMemory(info.totalMem / (1024.0 * 1024.0 * 1024.0))
        }
    }
}

/** Pure functions, so the JVM unit tests can pin the wire format. */
internal object Fields {
    /**
     * HttpURLConnection rejects header values outside printable ASCII, and
     * some OEM model names carry other characters, so those are dropped.
     */
    fun userAgent(webViewUa: String, version: String): String =
        "$webViewUa PlayndgainSDK/$version".filter { it in ' '..'~' }.trim()

    /**
     * The browser's `navigator.deviceMemory` reports a power of two capped at 8,
     * which is what `resources/js/wall.js` sends. Same bucketing here.
     */
    fun bucketMemory(gb: Double): Double {
        if (gb <= 0) return 0.0
        val buckets = listOf(0.25, 0.5, 1.0, 2.0, 4.0, 8.0)
        return buckets.minByOrNull { kotlin.math.abs(it - gb) } ?: 8.0
    }

    /**
     * The `ds` token in the exact shape `App\Identity\DeviceSignals::decode()`
     * accepts: base64url JSON of {c, m, p, r, t, w}, renderer at most 80
     * characters, the whole token at most 256. `t` is 5, what a phone browser
     * reports for touch points; `w` is 0 because native has no webdriver flag.
     */
    fun ds(renderer: String, cores: Int, memoryGb: Double, ratio: Double): String {
        val json = JSONObject()
            .put("c", cores)
            .put("m", memoryGb)
            .put("p", ratio)
            .put("r", renderer.take(80))
            .put("t", 5)
            .put("w", 0)
            .toString()
        val token = base64Url(json.toByteArray(Charsets.UTF_8))
        return if (token.length <= 256) token else ""
    }

    internal var base64Url: (ByteArray) -> String = { bytes ->
        Base64.encodeToString(bytes, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP)
    }

    /** The query every request carries, in the order the wall reads it. */
    fun query(
        appId: String,
        userId: String,
        sig: String?,
        options: PlayndgainOptions,
        model: String,
        hint: String,
        ds: String,
        version: String,
        deviceLanguage: String = Locale.getDefault().language,
        systemDark: Boolean = false,
        extra: Map<String, String> = emptyMap(),
    ): String {
        val lang = (options.language ?: deviceLanguage).lowercase(Locale.ROOT).let { if (it == "ar") "ar" else "en" }
        val theme = when (options.theme) {
            PlayndgainTheme.APP -> null
            PlayndgainTheme.LIGHT -> "light"
            PlayndgainTheme.DARK -> "dark"
            PlayndgainTheme.SYSTEM -> if (systemDark) "dark" else "light"
        }
        val params = linkedMapOf<String, String?>(
            "appid" to appId,
            "userid" to userId,
            "sig" to sig?.takeIf { it.isNotEmpty() },
            "lang" to lang,
            "theme" to theme,
            "balance" to options.balance,
            "sdk" to "android",
            "sdk_v" to version,
            "model" to model.takeIf { it.isNotEmpty() },
            "dh" to hint.takeIf { it.isNotEmpty() },
            "ds" to ds.takeIf { it.isNotEmpty() },
        )
        options.subIds.take(5).forEachIndexed { i, v -> params["sub${i + 1}"] = v }
        params.putAll(extra)

        return params.entries
            .filter { !it.value.isNullOrEmpty() }
            .joinToString("&") { "${enc(it.key)}=${enc(it.value!!)}" }
    }

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8")
}
