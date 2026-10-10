package com.playndgain.sdk.internal

import android.content.Context
import android.content.pm.ApplicationInfo
import android.content.res.Configuration
import android.util.Log
import com.playndgain.sdk.Playndgain
import com.playndgain.sdk.PlayndgainError
import com.playndgain.sdk.PlayndgainOptions
import com.playndgain.sdk.SignatureCallback
import org.json.JSONException
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URI
import java.net.URL
import java.util.Locale
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit

/** A failure the UI can name. */
internal class ApiException(val error: PlayndgainError, cause: Throwable? = null) : Exception(error.name, cause)

/**
 * `/sdk/v1` over `HttpURLConnection`, so the SDK brings no HTTP library into a
 * host app that may pin a different one. Blocking: call it off the main thread.
 */
internal class ApiClient(
    private val context: Context,
    private val options: PlayndgainOptions,
) {
    private val base: String = Playndgain.config.baseUrl.trimEnd('/')
    /**
     * Built on first use, which is always a request on the I/O thread. The
     * first WebView User-Agent read loads the WebView provider, which takes
     * seconds on a cold device; on the main thread that is an ANR.
     */
    private val device by lazy { DeviceInfo.of(context) }
    /** The signature and the user session it was issued for. */
    @Volatile private var sig: Pair<Int, String>? = null

    init {
        val debuggable = (context.applicationInfo.flags and ApplicationInfo.FLAG_DEBUGGABLE) != 0
        if (!Responses.schemeAllowed(base, debuggable)) throw ApiException(PlayndgainError.INTERNAL)
    }

    fun wall(sort: String?): WallResponse = get("wall", mapOfNotNull("sort" to sort))
    fun offer(id: Int): WallResponse = get("offers/$id")
    fun history(): WallResponse = get("history")
    fun consent(accept: Boolean): WallResponse = request("POST", "consent", emptyMap(), "action=" + if (accept) "accept" else "decline")

    private fun get(path: String, extra: Map<String, String> = emptyMap()) = request("GET", path, extra, null)

    private fun request(method: String, path: String, extra: Map<String, String>, form: String?): WallResponse {
        val session = Playndgain.session
        val signature = sig?.takeIf { it.first == session }?.second
            ?: fetchSignature().also { sig = session to it }
        // Everything in the query, the POST included: the wall's per-app rate
        // limiter keys on the query string's appid.
        val url = URL("$base/sdk/v1/$path?" + query(signature, extra))
        val conn = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = method
            connectTimeout = 15_000
            readTimeout = 15_000
            useCaches = false
            setRequestProperty("Accept", "application/json")
            setRequestProperty("User-Agent", device.userAgent)
            if (form != null) {
                doOutput = true
                setRequestProperty("Content-Type", "application/x-www-form-urlencoded")
            }
        }

        try {
            if (form != null) conn.outputStream.use { it.write(form.toByteArray()) }
            val code = conn.responseCode
            val stream = if (code >= 400) conn.errorStream else conn.inputStream
            val body = stream?.bufferedReader()?.use { it.readText() }.orEmpty()
            log("$method $path -> $code")

            return Responses.classify(code, body)
        } catch (e: IOException) {
            throw ApiException(PlayndgainError.NETWORK, e)
        } finally {
            conn.disconnect()
        }
    }

    private fun query(signature: String, extra: Map<String, String>): String {
        val night = (context.resources.configuration.uiMode and Configuration.UI_MODE_NIGHT_MASK) == Configuration.UI_MODE_NIGHT_YES
        return Fields.query(
            appId = Playndgain.appId.orEmpty(),
            userId = Playndgain.userId.orEmpty(),
            sig = signature,
            options = options,
            model = device.model,
            hint = device.hint,
            ds = device.ds,
            version = Playndgain.VERSION,
            systemDark = night,
            extra = extra,
        )
    }

    /** Asks the host app once per screen, waits at most 20 seconds. */
    private fun fetchSignature(): String {
        val provider = Playndgain.signatureProvider ?: throw ApiException(PlayndgainError.USER_NOT_SET)
        if (Playndgain.userId == null) throw ApiException(PlayndgainError.USER_NOT_SET)
        val latch = CountDownLatch(1)
        var result: String? = null
        var failed = false

        MainThread.post {
            try {
                provider.provide(Playndgain.appId.orEmpty(), Playndgain.userId.orEmpty(), object : SignatureCallback {
                    override fun onSignature(sig: String) { result = sig.trim().lowercase(); latch.countDown() }
                    override fun onFailure(error: Throwable?) { failed = true; latch.countDown() }
                })
            } catch (_: Exception) {
                // The host's provider threw. Report it as a missing signature
                // rather than crash the host on its main thread.
                failed = true
                latch.countDown()
            }
        }

        if (!latch.await(20, TimeUnit.SECONDS) || failed) throw ApiException(PlayndgainError.SIGNATURE_UNAVAILABLE)
        return result.orEmpty()
    }

    private fun log(message: String) {
        // Never the URL: it carries the signature.
        if (Playndgain.config.debugLogging) Log.d("Playndgain", message)
    }

    private fun mapOfNotNull(vararg pairs: Pair<String, String?>): Map<String, String> =
        pairs.mapNotNull { (k, v) -> v?.let { k to it } }.toMap()
}

/** Pure response handling, so the JVM unit tests can pin it. */
internal object Responses {
    /** `https` always; `http` only for a debuggable host app. Case-insensitive. */
    fun schemeAllowed(base: String, debuggable: Boolean): Boolean {
        val scheme = scheme(base) ?: return false
        return scheme == "https" || (debuggable && scheme == "http")
    }

    fun host(url: String): String? = try {
        URI(url.trim()).host?.lowercase(Locale.ROOT)
    } catch (_: Exception) { null }

    private fun scheme(url: String): String? = try {
        URI(url.trim()).scheme?.lowercase(Locale.ROOT)
    } catch (_: Exception) { null }

    /**
     * A click URL is opened only on the wall's own host and only over https,
     * so a tampered or malformed response cannot send the player elsewhere.
     * The one exception is a local `http` base, which [schemeAllowed] admits
     * only for a debuggable host app: its clicks share that scheme.
     */
    fun clickAllowed(url: String, base: String): Boolean {
        val wallHost = host(base) ?: return false
        val s = scheme(url) ?: return false
        return host(url) == wallHost && (s == "https" || s == scheme(base))
    }

    /**
     * The body decides first. A JSON envelope is returned whatever the HTTP
     * code, so the UI can show its notice. Anything else (a challenge page,
     * a proxy error, an empty body) is unavailable, except a bare 429.
     */
    fun classify(code: Int, body: String): WallResponse {
        val parsed = try {
            if (JSONObject(body).has("status")) Parser.parse(code, body) else null
        } catch (_: JSONException) {
            null
        } catch (_: RuntimeException) {
            null
        }
        if (parsed == null) {
            throw ApiException(if (code == 429) PlayndgainError.RATE_LIMITED else PlayndgainError.WALL_UNAVAILABLE)
        }
        if (parsed.status == "invalid" && parsed.app == null) {
            // The wall refuses a missing or wrong signature with 403. A bad user
            // id, an unknown app or path (400/404/405) is not the signature.
            throw ApiException(if (code == 403) PlayndgainError.SIGNATURE_UNAVAILABLE else PlayndgainError.WALL_UNAVAILABLE)
        }
        return parsed
    }
}
