#!/bin/sh

export MEMCACHED_HOST="${MEMCACHED_HOST:-localhost}"
export FIREBASE_AUTH_EMULATOR_HOST="${FIREBASE_AUTH_EMULATOR_HOST:-}"

printf '\nenv[MEMCACHED_HOST] = %s\n' "$MEMCACHED_HOST" >> /etc/php/8.4/fpm/pool.d/www.conf

# Dev only: the bind-mounted SQLite DB is owned by the host user, so on
# WSL/Linux bind mounts php-fpm (www-data) can't write it ("readonly
# database"). Make it writable. Harmless on macOS, where the mount is already
# writable, and never runs in prod (guarded on APP_ENV).
if [ "$APP_ENV" = "dev" ]; then
    chmod -R a+rw /var/www/uprzejmiedonosze.net/db 2>/dev/null || true
fi

/usr/sbin/php-fpm8.4 --nodaemonize &

exec nginx -g 'daemon off;'
