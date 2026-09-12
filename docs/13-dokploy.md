# 13 — Deploying on Dokploy

[docs/11-infrastructure.md](11-infrastructure.md) is still the specification. It
says nginx in front of PHP-FPM, MySQL and Redis behind, Horizon on three
supervisors, a scheduler on its own minute. This document does not change any of
that — it says where each of those processes lives when the host is Dokploy
instead of a hand-built Ubuntu box, and what is different because of it.

Two deployment paths now exist in this repository and only one of them is live
on a given host:

| Path | Files | Use it when |
|---|---|---|
| VPS, by hand | `deploy/` — nginx blocks, supervisor, `deploy.sh` | You own the machine and installed the stack yourself. |
| Dokploy | `Dockerfile`, `docker-compose.yml`, `docker/`, `.env.dokploy.example` | This document. |

They are not alternatives to keep in sync by hand. The container image installs
`deploy/nginx/snippets/` and `deploy/nginx/conf.d/playndgain-limit-zones.conf`
**verbatim**, so the header rules and the rate-limit zones have exactly one
source. Only the three server blocks are restated under `docker/nginx/templates/`,
and `tests/Feature/Security/SecurityChecklistTest.php` asserts that both copies
still carry the same asymmetry.

---

## 1. The shape

One image. Three Swarm services, differing only by `CONTAINER_ROLE`:

| Service | Role | Replicas | What it is on the VPS |
|---|---|---|---|
| `app` | nginx + PHP-FPM under supervisor | **1** (roll `start-first`, rollback `stop-first`) | The web server |
| `horizon` | `artisan horizon` | 1 (updates after `app`) | `deploy/supervisor/playndgain-horizon.conf` |
| `scheduler` | `artisan schedule:work` | 1 (updates after `horizon`) | The crontab line in §4 of docs/11 |

One build means a worker can never be running last week's code against this
week's schema.

MySQL and Redis are **not** in `docker-compose.yml`. They are Dokploy services,
created once in the panel, so their volumes, their backups and their lifecycle
survive every redeploy of this stack.

---

## 2. First deployment

### 2.1 Create the databases

In the Dokploy project, add:

- a **MySQL** service — image `mysql:9.7`, database `playndgain`
- a **Redis** service — image `redis:8`

Note each service's container name and password. They stay on
`dokploy-network`, which is how this application reaches them; neither needs an
external port, and neither should have one.

### 2.2 Create the application

Add a **Compose** application pointing at this repository, `docker-compose.yml`
at the root.

**Compose Type must be Stack** (Docker Swarm), not plain Docker Compose.
Plain Compose ignores `deploy.update_config`, so every redeploy would still
stop the old container before the new one is healthy. Stack honors
`order: start-first` and `parallelism: 1` on `app`.

If the service was already created as Docker Compose, recreate it as Stack —
Dokploy does not convert the type in place.

#### Advanced → Command (required)

Stack cannot `build:` by itself. Dokploy prefixes the field with `docker` and
**rejects shell metacharacters** (`&&`, `|`, `>`), so do not paste a shell
pipeline. Use a one-shot `docker run` that mounts the compose code and the
Docker socket, then runs `docker/dokploy-stack-deploy.sh`:

```bash
run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /etc/dokploy/compose/playndgain-u0obl7/code:/work -w /work docker:27-cli sh docker/dokploy-stack-deploy.sh playndgain-u0obl7
```

Replace `playndgain-u0obl7` if your General-tab appName differs. That becomes
`docker run …` on the host and:

1. Builds `playndgain-app:latest` with Compose
2. Resolves the compose file for Swarm
3. `docker stack deploy --prune`
4. Force-rolls **app → horizon → scheduler** in that order (`start-first`; rollback is `stop-first` so a 1-replica failure cannot hang at 0/1)

#### AutoDeploy

On the same Compose application, enable **AutoDeploy** for the `main` branch
(GitHub webhook). Each push runs that command; you do not click Deploy by hand
for ordinary releases.

