<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * المرحلة ١٦ «التنظيف» (قرار ٤٤): حارس دائم على ملفات المعهد — يقرأ المصدر في المجلد الأعلى
 * (كما ResponsePlanTest يقرأ وثائق الخطط) ويمنع عودة ما حُذف: كلمات المرور المعروضة، ومسار الاستيراد
 * المدمِّر، والكود الميت. ولا يحذف الحارسُ ما يجب بقاؤه: عنصر شاشة النظام `#login` ودوال الحفظ.
 */
class InstituteFilesTest extends TestCase
{
    private function dashboard(): string
    {
        $path = dirname(base_path()).'/dashboard.html';
        if (!is_file($path)) $this->markTestSkipped('ملفات المعهد غير موجودة');
        return file_get_contents($path);
    }

    /** الدفعة ١: لا كلمات مرور ولا جدول حسابات في اللوحة */
    public function test_dashboard_has_no_demo_credentials(): void
    {
        $html = $this->dashboard();
        $this->assertStringNotContainsString('class="lusers"', $html, 'جدول الحسابات وكلماتها ما زال في اللوحة');
        $this->assertStringNotContainsString('بيانات الدخول معروضة عمداً', $html);
        $this->assertStringNotContainsString("p:'1234'", $html, 'كلمات المرور ما زالت في USERS');
        $this->assertStringNotContainsString('placeholder="1234"', $html);
        $this->assertStringNotContainsString('واجهة عرض.', $html);
    }

    /** الدفعة ١: دور الخادم هو المعتمد، ولا يطغى عليه دور تجريبي بالاسم نفسه */
    public function test_dashboard_session_takes_server_role(): void
    {
        $this->assertStringNotContainsString('if(s&&s.r&&!USERS[s.u])', $this->dashboard(),
            'الجلسة ما زالت تُهمل دور الخادم حين يطابق الاسم حساباً تجريبياً');
    }

    /** الدفعة ٢: لا مسار استيراد يستبدل بلاغات النماذج — الزر والمدخل والمعالج تُحذف معاً */
    public function test_dashboard_has_no_file_import_path(): void
    {
        $html = $this->dashboard();
        $this->assertStringNotContainsString('fpick', $html, 'مدخل الاستيراد أو معالجه ما زال في اللوحة');
        $this->assertStringNotContainsString('استيراد جولات', $html);
    }

    /** الدفعة ٣: لا كود ميت لبلاغات الشاغلين في اللوحة — والحيّ منه يبقى */
    public function test_dashboard_has_no_dead_occupant_code(): void
    {
        $html = $this->dashboard();
        foreach (['function occRow', 'function occAssign', 'function occClose', 'function occImg', 'const OST', 'function occSave'] as $dead) {
            $this->assertStringNotContainsString($dead, $html, "كود ميت باقٍ: $dead");
        }
        foreach (['function occAll', 'function occState', 'function occLinked', 'occOpen', 'function drawOcc'] as $live) {
            $this->assertStringContainsString($live, $html, "حُذف ما هو حيّ: $live");
        }
    }

    /** الدفعة ٤: نافذة الإدارات لا تقول إن الهيكل يُحفظ على الجهاز — الجدول في الخادم هو الأصل (StoreController:119-126) */
    public function test_dashboard_org_window_says_server_is_the_source(): void
    {
        $html = $this->dashboard();
        $this->assertStringNotContainsString('يُحفظ في الهيكل على هذا الجهاز', $html, 'النص القديم يخالف أن الخادم هو الأصل');
        $this->assertStringContainsString('يُحفظ في خادم المنظومة', $html);
    }

    /** النماذج العشرة كما تُخدَم من المجلد الأعلى */
    private function forms(): array
    {
        $root = dirname(base_path());
        $files = glob($root.'/HZ-*/inspection-form.html');
        $fire = $root.'/HZ-00-safety-center/fire-inspection.html';
        if (is_file($fire)) $files[] = $fire;
        if (count($files) !== 10) $this->markTestSkipped('النماذج العشرة غير مكتملة: '.count($files));
        return $files;
    }

    /** الدفعة ٥: لا كود «زمن الملفات» في النماذج العشرة — ولا يُمسّ ما يحفظ على الخادم */
    public function test_ten_forms_have_no_file_era_code(): void
    {
        foreach ($this->forms() as $file) {
            $html = file_get_contents($file);
            $name = basename(dirname($file)).'/'.basename($file);
            foreach (['impFile', 'saveSelf', 'expFile', "id=\"fpick\""] as $dead) {
                $this->assertStringNotContainsString($dead, $html, "كود «زمن الملفات» باقٍ في $name: $dead");
            }
            foreach (['function loadSelf', 'function snapshot', 'id="SAVED"'] as $live) {
                $this->assertStringContainsString($live, $html, "حُذف ما هو حيّ في $name: $live");
            }
        }
    }

    /** ما يجب ألا يُحذف: شاشة رسالة النظام ودوال الدخول بالجلسة */
    public function test_dashboard_keeps_system_gate_and_session_entry(): void
    {
        $html = $this->dashboard();
        $this->assertStringContainsString('id="login"', $html, 'عنصر رسالة النظام في ipa-store.js يعتمد عليه');
        $this->assertStringContainsString('function enter(', $html);
        $this->assertStringContainsString('function logout(', $html);
    }
}
