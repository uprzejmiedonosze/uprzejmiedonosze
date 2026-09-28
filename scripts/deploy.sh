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

echo "==> Updating checkout"
git fetch --prune origin
git reset --hard "origin/main"

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
APP_SERVICES=(webapp-srv worker-cron face-detect-consumer)

echo "==> Pulling images (IMAGE_TAG=${IMAGE_TAG}) — app services only, not memcached/face-detector"
"${compose[@]}" pull "${APP_SERVICES[@]}"

echo "==> Recreating the app services (no build, no compile on this host)"
"${compose[@]}" up -d --no-build --wait --wait-timeout 180 "${APP_SERVICES[@]}"

echo "==> Pruning old images (keep last 7 days, never touches running containers)"
docker image prune -af --filter until=168h

echo "Deployed ${ENV} at ${IMAGE_TAG}."
