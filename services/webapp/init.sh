#!/bin/sh

# Explicit export so PHP-FPM master inherits Docker env vars
# (start-stop-daemon used by init.d may not propagate them otherwise)
export MEMCACHED_HOST="${MEMCACHED_HOST:-localhost}"
export FIREBASE_AUTH_EMULATOR_HOST="${FIREBASE_AUTH_EMULATOR_HOST:-}"

/usr/sbin/php-fpm8.4 --daemonize

# -l (login shell) resets env — use plain su to inherit exported vars
su www-data -s /bin/sh -c "touch /var/log/uprzejmiedonosze.net/localhost.log"
su www-data -s /bin/sh -c "touch /var/log/uprzejmiedonosze.net/error.log"

tail --silent -f \
    /var/log/uprzejmiedonosze.net/error.log \
    /var/log/uprzejmiedonosze.net/localhost.log &

su www-data -s /bin/sh -c "php /var/www/localhost/webapp/tools/face-detect-consumer.php &"

exec nginx -g 'daemon off;'
