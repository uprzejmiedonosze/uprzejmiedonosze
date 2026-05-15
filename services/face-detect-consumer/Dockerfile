FROM node:20-bookworm-slim AS builder

WORKDIR /build

# npm deps (sm-parser, badges-validator)
COPY package.json package-lock.json ./
RUN npm ci --omit=dev

# PHP vendor
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates php-cli \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
COPY openalpr/ ./openalpr/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts

# Process JSON configs (sm-parser rewrites structure; others are copied as-is)
COPY src/api/config/ ./src/api/config/
COPY tools/ ./tools/
RUN mkdir -p dist/api/config && \
    node tools/sm-parser.js src/api/config/sm.json            dist/api/config/sm.json && \
    node tools/sm-parser.js src/api/config/stop-agresji.json  dist/api/config/stop-agresji.json && \
    node tools/badges-validator.js src/api/config/badges.json dist/api/config/badges.json && \
    cp src/api/config/categories.json dist/api/config/ && \
    cp src/api/config/extensions.json dist/api/config/ && \
    cp src/api/config/statuses.json   dist/api/config/ && \
    cp src/api/config/levels.json     dist/api/config/

# ── Runtime ──────────────────────────────────────────────────────────────────
FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-sqlite3 php8.4-curl \
        php8.4-memcache php8.4-memcached \
        php8.4-xml php8.4-mbstring \
    && rm -rf /var/lib/apt/lists/*

COPY src/inc/   /app/webapp/inc/
COPY src/tools/ /app/webapp/tools/
COPY --from=builder /build/dist/api/config/ /app/webapp/public/api/config/
COPY --from=builder /build/vendor/           /app/vendor/

# db/ i config.php/config.prod.php montowane jako volumes z serwera
ENV APP_HOST=uprzejmiedonosze.net
ENV APP_ROOT=/app/

CMD ["nice", "-n", "10", "php", "/app/webapp/tools/face-detect-consumer.php"]
