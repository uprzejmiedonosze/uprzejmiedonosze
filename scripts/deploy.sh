#!/usr/bin/env bash
# Runs ON THE HOST (/opt/uprzejmiedonosze). Pulls the already-built,
# already-pushed images (scripts/build-push.sh) and brings the stack up —
# zero compilation here, mirroring ../zenfeed.eu/scripts/dev-deploy.sh's
# pull mode.
#
# Usage: bash scripts/deploy.sh <env> <tag>
#   <env>  prod | staging
#   <tag>  the tag build-push.sh pushed as, e.g. prod_main_2026-09-27 or a git SHA
set -euo pipefail

ENV="${1:?usage: deploy.sh <env> <tag>}"
TAG="${2:?usage: deploy.sh <env> <tag>}"

case "$ENV" in
  prod|staging) ;;
  *) echo "env must be 'prod' or 'staging', got: $ENV" >&2; exit 1 ;;
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

export IMAGE_TAG="${ENV}-${TAG}"
ENV_FILE="services/.env.${ENV}"
[[ -f "${ENV_FILE}" ]] || { echo "ERROR: ${ENV_FILE} not found" >&2; exit 1; }

compose=(docker compose -f services/compose.yml --env-file "${ENV_FILE}" -p "${ENV}" --profile "${ENV}")

# Only the services that actually change with every app release — never
# memcached or face-detector, on purpose:
#   - memcached holds PHP sessions (session.save_handler=memcached, see
#     Dockerfile). Its image tag is a floating `memcached:alpine`; pulling
#     it here would eventually catch an upstream rebuild and recreate the
#     container, wiping everyone's session. Update it deliberately instead:
#     docker compose pull memcached && up -d memcached
#   - face-detector is slow/expensive to build (dlib/cmake — confirmed
#     2026-09-27: didn't fit in memory under buildx/QEMU, took ~9.5min
#     natively) and versioned independently via FACE_DETECTOR_TAG, not
#     IMAGE_TAG. build-push.sh already treats it as opt-in
#     (--with-face-detector). Update it deliberately: bump FACE_DETECTOR_TAG
#     in .env.<env>, then docker compose pull face-detector && up -d
#     face-detector face-detect-consumer
# Both still start automatically below via `up`'s dependency resolution if
# they aren't already running (self-healing) — just never recreated, since
# we never pull a new image for them here.
#
# `docker compose pull <service>` still follows depends_on and pulls
# memcached/face-detector too, regardless of naming only the app services —
# confirmed empirically (2026-09-28, Compose v5.5.1): `--include-deps` is
# opt-in per the docs, but this version pulls dependencies whether or not
# it's passed. Sidestep compose's pull entirely and `docker pull` the two
# actual image references directly instead — this can't touch anything
# else. `up` below is left on compose (its default --pull=missing policy
# doesn't refetch what's already local, so it won't touch memcached/
# face-detector either as long as we never pulled new versions of them).
REGISTRY="${REGISTRY:-ghcr.io/uprzejmiedonosze/uprzejmiedonosze}"

# staging is deliberately a bare app — no face-detect-consumer, no
# face-detector, no worker-cron (2026-09-28, explicit request). It still
# needs memcached (sessions — session.save_handler=memcached, see
# Dockerfile), so that's named explicitly alongside webapp-srv; --no-deps
# stops `up` from also pulling in face-detector as webapp-srv's declared
# (but functionally unused by webapp-srv itself) dependency. worker image
# is only pulled for envs that actually run something from it.
case "$ENV" in
  prod)
    APP_SERVICES=(webapp-srv worker-cron face-detect-consumer)
    UP_FLAGS=()
    PULL_WORKER=1
    ;;
  staging)
    APP_SERVICES=(webapp-srv memcached)
    UP_FLAGS=(--no-deps)
    PULL_WORKER=0
    ;;
esac

echo "==> Pulling app images directly (IMAGE_TAG=${IMAGE_TAG}) — not via compose, so memcached/face-detector are never touched"
docker pull "${REGISTRY}/webapp:${IMAGE_TAG}"
[[ "${PULL_WORKER}" == "1" ]] && docker pull "${REGISTRY}/worker:${IMAGE_TAG}"

echo "==> Recreating the app services (no build, no compile on this host)"
"${compose[@]}" up -d --no-build --wait --wait-timeout 180 "${UP_FLAGS[@]}" "${APP_SERVICES[@]}"

echo "==> Pruning old images (keep last 7 days, never touches running containers)"
docker image prune -af --filter until=168h

echo "Deployed ${ENV} at ${IMAGE_TAG}."
