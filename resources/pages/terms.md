---
title: Terms of service
description: The terms for using the PLAYNDGAIN site, documentation and platform.
note: Drafted from the product specification to describe what the system actually does. It has not been reviewed by counsel. Before launch a lawyer must review it and add the operating entity, its registered address, the governing law and the venue for disputes — none of which are invented here.
---

Last updated: 2026-09-01

These terms cover this website, the developer documentation, and the PLAYNDGAIN platform. Using any of them means accepting them.

If you hold a publisher account, the [publisher agreement](/legal/publisher-agreement) also applies and governs the commercial relationship: what you are paid, payouts, traffic quality. Where the two disagree about money or traffic, the publisher agreement wins.

## What the service is

PLAYNDGAIN is an **offerwall aggregator**. We do not create advertising demand and we do not sell campaigns. We pull offer catalogues from partner advertising networks, normalise them into one shape, serve them on a hosted offerwall that publishers embed by iframe, receive conversion postbacks from those networks, and pay the publisher the payout each conversion earned along with a signed callback so they can credit their own user.

Two consequences follow, and both are terms rather than caveats:

- **The offers are not ours.** Their availability, their targeting, their payout, and whether a completion is ultimately accepted are decided by the network and the advertiser behind it. We pass on what we are told, including a rejection.
- **We are paid before you are.** A conversion we are not paid for is a conversion you are not paid for. This is why the hold and the reversal mechanism in the publisher agreement exist.

## Accounts

Signup is open. **Earning is not.** Every account and every app is reviewed by a person before it can serve a single offer, and approval can be refused without a reason being owed. There is no automatic approval path and no way to earn while unapproved.

You are responsible for everything done under your account, including by anyone you give access to. Your app secret key signs the callbacks that tell you to pay your users; treat it as a credential, not a configuration value. It is shown once, in full, when the app is created, and is masked afterwards with a regenerate action. If you lose control of it, regenerate it.

Two-factor authentication is available on publisher accounts and is required on administrator accounts.

## Acceptable use

Do not attempt to defeat the fraud engine, the signature scheme, or the rate limits. Do not scrape the offer catalogue, resell it, or expose it through your own API — the offerwall is delivered as a hosted iframe and that is the only delivery method offered.

Traffic obligations are separate and more detailed. They are set out in [traffic rules](/docs/traffic-quality), which forms part of these terms.

## The documentation and the site

The documentation is published so you can integrate without contacting anyone. You may copy the code snippets in it into your own systems freely. The rest of the site — the brand, the wordmark, the copy, the design — is ours.

Figures shown on marketing pages are drawn from live platform data at the time the page was cached. They describe the platform, not a promise about your results.

## Service availability

We aim to keep the offerwall and the callback pipeline running continuously and we monitor both. We do not offer an availability guarantee or service credits, and there is no uptime commitment in these terms. Planned maintenance is announced in the dashboard when it will be visible to your users.

The callback pipeline retries a failed delivery at 1 minute, 5 minutes, 30 minutes, 2 hours and 6 hours before marking it failed, and every attempt with its response is visible to you. **A callback we could not deliver is still money owed to you** — the ledger entry exists whether or not your endpoint answered.

## Suspension and termination

You may close your account at any time. Cleared earnings above the minimum can be withdrawn first; the mechanics are in the publisher agreement.

We may suspend an app or an account immediately where there is evidence of prohibited traffic, fraud, a security compromise, or a legal obligation to do so. Suspension pauses serving; it does not by itself forfeit a balance. Termination for deliberate fraud does, and that consequence is stated in [traffic rules](/docs/traffic-quality) rather than hidden here.

Every suspension, ban and money-affecting decision is written to an audit log with who did it and what changed. Nothing blocks silently: if a click or a conversion was rejected, the reason exists and can be given to you.

## No warranty

The platform is provided as it is. We do not warrant that it will be uninterrupted, that any particular offer will be available in any particular country, that a given conversion will be accepted by the advertiser, or that the platform will meet a purpose you have in mind for it.

## Limitation of liability

To the fullest extent the law allows, we are not liable for lost profits, lost revenue, lost users, lost data, or any indirect or consequential loss arising from the platform — including revenue you expected from conversions that were rejected or reversed.

Our total liability to a publisher for any claim is limited to the amount we owe that publisher under the ledger at the time the claim arises. That figure is not arbitrary: the ledger is append-only, it is reconciled nightly, and it is the same number both sides can read.

Nothing here limits liability that cannot be limited by law.

## Disputes about money

Every credit, every reversal and every withdrawal is a permanent entry in an append-only ledger. Nothing is edited and nothing is deleted, so a disagreement about a balance is settled by reading the entries that produced it. Raise it through [contact](/contact) and we will work from the same rows you can see in your dashboard.

## Changes

These terms change when the platform changes. Material changes are announced in the publisher dashboard before they take effect, and the date at the top of this page is the date of the last change. Continuing to use the platform after that date means accepting the new version.

## Contact

Questions about these terms: [contact us](/contact).
