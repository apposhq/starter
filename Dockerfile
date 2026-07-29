# syntax=docker/dockerfile:1

FROM composer:2 AS composer

FROM dunglas/frankenphp:1.12.6-php8.5-bookworm AS base
RUN install-php-extensions pcntl pdo_pgsql intl opcache
WORKDIR /app

# zip serves Composer's --prefer-dist extraction only, so it stays out of the runtime.
FROM base AS vendor
RUN install-php-extensions zip
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY composer.json composer.lock ./
RUN --mount=type=cache,target=/tmp/composer \
    COMPOSER_HOME=/tmp/composer composer install \
      --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress
COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
      storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chmod -R ug+w storage bootstrap/cache \
 && composer dump-autoload --no-dev --classmap-authoritative \
 && php artisan package:discover --ansi

# From vendor because Wayfinder's Vite plugin shells out to `php artisan wayfinder:generate`.
FROM vendor AS assets
ARG TARGETARCH
# musl: the glibc build needs 2.39, bookworm ships 2.36.
RUN curl -fsSL "https://github.com/jdx/mise/releases/download/v2026.7.15/mise-v2026.7.15-linux-$(echo ${TARGETARCH} | sed s/amd64/x64/)-musl.tar.gz" \
    | tar -xz -C /usr/local --strip-components=1 mise/bin/mise
ENV MISE_DATA_DIR=/mise MISE_TRUSTED_CONFIG_PATHS=/app PATH=/mise/shims:$PATH
COPY mise.toml mise.lock ./
# --locked resolves from mise.lock's pinned URLs, keeping the build off the GitHub API.
RUN mise install --locked bun node
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile
RUN ./node_modules/.bin/vp build && ./node_modules/.bin/vp build --ssr

FROM base AS runtime
COPY --from=assets /mise/installs/bun/latest/bin/bun /usr/local/bin/bun
RUN apt-get update && apt-get install -y --no-install-recommends supervisor && rm -rf /var/lib/apt/lists/*
ENV APP_ENV=production PORT=8000 OCTANE_WORKERS=auto OCTANE_MAX_REQUESTS=500
COPY --from=vendor /app /app
COPY --from=assets /app/public/build ./public/build
COPY --from=assets /app/bootstrap/ssr ./bootstrap/ssr

# One deployable, so app and renderer cannot drift. The healthcheck probes both: a dead renderer must
# fail the container rather than silently degrade every page to client rendering.
RUN cat > /etc/supervisor/conf.d/app.conf <<'CONF'
[supervisord]
nodaemon=true
logfile=/dev/null
pidfile=/tmp/supervisord.pid

[program:octane]
command=php /app/artisan octane:start --server=frankenphp --host=0.0.0.0 --port=%(ENV_PORT)s --workers=%(ENV_OCTANE_WORKERS)s --max-requests=%(ENV_OCTANE_MAX_REQUESTS)s
stopwaitsecs=30
autorestart=true
redirect_stderr=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0

[program:ssr]
command=bun /app/bootstrap/ssr/app.js
stopwaitsecs=10
autorestart=true
redirect_stderr=true
stdout_logfile=/dev/stdout
stdout_logfile_maxbytes=0
CONF

EXPOSE 8000
HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=5 \
  CMD php -r "exit(@file_get_contents('http://127.0.0.1:'.(getenv('PORT') ?: '8000').'/up') !== false && @file_get_contents('http://127.0.0.1:13714/health') !== false ? 0 : 1);"
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
