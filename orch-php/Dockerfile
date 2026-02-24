FROM php:8.3-apache

RUN a2enmod rewrite
RUN echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev openssh-client sshpass && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo_pgsql pgsql

COPY apache/000-default.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
