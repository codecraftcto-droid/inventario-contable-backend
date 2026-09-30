# API Laravel (PHP 8.3 + Apache) para desplegar en Dokploy.
# Las variables de entorno (.env) se configuran en Dokploy, no se copian a la imagen.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-jpeg \
    && docker-php-ext-install \
        bcmath \
        gd \
        intl \
        opcache \
        pdo_mysql \
        zip \
    && a2enmod rewrite headers \
    && printf "ServerName localhost\n" > /etc/apache2/conf-available/server-name.conf \
    && a2enconf server-name \
    && { \
        echo "upload_max_filesize=64M"; \
        echo "post_max_size=64M"; \
        echo "memory_limit=512M"; \
        echo "max_execution_time=300"; \
        echo "max_input_time=300"; \
        echo "date.timezone=America/Lima"; \
        echo "expose_php=Off"; \
    } > /usr/local/etc/php/conf.d/inventario.ini \
    && { \
        echo "opcache.enable=1"; \
        echo "opcache.memory_consumption=128"; \
        echo "opcache.max_accelerated_files=20000"; \
        echo "opcache.validate_timestamps=0"; \
    } > /usr/local/etc/php/conf.d/opcache.ini \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Primero las dependencias (se reutiliza la capa mientras composer.lock no cambie)
COPY composer.json composer.lock ./
COPY Modules/Core/composer.json Modules/Core/composer.json
COPY Modules/Security/composer.json Modules/Security/composer.json
COPY Modules/Companies/composer.json Modules/Companies/composer.json
COPY Modules/Inventories/composer.json Modules/Inventories/composer.json
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --no-progress

COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && sed -i 's!/var/www/html!/var/www/html/public!g' /etc/apache2/sites-available/000-default.conf

COPY docker/entrypoint.sh /usr/local/bin/inventario-entrypoint
RUN chmod +x /usr/local/bin/inventario-entrypoint

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["inventario-entrypoint"]
CMD ["apache2-foreground"]
