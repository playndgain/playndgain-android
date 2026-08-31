# 01 — Domain Model

Every table, column, index, and relationship. This is the contract the rest of the docs build on.

**Conventions used throughout:**

- Money: `decimal(14,6)` unsigned unless it can go negative (ledger amounts can).
- Timestamps: `timestamp` nullable, UTC always. Display timezone is a per-user preference.
- Soft deletes: only on `apps` and `publishers`. Nothing in the money path is ever soft-deleted.
- Enums: PHP backed enums (`app/Enums/`), stored as `string` columns, not MySQL `ENUM` (migrations stay cheap).

---

## 1. Identity

### `users`

Standard Laravel table plus:

| Column | Type | Notes |
|---|---|---|
| `role` | string | `admin` \| `publisher` |
| `two_factor_secret` | text nullable | Fortify |
| `two_factor_recovery_codes` | text nullable | Fortify |
| `two_factor_confirmed_at` | timestamp nullable | **Required non-null for `role=admin`** — enforced in the admin panel middleware |
| `last_login_at` | timestamp nullable | |
| `last_login_ip` | string(45) nullable | IPv6-safe length |

Index: `role`.

### `publishers`

One row per publisher account. Separate from `users` so publisher business state is not tangled with auth.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `user_id` | foreignId → users | unique |
| `pub_id` | unsignedInteger | Public-facing ID, starts at 10000. Unique. Shown in UI as `#10042`. |
| `company_name` | string nullable | |
| `country` | char(2) nullable | |
| `status` | string | `pending` \| `approved` \| `suspended` \| `banned` |
| `rev_share` | decimal(5,2) | Percent to publisher. Default from `settings.default_rev_share` (**70.00**). |
| `hold_days` | unsignedSmallInteger nullable | Unused leftover. Clearing is the start of the next calendar month. |
| `min_payout` | decimal(10,2) | Default 20.00 |
| `balance` | decimal(14,6) | **Cached.** Derived from `ledger_entries`. Signed — can go negative. |
| `lifetime_earnings` | decimal(14,6) | Cached, positive earnings only |
| `traffic_source` | text nullable | Free text from signup, for admin review |
| `approved_at` | timestamp nullable | |
| `approved_by` | foreignId → users nullable | |
| `notes` | text nullable | Admin-only |

Indexes: `status`, `pub_id` (unique).

> `balance` is a cache, not a source of truth. See [06-ledger-payouts.md](06-ledger-payouts.md) for the derivation rule and the nightly drift check.

---

## 2. Placements

### `apps`

A publisher's placement. One offerwall configuration.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `publisher_id` | foreignId → publishers | |
| `app_id` | unsignedInteger | Public ID, starts at 10000. Unique. This is what goes in the iframe URL. |
| `name` | string | |
| `url` | string nullable | Publisher's site/app URL |
| `platform` | string | `web` \| `android` \| `ios` \| `all` |
| `status` | string | `pending` \| `active` \| `paused` \| `rejected` |
| `icon_path` | string nullable | |
| `secret_key` | text | **`encrypted` cast.** Used for wall URL HMAC and outgoing postback signatures. |
| `currency_name` | string | e.g. "Coins", "Gems", "Points" |
| `currency_rate` | decimal(14,4) | Currency units per $1 USD. e.g. `1000.0000` |
| `postback_url` | text nullable | Publisher's callback with macros |
| `postback_method` | string | `GET` \| `POST` |
| `require_signed_wall` | boolean | Default `false`. When true, `/wall` requires a valid `sig`. |
| `anonymize_ip` | boolean | Default `false`. Zeroes the last octet before storage. |
| `test_mode` | boolean | Default `false`. Honours the wall's `country` override and pins the test offer — docs/03 §7. |
| `theme` | json nullable | `{primary, background, card, text, radius, dark}` |
| `blocked_countries` | json nullable | Denormalized copy of country blacklist for fast wall filtering |
| `daily_conversion_cap` | unsignedInteger nullable | Fraud control |
| `approved_at` | timestamp nullable | |
| `deleted_at` | timestamp nullable | Soft delete |

Indexes: `app_id` (unique), `publisher_id`, `status`.

---

## 3. Supply

### `networks`

