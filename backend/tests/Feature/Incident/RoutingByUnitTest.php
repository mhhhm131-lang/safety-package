<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢١-٤ (قرار ٥٤): بلاغ الشاغل يُوجَّه بالإدارة من سجلها الفعلي — إدارة المبلّغ (أو إدارة المكان المعني)
 * ← خطرها الفعلي ← منسقه ومعالجه المسمّيان. لا قفز إلى «فني المكان»؛ وبلا خطر فعلي يبقى في المركز ويُنبَّه مدير الإدارة.
 * يعيد إنتاج عيوب جولة ٢١-٣: ع١ (ذهب تنمر المالية إلى منسق مكتب البيانات)، ع٢ (إدارة المبلّغ لا تُلتقط)، ع٥ (القفز إلى فني المكان).
 */
class RoutingByUnitTest extends TestCase
{
    use RefreshDatabase;

    private Risk $bully;
    private Risk $electric;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->bully = $this->reference('التنظيمية', 'ORG', 'العنف والتحرش', 'VIO', 'التحرش والتنمّر بين الزملاء');
        $this->electric = $this->reference('الكهربائية', 'ELC', 'الصعق', 'SHK', 'صعق من مقبس');
        $this->user('salama', 'system_admin');
        $this->user('fani', 'tech_electrical', null, 'HZ-06'); // فني مكانه المكاتب: كان يُقفز إليه
    }

    private function reference(string $cat, string $ca, string $sub, string $sa, string $title): Risk
    {
        $c = RiskCategory::create(['name' => $cat, 'abbreviation' => $ca, 'created_at' => now()]);
        $s = RiskSubCategory::create(['category_id' => $c->id, 'name' => $sub, 'abbreviation' => $sa]);
        $m = app(RiskService::class)->createRisk(null, ['title' => $title, 'description' => 'x', 'category_id' => $c->id, 'sub_category_id' => $s->id, 'severity' => 3, 'likelihood' => 3], 'master');
        $m->update(['status' => 'approved']);
        $r = app(RiskCopyService::class)->masterToReference($m->fresh(), null);
        $r->update(['status' => 'approved']);
        return $r;
    }

    private function user(string $username, string $role, ?string $unitCode = null, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function unitId(string $code): int
    {
        return (int) OrganizationUnit::where('code', $code)->value('id');
    }

    private function activate(Risk $ref, string $unitCode, string $placeCode, User $coord, User $handler): Risk
    {
        return app(RiskService::class)->activateFromReference($ref, null, ['scope_type' => 'org_unit', 'organization_unit_id' => $this->unitId($unitCode),
            'place_id' => Place::idByCode($placeCode), 'assigned_coordinator_id' => $coord->id, 'assigned_field_team_id' => $handler->id]);
    }

    public function test_employee_report_follows_his_own_department_register_not_the_last_one_in_the_place(): void
    {
        $hrMgr = $this->user('hr.m', 'department_manager', 'hr');
        $finCoord = $this->user('fin.c', 'safety_coordinator', 'fin');
        $dataCoord = $this->user('data.c', 'safety_coordinator', 'data');
        $this->activate($this->bully, 'fin', 'HZ-06', $finCoord, $hrMgr);
        $this->activate($this->bully, 'data', 'HZ-06', $dataCoord, $hrMgr); // أُنشئ أخيراً في المكان نفسه: كان يخطف البلاغ (ع١)
        $emp = $this->user('fin.e1', 'employee', 'fin');

        $this->actingAs($emp)->post('/incident/normal', ['description' => 'تنمر متكرر من زميل', 'risk_id' => $this->bully->id, 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::latest('id')->first();

        $this->assertSame($this->unitId('fin'), $i->organization_unit_id, 'إدارة المبلّغ لم تُلتقط من حسابه (ع٢)');
        $this->assertSame($finCoord->id, $i->incident_coordinator_id, 'ذهب إلى منسق إدارة أخرى (ع١)');
        $this->assertSame($hrMgr->id, $i->incident_field_team_id);
        $this->assertStringStartsWith($this->bully->code.'/FIN', (string) $i->risk->code);
        $this->assertSame('forwarded', $i->status);
    }

    public function test_report_about_another_place_follows_the_department_operating_that_place(): void
    {
        $tropsCoord = $this->user('trops.c', 'safety_coordinator', 'trops');
        $elec = $this->user('kahraba', 'tech_electrical');
        $this->activate($this->electric, 'trops', 'HZ-07', $tropsCoord, $elec);
        $this->activate($this->electric, 'fin', 'HZ-07', $this->user('fin.c', 'safety_coordinator', 'fin'), $elec); // أُنشئ أخيراً في المكان نفسه لإدارة لا تشغّله
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-07' => ['units' => ['_' => ['dept' => 'trops']]]])]);
        $hall = PlaceUnit::create(['place_id' => Place::idByCode('HZ-07'), 'type' => 'training_hall', 'name' => '312', 'is_active' => true]);
        $emp = $this->user('fin.e1', 'employee', 'fin'); // موظف المالية يبلّغ عن قاعة ليست لإدارته

        $this->actingAs($emp)->post('/incident/normal', ['description' => 'شرر من مقبس في القاعة ٣١٢', 'risk_id' => $this->electric->id,
            'place_id' => Place::idByCode('HZ-07'), 'place_unit_id' => $hall->id])->assertRedirect();
        $i = Incident::latest('id')->first();

        $this->assertSame($this->unitId('trops'), $i->organization_unit_id);
        $this->assertSame($tropsCoord->id, $i->incident_coordinator_id);
        $this->assertSame($elec->id, $i->incident_field_team_id);
    }

    public function test_without_an_active_risk_the_report_stays_at_the_center_and_the_unit_manager_is_told(): void
    {
        $finMgr = $this->user('fin.m', 'department_manager', 'fin');
        $emp = $this->user('fin.e1', 'employee', 'fin');

        $this->actingAs($emp)->post('/incident/normal', ['description' => 'تنمر ولم تفعّل الإدارة الخطر', 'risk_id' => $this->bully->id, 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::latest('id')->first();
        $this->assertSame('received', $i->status);                       // في المركز
        $this->assertNull($i->incident_field_team_id, 'قُفز إلى فني المكان (ع٥)');
        $this->assertSame($this->unitId('fin'), $i->organization_unit_id);
        $this->assertTrue(AppNotification::where('user_id', $finMgr->id)->where('type', 'incident.risk_not_activated')->exists(), 'مدير الإدارة لم يُنبَّه لتفعيل الخطر');

        // وبلا خطر أصلاً: المركز يصنّف، ولا قفز إلى فني المكان
        $this->actingAs($emp)->post('/incident/urgent', ['description' => 'رائحة دخان قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $u = Incident::latest('id')->first();
        $this->assertSame('received', $u->status);
        $this->assertNull($u->incident_field_team_id);
        $this->assertSame($this->unitId('fin'), $u->organization_unit_id);
    }

    public function test_timeline_does_not_claim_a_coordinator_step_nobody_took(): void
    {
        $elec = $this->user('kahraba', 'tech_electrical');
        $risk = app(RiskService::class)->activateFromReference($this->electric, null, ['scope_type' => 'org_unit', 'organization_unit_id' => $this->unitId('fin'),
            'place_id' => Place::idByCode('HZ-06'), 'assigned_field_team_id' => $elec->id]); // خطر قديم بلا منسق
        $emp = $this->user('fin.e1', 'employee', 'fin');
        $this->actingAs($emp)->post('/incident/normal', ['description' => 'مقبس تالف في المكتب', 'risk_id' => $this->electric->id, 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::latest('id')->first();
        $this->assertSame('forwarded', $i->status);
        $this->assertSame($elec->id, $i->incident_field_team_id);
        $this->assertNotContains('ref_receive', $i->events()->pluck('action')->all(), 'الخط الزمني سجّل «استلمه المنسق» ولا منسق');
        $this->assertSame($risk->id, $i->risk_id);
    }

    public function test_a_risk_cannot_be_activated_without_naming_its_coordinator_and_handler(): void
    {
        $mgr = $this->user('fin.m', 'department_manager', 'fin');
        $coord = $this->user('fin.c', 'safety_coordinator', 'fin');
        $base = ['scope_type' => 'org_unit', 'organization_unit_id' => $this->unitId('fin'), 'place_id' => Place::idByCode('HZ-06'), 'severity' => 3, 'likelihood' => 3];
        $this->actingAs($mgr)->post("/app/risk/{$this->bully->id}/activate", $base)->assertSessionHasErrors(['assigned_coordinator_id', 'assigned_field_team_id']);
        $this->assertSame(0, Risk::where('risk_type', 'active')->count());
        $this->actingAs($mgr)->post("/app/risk/{$this->bully->id}/activate", $base + ['assigned_coordinator_id' => $coord->id, 'assigned_field_team_id' => $mgr->id])->assertSessionHasNoErrors();
        $this->assertSame(1, Risk::where('risk_type', 'active')->where('assigned_coordinator_id', $coord->id)->where('assigned_field_team_id', $mgr->id)->count());
    }
}
