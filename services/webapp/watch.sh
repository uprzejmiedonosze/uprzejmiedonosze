#!/bin/sh
set -e
cd /build

APP_HOST="${APP_HOST:-localhost}"
APP_HTTPS="${APP_HTTPS:-http}"

log() { printf '==> %s\n' "$*"; }

mkdir -p \
    export/public/api/config export/public/api/rest export/public/img \
    export/public/css export/public/js \
    export/inc/integrations export/inc/middleware export/inc/handlers \
    export/inc/dataclasses export/inc/store export/inc/converters \
    export/templates export/tools export/sql

build_config_env() {
    CSS_HASH=$(find src/scss -name '*.scss' | sort | xargs cat | md5sum | cut -b1-8)
    JS_HASH=$(cat $(find src/js -name '*.js' | sort) $(find src/api/config -name '*.json' | sort) | md5sum | cut -b1-8)
    TWIG_HASH=$(cat src/templates/*.twig | md5sum | cut -b1-8)
    printf '<?php\ndefine("HOST", "%s");\ndefine("TWIG_HASH", "%s");\ndefine("CSS_HASH", "%s");\ndefine("JS_HASH", "%s");\ndefine("HTTPS", "%s");\n' \
        "$APP_HOST" "$TWIG_HASH" "$CSS_HASH" "$JS_HASH" "$APP_HTTPS" \
        > export/config.env.php
    [ -f config.dev.php ] && cp config.dev.php export/config.dev.php
}

build_static() {
    cp -r src/inc/.       export/inc/
    cp -r src/templates/. export/templates/
    cp -r src/tools/.     export/tools/
    cp -r src/sql/.       export/sql/
    cp src/index.php src/favicon.ico src/robots.txt src/ads.txt export/public/
    cp src/manifest.json  export/public/
    cp src/api/rest/index.php export/public/api/rest/
    find src/api -maxdepth 1 -name '*.html' -exec cp {} export/public/api/ \;
    cp src/images-index.html export/
    [ -d src/img ] && cp -r src/img/. export/public/img/ || true
}

build_json() {
    node tools/sm-parser.js src/api/config export/public/api/config
    node tools/badges-validator.js src/api/config/badges.json export/public/api/config/badges.json
    for f in src/api/config/*.json; do
        name=$(basename "$f")
        [ ! -f "export/public/api/config/$name" ] && jq -c . "$f" > "export/public/api/config/$name" || true
    done
    php tools/police-stations.php \
        src/api/config/police-stations.csv \
        export/public/api/config/police.json \
        > export/public/api/config/police-stations.pjson
}

# ── Initial build ─────────────────────────────────────────────────────────────
log "Starting initial build..."

build_config_env
build_static
build_json

node_modules/.bin/parcel build --dist-dir export/public/css src/scss/index.scss
for f in src/js/*.js; do
    node_modules/.bin/parcel build --dist-dir export/public/js/ "$f"
done

log "Initial build done. Watching for changes..."

# ── Parcel watch: CSS + JS (inkrementalny, w tle) ────────────────────────────
node_modules/.bin/parcel watch --no-hmr --dist-dir export/public/css src/scss/index.scss &
for f in src/js/*.js; do
    node_modules/.bin/parcel watch --no-hmr --dist-dir export/public/js/ "$f" &
done

# ── Poll loop: PHP / Twig / SQL / JSON ───────────────────────────────────────
# inotify events from the macOS host don't propagate reliably through Docker
# bind mounts; find -newer gives reliable 1-second polling instead.
touch /tmp/watch-marker
while true; do
    sleep 1
    find src/inc src/templates src/tools src/sql src/api \
        -newer /tmp/watch-marker -type f 2>/dev/null > /tmp/watch-changed
    touch /tmp/watch-marker
    if [ -s /tmp/watch-changed ]; then
        log "Changed: $(head -1 /tmp/watch-changed)"
        grep -q  'src/api/config/' /tmp/watch-changed && build_json
        grep -qv 'src/api/config/' /tmp/watch-changed && build_static
    fi
done
