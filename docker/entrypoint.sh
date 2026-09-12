#!/bin/sh
#
# PLAYNDGAIN container entrypoint — docs/13-dokploy.md.
#
# One image, three roles. CONTAINER_ROLE picks which:
#
#   app        nginx + PHP-FPM. Runs the release steps (migrations, caches) and
#              is the only role that writes to the database on boot.
#   horizon    the queue supervisors from config/horizon.php.
#   scheduler  `schedule:work`, i.e. the crontab line from docs/11 §4.
#
# The order of the release steps is deploy/deploy.sh's order, and it is the
# order for the reasons that script gives: migrations run BEFORE the caches, so
# a cached config never points at a schema that does not exist yet, and the
# response cache is cleared LAST, because cached HTML references hashed asset
# filenames that this image has just changed.

set -eu

ROLE="${CONTAINER_ROLE:-app}"
ARTISAN="su-exec www-data php artisan"

log() { printf '[entrypoint] %s\n' "$*"; }
die() { printf '[entrypoint] FATAL: %s\n' "$*" >&2; exit 1; }

# ---------------------------------------------------------------------------
# Pre-flight
# ---------------------------------------------------------------------------
#
# The same two refusals deploy/deploy.sh makes, for the same reason: they are
# the only automated enforcement of the docs/11 §10 checklist rows anywhere in
# this project, and a container that starts anyway turns both into a silent
# production incident.

[ -n "${APP_KEY:-}" ] || die "APP_KEY is empty.
    Every encrypted column in this database — network credentials, publisher
    payout details, app secret keys — is unreadable without it, and a key
    generated later does not decrypt rows written under the old one.
    Generate one on your machine with:  php artisan key:generate --show
    then paste it into the Dokploy environment as APP_KEY (keep it forever)."

case "${APP_ENV:-production}:$(printf '%s' "${APP_DEBUG:-false}" | tr '[:upper:]' '[:lower:]')" in
    production:1|production:true|production:on|production:yes)
        die "APP_ENV=production with APP_DEBUG=${APP_DEBUG} (docs/11 §10).
    A debug-mode production box prints database credentials and the app key on
    any unhandled exception. Set APP_DEBUG=false in the Dokploy environment."
        ;;
esac

[ -n "${APP_URL:-}" ] || log "warning: APP_URL is unset — mail links, invoice
    PDFs and the wall's own asset URLs will be built against http://localhost."

# ---------------------------------------------------------------------------
# Writable state
# ---------------------------------------------------------------------------
#
# storage/app is a Docker volume, so its ownership is whatever the volume was
# created with rather than what the image set. Everything else here exists in
# the image already and is re-asserted because a bind mount over any of it
# would arrive root-owned and PHP-FPM would fail its first write.

mkdir -p \
    storage/app/public \
    storage/app/private \
    storage/app/logs \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache

# ---------------------------------------------------------------------------
# Wait for the database
# ---------------------------------------------------------------------------
#
# Dokploy's MySQL and Redis are separate services on the same Docker network,
# and nothing sequences them against this container. Without the wait, the very
# first deploy races: migrations run against a MySQL that is still initialising,
# the container exits, and the deployment reads as a broken image.

