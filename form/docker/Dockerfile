FROM nginx

RUN apt-get update

RUN DEBIAN_FRONTEND="noninteractive" apt-get install -y apt-utils
RUN DEBIAN_FRONTEND="noninteractive" apt-get install -y locales vim telnet curl
#RUN locale-gen pl_PL.UTF-8
#ENV LANG       pl_PL.UTF-8
#ENV LC_ALL     pl_PL.UTF-8

ENV HOME /root

#CMD ["/sbin/my_init"]

RUN DEBIAN_FRONTEND="noninteractive" apt-get install -y --allow-downgrades --allow-remove-essential \
    --allow-change-held-packages php7.0-fpm php7.0-sqlite3 php7.0-mcrypt php-memcache php-memcached \
    php7.0-opcache php7.0-mbstring php7.0-gd memcached

#RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php5/fpm/php.ini
#RUN sed -i "s/;date.timezone =.*/date.timezone = UTC/" /etc/php5/cli/php.ini

RUN DEBIAN_FRONTEND="noninteractive" apt-get install -y nginx

RUN mkdir -p        /var/www
ADD default.conf    /etc/nginx/conf.d/default.conf
ADD nginx.conf      /etc/nginx/nginx.conf
ADD www.conf        /etc/php/7.0/fpm/pool.d/www.conf
RUN sed -i -e "s/.*short_open_tag.*/short_open_tag = On/" /etc/php/7.0/fpm/php.ini

RUN update-rc.d php7.0-fpm defaults
RUN update-rc.d memcached defaults

EXPOSE 80:8080

RUN apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

#RUN /etc/init.d/php7.0-fpm start
#CMD ["/etc/init.d/php7.0-fpm", "start"]
#CMD ["nginx", "-s", "start"]
#CMD ["/sbin/my_init"]