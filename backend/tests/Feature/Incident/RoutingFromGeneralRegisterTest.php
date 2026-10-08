<?php

namespace Tests\Feature\Incident;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\IncidentService;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خطة المعالج — الخطوة ٣ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨): البلاغ يسأل الخطر في السجل العام «من يعالجك؟»، لا إدارةَ المكان.
 *   ١ شخص مسمّى ← هو · ٢ تخصص ← الفني الذي يغطي مكان البلاغ · ٣ تخصص بلا فني يغطي المكان، أو إدارة بلا تخصص ← مدير الإدارة المعالجة
 *   ٤ لا إدارة معالجة ← المسار القائم (انتقالي) ثم المركز بعلّته «هذا الخطر بلا إدارة معالجة في السجل العام».
 *   يُقرأ من العام عند كل بلاغ، ولا ينتظر تفعيل الإدارة للخطر؛ والمنسق من نسخة الإدارة إن وُجدت. كل فحص يقرأ القاعدة بعد البلاغ.
 */
class RoutingFromGeneralRegisterTest extends TestCase
{
    use RefreshDatabase;

    private Risk $socket;
    private Risk $bully;
    private OrganizationUnit $fac;
    private User $salama;
    private User $marafiq;
    private User $hrMgr;
    private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->fac = OrganizationUnit::create(['code' => 'fac', 'name' => 'المرافق والصيانة', 'unit_type' => 'section', 'parent_id' => $this->unitId('adm-eng'), 'order' => 99]);
        $this->socket = $this->reference('الكهربائية', 'ELC', 'الصعق', 'SHK', 'غطاء مقبس مكسور', $this->fac);
        $this->bully = $this->reference('التنظيمية', 'ORG', 'العنف والتحرش', 'VIO', 'التحرش والتنمّر بين الزملاء', null);
        $this->salama = $this->user('salama', 'system_admin');
        $this->marafiq = $this->user('marafiq', 'facilities_manager', 'fac');
        $this->hrMgr = $this->user('hr.m', 'department_manager', 'hr');
        $this->emp = $this->user('fin.e1', 'employee', 'fin');
    }

    private function reference(string $cat, string $ca, string $sub, string $sa, string $title, ?OrganizationUnit $handling): Risk
    {
        $c = RiskCategory::create(['name' => $cat, 'abbreviation' => $ca, 'created_at' => now()]);
        $s = RiskSubCategory::create(['category_id' => $c->id, 'name' => $sub, 'abbreviation' => $sa]);
        $r = Risk::create(['risk_type' => 'reference', 'title' => $title, 'description' => 'x', 'category_id' => $c->id, 'sub_category_id' => $s->id,
            'severity' => 3, 'likelihood' => 3, 'status' => 'approved', 'handling_unit_id' => $handling?->id, 'handling_unit_name' => $handling?->name]);
        app(RiskService::class)->ensurePhases($r);
        return $r;
    }

    private function user(string $username, string $role, ?string $unitCode = null, ?string $placeCode = null, array $coverage = []): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? $this->unitId($unitCode) : null, 'place_id' => Place::idByCode($placeCode)]);
        if ($coverage) $p->coverage()->sync(array_map(fn ($c) => Place::idByCode($c), $coverage));
        return $u;
    }

    private function unitId(string $code): int
    {
        return (int) OrganizationUnit::where('code', $code)->value('id');
    }

    private function setHandler(Risk $ref, ?string $specialty = null, ?User $person = null): void
    {
        $ref->forceFill(['handler_specialty' => $specialty, 'handler_user_id' => $person?->id, 'handler_set_by_id' => $this->marafiq->id, 'handler_set_at' => now()])->save();
    }

    private function report(Risk $ref, string $place = 'HZ-06', string $text = 'بلاغ من الموظف عن الخطر'): Incident
    {
        $this->actingAs($this->emp)->post('/incident/normal', ['description' => $text, 'risk_id' => $ref->id, 'place_id' => Place::idByCode($place)])->assertRedirect();
        auth()->logout();
        return Incident::latest('id')->first();
    }

    private function createNote(Incident $i): string
    {
        return (string) $i->events()->where('action', 'create')->value('note');
    }

    private function centerCard(Incident $i): ?Task
    {
        return app(InboxService::class)->forUser($this->salama->fresh())->first(fn (Task $t) => $t->key === "incident:{$i->id}:center");
    }

    /** ١ شخص مسمّى على الخطر في العام ← هو، ولو لم تفعّل الإدارة الخطر */
    public function test_a_named_person_on_the_general_register_gets_the_report_without_activation(): void
    {
        $tech = $this->user('ahmad', 'tech_electrical', 'fac');
        $this->setHandler($this->socket, null, $tech);
        $finMgr = $this->user('fin.m', 'department_manager', 'fin');

        $i = $this->report($this->socket);
        $this->assertSame($tech->id, $i->incident_field_team_id);
        $this->assertSame('forwarded', $i->status, 'لم يصل المعالج آلياً');
        $this->assertNull($i->incident_coordinator_id);
        $this->assertNull($i->center_reason);
        $this->assertSame($this->socket->id, $i->risk_id);
        $this->assertStringContainsString('المعالج المسمّى على الخطر اسم ahmad', $this->createNote($i));
        $this->assertNull($this->centerCard($i), 'المركز رأى بطاقة لبلاغ وصل معالجه');
        // مدير الإدارة المعنية يُنبَّه أن يفعّل ويسمّي المنسق، ويُقال له أن البلاغ ذهب لمعالجه
        $n = AppNotification::where('user_id', $finMgr->id)->where('type', 'incident.risk_not_activated')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('ذهب إلى معالجه من السجل العام: اسم ahmad', (string) $n->message);
        $this->assertStringNotContainsString('ومعالجه', (string) $n->message);
    }

    /** ٢ تخصص ← الفني بذلك التخصص الذي يغطي مكان البلاغ، لا فني مكان آخر */
    public function test_a_specialty_goes_to_the_technician_covering_the_place_of_the_report(): void
    {
        $this->setHandler($this->socket, 'tech_electrical');
        $halls = $this->user('kahraba.halls', 'tech_electrical', null, null, ['HZ-07']);
        $offices = $this->user('kahraba.offices', 'tech_electrical', null, 'HZ-06');   // مكان حسابه بلا تغطية
        $this->user('takyeef', 'tech_hvac', null, null, ['HZ-07']);                     // تخصص آخر يغطي القاعات

        $i = $this->report($this->socket, 'HZ-07');
        $this->assertSame($halls->id, $i->incident_field_team_id);
        $this->assertSame('forwarded', $i->status);
        $this->assertStringContainsString('فني الكهرباء الذي يغطي', $this->createNote($i));

        $j = $this->report($this->socket, 'HZ-06');
        $this->assertSame($offices->id, $j->incident_field_team_id);
    }

    /** ٣ تخصص بلا فني يغطي المكان ← مدير الإدارة المعالجة؛ وإدارة بلا تخصص ← مديرها */
    public function test_without_a_covering_technician_or_a_specialty_the_handling_unit_manager_gets_it(): void
    {
        $this->setHandler($this->socket, 'tech_electrical');
        $this->user('kahraba.halls', 'tech_electrical', null, null, ['HZ-07']); // يغطي القاعات لا المطاعم
        $i = $this->report($this->socket, 'HZ-08');
        $this->assertSame($this->marafiq->id, $i->incident_field_team_id);
        $this->assertStringContainsString('مدير الإدارة المعالجة «المرافق والصيانة» اسم marafiq — لا فني الكهرباء يغطي مكان البلاغ', $this->createNote($i));

        $this->setHandler($this->socket, null, null);
        $j = $this->report($this->socket, 'HZ-07');
        $this->assertSame($this->marafiq->id, $j->incident_field_team_id);
        $this->assertStringContainsString('لا تخصص ولا شخص على الخطر', $this->createNote($j));

        // الوحدة بلا مدير بحساب: مدير ما فوقها (الشؤون الإدارية والهندسية)؛ ولا أحد ← المركز بعلّته
        $this->marafiq->profile->update(['is_active' => false]);
        $shuon = $this->user('shuon', 'admin_eng_manager', 'adm-eng');
        $k = $this->report($this->socket, 'HZ-07');
        $this->assertSame($shuon->id, $k->incident_field_team_id);
        $this->assertStringContainsString('مدير الإدارة المعالجة «الإدارة العامة للشؤون الإدارية والهندسية»', $this->createNote($k));
        $shuon->profile->update(['is_active' => false]);
        $m = $this->report($this->socket, 'HZ-07');
        $this->assertNull($m->incident_field_team_id);
        $this->assertSame('received', $m->status);
        $this->assertSame('الإدارة المعالجة «المرافق والصيانة» بلا مدير بحساب', $m->center_reason);
        $this->assertStringContainsString('بلا مدير بحساب — أحله', $this->centerCard($m)->question);
    }

    /** ٤ لا إدارة معالجة في العام ← المسار القائم (انتقالي): معالج نسخة الإدارة، وإلا المركز بعلّة «بلا إدارة معالجة» */
    public function test_without_a_handling_unit_the_old_path_still_works_and_the_center_is_told_why(): void
    {
        $i = $this->report($this->bully);
        $this->assertNull($i->incident_field_team_id);
        $this->assertSame('received', $i->status);
        $this->assertSame('هذا الخطر بلا إدارة معالجة في السجل العام', $i->center_reason);
        $this->assertStringContainsString('بلا إدارة معالجة في السجل العام — أحله', $this->centerCard($i)->question);

        // الإدارة المعنية فعّلت الخطر وسمّت معالجاً (الطريقة القديمة): يصل إليه ما دام العام صامتاً
        $finCoord = $this->user('fin.c', 'safety_coordinator', 'fin');
        app(RiskService::class)->activateFromReference($this->bully, null, ['scope_type' => 'org_unit', 'organization_unit_id' => $this->unitId('fin'),
            'place_id' => Place::idByCode('HZ-06'), 'assigned_coordinator_id' => $finCoord->id, 'assigned_field_team_id' => $this->hrMgr->id]);
        $j = $this->report($this->bully);
        $this->assertSame($this->hrMgr->id, $j->incident_field_team_id);
        $this->assertSame($finCoord->id, $j->incident_coordinator_id);
        $this->assertNull($j->center_reason);
    }

    /** العام يُقرأ عند كل بلاغ ويغلب نسخة الإدارة: تغيير المعالج في العام يسري على البلاغ التالي بلا تفعيل جديد */
    public function test_the_general_register_is_read_at_every_report_and_overrides_the_unit_copy(): void
    {
        $finCoord = $this->user('fin.c', 'safety_coordinator', 'fin');
        $old = $this->user('old.handler', 'employee', 'fin');
        app(RiskService::class)->activateFromReference($this->socket, null, ['scope_type' => 'org_unit', 'organization_unit_id' => $this->unitId('fin'),
            'place_id' => Place::idByCode('HZ-06'), 'assigned_coordinator_id' => $finCoord->id, 'assigned_field_team_id' => $old->id]);
        $copy = Risk::where('risk_type', 'active')->where('parent_reference_id', $this->socket->id)->firstOrFail();

        // العام: إدارة معالجة بلا معالج بعد ← مدير المرافق، لا معالج النسخة؛ والمنسق من النسخة
        $i = $this->report($this->socket);
        $this->assertSame($this->marafiq->id, $i->incident_field_team_id, 'ذهب إلى معالج نسخة الإدارة والعام يقول غيره');
        $this->assertSame($finCoord->id, $i->incident_coordinator_id);
        $this->assertSame($copy->id, $i->risk_id);
        $this->assertSame('forwarded', $i->status);
        $this->assertContains('ref_receive', $i->events()->pluck('action')->all()); // المنسق موجود فيمرّ المسار بخطوتيه

        // مدير المرافق سمّى شخصاً في العام ← البلاغ التالي إليه فوراً
        $tech = $this->user('ahmad', 'tech_electrical', 'fac');
        $this->setHandler($this->socket, null, $tech);
        $j = $this->report($this->socket);
        $this->assertSame($tech->id, $j->incident_field_team_id);
        $this->assertSame($old->id, $copy->fresh()->assigned_field_team_id, 'النسخة لم تُمس');

        // مسؤول السلامة نقل الإدارة المعالجة إلى الموارد البشرية ← مديرها، ومعالج المرافق لم ينتقل
        $this->actingAs($this->salama)->post(route('risk.reference.update', $this->socket), ['category_id' => $this->socket->category_id, 'sub_category_id' => $this->socket->sub_category_id,
            'severity' => 3, 'likelihood' => 3, 'title' => 'غطاء مقبس مكسور', 'handling_unit_id' => $this->unitId('hr')])->assertRedirect();
        auth()->logout();
        $k = $this->report($this->socket);
        $this->assertSame($this->hrMgr->id, $k->incident_field_team_id);
    }

    /** السري يسلك المسار نفسه، وتصنيف المركز لبلاغ بلا خطر كذلك */
    public function test_secret_reports_and_center_classification_follow_the_same_path(): void
    {
        $tech = $this->user('ahmad', 'tech_electrical', 'fac');
        $this->setHandler($this->socket, null, $tech);

        $this->post('/incident/secret', ['description' => 'مقبس مكسور', 'risk_id' => $this->socket->id, 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $s = Incident::latest('id')->first();
        $this->assertSame('secret', $s->incident_type);
        $this->assertSame($tech->id, $s->incident_field_team_id);
        $this->assertSame('forwarded', $s->status);

        // سري قديم بلا خطر (النموذج يشترطه منذ قرار ٧٢؛ الخدمة تقبله): المركز يصنّفه فيسلك المسار نفسه
        $u = app(IncidentService::class)->createSecretIncident(['description' => 'شيء غامض قرب المقبس', 'place_id' => Place::idByCode('HZ-06')])['incident'];
        $this->assertNull($u->risk_id);
        app(IncidentService::class)->linkRisk($u, $this->salama->id, $this->socket->id);
        $u->refresh();
        $this->assertSame($tech->id, $u->incident_field_team_id);
        $this->assertSame('forwarded', $u->status);
        $this->assertNull($u->center_reason);
    }

    /** الإدارة المعالجة المعطَّلة تُوجد باسمها (الفروع لاحقاً: الاسم نفسه داخل فرع المكان) */
    public function test_a_deactivated_handling_unit_is_found_by_its_name(): void
    {
        $this->fac->update(['is_active' => false]);
        $fac2 = OrganizationUnit::create(['code' => 'fac2', 'name' => 'المرافق والصيانة', 'unit_type' => 'section', 'parent_id' => $this->unitId('adm-eng'), 'order' => 100]);
        $mgr2 = $this->user('marafiq2', 'facilities_manager', 'fac2');
        $i = $this->report($this->socket);
        $this->assertSame($mgr2->id, $i->incident_field_team_id);
    }
}
