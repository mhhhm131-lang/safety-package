<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * المرحلة ١٧ «باب واحد» (قرار ٤٥، ٢٠٢٦-٠٩-١٦): حارس على ملفَي المعهد الرؤية والغلاف —
 * الرؤية فيها دخول المنظومة وبلاغ الخطر والوثائق، وزر الغلاف يفتح /login لا اللوحة.
 */
class EntryDoorTest extends TestCase
{
    private function file(string $name): string
    {
        $path = dirname(base_path()).'/'.$name;
        if (!is_file($path)) $this->markTestSkipped("ملف المعهد غير موجود: $name");
        return file_get_contents($path);
    }

    /** ١٧-١: الرؤية — ثلاثة مخارج: دخول، بلاغ بلا دخول، وثائق */
    public function test_vision_has_login_report_and_documents(): void
    {
        $v = $this->file('vision.html');
        $this->assertStringNotContainsString('<a href="index.html" class="enter-btn"', $v, 'زر الرؤية ما زال يفتح الغلاف بدل الدخول');
        $this->assertMatchesRegularExpression('~<a href="/login"[^>]*class="enter-btn[^"]*"~', $v, 'لا زر دخول إلى /login في الرؤية');
        $this->assertMatchesRegularExpression('~<a href="/incident"[^>]*>~', $v, 'لا زر «أبلغ عن خطر» في الرؤية');
        $this->assertMatchesRegularExpression('~<a href="index.html"[^>]*>[^<]*الوثائق~u', $v, 'لا رابط «الوثائق» إلى الغلاف في الرؤية');
    }

    /**
     * قرار ٨٥ (بكلمته ٢٠٢٦-١٠-١١): صفحة الوثائق وثائق فقط — لا زر دخول ولا زر بلاغ فيها؛
     * الدخول للمنظومة وبلاغ الخطر من صفحة الرؤية وحدها (تبقى كما في ١٧-١)، ومن لديه جلسة يحوّله /login إلى /app.
     * (نسخ ١٧-٢ و١٩-٧ السابقة: زر الغلاف إلى /login والجلسة القائمة تفتح المنظومة.)
     */
    public function test_documents_page_has_no_login_or_report_buttons(): void
    {
        $i = $this->file('index.html');
        $this->assertStringNotContainsString('href="/login"', $i, 'صفحة الوثائق ما زالت تعرض زر الدخول');
        $this->assertStringNotContainsString('href="/incident"', $i, 'صفحة الوثائق ما زالت تعرض زر البلاغ');
        $this->assertStringNotContainsString('enterBtn', $i);
        $this->assertStringNotContainsString('href="dashboard.html"', $i);
        // الأقسام الثلاثة باقية، والعودة إلى الرؤية
        $this->assertStringContainsString('الوثائق الرئيسية', $i);
        $this->assertStringContainsString('role-cards/index.html', $i);
        $this->assertStringContainsString('href="vision.html"', $i);
        // النسخة المنشورة مطابقة للأصل
        $this->assertSame($i, $this->file('backend/public/index.html'), 'نسخة public من صفحة الوثائق تختلف عن الأصل');
    }
}
