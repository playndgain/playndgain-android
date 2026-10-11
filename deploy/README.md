# deploy/

The production server configuration, in the repository instead of in a document.

> **Two deployment paths now exist, and only one is live on a given host.**
> This directory is the hand-built VPS: you installed nginx, PHP-FPM, MySQL,
> Redis and supervisor yourself, and `deploy.sh` releases onto them.
> [docs/13-dokploy.md](../docs/13-dokploy.md) is the container path —
> `Dockerfile`, `docker-compose.yml` and `docker/` — for a Dokploy host.
>
> They are not two copies to keep in step by hand. The container image installs
> `nginx/snippets/` and `nginx/conf.d/playndgain-limit-zones.conf` from this
> directory **verbatim**, so the header rules and the rate-limit zones have one
> source. Only the three server blocks are restated, under
> `docker/nginx/templates/`, and `SecurityChecklistTest` asserts both copies
> still carry the same asymmetry.

`docs/11-infrastructure.md` is still the spec and still explains *why* each of
these is shaped the way it is. These files are the executable copy: when the
build was a copy-paste out of the doc, the machine drifted from the spec the
first time anyone edited a header on the box and forgot to say so.

| File | Goes to | What it is |
|---|---|---|
| `nginx/playndgain.com.conf` | `/etc/nginx/sites-available/` | Marketing site, `/docs`, both Filament panels, and the offerwall at `/wall`, whose location block is the one framable path on this host. **Read its comment before touching it.** |
| `nginx/wall.playndgain.com.conf` | `/etc/nginx/sites-available/` | The offerwall on its own host, kept for embeds handed out before the move to `/wall`. **Iframe-embeddable — read the header comment before touching it.** |
| `nginx/api.playndgain.com.conf` | `/etc/nginx/sites-available/` | Inbound network postbacks. Every other path is a 404. |
| `nginx/conf.d/playndgain-limit-zones.conf` | `/etc/nginx/conf.d/` | `limit_req_zone` declarations. Must be in the `http` context. |
| `nginx/snippets/playndgain-security-headers.conf` | `/etc/nginx/snippets/` | `X-Frame-Options: SAMEORIGIN`, nosniff, referrer policy, HSTS. Included by the main and API blocks, **never by the wall**. |
| `nginx/snippets/playndgain-gzip.conf` | `/etc/nginx/snippets/` | gzip for CSS, JS, JSON and SVG. Included by the main and wall blocks; the Lighthouse job in CI serves through this same file. |
| `supervisor/playndgain-horizon.conf` | `/etc/supervisor/conf.d/` | Keeps Horizon alive, with an hour of grace on shutdown. |
| `deploy.sh` | stays in the release | The §9 deploy sequence, in its stated order. |

## Fill in per host

- **`server_name`** in all three server blocks, if the domains are not
  `playndgain.com` / `wall.playndgain.com` / `api.playndgain.com`.
- **`root`** and **`APP_DIR`** — all four files assume `/var/www/playndgain`.
- **PHP-FPM socket** — `/run/php/php8.5-fpm.sock` in three places. Check
  `ls /run/php/` after installing `php8.5-fpm`.
- **TLS** — none of the files carry certificate paths. Run certbot once and it
  rewrites the `listen` lines in place:

  ```bash
  certbot --nginx -d playndgain.com -d wall.playndgain.com -d api.playndgain.com
  ```

## Install

```bash
install -m 644 nginx/conf.d/playndgain-limit-zones.conf      /etc/nginx/conf.d/
install -m 644 nginx/snippets/playndgain-security-headers.conf /etc/nginx/snippets/
install -m 644 nginx/snippets/playndgain-gzip.conf             /etc/nginx/snippets/
install -m 644 nginx/*.conf                                /etc/nginx/sites-available/
ln -sf /etc/nginx/sites-available/playndgain.com.conf      /etc/nginx/sites-enabled/
ln -sf /etc/nginx/sites-available/wall.playndgain.com.conf /etc/nginx/sites-enabled/
ln -sf /etc/nginx/sites-available/api.playndgain.com.conf  /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default        # or it answers for unmatched Host headers
nginx -t && systemctl reload nginx

install -m 644 supervisor/playndgain-horizon.conf /etc/supervisor/conf.d/
supervisorctl reread && supervisorctl update
```

