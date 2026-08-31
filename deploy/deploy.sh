#!/usr/bin/env bash
#
# AdVault deploy — docs/11-infrastructure.md §9, in the order that document
# states, because the order is the whole point:
#
#   * migrations run BEFORE the caches are rebuilt, so a cached config never
#     points at a schema that does not exist yet;
#   * `responsecache:clear` runs AFTER `npm run build`, because cached HTML
#     references hashed asset filenames and serving it after a rebuild points
#     browsers at files that are gone;
#   * `horizon:terminate`, never `kill` — terminate lets in-flight postbacks
#     finish and supervisor brings Horizon back on the new code.
#
# Usage:  sudo -u www-data deploy/deploy.sh          (from the release root)
#         APP_DIR=/var/www/advault deploy/deploy.sh
#
# Run it as the user that owns the files. Running it as root leaves
# root-owned entries in storage/framework and bootstrap/cache, and the next
# request from PHP-FPM cannot write them.

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/advault}"
PHP="${PHP:-php}"

cd "$APP_DIR"

# If anything below fails the app is very likely still in maintenance mode, and
# a half-deployed site that stays down silently is worse than one that says so.
trap 'echo; echo "DEPLOY FAILED. The app may still be down — fix, then run: $PHP artisan up" >&2' ERR

# ---------------------------------------------------------------------------
# Pre-flight
# ---------------------------------------------------------------------------
#
# NOT IN THE DOC. docs/11 §10 requires APP_DEBUG=false in production and there
# is nothing in the application that can enforce it — `config('app.debug')` is
# whatever .env says. This is the last moment anyone looks. A debug-mode
# production box prints database credentials and the app key on any unhandled
# exception, so this refuses the deploy rather than warning about it.
#
# An absent APP_DEBUG is fine: config/app.php defaults it to false.

# Last assignment wins, inline `# comment` stripped, quotes and spaces removed,
# lower-cased — i.e. read the way phpdotenv reads it, so this agrees with what
# the application will actually see.
#
# The `|| true` is not decoration. Under `set -euo pipefail` a `grep` that
# matches nothing exits 1, the pipeline inherits it, the assignment inherits
# that, and the ERR trap fires — so a .env that simply omits APP_DEBUG, which
# the comment above explicitly blesses as fine, would abort the deploy before
# `artisan down` had even run, printing "the app may still be down" about an
# app that was never taken down.
envval() {
    { grep -E "^${1}=" .env || true; } | tail -n1 | cut -d= -f2- | sed 's/[[:space:]]*#.*$//' | tr -d "\"' " | tr '[:upper:]' '[:lower:]'
}

if [ -f .env ]; then
    app_env="$(envval APP_ENV)"
    app_debug="$(envval APP_DEBUG)"

    if [ "$app_env" = "production" ] && [ -n "$app_debug" ] && [ "$app_debug" != "false" ] && [ "$app_debug" != "0" ]; then
        echo "REFUSING TO DEPLOY: APP_ENV=production with APP_DEBUG=${app_debug} in .env (docs/11 §10)." >&2
        exit 1
    fi
else
    echo "REFUSING TO DEPLOY: no .env in ${APP_DIR}." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# §9, in order
# ---------------------------------------------------------------------------

$PHP artisan down --render=errors::503 --retry=60

git pull --ff-only
composer install --no-dev --optimize-autoloader

# Node is a build-time dependency only — nothing it produces runs in
# production, it just emits hashed files into public/build.
npm ci
npm run build
$PHP artisan docs:index

$PHP artisan migrate --force

# Three commands. `artisan config:cache route:cache view:cache` — as §9 writes
# it — is ONE artisan invocation with two stray arguments: route and view
# caches are never built, and every request pays to compile them again.
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache

$PHP artisan filament:optimize

# spatie/laravel-responsecache is installed and the landing route uses it, so
# this normally runs. The presence check stays because the step is the one whose
# absence is silent: checked rather than `|| true`d, since `|| true` would also
# swallow a real failure — Redis down, cache store misconfigured — and ship
# stale HTML pointing at asset hashes that no longer exist.
#
# Matched with `case` rather than a pipe into grep: under `pipefail`, grep -q
# exits at the first match and artisan takes a SIGPIPE, which reads as a failed
# check and silently skips the command on exactly the boxes that have it.
artisan_commands="$($PHP artisan list --raw)"

case "$artisan_commands" in
    *responsecache:clear*)
        $PHP artisan responsecache:clear
        ;;
    *)
        echo "note: responsecache:clear not available on this release — skipped."
        ;;
esac

# Graceful. Supervisor restarts Horizon on the new code; stopwaitsecs=3600 in
# deploy/supervisor/advault-horizon.conf gives in-flight jobs room to land.
$PHP artisan horizon:terminate

$PHP artisan up

trap - ERR
echo "Deployed $(git rev-parse --short HEAD) to ${APP_DIR}."
