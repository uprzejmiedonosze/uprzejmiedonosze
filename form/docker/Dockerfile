FROM nginx as webserver
LABEL net.uprzejmiedonosze=0.0.1

RUN apt-get update

RUN DEBIAN_FRONTEND="noninteractive" apt-get update && apt-get install -y \
    apt-utils locales vim telnet curl xtail \
    memcached texlive \
    php7.0-fpm php7.0-sqlite3 php7.0-mcrypt php7.0-curl  \
    php7.0-opcache php7.0-mbstring php7.0-gd \
    php-memcache php-memcached

RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

ENV HOME /root

RUN mkdir -p             /var/www/uprzejmiedonosze.net
COPY db                  /var/www/uprzejmiedonosze.net/db
RUN chown -R nginx:nginx /var/www/uprzejmiedonosze.net/db
COPY cdn                 /var/www/uprzejmiedonosze.net/cdn
RUN chown -R nginx:nginx /var/www/uprzejmiedonosze.net/cdn

RUN touch                /tmp/uprzejmiedonosze.localhost.log
RUN touch                /tmp/error.log
RUN touch                /tmp/access.log
RUN chown nginx:nginx    /tmp/*.log

ADD default.conf         /etc/nginx/conf.d/default.conf
ADD nginx.conf           /etc/nginx/nginx.conf
ADD www.conf             /etc/php/7.0/fpm/pool.d/www.conf
RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/7.0/fpm/php.ini

RUN update-rc.d php7.0-fpm defaults
RUN update-rc.d memcached defaults

COPY init.sh /root/init.sh

EXPOSE 80

CMD ["/bin/sh", "/root/init.sh"]