The scheduler is a crontab line for the deploy user, not a file here
(docs/11 §4):

```
* * * * * cd /var/www/playndgain && php artisan schedule:run >> /dev/null 2>&1
```

## Deploying

```bash
chmod +x deploy/deploy.sh          # once — the exec bit does not survive a
                                   # checkout made on Windows
sudo -u www-data deploy/deploy.sh
```

Run it as the user that owns the files — as root it leaves root-owned entries
in `storage/framework` and `bootstrap/cache` that PHP-FPM then cannot write.

The script refuses to start if `.env` has `APP_ENV=production` together with a
truthy `APP_DEBUG`. That is the only automated enforcement of that checklist row
anywhere in this project; nothing in the application can stop debug mode being
switched on by hand.

**Rollback** (docs/11 §9): previous release + `migrate:rollback`, and only when
the migration is reversible. Destructive migrations ship in two deploys — add
the column, ship code that writes both, drop the old one a deploy later. Never
in one step on a table holding money.

## Where these differ from docs/11

Each deviation is also commented in the file it applies to.

1. **`limit_req_zone` moved out of the server block** (§2 puts it inside).
   nginx only accepts it in the `http` context; a faithful copy fails
   `nginx -t` and leaves the machine with no web server at all.
2. **The wall block gained a `location ~ \.php$`** (§2 has none). Without it
   `try_files … /index.php` hands the front controller to the static file
   handler and the browser downloads the PHP source instead of running it.
3. **`api.playndgain.com.conf` is new.** §2 names the domain and its purpose but
   gives no block. Written from the table row plus §2's stated reason for the
   split: its own rate-limit zone, its own logs, and a 404 for everything that
   is not `/postback/`.
4. **`config:cache route:cache view:cache` split into three commands.** As §9
   writes it, that is one artisan call with two ignored arguments — the route
   and view caches are never built.
5. **`fastcgi_hide_header X-Frame-Options` in the wall block.**
   `add_header X-Frame-Options ""` stops nginx adding the header but cannot
   remove one PHP emitted, and the doc's line reads as if it does. This is the
   half that makes the promise true if a hardening middleware ever lands in the
   application.
6. **The `APP_DEBUG` pre-flight check** and the dotfile `deny all` blocks are
   additions, not in the doc. Both close checklist rows (§10) that nothing else
   was closing.
7. **Supervisor log paths** added, so Horizon's output lands somewhere
   guessable and is rotated instead of growing into the disk-usage alert.
8. **`snippets/playndgain-gzip.conf` is new.** §2 has no compression directives
   and Ubuntu's stock `nginx.conf` gzips `text/html` only, so the gzipped byte
   budgets in docs/09 §8 were never what the box served. The site and wall
   blocks include it, and `.github/lighthouse/serve.sh` serves CI's Lighthouse
   run through the same file, so the build measures the bytes production sends.

## Not in these files — the operator's half of docs/11 §10

Nothing in the repository can verify these. They are checked on the box, by
hand, and the check is the deploy runbook's, not the test suite's:

- [ ] Root SSH login disabled, key-only auth
- [ ] UFW: 22, 80, 443 only
- [ ] fail2ban on SSH and nginx auth
- [ ] MySQL and Redis bound to `127.0.0.1`
- [ ] Redis password set (`requirepass`, and `REDIS_PASSWORD` to match)
- [ ] `.env` mode `600`, owned by the deploy user
- [ ] TLS live on all three domains, certificate renewal timer enabled
- [ ] Backup restore drill passed this month — an untested backup is a
      hypothesis (docs/11 §6)
