---
title: The ledger
description: Append-only by design, which is what makes a disagreement about money answerable.
---

Every movement of money on this platform is a ledger entry. Entries are appended and never edited, never deleted and never restated.

That has a cost, which is that a mistake stays visible forever. It buys the only thing that matters more: when a figure looks wrong, the entries that produced it are all still there.

## What writes an entry

| Event | Entry |
|---|---|
| A conversion is approved | A credit for the payout the conversion earned you |
| A conversion is reversed | A separate chargeback entry, negative, for the same amount |
| A payout is paid | A debit for the amount paid |
| An adjustment | Its own entry, with the actor recorded |

A conversion that is credited and later reversed is **two entries**, both permanent. Neither is a correction of the other. The first says the conversion was approved on the day it was approved, and the second says it was rejected on the day it was rejected, and both are true.

Only approved conversions write an entry. A `pending` or `rejected` conversion writes nothing. [Conversion statuses](/docs/conversion-statuses) has the full table.

## What this means for your reconciliation

**Sum with the sign.** Adding conversion rows without their sign overstates earnings by exactly the value of the reversals. This is the single most common cause of your number and ours disagreeing.

**Test conversions are not in it.** They carry a payout and produce a postback but never a ledger entry, so they are absent from earnings by construction. If you count them on your side your total is higher than ours.

**A closed month stays closed.** A reversal in March that undoes a January conversion appears in March. January's statement is not rewritten, because a statement that changes after it was sent is not a statement.

**Per app, then summed.** Reports break down per app, and two apps never roll into one line automatically. Your publisher balance is the sum across all of them.

## Balances are checked against it

Your balance is a cached figure and the ledger is the truth. They are reconciled nightly.

A cached balance that disagrees with the sum of its entries is treated as a bug we are told about, not as something quietly corrected in the background. A balance that silently repairs itself is a balance nobody can audit.

## When you think a figure is wrong

You can settle most of it yourself before you write to us:

1. **Filter Conversions by status.** Reversed conversions are still listed, with negative payouts
2. **Compare per app**, not in aggregate. A mismatch confined to one app is nearly always a `userid` or a dedupe issue in that integration
3. **Check for test conversions** in your own count
4. **Sum with the sign**

If it still does not reconcile, send the `trans_id` of one row that disagrees. Every entry, its timestamp and what produced it is on our side already, and one transaction is enough to find the pattern.

## What we cannot delete

A player can ask you to delete their data, and the privacy endpoints in [Countries and devices](/docs/countries-and-devices) anonymise their clicks and conversions: the IP is nulled and the identifier is replaced with a hash.

The money rows stay. Financial records are not deletable, that retention basis is stated in the privacy policy, and an anonymised conversion still has a ledger entry with no person attached to it.
