FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates git postgresql-client libpq-dev \
    && update-ca-certificates \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql pgsql \
    && a2enmod rewrite headers cgi env setenvif \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY docker/apache-render-port.sh /usr/local/bin/apache-render-port.sh
COPY docker/apache-git-http.conf /etc/apache2/conf-available/git-http.conf
COPY docker/git-http-backend-wrapper.sh /usr/local/bin/git-http-backend-wrapper.sh
RUN chmod +x /usr/local/bin/apache-render-port.sh /usr/local/bin/git-http-backend-wrapper.sh \
    && a2enconf git-http

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads /var/www/html/git /var/www/html/git-workspaces /var/www/html/logs \
    && chmod +x /var/www/html/bin/*.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 10000

CMD ["apache-render-port.sh"]
