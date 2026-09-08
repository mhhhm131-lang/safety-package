<?php

use Illuminate\Support\Facades\Schedule;

// البند ج (BACKEND.md ٥-٢-ب): مؤقت مهل بلاغ الشاغل في الخادم، كل دقيقة. يعمل عبر program:scheduler في supervisord.
Schedule::command('incidents:check-deadlines')->everyMinute()->withoutOverlapping();

// المرحلة ٤ (BACKEND.md ٥-٣): التصعيد الآلي للحالات الطارئة النشطة كل دقيقة — المهل من شاشة الإعدادات بلا قيم افتراضية.
Schedule::command('emergency:check-escalation')->everyMinute()->withoutOverlapping();
