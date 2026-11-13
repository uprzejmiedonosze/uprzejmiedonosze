# ── Shared PHP 8.4 base for builder/webapp/worker ────────────────────────────
# All three stages below FROM this one instead of three different official
# images (node:*, nginx:*, debian:*) as before. That mattered for two
# reasons, confirmed empirically while writing this:
#   - BuildKit can only share cached layers between stages that share an
#     ancestor. Three different upstream images meant the near-identical
#     `apt-get install php8.4-*` in each stage was computed three times,
#     even within the same `docker build`.
#   - `nginx:1.27.3-bookworm` alone was ~197MB vs ~97MB for
#     `debian:bookworm-slim` — almost entirely nginx dynamic modules we
#     don't use (image-filter pulls in a full image-codec library stack:
#     libgd3/libjpeg/libpng/libwebp/libtiff/libheif/libavif; plus geoip,
#     njs, xslt) and curl/ca-certificates/gettext-base for the official
#     image's docker-entrypoint.sh, which we don't use either (own
#     init.sh below). The actual nginx binary + modules are ~9.6MB.
# Which stage the runtime images take code from: `release` (full build, default)
# or `builder` (dev: empty export/ tree, code comes from the bind mount).
ARG EXPORT_STAGE=release

FROM debian:bookworm-slim AS php-base

# Packages every stage needs: the PHP extensions common to builder, webapp
# AND worker. Stage-specific extensions (php8.4-cli, -fpm, -opcache,
# -memcached) are installed per stage below, not here — see the Dockerfile
# history / AGENTS.md for exactly which stage needs which, confirmed
# against the pre-refactor per-stage package lists so this is a pure
# de-duplication, not a behavior change.
# Bump to invalidate the cached apt layer and pull the newest Sury PHP
# (the layer is otherwise reused from the registry/CI cache indefinitely).
ARG PHP_REFRESH=2026-10-22
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-sqlite3 php8.4-curl php8.4-gd php8.4-memcache \
        php8.4-mbstring php8.4-intl php8.4-exif php8.4-xml php8.4-zip && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Builder (dev image + base of the release build stages below) ─────────────
# Dev (`builder`, `builder-test` services) bind-mounts src/, tests/ and tools/ over
# /build, so nothing from them is baked in here — only the slow, rarely changing
# layers (Node, npm ci, composer install).
FROM php-base AS builder

WORKDIR /build

