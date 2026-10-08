<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Governance\Services\ScopeService;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\HandlerResolver;
use App\Modules\Incident\Services\IncidentVisibilityService;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * مرحلة الفروع — الخطوة ٥ (قرار ٧٨): التوجيه بالاسم داخل فرع مكان البلاغ وإلا وحدة المركز الرئيسي؛
 * مدير الفرع بوحدة فرعه ومبانيه؛ المناوب بمبناه (س١ بكلمته)؛ التفعيل يعرض أماكن مبنى الوحدة؛ مهام المركز بمبانيه.
 */
class BranchesStep5Test extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private EmergencyBuilding $c;
    private OrganizationUnit $hq;
    private OrganizationUnit $hqFm;
    private OrganizationUnit $branchB;
    private OrganizationUnit $dmmFm;
    private Risk $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $this->hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $this->hq->id]);
        $this->hqFm = OrganizationUnit::create(['code' => 'hq-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => Place::idByCode('HZ-06')]);

        $this->branchB = OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'branch']);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الشرقية — المبنى الرئيسي', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->dmmFm = OrganizationUnit::create(['code' => 'dmm-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);

        $branchC = OrganizationUnit::create(['code' => 'mka', 'name' => 'فرع مكة', 'unit_type' => 'branch']);
        $this->c = EmergencyBuilding::create(['code' => 'MKA', 'name' => 'فرع مكة — المبنى الرئيسي', 'branch' => 'فرع مكة', 'branch_unit_id' => $branchC->id]);
        Place::createCategoriesFor($this->c);

        $cat = RiskCategory::create(['name' => 'الكهربائية', 'is_active' => true, 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'التمديدات']);
        $this->ref = Risk::create(['risk_type' => 'reference', 'code' => 'EL-01-01', 'title' => 'مقبس مكسور', 'description' => 'وصف', 'status' => 'approved', 'severity' => 3, 'likelihood' => 3,
            'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'handling_unit_id' => $this->hqFm->id, 'handling_unit_name' => 'المرافق والصيانة', 'handler_specialty' => 'tech_electrical']);
    }

    private function bp(string $cat, ?EmergencyBuilding $b = null): Place
    {
        return Place::where('building_id', ($b ?? $this->b)->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null, array $coverage = []): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        if ($coverage) $p->coverage()->sync($coverage);
        return $u;
    }

    public function test_routing_finds_the_handling_unit_by_name_inside_the_branch_of_the_incident_place(): void
    {
        $faniM = $this->user('fani.m', 'tech_electrical', $this->main, Place::find(Place::idByCode('HZ-01')), null, [Place::idByCode('HZ-01')]);
        $faniB = $this->user('fani.b', 'tech_electrical', $this->b, $this->bp('HZ-01'), null, [$this->bp('HZ-01')->id]);
        $mudirM = $this->user('mudir.m', 'department_manager', $this->main, null, $this->hqFm);
        $mudirB = $this->user('mudir.b', 'department_manager', $this->b, null, $this->dmmFm);

        $this->assertSame($faniB->id, HandlerResolver::resolve($this->ref, $this->bp('HZ-01')->id)['user_id'], 'بلاغ الشرقية لم يذهب إلى فني الشرقية');
        $this->assertSame($faniM->id, HandlerResolver::resolve($this->ref, Place::idByCode('HZ-01'))['user_id'], 'بلاغ الملز لم يذهب إلى فني الملز');

        $faniB->profile->update(['is_active' => false]);
        $r = HandlerResolver::resolve($this->ref, $this->bp('HZ-01')->id);
        $this->assertSame($mudirB->id, $r['user_id'], 'بلا فني في الشرقية: يجب أن يذهب إلى مدير «المرافق والصيانة» في الشرقية لا في المركز');
        $this->assertStringContainsString('فرع الشرقية', $r['note'], 'الخط الزمني لا يسمّي الفرع');

        // فرع بلا وحدة بهذا الاسم: وحدة المركز الرئيسي
        $r = HandlerResolver::resolve($this->ref, $this->bp('HZ-01', $this->c)->id);
        $this->assertSame($mudirM->id, $r['user_id'], 'فرع بلا الوحدة: يجب أن يعود إلى المركز الرئيسي');
    }

    public function test_branch_manager_scope_follows_the_branch_unit(): void
    {
        $farea = $this->user('farea.b', 'branch_manager', null, null, $this->branchB);
        $codes = ScopeService::forUser($farea)->codes();
        $this->assertContains('HZ-06/DMM', $codes);
        $this->assertNotContains('HZ-06', $codes, 'مدير فرع الشرقية يرى أماكن الملز');
        $this->assertNotContains('HZ-06/MKA', $codes);
        $this->assertSame([$this->b->id], BuildingContext::choices($farea)->pluck('id')->all());

        $v = app(IncidentVisibilityService::class);
        $inB = Incident::create(['code' => 'ش-0201', 'title' => 'ب', 'description' => 'بلاغ الشرقية', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $this->bp('HZ-01')->id]);
        $inM = Incident::create(['code' => 'ش-0202', 'title' => 'م', 'description' => 'بلاغ الملز', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => Place::idByCode('HZ-01')]);
        $this->assertTrue($v->canView($inB, $farea->id));
        $this->assertFalse($v->canView($inM, $farea->id));
    }

    public function test_duty_officer_sees_only_its_building_and_safety_officer_sees_all(): void
    {
        $munawibB = $this->user('munawib.b', 'system_staff', $this->b, $this->bp('HZ-00'));
        $salama = $this->user('salama', 'system_admin');
        $codes = ScopeService::forUser($munawibB)->codes();
        $this->assertContains('HZ-01/DMM', $codes);
        $this->assertNotContains('HZ-01', $codes, 'مناوب الشرقية يرى أماكن الملز');
        $this->assertFalse(BuildingContext::canSwitch($munawibB), 'المناوب لا يبدّل المبنى');
        $this->assertTrue(BuildingContext::canSwitch($salama));

        $v = app(IncidentVisibilityService::class);
        $inB = Incident::create(['code' => 'ش-0301', 'title' => 'ب', 'description' => 'بلاغ الشرقية', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $this->bp('HZ-01')->id]);
        $inM = Incident::create(['code' => 'ش-0302', 'title' => 'م', 'description' => 'بلاغ الملز', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => Place::idByCode('HZ-01')]);
        $this->assertTrue($v->canView($inB, $munawibB->id));
        $this->assertFalse($v->canView($inM, $munawibB->id), 'مناوب الشرقية يرى بلاغ الملز');
        $this->assertSame(1, $v->getVisibleIncidents($munawibB->id)->count());
        $this->assertSame(2, $v->getVisibleIncidents($salama->id)->count());
        $this->assertTrue($v->canView($inB, $salama->id) && $v->canView($inM, $salama->id));

        // مهام المركز: الحالة المنتهية بلا تقرير في الملز لا تصل مناوب الشرقية، وحالة الشرقية تصله
        EmergencyIncident::create(['building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-01'), 'incident_code' => 'ط-9101', 'incident_type' => 'fire', 'status' => 'ended', 'is_drill' => false, 'triggered_at' => now()->subHour(), 'ended_at' => now()]);
        $eb = EmergencyIncident::create(['building_id' => $this->b->id, 'place_id' => $this->bp('HZ-01')->id, 'incident_code' => 'ط-9102', 'incident_type' => 'fire', 'status' => 'ended', 'is_drill' => false, 'triggered_at' => now()->subHour(), 'ended_at' => now()]);
        $keys = app(\App\Modules\Emergency\Inbox\EmergencyTasks::class)->tasksFor($munawibB)->map(fn ($t) => $t->key)->all();
        $this->assertContains('aarmissing:'.$eb->id, $keys);
        $this->assertCount(1, array_filter($keys, fn ($k) => str_starts_with($k, 'aarmissing:')), 'مناوب الشرقية يرى حالات الملز');
        $this->assertCount(2, array_filter(app(\App\Modules\Emergency\Inbox\EmergencyTasks::class)->tasksFor($salama)->map(fn ($t) => $t->key)->all(), fn ($k) => str_starts_with($k, 'aarmissing:')));
    }

    /** بكلمته (٢٠٢٦-١٠-٠٨): الملز هو المركز الرئيسي، والفروع الأربعة تحته — نطاق الملز لا يشمل ما تحت فروعه */
    public function test_branches_nested_under_the_main_center_keep_their_scopes_apart(): void
    {
        $this->branchB->update(['parent_id' => $this->hq->id]);
        // «المرافق والصيانة» في المركز تُعاد بمعرّف أعلى من نظيرتها في الشرقية — الترتيب بالمعرّف لا يكفي
        $this->ref->update(['handling_unit_id' => null]);
        $this->hqFm->delete();
        $this->hqFm = OrganizationUnit::create(['code' => 'hq-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => Place::idByCode('HZ-06')]);
        $this->ref->update(['handling_unit_id' => $this->hqFm->id]);

        $mainIds = \App\Modules\Governance\Services\DeptSync::scopeIds($this->main->id);
        $this->assertContains($this->hqFm->id, $mainIds);
        $this->assertNotContains($this->dmmFm->id, $mainIds, 'نطاق الملز يشمل إدارة الشرقية');
        $this->assertNotContains($this->branchB->id, $mainIds, 'نطاق الملز يشمل وحدة فرع الشرقية');
        $this->assertSame(['dmm', 'dmm-fm'], array_column(app(\App\Modules\Governance\Services\DeptSync::class)->toDocument($this->b->id), 'id'));
        $this->assertNotContains('dmm-fm', array_column(app(\App\Modules\Governance\Services\DeptSync::class)->toDocument($this->main->id), 'id'), 'لوحة الملز تعرض إدارات الشرقية');

        $faniM = $this->user('fani.m', 'tech_electrical', $this->main, Place::find(Place::idByCode('HZ-01')), null, [Place::idByCode('HZ-01')]);
        $mudirM = $this->user('mudir.m', 'department_manager', $this->main, null, $this->hqFm);
        $mudirB = $this->user('mudir.b', 'department_manager', $this->b, null, $this->dmmFm);
        $this->assertSame($faniM->id, HandlerResolver::resolve($this->ref, Place::idByCode('HZ-01'))['user_id']);
        $this->assertSame($mudirB->id, HandlerResolver::resolve($this->ref, $this->bp('HZ-01')->id)['user_id'], 'بلاغ الشرقية لم يجد وحدتها تحت المركز الرئيسي');
        $faniM->profile->update(['is_active' => false]);
        $this->assertSame($mudirM->id, HandlerResolver::resolve($this->ref, Place::idByCode('HZ-01'))['user_id'], 'بلاغ الملز ذهب إلى مدير الشرقية');

        // مدير الفرع المتداخل يرى مبنى فرعه وحده، ومدير المركز الرئيسي (وحدته الجذر) يرى الكل
        $fareaB = $this->user('farea.b', 'branch_manager', null, null, $this->branchB);
        $this->assertSame([$this->b->id], BuildingContext::choices($fareaB)->pluck('id')->all());
        $this->assertSame([$this->main->id, $this->b->id], BuildingContext::choices($this->user('farea.hq', 'branch_manager', null, null, $this->hq))->pluck('id')->sort()->values()->all());
    }

    public function test_activation_form_lists_the_places_of_the_unit_building(): void
    {
        $mudirB = $this->user('mudir.b', 'department_manager', $this->b, null, $this->dmmFm);
        $this->actingAs($mudirB);
        $html = $this->get(route('risk.activate.form', $this->ref))->assertOk()->getContent();
        $this->assertStringContainsString('HZ-01/DMM', $html);
        $this->assertStringNotContainsString('HZ-01/MKA', $html, 'نموذج التفعيل يعرض أماكن فرع آخر');
        $this->assertStringNotContainsString('>HZ-01 —', $html, 'نموذج التفعيل يعرض أماكن الملز لمدير في الشرقية');
    }
}
