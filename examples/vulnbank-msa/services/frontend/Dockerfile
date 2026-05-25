FROM php:7.4-cli

RUN docker-php-ext-install mysqli pdo_mysql

WORKDIR /var/www/html
COPY services/frontend/public ./

ENV USER_SERVICE_URL=http://user-service:8080/api.php
ENV TRANSACTION_SERVICE_URL=http://transaction-service:8080/api.php
ENV STATUS_SERVICE_URL=http://status-service:8080/api.php
ENV FILE_SERVICE_URL=http://file-service:8080/api.php
ENV SETTINGS_SERVICE_URL=http://settings-service:8080/api.php

EXPOSE 8080
CMD ["php", "-d", "output_buffering=On", "-S", "0.0.0.0:8080", "router.php"]
