#!/bin/sh -x
# Full project build — run inside Docker builder stage (WORKDIR /build).
# APP_HOST and APP_HTTPS are passed as environment variables from Docker build args.
set -e
cd /build

APP_HOST="${APP_HOST:-localhost}"
APP_HTTPS="${APP_HTTPS:-http}"

log() { printf '==> %s\n' "$*"; }

# ── Output directories ────────────────────────────────────────────────────────

mkdir -p \
    export/public/api/config export/public/api/rest export/public/img \
    export/public/css export/public/js \
    export/inc/integrations export/inc/middleware export/inc/handlers \
    export/inc/dataclasses export/inc/store export/inc/converters \
    export/templates export/tools export/sql

# ── config.env.php ────────────────────────────────────────────────────────────

log "config.env.php"
CSS_HASH=$(find src/scss -name '*.scss' | sort | xargs cat | md5sum | cut -b1-8)
JS_HASH=$(cat $(find src/js -name '*.js' | sort) $(find src/api/config -name '*.json' | sort) | md5sum | cut -b1-8)
TWIG_HASH=$(cat src/templates/*.twig | md5sum | cut -b1-8)
printf '<?php\ndefine("HOST", "%s");\ndefine("TWIG_HASH", "%s");\ndefine("CSS_HASH", "%s");\ndefine("JS_HASH", "%s");\ndefine("HTTPS", "%s");\n' \
    "$APP_HOST" "$TWIG_HASH" "$CSS_HASH" "$JS_HASH" "$APP_HTTPS" \
    > export/config.env.php

# ── PHP / static files ────────────────────────────────────────────────────────

log "PHP + static files"
cp -r src/inc/.       export/inc/
cp -r src/templates/. export/templates/
cp -r src/tools/.     export/tools/
cp -r src/sql/.       export/sql/
cp src/index.php src/favicon.ico src/robots.txt src/ads.txt export/public/
cp src/manifest.json  export/public/
cp src/api/rest/index.php export/public/api/rest/
find src/api -maxdepth 1 -name '*.html' -exec cp {} export/public/api/ \;

# ── JSON configs ──────────────────────────────────────────────────────────────
# Order matters: sm-parser first (parent resolution), badges-validator second,
# then jq for everything else not yet written.

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

# ── CSS ───────────────────────────────────────────────────────────────────────

log "CSS (parcel)"
HOST=$APP_HOST node src/scss/env.js > src/scss/lib/variables.env.scss
node_modules/.bin/parcel build --no-cache --dist-dir export/public/css src/scss/index.scss

# ── JS ────────────────────────────────────────────────────────────────────────

log "JS (parcel, one invocation per entry point)"
for f in src/js/*.js; do
    node_modules/.bin/parcel build --no-cache --dist-dir export/public/js/ "$f"
done

# ── Images ────────────────────────────────────────────────────────────────────
# Collect all /img/... references from Twig templates and merge into
# images-index.html (replicates Makefile's sponge step), then parcel build.

log "Images (parcel)"
{ cat src/images-index.html
  grep 'src="/img[^"{]\+"' --only-matching --no-filename --recursive --color=never src/templates \
      | sed 's|src="/|<img src="./|' | sed 's|$| />|'
} | sort | uniq > /tmp/images-index.html
mv /tmp/images-index.html src/images-index.html

node_modules/.bin/parcel build --no-cache --no-source-maps --dist-dir export/public/img src/images-index.html

# ── Sitemap ───────────────────────────────────────────────────────────────────

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

# ── Lint ──────────────────────────────────────────────────────────────────────

log "PHP syntax (php -l)"
find src/inc src/tools src/api -name '*.php' | xargs -P4 -n1 php -l > /dev/null

log "Twig lint"
./vendor/bin/twig-linter lint --no-interaction --quiet src/templates/*.twig

# ── Test/PHP environment setup ────────────────────────────────────────────────
# Needed by fail2ban-twig.php (Cache.php → Memcache) and phpunit.

log "Starting memcached + loading .env.dev"
memcached -d -u root -m 64 -p 11211
sleep 0.3
while IFS= read -r line; do
    case "$line" in ''|'#'*) continue ;; *=*) export "$line" ;; esac
done < services/.env.dev

# ── PHP runtime layout ────────────────────────────────────────────────────────
# PHP code uses ROOT . 'webapp/public/api/config/' etc. Mirror the production
# directory structure so PHP scripts resolve paths correctly inside the builder.

log "PHP runtime symlinks (/var/www/$APP_HOST/)"
mkdir -p /var/www/$APP_HOST/db
ln -s /build/export  /var/www/$APP_HOST/webapp
ln -s /build/vendor  /var/www/$APP_HOST/vendor

# ── fail2ban page ─────────────────────────────────────────────────────────────

log "fail2ban/index.html"
mkdir -p export/public/fail2ban
APP_ROOT=/var/www/$APP_HOST/ APP_HOST=$APP_HOST APP_HTTPS=$APP_HTTPS APP_ENV=dev \
    php tools/fail2ban-twig.php > export/public/fail2ban/index.html

# ── Tests ─────────────────────────────────────────────────────────────────────

log "Tests (phpunit)"
cp /tmp/test-db.sqlite /var/www/$APP_HOST/db/store.sqlite
TEST_DB=/var/www/$APP_HOST/db/store.sqlite \
APP_ROOT=/var/www/$APP_HOST/ APP_ENV=staging APP_HOST=$APP_HOST MEMCACHED_HOST=localhost \
    ./vendor/phpunit/phpunit/phpunit --display-deprecations tests

# ── Strip dev dependencies from vendor ────────────────────────────────────────
# Linting and tests needed dev-deps above; production images should not have them.

log "composer install --no-dev (strip dev deps)"
composer install --no-dev --no-interaction --no-progress --no-scripts --optimize-autoloader

log "Build complete."
