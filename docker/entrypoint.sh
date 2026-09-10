#!/bin/sh
set -e
cd /var/www/html

echo "▶ منظومة السلامة — بدء التشغيل"

# مفتاح التطبيق: لا يُكتب في المستودع. Render يولّد قيمة عشوائية في APP_KEY،
# ونشتق منها مفتاح AES-256 ثابتاً (لا يتغير بين التشغيلات فلا تسقط الجلسات).
if [ -z "$APP_KEY" ]; then
  echo "  ✗ APP_KEY غير محدد"; exit 1
fi
case "$APP_KEY" in
  base64:*) ;;
  *) export APP_KEY="$(php -r 'echo "base64:".base64_encode(hash("sha256", getenv("APP_KEY"), true));')" ;;
esac

# انتظار قاعدة البيانات
if [ -n "$DB_URL" ] || [ -n "$DB_HOST" ]; then
  for i in $(seq 1 30); do
    if php artisan db:monitor --max=0 >/dev/null 2>&1 || php -r 'exit(0);'; then break; fi
    sleep 1
  done
fi

if [ "$APP_ENV" = "production" ]; then
  php artisan config:cache --no-interaction
  php artisan route:cache --no-interaction
  php artisan view:cache --no-interaction
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
  echo "  الترحيلات..."
  php artisan migrate --force --no-interaction
  echo "  البيانات المرجعية (الأماكن، الهيكل)..."
  php artisan db:seed --force --no-interaction
  echo "  خطط الاستجابة من الوثائق الثماني..."
  php artisan ipa:sync-plans --no-interaction || true
fi

# حساب مسؤول السلامة الأول في الإنتاج: من متغيرات البيئة (تُضبط في Render مرة واحدة ثم تُحذف).
if [ -n "$IPA_ADMIN_USERNAME" ] && [ -n "$IPA_ADMIN_PASSWORD" ]; then
  echo "  حساب مسؤول السلامة: $IPA_ADMIN_USERNAME"
  php artisan ipa:user "$IPA_ADMIN_USERNAME" system_admin "${IPA_ADMIN_NAME:-مسؤول السلامة}" --password="$IPA_ADMIN_PASSWORD" --no-interaction
fi

# لا حساب افتراضي في الإنتاج. الحسابات التجريبية للتطوير فقط.
if [ "$SEED_DEMO" = "true" ]; then
  echo "  الحسابات التجريبية..."
  php artisan db:seed --class=DemoUsersSeeder --force --no-interaction
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# مجلدات nginx المؤقتة (قد يكون /tmp فارغاً عند التشغيل) ورقم البناء للتحقق من النشر
mkdir -p /tmp/nginx/client-body /tmp/nginx/fastcgi /tmp/nginx/proxy && chown -R www-data:www-data /tmp/nginx
echo "${RENDER_GIT_COMMIT:-local}" > public/build.txt

echo "▶ جاهز"
exec "$@"
