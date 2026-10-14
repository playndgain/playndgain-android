package com.playndgain.sample

import android.app.Activity
import android.graphics.Color
import android.os.Bundle
import android.text.InputType
import android.view.ViewGroup.LayoutParams.MATCH_PARENT
import android.view.ViewGroup.LayoutParams.WRAP_CONTENT
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.ScrollView
import android.widget.TextView
import com.playndgain.sdk.Playndgain
import com.playndgain.sdk.PlayndgainConfig
import com.playndgain.sdk.PlayndgainError
import com.playndgain.sdk.PlayndgainListener
import com.playndgain.sdk.PlayndgainOptions
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * A test harness, not an integration example to copy. It signs on the device
 * with a secret typed into a field, which a real app must never do: a real app
 * asks its own server for the signature. See README.md.
 */
class MainActivity : Activity() {
    private lateinit var log: TextView

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val col = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(40, 60, 40, 40) }

        fun field(hint: String, value: String, password: Boolean = false) = EditText(this).apply {
            this.hint = hint
            setText(value)
            if (password) inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD
            col.addView(this, LinearLayout.LayoutParams(MATCH_PARENT, WRAP_CONTENT))
        }

        val base = field("Base URL", intent.getStringExtra("base") ?: "http://10.0.2.2:8000")
        val appId = field("App ID", intent.getStringExtra("appid") ?: "10000")
        val user = field("User ID", intent.getStringExtra("userid") ?: "USER_1")
        val secret = field("Secret: local testing only, never ship this", "", password = true)
        val lang = field("Language (en / ar, blank = device)", intent.getStringExtra("lang") ?: "")
        val sub1 = field("sub1 (optional)", "sample")
        val balance = field("Balance shown in the header (optional)", intent.getStringExtra("balance") ?: "")

        Button(this).apply {
            text = "Show"
            setOnClickListener {
                Playndgain.init(applicationContext, appId.text.toString(), PlayndgainConfig(baseUrl = base.text.toString(), debugLogging = true))
                Playndgain.setUser(user.text.toString()) { a, u, cb ->
                    val s = secret.text.toString()
                    cb.onSignature(if (s.isEmpty()) "" else sha256(a + u + s))
                }
                Playndgain.show(this@MainActivity, PlayndgainOptions(
                    language = lang.text.toString().ifBlank { null },
                    balance = balance.text.toString().ifBlank { null },
                    subIds = listOfNotNull(sub1.text.toString().ifBlank { null }),
                ))
            }
            col.addView(this)
        }
        Button(this).apply {
            text = "Reset"
            setOnClickListener { Playndgain.reset(); write("reset") }
            col.addView(this)
        }

        log = TextView(this).apply { setTextColor(Color.DKGRAY); textSize = 12f; setPadding(0, 30, 0, 0) }
        col.addView(log)
        setContentView(ScrollView(this).apply { addView(col) })

        Playndgain.setListener(object : PlayndgainListener {
            override fun onOpened() = write("onOpened")
            override fun onClosed() = write("onClosed")
            override fun onRewardCheck() = write("onRewardCheck")
            override fun onError(error: PlayndgainError) = write("onError $error")
        })
    }

    override fun onDestroy() {
        Playndgain.setListener(null)
        super.onDestroy()
    }

    private fun write(event: String) {
        val t = SimpleDateFormat("HH:mm:ss", Locale.US).format(Date())
        log.append("$t  $event\n")
    }

    private fun sha256(s: String): String =
        MessageDigest.getInstance("SHA-256").digest(s.toByteArray()).joinToString("") { "%02x".format(it) }
}
