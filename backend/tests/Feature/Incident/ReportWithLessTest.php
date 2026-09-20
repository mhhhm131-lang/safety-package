<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentClosureService;
use App\Modules\Incident\Services\IncidentService;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢١-٧: الشاغل يبلغ بأقل — لا يُسأل عمّا يعرفه النظام أو الجهاز.
 * المكان: من الرمز (QR) ثم من حسابه ثم آخر مكان على الجهاز؛ رمز التتبع يُحفظ في المتصفح فلا يُكتب؛ رفض الإغلاق بخيارات جاهزة.
 */
class ReportWithLessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
    }

    private function user(string $username, string $role, array $profile = []): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true] + $profile);
        return $u;
    }

    private function selectedPlace(string $html): ?string
    {
        return preg_match('/<option value="(\d+)"\s+selected[^>]*>\s*(HZ-\d+)/u', $html, $m) ? $m[2] : null;
    }

    public function test_place_comes_from_the_code_then_from_the_account_then_from_the_device(): void
    {
        // ضيف بلا رمز: لا مكان محدد، والصفحة تعرف كيف تقرأ آخر مكان من الجهاز
        $h = $this->get('/incident/normal')->assertOk()->getContent();
        $this->assertNull($this->selectedPlace($h));
        $this->assertStringContainsString('ipa-last-place', $h);

        // موظف إدارته في المكاتب: مكانه محدد بلا سؤال؛ والرمز يغلب الحساب
        $emp = $this->user('emp', 'employee', ['organization_unit_id' => OrganizationUnit::where('code', 'fin')->value('id')]);
        $this->assertSame('HZ-06', $this->selectedPlace($this->actingAs($emp)->get('/incident/normal')->assertOk()->getContent()));
        $this->assertSame('HZ-02', $this->selectedPlace($this->actingAs($emp)->get('/incident/normal?place=HZ-02')->assertOk()->getContent()));
    }

    public function test_tracking_code_is_kept_on_the_device_and_offered_back(): void
    {
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::first();
        $h = $this->get('/incident/success?code='.$i->secret_tracking_code)->assertOk()->getContent();
        $this->assertStringContainsString('ipa-my-reports', $h);           // يُحفظ الرمز في المتصفح
        $this->assertStringContainsString('ipa-last-place', $h);           // ومكانه لبلاغه القادم
        $this->assertStringContainsString('المعالج المختص', $h);
        $this->assertStringNotContainsString('فني المكان', $h);             // لم يعد البلاغ يذهب إلى «فني المكان»
        // صفحة التتبع وصفحة البلاغ الأولى تعرضان بلاغاته المحفوظة بضغطة
        $this->assertStringContainsString('id="myReports"', $this->get('/incident/track')->assertOk()->getContent());
        $this->assertStringContainsString('id="myReports"', $this->get('/incident')->assertOk()->getContent());
    }

    public function test_reporter_rejects_closure_with_a_ready_reason(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $fani = $this->user('fani', 'tech_electrical');
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::first();
        app(IncidentService::class)->referToField($i, $salama->id, $fani->id);
        $i->update(['resolution_summary' => 'بُدّل البلاط']);
        Incident::withoutEvents(fn () => $i->forceFill(['status' => 'resolved'])->save());
        app(IncidentClosureService::class)->close($i->fresh(), $salama->id); // = «اطلب موافقته»

        $h = $this->get('/incident/track?code='.$i->secret_tracking_code)->assertOk()->getContent();
        foreach (['لم يُعالج أصلاً', 'عولج جزئياً', 'عاد الخلل بعد المعالجة'] as $reason) $this->assertStringContainsString($reason, $h);
        $this->assertStringContainsString('غير ذلك', $h);

        // خيار جاهز يكفي بلا كتابة
        $this->post('/incident/track/reject', ['tracking_code' => $i->secret_tracking_code, 'reason' => 'عولج جزئياً'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $i->fresh()->status);
        $this->assertTrue($i->events()->where('note', 'like', '%عولج جزئياً%')->exists());
    }
}