A partner ad network we pull offers from.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `driver` | string | Unique. e.g. `notik`, `ogads`, `monlix`. Maps to a driver class. |
| `name` | string | Display name |
| `logo_path` | string nullable | |
| `website` | string nullable | |
| `wall_url` | text nullable | The partner's own hosted wall, as a macro template — `{sub_id}`, `{user_id}`, `{app_id}`, `{country}`, `{device}`, `{lang}` |
| `wall_new_tab` | boolean | Set when the partner's frame-ancestors refuses us. Renders a button to a new tab instead of an iframe. Default `false` |
| `status` | string | `disabled` \| `active` \| `error` |
| `credentials` | text nullable | **`encrypted:array` cast.** `{api_key, publisher_id, secret, …}` — shape is per-driver. |
| `postback_ips` | json nullable | Source IP allowlist for incoming postbacks. Empty rejects. |
| `allow_any_postback_ip` | boolean | Admin opt-out of the allowlist for a partner that will not publish firing IPs. Default `false`. |
| `priority` | unsignedTinyInteger | Tie-breaker when the same offer exists on two networks. Higher wins. |
| `margin_override` | decimal(5,2) nullable | Optional per-network margin adjustment |
| `sync_interval_minutes` | unsignedSmallInteger | Default 60 |
| `last_synced_at` | timestamp nullable | |
| `last_sync_status` | string nullable | `ok` \| `failed` |
| `last_sync_error` | text nullable | |
| `offers_count` | unsignedInteger | Cached count of active offers |

`wall_url` and `wall_new_tab` describe the second integration mode: instead of syncing a catalogue and redirecting clicks, we frame the partner's own wall. Both are null/false for an API network, which is most of them.

Networks are **seeded as `disabled`** with empty credentials. An admin activates one by pasting credentials and hitting *Test connection*.

The seed covers the **five launch partners** — AdGate, Admantum, Monlix, Adscend Media, Notik — each with its mark in `public/img/networks/`, plus **AdVault (house)**, which has no API at all: it is the home for offers we sell ourselves, added by hand in the admin panel ([02 §6](02-network-adapters.md)), and it sorts below every partner because house inventory fills a gap rather than outranking a supplier.

Two rules decide what is in that list, and they are different rules. A network is only seeded once its driver can talk to the real API — a row an admin can enable that then syncs nothing reads as a broken network rather than a missing one. And it is only seeded once there is an account behind it: Adswedmedia and Lootably have working, fixture-tested drivers and are deliberately absent, because a row for a network we have no credentials for is an invitation to switch on something that cannot earn. Adding one later is a row, not a deploy.

### `offers`

Normalized offers. One row per offer per network.

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `network_id` | foreignId → networks | |
| `external_id` | string | The network's own offer ID |
| `name` | string | |
| `description` | text nullable | |
| `instructions` | text nullable | "Reach level 10 within 7 days" |
| `icon_url` | string nullable | |
| `preview_url` | string nullable | |
| `tracking_url` | text | Template containing the network's subid macro |
| `payout` | decimal(14,6) | **What the network pays us**, USD |
| `conversion_type` | string | `cpi` \| `cpa` \| `cpl` \| `survey` \| `signup` \| `purchase` \| `other` |
| `countries` | json | Allowed ISO-2 codes. `null` / `[]` = worldwide. |
| `devices` | json | `["android","ios","desktop"]` |
| `os_min_version` | string nullable | |
| `categories` | json nullable | |
| `is_multi_reward` | boolean | Offer pays out in multiple events |
| `goals` | json nullable | Those events, in order: `[{"name": "Reach level 50", "payout": 2.0}]`, USD like `payout`. Every driver already parsed them; this is where they land. The wall's detail view converts each to the publisher's currency at render time — a stored conversion would be wrong for every other publisher. |
| `daily_cap` | unsignedInteger nullable | From the network, when provided |
| `status` | string | `active` \| `inactive` \| `blocked` |
| `is_featured` | boolean | Admin-pinned. Sorts to the top of every wall. Default `false`. |
| `blocked_reason` | string nullable | Set when an admin blocks it globally |
| `last_seen_at` | timestamp | Updated every sync where the offer appears |
| `first_seen_at` | timestamp | |

Indexes: `(network_id, external_id)` **unique** · `status` · `payout` · `last_seen_at`.

> Offers are **never hard-deleted.** Clicks and conversions reference them for the lifetime of the platform. A vanished offer goes `inactive`.

---

## 4. Traffic

### `offer_clicks`

