ARG PHP_IMAGE
FROM node:22.23.4-bookworm-slim@sha256:4b150b48b4116e2a22f2c39cc27025fbf4f153e2078fd3b14d159fdb5c7279da AS node
FROM composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48 AS composer

FROM ${PHP_IMAGE}

COPY .central-apt-source/docker/apt/require-fixed-bookworm-apt.sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY .devcontainer/docker/verify-php-trust.php /usr/local/share/sample-app/verify-php-trust.php
COPY docker/brotli /usr/local/share/sample-app/brotli
COPY docker/install-phpredis.sh /usr/local/bin/install-phpredis

RUN /bin/sh /usr/local/share/sample-app/require-fixed-bookworm-apt.sh \
    && printf 'Acquire::Retries "0";\n' > /etc/apt/apt.conf.d/99-no-retries \
    && php /usr/local/share/sample-app/verify-php-trust.php /usr/local/bin/composer \
    && sh /usr/local/share/sample-app/brotli/install-vendor.sh /usr/local/share/sample-app/brotli \
        git unzip python3 gcc libc6-dev libicu-dev libonig-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j2 bcmath gd intl mbstring pcntl pdo_mysql zip \
    && sh /usr/local/bin/install-phpredis \
    && cc -O2 /usr/local/share/sample-app/brotli/verify-system-brotli.c -lbrotlidec -lbrotlienc -ldl \
        -o /usr/local/share/sample-app/brotli/verify-system-brotli \
    && cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && php /usr/local/share/sample-app/verify-php-trust.php /usr/local/bin/composer \
    && php /usr/local/share/sample-app/brotli/verify-runtime.php \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app
