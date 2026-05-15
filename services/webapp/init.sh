#!/bin/sh

export MEMCACHED_HOST="${MEMCACHED_HOST:-localhost}"
export FIREBASE_AUTH_EMULATOR_HOST="${FIREBASE_AUTH_EMULATOR_HOST:-}"

printf '\nenv[MEMCACHED_HOST] = %s\n' "$MEMCACHED_HOST" >> /etc/php/8.4/fpm/pool.d/www.conf

/usr/sbin/php-fpm8.4 --daemonize

su www-data -s /bin/sh -c "touch /var/log/uprzejmiedonosze.net/localhost.log"
su www-data -s /bin/sh -c "touch /var/log/uprzejmiedonosze.net/error.log"

tail --silent -f \
    /var/log/uprzejmiedonosze.net/error.log \
    /var/log/uprzejmiedonosze.net/localhost.log &

exec nginx -g 'daemon off;'
