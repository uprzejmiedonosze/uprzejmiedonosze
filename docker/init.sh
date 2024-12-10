#!/bin/sh
/etc/init.d/memcached start
/etc/init.d/php8.2-fpm start


xtail -f \
    /var/log/uprzejmiedonosze.net/error.log \
    /var/log/uprzejmiedonosze.net/uprzejmiedonosze.localhost.log &

exec nginx -g 'daemon off;'
