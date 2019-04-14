/etc/init.d/memcached start
/etc/init.d/php7.0-fpm start

xtail -f /tmp &

exec nginx-debug -g 'daemon off;'