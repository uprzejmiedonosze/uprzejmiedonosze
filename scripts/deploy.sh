#!/usr/bin/env bash
# Runs ON THE HOST (/opt/uprzejmiedonosze). Pulls the already-built,
# already-pushed images (scripts/build-push.sh) and brings the stack up —
# zero compilation here, mirroring ../zenfeed.eu/scripts/dev-deploy.sh's
# pull mode.
#
# Usage: bash scripts/deploy.sh <env> <tag>
#   <env>  prod | staging | shadow
#   <tag>  the tag build-push.sh pushed as, e.g. prod_main_2026-09-27 or a git SHA
set -euo pipefail

ENV="${1:?usage: deploy.sh <env> <tag>}"
TAG="${2:?usage: deploy.sh <env> <tag>}"

case "$ENV" in
  prod|staging|shadow) ;;
  *) echo "env must be 'prod', 'staging' or 'shadow', got: $ENV" >&2; exit 1 ;;
esac

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd -- "${script_dir}/.." && pwd)
cd "${repo_root}"

# Re-exec from a fresh process right after updating the checkout (which
# may include this very file). Without this, bash keeps executing from the
# file descriptor it opened at startup — whatever this script's content
# was BEFORE the git reset, not after — confirmed empirically
# (2026-09-28): a deploy.sh change landed in this same commit as
# `git reset --hard` and silently ran with the OLD script logic for the
# rest of that invocation, because nothing forced a re-read. The guard env
# var both stops this from looping forever on the second pass AND skips
# redoing the fetch/reset then (already current from the first pass).
if [[ -z "${UD_DEPLOY_REEXECED:-}" ]]; then
  echo "==> Updating checkout"
  git fetch --prune origin
  git reset --hard "origin/main"
  UD_DEPLOY_REEXECED=1 exec bash "${BASH_SOURCE[0]}" "$@"
fi

# one deploy at a time (the untracked lock file survives the git reset above)
exec 9>".deploy.lock"
flock 9

export IMAGE_TAG="${ENV}-${TAG}"
ENV_FILE="services/.env.${ENV}"
[[ -f "${ENV_FILE}" ]] || { echo "ERROR: ${ENV_FILE} not found" >&2; exit 1; }

compose=(docker compose -f services/compose.yml --env-file "${ENV_FILE}" -p "${ENV}" --profile "${ENV}")

# Only the services that actually change with every app release — never
# memcached, on purpose:
#   - memcached holds PHP sessions (session.save_handler=memcached, see
#     Dockerfile). Its image tag is a floating `memcached:alpine`; pulling
#     it here would eventually catch an upstream rebuild and recreate the
#     container, wiping everyone's session. Update it deliberately instead:
#     docker compose pull memcached && up -d memcached
# It still starts automatically below via `up`'s dependency resolution if
# it isn't already running (self-healing) — just never recreated, since we
# never pull a new image for it here.
#
# `docker compose pull <service>` still follows depends_on and pulls
# memcached too, regardless of naming only the app services —
# confirmed empirically (2026-09-28, Compose v5.5.1): `--include-deps` is
# opt-in per the docs, but this version pulls dependencies whether or not
# it's passed. Sidestep compose's pull entirely and `docker pull` the two
# actual image references directly instead — this can't touch anything
# else. `up` below is left on compose (its default --pull=missing policy
# doesn't refetch what's already local, so it won't touch memcached
# either as long as we never pulled a new version of it).
REGISTRY="${REGISTRY:-ghcr.io/uprzejmiedonosze/uprzejmiedonosze}"

