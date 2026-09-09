---
title: Introduction
description: What PLAYNDGAIN is, where the money comes from, and the one thing you have to build.
---

Your players are already in your game. PLAYNDGAIN gives them a way to earn your currency without leaving it: a wall of offers, embedded in your product, wearing your brand. A player installs a game, finishes a survey, opens an account. That action pays, and it pays you.

You build one thing: an HTTP endpoint that listens for us saying "this player earned this much". Everything else is an iframe and a form.

## Where the money comes from

Nobody is paying for attention here. An advertiser pays for a completed action, which is why an offer has instructions and why a reward can be taken back if the action turns out to be fake.

```txt
Player completes an offer
        │
        ▼
Verified, then credited
        │
        ├──►  your payout, on your ledger
        │
        └──►  your currency, in your player's balance
```

Money moves in one direction and stops at your player. Every hop is written down. When a figure on your dashboard looks wrong, the entries that produced it are all still there, which is the only way an argument about money has ever been settled.

## The three things that break

Most of this documentation is reference material you will read once. Three pages are not, and they are the reason integrations fail in production rather than in testing:

1. **[Verifying the signature](/docs/signature-verification).** Until you check it, anyone who guesses your callback URL can credit any player any amount.
2. **[Retries and duplicates](/docs/retries-and-failure).** We retry a failed delivery up to six times. If your endpoint credits every time it is called, one offer pays six times.
3. **[Reversals](/docs/chargebacks-reversals).** An advertiser can reject a conversion after we have already paid you for it. The amount arrives negative. Code that treats it as unsigned hands your players coins for revenue you never received, and nothing anywhere throws an error.

Read those three before you go live. The rest can wait until you need it.

## What you never have to build

- **Offer inventory.** Offers arrive from our demand partners, filtered by country, device and OS before a player sees one.
- **A wall UI.** The wall is a page we host and you embed. It takes your colours, your radius and your currency name.
- **Fraud checks.** Every click is scored on the way out and every conversion on the way in.
- **Payout arithmetic.** We send you both figures: what you earned in dollars, and what to credit your player in your own currency at your own rate.

## What you do build

An endpoint that verifies a signature, refuses to act twice on the same transaction, handles a negative amount, and answers `200`. Complete working versions in five languages are on [Verifying the signature](/docs/signature-verification), and the [quick start](/docs/quick-start) gets you there in six steps.

## Vocabulary

We use a small number of words precisely throughout: app, end user, click, conversion, postback, currency rate. [Core concepts](/docs/core-concepts) defines them, and every other page assumes them.

One of those words is worth flagging here. We say **end user** where you would say player, because the platform term has to cover a survey site and a rewards app as well as a game. It is the same person.
