#!/bin/sh
#
# PLAYNDGAIN — Dokploy Stack rolling deploy (docs/13-dokploy.md).
#
# Docker Stack ignores `build:` and plain Compose ignores `deploy.update_config`.
# This script bridges both: build the image with Compose, resolve the file, then
# `docker stack deploy` so Swarm rolls replicas with start-first.
#
# Services update in order: app → horizon → scheduler. Autodeploy on push
# still works: set this script as the Dokploy Compose Advanced → Command and
# enable AutoDeploy.
#
# Horizon/scheduler use long stop_grace_period; force updates must override
# it or a drain hangs for up to an hour.
#
# Usage (Dokploy Advanced → Command):
#   sh docker/dokploy-stack-deploy.sh <dokploy-compose-appName>
#
# The appName is the stack name Dokploy already uses (General tab). Wrong name
# creates a second stack beside the live one.

set -eu

STACK_NAME="${1:?usage: dokploy-stack-deploy.sh <dokploy-compose-appName>}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
RESOLVED="${RESOLVED:-/tmp/playndgain-stack-${STACK_NAME}.yml}"
# 72 * 5s = 6 minutes per service. App start_period is 120s plus migrate/caches.
UPDATE_WAIT_MAX="${UPDATE_WAIT_MAX:-72}"

log() { printf '[dokploy-stack-deploy] %s\n' "$*"; }
die() { printf '[dokploy-stack-deploy] FATAL: %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "docker not found on PATH"

[ -f "$COMPOSE_FILE" ] || die "compose file not found: ${COMPOSE_FILE} (cwd=$(pwd))"

update_state() {
    docker service inspect "$1" --format '{{if .UpdateStatus}}{{.UpdateStatus.State}}{{end}}' 2>/dev/null || true
}

wait_for_service_idle() {
    service="$1"
    max="${2:-$UPDATE_WAIT_MAX}"
    i=0

    while [ "$i" -lt "$max" ]; do
        state="$(update_state "$service")"
        case "$state" in
            updating|rollback_started)
                log "waiting for ${service} (${state} ${i}/${max})"
                sleep 5
                ;;
            *)
                return 0
                ;;
        esac
        i=$((i + 1))
    done

    # `docker stack deploy` copies compose `stop_grace_period` (3600s on
    # horizon). The old task then sits in Shutdown for an hour and this wait
    # never ends. Changing grace on the in-flight update is not enough —
    # Swarm keeps the draining task. Force-remove worker containers so the
    # update can finish. Never do that to app — it is the live web task.
    case "$service" in
        *_horizon|*_scheduler) ;;
        *)
            recover_service "$service"
            die "${service} still ${state:-unknown} after ${max} waits — not stacking a second update"
            ;;
    esac

    log "unsticking ${service}: remove draining tasks, 60s stop grace"
    ids="$(docker ps -aq --filter "name=${service}" 2>/dev/null || true)"
    if [ -n "$ids" ]; then
        # shellcheck disable=SC2086
        docker rm -f $ids >/dev/null 2>&1 || true
    fi
    docker service update \
        --stop-grace-period 60s \
        --update-order stop-first \
        --rollback-order stop-first \
        --detach=true \
        "$service" >/dev/null 2>&1 || true

    i=0
    while [ "$i" -lt 36 ]; do
        state="$(update_state "$service")"
        case "$state" in
            updating|rollback_started)
                log "waiting for ${service} after unstick (${state} ${i}/36)"
                sleep 5
                ;;
            *)
                return 0
                ;;
        esac
        i=$((i + 1))
    done

    recover_service "$service"
    die "${service} still ${state:-unknown} after ${max} waits — not stacking a second update"
}

recover_service() {
    service="$1"
    log "recovering ${service}: rollback, then replicas=1"
    # `--rollback` cannot be combined with --rollback-order; the forward update
    # already set stop-first. `|| true` so a service with no PreviousSpec still
    # gets scaled back to one task.
    docker service rollback --detach=true "$service" >/dev/null 2>&1 || true
    docker service scale "${service}=1" >/dev/null 2>&1 || true
}

# Poll detached updates. Swarm's start-first rollback of a 1-replica
# service can print "rolling back update: 0 out of 1 tasks" forever;
# waiting on the CLI for that state then never returns and Dokploy never
# finishes.
update_service() {
    service="${STACK_NAME}_$1"
    log "rolling update ${service}"

    wait_for_service_idle "$service"

    docker service update \
        --force \
        --stop-grace-period 60s \
        --rollback-order stop-first \
        --detach=true \
        "$service"

    sleep 2
    i=0
    while [ "$i" -lt "$UPDATE_WAIT_MAX" ]; do
        state="$(update_state "$service")"
        case "$state" in
            completed|'')
                log "${service} update ${state:-idle}"
                return 0
                ;;
            paused|rollback_paused)
                recover_service "$service"
                die "${service} update ${state} (healthcheck or start failed)"
                ;;
            rollback_completed)
                die "${service} rolled back — new task never became healthy"
                ;;
            updating|rollback_started)
                log "${service} ${state} (${i}/${UPDATE_WAIT_MAX})"
                ;;
            *)
                log "${service} update state=${state:-empty} (${i}/${UPDATE_WAIT_MAX})"
                ;;
        esac
        i=$((i + 1))
        sleep 5
    done

    recover_service "$service"
    die "${service} rolling update timed out (${UPDATE_WAIT_MAX} waits)"
}

log "building image (project=${STACK_NAME})"
docker compose -p "$STACK_NAME" -f "$COMPOSE_FILE" build

# One-time cutover from plain Compose. Match Compose's project label only —
# `name=${STACK_NAME}` also matches Swarm tasks, and force-removing those
# races the rolling update into a rollback deadlock.
log "force-removing previous Compose containers if present (one-time cutover)"
compose_ids="$(docker ps -aq --filter "label=com.docker.compose.project=${STACK_NAME}" 2>/dev/null || true)"
if [ -n "$compose_ids" ]; then
    # shellcheck disable=SC2086
    docker rm -f $compose_ids >/dev/null 2>&1 || true
fi
docker network rm "${STACK_NAME}_default" >/dev/null 2>&1 || true

log "resolving compose for stack deploy → ${RESOLVED}"
# Swarm rejects the top-level `name:` field Compose V2 emits.
docker compose -p "$STACK_NAME" -f "$COMPOSE_FILE" config | grep -v '^name:' > "$RESOLVED"

log "stack deploy ${STACK_NAME}"
docker stack deploy -c "$RESOLVED" --prune --with-registry-auth "$STACK_NAME"

# Same image tag (playndgain-app:latest) does not always trigger a rolling
# update on its own. Force each service in sequence so app is healthy on the
# new image before workers move. Wait until stack deploy's own updates are
# idle first — overlapping `service update` is what leaves rollback at 0/1.
wait_for_service_idle "${STACK_NAME}_app"
wait_for_service_idle "${STACK_NAME}_horizon" 6
wait_for_service_idle "${STACK_NAME}_scheduler" 6

update_service app
update_service horizon
update_service scheduler

log "done — app then horizon then scheduler rolled on stack ${STACK_NAME}"
