---
title: Status
description: The surfaces that make up PLAYNDGAIN, what each one failing looks like, and where to check.
note: Deliberately not a monitoring system. External HTTP checks are an external tool (docs/11-infrastructure.md §7 — UptimeRobot or Better Stack); the public status board URL replaces the placeholder paragraph below once it exists. An in-app status page cannot report that the app is down, which is exactly why this one does not claim to.
---

PLAYNDGAIN runs on one application and a small number of moving parts. This page says what they are and what a failure in each one looks like from the outside, so you can tell a problem on our side from a problem in your integration.

## The surfaces

| Surface | What it is | What its failure looks like to you |
|---|---|---|
| **The wall** `/wall` | The hosted iframe your players see | The iframe is blank, or shows "Unavailable" |
| **Click redirect** `/click` | Fraud checks, then the redirect to the network | Tapping an offer goes nowhere |
| **Incoming postbacks** | Conversions arriving from partner networks | Your players complete offers and are never credited |
| **Outgoing callbacks** | The signed S2S call to your server | Conversions appear in your dashboard but your endpoint never hears about them |
| **Publisher dashboard** `/dashboard` | Your panel | You cannot sign in |
| **Documentation** `/docs` | This site | You are reading it |

## Before you assume it is us

Most reports resolve to something visible in your own dashboard, which is why every attempt is recorded:

- **No callback received?** Postback reports show every delivery attempt with its response body. A failed delivery is retried at 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours before it is marked failed — and the ledger entry exists either way, so the money is not lost while the retries run.
- **"No offers available"?** That is a targeting or blacklist result, not an outage. Test mode with a country override will show you which.
- **Signature failures?** The string being signed, not the platform. [Signature verification](/docs/signature-verification) has the exact concatenation.

[Troubleshooting](/docs/troubleshooting) maps the common symptoms to their causes.

## Uptime monitoring

External uptime checks run outside this application, because an in-app status page cannot report that the app is down — a page that is always green when you can load it is worse than no page. The public status board will be linked here.

Internally, availability, postback failure rate, queue depth and ledger integrity are monitored continuously, and ledger drift pages a human immediately rather than waiting for a daily digest.

## Incidents

An incident that affects serving or callbacks is announced in the publisher dashboard, where it is in front of the people it affects rather than on a page nobody has bookmarked. Anything that changed a balance is visible in the ledger afterwards, entry by entry.

Something broken right now: [contact us](/contact?topic=technical).
