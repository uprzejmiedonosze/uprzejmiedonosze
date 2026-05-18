# ── Builder (shared: vendor + full export) ───────────────────────────────────
FROM node:22-bookworm-slim AS builder

ARG APP_HOST=localhost
ARG APP_HTTPS=http

WORKDIR /build

# npm (cached until package-lock.json changes)
COPY .npmrc package.json package-lock.json ./
RUN npm ci

# PHP 8.4 + composer (cached until composer.lock changes)
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates jq inotify-tools && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-zip php8.4-xml php8.4-curl php8.4-gd php8.4-sqlite3 \
        php8.4-memcache php8.4-mbstring php8.4-intl \
        memcached && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    rm -rf /var/lib/apt/lists/*

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
FROM nginx:1.27.3-bookworm AS webapp

LABEL net.uprzejmiedonosze=0.2.3
ENV HOME=/root

RUN curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list

RUN DEBIAN_FRONTEND="noninteractive" apt-get update && \
    apt-get --no-install-recommends -y install \
        locales vim curl sqlite3 \
        texlive ca-certificates \
        php8.4-fpm php8.4-sqlite3 php8.4-curl php8.4-zip \
        php8.4-opcache php8.4-mbstring php8.4-gd \
        php8.4-memcache php8.4-memcached php8.4-intl php8.4-xml \
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

RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/8.4/fpm/php.ini && \
    sed -i -e "s|.*error_log.*|error_log = /dev/stderr|" /etc/php/8.4/fpm/php-fpm.conf && \
    update-rc.d php8.4-fpm defaults

# Fixed inside every container regardless of profile — host path varies via volume mount
ENV APP_ROOT=/var/www/uprzejmiedonosze.net/
EXPOSE 80
CMD ["/bin/sh", "/root/init.sh"]

# ── Worker (PHP CLI — face-detect-consumer daemon + cron jobs) ────────────────
FROM debian:bookworm-slim AS worker

ARG TARGETARCH=amd64

RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-sqlite3 php8.4-curl php8.4-gd \
        php8.4-memcache php8.4-memcached \
        php8.4-xml php8.4-mbstring \
    && rm -rf /var/lib/apt/lists/*

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
