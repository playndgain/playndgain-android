package com.playndgain.sdk

import android.app.Activity
import android.content.Context
import com.playndgain.sdk.internal.Prefs
import com.playndgain.sdk.internal.Validation
import com.playndgain.sdk.internal.MainThread
import com.playndgain.sdk.ui.PlayndgainActivity

/**
 * The native offerwall.
 *
 * ```
 * Playndgain.init(applicationContext, "YOUR_APP_ID")
 * Playndgain.setUser("4812") { appId, userId, callback -> /* ask your server */ }
 * Playndgain.show(activity)
 * ```
 *
 * The SDK never holds your secret key. Your server signs, the app passes the
 * signature through. Rewards arrive at your server by postback; the SDK only
 * tells you when to look.
 */
public object Playndgain {
    public const val VERSION: String = BuildConfig.SDK_VERSION

    @Volatile internal var appContext: Context? = null
        private set
    @Volatile internal var appId: String? = null
        private set
    @Volatile internal var config: PlayndgainConfig = PlayndgainConfig()
        private set
    @Volatile internal var userId: String? = null
        private set
    @Volatile internal var signatureProvider: SignatureProvider? = null
        private set
    @Volatile private var listener: PlayndgainListener? = null
    /** Bumped whenever the user changes, so a cached signature is never reused for another user. */
    @Volatile internal var session: Int = 0
        private set

    /** Idempotent and non-blocking. A different app id resets the user. */
    @JvmStatic
    @JvmOverloads
    public fun init(context: Context, appId: String, config: PlayndgainConfig = PlayndgainConfig()) {
        if (this.appId != null && this.appId != appId) reset()
        this.appContext = context.applicationContext
        this.appId = appId.trim()
        this.config = config
    }

    /**
     * Who is playing. [userId] is your own opaque, stable identifier: letters,
     * digits and `_ - . @ : |`, at most 191 characters.
     */
    @JvmStatic
    public fun setUser(userId: String, signatureProvider: SignatureProvider) {
        if (!Validation.isUserId(userId)) {
            // Never keep showing the previous player's wall.
            reset()
            emitError(PlayndgainError.INVALID_USER_ID)
            return
        }
        this.userId = userId
        this.signatureProvider = signatureProvider
        session++
    }

    /** Held strongly. Pass null when the screen that set it is destroyed. */
    @JvmStatic
    public fun setListener(listener: PlayndgainListener?) {
        this.listener = listener
    }

    @JvmStatic
    @JvmOverloads
    public fun show(activity: Activity, options: PlayndgainOptions = PlayndgainOptions()) {
        when {
            appId.isNullOrEmpty() || appContext == null -> emitError(PlayndgainError.NOT_INITIALIZED)
            userId == null || signatureProvider == null -> emitError(PlayndgainError.USER_NOT_SET)
            !options.subIds.all(Validation::isSubId) || options.subIds.size > 5 -> emitError(PlayndgainError.INVALID_SUB_ID)
            else -> activity.startActivity(PlayndgainActivity.intent(activity, options))
        }
    }

    /** Forgets the user and the provider. Call it when the player logs out. */
    @JvmStatic
    public fun reset() {
        userId = null
        signatureProvider = null
        session++
    }

    /**
     * Stored for a later version. In 1.0 nothing reads or sends an
     * advertising ID, whatever this is set to.
     */
    @JvmStatic
    public fun setAdvertisingIdEnabled(enabled: Boolean) {
        appContext?.let { Prefs.setAdIdEnabled(it, enabled) }
    }

    /** When true the advertising-ID switch is ignored for good, as COPPA-style rules require. */
    @JvmStatic
    public fun setChildDirected(childDirected: Boolean) {
        appContext?.let { Prefs.setChildDirected(it, childDirected) }
    }

    internal fun emitOpened() = MainThread.post { listener?.onOpened() }
    internal fun emitClosed() = MainThread.post { listener?.onClosed() }
    internal fun emitRewardCheck() = MainThread.post { listener?.onRewardCheck() }
    internal fun emitError(error: PlayndgainError) = MainThread.post { listener?.onError(error) }
}

public enum class PlayndgainTheme { APP, LIGHT, DARK, SYSTEM }

public data class PlayndgainConfig @JvmOverloads constructor(
    /** Overridable for local testing. `http` is refused unless the host app is debuggable. */
    val baseUrl: String = "https://playndgain.com",
    /** Logs request outcomes. Never logs URLs (they carry the signature) or offer names. */
    val debugLogging: Boolean = false,
)

public data class PlayndgainOptions @JvmOverloads constructor(
    val theme: PlayndgainTheme = PlayndgainTheme.APP,
    /** `en` or `ar`. Null follows the device. */
    val language: String? = null,
    /** Shown in the header, e.g. "1,200 coins". */
    val balance: String? = null,
    /** Replaces the app name in the header. */
    val title: String? = null,
    /** Up to five, each `[A-Za-z0-9._:@|-]{1,100}`. Echoed as {sub1}..{sub5} on the postback. */
    val subIds: List<String> = emptyList(),
)

public fun interface SignatureProvider {
    /**
     * Ask your server for `sha256(appId + userId + secret)` and call back once,
     * on any thread. Call back with an empty string if your app does not
     * require signed walls.
     */
    public fun provide(appId: String, userId: String, callback: SignatureCallback)
}

public interface SignatureCallback {
    public fun onSignature(sig: String)
    public fun onFailure(error: Throwable?)
}

public interface PlayndgainListener {
    public fun onOpened() {}
    public fun onClosed() {}
    /** The player came back from an offer. Refresh the balance from your server. Never credit from this. */
    public fun onRewardCheck() {}
    public fun onError(error: PlayndgainError) {}
}

public enum class PlayndgainError {
    NOT_INITIALIZED,
    USER_NOT_SET,
    INVALID_USER_ID,
    INVALID_SUB_ID,
    SIGNATURE_UNAVAILABLE,
    NETWORK,
    RATE_LIMITED,
    WALL_UNAVAILABLE,
    INTERNAL,
}
