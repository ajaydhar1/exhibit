FROM php:8.4-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends libpq-dev ca-certificates \
 && docker-php-ext-install pdo_pgsql \
 && rm -rf /var/lib/apt/lists/*

COPY docker/apache-private.conf /etc/apache2/conf-available/exhibit-private.conf
COPY docker/php-production.ini /usr/local/etc/php/conf.d/exhibit-production.ini
RUN a2enconf exhibit-private

# Render web services listen on the port supplied through PORT (default 10000).
ENV PORT=10000
ENV DB_SSLROOTCERT=/etc/ssl/certs/ca-certificates.crt
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
 && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

RUN php -r 'exit(in_array("pgsql", PDO::getAvailableDrivers(), true) ? 0 : 1);' \
 && apache2ctl -t

EXPOSE 10000
CMD ["apache2-foreground"]
