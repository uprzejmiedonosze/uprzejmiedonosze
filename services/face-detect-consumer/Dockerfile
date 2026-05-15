FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
    libsqlite3-dev libmemcached-dev libcurl4-openssl-dev zlib1g-dev \
    && docker-php-ext-install pdo_sqlite curl \
    && pecl install memcached && docker-php-ext-enable memcached \
    && rm -rf /var/lib/apt/lists/*
