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
FROM debian:bookworm-slim AS php-base

# Packages every stage needs: the PHP extensions common to builder, webapp
# AND worker. Stage-specific extensions (php8.4-cli, -fpm, -opcache,
# -memcached) are installed per stage below, not here — see the Dockerfile
# history / AGENTS.md for exactly which stage needs which, confirmed
# against the pre-refactor per-stage package lists so this is a pure
# de-duplication, not a behavior change.
RUN apt-get update && apt-get install -y --no-install-recommends \
        curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-sqlite3 php8.4-curl php8.4-gd php8.4-memcache \
        php8.4-mbstring php8.4-intl php8.4-exif php8.4-xml php8.4-zip && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# ── Builder (shared: vendor + full export) ───────────────────────────────────
FROM php-base AS builder

ARG APP_HOST=localhost
ARG APP_HTTPS=http

WORKDIR /build

# Node.js (NodeSource — same upstream binaries the official node image ships,
# installed on the shared base instead of starting from a separate image)
# + the extensions/tools only the builder needs (cached until this layer's
# inputs change, same as before).
RUN curl -fsSL https://deb.nodesource.com/setup_22.x | bash - && \
    apt-get install -y --no-install-recommends \
        nodejs jq inotify-tools memcached php8.4-cli && \
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

# Source + build script (changes frequently — after slow cached steps)
COPY .parcelrc               ./.parcelrc
COPY phpunit.xml             ./phpunit.xml
COPY tests/                  ./tests/
COPY src/                    ./src/
COPY tools/                  ./tools/
COPY services/webapp/build.sh ./build.sh
COPY services/devroot/db/store.sqlite /tmp/test-db.sqlite
# .env.dev is gitignored — must exist locally for tests to have secrets + crypto keys
COPY services/.env.dev        ./services/.env.dev

# Always create the full export/ tree so COPY --from=builder succeeds in all profiles.
# For dev, these directories stay empty (overridden by volume mounts at runtime).
# For staging/prod, build.sh fills them.
RUN mkdir -p \
    export/public/api/config export/public/api/rest export/public/img \
    export/public/css export/public/js \
    export/inc/integrations export/inc/middleware export/inc/handlers \
    export/inc/dataclasses export/inc/store export/inc/converters \
    export/templates export/tools export/sql

RUN [ "${APP_HOST}" = "localhost" ] || sh build.sh

# ── Webapp ────────────────────────────────────────────────────────────────────
FROM php-base AS webapp

LABEL net.uprzejmiedonosze=0.2.3
ENV HOME=/root

# nginx from nginx.org (not Debian's bookworm package, which is 1.22.1 —
# stays on the same fast-moving mainline this image tracked before). gnupg
# is only needed to import this one key; dropped again below along with the
# rest of the apt cache. Pin-Priority makes apt prefer this repo's `nginx`
# over Debian's own same-named package now that both are configured.
RUN apt-get update && apt-get install -y --no-install-recommends gnupg && \
    curl -sSL https://nginx.org/keys/nginx_signing.key | gpg --dearmor -o /usr/share/keyrings/nginx.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/nginx.gpg] https://nginx.org/packages/debian/ bookworm nginx" \
        > /etc/apt/sources.list.d/nginx.list && \
    printf 'Package: *\nPin: origin nginx.org\nPin-Priority: 900\n' > /etc/apt/preferences.d/nginx

RUN DEBIAN_FRONTEND="noninteractive" apt-get update && \
    apt-get --no-install-recommends -y install \
        nginx \
        locales vim curl sqlite3 \
        texlive ca-certificates \
        php8.4-fpm php8.4-zip php8.4-opcache php8.4-memcached \
        lmodern texlive-lang-polish \
        texlive-latex-recommended texlive-latex-extra && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    mkdir -p /var/www/uprzejmiedonosze.net/db \
             /var/www/uprzejmiedonosze.net/cdn2 \
             /var/www/uprzejmiedonosze.net/video \
             /var/log/uprzejmiedonosze.net \
             /var/cache/uprzejmiedonosze.net && \
    chown -R www-data:www-data /var/www/uprzejmiedonosze.net && \
    chown www-data:www-data /var/log/uprzejmiedonosze.net /var/cache/uprzejmiedonosze.net

# Code + deps from builder (db/cdn/config come from volume mounts at runtime)
COPY --from=builder --chown=www-data:www-data /build/export/ /var/www/uprzejmiedonosze.net/webapp/
COPY --from=builder /build/vendor/              /var/www/uprzejmiedonosze.net/vendor/

# Session fixture + nginx/php-fpm config
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
RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" \
        -e "s/^upload_max_filesize.*/upload_max_filesize = 4M/" \
        -e "s/^post_max_size.*/post_max_size = 5M/" \
        -e "s/^session.name.*/session.name = UDSESSIONID/" \
        -e "s/^session.save_handler.*/session.save_handler = memcached/" \
        /etc/php/8.4/fpm/php.ini && \
    printf 'session.save_path = "memcached:11211"\n' >> /etc/php/8.4/fpm/php.ini && \
    sed -i -e "s|.*error_log.*|error_log = /dev/stderr|" /etc/php/8.4/fpm/php-fpm.conf && \
    update-rc.d php8.4-fpm defaults

# Fixed inside every container regardless of profile — host path varies via volume mount
ENV APP_ROOT=/var/www/uprzejmiedonosze.net/
EXPOSE 80
CMD ["/bin/sh", "/root/init.sh"]

# ── Worker (PHP CLI — face-detect-consumer daemon + cron jobs) ────────────────
FROM php-base AS worker

ARG TARGETARCH=amd64

RUN apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-memcached age && \
    rm -rf /var/lib/apt/lists/*

RUN curl -sSLo /usr/local/bin/supercronic \
    "https://github.com/aptible/supercronic/releases/download/v0.2.33/supercronic-linux-${TARGETARCH}" && \
    chmod +x /usr/local/bin/supercronic

COPY --from=builder /build/export/inc/               /var/www/uprzejmiedonosze.net/webapp/inc/
COPY --from=builder /build/export/tools/             /var/www/uprzejmiedonosze.net/webapp/tools/
COPY --from=builder /build/export/public/api/config/ /var/www/uprzejmiedonosze.net/webapp/public/api/config/
COPY --from=builder /build/vendor/                   /var/www/uprzejmiedonosze.net/vendor/
COPY services/webapp/crontab                         /etc/crontab

ENV APP_ROOT=/var/www/uprzejmiedonosze.net/
USER www-data