### 2.3 Fill in the environment

Copy `.env.dokploy.example` into the Environment tab and fill the blanks. Two
of them decide whether the deploy works at all:

- **`APP_KEY`** — generate it once, with `php artisan key:generate --show`, and
  keep it forever. Every encrypted column in this database is unreadable under a
  different key, and a key generated later does not decrypt rows written under
  the old one. It belongs in the password manager beside the database
  credentials. The container refuses to start without it.
- **`DB_HOST` / `REDIS_HOST`** — the Dokploy service container names, not IPs
  and not `localhost`.

Dokploy writes these to a `.env` file beside the compose file. The `env_file:`
line in `docker-compose.yml` is what actually puts them inside the containers —
Dokploy does not inject them on its own, and without that line the application
starts with no configuration at all.

### 2.4 Add the domains

Three domains, all on the **`app`** service, port **80**:

| Domain | |
|---|---|
| `playndgain.com` | Marketing site, `/docs`, both panels |
| `wall.playndgain.com` | The offerwall |
| `api.playndgain.com` | Inbound network postbacks |

They must match `APP_DOMAIN`, `WALL_DOMAIN` and `API_DOMAIN` in the
environment. nginx picks the server block by the `Host` Traefik forwards; a
domain that reaches the container under a name no block claims falls through to
the site block, and the site block frame-protects what it serves. On the wall
that means a blank iframe for every publisher, a normal `200` in our logs, and
nothing anywhere that says why.

Let Dokploy issue the certificates. **Do not** add an HSTS or a frame-options
middleware to the wall domain in Traefik — §4 below.

### 2.5 Deploy

The first boot runs migrations (under a MySQL advisory lock so two `app`
replicas never race), builds the caches, and starts nginx. Horizon and the
scheduler wait until that lock is free and `migrate:status` shows nothing
Pending, so nothing processes a job against a half schema.

Swarm keeps one `app` task (plus horizon and scheduler). On each AutoDeploy,
the new task becomes healthy before the old one stops (`start-first`); horizon
and scheduler roll only after `app` has finished updating.

Then, once, by hand — the historical payout statements, per
[docs/06-ledger-payouts.md](06-ledger-payouts.md) §9:

```bash
# Pick any running app task.
APP_TASK=$(docker ps -q -f name=<stack>_app | head -1)
docker exec -u www-data "$APP_TASK" php artisan payouts:backfill-statements --dry-run
docker exec -u www-data "$APP_TASK" php artisan payouts:backfill-statements
docker exec -u www-data "$APP_TASK" php artisan payouts:reconcile-statements
```

---

## 3. What each deploy does

`docker/entrypoint.sh` is `deploy/deploy.sh`'s sequence, in the same order and
for the same reasons:

1. Refuse to start on an empty `APP_KEY`, or on `APP_ENV=production` with a
   truthy `APP_DEBUG`. These are the only automated enforcement of the docs/11
   §10 checklist rows anywhere in this project.
2. Wait for MySQL. Nothing sequences a Dokploy database against this container,
   and without the wait the first deploy races its own MySQL still initialising.
3. `migrate --force` — **`app` only**, and serialised with a MySQL `GET_LOCK`
   (`docker/migrate-locked.php`) so two Swarm replicas never migrate in
   parallel. Horizon and the scheduler wait for that lock and for an empty
   pending list before they start work.
4. `config:cache`, `route:cache`, `view:cache` as three commands. Written as one
   invocation with two arguments, the route and view caches are never built.
5. `filament:optimize`, `storage:link`.
6. `responsecache:clear` last, because cached landing HTML references hashed
   asset filenames this image has just replaced.

Everything that is a pure function of the source — Composer's autoloader,
Filament's published assets, the docs search index, the Vite build — happens at
image build time instead, so a container restart is not a rebuild.

