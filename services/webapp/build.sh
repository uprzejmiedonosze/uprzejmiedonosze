#!/bin/sh -x
# Project build for staging/prod/shadow images — run inside Docker build stages
# (WORKDIR /build). One subcommand per Dockerfile stage, so BuildKit caches each
# step separately and shares it between environments:
#
#   js      host-independent  Parcel JS bundles + Sentry debug-id injection
#   images  host-independent  Parcel images (+ wiki/zrodla media)
#   php     host-independent  PHP/Twig/SQL/JSON copy, lint, PHPUnit, composer --no-dev
#   host    HOST-DEPENDENT    config.env.php, CSS, sitemap, fail2ban page
#
# Only `host` reads APP_HOST/APP_HTTPS (ARG in the Dockerfile's `release` stage),
# so switching staging -> prod -> shadow re-runs just that cheap step.
set -e
cd /build

log() { printf '==> %s\n' "$*"; }

# ── JS ────────────────────────────────────────────────────────────────────────

build_js() {
    log "JS (parcel, one invocation per entry point)"
    for f in src/js/*.js; do
        node_modules/.bin/parcel build --no-cache --dist-dir export/public/js/ "$f"
    done

    # Sentry debug-id injection happens here, baked into the image, so the same
    # artifact that ships is the one whose sourcemaps get uploaded (see
    # scripts/sentry-release.sh, invoked from build-push.sh --sentry, which
    # extracts this exact export/public/js from the `js` stage afterwards).
    # No network access or auth token needed for `inject` — only the later
    # `sourcemaps upload` does.
    log "Sentry sourcemaps inject"
    node_modules/.bin/sentry-cli sourcemaps inject export/public/js
}

# ── Images ────────────────────────────────────────────────────────────────────
# Collect all /img/... references from Twig templates and merge into
# images-index.html (replicates Makefile's sponge step), then parcel build.

build_images() {
    log "Images (parcel)"
    { cat src/images-index.html
      grep 'src="/img[^"{]\+"' --only-matching --no-filename --recursive --color=never src/templates \
          | sed 's|src="/|<img src="./|' | sed 's|$| />|'
    } | sort | uniq > /tmp/images-index.html
    mv /tmp/images-index.html src/images-index.html

    node_modules/.bin/parcel build --no-cache --no-source-maps --dist-dir export/public/img src/images-index.html

    # Media wiki (src/inc/Wiki.php mediaFile) — kopiowane bez parcela: nazwy plików są ich identyfikatorami w treści.
    mkdir -p export/public/img/wiki
    cp -r src/img/wiki/. export/public/img/wiki/

    # Dokumenty źródłowe (/zrodla.html) – linkowane przez href, więc parcel ich nie zbiera.
    mkdir -p export/public/img/zrodla
    cp -r src/img/zrodla/. export/public/img/zrodla/
}

# ── PHP / static files / JSON configs / lint / tests ─────────────────────────

build_php() {
    # Tests (and lint) run as in `make test`: HOST=localhost. The real host is
    # written later by `host`; nothing in the PHP tree depends on it.
    log "config.env.php (localhost placeholder for the tests)"
    printf '<?php\ndefined("HOST") || define("HOST", "localhost");\ndefined("TWIG_HASH") || define("TWIG_HASH", "00000000");\ndefined("CSS_HASH") || define("CSS_HASH", "00000000");\ndefined("JS_HASH") || define("JS_HASH", "00000000");\ndefined("HTTPS") || define("HTTPS", "http");\n' \
        > export/config.env.php

    log "PHP + static files"
    cp -r src/inc/.       export/inc/
    cp -r src/templates/. export/templates/
    cp -r src/tools/.     export/tools/
    cp -r src/sql/.       export/sql/
    cp src/index.php src/favicon.ico src/robots.txt src/ads.txt export/public/
    cp src/manifest.json  export/public/
    mkdir -p export/public/.well-known
    cp src/.well-known/traffic-advice src/.well-known/assetlinks.json export/public/.well-known/
    cp src/api/rest/index.php export/public/api/rest/
    mkdir -p export/public/api/mcp
    cp src/api/mcp/index.php export/public/api/mcp/
    mkdir -p export/public/api/oauth
    cp src/api/oauth/index.php export/public/api/oauth/
    find src/api -maxdepth 1 -name '*.html' -exec cp {} export/public/api/ \;

    # JSON configs. Order matters: sm-parser first (parent resolution),
    # badges-validator second, then jq for everything else not yet written.
    log "JSON configs"
    node tools/sm-parser.js src/api/config export/public/api/config
    node tools/badges-validator.js src/api/config/badges.json export/public/api/config/badges.json
    for f in src/api/config/*.json; do
        name=$(basename "$f")
        [ ! -f "export/public/api/config/$name" ] && jq -c . "$f" > "export/public/api/config/$name" || true
    done

    log "police-stations.pjson"
    php tools/police-stations.php \
        src/api/config/police-stations.csv \
        export/public/api/config/police.json \
        > export/public/api/config/police-stations.pjson

    log "PHP syntax (php -l)"
    find src/inc src/tools src/api -name '*.php' | xargs -P4 -n1 php -l > /dev/null

    log "Twig lint"
    ./vendor/bin/twig-linter lint --no-interaction --quiet src/templates/*.twig

    # Memcached is needed by Cache.php (-> Memcache) and phpunit.
    log "Starting memcached + loading .env.dev"
    memcached -d -u root -m 64 -p 11211
    sleep 0.3
    while IFS= read -r line; do
        case "$line" in ''|'#'*) continue ;; *=*) export "$line" ;; esac
    done < services/.env.dev

    # PHP code uses ROOT . 'webapp/public/api/config/' etc. Mirror the production
    # directory structure so PHP scripts resolve paths correctly inside the builder.
    log "PHP runtime symlinks (/var/www/localhost/)"
    mkdir -p /var/www/localhost/db
    ln -s /build/export  /var/www/localhost/webapp
    ln -s /build/vendor  /var/www/localhost/vendor

    log "Tests (phpunit)"
    cp /tmp/test-db.sqlite /var/www/localhost/db/store.sqlite
    TEST_DB=/var/www/localhost/db/store.sqlite \
    APP_ROOT=/var/www/localhost/ APP_ENV=staging APP_HOST=localhost MEMCACHED_HOST=localhost \
        ./vendor/phpunit/phpunit/phpunit --display-deprecations tests

    # Linting and tests needed dev-deps above; production images should not have them.
    log "composer install --no-dev (strip dev deps)"
    composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader
}

# ── Host-dependent bits ───────────────────────────────────────────────────────

build_host() {
    APP_HOST="${APP_HOST:?APP_HOST is required}"
    APP_HTTPS="${APP_HTTPS:-https}"

    log "config.env.php ($APP_HOST)"
    CSS_HASH=$(find src/scss -name '*.scss' | sort | xargs cat | md5sum | cut -b1-8)
    JS_HASH=$(cat $(find src/js -name '*.js' | sort) $(find src/api/config -name '*.json' | sort) | md5sum | cut -b1-8)
    TWIG_HASH=$(cat src/templates/*.twig | md5sum | cut -b1-8)
    printf '<?php\ndefined("HOST") || define("HOST", "%s");\ndefined("TWIG_HASH") || define("TWIG_HASH", "%s");\ndefined("CSS_HASH") || define("CSS_HASH", "%s");\ndefined("JS_HASH") || define("JS_HASH", "%s");\ndefined("HTTPS") || define("HTTPS", "%s");\n' \
        "$APP_HOST" "$TWIG_HASH" "$CSS_HASH" "$JS_HASH" "$APP_HTTPS" \
        > export/config.env.php

    log "CSS (parcel)"
    HOST=$APP_HOST node src/scss/env.js > src/scss/lib/variables.env.scss
    node_modules/.bin/parcel build --no-cache --dist-dir export/public/css src/scss/index3.scss

    log "sitemap.xml"
    { printf '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"\n'
      printf '  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"\n'
      printf '  xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9'
      printf ' http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd">\n'
      for PAGE in src/templates/*.html.twig; do
          grep -q "SITEMAP-PRIORITY" "$PAGE" || continue
          PRIO=$(grep "SITEMAP-PRIORITY" "$PAGE" | sed 's/[^0-9\.]//g')
          URL=$(basename "$PAGE" .twig | sed 's/index\.html//')
          MOD_DATE=$(stat -c '%y' "$PAGE" | cut -d' ' -f1)
          printf '<url>\n  <loc>%s://%s/%s</loc>\n  <lastmod>%s</lastmod>\n  <priority>%s</priority>\n</url>\n' \
              "$APP_HTTPS" "$APP_HOST" "$URL" "$MOD_DATE" "$PRIO"
      done
      printf '</urlset>\n'
    } > export/public/sitemap.xml

    # fail2ban-twig.php (Cache.php -> Memcache) needs memcached and the env vars.
    log "Starting memcached + loading .env.dev"
    memcached -d -u root -m 64 -p 11211
    sleep 0.3
    while IFS= read -r line; do
        case "$line" in ''|'#'*) continue ;; *=*) export "$line" ;; esac
    done < services/.env.dev

    log "PHP runtime symlinks (/var/www/$APP_HOST/)"
    mkdir -p /var/www/$APP_HOST/db
    ln -s /build/export  /var/www/$APP_HOST/webapp
    ln -s /build/vendor  /var/www/$APP_HOST/vendor

    log "fail2ban/index.html"
    mkdir -p export/public/fail2ban
    APP_ROOT=/var/www/$APP_HOST/ APP_HOST=$APP_HOST APP_HTTPS=$APP_HTTPS APP_ENV=dev \
        php tools/fail2ban-twig.php > export/public/fail2ban/index.html
}

case "${1:-}" in
    js)     build_js ;;
    images) build_images ;;
    php)    build_php ;;
    host)   build_host ;;
    *) echo "usage: build.sh js|images|php|host" >&2; exit 1 ;;
esac
log "Build step '$1' complete."
