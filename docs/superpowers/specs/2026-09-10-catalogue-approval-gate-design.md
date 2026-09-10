# Catalogue approval gate — Design

**Date:** 2026-09-10
**Status:** Approved
**Extends:** `docs/08-publisher-panel.md` §1 (account states), §5 (Offers), §12 (Blacklists)

## 1. Goal

A publisher who is not Approved, or an app that is not Active, must not see the live offer catalogue in the publisher panel. The hosted wall already refuses those cases (`WallGate::isServable()`). This spec closes the two panel leaks: `/dashboard/offers` and the blacklist offer picker, both of which read `EligibleOffers`.

Signup stays open. Configuration stays open. Catalogue browsing does not.

## 2. Rule

Same predicates as the wall:

- Account approved = `Publisher::isApproved()` (`status === approved`)
- App approved = `App::isActive()` (`status === active`)

`EligibleOffers::query(?int $appId)` is the only gate. Offers and the blacklist offer picker inherit it. `WallGate` is unchanged. Admin `/admin` is unchanged.

At the top of `query()`:

1. No current publisher, or publisher not Approved → empty query
2. `$appId` set, and that app is missing, not theirs, or not Active → empty
3. `$appId` null (“all apps”) and the publisher has no Active app → empty

Otherwise the existing inventory filters run unchanged (active offers, active networks, blacklists, blocked countries).

A helper such as `EligibleOffers::catalogueOpen(?int $appId): bool` may exist so the Offers empty-state copy can tell “waiting for approval” from “nothing eligible”. It must use the same predicates as `query()`, not a second definition.

## 3. Offers page

The page stays in the nav. The table is empty until the gate opens.

- `mount()` defaults `appId` to the first Active app only. It must not fall back to a pending, paused, or rejected app.
- Gate closed: empty heading **Waiting for approval**. Body **Offers appear after your account and this app are approved.** When no app is selected (`appId` null), body **Offers appear after your account and an app are approved.**
- Gate open and inventory empty: keep **Nothing eligible** / current description.
- The app selector may still list pending apps. Selecting one keeps the waiting empty state.

Block and preview-URL row actions are unreachable when the table has no rows. No extra lock on those actions.

## 4. Blacklists

The resource stays available. Country and device blocks still save.

The offer picker and the impact line go empty through `EligibleOffers`. Impact already says **No eligible offers to measure against yet** when the query count is 0; keep that. Saved rows still resolve names via `EligibleOffers::label()`.

## 5. App create / edit preview

`resources/views/filament/publisher/wall-preview.blade.php` keeps the themed chrome (topbar, empty catalogue, footer). Sample cards and the “Sample inventory…” note render only when the saved app is Active.

Create has no saved app, so chrome only. Registration has no preview today; if one is added later it may keep sample cards.

The live-wall link already waits for Active + Approved. No change.

## 6. Tests

Extend `tests/Feature/Panel/PublisherOffersTest.php` (and one preview assertion on the app form):

- Pending publisher → `EligibleOffers` empty, even with an Active app
- Approved publisher + pending / paused / rejected app → empty
- Approved + no Active apps + `query(null)` → empty
- Approved + Active app → current inventory rules still hold
- Offers page does not default `appId` to a pending app
- Edit preview omits sample cards until the app is Active

Existing wall tests stay as they are.

## 7. Out of scope

- Changing `WallGate` or the hosted `/wall` unavailable page
- Hiding Offers from the sidebar
- Hiding the preview pane entirely
- Gating country / device blacklists
- Admin offer catalogue
