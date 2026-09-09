#!/bin/sh
#
# PLAYNDGAIN — Dokploy Stack rolling deploy (docs/13-dokploy.md).
#
# Docker Stack ignores `build:` and plain Compose ignores `deploy.update_config`.
# This script bridges both: build the image with Compose, resolve the file, then
# `docker stack deploy` so Swarm rolls replicas with start-first.
#
# Services update in order: app (both replicas, one at a time via Swarm) →
# horizon → scheduler. Autodeploy on push still works: set this script as the
# Dokploy Compose Advanced → Command and enable AutoDeploy.
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

log() { printf '[dokploy-stack-deploy] %s\n' "$*"; }
die() { printf '[dokploy-stack-deploy] FATAL: %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "docker not found on PATH"

[ -f "$COMPOSE_FILE" ] || die "compose file not found: ${COMPOSE_FILE} (cwd=$(pwd))"

log "building image (project=${STACK_NAME})"
docker compose -p "$STACK_NAME" -f "$COMPOSE_FILE" build

# One-time cutover: force-remove leftover Compose containers/networks. Do NOT
# use `compose down` without a short -t — horizon's stop_grace_period is 3600s
# and would hang the deploy for an hour.
log "force-removing previous Compose containers if present (one-time cutover)"
ids="$(docker ps -aq --filter "name=${STACK_NAME}" 2>/dev/null || true)"
if [ -n "$ids" ]; then
    # shellcheck disable=SC2086
    docker rm -f $ids >/dev/null 2>&1 || true
fi
docker network rm "${STACK_NAME}_default" >/dev/null 2>&1 || true

log "resolving compose for stack deploy → ${RESOLVED}"
# Swarm rejects the top-level `name:` field Compose V2 emits.
docker compose -p "$STACK_NAME" -f "$COMPOSE_FILE" config | grep -v '^name:' > "$RESOLVED"

log "stack deploy ${STACK_NAME}"
docker stack deploy -c "$RESOLVED" --prune --with-registry-auth "$STACK_NAME"

# Same image tag (playndgain-app:latest) does not always trigger a rolling
# update on its own. Force each service in sequence so app is healthy on the
# new image before workers move.
#
# --detach=false waits for Swarm to finish the rolling update (start-first).
update_service() {
    service="${STACK_NAME}_$1"
    log "rolling update ${service}"
    docker service update --force --detach=false "$service"
}

update_service app
update_service horizon
update_service scheduler

log "done — app then horizon then scheduler rolled on stack ${STACK_NAME}"
