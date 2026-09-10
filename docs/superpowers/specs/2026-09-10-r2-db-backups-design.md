# R2 DB Backups — Design

**Date:** 2026-09-10
**Status:** Blocked on R2 S3 API token write permission (see §8)
**Extends:** `docs/11-infrastructure.md` §6, `docs/13-dokploy.md` operator checklist (`BACKUP_DISK` off-site).

---

## 1. Goal

Off-site MySQL (and Spatie file) backups on Cloudflare R2, with a hard free-tier budget so storage never drifts into paid usage on the linked card.

## 2. Architecture

```text
MySQL (Dokploy) ──► Spatie backup:run @ 02:00 ──► R2 playndgain-backups (app name /)
                 └──► Dokploy dump @ 03:00     ──► R2 playndgain-backups /dokploy/
```

| Piece | Choice |
|---|---|
| Account | Cloudflare `dev.ziad` (`d5e2b0c2a45342ff001e146acef00869`) |
| Bucket | `playndgain-backups` (private, Standard, default jurisdiction, location WEUR) |
| Endpoint | `https://<account_id>.r2.cloudflarestorage.com` (not `.eu.` — that is jurisdiction-only) |
| Region | `auto` |
| Creds | One R2 S3 API token; same keys in compose `AWS_*` and Dokploy destination |

## 3. Pipelines

### Spatie (primary)

- Already scheduled: `backup:clean` 01:30, `backup:run` 02:00, `backup:monitor` 06:00
- Includes DB + `.env` + `storage/app` (geoip excluded)
- Disk: `BACKUP_DISK=s3` → Laravel `s3` disk → R2
- Retention: keep 14 days; delete oldest when Spatie disk usage > **4096 MB**
- Monitor disk follows `BACKUP_DISK`; health max age 1 day, max **4096 MB**

### Dokploy (second dump)

- Destination: `playndgain-r2` (S3-compatible → same bucket)
- MySQL backup on database `playndgain`
- Schedule: `0 3 * * *` (03:00 daily)
- Prefix: `dokploy`
- `keepLatestCount`: **7**

## 4. Free-tier guardrails

R2 free storage is 10 GB. Combined target **≤8 GB**:

- Spatie ≤ ~4 GB (cleanup cap)
- Dokploy ≤ ~7 dumps
- ~2 GB headroom

No public bucket, no custom domain, no second token unless rotated later.

## 5. Compose env (production)

```text
BACKUP_DISK=s3
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=auto
AWS_BUCKET=playndgain-backups
AWS_ENDPOINT=https://d5e2b0c2a45342ff001e146acef00869.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=true
```

Compose must be redeployed/reloaded after env changes so the scheduler container sees them.

## 6. Out of scope

- Binlog streaming
- Automated monthly restore drill (still an operator checklist item)
- Separate per-pipeline API tokens
- Advault / other accounts

## 7. Verification

1. Dokploy destination test connection succeeds
2. Manual Dokploy MySQL backup lands under `dokploy/` (path is actually `{mysqlAppName}/dokploy/…`)
3. Manual `php artisan backup:run` in the app container lands on R2
4. Combined object size stays well under 8 GB

## 8. Blocker (2026-09-10)

Dokploy finds the MySQL container and runs `mysqldump` successfully. Upload fails:

`S3 PutObject 403 AccessDenied`

Destination **test** only proves list/read. The S3 API token in compose/Dokploy needs **Object Read & Write** on `playndgain-backups`.

Create a new token: Cloudflare → R2 → Manage API tokens → User API Token → Object Read & Write → specify bucket `playndgain-backups`. Then update compose `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` and Dokploy destination `playndgain-r2`, redeploy compose, re-run manual MySQL backup.

Also fixed during setup (already applied):
- Created bucket `playndgain-backups` (WEUR, default jurisdiction)
- Corrected compose `AWS_ENDPOINT` (removed erroneous `.eu` jurisdiction host)
- Corrected `AWS_USE_PATH_STYLE_ENDPOINT=true` (was `tru`)
- Dokploy destination `playndgain-r2` + backup schedule `0 3 * * *`, prefix `dokploy`, keep 7
- Spatie retention 14 days / 4096 MB cap in `config/backup.php`
