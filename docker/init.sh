#!/bin/sh
/etc/init.d/memcached start
/etc/init.d/php8.2-fpm start

xtail -f /var/log/uprzejmiedonosze.net/ &

exec nginx -g 'daemon off;'
