FROM ghcr.io/roadrunner-server/roadrunner:2.8.7 AS roadrunner

FROM php:8.4.3-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
  apt-transport-https \
  build-essential \
  nano \
  libzip-dev \
  libonig-dev \
  unzip

# Install PHP Extensions
RUN docker-php-ext-install zip mbstring pdo_mysql mysqli sockets pcntl

# Clear cache
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json /app
COPY composer.lock /app

RUN composer install --ignore-platform-reqs --no-scripts -n --no-dev --no-cache --no-ansi --no-autoloader --no-scripts --prefer-dist

COPY . /app

RUN composer dump-autoload -n --optimize

EXPOSE 8090

ENTRYPOINT [ "rr", "serve", "-c", "/app/.rr.yaml" ]
