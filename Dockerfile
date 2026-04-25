FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends git postgresql-client \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql pgsql \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

COPY docker/apache-render-port.sh /usr/local/bin/apache-render-port.sh
RUN chmod +x /usr/local/bin/apache-render-port.sh

WORKDIR /var/www/html
COPY . /var/www/html/

RUN mkdir -p /var/www/html/uploads /var/www/html/git /var/www/html/git-workspaces /var/www/html/logs \
    && chmod +x /var/www/html/bin/*.sh \
    && chown -R www-data:www-data /var/www/html

EXPOSE 10000

CMD ["apache-render-port.sh"]
