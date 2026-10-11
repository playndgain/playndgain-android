package com.playndgain.sdk.internal

import android.content.Context
import android.os.Handler
import android.os.Looper

internal object Validation {
    private val USER_ID = Regex("^[A-Za-z0-9._:@|\\-]{1,191}$")
    private val SUB_ID = Regex("^[A-Za-z0-9._:@|\\-]{1,100}$")
    private val MODEL_STRIP = Regex("[^A-Za-z0-9 ._()+,\\-]")
    private val HINT_STRIP = Regex("[^A-Za-z0-9 ._:/|,()+\\-]")

    fun isUserId(value: String): Boolean = USER_ID.matches(value)
    fun isSubId(value: String): Boolean = SUB_ID.matches(value)

    /** Same alphabet and length the wall accepts for `model`. */
    fun model(raw: String?): String = MODEL_STRIP.replace(raw.orEmpty(), "").trim().take(64)

    /** Same alphabet and length the wall accepts for `dh`. */
    fun hint(parts: List<String?>): String =
        HINT_STRIP.replace(parts.joinToString("|") { it.orEmpty() }, "").take(256)
}

internal object MainThread {
    private val handler by lazy { Handler(Looper.getMainLooper()) }
    fun post(block: () -> Unit) {
        if (Looper.myLooper() == Looper.getMainLooper()) block() else handler.post(block)
    }
}

internal object Prefs {
    private const val FILE = "com.playndgain.sdk"
    private fun prefs(context: Context) = context.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    fun setAdIdEnabled(context: Context, enabled: Boolean) =
        prefs(context).edit().putBoolean("ad_id_enabled", enabled).apply()

    fun setChildDirected(context: Context, value: Boolean) =
        prefs(context).edit().putBoolean("child_directed", value).apply()
}
