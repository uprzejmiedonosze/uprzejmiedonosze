#!/usr/bin/env bash
# Creates the Sentry release + uploads JS sourcemaps for a prod build pushed
# via build-push.sh. Called with --sentry from that script — not meant to be
# run standalone except for a manual re-upload.
#
# The sourcemaps themselves were already debug-id-injected *inside* the
# image (services/webapp/build.sh, at build time) so the uploaded maps match
# exactly what's running. This script only extracts that already-injected
# export/public/js from a local (--load, not --push) build of the `builder`
# target — same Dockerfile/args as the pushed webapp image, so buildx's
# layer cache makes this near-instant — and talks to the Sentry API.
#
# Usage: bash scripts/sentry-release.sh <tag> <app_host> <app_https> <platform>
set -euo pipefail

TAG="${1:?usage: sentry-release.sh <tag> <app_host> <app_https> <platform>}"
APP_HOST="${2:?}"
APP_HTTPS="${3:?}"
PLATFORM="${4:?}"

SENTRY_ORG="uprzejmie-donosze"
SENTRY_PROJECT_JS="ud-js"
SENTRY_PROJECT_PHP="ud-php"
RELEASE="prod_${TAG}"

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repo_root=$(cd -- "${script_dir}/.." && pwd)
cd "${repo_root}"

BUILDER_IMAGE="ud-builder-sentry-tmp"
BUILDER_CTR="ud-builder-sentry-tmp-ctr"
EXTRACT_DIR=$(mktemp -d)
trap 'docker rm -f "${BUILDER_CTR}" >/dev/null 2>&1 || true; docker rmi "${BUILDER_IMAGE}" >/dev/null 2>&1 || true; rm -rf "${EXTRACT_DIR}"' EXIT

echo "==> Extracting sourcemap-injected export/public/js from the builder stage"
docker buildx build --platform "${PLATFORM}" --load \
  -f services/webapp/Dockerfile --target builder \
  --build-arg "APP_HOST=${APP_HOST}" --build-arg "APP_HTTPS=${APP_HTTPS}" \
  -t "${BUILDER_IMAGE}" .
docker create --name "${BUILDER_CTR}" "${BUILDER_IMAGE}" >/dev/null
docker cp "${BUILDER_CTR}:/build/export/public/js" "${EXTRACT_DIR}/js"

echo "==> Sentry release ${RELEASE}"
SENTRY_ORG="${SENTRY_ORG}" SENTRY_PROJECT="${SENTRY_PROJECT_JS}" \
  ./node_modules/.bin/sentry-cli releases new "${RELEASE}" --finalize
SENTRY_ORG="${SENTRY_ORG}" SENTRY_PROJECT="${SENTRY_PROJECT_PHP}" \
  ./node_modules/.bin/sentry-cli releases new "${RELEASE}" --finalize
SENTRY_ORG="${SENTRY_ORG}" SENTRY_PROJECT="${SENTRY_PROJECT_JS}" \
  ./node_modules/.bin/sentry-cli sourcemaps upload --org "${SENTRY_ORG}" \
  --project "${SENTRY_PROJECT_JS}" --release "${RELEASE}" "${EXTRACT_DIR}/js"

echo "Sentry release ${RELEASE} created and sourcemaps uploaded."
