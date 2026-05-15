#!/bin/sh
/etc/init.d/php8.4-fpm start

su -l www-data -s /bin/bash -c "touch /var/log/uprzejmiedonosze.net/localhost.log"
su -l www-data -s /bin/bash -c "touch /var/log/uprzejmiedonosze.net/error.log"

tail --silent -f \
    /var/log/uprzejmiedonosze.net/error.log \
    /var/log/uprzejmiedonosze.net/localhost.log &

su -l www-data -s /bin/bash -c "php /var/www/localhost/webapp/tools/face-detect-consumer.php &"

exec nginx -g 'daemon off;'
