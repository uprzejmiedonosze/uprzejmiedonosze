#!/bin/sh

export MEMCACHED_HOST="${MEMCACHED_HOST:-localhost}"
export FIREBASE_AUTH_EMULATOR_HOST="${FIREBASE_AUTH_EMULATOR_HOST:-}"

printf '\nenv[MEMCACHED_HOST] = %s\n' "$MEMCACHED_HOST" >> /etc/php/8.4/fpm/pool.d/www.conf

/usr/sbin/php-fpm8.4 --nodaemonize &

exec nginx -g 'daemon off;'
