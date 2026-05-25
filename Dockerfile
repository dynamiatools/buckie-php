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

# --- Apache VirtualHost ----------------------
COPY docker/apache-buckie.conf /etc/apache2/sites-available/buckie.conf
RUN a2dissite 000-default.conf && a2ensite buckie.conf

# --- PHP tuning for file uploads -------------
RUN echo "upload_max_filesize = 512M\n\
post_max_size = 512M\n\
memory_limit = 256M\n\
max_execution_time = 300\n\
expose_php = Off" > /usr/local/etc/php/conf.d/buckie.ini

# --- Application source ----------------------
WORKDIR /var/www/buckie

COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress

COPY . .

# --- Entrypoint (permissions + start) --------
COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Data dir & storage will be mounted as volumes
VOLUME ["/var/buckie-data", "/var/buckie-storage"]

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]

