---
title: Test mode
description: The toggle, the test offer, and what changes while it is on.
---

Test mode turns integration support from a conversation into something you can do yourself at 2am.

**Apps → Settings → Test mode.**

## What changes while it is on

| Behaviour | Normal | Test mode |
|---|---|---|
| `country` URL parameter | Ignored | **Honoured** — preview any geo |
| Test Offer | Absent | Pinned to the top of the wall, payout `$0.01` |
| Real offers | Shown | Still shown |
| Conversions from the Test Offer | — | Flagged as test: excluded from earnings and from reports |

Everything else is unchanged. Real offers still work, real conversions still pay.

## The test offer

Clicking it fires a **real end-to-end conversion** through the full pipeline: click row, fraud rules, conversion row, ledger entry, outgoing postback to your endpoint, signature and all.

It is not a mock. The only difference from a real conversion is the `is_test` flag, which keeps it out of your earnings and out of your reports.

That means the test offer exercises exactly the things that break:

- Whether your `userid` arrived intact and came back the same.
- Whether your signature check passes against a real signature.
- Whether your endpoint returned a 2xx.
- What your endpoint's response body actually said.

## Previewing another country

With test mode on, add `country`:

```txt
{APP_URL}/wall?appid=YOUR_APP_ID&userid=qa-1&country=DE
```

ISO-3166-1 alpha-2. This is how you check that a geo you care about has inventory before you launch there.

In production the parameter is ignored and the country is resolved from the user's IP. An offer's eligibility and payout are geo-bound by the advertiser's contract, so a publisher-supplied country in production would be an open invitation to farm US payouts with cheaper traffic.

## A working test loop

1. Turn test mode on.
2. Load your wall with a throwaway `userid` — `qa-1`.
3. Click the Test Offer.
4. Watch **Postback Reports** for the attempt and read the response body your server returned.
5. Check your own database: one row, correct user, correct amount.
6. Fire the same signed URL at your endpoint a second time by hand and confirm nothing changes. That is your [dedupe](/docs/idempotency).
7. Negate the payout and the amount, recompute the signature, fire again, and confirm the balance goes **down**. That is your [reversal branch](/docs/chargebacks-reversals).

Steps 6 and 7 are the ones publishers skip, and they are the two that fail in production.

## Turn it off before you launch

The Test Offer is visible to every user of an app in test mode, and it pays `$0.01`. Leaving it on ships a one-cent offer to your whole audience.
