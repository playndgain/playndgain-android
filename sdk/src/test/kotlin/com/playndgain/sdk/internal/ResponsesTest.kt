package com.playndgain.sdk.internal

import com.playndgain.sdk.PlayndgainError
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test

class ResponsesTest {
    private fun fixture(name: String): String =
        javaClass.classLoader!!.getResource("fixtures/$name.json")!!.readText()

    private fun errorOf(code: Int, body: String): PlayndgainError = try {
        Responses.classify(code, body)
        fail("expected an ApiException")
        throw IllegalStateException()
    } catch (e: ApiException) {
        e.error
    }

    @Test
    fun `release builds accept only https, in any case`() {
        assertTrue(Responses.schemeAllowed("https://wall.example.com", debuggable = false))
        assertTrue(Responses.schemeAllowed("HTTPS://wall.example.com", debuggable = false))
        assertFalse(Responses.schemeAllowed("http://wall.example.com", debuggable = false))
        assertFalse(Responses.schemeAllowed("HTTP://wall.example.com", debuggable = false))
        assertFalse(Responses.schemeAllowed("Http://wall.example.com", debuggable = false))
        assertFalse(Responses.schemeAllowed("ftp://wall.example.com", debuggable = false))
        assertFalse(Responses.schemeAllowed("wall.example.com", debuggable = false))
        assertFalse(Responses.schemeAllowed("", debuggable = false))
        assertTrue(Responses.schemeAllowed("HTTP://10.0.2.2:8000", debuggable = true))
        assertFalse(Responses.schemeAllowed("ftp://10.0.2.2", debuggable = true))
    }

    @Test
    fun `a body that is not an envelope is unavailable on any code`() {
        listOf(200, 400, 403, 404, 500, 502, 503).forEach { code ->
            assertEquals("$code html", PlayndgainError.WALL_UNAVAILABLE, errorOf(code, "<html>challenge</html>"))
            assertEquals("$code empty", PlayndgainError.WALL_UNAVAILABLE, errorOf(code, ""))
            assertEquals("$code json without status", PlayndgainError.WALL_UNAVAILABLE, errorOf(code, "{\"message\":\"Server Error\"}"))
            assertEquals("$code json array", PlayndgainError.WALL_UNAVAILABLE, errorOf(code, "[]"))
        }
    }

    @Test
    fun `a bare 429 is rate limited`() {
        assertEquals(PlayndgainError.RATE_LIMITED, errorOf(429, "Too Many Attempts."))
        assertEquals(PlayndgainError.RATE_LIMITED, errorOf(429, ""))
    }

    @Test
    fun `a rate limited envelope is returned so its notice can be shown`() {
        val body = """{"status":"rate_limited","strings":{"too_many":"Slow down"},"notice":{"key":"rate_limited","title":"Too many requests","body":"Try again in a minute."}}"""
        val r = Responses.classify(429, body)
        assertEquals("rate_limited", r.status)
        assertEquals("Too many requests", r.notice!!.title)
        assertEquals("Slow down", r.strings["too_many"])
    }

    @Test
    fun `envelopes pass through whatever the http code`() {
        assertEquals("blocked", Responses.classify(403, fixture("blocked")).status)
        assertEquals("ok", Responses.classify(200, fixture("wall_ok")).status)
        assertEquals("unplaced", Responses.classify(200, fixture("unplaced")).status)
    }

    @Test
    fun `an invalid envelope is a signature problem only on 403`() {
        assertEquals(PlayndgainError.SIGNATURE_UNAVAILABLE, errorOf(403, fixture("invalid")))
        // Unknown app, bad user id, wrong path: not the signature.
        assertEquals(PlayndgainError.WALL_UNAVAILABLE, errorOf(400, fixture("invalid")))
        assertEquals(PlayndgainError.WALL_UNAVAILABLE, errorOf(404, fixture("invalid")))
    }

    @Test
    fun `click urls must be https on the wall host`() {
        val base = "https://wall.example.com"
        assertTrue(Responses.clickAllowed("https://wall.example.com/click/12?x=1", base))
        assertTrue(Responses.clickAllowed("HTTPS://WALL.example.com/click/12", base))
        assertFalse(Responses.clickAllowed("http://wall.example.com/click/12", base))
        assertFalse(Responses.clickAllowed("https://elsewhere.example/click/12", base))
        assertFalse(Responses.clickAllowed("https://wall.example.com.evil.test/click/12", base))
        assertFalse(Responses.clickAllowed("javascript:alert(1)", base))
        assertFalse(Responses.clickAllowed("intent://wall.example.com/#Intent;end", base))
        assertFalse(Responses.clickAllowed("", base))
        assertFalse(Responses.clickAllowed("not a url", base))
        // A local http base, only ever reached from a debuggable host.
        assertTrue(Responses.clickAllowed("http://10.0.2.2:8000/click/12", "http://10.0.2.2:8000"))
        assertFalse(Responses.clickAllowed("http://10.0.2.3:8000/click/12", "http://10.0.2.2:8000"))
    }

    @Test
    fun `reads the newer wall fields and falls back without them`() {
        val newer = """{"status":"ok","data":{"offers":[{"id":3,"name":"Royal Match","instructions":"old","subtitle":"Reach level 50","reward_label":"6,125","multi_reward":true,"up_to":true,"repeatable":false,"opening_label":"Opening Royal Match…"}]}}"""
        val o = Parser.parse(200, newer).offers.first()
        assertEquals("Reach level 50", o.instructions)
        assertTrue(o.upTo)
        assertFalse(o.repeatable)
        assertEquals("Opening Royal Match…", o.openingLabel)

        // A 1.0 wall: `multi_reward` meant both, and there is no cover sentence.
        val older = """{"status":"ok","data":{"offers":[{"id":3,"name":"X","instructions":"old","reward_label":"1","multi_reward":true}]}}"""
        val old = Parser.parse(200, older).offers.first()
        assertEquals("old", old.instructions)
        assertTrue(old.upTo)
        assertTrue(old.repeatable)
        assertEquals(null, old.openingLabel)

        val detail = """{"status":"ok","data":{"id":3,"name":"X","reward_label":"5","steps":[{"name":"Install","reward":"Required","required":true},{"name":"Play","reward":"5"}],"device_labels":["iOS"]}}"""
        val d = Parser.parse(200, detail).detail!!
        assertTrue(d.steps[0].required)
        assertFalse(d.steps[1].required)
        assertTrue(d.upTo)
        assertEquals(listOf("iOS"), d.deviceLabels)
    }
}
