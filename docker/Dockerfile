FROM nginx:1.27.3-bookworm AS webserver

LABEL net.uprzejmiedonosze=0.2.3

ENV HOME=/root

# Install packages, create directories, and set ownership in one RUN command
RUN DEBIAN_FRONTEND="noninteractive" apt-get update && \
    apt-get --no-install-recommends -y install \
        locales vim curl xtail \
        memcached texlive ca-certificates \
        php-fpm php-sqlite3 php-curl \
        php-opcache php-mbstring php-gd \
        php-memcache php-memcached php-intl \
        ffmpeg \
        lmodern texlive-lang-polish texlive-latex-recommended texlive-latex-extra && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    mkdir -p /var/www/uprzejmiedonosze.localhost/db \
             /var/www/uprzejmiedonosze.localhost/cdn \
             /var/www/uprzejmiedonosze.localhost/cdn2 \
             /var/www/uprzejmiedonosze.localhost/video \
             /var/log/uprzejmiedonosze.net \
             /var/cache/uprzejmiedonosze.net && \
    chown -R www-data:www-data /var/www/uprzejmiedonosze.localhost && \
    chown www-data:www-data /var/log/uprzejmiedonosze.net /var/cache/uprzejmiedonosze.net

COPY --chown=www-data:www-data db          /var/www/uprzejmiedonosze.localhost/db
COPY --chown=www-data:www-data cdn         /var/www/uprzejmiedonosze.localhost/cdn
COPY --chown=www-data:www-data sess_php    /var/lib/php/sessions/sess_48msfr815nd7f6ujomebqdpil9jueuq0
COPY nginx.conf   /etc/nginx/
COPY nginx_php_configuration.conf   /etc/nginx/
COPY www.conf     /etc/php/8.2/fpm/pool.d/
COPY init.sh      /root/

RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/8.2/fpm/php.ini && \
    update-rc.d php8.2-fpm defaults && \
    update-rc.d memcached defaults && \
    ln -s /var/www/uprzejmiedonosze.localhost/webapp/vendor /var/www/uprzejmiedonosze.localhost

EXPOSE 80
CMD ["/bin/sh", "/root/init.sh"]
