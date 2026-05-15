FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev libmemcached-dev libcurl4-openssl-dev zlib1g-dev pkg-config \
    && docker-php-ext-install pdo_sqlite curl \
    && cd /tmp \
    && pecl download memcached \
    && tar xf memcached-*.tgz \
    && cd memcached-* \
    && phpize \
    && ./configure --with-libmemcached-dir=/usr \
    && make -j$(nproc) install \
    && docker-php-ext-enable memcached \
    && rm -rf /tmp/memcached* \
    && rm -rf /var/lib/apt/lists/*
