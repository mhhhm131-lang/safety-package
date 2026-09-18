<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٩-٥ (قرار ٤٨): الفريق الأولي والخطتان وفرق الفعاليات في الخلفية — ما كان يُكتب في dashboard.html:848-1086
 * يُكتب الآن من ملف المكان، في وثيقة ipa-place نفسها بصيغتها، والصلاحيات في الخادم كما كانت في اللوحة.
 */
class TeamNominationTest extends TestCase
{
    use RefreshDatabase;

    private Place $hub; private Place $park; private Place $halls;
    private OrganizationUnit $unit; private OrganizationUnit $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->hub = Place::where('code', 'HZ-06')->first();
        $this->park = Place::where('code', 'HZ-01')->first();
        $this->halls = Place::where('code', 'HZ-07')->first();
        $this->unit = OrganizationUnit::where('place_id', $this->hub->id)->first();
        $this->other = OrganizationUnit::where('place_id', $this->hub->id)->where('id', '!=', $this->unit->id)->first();
    }

    private function user(string $username, string $role, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function doc(): array
    {
        return json_decode((string) InstituteDocument::where('key', 'ipa-place')->value('data'), true) ?: [];
    }

    private function names(string $p, int $n = 4): array
    {
        $t = [];
        foreach ([0, 1, 2, 3] as $i) $t[$i] = ['name' => $i < $n ? "$p $i" : '', 'user' => '', 'dept' => '', 'phone' => $i === 0 ? '0500000009' : '', 'trained' => '', 'trainer' => ''];
        return $t;
    }

    public function test_nominate_then_approve_then_refer_with_server_side_roles(): void
    {
        $mudir = $this->user('mudir', 'department_manager', $this->unit->id);
        $mudir2 = $this->user('mudir2', 'department_manager', $this->other->id);
        $shuun = $this->user('shuun', 'admin_eng_manager');
        $salama = $this->user('salama', 'system_admin');
        $fani = $this->user('fani', 'field_worker');
        $base = "/app/places/{$this->hub->id}/team/{$this->unit->code}/0";

        // مدير إدارة أخرى والفني لا يرشّحان لهذه الإدارة
        $this->actingAs($mudir2)->get($base)->assertForbidden();
        $this->actingAs($fani)->post($base, ['team' => $this->names('عضو')])->assertForbidden();

        // مدير الإدارة يرشّح فريقه ويسجّل عدد الموظفين
        $this->actingAs($mudir)->get($base)->assertOk()->assertSee('المنسق')->assertSee('الإطفائي');
        $this->actingAs($mudir)->post($base, ['nom_by' => 'مدير الإدارة', 'nom_date' => '2026-09-18', 'staff' => '٣٠', 'team' => $this->names('عضو')])
            ->assertRedirect("/app/places/{$this->hub->id}/file#pfTeams");
        $u = $this->doc()['HZ-06']['units'][$this->unit->code];
        $this->assertSame('عضو 0', $u['team'][0]['name']);
        $this->assertSame('المنسق', $u['team'][0]['role']);
        $this->assertSame('2026-09-18', $u['nom']['date']);
        $this->assertSame($this->unit->code, $u['nom']['dept']);
        $this->assertSame(30, $u['staff']);
        $this->assertSame(1, (int) InstituteDocument::where('key', 'ipa-place')->value('version'), 'النسخة لم تُرفع');
        // الخرائط الفارغة تبقى كائنات في الوثيقة (اللوحة تكتب فيها بمفاتيح نصية)
        $this->assertStringContainsString('"appr":{}', (string) InstituteDocument::where('key', 'ipa-place')->value('data'));
        // الفريق يُشتق في وحدة الطوارئ فور الحفظ
        $team = EmergencyTeam::where('source', 'place_profile')->where('place_id', $this->hub->id)->where('unit_key', $this->unit->code)->first();
        $this->assertNotNull($team);
        $this->assertSame('nominated', $team->readiness);

        // ملف المكان: الحالة والمسار وزر الاعتماد لمدير الشؤون وحده
        $h = $this->actingAs($shuun)->get("/app/places/{$this->hub->id}/file")->assertOk()->getContent();
        $this->assertStringContainsString('data-team="'.$this->unit->code.':0" data-st="nom"', $h);
        $this->assertStringContainsString('href="tel:0500000009"', $h);
        $this->assertStringContainsString("$base/approve", $h);
        $this->assertStringNotContainsString("$base/approve", $this->actingAs($salama)->get("/app/places/{$this->hub->id}/file")->getContent());
        // ٣٠ موظفاً ← فريقان: الثاني ظاهر بزر «تسجيل الترشيح»
        $this->assertStringContainsString('data-team="'.$this->unit->code.':1" data-st="none"', $h);

        // الاعتماد: مدير الشؤون وحده
        $this->actingAs($salama)->post("$base/approve")->assertForbidden();
        $this->actingAs($mudir)->post("$base/approve")->assertForbidden();
        $this->actingAs($shuun)->post("$base/approve")->assertRedirect();
        $this->assertNotEmpty($this->doc()['HZ-06']['units'][$this->unit->code]['appr']['date']);
        $this->assertSame('approved', $team->fresh()->readiness);

        // الإحالة: مسؤول السلامة أو مدير الشؤون، لا مدير الإدارة
        $this->actingAs($mudir)->post("$base/refer")->assertForbidden();
        $this->actingAs($salama)->post("$base/refer")->assertRedirect();
        $this->assertNotEmpty($this->doc()['HZ-06']['units'][$this->unit->code]['hr']['date']);
        $this->assertSame('referred', $team->fresh()->readiness);

        // تغيير الأسماء بعد الاعتماد يُلغي الاعتماد والإحالة ويعيد المسار إلى الترشيح
        $this->actingAs($mudir)->post($base, ['nom_by' => 'مدير الإدارة', 'nom_date' => '2026-09-18', 'staff' => '30', 'team' => $this->names('بديل')])->assertRedirect();
        $u = $this->doc()['HZ-06']['units'][$this->unit->code];
        $this->assertSame('بديل 0', $u['team'][0]['name']);
        $this->assertTrue(empty($u['appr']['date']) && empty($u['hr']['date']));

        // الفريق الثاني في more[0] ومشتقه «<uid>#2»
        $this->actingAs($mudir)->post("/app/places/{$this->hub->id}/team/{$this->unit->code}/1", ['nom_by' => 'م', 'team' => $this->names('ثانٍ')])->assertRedirect();
        $this->assertSame('ثانٍ 0', $this->doc()['HZ-06']['units'][$this->unit->code]['more'][0]['team'][0]['name']);
        $this->assertNotNull(EmergencyTeam::where('unit_key', $this->unit->code.'#2')->first());

        // لا اعتماد بلا ترشيح
        $this->actingAs($shuun)->post("/app/places/{$this->hub->id}/team/{$this->other->code}/0/approve")->assertStatus(422);
    }

    public function test_single_unit_place_plans_and_staff(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $fani = $this->user('fani', 'field_worker');

        // مكان بلا إدارات: وحدة واحدة «_»
        $this->actingAs($salama)->post("/app/places/{$this->park->id}/team/_/0", ['nom_by' => 'مدير المرافق', 'team' => $this->names('قبو', 3)])->assertRedirect();
        $u = $this->doc()['HZ-01']['units']['_'];
        $this->assertSame('قبو 2', $u['team'][2]['name']);
        $this->assertSame('', $u['team'][3]['name']);

        // الخطتان: مسؤول السلامة ومدير الشؤون
        $this->actingAs($fani)->post("/app/places/{$this->park->id}/plans", ['sa' => '2026-09-01'])->assertForbidden();
        $this->actingAs($salama)->post("/app/places/{$this->park->id}/plans", ['sa' => '2026-09-01', 'sa_by' => 'الإدارة العليا', 'ra' => '2026-09-02', 'drill' => ''])->assertRedirect();
        $this->assertSame(['sa' => '2026-09-01', 'saBy' => 'الإدارة العليا', 'ra' => '2026-09-02', 'drill' => ''], $this->doc()['HZ-01']['plans']);
        $this->assertSame('قبو 0', $this->doc()['HZ-01']['units']['_']['team'][0]['name'], 'تحرير الخطتين مسح الفريق');

        // عدد الموظفين وحده
        $this->actingAs($salama)->post("/app/places/{$this->park->id}/team/_/staff", ['staff' => 60])->assertRedirect();
        $this->assertSame(60, $this->doc()['HZ-01']['units']['_']['staff']);

        // ملف المكان: أربع بطاقات جاهزية، والفني يرى ولا يعدّل
        $h = $this->actingAs($fani)->get("/app/places/{$this->park->id}/file")->assertOk()->getContent();
        foreach (['sa', 'ra', 'insp', 'team'] as $c) $this->assertStringContainsString('data-ready="'.$c.'"', $h);
        $this->assertStringContainsString('data-team="_:0" data-st="nom"', $h);
        $this->assertStringContainsString('(٣ من ٤)', $h);
        $this->assertStringNotContainsString('تحرير الخطتين', $h);
        $this->assertStringNotContainsString('/team/_/0"', $h);
        $this->assertStringContainsString('تحرير الخطتين', $this->actingAs($salama)->get("/app/places/{$this->park->id}/file")->getContent());
    }

    public function test_hall_events_nominated_by_halls_and_approved_by_security_head_only(): void
    {
        $halls = OrganizationUnit::create(['code' => 'hallsdept', 'name' => 'إدارة القاعات', 'unit_type' => 'department', 'place_id' => $this->halls->id, 'order' => 99, 'is_active' => true]);
        $qaat = $this->user('qaat', 'department_manager', $halls->id);
        $mudir = $this->user('mudir', 'department_manager', $this->unit->id);
        $amn = $this->user('amn', 'security_safety_head');
        $salama = $this->user('salama', 'system_admin');
        $base = "/app/places/{$this->halls->id}/events";

        $this->actingAs($mudir)->post("$base/new", ['name' => 'حفل', 'date' => '2030-01-01', 'team' => $this->names('ف')])->assertForbidden();
        $this->actingAs($qaat)->get("$base/new")->assertOk();
        $this->actingAs($qaat)->post("$base/new", ['name' => 'حفل التخرج', 'date' => '2030-01-01', 'by' => 'إدارة القاعات', 'team' => $this->names('ف')])
            ->assertRedirect("/app/places/{$this->halls->id}/file#pfEvents");
        $e = $this->doc()['HZ-07']['events'][0];
        $this->assertSame('حفل التخرج', $e['name']);
        $this->assertSame('ف 1', $e['team'][1]['name']);
        $this->assertNotEmpty($e['nom']['date']);

        $h = $this->actingAs($amn)->get("/app/places/{$this->halls->id}/file")->assertOk()->getContent();
        $this->assertStringContainsString('قاعدة القاعات', $h);
        $this->assertStringContainsString('حفل التخرج', $h);
        $this->assertStringContainsString("$base/0/approve", $h);

        // الاعتماد لرئيس الأمن والسلامة وحده وباسمه
        $this->actingAs($salama)->post("$base/0/approve")->assertForbidden();
        $this->actingAs($amn)->post("$base/0/approve")->assertRedirect();
        $this->assertSame('اسم amn', $this->doc()['HZ-07']['events'][0]['appr']['by']);

        // تعديل الأسماء يُلغي الاعتماد؛ والحذف لمن يرشّح
        $this->actingAs($qaat)->post("$base/0", ['name' => 'حفل التخرج', 'date' => '2030-01-01', 'team' => $this->names('ج')])->assertRedirect();
        $this->assertTrue(empty($this->doc()['HZ-07']['events'][0]['appr']['date']));
        $this->actingAs($amn)->delete("$base/0")->assertForbidden();
        $this->actingAs($qaat)->delete("$base/0")->assertRedirect();
        $this->assertSame([], $this->doc()['HZ-07']['events']);
    }

    public function test_inbox_and_intent_open_the_backend_place_file(): void
    {
        $mudir = $this->user('mudir', 'department_manager', $this->unit->id);
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-06' => ['plans' => [], 'units' => [$this->unit->code => ['staff' => 30, 'team' => [], 'nom' => [], 'appr' => [], 'hr' => []]]]])]);
        $h = $this->actingAs($mudir)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString("/app/places/{$this->hub->id}/file#pfTeams", $h);
        $this->assertStringNotContainsString('/dashboard.html#place=HZ-06', $h);
    }
}
