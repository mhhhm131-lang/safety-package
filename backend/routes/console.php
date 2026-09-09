<?php

use Illuminate\Support\Facades\Schedule;

// البند ج (BACKEND.md ٥-٢-ب): مؤقت مهل بلاغ الشاغل في الخادم، كل دقيقة. يعمل عبر program:scheduler في supervisord.
Schedule::command('incidents:check-deadlines')->everyMinute()->withoutOverlapping();

// المرحلة ٤ (BACKEND.md ٥-٣): التصعيد الآلي للحالات الطارئة النشطة كل دقيقة — المهل من شاشة الإعدادات بلا قيم افتراضية.
Schedule::command('emergency:check-escalation')->everyMinute()->withoutOverlapping();

// المرحلة ٦-ب (BACKEND.md ٥-٦): إنهاء صلاحية التصاريح وتأهيلات المقاولين التي مضى تاريخها — مرة يومياً.
Schedule::command('permits:expire-overdue')->dailyAt('00:10')->withoutOverlapping();

// المرحلة ٧ (BACKEND.md ٥-٨): تكليفات النماذج التي تجاوزت مهلتها تصير «متأخرة» ويُنبَّه أصحابها — مرة يومياً.
Schedule::command('forms:check-overdue')->dailyAt('07:00')->withoutOverlapping();
