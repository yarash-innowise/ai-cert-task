FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_pgsql gd intl opcache @composer

COPY docker/app/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/app/Caddyfile /etc/caddy/Caddyfile

WORKDIR /app