Every click on the wall. The highest-volume table in the system.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `click_id` | uuid | **Unique.** UUIDv4. The join key for the whole conversion path. |
| `app_id` | foreignId → apps | |
| `publisher_id` | foreignId → publishers | Denormalized, saves a join on every report |
| `offer_id` | foreignId → offers nullable | |
| `network_id` | foreignId → networks | Denormalized |
| `end_user_id` | string(191) | Publisher-supplied opaque user identifier |
| `ip` | string(45) | |
| `country` | char(2) nullable | |
| `user_agent` | text nullable | |
| `device_type` | string nullable | `android` \| `ios` \| `desktop` \| `other` |
| `os` | string nullable | |
| `browser` | string nullable | |
| `referrer` | text nullable | |
| `risk_score` | unsignedTinyInteger | 0–100, computed pre-click |
| `blocked` | boolean | True when a pre-click rule refused the redirect |
| `blocked_reason` | string nullable | |
| `is_test` | boolean | Click on the test offer while the app is in test mode. Default `false`. |
| `converted_at` | timestamp nullable | Set when a conversion lands. Enables a cheap CR query. |
| `created_at` | timestamp | |

Indexes: `click_id` (unique) · `(app_id, created_at)` · `(end_user_id, offer_id)` · `(publisher_id, created_at)` · `country` · `created_at`.

> `offer_id` is nullable. A null means the click is an **embedded partner wall open** rather than a click on one of our offers — there is no offer of ours involved. `offer_id IS NULL` is what keeps those rows out of offer-level reporting.

**Retention:** partitioned or pruned by month. Clicks older than 6 months with no conversion are aggregated into `daily_stats` and deleted. Volume is the reason — a busy month is millions of rows.

### `daily_stats`

Pre-aggregated reporting so dashboards never scan `offer_clicks`.

| Column | Type |
|---|---|
| `date` | date |
| `publisher_id` | foreignId |
| `app_id` | foreignId |
| `network_id` | foreignId nullable |
| `country` | char(2) nullable |
| `clicks` | unsignedInteger |
| `conversions` | unsignedInteger |
| `reversals` | unsignedInteger |
| `revenue` | decimal(14,6) — what networks paid us |
| `payout` | decimal(14,6) — what we owe publishers |

Unique index on `(date, publisher_id, app_id, network_id, country)`. Rebuilt hourly for today, nightly for yesterday.

**The aggregation query filters `is_test = false` on both `offer_clicks` and `conversions`.** Test-mode traffic exists to prove an integration works; if it reaches a revenue chart, every number in both panels is wrong by however much the publisher tested.

---

## 5. Conversions & Money

### `conversions`

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `trans_id` | string(64) | **Unique.** Our public transaction ID, e.g. `OFFER19624243-Gw2CpYHhjF69lDdJ`. Sent to the publisher. |
| `network_txid` | string(191) | The network's transaction ID. **Unique together with `network_id`** — this is the idempotency key. |
| `click_id` | uuid nullable | FK to `offer_clicks.click_id`. Nullable because some networks postback without a resolvable click. |
| `offer_click_id` | foreignId nullable | Resolved FK |
| `app_id` | foreignId | |
| `publisher_id` | foreignId | |
| `offer_id` | foreignId nullable | |
| `network_id` | foreignId | |
| `end_user_id` | string(191) | |
| `offer_name` | string | **Snapshotted** at conversion time — offers change names |
| `revenue` | decimal(14,6) | What the network pays us |
| `payout` | decimal(14,6) | What we owe the publisher = `revenue × rev_share / 100` |
| `rev_share_applied` | decimal(5,2) | Snapshotted — a later rev-share change must not rewrite history |
| `currency_amount` | decimal(14,4) | `payout × app.currency_rate` |
| `status` | string | `pending` \| `approved` \| `reversed` \| `rejected` |
| `is_test` | boolean | Test-mode conversion. **Writes no ledger entry, excluded from `daily_stats`, excluded from every earnings figure.** Default `false`. |
| `rejected_reason` | string nullable | Fraud rule key, when rejected |
| `ip` | string(45) nullable | |
| `country` | char(2) nullable | |
| `clears_at` | timestamp | Start of the next calendar month after `created_at` |
| `reversed_at` | timestamp nullable | |
| `created_at` | timestamp | |

Indexes: `trans_id` (unique) · `(network_id, network_txid)` **unique** · `(publisher_id, created_at)` · `(app_id, created_at)` · `status` · `clears_at` · `(end_user_id, offer_id)`.

