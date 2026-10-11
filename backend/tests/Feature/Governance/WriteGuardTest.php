<?php

namespace Tests\Feature\Governance;

use App\Core\Rules\InAccountBuildings;
use App\Models\User;
use App\Modules\Emergency\Models\BuildingExit;
use App\Modules\Emergency\Models\BuildingFloor;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Models\EmergencyVisitor;
use App\Modules\Emergency\Models\PanicAlert;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\GateLog;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Worker\Models\Trade;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * حارس الكتابة (تتمة قرار ٨٤ — بكلمته «تمام» ٢٠٢٦-١٠-١١).
 * بعد قرار ٨٤ صارت القراءة والروابط معزولة، وبقي الباب الآخر مفتوحاً: نموذج يحمل رقم مبنى آخر في جسمه يمرّ،
 * لأن النطاق العام يقيّد القراءة لا الإدراج، والتحقق يسأل `exists:` لا «من مبانيك».
 * هنا حساب فرع الدمام (منسق الفرع ومدير الفرع) يرسل معرّفات الملز في جسم الطلب إلى كل موضع كتابة يأخذ
 * `building_id` أو `place_id`، ويُتوقع 422 بخطأ على الحقل ولا صف يُكتب؛ ومعرّفاته هو تمرّ؛ والمركز لا يتغيّر.
 */
class WriteGuardTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $branchB;
    private User $coord;   // منسق سلامة فرع الدمام (قرار ٨١: يرى فرعه)
    private User $farea;   // مدير فرع الدمام
    private User $salama;  // مسؤول السلامة — يرى الكل
    private EmergencyTeam $tDmm;
    private int $mlzPlace; // مكان في الملز
    private int $dmmPlace; // مكان في الدمام

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, PermitTypesSeeder::class, TradeSeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]);
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => $this->bp('HZ-06')->id]);

        $this->coord = $this->user('coord.b', 'safety_coordinator', $this->b, $this->bp('HZ-06'), $this->branchB);
        $this->farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $this->salama = $this->user('salama', 'system_admin');

        $this->mlzPlace = Place::idByCode('HZ-06');
        $this->dmmPlace = $this->bp('HZ-06')->id;
        $this->tDmm = EmergencyTeam::create(['building_id' => $this->b->id, 'place_id' => $this->bp('HZ-01')->id, 'name' => 'فريق الدمام', 'team_type' => 'fire_warden', 'shift' => 'all', 'is_active' => true, 'source' => 'manual']);
        // مخرج في الملز — لرابط «أقرب مخرج» الذي يأخذ المبنى من الطلب
        $floor = BuildingFloor::create(['building_id' => $this->main->id, 'floor_number' => 0, 'created_at' => now()]);
        BuildingExit::create(['building_id' => $this->main->id, 'floor_id' => $floor->id, 'code' => 'X-MLZ', 'name' => 'مخرج الملز', 'exit_type' => 'main', 'status' => 'available']);
    }

    private function bp(string $cat): Place
    {
        return Place::where('building_id', $this->b->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    /** (أ) الواجهات البرمجية: الزوار والذعر وأقرب مخرج والبوابة والإغلاق — معرّف الملز في الجسم يردّ 422 ولا يُكتب */
    public function test_json_endpoints_refuse_another_building_in_the_body(): void
    {
        $this->actingAs($this->coord);
        $m = $this->main->id;

        $this->postJson('/api/emergency/visitors/check-in', ['building_id' => $m, 'name' => 'زائر مدسوس'])->assertStatus(422)->assertJsonValidationErrors('building_id');
        $this->postJson('/api/emergency/visitors/check-in', ['place_id' => $this->mlzPlace, 'name' => 'زائر مدسوس'])->assertStatus(422)->assertJsonValidationErrors('place_id');
        $this->assertFalse(EmergencyVisitor::withoutGlobalScope('building')->where('name', 'زائر مدسوس')->exists(), 'زائر سُجّل على الملز من حساب الدمام');
        $this->getJson('/api/emergency/visitors/search?q=زائر&building_id='.$m)->assertStatus(422)->assertJsonValidationErrors('building_id');

        $this->postJson('/api/emergency/panic/trigger', ['building_id' => $m])->assertStatus(422)->assertJsonValidationErrors('building_id');
        $this->postJson('/api/emergency/panic/trigger', ['place_id' => $this->mlzPlace])->assertStatus(422)->assertJsonValidationErrors('place_id');
        $this->assertSame(0, PanicAlert::withoutGlobalScope('building')->count(), 'تنبيه ذعر كُتب على الملز من حساب الدمام');

        // كان يعيد مخارج الملز لحساب الدمام (findOrFail لا يمرّ بحارس الرابط)
        $this->getJson('/api/emergency/nearest-exit?building_id='.$m)->assertStatus(422)->assertJsonValidationErrors('building_id');

        $this->postJson('/app/permits/gate/check', ['worker_identifier' => '999', 'place_id' => $this->mlzPlace])->assertStatus(422)->assertJsonValidationErrors('place_id');
        $this->assertFalse(GateLog::withoutGlobalScope('building')->where('place_id', $this->mlzPlace)->exists(), 'سجل بوابة كُتب على مكان الملز من حساب الدمام');

        $this->postJson("/api/iot/lockdown/buildings/{$this->b->id}/initiate", ['level' => 'soft', 'place_id' => $this->mlzPlace])->assertStatus(422)->assertJsonValidationErrors('place_id');
    }

    /** (ب) شاشات الطوارئ: الفرق والتمارين والمعدات وجهات الاتصال ونقاط التجمع والتفعيل والإغلاق */
    public function test_emergency_forms_refuse_another_building_in_the_body(): void
    {
        $this->actingAs($this->coord);
        $m = $this->main->id; $d = $this->b->id;

        $team = ['name' => 'فريق مدسوس', 'team_type' => 'fire_warden', 'shift' => 'all'];
        $this->post('/app/emergency/teams', $team + ['building_id' => $m])->assertSessionHasErrors('building_id');
        $this->post('/app/emergency/teams', $team + ['building_id' => $d, 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $this->assertFalse(EmergencyTeam::withoutGlobalScope('building')->where('name', 'فريق مدسوس')->exists(), 'فريق كُتب على الملز من حساب الدمام');
        $this->put("/app/emergency/teams/{$this->tDmm->id}", ['name' => 'فريق الدمام', 'team_type' => 'fire_warden', 'shift' => 'all', 'building_id' => $m])->assertSessionHasErrors('building_id');
        $this->assertSame($d, (int) $this->tDmm->fresh()->building_id, 'فريق الدمام نُقل إلى الملز');

        $this->post('/app/emergency/drills', ['building_id' => $m, 'drill_type' => 'fire', 'scheduled_at' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('building_id');
        $this->post('/app/emergency/drills', ['building_id' => $d, 'place_id' => $this->mlzPlace, 'drill_type' => 'fire', 'scheduled_at' => now()->addDay()->toDateTimeString()])->assertSessionHasErrors('place_id');
        $this->post('/app/emergency/equipment', ['building_id' => $m, 'equipment_type' => 'fire_extinguisher', 'inspection_frequency' => 'monthly'])->assertSessionHasErrors('building_id');
        $this->post('/app/emergency/equipment', ['building_id' => $d, 'place_id' => $this->mlzPlace, 'equipment_type' => 'fire_extinguisher', 'inspection_frequency' => 'monthly'])->assertSessionHasErrors('place_id');
        $this->post('/app/emergency/contacts', ['building_id' => $m, 'name' => 'جهة مدسوسة', 'phone' => '0100', 'contact_type' => 'internal', 'priority' => 1])->assertSessionHasErrors('building_id');
        // داخل مبناه لكن المكان من الملز
        $this->post("/app/emergency/buildings/$d/assembly-points", ['code' => 'A1', 'name' => 'نقطة', 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $this->post("/app/emergency/buildings/$d/trigger", ['incident_type' => 'fire', 'severity' => 'high', 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $this->post("/app/emergency/buildings/$d/lockdown", ['level' => 'soft', 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
    }

    /** (ج) بقية الوحدات: التصريح ومعدات التصاريح والعامل والنموذج الرقمي والخطر والحساب */
    public function test_other_modules_refuse_another_building_in_the_body(): void
    {
        $this->actingAs($this->coord);
        $type = PermitType::query()->firstOrFail();
        $this->post('/app/permits', ['title' => 'تصريح مدسوس', 'permit_type_id' => $type->id, 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $this->post('/app/equipment', ['name' => 'معدة مدسوسة', 'status' => 'active', 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $party = ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor']);
        $this->post('/app/workers', ['full_name' => 'عامل', 'national_id' => '1234567890', 'trade_id' => Trade::query()->firstOrFail()->id, 'external_party_id' => $party->id, 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $this->post('/app/forms', ['title' => 'نموذج مدسوس', 'form_type' => 'awareness', 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $cat = RiskCategory::create(['name' => 'فئة', 'is_active' => true]);
        $this->post('/app/risk/create', ['category_id' => $cat->id, 'severity' => 3, 'likelihood' => 3, 'scope_type' => 'org_unit', 'organization_unit_id' => $this->branchB->id, 'assigned_coordinator_id' => $this->coord->id, 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');

        // مدير الفرع يرشّح منسق سلامة فرعه — لا بمبنى الملز ولا بمكانه
        $this->actingAs($this->farea);
        $acc = ['username' => 'new.coord', 'name' => 'منسق', 'password' => '123456', 'role' => 'safety_coordinator', 'organization_unit_id' => $this->branchB->id];
        $this->post('/app/users', $acc + ['building_id' => $this->main->id])->assertSessionHasErrors('building_id');
        $this->post('/app/users', $acc + ['building_id' => $this->b->id, 'place_id' => $this->mlzPlace])->assertSessionHasErrors('place_id');
        $this->assertFalse(User::where('username', 'new.coord')->exists(), 'حساب كُتب على الملز من مدير فرع الدمام');
    }

    /** معرّفاته هو تمرّ؛ والمركز يكتب لأي مبنى كما كان؛ وبلا مستخدم لا قيد */
    public function test_own_building_passes_and_center_and_system_stay_unrestricted(): void
    {
        $this->actingAs($this->coord);
        $d = $this->b->id;
        $this->post('/app/emergency/teams', ['name' => 'فريق جديد', 'team_type' => 'fire_warden', 'shift' => 'all', 'building_id' => $d, 'place_id' => $this->dmmPlace])->assertSessionDoesntHaveErrors(['building_id', 'place_id']);
        $this->assertTrue(EmergencyTeam::where('name', 'فريق جديد')->where('building_id', $d)->exists());
        $this->postJson('/api/emergency/visitors/check-in', ['building_id' => $d, 'place_id' => $this->dmmPlace, 'name' => 'زائر الدمام'])->assertStatus(201);
        $this->postJson('/api/emergency/panic/trigger', ['building_id' => $d, 'place_id' => $this->dmmPlace])->assertStatus(201);
        $this->getJson('/api/emergency/nearest-exit?building_id='.$d)->assertStatus(404); // مبناه بلا مخارج مسجّلة — مرّ التحقق ووصل الاستعلام

        $this->actingAs($this->salama);
        $this->post('/app/emergency/teams', ['name' => 'فريق المركز', 'team_type' => 'fire_warden', 'shift' => 'all', 'building_id' => $this->main->id, 'place_id' => $this->mlzPlace])->assertSessionDoesntHaveErrors(['building_id', 'place_id']);
        $this->postJson('/api/emergency/visitors/check-in', ['building_id' => $this->main->id, 'name' => 'زائر الملز'])->assertStatus(201);
        $this->getJson('/api/emergency/nearest-exit?building_id='.$this->main->id)->assertOk()->assertJsonPath('data.exit.code', 'X-MLZ');
        $this->post('/app/users', ['username' => 'mlz.coord', 'name' => 'منسق الملز', 'password' => '123456', 'role' => 'safety_coordinator', 'building_id' => $this->main->id, 'place_id' => $this->mlzPlace])->assertSessionDoesntHaveErrors(['building_id', 'place_id']);

        Auth::logout();
        $this->assertTrue(Validator::make(['building_id' => $this->main->id, 'place_id' => $this->mlzPlace], [
            'building_id' => InAccountBuildings::buildingRules('required'), 'place_id' => InAccountBuildings::placeRules('required'),
        ])->passes(), 'بلا مستخدم (الطابور والبذر) يجب ألا يُقيَّد شيء');
    }
}
