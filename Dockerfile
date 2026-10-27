FROM php:8.2-cli

WORKDIR /app

RUN apt-get update && apt-get install -y \
    libpq-dev \
    unzip \
    curl \
    git \
    && docker-php-ext-install pdo_pgsql \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . /app

RUN cd /app/backend && composer install --no-interaction --prefer-dist --no-progress

EXPOSE 8000

CMD ["sh", "-c", "cd /app/backend && php -S 0.0.0.0:${PORT:-8000} -t public"]
