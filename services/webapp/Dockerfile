# ── Builder (shared: vendor + JSON configs + PHP source) ─────────────────────
FROM node:22-bookworm-slim AS builder

WORKDIR /build

COPY .npmrc package.json package-lock.json ./
RUN npm ci --omit=dev

# PHP only for running composer
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-zip php8.4-xml php8.4-curl && \
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && \
    rm -rf /var/lib/apt/lists/*

COPY composer.json composer.lock ./
COPY openalpr/ ./openalpr/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts

# JSON configs requiring node processing
COPY src/api/config/ ./src/api/config/
COPY tools/           ./tools/
RUN mkdir -p export/public/api/config && \
    node tools/sm-parser.js src/api/config/sm.json           export/public/api/config/sm.json && \
    node tools/sm-parser.js src/api/config/stop-agresji.json export/public/api/config/stop-agresji.json && \
    node tools/badges-validator.js src/api/config/badges.json export/public/api/config/badges.json && \
    cp src/api/config/categories.json export/public/api/config/ && \
    cp src/api/config/extensions.json export/public/api/config/ && \
    cp src/api/config/statuses.json   export/public/api/config/ && \
    cp src/api/config/levels.json     export/public/api/config/

COPY src/inc/   ./export/inc/
COPY src/tools/ ./export/tools/

# ── Webapp (dev) ──────────────────────────────────────────────────────────────
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

COPY --from=builder /build/vendor/                    /var/www/localhost/vendor/
COPY --chown=www-data:www-data services/webapp/db     /var/www/localhost/db
COPY --chown=www-data:www-data services/webapp/cdn    /var/www/localhost/cdn
COPY --chown=www-data:www-data services/webapp/sess_php \
        /var/lib/php/sessions/sess_48msfr815nd7f6ujomebqdpil9jueuq0
COPY services/webapp/nginx.conf                   /etc/nginx/
COPY services/webapp/nginx_php_configuration.conf /etc/nginx/
COPY services/webapp/www.conf                     /etc/php/8.4/fpm/pool.d/
COPY services/webapp/init.sh                      /root/

RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/8.4/fpm/php.ini && \
    update-rc.d php8.4-fpm defaults

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
