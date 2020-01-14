/etc/init.d/memcached start
/etc/init.d/php7.3-fpm start

xtail -f /var/log/uprzejmiedonosze.net/ &

exec nginx -g 'daemon off;'
