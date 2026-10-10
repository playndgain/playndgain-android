# The public API is called by name from host apps.
-keep class com.playndgain.sdk.Playndgain { *; }
-keep class com.playndgain.sdk.PlayndgainConfig { *; }
-keep class com.playndgain.sdk.PlayndgainOptions { *; }
-keep class com.playndgain.sdk.PlayndgainTheme { *; }
-keep class com.playndgain.sdk.PlayndgainError { *; }
-keep interface com.playndgain.sdk.PlayndgainListener { *; }
-keep interface com.playndgain.sdk.SignatureProvider { *; }
-keep interface com.playndgain.sdk.SignatureCallback { *; }
-keep class com.playndgain.sdk.ui.PlayndgainActivity
