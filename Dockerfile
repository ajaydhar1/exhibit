FROM php:8.4-apache

# Render web services listen on the port supplied through PORT (default 10000).
ENV PORT=10000
RUN sed -i 's/Listen 80/Listen 10000/' /etc/apache2/ports.conf \
 && sed -i 's/:80>/:10000>/' /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY . /var/www/html/

EXPOSE 10000
CMD ["apache2-foreground"]
