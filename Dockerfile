FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --classmap-authoritative

FROM php:8.2-apache-bookworm

ENV APACHE_DOCUMENT_ROOT=/var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        gosu \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
        tesseract-ocr \
        tesseract-ocr-eng \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring mysqli pdo_mysql zip \
    && (a2dismod mpm_event mpm_worker || true)

RUN a2enmod mpm_prefork \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html
COPY --from=dependencies /app/vendor /var/www/html/vendor

# The face-api model binaries are large and make CLI source uploads unreliable
# on slow connections. Fetch the repository-pinned copies during the remote build.
RUN set -eux; \
    model_base='https://raw.githubusercontent.com/minokawa16/San-Lorenzo-Ruiz/4cb00ceb31662227a5b3a78a74a054f140a2939c/models'; \
    mkdir -p /var/www/html/models; \
    for model_file in \
        face_landmark_68_model-shard1 \
        face_landmark_68_model-weights_manifest.json \
        face_recognition_model-shard1 \
        face_recognition_model-shard2 \
        face_recognition_model-weights_manifest.json \
        tiny_face_detector_model-shard1 \
        tiny_face_detector_model-weights_manifest.json; do \
        curl --fail --location --retry 4 --retry-all-errors \
            --output "/var/www/html/models/${model_file}" \
            "${model_base}/${model_file}"; \
    done

COPY docker/entrypoint.sh /usr/local/bin/tugon-entrypoint
COPY docker/worker.sh /usr/local/bin/tugon-worker
COPY docker/tugon-apache.conf /etc/apache2/conf-available/tugon-security.conf
COPY docker/tugon-production.ini /usr/local/etc/php/conf.d/tugon-production.ini

RUN mkdir -p /opt/tugon-seed \
    && for directory in uploads storage backups cache logs; do \
        if [ -d "/var/www/html/${directory}" ]; then \
            cp -a "/var/www/html/${directory}" "/opt/tugon-seed/${directory}"; \
        else \
            mkdir -p "/opt/tugon-seed/${directory}"; \
        fi; \
    done \
    && chmod +x /usr/local/bin/tugon-entrypoint /usr/local/bin/tugon-worker \
    && a2enconf tugon-security \
    && chown -R www-data:www-data /var/www/html /opt/tugon-seed

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl --fail --silent --show-error "http://127.0.0.1:${PORT:-8080}/healthz.php" || exit 1

ENTRYPOINT ["tugon-entrypoint"]
CMD ["apache2-foreground"]
