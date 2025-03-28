#!/bin/sh
/etc/init.d/memcached start
/etc/init.d/php8.2-fpm start


xtail -f \
    /var/log/uprzejmiedonosze.net/error.log \
    /var/log/uprzejmiedonosze.net/localhost.log &

su -l www-data -s /bin/bash -c "php /var/www/localhost/webapp/tools/face-detect-consumer.php &"

exec nginx -g 'daemon off;'
