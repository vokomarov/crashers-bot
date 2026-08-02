FROM ghcr.io/roadrunner-server/roadrunner:2.8.7 AS roadrunner
FROM mwader/static-ffmpeg:8.1.2@sha256:33f770f812cbfc3de96c547157fc9faf8bd95a36481753439ffa761045167585 AS ffmpeg

FROM debian:bookworm-slim AS ytdlp-fetch
RUN apt-get update && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*
ARG YTDLP_VERSION=2026.07.04
WORKDIR /tmp/ytdlp
RUN curl -fL -o yt-dlp_linux \
      "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/yt-dlp_linux" \
    && curl -fL -o SHA2-256SUMS \
      "https://github.com/yt-dlp/yt-dlp/releases/download/${YTDLP_VERSION}/SHA2-256SUMS" \
    && grep "yt-dlp_linux$" SHA2-256SUMS | sha256sum -c - \
    && chmod +x yt-dlp_linux \
    && mv yt-dlp_linux /usr/local/bin/yt-dlp

FROM php:8.4.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends \
  apt-transport-https build-essential nano libzip-dev libonig-dev unzip \
  libjpeg62-turbo-dev libpng-dev libwebp-dev
RUN docker-php-ext-configure gd --with-jpeg --with-webp \
  && docker-php-ext-install zip mbstring pdo_mysql mysqli sockets pcntl gd
RUN pecl install --onlyreqdeps --force redis && rm -rf /tmp/pear && docker-php-ext-enable redis
RUN apt-get clean && rm -rf /var/lib/apt/lists/*
COPY --from=roadrunner /usr/bin/rr /usr/local/bin/rr
COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=ffmpeg /ffmpeg /ffprobe /usr/local/bin/
COPY --from=ytdlp-fetch /usr/local/bin/yt-dlp /usr/local/bin/yt-dlp
RUN chmod +x /usr/local/bin/ffmpeg /usr/local/bin/ffprobe /usr/local/bin/yt-dlp
WORKDIR /app
COPY composer.json /app
COPY composer.lock /app
RUN composer install --ignore-platform-reqs --no-scripts -n --no-dev --no-cache --no-ansi --no-autoloader --no-scripts --prefer-dist
COPY . /app
RUN composer dump-autoload -n --optimize
RUN groupadd -r app && useradd -r -g app -d /app app \
    && chown -R app:app /app \
    && chown app:app /run
USER app
EXPOSE 8090
ENTRYPOINT [ "rr", "serve", "-c", "/app/.rr.yaml" ]