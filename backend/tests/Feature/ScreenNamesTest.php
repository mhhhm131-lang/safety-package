<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * المرحلة ١٦ الدفعة ٧ (قرار ٤٤): لكل شاشة اسم واحد بكلمة المستخدم (٢٠٢٦-٠٩-١٦):
 * اللوحة «العمل اليومي» · شاشة الفرق «الفريق الأولي» · سجل الشاغلين «سجل مركز السلامة».
 * الاسم وحده يتغيّر؛ «بلاغات الشاغلين» نوعاً مقابل «بلاغات الفحص» يبقى (قرار ٢٠٢٦-٠٩-١٣)، والمفاتيح البرمجية لا تُمس.
 */
class ScreenNamesTest extends TestCase
{
    private function src(string $rel): string
    {
        $path = str_starts_with($rel, '../') ? dirname(base_path()).'/'.substr($rel, 3) : base_path($rel);
        if (!is_file($path)) $this->markTestSkipped("الملف غير موجود: $rel");
        return file_get_contents($path);
    }

    private function names(array $files, array $old, string $new): void
    {
        foreach ($files as $f) {
            $s = $this->src($f);
            foreach ($old as $o) $this->assertStringNotContainsString($o, $s, "اسم قديم «{$o}» باقٍ في $f");
            $this->assertStringContainsString($new, $s, "الاسم الموحّد «{$new}» غائب عن $f");
        }
    }

    public function test_dashboard_is_named_daily_work(): void
    {
        $this->names(['app/Core/Intents/IntentRegistry.php', '../dashboard.html'],
            ['صورة المبنى واللوحة', 'لوحة البلاغات والتصعيد'], 'العمل اليومي');
    }

    public function test_teams_screen_is_named_initial_team(): void
    {
        $this->names([
            'app/Core/Intents/IntentRegistry.php',
            'app/Modules/Emergency/Inbox/TeamGapTasks.php',
            'resources/views/modules/emergency/teams/index.blade.php',
        ], ['فرق الطوارئ', "'الفرق الأولية'"], 'الفريق الأولي');

        $this->assertStringContainsString('<i class="bi bi-people-fill"></i>الفريق الأولي</a>', $this->src('resources/views/layouts/app.blade.php'));
        $this->assertStringContainsString('>الفريق الأولي</a>', $this->src('resources/views/modules/emergency/teams/show.blade.php'));
        $this->assertStringContainsString("['الفريق الأولي', ", $this->src('app/Modules/Governance/Controllers/SettingsController.php'));
    }

    public function test_occupant_log_is_named_safety_center_log(): void
    {
        $this->names([
            'app/Core/Intents/IntentRegistry.php',
            'resources/views/modules/incidents/dashboard.blade.php',
            'resources/views/modules/incidents/detail.blade.php',
        ], ["'سجل البلاغات'", "'بلاغات الشاغلين')", '>بلاغات الشاغلين<'], 'سجل مركز السلامة');

        $this->assertStringContainsString('<h2>سجل مركز السلامة</h2>', $this->src('../dashboard.html'));
    }

    /** النوع يبقى: «ما ينتظرك» والبحث يفرّقان بلاغات الشاغلين عن بلاغات الفحص */
    public function test_occupant_type_label_stays(): void
    {
        $this->assertStringContainsString("module: 'بلاغات الشاغلين'", $this->src('app/Modules/Incident/Inbox/IncidentTasks.php'));
        $this->assertStringContainsString("\$add('بلاغات الشاغلين'", $this->src('app/Modules/Governance/Controllers/SearchController.php'));
    }
}
