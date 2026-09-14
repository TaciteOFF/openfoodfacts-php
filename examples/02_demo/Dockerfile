# Production image for the openfoodfacts-php demo.
#
# Apache + mod_php, with `public/` as the document root and the rest of the project outside the
# web root - exactly the layout the README asks for. Nothing in the application code changes to
# run here: PHP sessions, the 5 MB photo upload and the per-session rate limiter all behave as
# they do locally, because this is a real long-lived PHP process and not a serverless function.
#
# Build:  docker build -t off-lab .
# Run:    docker run --rm -p 8080:8080 -e OFF_USER_AGENT="My demo - Web - 1.0 - https://…" off-lab
#
# The server listens on $PORT (default 8080), which is what Fly.io, Render and Railway inject.

# ---- Stage 1: Composer dependencies -----------------------------------------------------------
# Built on the same PHP version as the runtime stage, so dependency resolution sees the exact
# platform the code will run on. git and unzip are needed because the wrapper is installed from
# its VCS repository (the TaciteOFF fork), pinned by composer.lock.
FROM php:8.3-apache AS deps
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader

# ---- Stage 2: runtime -------------------------------------------------------------------------
FROM php:8.3-apache

# fileinfo and json are compiled into the official image; nothing else is required.
COPY docker/php.ini /usr/local/etc/php/conf.d/off-lab.ini
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/off-lab-entrypoint

# A global ServerName keeps Apache from warning about the FQDN on every start; the public
# hostname is the platform's, not the container's.
RUN printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# Private session storage, owned by the web user, matching session.save_path in php.ini.
RUN chmod +x /usr/local/bin/off-lab-entrypoint \
 && mkdir -p /var/lib/php/sessions \
 && chown www-data:www-data /var/lib/php/sessions \
 && chmod 700 /var/lib/php/sessions

WORKDIR /var/www/html
COPY app app
COPY public public
COPY composer.json composer.lock LICENSE ./
COPY --from=deps /app/vendor vendor

ENV PORT=8080
EXPOSE 8080
ENTRYPOINT ["off-lab-entrypoint"]
CMD ["apache2-foreground"]
