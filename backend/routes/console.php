<?php

use Illuminate\Support\Facades\Schedule;

// البند ج (BACKEND.md ٥-٢-ب): مؤقت مهل بلاغ الشاغل في الخادم، كل دقيقة. يعمل عبر program:scheduler في supervisord.
Schedule::command('incidents:check-deadlines')->everyMinute()->withoutOverlapping();
