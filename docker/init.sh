/etc/init.d/memcached start
/etc/init.d/php7.3-fpm start

exec nginx-debug -g 'daemon off;'
