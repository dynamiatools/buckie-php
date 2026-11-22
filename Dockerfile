# ─────────────────────────────────────────────
# Buckie PHP — Docker image (Apache, local dev)
# ─────────────────────────────────────────────
FROM php:8.3-apache

# --- System dependencies & PHP extensions ----
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg62-turbo-dev \
        libwebp-dev \
        libfreetype6-dev \
        libxml2-dev \
        unzip \
        git \
    && docker-php-ext-configure gd \
        --with-jpeg \
        --with-webp \
        --with-freetype \
    && docker-php-ext-install -j$(nproc) \
        gd \
        fileinfo \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# --- Composer (official image trick) ---------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Apache: enable mod_rewrite --------------
RUN a2enmod rewrite

# --- Apache: do not advertise the version ---
RUN printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/zz-buckie-security.conf \
    && a2enconf zz-buckie-security

# --- Apache VirtualHost ----------------------
COPY docker/apache-buckie.conf /etc/apache2/sites-available/buckie.conf
RUN a2dissite 000-default.conf && a2ensite buckie.conf

# --- PHP tuning for file uploads -------------
RUN echo "upload_max_filesize = 512M\n\
post_max_size = 512M\n\
memory_limit = 256M\n\
max_execution_time = 300\n\
expose_php = Off\n\
display_errors = Off" > /usr/local/etc/php/conf.d/buckie.ini

# --- Application source ----------------------
WORKDIR /var/www/buckie

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY . .

# --- Entrypoint (permissions + start) --------
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV BUCKIE_DATA_DIR=/var/buckie-data

# `buckie` wrapper: always runs the CLI as www-data, the user Apache runs as, so that
# files it creates (config.json is 0600) stay readable by the web server.
RUN printf '#!/bin/sh\nexec runuser -u www-data -- php /var/www/buckie/bin/buckie "$@"\n' > /usr/local/bin/buckie \
    && chmod +x /usr/local/bin/buckie

# Data dir & storage will be mounted as volumes
VOLUME ["/var/buckie-data", "/var/buckie-storage"]

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

