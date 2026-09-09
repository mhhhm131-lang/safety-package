<?php

namespace Tests\Feature\Closeout;

use App\Core\Services\MailHealthService;
use App\Core\Services\NotificationService;
use App\Models\User;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * المرحلة ٨-٢ — قناة البريد.
 *
 * الخلل الأصلي: بلا `MAIL_MAILER` يقع Laravel على `log` فتُكتب الرسالة في السجل ولا تصل
 * أحداً **بلا خطأ ظاهر**. الاختبارات هنا تثبّت أن الحال تُقال صراحةً، وأن الفشل يُسجَّل.
 */
class MailHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $fani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani   = $this->user('fani', 'field_worker');
    }

    private function user(string $username, string $role): User
    {
        $u = User::create([
            'username' => $username, 'name' => "اسم {$username}",
            'password' => '123456', 'email' => "{$username}@example.test",
        ]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);

        return $u;
    }

    // ════════════ الحالة ════════════

    public function test_log_mailer_is_reported_as_not_delivering(): void
    {
        config(['mail.default' => 'log']);

        $status = app(MailHealthService::class)->status();

        $this->assertFalse($status['delivers']);
        $this->assertFalse($status['ready']);
        $this->assertStringContainsString('لا يوصل بريداً', $status['reason']);
    }

    public function test_smtp_without_host_is_not_ready(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.from.address' => 'a@b.test']);

        $status = app(MailHealthService::class)->status();

        $this->assertTrue($status['delivers']);
        $this->assertFalse($status['ready']);
        $this->assertStringContainsString('SMTP', $status['reason']);
    }

    public function test_smtp_without_from_address_is_not_ready(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => '']);

        $status = app(MailHealthService::class)->status();

        $this->assertFalse($status['ready']);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS', $status['reason']);
    }

    public function test_configured_smtp_is_ready(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'safety@ipa.test']);

        $status = app(MailHealthService::class)->status();

        $this->assertTrue($status['ready']);
        $this->assertNull($status['reason']);
    }

    // ════════════ رسالة الاختبار ════════════

    public function test_test_message_refuses_on_a_dead_mailer(): void
    {
        config(['mail.default' => 'log']);

        [$ok, $message] = app(MailHealthService::class)->sendTest('someone@example.test');

        $this->assertFalse($ok);
        $this->assertStringContainsString('لا يوصل بريداً', $message);
        $this->assertNotNull(Setting::get(MailHealthService::KEY_LAST_ERROR));
    }

    public function test_test_message_is_sent_and_recorded(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'safety@ipa.test']);
        Mail::fake();

        [$ok, $message] = app(MailHealthService::class)->sendTest('someone@example.test', $this->salama->id);

        $this->assertTrue($ok);
        $this->assertStringContainsString('someone@example.test', $message);
        $this->assertNotNull(Setting::get(MailHealthService::KEY_LAST_OK));
        $this->assertSame('someone@example.test', Setting::get(MailHealthService::KEY_LAST_TO));
        $this->assertNull(Setting::get(MailHealthService::KEY_LAST_ERROR));
    }

    public function test_a_successful_send_clears_the_previous_error(): void
    {
        Setting::set(MailHealthService::KEY_LAST_ERROR, 'عطل قديم');
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'safety@ipa.test']);
        Mail::fake();

        app(MailHealthService::class)->sendTest('someone@example.test');

        $this->assertNull(Setting::get(MailHealthService::KEY_LAST_ERROR));
    }

    // ════════════ الفشل يُسجَّل لا يُبتلع ════════════

    public function test_notification_failure_is_recorded_and_does_not_break_the_inbox(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.invalid', 'mail.from.address' => 'safety@ipa.test']);

        // مرسل يرمي عند الإرسال
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('اتصال مرفوض'));

        $notification = app(NotificationService::class)
            ->create($this->fani->id, 'test', 'عنوان الإشعار', 'نصه');

        $this->assertNotNull($notification->id, 'صندوق الوارد لا يسقط بسقوط البريد');
        $this->assertStringContainsString('اتصال مرفوض', (string) Setting::get(MailHealthService::KEY_LAST_ERROR));
    }

    // ════════════ الشاشة ════════════

    public function test_screen_requires_settings_permission(): void
    {
        $this->actingAs($this->fani)->get(route('app.mail.index'))->assertForbidden();
        $this->actingAs($this->salama)->get(route('app.mail.index'))->assertOk();
    }

    public function test_screen_says_plainly_when_mail_is_down(): void
    {
        config(['mail.default' => 'log']);

        $this->actingAs($this->salama)->get(route('app.mail.index'))
            ->assertOk()
            ->assertSee('data-mail-state="down"', false)
            ->assertSee('البريد لا يصل أحداً');
    }

    public function test_screen_says_ready_when_configured(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'safety@ipa.test']);

        $this->actingAs($this->salama)->get(route('app.mail.index'))
            ->assertOk()
            ->assertSee('data-mail-state="ready"', false);
    }

    public function test_test_form_validates_the_address(): void
    {
        $this->actingAs($this->salama)
            ->post(route('app.mail.test'), ['to' => 'ليس بريداً'])
            ->assertSessionHasErrors('to');
    }

    public function test_test_form_sends(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.test', 'mail.from.address' => 'safety@ipa.test']);
        Mail::fake();

        $this->actingAs($this->salama)
            ->post(route('app.mail.test'), ['to' => 'someone@example.test'])
            ->assertSessionHas('success');
    }
}