# staging is deliberately a bare app — no face-detect-consumer, no
# worker-cron (2026-09-28, explicit request). shadow is
# bare too (2026-10-06): it shares the PROD db/cdn2 via symlinks
# (/var/www/shadow.uprzejmiedonosze.net/{db,cdn2} → ../uprzejmiedonosze.net/,
# since 2020), so shadow's worker would just re-run prod's own cron jobs
# (cleanup, old-users-removal, s3-sync, db-backup) against the same data —
# duplicated work racing the real prod worker, zero benefit.
# Both envs still need memcached (sessions — session.save_handler=memcached,
# see Dockerfile), so that's named explicitly alongside webapp-srv.
# worker image is only pulled for envs that actually run something from it.
case "$ENV" in
  prod)           PULL_WORKER=1 ;;
  staging|shadow) PULL_WORKER=0 ;;
esac

echo "==> Pulling app images directly (IMAGE_TAG=${IMAGE_TAG}) — not via compose, so memcached is never touched"
docker pull "${REGISTRY}/webapp:${IMAGE_TAG}"
[[ "${PULL_WORKER}" == "1" ]] && docker pull "${REGISTRY}/worker:${IMAGE_TAG}"

# memcached first: never recreated unless its own config changed (see above); started if it is down.
"${compose[@]}" up -d --no-build --no-deps --wait --wait-timeout 60 memcached

# webapp-srv: rolling update, no downtime. Start the new container next to the old one, wait until its
# HEALTHCHECK passes (Dockerfile; Traefik only routes to healthy containers), then retire the old one
# (stop_signal SIGQUIT + stop_grace_period 30s = running requests finish). A plain `up -d` would stop
# the old container first and leave the site down until the new one is up. Needs webapp-srv without a
# published host port (compose.yml). An old container without a HEALTHCHECK (image from before this
# change) is treated by Traefik as healthy, so the first rolling deploy needs no special handling.
echo "==> Rolling update of webapp-srv (no build, no compile on this host)"
old=$("${compose[@]}" ps -q webapp-srv)
if [[ -z "${old}" ]]; then
  "${compose[@]}" up -d --no-build --no-deps --wait --wait-timeout 180 webapp-srv
else
  before=$("${compose[@]}" ps -q webapp-srv | sort)
  if ! "${compose[@]}" up -d --no-build --no-deps --no-recreate --scale webapp-srv=2 \
        --wait --wait-timeout 180 webapp-srv; then
    # the new container never became healthy: drop it, the old one keeps serving
    for c in $("${compose[@]}" ps -q webapp-srv | sort | grep -vxF "${before}" || true); do
      docker rm --force "$c" >/dev/null 2>&1 || true
    done
    echo "ERROR: new webapp-srv did not become healthy, kept the old one" >&2
    exit 1
  fi
  # let Traefik pick up the new backend before the old one goes away
  sleep 5
  for c in ${old}; do
    docker stop -t 30 "$c" >/dev/null
    docker rm "$c" >/dev/null
  done
  # one container left; its config matches, so this recreates nothing
  "${compose[@]}" up -d --no-build --no-deps --no-recreate --scale webapp-srv=1 \
    --wait --wait-timeout 60 webapp-srv
fi

# Workers share the worker image; a short restart of a cron/queue process is harmless, so a plain recreate.
if [[ "${PULL_WORKER}" == "1" ]]; then
  echo "==> Recreating workers"
  "${compose[@]}" up -d --no-build --no-deps --wait --wait-timeout 120 worker-cron face-detect-consumer
fi

# The running container must carry the commit its image was built from (/version.txt, Dockerfile).
want=$(docker image inspect "${REGISTRY}/webapp:${IMAGE_TAG}" --format '{{index .Config.Labels "org.opencontainers.image.revision"}}')
live=$("${compose[@]}" exec -T webapp-srv cat /var/www/uprzejmiedonosze.net/webapp/public/version.txt | tr -d '[:space:]')
if [[ -z "${want}" || "${live}" != "${want}" ]]; then
  echo "ERROR: webapp-srv reports '${live}', image ${IMAGE_TAG} was built from '${want}'" >&2
  exit 1
fi
echo "webapp-srv serves ${live}"

echo "==> Pruning old images (keep last 7 days, never touches running containers)"
docker image prune -af --filter until=168h

echo "Deployed ${ENV} at ${IMAGE_TAG}."