> The `(network_id, network_txid)` unique index **is** the idempotency guarantee. Not an `exists()` check in PHP — a database constraint, because two concurrent postback workers will race and only the database can settle it.

### `ledger_entries`

Append-only. The only truth about money.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `publisher_id` | foreignId | |
| `type` | string | `earning` \| `chargeback` \| `withdrawal` \| `adjustment` \| `referral` |
| `amount` | decimal(14,6) **signed** | Positive credits, negative debits |
| `balance_after` | decimal(14,6) signed | Running balance snapshot at write time |
| `reference_type` | string nullable | Morph type: `Conversion`, `Withdrawal` |
| `reference_id` | bigInteger nullable | Morph id |
| `available_at` | timestamp nullable | For earnings, mirrors `conversions.clears_at` |
| `description` | string nullable | |
| `created_by` | foreignId → users nullable | Set for manual adjustments |
| `created_at` | timestamp | |

Indexes: `(publisher_id, created_at)` · `type` · `(reference_type, reference_id)` · `available_at`.

**No `updated_at`. No update statements. No deletes.** A mistake is corrected by an `adjustment` entry, never by editing history. This rule is enforced in the model:

```php
protected static function booted(): void
{
    static::updating(fn () => throw new LogicException('Ledger entries are immutable.'));
    static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
}
```

### `withdrawals`

| Column | Type | Notes |
|---|---|---|
| `id` | id | |
| `publisher_id` | foreignId | |
| `payment_method_id` | foreignId nullable | |
| `amount` | decimal(14,6) | |
| `method` | string | Snapshot: `paypal` \| `crypto` \| `bank` \| `wise` |
| `payment_details` | text | **`encrypted` cast.** Snapshot of the destination at request time. |
| `status` | string | `requested` \| `processing` \| `paid` \| `rejected` |
| `admin_note` | text nullable | |
| `tx_reference` | string nullable | External payment reference |
| `processed_by` | foreignId → users nullable | |
| `requested_at` / `processed_at` / `paid_at` | timestamps nullable | |

Indexes: `(publisher_id, status)` · `status`.

### `payment_methods`

| Column | Type | Notes |
|---|---|---|
| `publisher_id` | foreignId | |
| `type` | string | `paypal` \| `crypto` \| `bank` \| `wise` |
| `details` | text | **`encrypted:array` cast.** Shape per type. |
| `label` | string nullable | Masked display, e.g. `Coco****@gmail.com` |
| `is_default` | boolean | |
| `verified_at` | timestamp nullable | |

---

## 6. Postback Logs

### `outgoing_postbacks`

Our call to the publisher.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `conversion_id` | foreignId | |
| `app_id` | foreignId | |
| `kind` | string | `credit` \| `reversal` |
| `url` | text | Fully resolved URL, secrets redacted before storage |
| `method` | string | |
| `payload` | json nullable | For POST |
| `attempt` | unsignedTinyInteger | 1-indexed |
| `http_status` | unsignedSmallInteger nullable | |
| `response_body` | text nullable | **Truncated to 2 KB.** Publishers return HTML error pages. |
| `duration_ms` | unsignedInteger nullable | |
| `status` | string | `queued` \| `success` \| `retrying` \| `failed` |
| `error` | text nullable | |
| `next_retry_at` | timestamp nullable | |
| `created_at` | timestamp | |

Indexes: `conversion_id` · `(app_id, created_at)` · `status`.

### `incoming_postbacks`

Raw log of everything a network sent us, **written before parsing**. When a network claims they sent a conversion, this table settles it.

| Column | Type | Notes |
|---|---|---|
| `id` | bigIncrements | |
| `network_id` | foreignId nullable | Null when the driver in the URL is unknown |
| `driver` | string | From the route |
| `method` | string | |
| `query` | json nullable | |
| `body` | json nullable | |
| `headers` | json nullable | Sensitive headers stripped |
| `ip` | string(45) | |
| `signature_valid` | boolean nullable | |
| `ip_allowed` | boolean nullable | |
| `result` | string | `accepted` \| `duplicate` \| `rejected` \| `error` \| `unknown_click` |
| `reject_reason` | string nullable | |
| `conversion_id` | foreignId nullable | Set on acceptance |
| `created_at` | timestamp | |

Indexes: `(driver, created_at)` · `result` · `created_at`. Pruned after 90 days.

---

## 7. Fraud & Controls

### `fraud_rules`

