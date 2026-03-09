FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    bash \
    curl \
    icu-data-full \
    libzip-dev \
    sqlite \
    sqlite-dev \
    oniguruma-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo pdo_sqlite sqlite3 mbstring gd exif \
    && rm -rf /tmp/*

WORKDIR /var/www/html