# Node.js (NodeSource — same upstream binaries the official node image ships,
# installed on the shared base instead of starting from a separate image)
# + the extensions/tools only the builder needs (cached until this layer's
# inputs change, same as before).
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get install -y --no-install-recommends \
        nodejs jq memcached php8.4-cli && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    rm -rf /var/lib/apt/lists/*

# npm (cached until package-lock.json changes)
# Cypress/ESLint live in tools/ (a separate npm project, see Makefile
# cypress-local) — this package.json only lists runtime + build-time deps,
# so npm ci here never touches them. tools/node_modules itself is excluded
# from the build context below (.dockerignore).
COPY .npmrc package.json package-lock.json ./
RUN npm ci

COPY composer.json composer.lock ./
COPY openalpr/ ./openalpr/
RUN composer install --no-interaction --no-progress --no-scripts

COPY .parcelrc               ./.parcelrc
COPY phpunit.xml             ./phpunit.xml
COPY services/devroot/db/store.sqlite /tmp/test-db.sqlite
# .env.dev is gitignored — must exist locally for tests to have secrets + crypto keys
COPY services/.env.dev        ./services/.env.dev
COPY services/webapp/build.sh ./build.sh

# Always create the full export/ tree so COPY --from=export succeeds in dev too
# (there these directories stay empty, overridden by volume mounts at runtime).
RUN mkdir -p \
    export/public/api/config export/public/api/rest export/public/img \
    export/public/css export/public/js \
    export/inc/integrations export/inc/middleware export/inc/handlers \
    export/inc/dataclasses export/inc/store export/inc/converters \
    export/templates export/tools export/sql

# ── Release build (staging/prod/shadow) ───────────────────────────────────────
# Split by what each step reads, so a change only re-runs what it affects and
# the host-independent steps are shared between staging, prod and shadow
# (see build.sh). BuildKit builds these stages in parallel.

# JS bundles — depend on src/js, the JSON it imports from src/api/config
# (parcel-namer too). Not rebuilt by PHP/SCSS edits.
FROM builder AS js
COPY tools/parcel-namer.js ./tools/parcel-namer.js
COPY src/api/config/ ./src/api/config/
COPY src/js/ ./src/js/
RUN sh build.sh js

# Images — depend on the <img> references in templates and on src/img.
FROM builder AS images
COPY tools/parcel-namer.js ./tools/parcel-namer.js
COPY src/images-index.html ./src/images-index.html
COPY src/img/              ./src/img/
COPY src/templates/        ./src/templates/
RUN sh build.sh images

# PHP tree + JSON configs + lint + PHPUnit + `composer install --no-dev`.
# Not rebuilt by JS/SCSS edits. Needs the built media (a wiki test checks that
# every image referenced from the seeds exists in export/public/img/wiki).
FROM builder AS php
COPY --from=images /build/export/public/img/ ./export/public/img/
COPY tools/       ./tools/
COPY tests/       ./tests/
COPY src/.well-known/ ./src/.well-known/
COPY src/api/     ./src/api/
COPY src/inc/     ./src/inc/
COPY src/sql/     ./src/sql/
COPY src/templates/ ./src/templates/
COPY src/tools/   ./src/tools/
COPY src/index.php src/favicon.ico src/robots.txt src/ads.txt src/manifest.json ./src/
RUN sh build.sh php

# Everything that depends on the target host. ARGs are declared only here: a
# changed ARG value invalidates the cache of every instruction after it, so an
# early ARG would re-run the steps above for each environment.
FROM php AS release
COPY --from=js     /build/export/public/js/  ./export/public/js/
COPY src/js/   ./src/js/
COPY src/img/  ./src/img/
COPY src/scss/ ./src/scss/
ARG APP_HOST=uprzejmiedonosze.net
ARG APP_HTTPS=https
RUN sh build.sh host

# What the runtime images below copy their code from (see EXPORT_STAGE).
FROM ${EXPORT_STAGE} AS export

# ── Webapp ────────────────────────────────────────────────────────────────────
FROM php-base AS webapp

ENV HOME=/root

# nginx from nginx.org (not Debian's bookworm package, which is 1.22.1 —
# stays on the same fast-moving mainline this image tracked before). apt reads
# the armored key directly (signed-by=….asc), so no gnupg is needed.
# Pin-Priority makes apt prefer this repo's `nginx` over Debian's own
# same-named package now that both are configured.
#
# TeX is only for pdflatex (src/inc/converters/App2Pdf.php). Explicit packages
# instead of the `texlive` metapackage, which adds texlive-fonts-recommended
# (unused: the template uses lmodern). texlive-latex-extra (qrcode, adjustbox,
# footmisc) hard-depends on python3 and libpdfbox-java — that is where python
# in this image comes from, not from our own packages.
# Deliberately absent: vim, locales (no locale is generated or used — ICU does
# the Polish collation), gnupg. curl/ca-certificates/php8.4-zip come from php-base.
RUN curl -sSLo /usr/share/keyrings/nginx.asc https://nginx.org/keys/nginx_signing.key && \
    echo "deb [signed-by=/usr/share/keyrings/nginx.asc] https://nginx.org/packages/debian/ bookworm nginx" \
        > /etc/apt/sources.list.d/nginx.list && \
    printf 'Package: *\nPin: origin nginx.org\nPin-Priority: 900\n' > /etc/apt/preferences.d/nginx && \
    DEBIAN_FRONTEND="noninteractive" apt-get update && \
    apt-get --no-install-recommends -y install \
        nginx sqlite3 \
        php8.4-fpm php8.4-opcache php8.4-memcached \
        texlive-latex-base texlive-latex-recommended texlive-latex-extra \
        texlive-lang-polish lmodern && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    mkdir -p /var/www/uprzejmiedonosze.net/db \
             /var/www/uprzejmiedonosze.net/cdn2 \
             /var/www/uprzejmiedonosze.net/video \
             /var/log/uprzejmiedonosze.net \
             /var/cache/uprzejmiedonosze.net && \
    chown -R www-data:www-data /var/www/uprzejmiedonosze.net && \
    chown www-data:www-data /var/log/uprzejmiedonosze.net /var/cache/uprzejmiedonosze.net

# Session fixture + nginx/php-fpm config. Before the code COPY below: these
# change rarely, the code changes on every build and invalidates what follows it.
COPY --chown=www-data:www-data services/webapp/sess_php \
        /var/lib/php/sessions/sess_48msfr815nd7f6ujomebqdpil9jueuq0
COPY services/webapp/nginx.conf                   /etc/nginx/
COPY services/webapp/nginx_php_configuration.conf /etc/nginx/
COPY services/webapp/www.conf                     /etc/php/8.4/fpm/pool.d/
COPY services/webapp/init.sh                      /root/

# The php-fpm.conf error_log below is the FPM *master's own* operational
# log (startup/shutdown, worker respawns) — with catch_workers_output=yes
# (www.conf) it also captures stray stdout/stderr writes from app code that
# don't go through Logger.php's syslog transport. It stays on stderr
# (real PHP errors are correctly ERROR there); app-level severity is instead
# carried by Logger.php writing syslog datagrams directly, bypassing this
# entirely (see AGENTS.md § Logging).
# log_level is raised from the default 'notice' to 'warning' to drop the
# routine "child N exited"/"child N started" respawn pairs from pm.max_requests
# (regular, expected churn — not a signal worth a Papertrail line), while
# keeping real fpm problems (e.g. "server reached pm.max_children") visible.
RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" \
        -e "s/^upload_max_filesize.*/upload_max_filesize = 4M/" \
        -e "s/^post_max_size.*/post_max_size = 5M/" \
        -e "s/^session.name.*/session.name = UDSESSIONID/" \
        -e "s/^session.save_handler.*/session.save_handler = memcached/" \
        /etc/php/8.4/fpm/php.ini && \
    printf 'session.save_path = "memcached:11211"\nsession.upload_progress.enabled = 0\n' >> /etc/php/8.4/fpm/php.ini && \
    # sess_lock_wait_min/max are in MILLISECONDS (php-memcached sleeps
    # wait*1000 us, doubling up to max). Defaults (150/150 ms, 5 retries)
    # give a losing request only ~750 ms before it gives up and proceeds
    # with an empty $_SESSION — a spurious "User not logged in" 403 (or a
    # redirect to /login.html, whose resetSession() then really wipes the
    # session) while a slow handler such as image upload + ALPR holds the
    # lock (Papertrail 2026-09-29).
    # The previous fix (2026-09-30) set 1000/100000/30 assuming microseconds:
    # that made every contended request sleep 1, 2, 4, 8, 16 s… so it got
    # its lock only at 1/3/7/15/31 s regardless of when it was actually
    # released — visible as spikes at exactly those durations in the access
    # log, and as 30-60 s parallel image uploads (2026-10-09).
    # Now: start at 10 ms, cap at 100 ms, 300 retries ≈ 30 s total budget —
    # a waiter resumes within ≤100 ms of the lock being released, and
    # gives up around when the lock itself expires anyway
    # (sess_lock_expire=0 → max_execution_time = 30 s).
    # Releasing the lock early via session_write_close() in SessionMiddleware
    # was tried and reverted (2026-09-30): something past preprocess() does
    # write to $_SESSION.
    printf 'memcached.sess_lock_wait_min = 10\nmemcached.sess_lock_wait_max = 100\nmemcached.sess_lock_retries = 300\n' >> /etc/php/8.4/fpm/php.ini && \
    sed -i -e "s|.*error_log.*|error_log = /dev/stderr|" \
        -e "s|^;log_level.*|log_level = warning|" \
        /etc/php/8.4/fpm/php-fpm.conf && \
    update-rc.d php8.4-fpm defaults

