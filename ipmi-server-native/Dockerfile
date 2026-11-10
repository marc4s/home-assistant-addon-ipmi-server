# 1. ARGs used in FROM must be at the very top
ARG BUILD_FROM=ghcr.io/hassio-addons/base:21.0.11

# 2. Define the composer stage
FROM composer:2.8.5 AS composer_stage

# 3. Start the main build using the ARG (it is now globally available)
# hadolint ignore=DL3006
FROM ${BUILD_FROM}

# Set environment variables
ENV S6_CMD_WAIT_FOR_SERVICES=1 \
    S6_CMD_WAIT_FOR_SERVICES_MAXTIME=0 \
    S6_SERVICES_GRACETIME=0 \
    LANG=C.UTF-8 \
    APP_DIR="/app"

# Set shell
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Build ipmitool from source without executing cross-compiled binaries during build
# hadolint ignore=DL3003,DL3018
RUN apk add --no-cache ipmitool

# hadolint ignore=DL3018
RUN apk add --no-cache \
        nginx \
        php84 \
        php84-fpm \
        php84-openssl \
        php84-ctype \
        php84-session \
        php84-tokenizer \
        php84-opcache \
        php84-iconv \
        php84-xml && \
    mkdir -p /app/cache /app/var/cache/prod /app/var/log /var/tmp/nginx && \
    chmod -R 777 /app/var/

# Copy root filesystem
COPY rootfs /

# OpenSSL 3 legacy provider for older BMC cipher suites (runtime only).
ENV OPENSSL_CONF="/etc/ssl/ipmitool-openssl.cnf"

# Pull composer binary from the aliased stage
COPY --from=composer_stage /usr/bin/composer /usr/bin/composer

# Install app dependencies and clean up
# hadolint ignore=DL3018
RUN apk add --no-cache \
        php84-phar \
        php84-mbstring && \
    php84 /usr/bin/composer --working-dir /app install && \
    rm -rf /app/var/cache/* && \
    apk del \
        php84-phar \
        php84-mbstring && \
    rm /usr/bin/composer

# Consolidated permissions
RUN if [ -d /app ]; then chown -R nginx /app; fi && \
    chown -R nginx /var/lib/nginx && \
    chmod -R 777 /var/lib/nginx && \
    if [ -d /etc/cont-init.d ]; then chmod -R 755 /etc/cont-init.d; fi && \
    if [ -d /etc/services.d ]; then chmod -R 755 /etc/services.d; fi && \
    if [ -f /entrypoint.sh ]; then chmod 755 /entrypoint.sh; fi

# Build arguments for Hass.io
ARG BUILD_ARCH
ARG BUILD_DATE
ARG BUILD_DESCRIPTION
ARG BUILD_NAME
ARG BUILD_REF
ARG BUILD_REPOSITORY
ARG BUILD_VERSION

# Labels
LABEL \
    io.hass.name="${BUILD_NAME}" \
    io.hass.description="${BUILD_DESCRIPTION}" \
    io.hass.arch="${BUILD_ARCH}" \
    io.hass.type="addon" \
    io.hass.version=${BUILD_VERSION} \
    maintainer="Franck Nijhof <frenck@addons.community>" \
    org.opencontainers.image.title="${BUILD_NAME}" \
    org.opencontainers.image.description="${BUILD_DESCRIPTION}" \
    org.opencontainers.image.vendor="Home Assistant Community Add-ons" \
    org.opencontainers.image.authors="Franck Nijhof <frenck@addons.community>" \
    org.opencontainers.image.licenses="MIT" \
    org.opencontainers.image.url="https://addons.community" \
    org.opencontainers.image.source="https://github.com{BUILD_REPOSITORY}" \
    org.opencontainers.image.documentation="https://github.com{BUILD_REPOSITORY}/blob/main/README.md" \
    org.opencontainers.image.created=${BUILD_DATE} \
    org.opencontainers.image.revision=${BUILD_REF} \
    org.opencontainers.image.version=${BUILD_VERSION}
