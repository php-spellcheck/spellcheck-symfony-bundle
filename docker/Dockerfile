# Development image: PHP plus every spell checking backend and the dictionaries
# the integration tests need.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libonig-dev \
        libpspell-dev \
        aspell \
        aspell-it \
        aspell-en \
        aspell-de \
        aspell-fr \
        hunspell \
        hunspell-it \
        hunspell-en-us \
        hunspell-en-gb \
        hunspell-de-de \
        hunspell-fr \
    && docker-php-ext-install -j"$(nproc)" mbstring \
    # pspell left php-src in 8.4 and is only on PECL since then.
    && if [ -d /usr/src/php/ext/pspell ]; then \
           docker-php-ext-install -j"$(nproc)" pspell; \
       else \
           pecl install pspell && docker-php-ext-enable pspell; \
       fi \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

WORKDIR /app

# Sanity check at build time: hunspell -D writes to stderr and waits on stdin,
# hence the redirection and the empty input.
RUN hunspell -D < /dev/null 2>&1 | tail -20
