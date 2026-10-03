# Carbure: household budget web portal and API, with woob bundled
# https://github.com/ltoinel/Carbure
FROM php:8.3-fpm-bookworm

ARG WOOB_VERSION=3.7
# Version of Carbure (the release tag), shown in the portal footer and by /api/health
ARG CARBURE_VERSION=dev

LABEL org.opencontainers.image.title="Carbure" \
      org.opencontainers.image.version="${CARBURE_VERSION}" \
      org.opencontainers.image.description="Household budget web portal and API, bank synchronization with woob" \
      org.opencontainers.image.source="https://github.com/ltoinel/Carbure" \
      org.opencontainers.image.licenses="MIT"

# nginx, PHP extensions (mysqli, APCu) and Python for woob
RUN apt-get update \
    && apt-get install -y --no-install-recommends nginx python3 python3-venv curl ca-certificates \
    && docker-php-ext-install mysqli \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && rm -rf /var/lib/apt/lists/* /tmp/pear \
    && ln -sf /dev/stdout /var/log/nginx/access.log \
    && ln -sf /dev/stderr /var/log/nginx/error.log

# woob and curl_cffi (required by the BNP module) in a virtual environment
# (pip and setuptools of the venv are upgraded: the Debian ones have known vulnerabilities)
RUN python3 -m venv /opt/woob \
    && /opt/woob/bin/pip install --no-cache-dir --upgrade "pip>=26.1.2" "setuptools>=78.1.1" \
    && /opt/woob/bin/pip install --no-cache-dir "woob==${WOOB_VERSION}" "curl_cffi>=0.7" \
    && ln -s /opt/woob/bin/woob /usr/local/bin/woob

# nginx serves the portal and Swagger, PHP-FPM runs the API
COPY docker/nginx.conf /etc/nginx/sites-available/default
COPY docker/php.ini /usr/local/etc/php/conf.d/carbure.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-carbure.conf

WORKDIR /var/www/carbure
COPY --chown=www-data:www-data . .

# Data of the instance in the volume /data, seen by Carbure as data/:
# conf/ (prod.ini written by the installation wizard, certs/), logs/, cache/, woob/
RUN echo "${CARBURE_VERSION#v}" > VERSION \
    && ln -s /data /var/www/carbure/data \
    && chmod +x docker/entrypoint.sh

# Command run by the synchronization to call woob (its settings live in /data)
ENV CARBURE_WOOB_PATH="env HOME=/data/woob woob"

VOLUME /data
EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=60s --retries=3 \
    CMD ["curl", "-fsS", "-o", "/dev/null", "http://localhost/api/health"]

ENTRYPOINT ["/var/www/carbure/docker/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
