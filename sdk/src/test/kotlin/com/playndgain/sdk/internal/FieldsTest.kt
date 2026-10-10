package com.playndgain.sdk.internal

import com.playndgain.sdk.PlayndgainOptions
import com.playndgain.sdk.PlayndgainTheme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.json.JSONObject
import java.net.URLDecoder
import java.util.Base64

class FieldsTest {
    @Before
    fun jvmBase64() {
        // android.util.Base64 is a stub on the JVM.
        Fields.base64Url = { Base64.getUrlEncoder().withoutPadding().encodeToString(it) }
    }

    private fun params(q: String): Map<String, String> = q.split("&").associate {
        val (k, v) = it.split("=", limit = 2)
        URLDecoder.decode(k, "UTF-8") to URLDecoder.decode(v, "UTF-8")
    }

    @Test
    fun `query carries every wall parameter and the sdk evidence`() {
        val q = params(Fields.query(
            appId = "10000", userId = "USER_1", sig = "abc",
            options = PlayndgainOptions(theme = PlayndgainTheme.DARK, language = "ar", balance = "1,200 coins", subIds = listOf("camp", "b")),
            model = "Pixel 7", hint = "Pixel 7|panther", ds = "tok", version = "1.0.0",
        ))

        assertEquals("10000", q["appid"])
        assertEquals("USER_1", q["userid"])
        assertEquals("abc", q["sig"])
        assertEquals("ar", q["lang"])
        assertEquals("dark", q["theme"])
        assertEquals("1,200 coins", q["balance"])
        assertEquals("android", q["sdk"])
        assertEquals("1.0.0", q["sdk_v"])
        assertEquals("Pixel 7", q["model"])
        assertEquals("Pixel 7|panther", q["dh"])
        assertEquals("tok", q["ds"])
        assertEquals("camp", q["sub1"])
        assertEquals("b", q["sub2"])
        assertFalse(q.containsKey("sub3"))
    }

    @Test
    fun `app theme and an empty signature send nothing`() {
        val q = params(Fields.query("1", "U", "", PlayndgainOptions(), "", "", "", "1.0.0", deviceLanguage = "fr"))
        assertNull(q["theme"])
        assertNull(q["sig"])
        assertNull(q["dh"])
        assertEquals("en", q["lang"])
    }

    @Test
    fun `user agent is the webview ua plus the sdk marker`() {
        val ua = Fields.userAgent("Mozilla/5.0 (Linux; Android 14; sdk_gphone64_arm64 Build/UE1A; wv) Chrome/129", "1.0.0")
        assertTrue(ua.endsWith(" PlayndgainSDK/1.0.0"))
        // The emulator signature has to survive into what the wall reads.
        assertTrue(ua.lowercase().contains("sdk_gphone"))
        assertTrue(ua.lowercase().contains("android 14"))
    }

    @Test
    fun `user agent keeps only printable ascii`() {
        val ua = Fields.userAgent("Mozilla/5.0 (Linux; Android 14; Galaxy\u00A0S24 \u7D05\u7C73 Note\u2122\tX\n) Chrome/129", "1.0.0")
        assertTrue(ua.all { it in ' '..'~' })
        assertEquals("Mozilla/5.0 (Linux; Android 14; GalaxyS24  NoteX) Chrome/129 PlayndgainSDK/1.0.0", ua)
        // An already clean UA is untouched.
        val clean = "Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/129"
        assertEquals("$clean PlayndgainSDK/1.0.0", Fields.userAgent(clean, "1.0.0"))
    }

    @Test
    fun `ds token has the shape the wall decodes`() {
        val token = Fields.ds("android:" + "x".repeat(200), cores = 8, memoryGb = 8.0, ratio = 2.625)
        assertTrue(token.length <= 256)
        val json = JSONObject(String(Base64.getUrlDecoder().decode(token)))
        assertEquals(setOf("c", "m", "p", "r", "t", "w"), json.keys().asSequence().toSet())
        assertEquals(80, json.getString("r").length)
        assertEquals(8, json.getInt("c"))
        assertEquals(0, json.getInt("w"))
    }

    @Test
    fun `memory buckets like the browser does`() {
        assertEquals(8.0, Fields.bucketMemory(11.5), 0.0)
        assertEquals(4.0, Fields.bucketMemory(3.7), 0.0)
        assertEquals(2.0, Fields.bucketMemory(1.9), 0.0)
        assertEquals(0.0, Fields.bucketMemory(0.0), 0.0)
    }

    @Test
    fun `validation matches the wall`() {
        assertTrue(Validation.isUserId("u_4812:a@b|c-d.e"))
        assertFalse(Validation.isUserId("has space"))
        assertFalse(Validation.isUserId("x".repeat(192)))
        assertTrue(Validation.isSubId("spring_promo"))
        assertFalse(Validation.isSubId("x".repeat(101)))
        assertEquals("Pixel 7 Pro script", Validation.model("Pixel 7 Pro <script>"))
        assertEquals(64, Validation.model("a".repeat(100)).length)
        assertEquals("sdk_gphone64_arm64|emu64a|google/sdk:14/UE1A", Validation.hint(listOf("sdk_gphone64_arm64", "emu64a", "google/sdk:14/UE1A")))
        assertEquals(256, Validation.hint(listOf("a".repeat(300))).length)
    }

    @Test
    fun `colours parse every form the wall theme allows`() {
        assertEquals(0xFFFFB020.toInt(), Colors.parse("#FFB020", 0))
        assertEquals(0xFFFFAA00.toInt(), Colors.parse("#FA0", 0))
        assertEquals(0x80FFB020.toInt(), Colors.parse("#FFB02080", 0))
        assertEquals(7, Colors.parse("nope", 7))
    }
}

class ParserTest {
    private fun fixture(name: String): String =
        javaClass.classLoader!!.getResource("fixtures/$name.json")!!.readText()

    @Test
    fun `parses the wall`() {
        val r = Parser.parse(200, fixture("wall_ok"))
        assertEquals("ok", r.status)
        assertNotNull(r.app)
        assertEquals("Coins", r.app!!.currencyName)
        assertEquals(14, r.app!!.theme.radius)
        assertTrue(r.offers.isNotEmpty())
        assertTrue(r.offers.first().clickUrl.contains("/click/"))
        assertEquals("Earn Coins", r.strings["earn"])
        assertFalse(fixture("wall_ok").contains("\"payout\""))
    }

    @Test
    fun `parses offer detail, history, a block and an invalid link`() {
        val d = Parser.parse(200, fixture("offer_ok")).detail
        assertNotNull(d)
        assertEquals(4, d!!.important.size)
        assertTrue(d.steps.isNotEmpty())

        val h = Parser.parse(200, fixture("history_ok"))
        assertEquals("Partner wall", h.history.first().partner)
        assertEquals("Credited", h.history.first().statusLabel)

        val b = Parser.parse(403, fixture("blocked"))
        assertEquals("blocked", b.status)
        assertEquals("blocked_unavailable", b.notice!!.key)
        assertTrue(b.offers.isEmpty())

        assertEquals("unplaced", Parser.parse(200, fixture("unplaced")).status)
        assertEquals("invalid", Parser.parse(400, fixture("invalid")).status)
    }

    @Test
    fun `strings fall back on an older server`() {
        val s = Strings(null)
        assertEquals("", s["anything"])
        assertEquals("Offers", s.or("tabs.offers", "Offers"))
    }
}
