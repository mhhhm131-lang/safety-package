# ─────────────────────────────────────────────────────────────
# منظومة السلامة — معهد الإدارة العامة
# صورة واحدة: Laravel (backend/) + صفحات المعهد (HTML كما هي) في public/
# مأخوذة من وصفة OHSMS مع تصحيح أخطائها (BACKEND.md ٧-٤)
# ─────────────────────────────────────────────────────────────

# ── المرحلة ١: الاعتماديات ──
FROM composer:2 AS vendor
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY backend/ .
RUN composer dump-autoload --optimize --classmap-authoritative

# ── المرحلة ٢: التشغيل ──
FROM php:8.4-fpm-alpine AS runtime

RUN apk add --no-cache nginx supervisor bash curl icu-dev libzip-dev oniguruma-dev postgresql-dev sqlite-dev $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pdo_sqlite mbstring bcmath intl opcache zip \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/nginx.conf /etc/nginx/nginx.conf
COPY docker/supervisord.conf /etc/supervisord.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

# صفحات المعهد: تُنسخ كما هي إلى public/ (الأصل في جذر المستودع لا يُمس)
COPY HZ-00-safety-center public/HZ-00-safety-center
COPY HZ-01-basement     public/HZ-01-basement
COPY HZ-02-electrical   public/HZ-02-electrical
COPY HZ-03-hvac         public/HZ-03-hvac
COPY HZ-04-datacenter   public/HZ-04-datacenter
COPY HZ-05-restaurants  public/HZ-05-restaurants
COPY HZ-06-offices      public/HZ-06-offices
COPY HZ-07-halls        public/HZ-07-halls
COPY HZ-08-storage      public/HZ-08-storage
COPY role-cards         public/role-cards
COPY story              public/story
COPY archive            public/archive
COPY *.html support.js  public/

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 CMD curl -fsS http://localhost/up || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
