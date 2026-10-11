package com.playndgain.sdk

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertNull
import org.junit.Test

class PlayndgainTest {
    @Test
    fun `an invalid user id clears the previous user and provider`() {
        val errors = mutableListOf<PlayndgainError>()
        Playndgain.setListener(object : PlayndgainListener {
            override fun onError(error: PlayndgainError) { errors += error }
        })
        try {
            Playndgain.setUser("USER_1") { _, _, cb -> cb.onSignature("") }
            assertEquals("USER_1", Playndgain.userId)
            val before = Playndgain.session

            Playndgain.setUser("not valid") { _, _, cb -> cb.onSignature("") }

            assertNull(Playndgain.userId)
            assertNull(Playndgain.signatureProvider)
            // The cached signature is keyed on the session, so it is dropped too.
            assertNotEquals(before, Playndgain.session)
            assertEquals(listOf(PlayndgainError.INVALID_USER_ID), errors)
        } finally {
            Playndgain.setListener(null)
            Playndgain.reset()
        }
    }
}
