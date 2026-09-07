# backend/ — خلفية منظومة السلامة

اقرأ `../BACKEND.md` أولاً (البند ٠ الفهم، ثم ٧-٣ سجل التنفيذ)، والمهارات `/backend` `/on-track` `/verify` في `../.claude/commands/`.

- Laravel 13، PHP ^8.3. محلياً SQLite، على Render Postgres.
- ملفات المعهد في المجلد الأعلى لا تُمس. تُنسخ إلى `public/` بالأمر `php artisan ipa:sync-site`.
- الربط الوحيد بالمعهد: `public/ipa-store.js` + جدول `institute_documents`.
- وحدات OHSMS تُنقل إلى `app/Modules/` بطريقة النقل الموحدة في BACKEND.md ٧-١.
