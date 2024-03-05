FROM nginx:bookworm as webserver
LABEL net.uprzejmiedonosze=0.1.2

RUN DEBIAN_FRONTEND="noninteractive" apt-get update

RUN DEBIAN_FRONTEND="noninteractive" apt-get --no-install-recommends install -y \
    apt-utils locales vim telnet curl xtail \
    memcached texlive ca-certificates \
    php-fpm php-sqlite3 php-curl \
    php-opcache php-mbstring php-gd \
    php-memcache php-memcached php-intl

RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

ENV HOME /root

RUN mkdir -p                   /var/www/uprzejmiedonosze.localhost
COPY db                        /var/www/uprzejmiedonosze.localhost/db
RUN chown -R www-data:www-data /var/www/uprzejmiedonosze.localhost/db
COPY cdn                       /var/www/uprzejmiedonosze.localhost/cdn
RUN chown -R www-data:www-data /var/www/uprzejmiedonosze.localhost/cdn
RUN mkdir -p                   /var/www/uprzejmiedonosze.localhost/cdn2
RUN chown -R www-data:www-data /var/www/uprzejmiedonosze.localhost/cdn2
COPY sess_php                  /var/lib/php/sessions/sess_48msfr815nd7f6ujomebqdpil9jueuq0
RUN chown www-data:www-data    /var/lib/php/sessions/*

RUN mkdir                      /var/log/uprzejmiedonosze.net
RUN chown www-data:www-data    /var/log/uprzejmiedonosze.net
RUN mkdir                      /var/cache/uprzejmiedonosze.net
RUN chown www-data:www-data    /var/cache/uprzejmiedonosze.net

ADD default.conf         /etc/nginx/conf.d/default.conf
ADD nginx.conf           /etc/nginx/nginx.conf
ADD www.conf             /etc/php/8.2/fpm/pool.d/www.conf
RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/8.2/fpm/php.ini

RUN update-rc.d php8.2-fpm defaults
RUN update-rc.d memcached defaults

COPY init.sh /root/init.sh
RUN ln -s /var/www/uprzejmiedonosze.localhost/webapp/vendor /var/www/uprzejmiedonosze.localhost

EXPOSE 80

CMD ["/bin/sh", "/root/init.sh"]
