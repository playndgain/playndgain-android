package com.playndgain.sdk.ui

import com.playndgain.sdk.ui.OfferResolution.Kind
import com.playndgain.sdk.ui.OfferResolution.Phase
import com.playndgain.sdk.ui.OfferResolution.Step
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class OfferResolutionTest {
    private val click = "https://wall.example/click/7?t=1"
    private val tracker = "https://tracker.example/c/abc"
    private val store = "https://play.google.com/store/apps/details?id=com.example.game"
    private val market = "market://details?id=com.example.game"
    private val deepLink = "game://open?ref=1"
    private val listing = OfferOutcome.Store("com.example.game")

    @Test
    fun `classifies each kind of navigation`() {
        assertEquals(Kind.Web, OfferResolution.classify(click))
        assertEquals(Kind.Store("com.example.game"), OfferResolution.classify(store))
        assertEquals(Kind.Store("com.example.game"), OfferResolution.classify(market))
        assertEquals(Kind.Store("com.example.game"), OfferResolution.classify("intent://details?id=com.example.game#Intent;scheme=market;package=com.android.vending;end"))
        assertEquals(Kind.App(deepLink), OfferResolution.classify(deepLink))
        val intent = "intent://open#Intent;scheme=game;package=com.example.game;end"
        assertEquals(Kind.App(intent), OfferResolution.classify(intent))
        assertEquals(Kind.Quiet, OfferResolution.classify("about:blank"))
        assertEquals(Kind.Blocked, OfferResolution.classify("file:///sdcard/x"))
        assertEquals(Kind.Blocked, OfferResolution.classify("content://x/y"))
        assertEquals(Kind.Web, OfferResolution.classify("https://play.google.com.evil.example/store/apps/details?id=com.example.game"))
    }

    @Test
    fun `a redirect chain to the store resolves once and cancels`() {
        val r = OfferResolution()
        assertEquals(Step.Allow, r.navigate(tracker, mainFrame = true))
        assertEquals(Step.Resolve(listing), r.navigate(market, mainFrame = true))
        assertEquals(Phase.DONE, r.phase)
        // Nothing after the resolution is followed or reported again.
        assertEquals(Step.Cancel, r.navigate(tracker, mainFrame = true))
        assertEquals(Step.Cancel, r.navigate(store, mainFrame = true))
        assertNull(r.didFail())
        assertNull(r.timedOut())
        assertNull(r.didFinish())
    }

    @Test
    fun `a store link in a frame still resolves`() {
        val r = OfferResolution()
        assertEquals(Step.Resolve(listing), r.navigate(store, mainFrame = false))
    }

    @Test
    fun `an app link resolves in the main frame only`() {
        val r = OfferResolution()
        assertEquals(Step.Cancel, r.navigate(deepLink, mainFrame = false))
        assertEquals(Phase.RESOLVING, r.phase)
        assertEquals(Step.Resolve(OfferOutcome.App(deepLink)), r.navigate(deepLink, mainFrame = true))
    }

    @Test
    fun `a finished page is the landing only if nothing follows it`() {
        val r = OfferResolution()
        r.didCommit()
        val first = r.didFinish()!!
        // A script redirect inside the wait makes the first ticket stale.
        assertEquals(Step.Allow, r.navigate(tracker, mainFrame = true))
        assertNull(r.settled(first))
        assertEquals(Phase.RESOLVING, r.phase)
        val second = r.didFinish()!!
        assertEquals(OfferOutcome.Landing, r.settled(second))
        assertEquals(Phase.REVEALED, r.phase)
        assertNull(r.settled(second))
        assertNull(r.timedOut())
    }

    @Test
    fun `a frame loading does not postpone the landing`() {
        val r = OfferResolution()
        val ticket = r.didFinish()!!
        assertEquals(Step.Allow, r.navigate("https://frame.example/x", mainFrame = false))
        assertEquals(OfferOutcome.Landing, r.settled(ticket))
    }

    @Test
    fun `timeout reveals and later store taps are intercepted each time`() {
        val r = OfferResolution()
        assertEquals(OfferOutcome.Timeout, r.timedOut())
        assertEquals(Phase.REVEALED, r.phase)
        assertNull(r.timedOut())
        assertEquals(Step.Allow, r.navigate(tracker, mainFrame = true))
        assertEquals(Step.Intercept(listing), r.navigate(store, mainFrame = true))
        assertEquals(Step.Intercept(listing), r.navigate(market, mainFrame = true))
        assertEquals(Step.Intercept(OfferOutcome.App(deepLink)), r.navigate(deepLink, mainFrame = true))
        assertEquals(Phase.REVEALED, r.phase)
        // The page is the user's now: a failed load there is not the click failing.
        assertNull(r.didFail())
    }

    @Test
    fun `an error before or after the first page fails`() {
        val fresh = OfferResolution()
        assertEquals(OfferOutcome.Failed, fresh.didFail())
        assertEquals(Phase.DONE, fresh.phase)

        // Mid-chain, after a page: still failed, so the web view's error page,
        // which prints a partner's address, is never revealed.
        val mid = OfferResolution()
        mid.didCommit()
        mid.navigate(tracker, mainFrame = true)
        assertEquals(OfferOutcome.Failed, mid.didFail())
        assertNull(mid.timedOut())
    }

    @Test
    fun `an unopened app falls back to what loaded, once`() {
        val none = OfferResolution()
        none.navigate(deepLink, mainFrame = true)
        assertEquals(OfferOutcome.Failed, none.appUnopened())
        assertNull(none.appUnopened())

        val loaded = OfferResolution()
        loaded.didCommit()
        loaded.navigate(deepLink, mainFrame = true)
        assertEquals(OfferOutcome.Landing, loaded.appUnopened())
        assertEquals(Phase.REVEALED, loaded.phase)
        assertNull(loaded.appUnopened())

        // Not after a store resolution, a cancel or an intercepted tap.
        val stored = OfferResolution()
        stored.navigate(store, mainFrame = true)
        assertNull(stored.appUnopened())
        val cancelled = OfferResolution()
        cancelled.navigate(deepLink, mainFrame = true)
        cancelled.cancel()
        assertNull(cancelled.appUnopened())
        val revealed = OfferResolution()
        revealed.timedOut()
        revealed.navigate(deepLink, mainFrame = true)
        assertNull(revealed.appUnopened())
    }

    @Test
    fun `cancel stops everything`() {
        val r = OfferResolution()
        r.navigate(click, mainFrame = true)
        r.cancel()
        assertEquals(Step.Cancel, r.navigate(tracker, mainFrame = true))
        assertEquals(Step.Cancel, r.navigate(store, mainFrame = true))
        assertNull(r.timedOut())
        assertNull(r.didFail())
    }

    @Test
    fun `quiet and local navigations never resolve`() {
        val r = OfferResolution()
        assertEquals(Step.Allow, r.navigate("about:blank", mainFrame = true))
        assertEquals(Step.Cancel, r.navigate("file:///sdcard/x", mainFrame = true))
        assertEquals(Step.Cancel, r.navigate("content://x/y", mainFrame = true))
        assertEquals(Phase.RESOLVING, r.phase)
    }
}
