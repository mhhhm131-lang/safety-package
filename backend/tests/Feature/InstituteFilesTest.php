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

    /** ما يجب ألا يُحذف: شاشة رسالة النظام ودوال الدخول بالجلسة */
    public function test_dashboard_keeps_system_gate_and_session_entry(): void
    {
        $html = $this->dashboard();
        $this->assertStringContainsString('id="login"', $html, 'عنصر رسالة النظام في ipa-store.js يعتمد عليه');
        $this->assertStringContainsString('function enter(', $html);
        $this->assertStringContainsString('function logout(', $html);
    }
}
