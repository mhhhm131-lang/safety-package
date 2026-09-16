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

    /** ١٧-٢: الغلاف — زر الدخول إلى /login، وعند جلسة قائمة يفتح اللوحة */
    public function test_cover_login_button_opens_login(): void
    {
        $i = $this->file('index.html');
        $this->assertStringContainsString('id="enterBtn" href="/login"', $i, 'زر الغلاف ما زال يفتح اللوحة مباشرة');
        $this->assertStringNotContainsString('id="enterBtn" href="dashboard.html"', $i);
        $this->assertStringContainsString("enterBtn').href='dashboard.html'", $i, 'الجلسة القائمة لا تعيد الزر إلى اللوحة');
    }
}
