# ── Builder (shared: vendor + full export) ───────────────────────────────────
FROM node:22-bookworm-slim AS builder

ARG APP_HOST=localhost
ARG APP_HTTPS=http

WORKDIR /build

# npm (cached until package-lock.json changes)
COPY .npmrc package.json package-lock.json ./
RUN npm ci --omit=dev

# PHP 8.4 + composer (cached until composer.lock changes)
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates jq && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-zip php8.4-xml php8.4-curl php8.4-gd && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
COPY openalpr/ ./openalpr/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts

# Source (changes frequently — after slow steps)
COPY src/   ./src/
COPY tools/ ./tools/

# Output directories
RUN mkdir -p \
    export/public/api/config export/public/api/rest \
    export/public/css export/public/js \
    export/inc export/templates export/tools export/sql

# PHP files
RUN cp -r src/inc/.     export/inc/ && \
    cp -r src/tools      export/ && \
    cp -r src/sql        export/ && \
    cp -r src/templates/. export/templates/ && \
    cp src/index.php src/favicon.ico src/robots.txt src/ads.txt export/public/ && \
    cp src/manifest.json export/public/

# API HTML files + REST
RUN find src/api -maxdepth 1 -name '*.html' -exec cp {} export/public/api/ \; && \
    cp src/api/rest/index.php export/public/api/rest/

# JSON configs (node processing + plain copy)
RUN node tools/sm-parser.js src/api/config/sm.json           export/public/api/config/sm.json && \
    node tools/sm-parser.js src/api/config/stop-agresji.json export/public/api/config/stop-agresji.json && \
    node tools/badges-validator.js src/api/config/badges.json export/public/api/config/badges.json && \
    for f in src/api/config/*.json; do \
      name=$(basename "$f"); \
      [ ! -f "export/public/api/config/$name" ] && jq -c . "$f" > "export/public/api/config/$name" || true; \
    done

# Police stations (needs PHP)
RUN php tools/police-stations.php \
        src/api/config/police-stations.csv \
        export/public/api/config/stop-agresji.json \
    > export/public/api/config/police-stations.pjson

# CSS (HOST-specific scss variables)
RUN HOST=${APP_HOST} node src/scss/env.js > src/scss/lib/variables.env.scss && \
    node_modules/.bin/parcel build --no-cache --dist-dir export/public/css src/scss/index.scss

# JS (one output dir, one file per source)
RUN for f in src/js/*.js; do \
      node_modules/.bin/parcel build --no-cache --dist-dir export/public/js/ "$f"; \
    done

# config.env.php — computed hashes so cache busting works correctly
RUN CSS_HASH=$(find export/public/css  -type f | sort | xargs cat | md5sum | cut -c1-8) && \
    JS_HASH=$(find  export/public/js   -type f | sort | xargs cat | md5sum | cut -c1-8) && \
    TWIG_HASH=$(find src/templates -name '*.twig' | sort | xargs cat | md5sum | cut -c1-8) && \
    printf '<?php\ndefine("HOST", "%s");\ndefine("TWIG_HASH", "%s");\ndefine("CSS_HASH", "%s");\ndefine("JS_HASH", "%s");\ndefine("HTTPS", "%s");\n' \
        "${APP_HOST}" "${TWIG_HASH}" "${CSS_HASH}" "${JS_HASH}" "${APP_HTTPS}" \
    > export/config.env.php

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
    mkdir -p /var/www/localhost/db \
             /var/www/localhost/cdn2 \
             /var/www/localhost/video \
             /var/log/uprzejmiedonosze.net \
             /var/cache/uprzejmiedonosze.net && \
    chown -R www-data:www-data /var/www/localhost && \
    chown www-data:www-data /var/log/uprzejmiedonosze.net /var/cache/uprzejmiedonosze.net

# Code + deps from builder (no volume mount needed for code)
COPY --from=builder --chown=www-data:www-data /build/export/ /var/www/localhost/webapp/
COPY --from=builder /build/vendor/              /var/www/localhost/vendor/

# Dev seed data (db, cdn, session fixture)
COPY --chown=www-data:www-data services/webapp/db      /var/www/localhost/db
COPY --chown=www-data:www-data services/webapp/cdn     /var/www/localhost/cdn
COPY --chown=www-data:www-data services/webapp/sess_php \
        /var/lib/php/sessions/sess_48msfr815nd7f6ujomebqdpil9jueuq0

COPY services/webapp/nginx.conf                   /etc/nginx/
COPY services/webapp/nginx_php_configuration.conf /etc/nginx/
COPY services/webapp/www.conf                     /etc/php/8.4/fpm/pool.d/
COPY services/webapp/init.sh                      /root/

RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/8.4/fpm/php.ini && \
    update-rc.d php8.4-fpm defaults

ENV APP_ROOT=/var/www/localhost/
EXPOSE 80
CMD ["/bin/sh", "/root/init.sh"]

# ── face-detect-consumer (staging / prod) ────────────────────────────────────
FROM debian:bookworm-slim AS face-detect-consumer

RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-sqlite3 php8.4-curl \
        php8.4-memcache php8.4-memcached \
        php8.4-xml php8.4-mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /build/export/inc/               /app/webapp/inc/
COPY --from=builder /build/export/tools/             /app/webapp/tools/
COPY --from=builder /build/export/public/api/config/ /app/webapp/public/api/config/
COPY --from=builder /build/vendor/                   /app/vendor/

ENV APP_HOST=uprzejmiedonosze.net
ENV APP_ROOT=/app/

CMD ["nice", "-n", "10", "php", "/app/webapp/tools/face-detect-consumer.php"]
