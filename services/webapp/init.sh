#!/bin/sh

export MEMCACHED_HOST="${MEMCACHED_HOST:-localhost}"
export FIREBASE_AUTH_EMULATOR_HOST="${FIREBASE_AUTH_EMULATOR_HOST:-}"

printf '\nenv[MEMCACHED_HOST] = %s\n' "$MEMCACHED_HOST" >> /etc/php/8.4/fpm/pool.d/www.conf

# nginx error_log destination: on staging/prod the host's journald socket is
# bind-mounted at /run/systemd/journal (services/compose.yml), so send
# errors there directly as syslog with a real severity — same rationale as
# Logger.php's syslog transport (AGENTS.md § Logging). Falls back to
# /dev/stderr when that socket isn't present (dev, or any future profile
# that doesn't mount it). nginx syslog tags only allow [A-Za-z0-9_], max 32
# chars, hence the underscore instead of a hyphen.
if [ -S /run/systemd/journal/dev-log ]; then
    printf 'error_log syslog:server=unix:/run/systemd/journal/dev-log,tag=%s_nginx,nohostname warn;\n' \
        "${APP_ENV:-dev}" > /etc/nginx/error_log.conf
else
    printf 'error_log /dev/stderr warn;\n' > /etc/nginx/error_log.conf
fi

# Dev only: the bind-mounted SQLite DB is owned by the host user, so on
# WSL/Linux bind mounts php-fpm (www-data) can't write it ("readonly
# database"). Make it writable. Harmless on macOS, where the mount is already
# writable, and never runs in prod (guarded on APP_ENV).
if [ "$APP_ENV" = "dev" ]; then
    chmod -R a+rw /var/www/uprzejmiedonosze.net/db 2>/dev/null || true
fi

/usr/sbin/php-fpm8.4 --nodaemonize &

exec nginx -g 'daemon off;'
