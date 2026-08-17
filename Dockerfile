FROM php:8.2-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        tesseract-ocr \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring mysqli pdo_mysql zip \
    && (a2dismod mpm_event mpm_worker || true)

RUN a2enmod mpm_prefork \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/tugon-entrypoint

RUN mkdir -p /opt/tugon-seed \
    && for directory in uploads storage backups cache logs; do \
        if [ -d "/var/www/html/${directory}" ]; then \
            cp -a "/var/www/html/${directory}" "/opt/tugon-seed/${directory}"; \
        else \
            mkdir -p "/opt/tugon-seed/${directory}"; \
        fi; \
    done \
    && chmod +x /usr/local/bin/tugon-entrypoint \
    && chown -R www-data:www-data /var/www/html /opt/tugon-seed

EXPOSE 8080

ENTRYPOINT ["tugon-entrypoint"]
CMD ["apache2-foreground"]
