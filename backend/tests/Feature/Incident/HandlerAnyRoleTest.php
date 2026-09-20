<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٢١-٥ (قرار ٥١ «الفني المنفّذ صفة إحالة لا دور» وقرار ٥٤): المعالج المسمّى في الخطر أو المُحال إليه يستلم بلاغه
 * ويعالجه أياً كان دوره، ويرى بلاغه وحده ولا يكسب غير ذلك. والإحالة لأي حساب مفعَّل بيد المركز ومنسق البلاغ وحدهما.
 * يعيد إنتاج ع٣ من جولة ٢١-٣: مدير الموارد البشرية المعالج ٤٠٣ على «استلام» و«بدء المعالجة».
 */
class HandlerAnyRoleTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Risk $bully;
    private User $salama; private User $hrMgr; private User $finCoord; private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $c = RiskCategory::create(['name' => 'التنظيمية', 'abbreviation' => 'ORG', 'created_at' => now()]);
        $s = RiskSubCategory::create(['category_id' => $c->id, 'name' => 'العنف والتحرش', 'abbreviation' => 'VIO']);
        $m = app(RiskService::class)->createRisk(null, ['title' => 'التحرش والتنمّر بين الزملاء', 'description' => 'x', 'category_id' => $c->id, 'sub_category_id' => $s->id, 'severity' => 3, 'likelihood' => 3], 'master');
        $m->update(['status' => 'approved']);
        $this->bully = app(RiskCopyService::class)->masterToReference($m->fresh(), null);
        $this->bully->update(['status' => 'approved']);

        $this->salama = $this->user('salama', 'system_admin');
        $this->hrMgr = $this->user('hr.m', 'department_manager', 'hr');
        $this->finCoord = $this->user('fin.c', 'safety_coordinator', 'fin');
        $this->emp = $this->user('fin.e1', 'employee', 'fin');
        app(RiskService::class)->activateFromReference($this->bully, null, ['scope_type' => 'org_unit', 'organization_unit_id' => $this->unitId('fin'),
            'place_id' => Place::idByCode('HZ-06'), 'assigned_coordinator_id' => $this->finCoord->id, 'assigned_field_team_id' => $this->hrMgr->id]);
    }

    private function user(string $username, string $role, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitCode ? $this->unitId($unitCode) : null]);
        return $u;
    }

    private function unitId(string $code): int
    {
        return (int) OrganizationUnit::where('code', $code)->value('id');
    }

    private function report(string $text = 'تنمر متكرر من زميل في الإدارة'): Incident
    {
        $this->actingAs($this->emp)->post('/incident/normal', ['description' => $text, 'risk_id' => $this->bully->id, 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        auth()->logout();
        return Incident::latest('id')->first();
    }

    public function test_named_administrative_handler_receives_processes_and_resolves_his_report(): void
    {
        $i = $this->report();
        $this->assertSame($this->hrMgr->id, $i->incident_field_team_id);

        // «ما ينتظرك» يعرضه له، وفتحه = استلامه (كما للفني)
        $this->actingAs($this->hrMgr)->get('/app')->assertOk()->assertSee($i->code);
        $this->actingAs($this->hrMgr)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('رفع دليل');
        $this->assertSame('field_received', $i->fresh()->status);

        $this->actingAs($this->hrMgr)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => 'حُقّق في الشكوى بالإجراء وحُمي المشتكي ووُجّه المسيء كتابياً',
            'evidence' => UploadedFile::fake()->createWithContent('minutes.png', base64_decode(self::PNG))])->assertSessionHas('success');
        $this->assertSame('resolved', $i->fresh()->status);

        // الإغلاق بمساره القائم: المبلّغ بحساب يوافق
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close")->assertSessionHas('success');
        $this->actingAs($this->emp)->post("/app/incidents/{$i->id}/approve-closure")->assertSessionHas('success');
        $this->assertSame('closed', $i->fresh()->status);
    }

    public function test_handler_gains_nothing_else_and_others_cannot_process(): void
    {
        $mine = $this->report();
        $other = $this->report('بلاغ آخر لا يخصه');
        app(\App\Modules\Incident\Services\IncidentService::class)->referToField($other, $this->salama->id, $this->salama->id);

        // لا يرى غير بلاغه، ولا يعالج غيره، ولا يملك أدوات المركز على بلاغه
        $this->actingAs($this->emp)->get("/app/incidents/{$other->id}")->assertOk(); // المبلّغ يرى بلاغيه
        $stranger = $this->user('it.e1', 'employee', 'it');
        $this->actingAs($stranger)->get("/app/incidents/{$mine->id}")->assertForbidden();
        $this->actingAs($stranger)->post("/app/incidents/{$mine->id}/field-receive")->assertForbidden();
        $this->actingAs($this->hrMgr)->post("/app/incidents/{$other->id}/field-receive")->assertForbidden();
        $this->actingAs($this->hrMgr)->post("/app/incidents/{$mine->id}/close-with-note", ['note' => 'أغلقه بنفسي'])->assertForbidden();
        $this->actingAs($this->hrMgr)->post("/app/incidents/{$mine->id}/out-of-scope")->assertForbidden();
        $this->assertSame('forwarded', $mine->fresh()->status);

        // المعالج يصعّد لمنسق البلاغ إن تعذّر عليه
        $this->actingAs($this->hrMgr)->get("/app/incidents/{$mine->id}");
        $this->actingAs($this->hrMgr)->post("/app/incidents/{$mine->id}/escalate-to-coord", ['reason' => 'يحتاج قراراً من خارج الموارد البشرية'])->assertSessionHas('success');
        $this->assertSame('escalated_to_coord', $mine->fresh()->status);
    }

    public function test_center_and_report_coordinator_refer_to_any_active_account_and_nobody_else_does(): void
    {
        $i = $this->report();
        $legal = $this->user('legal.m', 'department_manager', 'legal');
        $tech = $this->user('fani', 'tech_electrical');

        // فني لا علاقة له بالبلاغ كان يستطيع إحالة أي بلاغ (المسار بصلاحية المعالجة وحدها)
        $this->actingAs($tech)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $tech->id])->assertForbidden();
        $this->assertSame($this->hrMgr->id, $i->fresh()->incident_field_team_id);

        // منسق البلاغ يعيد الإحالة إلى حساب غير فني
        $this->actingAs($this->finCoord)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('referModal');
        $this->actingAs($this->finCoord)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $legal->id, 'note' => 'الشكوى ضد موظف في الموارد البشرية'])->assertSessionHas('success');
        $this->assertSame($legal->id, $i->fresh()->incident_field_team_id);
        $this->actingAs($this->hrMgr)->post("/app/incidents/{$i->id}/field-receive")->assertForbidden(); // لم يعد معالجه

        // والمركز كذلك؛ والحساب المعطَّل لا يُحال إليه
        $off = $this->user('off', 'employee', 'fin');
        $off->profile->update(['is_active' => false]);
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $off->id])->assertSessionHas('error');
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $this->hrMgr->id])->assertSessionHas('success');
        $this->assertSame($this->hrMgr->id, $i->fresh()->incident_field_team_id);
    }

    /** قرار ٥٥ (كلمة المستخدم): الطبيب والأمن ومراقب الحريق لا دخل لهم في بلاغات الشاغلين — دورهم في الطوارئ. */
    public function test_support_team_has_no_business_with_occupant_reports(): void
    {
        $i = $this->report();
        $tabib = $this->user('tabib', 'support_team');
        $this->assertFalse(\App\Core\Permissions\PermissionRegistry::hasPermission('support_team', 'incident.list'));
        $this->actingAs($tabib)->get('/app/incidents')->assertForbidden();
        $this->actingAs($tabib)->get("/app/incidents/{$i->id}")->assertForbidden();
        $this->assertFalse(\App\Core\Intents\IntentRegistry::forUser($tabib)->contains('key', 'incidents'));
        $this->actingAs($tabib)->get('/app')->assertOk();
        // إن أُحيل إليه بلاغ بعينه رآه وعالجه كأي معالج مسمّى
        app(\App\Modules\Incident\Services\IncidentService::class)->referToField($i, $this->salama->id, $tabib->id);
        $this->actingAs($tabib)->get("/app/incidents/{$i->id}")->assertOk();
    }
}
