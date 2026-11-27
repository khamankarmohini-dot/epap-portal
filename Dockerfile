FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    unzip \
    libcurl4-openssl-dev \
    libonig-dev \
    libzip-dev \
    && docker-php-ext-install pdo_mysql mbstring fileinfo curl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite headers \
    && printf '<Directory /var/www/html>\n    Options -Indexes +FollowSymLinks\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/epap.conf \
    && a2enconf epap \
    && printf 'ErrorLog /dev/stderr\nCustomLog /dev/stdout combined\n' > /etc/apache2/conf-available/epap-logs.conf \
    && a2enconf epap-logs

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY . /var/www/html/

RUN mkdir -p storage/uploads storage/logs storage/mail storage/backups \
    && chown -R www-data:www-data /var/www/html /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf /var/run/apache2 /var/lock/apache2 /var/log/apache2 \
    && chmod +x /var/www/html/docker/entrypoint.sh

USER www-data

EXPOSE 10000

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