| Column | Type | Notes |
|---|---|---|
| `key` | string | Unique. e.g. `vpn_ip`, `geo_mismatch`, `velocity` |
| `name` | string | |
| `description` | text | Shown in admin |
| `stage` | string | `pre_click` \| `post_conversion` |
| `enabled` | boolean | |
| `action` | string | `block` \| `flag` \| `score` |
| `score_weight` | unsignedTinyInteger | Contribution to `risk_score` |
| `config` | json | Rule-specific thresholds |

Seeded with the day-1 rule set. See [05-fraud-engine.md](05-fraud-engine.md).

### `fraud_events`

| Column | Type |
|---|---|
| `rule_key` | string |
| `stage` | string |
| `subject_type` / `subject_id` | morph → `OfferClick` or `Conversion` |
| `app_id` / `publisher_id` | foreignId nullable |
| `end_user_id` | string nullable |
| `action_taken` | string — `blocked` \| `flagged` \| `scored` |
| `context` | json — the inputs the rule saw |
| `created_at` | timestamp |

Indexes: `rule_key` · `(publisher_id, created_at)` · `(end_user_id, created_at)`.

### `blacklists`

Publisher-owned blocks.

| Column | Type | Notes |
|---|---|---|
| `publisher_id` | foreignId | |
| `app_id` | foreignId nullable | Null = applies to all of that publisher's apps |
| `type` | string | `offer` \| `country` \| `device` \| `network` |
| `value` | string | Offer ID, ISO-2 code, device type, or network id (legacy rows may store the driver; the wall accepts both) |
| `reason` | string nullable | |

Unique index on `(publisher_id, app_id, type, value)`.

### `banned_end_users`

| Column | Type | Notes |
|---|---|---|
| `publisher_id` | foreignId | |
| `app_id` | foreignId nullable | |
| `end_user_id` | string(191) | |
| `banned_by` | string | `publisher` \| `system` |
| `reason` | string nullable | |
| `expires_at` | timestamp nullable | Null = permanent |

Unique index on `(publisher_id, app_id, end_user_id)`. Index on `end_user_id`.

### `consents`

| Column | Type |
|---|---|
| `app_id` | foreignId |
| `end_user_id` | string(191) |
| `granted` | boolean |
| `purposes` | json nullable |
| `ip` | string(45) |
| `country` | char(2) nullable |
| `user_agent_hash` | string(64) |
| `created_at` | timestamp |

Unique index on `(app_id, end_user_id)`, kept as the latest state. Historical rows retained in `consent_history` if an auditor ever asks — a v2 concern, noted not built.

---

## 8. Platform

### `settings`

Key/value, cached. Not a migration column per setting.

| Key | Default |
|---|---|
| `default_rev_share` | `70.00` |
| `default_hold_days` | `30` | Unused leftover. Clearing is not days-based. |
| `default_min_payout` | `20.00` |
| `platform_name` | `AdVault` |
| `support_email` | — |
| `signups_enabled` | `true` |
| `auto_approve_publishers` | `false` |
| `wall_offers_limit` | `100` |
| `consent_required_countries` | EU-27 + UK + EEA |

### `audit_logs`

Every admin action that touches money, status, or bans.

| Column | Type |
|---|---|
| `user_id` | foreignId |
| `action` | string |
| `subject_type` / `subject_id` | morph |
| `before` / `after` | json nullable |
| `ip` | string(45) |
| `created_at` | timestamp |

---

## 9. Relationship Summary

```php
User          hasOne      Publisher
Publisher     hasMany     App, Withdrawal, PaymentMethod, LedgerEntry,
                          Blacklist, BannedEndUser, Conversion
App           hasMany     OfferClick, Conversion, OutgoingPostback
Network       hasMany     Offer, IncomingPostback, Conversion
Offer         hasMany     OfferClick, Conversion
OfferClick    hasOne      Conversion
Conversion    hasMany     OutgoingPostback
Conversion    hasOne      LedgerEntry (type=earning)
```

---

## 10. Migration Order

Foreign keys force this sequence:

```
users → settings → networks → publishers → apps → offers
      → offer_clicks → conversions → ledger_entries
      → payment_methods → withdrawals
      → outgoing_postbacks → incoming_postbacks
      → fraud_rules → fraud_events
      → blacklists → banned_end_users → consents
      → daily_stats → audit_logs
```

**Seeders:** `SettingsSeeder`, `NetworkSeeder` (9 networks, all `disabled`), `FraudRuleSeeder` (day-1 rules), `AdminUserSeeder`.