**Rollback** is Dokploy's: redeploy the previous image. The migration caveat
from docs/11 §9 is unchanged — a destructive migration ships in two deploys, add
the column, ship code that writes both, drop the old one a deploy later. Never
in one step on a table holding money.

---

## 4. What is different from the VPS, and why

**Traefik terminates TLS, so nothing in the image listens on 443.** The nginx
blocks are plain HTTP on the Docker network. Certbot has no part here.

**The real client IP is recovered in `docker/nginx/nginx.conf`.** Every request
arrives from Traefik's container, so without `real_ip_header X-Forwarded-For`,
`$remote_addr` is the proxy for every visitor on earth. Two things then break
quietly: `limit_req zone=wall` keys on one address and spends the whole 60r/m
budget in the first busy second, and the fraud engine places every click at the
same IP. Laravel's own view of the client was already correct —
`bootstrap/app.php` trusts proxies and `TrustCloudflare` runs before anything
reads `$request->ip()`. That fixes PHP and cannot fix nginx.

**Headers stay nginx's, not Traefik's.** The wall must be embeddable from
arbitrary publisher domains; the panels must not be embeddable at all. That
asymmetry is expressed in the server blocks and asserted by
`SecurityChecklistTest`. A Traefik middleware adding `X-Frame-Options` or HSTS
to the wall domain would sit outside both and would break every integration at
once.

**Logs go to stderr and `storage/app/logs/laravel.log`.** A production stack
keeps `stderr` so Dokploy's container log still has every line (nginx and
PHP-FPM do the same). The file sits on `playndgain-storage`, so Log Viewer on
`app` also sees Horizon and scheduler lines and a redeploy does not wipe it.

**`storage/app` is a named volume shared by all three containers.** The
scheduler fetches the GeoIP databases weekly and the wall reads them; the
nightly backup writes there until `BACKUP_DISK` points off-site. Set it before
there is anything worth restoring — the local disk survives a bad migration and
nothing else.

**PHP-FPM's `clear_env = no` is load-bearing.** php-fpm clears the worker
environment by default, which would drop every variable Dokploy injects and
leave the application booting against framework defaults, failing in ways that
read like a database problem.

---

## 5. Operating it

```bash
# Anything from docs/11 that you would have run on the box (Stack tasks)
APP_TASK=$(docker ps -q -f name=<stack>_app | head -1)
docker exec -u www-data "$APP_TASK" php artisan <command>

# Queue health — the numbers docs/11 §7 alerts on
HZ_TASK=$(docker ps -q -f name=<stack>_horizon | head -1)
docker exec -u www-data "$HZ_TASK" php artisan horizon:status

# Restart the workers on new code without touching the site
docker exec -u www-data "$APP_TASK" php artisan horizon:terminate
```

`horizon` has an hour of stop grace, which is docs/11 §3's `stopwaitsecs=3600`:
in-flight postbacks land instead of being cut off mid-request on redeploy. The
scheduler has ten minutes, enough for a nightly backup or a GeoIP pull to finish
rather than leave a truncated archive that looks like a real one.

The Horizon dashboard at `/horizon` is for admins who are already signed into
`/admin` (same Filament session as Log Viewer). A guest is sent to the admin
login; a publisher gets 403. `horizon:status` above still works from the
container when you only need the process state.

---

## 6. The operator's half of docs/11 §10

Dokploy closes several rows that `deploy/README.md` leaves to a person: MySQL
and Redis are unreachable from outside the Docker network, TLS and its renewal
are Traefik's, and the environment is stored by the panel rather than in a file
whose mode someone has to check. These are still yours:

- [ ] Root SSH login disabled, key-only auth, on the Dokploy host itself
- [ ] UFW: 22, 80, 443 only — and **not** the MySQL or Redis ports
- [ ] The Dokploy panel behind its own domain with a strong password and 2FA
- [ ] `APP_KEY` stored somewhere other than this server
- [ ] `BACKUP_DISK` pointing off-site
- [ ] Backup restore drill passed this month — an untested backup is a
      hypothesis (docs/11 §6)
