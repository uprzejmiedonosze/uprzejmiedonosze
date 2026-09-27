#!/usr/bin/env bash
# Builds the webapp + worker images for one environment (linux/amd64) and
# pushes them to GHCR, mirroring ../zenfeed.eu/scripts/build-push.sh: images
# are built locally (Mac, arm64) and the target host only ever pulls, never
# compiles. face-detector is a separate, env-independent image, built only
# on request (its dlib/cmake build is slow under QEMU on arm64).
#
# Usage: bash scripts/build-push.sh <env> <tag> [--with-face-detector] [--sentry]
#   <env>  prod | staging  — selects APP_HOST baked into the image
#          (config.env.php, sitemap, SCSS — see services/webapp/build.sh)
#   <tag>  image tag. Convention: prod uses the same "prod_<branch>_<date>"
#          string as `make sentry-release`'s git tag; staging uses the git SHA.
#
#   --with-face-detector  also build+push services/face-detector (rare: only
#                          needed after changing that directory)
#   --sentry               after pushing, extract the builder stage's
#                          export/public/js (already sourcemap-injected, see
#                          services/webapp/build.sh) and run the Sentry
#                          release: upload sourcemaps + create+finalize the
#                          release. Needs SENTRY_AUTH_TOKEN in the environment
#                          or services/.env.dev.
#
# Prerequisites (once): docker login ghcr.io (token with write:packages).
set -euo pipefail

ENV="${1:?usage: build-push.sh <env> <tag> [--with-face-detector] [--sentry]}"
TAG="${2:?usage: build-push.sh <env> <tag> [--with-face-detector] [--sentry]}"
shift 2

WITH_FACE_DETECTOR=0
WITH_SENTRY=0
for arg in "$@"; do
  case "$arg" in
    --with-face-detector) WITH_FACE_DETECTOR=1 ;;
    --sentry) WITH_SENTRY=1 ;;
    *) echo "Unknown flag: $arg" >&2; exit 1 ;;
  esac
done

case "$ENV" in
  prod)    APP_HOST="uprzejmiedonosze.net" ;;
  staging) APP_HOST="staging.uprzejmiedonosze.net" ;;
  *) echo "env must be 'prod' or 'staging', got: $ENV" >&2; exit 1 ;;
esac
APP_HTTPS="https"

REGISTRY="${REGISTRY:-ghcr.io/uprzejmiedonosze/uprzejmiedonosze}"
PLATFORM="${PLATFORM:-linux/amd64}"

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd -- "${script_dir}/.." && pwd)
cd "${repo_root}"

if [[ -n "$(git status --porcelain)" ]]; then
  echo "WARNING: working tree is dirty — the pushed image will contain" >&2
  echo "uncommitted changes that the host checkout (git reset --hard) won't have." >&2
fi

echo "==> Building ${REGISTRY}/{webapp,worker}:${ENV}-${TAG} (APP_HOST=${APP_HOST}, ${PLATFORM})"

docker buildx build --platform "${PLATFORM}" \
  -f services/webapp/Dockerfile --target webapp \
  --build-arg "APP_HOST=${APP_HOST}" --build-arg "APP_HTTPS=${APP_HTTPS}" \
  -t "${REGISTRY}/webapp:${ENV}-${TAG}" --push .

docker buildx build --platform "${PLATFORM}" \
  -f services/webapp/Dockerfile --target worker \
  --build-arg "APP_HOST=${APP_HOST}" --build-arg "APP_HTTPS=${APP_HTTPS}" \
  -t "${REGISTRY}/worker:${ENV}-${TAG}" --push .

echo "Pushed ${REGISTRY}/{webapp,worker}:${ENV}-${TAG}"

if [[ "${WITH_FACE_DETECTOR}" == "1" ]]; then
  echo "==> Building ${REGISTRY}/face-detector:${TAG} (${PLATFORM})"
  docker buildx build --platform "${PLATFORM}" \
    -f services/face-detector/Dockerfile \
    -t "${REGISTRY}/face-detector:${TAG}" --push services/face-detector
  echo "Pushed ${REGISTRY}/face-detector:${TAG}"
fi

if [[ "${WITH_SENTRY}" == "1" ]]; then
  if [[ "${ENV}" != "prod" ]]; then
    echo "ERROR: --sentry only makes sense for env=prod" >&2
    exit 1
  fi
  bash "${script_dir}/sentry-release.sh" "${TAG}" "${APP_HOST}" "${APP_HTTPS}" "${PLATFORM}"
fi
