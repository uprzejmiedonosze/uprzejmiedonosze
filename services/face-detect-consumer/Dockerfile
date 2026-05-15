FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates && \
    curl -sSLo /usr/share/keyrings/deb.sury.org-php.gpg https://packages.sury.org/php/apt.gpg && \
    echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ bookworm main" \
        > /etc/apt/sources.list.d/php.list && \
    apt-get update && apt-get install -y --no-install-recommends \
        php8.4-cli php8.4-sqlite3 php8.4-curl php8.4-memcache php8.4-memcached \
    && rm -rf /var/lib/apt/lists/*
