package com.playndgain.sdk.internal

import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.os.Handler
import android.os.Looper
import android.util.LruCache
import android.widget.ImageView
import java.io.ByteArrayOutputStream
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.Executors

/**
 * Offer icons. They are small and proxied by the wall, so a tiny LRU and a
 * two-thread pool are enough; no image library rides into the host app.
 */
internal object ImageLoader {
    private val cache = object : LruCache<String, Bitmap>(4 * 1024 * 1024) {
        override fun sizeOf(key: String, value: Bitmap) = value.byteCount
    }
    private val pool = Executors.newFixedThreadPool(2)
    private val main = Handler(Looper.getMainLooper())
    /** Icons are small; anything bigger is not an icon and is not decoded. */
    private const val MAX_BYTES = 2 * 1024 * 1024

    fun load(url: String?, into: ImageView, onLoaded: (Boolean) -> Unit) {
        into.tag = url
        if (url == null) { onLoaded(false); return }
        cache.get(url)?.let { into.setImageBitmap(it); onLoaded(true); return }

        pool.execute {
            val bmp = try {
                val c = URL(url).openConnection() as HttpURLConnection
                try {
                    c.connectTimeout = 10_000
                    c.readTimeout = 10_000
                    val bytes = c.inputStream.use { readCapped(it) }
                    bytes?.let { decodeSampled(it) }
                } finally {
                    c.disconnect()
                }
            } catch (_: Throwable) {
                // Out of memory included: a broken icon must never take the host down.
                null
            }

            bmp?.let { cache.put(url, it) }
            main.post {
                if (into.tag != url) return@post
                if (bmp != null) into.setImageBitmap(bmp)
                onLoaded(bmp != null)
            }
        }
    }

    /** Icons render at most about 64 dp; decode near that, not at full size. */
    private fun decodeSampled(bytes: ByteArray, target: Int = 256): android.graphics.Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
        var sample = 1
        while (bounds.outWidth / (sample * 2) >= target && bounds.outHeight / (sample * 2) >= target) sample *= 2
        return BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
    }

    private fun readCapped(input: InputStream): ByteArray? {
        val out = ByteArrayOutputStream()
        val buf = ByteArray(16 * 1024)
        while (true) {
            val n = input.read(buf)
            if (n < 0) break
            if (out.size() + n > MAX_BYTES) return null
            out.write(buf, 0, n)
        }
        return out.toByteArray()
    }
}
