package com.playndgain.sdk.internal

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class StoreLinkTest {
    private fun pkg(url: String): String? = StoreLink.playPackage(url)

    @Test
    fun `play web listings give the package`() {
        assertEquals("com.example.game", pkg("https://play.google.com/store/apps/details?id=com.example.game"))
        assertEquals("com.example.game", pkg("https://play.google.com/store/apps/details?hl=en&id=com.example.game&referrer=utm_source%3Dx"))
        assertEquals("com.example.game", pkg("HTTPS://Play.Google.com/store/apps/details/?id=com.example.game"))
        assertEquals("com.example.game", pkg("http://play.google.com/store/apps/details?id=com.example.game#reviews"))
        assertEquals("com.example.game_2", pkg("https://play.google.com/store/apps/details?id=com.example.game%5F2"))
    }

    @Test
    fun `market links give the package`() {
        assertEquals("com.example.game", pkg("market://details?id=com.example.game"))
        assertEquals("com.example.game", pkg("MARKET://details?id=com.example.game&referrer=a%3Db"))
    }

    @Test
    fun `intent links that target play give the package`() {
        assertEquals("com.example.game", pkg("intent://details?id=com.example.game#Intent;scheme=market;package=com.android.vending;end"))
        assertEquals("com.example.game", pkg("intent://details?id=com.example.game#Intent;package=com.android.vending;end"))
        assertEquals("com.example.game", pkg("intent://play.google.com/store/apps/details?id=com.example.game#Intent;scheme=https;package=com.android.vending;end"))
    }

    @Test
    fun `intent links fall back to a play listing in browser_fallback_url`() {
        val fallback = "https%3A%2F%2Fplay.google.com%2Fstore%2Fapps%2Fdetails%3Fid%3Dcom.example.game"
        assertEquals("com.example.game", pkg("intent://open/x#Intent;scheme=game;package=com.example.game;S.browser_fallback_url=$fallback;end"))
        // A fallback that is not a listing, or not a web page, is not the store.
        assertNull(pkg("intent://open/x#Intent;scheme=game;package=com.example.game;S.browser_fallback_url=https%3A%2F%2Ftracker.example%2Fland;end"))
        assertNull(pkg("intent://open/x#Intent;scheme=game;S.browser_fallback_url=market%3A%2F%2Fdetails%3Fid%3Dcom.example.game;end"))
    }

    @Test
    fun `intent links without a play target or fallback are not the store`() {
        assertNull(pkg("intent://open/x#Intent;scheme=game;package=com.example.game;end"))
        assertNull(pkg("intent://details?id=com.example.game#Intent;scheme=game;end"))
        assertNull(pkg("intent://details?id=com.example.game"))
        assertNull(pkg("intent://details?id=com.example.game#Intent;scheme=market;package=com.android.vending"))
        assertNull(pkg("intent://details?id=com.example.game#NotIntent;scheme=market;end"))
    }

    @Test
    fun `lookalike hosts are not the store`() {
        assertNull(pkg("https://play.google.com.evil.example/store/apps/details?id=com.example.game"))
        assertNull(pkg("https://evil.example/play.google.com/store/apps/details?id=com.example.game"))
        assertNull(pkg("https://fakeplay.google.com/store/apps/details?id=com.example.game"))
        assertNull(pkg("https://play.google.com@evil.example/store/apps/details?id=com.example.game"))
        assertNull(pkg("https://tracker.example/store/apps/details?id=com.example.game"))
        assertNull(pkg("intent://play.google.com.evil.example/store/apps/details?id=com.example.game#Intent;scheme=https;end"))
    }

    @Test
    fun `listings without a valid package are not the store`() {
        assertNull(pkg("https://play.google.com/store/apps/details"))
        assertNull(pkg("https://play.google.com/store/apps/details?id="))
        assertNull(pkg("https://play.google.com/store/apps/details?id=nodots"))
        assertNull(pkg("https://play.google.com/store/apps/details?id=com.example.game;rm"))
        assertNull(pkg("https://play.google.com/store/apps/details?id=1com.example"))
        assertNull(pkg("https://play.google.com/store/apps/developer?id=com.example"))
        assertNull(pkg("https://play.google.com/store/search?q=com.example.game"))
        assertNull(pkg("market://search?q=com.example.game"))
        assertNull(pkg("market://details?package=com.example.game"))
    }

    @Test
    fun `other schemes and garbage are not the store`() {
        assertNull(pkg("game://details?id=com.example.game"))
        assertNull(pkg("javascript:location='market://details?id=com.example.game'"))
        assertNull(pkg("play.google.com/store/apps/details?id=com.example.game"))
        assertNull(pkg(""))
        assertNull(pkg(":"))
    }

    @Test
    fun `store links rebuild for the play app and the web`() {
        assertEquals("market://details?id=com.example.game", StoreLink.marketUri("com.example.game"))
        assertEquals("https://play.google.com/store/apps/details?id=com.example.game", StoreLink.webUri("com.example.game"))
        assertEquals("com.example.game", pkg(StoreLink.webUri("com.example.game")))
        assertEquals("com.example.game", pkg(StoreLink.marketUri("com.example.game")))
    }
}