# Fixed inside every container regardless of profile — host path varies via volume mount
ENV APP_ROOT=/var/www/uprzejmiedonosze.net/
EXPOSE 80
CMD ["/bin/sh", "/root/init.sh"]
LABEL net.uprzejmiedonosze=0.2.3

# Code + deps (db/cdn/config come from volume mounts at runtime). Last on
# purpose — see the comment on the config COPYs above.
COPY --from=export --chown=www-data:www-data /build/export/ /var/www/uprzejmiedonosze.net/webapp/
COPY --from=export /build/vendor/              /var/www/uprzejmiedonosze.net/vendor/

# ── Worker (PHP CLI — face-detect-consumer daemon + cron jobs) ────────────────
FROM php-base AS worker

ARG TARGETARCH=amd64

RUN apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-memcached age && \
    rm -rf /var/lib/apt/lists/*

RUN curl -sSLo /usr/local/bin/supercronic \
    "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-${TARGETARCH}" && \
    chmod +x /usr/local/bin/supercronic

COPY --from=export /build/export/inc/               /var/www/uprzejmiedonosze.net/webapp/inc/
COPY --from=export /build/export/tools/             /var/www/uprzejmiedonosze.net/webapp/tools/
COPY --from=export /build/export/public/api/config/ /var/www/uprzejmiedonosze.net/webapp/public/api/config/
COPY --from=export /build/vendor/                   /var/www/uprzejmiedonosze.net/vendor/
COPY services/webapp/crontab                         /etc/crontab

ENV APP_ROOT=/var/www/uprzejmiedonosze.net/
USER www-data
