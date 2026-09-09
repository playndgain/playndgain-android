---
title: Publisher agreement
description: The commercial terms: what you are paid, the calendar-month hold, the $20 minimum, reversals, and what ends an account.
note: Drafted from the product specification (docs/00 §2 and §6, docs/06-ledger-payouts.md) to describe the payout mechanics the platform actually implements. It has not been reviewed by counsel. Before launch a lawyer must review it and add the operating entity, the governing law, the venue, and the tax-status representations required in the jurisdictions publishers are accepted from.
---

Last updated: 2026-09-01

This is the commercial agreement between PLAYNDGAIN and a publisher. It applies from the moment an account is approved and it sits on top of the [terms of service](/legal/terms).

Every figure in it is the figure the software uses. Where this page and your dashboard disagree, that is a bug and we want to hear about it.

## Approval

Signup is open; earning is not. A person reviews every account and every app before it can serve an offer, and a rejection does not come with an obligation to explain it. Approval is per app, not per account — a new app on an approved account is reviewed again.

You must be able to enter into a contract in your jurisdiction, and the traffic you send must be yours to send.

## What you are paid

Each offer carries a payout, and each conversion records the payout it earned you. Payouts are set per offer and can differ by country, by device and over time. The rate that applies to your account is agreed with you and shown on your account.

The `payout_usd` value on the callback we send you is your figure. Nothing is applied to it afterwards: no platform fee at payout time, no adjustment, no rounding in our favour. What is on the callback is what is written to the ledger and what leaves in the payout.

Money is held to six decimal places throughout. Payouts of a third of a cent are normal in this business and they accumulate.

A change to what you are paid applies to conversions from the moment it is made and never retroactively. It is a material change and is announced accordingly.

Your conversions carry their own payouts and your statements total them. Those records, not any figure quoted anywhere else, are what you are owed.

## The hold

Approved earnings are pending until the **start of the next calendar month**, then become available to withdraw. A conversion on 15 January clears at 00:00 on 1 February; a conversion on the 1st waits until the following month. Each conversion shows its own clearing date.

A reversal that arrives while the earning is still pending debits immediately. The available balance can go negative; future earnings pay it down.

Your dashboard shows three different figures:

| Figure | Meaning |
|---|---|
| Pending | Approved, still inside the hold |
| Available | Cleared, withdrawable now |
| Balance | Both, minus anything already withdrawn |

## Withdrawals

The minimum withdrawal is **$20** of available balance. Requests below it are not accepted and the balance simply carries.

A request goes from your available balance into review, is approved by a person, and is paid to the payment method on your account. You may cancel a request while it is still under review and the money returns to available. Once it is marked paid it is final.

Payouts are in **USD only**. Where the payment method charges a fee it is shown on the request before you confirm it. Taxes are yours: we do not withhold and we do not issue tax documents.

## Reversals and chargebacks

An advertiser can reject a conversion after we have paid you for it — a refunded purchase, a fraudulent install, a duplicate. When that happens:

- A **negative ledger entry** is written against the original conversion. Nothing is edited; the credit and its reversal are two permanent rows and both stay visible.
- A second callback fires to your endpoint with `status=reversed`, the original `trans_id`, and a **negative** amount. Your integration must handle it; this is the failure that costs publishers the most money, and it is documented at [chargebacks & reversals](/docs/chargebacks-reversals).
- If the money was still pending, the pending figure drops. If it had already cleared, **your balance goes negative and future earnings pay it down automatically.** There is no separate invoice and no collection step.
- We do **not** automatically claw back a withdrawal that has already been paid. That is a human decision, taken in the admin panel and recorded in the audit log.

A balance that stays negative for more than 30 days flags the account for review, and somebody will get in touch rather than a process running against you silently.

## Your obligations

**Traffic.** Everything in [traffic rules](/docs/traffic-quality) is part of this agreement. In summary, and without limiting those pages: no bots, emulators or automation; no incentivising the *click* rather than the completion; no multi-accounting; no fake, shared or sequential user identifiers; no misrepresenting what an offer pays or what a subscription costs; no auto-redirects, pop-unders or forced interstitials; no hidden or disguised placement of the wall; no proxy or datacentre traffic presented as organic; no adult, hate, weapons, illegal or infringing placements; no traffic bought from click farms or resold from another offerwall.

**Identifiers.** The `userid` you pass must be stable for the life of that user and must identify exactly one person. If it changes, the reward goes to the wrong account; if it is shared, the conversions are void.

**Your integration.** Verify the signature on every callback, deduplicate on `trans_id`, handle negative amounts, and return a 2xx. We retry, so you will see the same `trans_id` more than once.

**Your users.** You are the one with the relationship. You tell them what the offerwall is, you honour our [privacy policy](/legal/privacy) inside your own, and you pass on access and deletion requests to the endpoints built for them.

**Your credentials.** The app secret key signs the callbacks that instruct you to pay. Keep it server-side. Regenerate it if it leaks.

## What we do about bad traffic

| Finding | Consequence |
|---|---|
| Isolated bad conversions | Rejected or reversed, balance adjusted |
| A pattern in one app | App paused pending review |
| Deliberate fraud | Account terminated, unpaid balance forfeited, networks informed |

Fraud decisions are rules with recorded inputs, not opinions. Every block writes an event with the rule that fired and the values it saw, so "why was my user not credited" is always answerable — ask, and you get the actual reason.

Forfeiting an unpaid balance is reserved for deliberate fraud, and deliberate means evidence, not a bad week of traffic. Publishers who report a hole in their own product — a leaked test account, a bot ring, a partner they did not vet — get help fixing it and keep their account.

## Termination

Either side may end this agreement at any time. You may close your account from the dashboard; we may terminate on notice.

On termination for any reason other than deliberate fraud, cleared earnings remain payable and can be withdrawn once they reach the minimum. Pending earnings clear on their normal schedule and reversals that land in the meantime still apply. On termination for deliberate fraud, unpaid balances are forfeited.

Suspension is not termination. An app paused pending review keeps its balance and its history.

## Independent parties

You are not our employee, agent or partner, and neither of us can bind the other. You choose what traffic to send and we choose what to accept.

## Confidentiality

Your rates, your volumes and your earnings are yours and are not disclosed to other publishers or to the networks except as needed to run a conversion or investigate fraud. Network credentials, our fraud thresholds and anything else we tell you that is not on the public site stay between us.

## No guarantee of earnings

Offer availability, payouts, targeting and acceptance are decided by the networks and advertisers behind them. Inventory in a country can disappear overnight. We guarantee that you are paid the payout each conversion recorded, and nothing beyond that. No figure anywhere on this site or in a conversation is a forecast of your earnings.

## Liability

The limitation of liability in the [terms of service](/legal/terms) applies to this agreement. Our total liability for any claim is capped at the amount the ledger shows we owe you when the claim arises.

## Changes

Material changes are announced in the publisher dashboard before they take effect, and the date at the top of this page is the date of the last change. A change to what you are paid, to your hold period or to the minimum withdrawal is always a material change. Continuing to send traffic after the effective date accepts the new version.

## Contact

[Get in touch](/contact) — payouts, approvals, traffic questions, or anything in this agreement.
