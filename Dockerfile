# Carbure: household budget web portal and API, with woob bundled
# https://github.com/ltoinel/Carbure
FROM php:8.3-apache-bookworm

ARG WOOB_VERSION=3.7

LABEL org.opencontainers.image.title="Carbure" \
      org.opencontainers.image.description="Household budget web portal and API, bank synchronization with woob" \
      org.opencontainers.image.source="https://github.com/ltoinel/Carbure" \
      org.opencontainers.image.licenses="MIT"

# PHP extensions (mysqli, APCu) and Python for woob
RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv curl ca-certificates \
    && docker-php-ext-install mysqli \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

# woob and curl_cffi (required by the BNP module) in a virtual environment
RUN python3 -m venv /opt/woob \
    && /opt/woob/bin/pip install --no-cache-dir "woob==${WOOB_VERSION}" "curl_cffi>=0.7" \
    && ln -s /opt/woob/bin/woob /usr/local/bin/woob

# Apache: only the portal, Swagger and the API are served
RUN a2enmod rewrite headers
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/carbure.ini

WORKDIR /var/www/carbure
COPY --chown=www-data:www-data . .

# Persistent data in /data: configuration, logs and woob settings (bank backends)
RUN ln -sf /data/conf/prod.ini conf/prod.ini \
    && rm -rf logs && ln -s /data/logs logs \
    && rm -rf conf/certs && ln -s /data/conf/certs conf/certs \
    && chmod +x docker/entrypoint.sh

VOLUME /data
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD curl -fsS http://localhost/portal/ > /dev/null || exit 1

ENTRYPOINT ["/var/www/carbure/docker/entrypoint.sh"]
CMD ["apache2-foreground"]
