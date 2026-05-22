FROM php:7.4-cli

RUN docker-php-ext-install mysqli pdo_mysql

WORKDIR /var/www/html
COPY shared/php/inc ./inc
COPY services/settings-service/public ./

ENV DB_HOST=vulnbank-db
ENV DB_USER=root
ENV DB_PASSWORD=
ENV DB_NAME=vulnbank

EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "api.php"]

