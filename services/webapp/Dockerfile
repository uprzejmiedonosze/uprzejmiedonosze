FROM nginx:1.27.3-bookworm AS webserver

LABEL net.uprzejmiedonosze=0.2.3

ENV HOME=/root

RUN DEBIAN_FRONTEND="noninteractive" apt-get update && \
    apt-get --no-install-recommends -y install \
        locales vim curl sqlite3 \
        memcached texlive ca-certificates \
        php-fpm php-sqlite3 php-curl php-zip \
        php-opcache php-mbstring php-gd \
        php-memcache php-memcached php-intl \
        lmodern texlive-lang-polish \
        texlive-lang-all texlive-latex-recommended texlive-latex-extra && \
    apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    mkdir -p /var/www/localhost/db \
             /var/www/localhost/cdn2 \
             /var/www/localhost/video \
             /var/log/uprzejmiedonosze.net \
             /var/cache/uprzejmiedonosze.net && \
    chown -R www-data:www-data /var/www/localhost && \
    chown www-data:www-data /var/log/uprzejmiedonosze.net /var/cache/uprzejmiedonosze.net

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /tmp/composer-build
COPY composer.json composer.lock ./
COPY openalpr/ ./openalpr/
RUN composer install --no-dev --no-interaction --no-progress --no-scripts && \
    mv vendor /var/www/localhost/vendor && \
    chown -R www-data:www-data /var/www/localhost/vendor && \
    rm -rf /tmp/composer-build /usr/local/bin/composer

COPY --chown=www-data:www-data services/webapp/db       /var/www/localhost/db
COPY --chown=www-data:www-data services/webapp/cdn      /var/www/localhost/cdn
COPY --chown=www-data:www-data services/webapp/sess_php /var/lib/php/sessions/sess_48msfr815nd7f6ujomebqdpil9jueuq0
COPY services/webapp/nginx.conf                   /etc/nginx/
COPY services/webapp/nginx_php_configuration.conf /etc/nginx/
COPY services/webapp/www.conf                     /etc/php/8.2/fpm/pool.d/
COPY services/webapp/init.sh                      /root/

RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/8.2/fpm/php.ini && \
    update-rc.d php8.2-fpm defaults && \
    update-rc.d memcached defaults

EXPOSE 80
CMD ["/bin/sh", "/root/init.sh"]