wait_for_database() {
    attempt=0
    max="${DB_WAIT_ATTEMPTS:-60}"

    while [ "$attempt" -lt "$max" ]; do
        if php -r '
            $dsn = sprintf(
                "mysql:host=%s;port=%s;dbname=%s",
                getenv("DB_HOST") ?: "mysql",
                getenv("DB_PORT") ?: "3306",
                getenv("DB_DATABASE") ?: "playndgain"
            );
            try {
                new PDO($dsn, getenv("DB_USERNAME") ?: "root", getenv("DB_PASSWORD") ?: "", [
                    PDO::ATTR_TIMEOUT => 3,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage() . PHP_EOL);
                exit(1);
            }
        ' 2>/dev/null; then
            log "database reachable at ${DB_HOST:-mysql}:${DB_PORT:-3306}."
            return 0
        fi

        attempt=$((attempt + 1))
        [ $((attempt % 10)) -eq 0 ] && log "waiting for ${DB_HOST:-mysql}:${DB_PORT:-3306} (${attempt}/${max})..."
        sleep 2
    done

    die "database at ${DB_HOST:-mysql}:${DB_PORT:-3306} did not answer after $((max * 2))s.
    Check DB_HOST against the Dokploy service name, and that this service and
    the database are both attached to dokploy-network."
}

# ---------------------------------------------------------------------------
# Migrations — single-flight under a MySQL advisory lock
# ---------------------------------------------------------------------------
#
# Rolling deploys run two `app` replicas. Without a lock, both can enter
# `migrate --force` on the same boot and one dies holding a half-applied
# schema. GET_LOCK is connection-scoped, so migrate-locked.php holds one PDO
# open for the whole migrate (a separate acquire-then-exit process would
# release the lock immediately).
#
# Horizon and the scheduler must not process jobs until that finishes, so
# they wait until the lock is free and `migrate:status` reports no Pending
# rows (docs/13 rolling deploy).

MIGRATE_LOCK_NAME="${MIGRATE_LOCK_NAME:-playndgain_migrate}"
MIGRATE_LOCK_TIMEOUT="${MIGRATE_LOCK_TIMEOUT:-300}"

php_lock_in_use() {
    php -r '
        $dsn = sprintf(
            "mysql:host=%s;port=%s;dbname=%s",
            getenv("DB_HOST") ?: "mysql",
            getenv("DB_PORT") ?: "3306",
            getenv("DB_DATABASE") ?: "playndgain"
        );
        $pdo = new PDO($dsn, getenv("DB_USERNAME") ?: "root", getenv("DB_PASSWORD") ?: "", [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $name = getenv("MIGRATE_LOCK_NAME") ?: "playndgain_migrate";
        $stmt = $pdo->prepare("SELECT IS_USED_LOCK(?)");
        $stmt->execute([$name]);
        $holder = $stmt->fetchColumn();
        echo ($holder === false || $holder === null) ? "0" : "1";
    '
}

run_migrations_locked() {
    export MIGRATE_LOCK_NAME MIGRATE_LOCK_TIMEOUT
    log "running migrations under lock ${MIGRATE_LOCK_NAME}"
    # www-data owns the app tree; migrate must run as that user like every
    # other artisan call. The lock script only opens PDO with the DB_* user.
    if ! su-exec www-data php /usr/local/bin/migrate-locked.php; then
        die "migrate --force failed under lock ${MIGRATE_LOCK_NAME}"
    fi
}

wait_for_schema() {
    attempt=0
    max="${SCHEMA_WAIT_ATTEMPTS:-90}"

    export MIGRATE_LOCK_NAME

    while [ "$attempt" -lt "$max" ]; do
        in_use="$(php_lock_in_use 2>/dev/null || echo 1)"

        if [ "$in_use" = "0" ]; then
            if status="$($ARTISAN migrate:status --no-ansi 2>/dev/null)"; then
                if ! printf '%s\n' "$status" | grep -qi 'pending'; then
                    if printf '%s\n' "$status" | grep -q .; then
                        log "schema ready (no pending migrations)."
                        return 0
                    fi
                fi
            fi
        fi

        attempt=$((attempt + 1))
        [ $((attempt % 10)) -eq 0 ] && log "waiting for migrations to finish (${attempt}/${max})..."
        sleep 2
    done

    die "schema was not ready after $((max * 2))s — app migrations may have failed."
}

# ---------------------------------------------------------------------------
# Caches
# ---------------------------------------------------------------------------
#
# Built at start, not at build: `config:cache` freezes environment values, and
# the environment does not exist until Dokploy starts the container. Three
# separate commands — `artisan config:cache route:cache view:cache` is one
# invocation with two ignored arguments, and the route and view caches never
# get built.
#
# Every role builds its own, in its own container filesystem. Cheap, and it
# means a worker never reads a cache written by a container running other code.

build_caches() {
    $ARTISAN config:cache
    $ARTISAN route:cache
    $ARTISAN view:cache
}

# ---------------------------------------------------------------------------
# Roles
# ---------------------------------------------------------------------------

case "$ROLE" in
    app)
        log "role: app (nginx + php-fpm)"

        # server_name is the only thing that varies per deployment. envsubst is
        # given an explicit variable list so nginx's own $uri, $query_string and
        # $realpath_root survive untouched.
        : "${APP_DOMAIN:=playndgain.com}"
        : "${WALL_DOMAIN:=wall.playndgain.com}"
        : "${API_DOMAIN:=api.playndgain.com}"
        export APP_DOMAIN WALL_DOMAIN API_DOMAIN

        for template in /etc/nginx/templates/*.conf.template; do
            rendered="/etc/nginx/conf.d/$(basename "$template" .template)"
            envsubst '${APP_DOMAIN} ${WALL_DOMAIN} ${API_DOMAIN}' < "$template" > "$rendered"
        done

        log "domains: site=${APP_DOMAIN} wall=${WALL_DOMAIN} api=${API_DOMAIN}"
        nginx -t

        wait_for_database

        if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
            log "running migrations (advisory lock)"
            run_migrations_locked
        else
            log "RUN_MIGRATIONS is not \"true\" — skipping migrations"
            wait_for_schema
        fi

        build_caches
        $ARTISAN filament:optimize
        $ARTISAN storage:link || true

        # spatie/laravel-responsecache holds rendered landing HTML that points
        # at hashed asset filenames this image has just replaced. Checked rather
        # than `|| true`d: `|| true` would also swallow a real failure — Redis
        # down, cache store misconfigured — and serve stale HTML referencing
        # files that are gone.
        case "$(su-exec www-data php artisan list --raw)" in
            *responsecache:clear*) $ARTISAN responsecache:clear ;;
            *) log "note: responsecache:clear not available on this release — skipped." ;;
        esac

        log "handing over to supervisor"
        exec supervisord -c /etc/supervisord.conf
        ;;

    horizon)
        log "role: horizon"
        wait_for_database
        # No migrations here — app owns them under the advisory lock. Wait until
        # that lock is free and nothing is Pending so jobs never hit a half
        # schema during a rolling deploy.
        wait_for_schema
        build_caches

        # SIGTERM reaches Horizon directly because it is PID 1's exec target:
        # it stops accepting jobs and lets the ones in flight finish, which is
        # the `stopwaitsecs=3600` behaviour docs/11 §3 asks supervisor for.
        exec su-exec www-data php artisan horizon
        ;;

    scheduler)
        log "role: scheduler"
        wait_for_database
        wait_for_schema
        build_caches

        # `schedule:work` is the crontab line from docs/11 §4 as a foreground
        # process: it wakes every minute and runs what is due. routes/console.php
        # stays the single source of the schedule.
        exec su-exec www-data php artisan schedule:work
        ;;

    *)
        # Anything else is a one-off: `docker compose run --rm app sh` for a
        # shell, or a backfill command run once by hand. The pre-flight checks
        # above have already run, so the command sees the same environment the
        # long-running roles do.
        [ "$#" -gt 0 ] || die "unknown CONTAINER_ROLE \"${ROLE}\" and no command given.
    Valid roles are: app, horizon, scheduler."
        log "role: ${ROLE} — executing given command"
        exec su-exec www-data "$@"
        ;;
esac
