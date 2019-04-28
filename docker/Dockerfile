FROM nginx as webserver
LABEL net.uprzejmiedonosze=0.0.1

RUN apt-get update

RUN DEBIAN_FRONTEND="noninteractive" apt-get update && apt-get --no-install-recommends install -y \
    apt-utils locales vim telnet curl xtail \
    memcached texlive ca-certificates \
    php7.0-fpm php7.0-sqlite3 php7.0-mcrypt php7.0-curl  \
    php7.0-opcache php7.0-mbstring php7.0-gd \
    php-memcache php-memcached

RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

ENV HOME /root

RUN mkdir -p             /var/www/uprzejmiedonosze.localhost
COPY db                  /var/www/uprzejmiedonosze.localhost/db
RUN chown -R nginx:nginx /var/www/uprzejmiedonosze.localhost/db
COPY cdn                 /var/www/uprzejmiedonosze.localhost/cdn
RUN chown -R nginx:nginx /var/www/uprzejmiedonosze.localhost/cdn
RUN mkdir -p             /var/www/uprzejmiedonosze.localhost/cdn2
RUN chown -R nginx:nginx /var/www/uprzejmiedonosze.localhost/cdn2

RUN mkdir                /var/log/uprzejmiedonosze.net
RUN chown nginx:nginx    /var/log/uprzejmiedonosze.net
RUN mkdir                /var/cache/uprzejmiedonosze.net
RUN chown nginx:nginx    /var/cache/uprzejmiedonosze.net

ADD default.conf         /etc/nginx/conf.d/default.conf
ADD nginx.conf           /etc/nginx/nginx.conf
ADD www.conf             /etc/php/7.0/fpm/pool.d/www.conf
RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/7.0/fpm/php.ini

RUN update-rc.d php7.0-fpm defaults
RUN update-rc.d memcached defaults

COPY init.sh /root/init.sh

EXPOSE 80

CMD ["/bin/sh", "/root/init.sh"]
