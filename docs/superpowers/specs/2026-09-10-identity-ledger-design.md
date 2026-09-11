# Identity ledger — Design

**Date:** 2026-09-10
**Status:** Approved
**Extends:** `docs/05-fraud-engine.md`, `docs/11-infrastructure.md` §5 and §7

## 1. Goal

Admin can see when two accounts share an IP, a device-signal hash, or a panel session, with an append-only session log. Panel matches also email ops. Wall matches never block, flag, or write a `fraud_event` — they only appear on the Linked accounts table.

## 2. Signals

Server-side IP + UA hash, plus the wall `ds` token (hand-written collector, hashed after validation). No fingerprinting SDK. No persistent cookie. Raw UA and raw `ds` are never stored.

- `ds` — strongest. Same hash, different account.
- `ip` — noisy (CGNAT, school, office). Shown as an IP match.
- `session` — panel only. Same Laravel session id on two users.

Wall matches are publisher-scoped. Panel matches are publisher-to-publisher only. Impersonation and admin accounts are excluded from matching.

## 3. Tables

`identity_sessions` — append-only log (`kind` panel|wall). Retention 180 days via `logs:prune`.
`account_matches` — one row per pair + signal. Inbox, not a firehose. Not pruned with money tables.

`offer_clicks` and `fraud_events` are untouched. No `shared_device` fraud rule.

## 4. Write path

- Panel: `Login` event → session row + `users.last_login_*` → match → `AdminAlert` on a new pair (`identity.panel_match.{left}.{right}` so cooldown is per pair).
- Wall render (after consent): IP + UA. Hourly dedupe per `(app, end_user, ip, ds-or-empty)`.
- Wall click (after the same consent gate): IP + UA + `ds` when present.
- Consent missing/declined, or `testMode`: write nothing.
- Fail-open: a ledger exception is logged; the wall and login still succeed.

## 5. Admin

Risk → Linked accounts. Amber signal pill (signup-queue match cell, not rose). Nav badge: matches with `last_seen_at` in the last 24 hours. Publisher view: Sessions + Linked accounts relations. Signup queue adds a Linked hit when the applicant already has a panel match.

Publisher panel and wall UI show none of this. `ds` is an invisible query param on offer links when JS and consent allow it.
