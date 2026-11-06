FROM node:22-alpine AS panel

WORKDIR /build
COPY panel/package.json panel/package-lock.json ./panel/
RUN npm --prefix panel ci
COPY panel ./panel
RUN npm --prefix panel run build

FROM phpswoole/swoole:php8.2-alpine AS runtime

RUN php -r 'if (version_compare(PHP_VERSION, "8.2.35", "<")) { \
    fwrite(STDERR, "PHP >= 8.2.35 is required for security fixes; found " . PHP_VERSION . "\n"); exit(1); \
}'

COPY --from=mlocati/php-extension-installer:2.12.3 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.10.3 /usr/bin/composer /usr/local/bin/composer

# Install these extensions separately to preserve the ARM64 compiler workarounds.
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath

RUN IPE_PROCESSOR_COUNT=2 install-php-extensions zip

# Keep Redis independent of Alpine's package version to preserve RDB 14 compatibility.
COPY --from=redis:8-alpine /usr/local/bin/redis-* /usr/local/bin/

RUN apk --no-cache add \
        shadow \
        sqlite \
        mysql-client \
        mysql-dev \
        mariadb-connector-c \
        supervisor \
        libssl3 \
        libcrypto3 \
        caddy && \
    redis-server --version && \
    redis-cli --version && \
    addgroup -S -g 1000 www && \
    adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis) && \
    php -r 'foreach (["swoole", "pcntl", "posix", "bcmath", "pdo_mysql", "pdo_sqlite", "sockets", "zip", "igbinary", "redis"] as $extension) { \
        if (!extension_loaded($extension)) { fwrite(STDERR, "Missing PHP extension: {$extension}\n"); exit(1); } \
    }'

FROM runtime AS app

WORKDIR /www

# Build the reviewed checkout, including the panel generated from panel/src.
# Composer installs the checked-in lock; no source checkout happens in the image.
COPY . /www
COPY --from=panel /build/public/assets/panel /www/public/assets/panel

ARG APP_VERSION=local
ARG VCS_REF=unknown
ENV APP_VERSION=${APP_VERSION}
LABEL org.opencontainers.image.title="SimpleXboard" \
      org.opencontainers.image.source="https://github.com/CoderDoubleflower/SimpleXboard" \
      org.opencontainers.image.revision="${VCS_REF}"

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN test -f composer.lock && \
    test -f panel/package-lock.json && \
    test -f public/assets/panel/manifest.json && \
    mkdir -p storage/app/public storage/backup storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs/supervisor bootstrap/cache .docker/.data /data && \
    composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader && \
    php artisan storage:link && \
    chown -R www:www /www && \
    chmod -R u=rwX,g=rX,o=rX /www && \
    chmod -R ug=rwX storage bootstrap/cache .docker/.data && \
    chown redis:redis /data

ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
