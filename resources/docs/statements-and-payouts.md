---
title: Statements and payouts
description: The hold, the month-end run, the minimum, and why a payout gets skipped.
---

You do not request a payout. On the 1st of each month we write your statement for the month that just ended and open the payout for your whole withdrawable balance, automatically, to your default payment method.

Your job is to have a default payment method and a complete billing address before the run. That is the whole of it.

## The hold

Approved earnings credit your balance immediately and stay **pending** until the start of the next calendar month, then become withdrawable.

A conversion on 15 January clears at 00:00 on 1 February. A conversion on 1 January waits until 1 February as well. The hold is to the month boundary, not a rolling thirty days.

The hold exists because [reversals](/docs/chargebacks-reversals) exist. An advertiser rejecting a conversion after you have been paid for it is a debt rather than an adjustment, and the hold is what keeps most reversals inside the window where they are simply a smaller balance.

Your dashboard shows three figures and they are three different things:

| Figure | Meaning |
|---|---|
| Pending | Approved and yours, inside the hold |
| Available | Cleared, payable at the next run |
| Balance | Both, minus anything already paid out |

Every conversion row shows the exact date it clears.

## The month-end run

Shortly after midnight on the 1st, in order:

1. A **statement** is written for every publisher with ledger activity in the month that ended
2. For each approved publisher, one **payout** is opened for the full withdrawable balance, on the default payment method, linked to the statements it settles
3. Publishers who fail a rule get a mail saying which rule, and the reason is written onto the statement so the Payments page can show it

The run is idempotent. If it is re-run for a cycle that already has a request, nothing is opened twice.

## Why a payout gets skipped

Each of these stops the run for one publisher, and each appears verbatim on your statement:

| Reason | What to do |
|---|---|
| Your account is still under review | [Going live](/docs/going-live). The balance carries to next month |
| Your account is suspended | Contact support |
| An open payout already exists | One at a time. It settles, then the next opens |
| Your balance is negative | Future earnings pay it down first |
| Below the minimum | The balance carries. See below |
| No default payment method | **Apps aside, this is the most common one.** Set one on the Payments page |
| The saved destination cannot be read | Enter it again on the Payments page |

Nothing here loses money. A skipped month carries the balance to the next run.

## The minimum

**$20.** Below it, nothing is opened and the balance carries to the following month.

The floor is $20 and an account-level minimum can be set higher but never lower.

## Payment methods

Three kinds, on the **Payments** page: **crypto**, **PayPal** and **bank transfer**. One of them is your default and that is where the monthly run sends the money.

Two things have to be complete before a method can be your default:

- The destination itself, which for crypto means the network as well as the address
- Your **billing address**, because it prints on the credit note issued with the payout

Add these when you integrate, not when you first have a balance. A payout blocked by a missing payment method is discovered on the 1st, which is after the run rather than before it.

Withdrawal fees, where the method charges one, are shown before the payout is made. Taxes are yours. We do not withhold and we do not issue tax documents.

## Statements

One per month with ledger activity, on the **Payments** page. A statement is the month's earnings, reversals and adjustments, and it names the payout that settled it.

They are not regenerated. A statement for a closed month says what that month was, and a reversal landing in March appears in March's statement even when it undoes a conversion from January. See [The ledger](/docs/the-ledger) for why nothing is restated.

## Negative balances

A reversal can arrive after the money has been paid out. When it does:

- Your ledger goes negative, and so does your balance
- The negative balance and the conversion that caused it are both visible in your transactions
- **Future earnings pay it down automatically.** No separate collection step, no invoice
- A negative balance older than 30 days raises an alert on our side and somebody gets in touch
- We do **not** automatically claw back a payout already paid. That is a human decision, taken in the admin panel and written to the audit log

While the balance is negative no payout is opened, which is one of the skip reasons above.

## Currency

Everything on our side is USD. Your players are credited in your currency at your app's rate, and that rate has no effect on what you are paid. [Your payout and your rate](/docs/your-payout) separates the two.
