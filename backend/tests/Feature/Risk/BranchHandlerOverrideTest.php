<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Services\HandlerResolver;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٨٠ (بكلمته «موافق» ٢٠٢٦-١٠-٠٨): «الفرع» يتعبّأ تلقائياً عند التفعيل؛ منسق سلامة الفرع يبدّل الإدارة المعالجة لفرعه
 * ويعتمدها مدير الفرع؛ مدير الإدارة المعالجة في الفرع يسمّي المعالج على نسخة الفرع؛ والمعالج بالاسم في العام يخص مبناه.
 */
class BranchHandlerOverrideTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $hq;
    private OrganizationUnit $hqFm;
    private OrganizationUnit $branchB;
    private OrganizationUnit $dmmFm;
    private OrganizationUnit $dmmContract;
    private Risk $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $this->hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $this->hq->id]);
        $this->hqFm = OrganizationUnit::create(['code' => 'hq-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->hq->id, 'place_id' => Place::idByCode('HZ-06')]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $this->hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->dmmFm = OrganizationUnit::create(['code' => 'dmm-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);
        $this->dmmContract = OrganizationUnit::create(['code' => 'dmm-contract', 'name' => 'مكتب الصيانة — عقد', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);

        $cat = RiskCategory::create(['name' => 'الكهربائية', 'is_active' => true, 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'التمديدات']);
        $this->ref = Risk::create(['risk_type' => 'reference', 'code' => 'EL-01-01', 'title' => 'مقبس مكسور', 'description' => 'وصف', 'status' => 'approved', 'severity' => 3, 'likelihood' => 3,
            'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'handling_unit_id' => $this->hqFm->id, 'handling_unit_name' => 'المرافق والصيانة']);
    }

    private function bp(string $cat): Place
    {
        return Place::where('building_id', $this->b->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null, array $coverage = []): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        if ($coverage) $p->coverage()->sync($coverage);
        return $u;
    }

    private function activate(OrganizationUnit $unit, int $by): Risk
    {
        return app(RiskService::class)->activateFromReference($this->ref, $by, ['scope_type' => 'org_unit', 'organization_unit_id' => $unit->id, 'severity' => 3, 'likelihood' => 3], false, false);
    }

    public function test_activation_fills_the_branch_automatically(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $copyB = $this->activate($this->dmmFm, $salama->id);
        $copyHq = $this->activate($this->hqFm, $salama->id);
        $this->assertSame($this->branchB->id, $copyB->fresh()->branch_unit_id, 'نسخة الشرقية بلا فرع');
        $this->assertSame($this->hq->id, $copyHq->fresh()->branch_unit_id, 'نسخة المركز بلا فرع');
        $this->assertNull($copyB->fresh()->handling_override_state);
        $this->assertSame('المرافق والصيانة', $copyB->fresh()->handling_unit_display, 'الخاص لا يقرأ الافتراض من العام');
    }

    public function test_branch_coordinator_proposes_the_override_and_the_branch_manager_approves_it(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $munasiq = $this->user('munasiq.b', 'safety_coordinator', $this->b, null, $this->branchB);
        $farea = $this->user('farea.b', 'branch_manager', $this->b, null, $this->branchB);
        $mudirFm = $this->user('mudir.fm', 'department_manager', $this->b, null, $this->dmmFm);
        $mudirContract = $this->user('mudir.contract', 'contractor_supervisor', $this->b, null, $this->dmmContract);
        $other = $this->user('munasiq.other', 'safety_coordinator', $this->main, null, $this->hq);
        $copy = $this->activate($this->dmmFm, $salama->id);
        $place = $this->bp('HZ-01')->id;

        // الافتراض: مدير «المرافق والصيانة» في الشرقية (بالاسم)
        $this->assertSame($mudirFm->id, HandlerResolver::resolve($this->ref, $place)['user_id']);

        // منسق فرع آخر لا يملك
        $this->actingAs($other)->post(route('risk.handling.override', $copy), ['handling_unit_id' => $this->dmmContract->id])->assertForbidden();
        // منسق الفرع يقترح ← ينتظر، والتوجيه لا يتغيّر بعد
        $this->actingAs($munasiq)->post(route('risk.handling.override', $copy), ['handling_unit_id' => $this->dmmContract->id])->assertRedirect();
        $this->assertSame('pending', $copy->fresh()->handling_override_state);
        $this->assertSame($mudirFm->id, HandlerResolver::resolve($this->ref, $place)['user_id'], 'بديل غير معتمد وجّه البلاغ');
        $this->assertSame('المرافق والصيانة', $copy->fresh()->handling_unit_display);
        // بطاقة الاعتماد عند مدير الفرع
        $keys = app(\App\Modules\Risk\Inbox\RiskTasks::class)->tasksFor($farea)->map(fn ($t) => $t->key)->all();
        $this->assertContains("risk:{$copy->id}:override", $keys, 'مدير الفرع بلا بطاقة الاعتماد');
        // الموظف لا يعتمد؛ مدير الفرع يعتمد
        $this->actingAs($mudirFm)->post(route('risk.handling.override.approve', $copy))->assertForbidden();
        $this->actingAs($farea)->post(route('risk.handling.override.approve', $copy))->assertRedirect();
        $this->assertSame('approved', $copy->fresh()->handling_override_state);
        $this->assertSame('مكتب الصيانة — عقد', $copy->fresh()->handling_unit_display);
        $r = HandlerResolver::resolve($this->ref, $place);
        $this->assertSame($mudirContract->id, $r['user_id'], 'بعد الاعتماد يجب أن يذهب البلاغ إلى مكتب الصيانة');
        $this->assertStringContainsString('مكتب الصيانة — عقد', $r['note']);
        // الملز لا يتأثر
        $mudirHq = $this->user('mudir.hq', 'department_manager', $this->main, null, $this->hqFm);
        $this->assertSame($mudirHq->id, HandlerResolver::resolve($this->ref, Place::idByCode('HZ-01'))['user_id']);
        // صفحة الخطر تعرض البطاقة، ومسؤول السلامة يبدّل مباشرة
        $this->actingAs($salama)->get(route('risk.show', $copy))->assertOk()->assertSee('data-branch-handling', false)->assertSee('مكتب الصيانة — عقد');
        $this->actingAs($salama)->post(route('risk.handling.override', $copy), ['handling_unit_id' => $this->dmmFm->id])->assertRedirect();
        $this->assertSame('approved', $copy->fresh()->handling_override_state);
        $this->assertSame($this->dmmFm->id, $copy->fresh()->handling_unit_id);
    }

    public function test_branch_handling_manager_names_the_branch_handler_and_the_general_named_person_stays_in_his_building(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $mudirFm = $this->user('mudir.fm', 'department_manager', $this->b, null, $this->dmmFm);
        $faniB = $this->user('fani.b', 'tech_electrical', $this->b, $this->bp('HZ-01'), $this->dmmFm, [$this->bp('HZ-01')->id]);
        $faniM = $this->user('fani.m', 'tech_electrical', $this->main, Place::find(Place::idByCode('HZ-01')), $this->hqFm, [Place::idByCode('HZ-01')]);
        $copy = $this->activate($this->dmmFm, $salama->id);
        $place = $this->bp('HZ-01')->id;

        // شخص مسمّى في العام من الملز لا يصله بلاغ الشرقية
        $this->ref->forceFill(['handler_user_id' => $faniM->id, 'handler_set_by_id' => $salama->id, 'handler_set_at' => now()])->save();
        $this->assertSame($faniM->id, HandlerResolver::resolve($this->ref, Place::idByCode('HZ-01'))['user_id']);
        $this->assertSame($mudirFm->id, HandlerResolver::resolve($this->ref, $place)['user_id'], 'المسمّى في الملز وصله بلاغ الشرقية');

        // «إدارتي» لمدير المرافق في الشرقية تعرض نسخة الفرع، ويسمّي معالجها لفرعه
        $h = $this->actingAs($mudirFm)->get(route('risk.handlers.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-branch-copy="'.$copy->id.'"', $h, 'نسخة الفرع لا تظهر في «إدارتي»');
        $this->actingAs($mudirFm)->post(route('risk.handlers.set', $copy), ['handler' => 'user:'.$faniB->id])->assertRedirect();
        $this->assertSame($faniB->id, $copy->fresh()->handler_user_id);
        $this->assertNull($this->ref->fresh()->handler_user_id === $faniM->id ? null : 'x', 'العام مُسّ');
        $r = HandlerResolver::resolve($this->ref, $place);
        $this->assertSame($faniB->id, $r['user_id'], 'معالج نسخة الفرع لم يُقدَّم');
        $this->assertStringContainsString('من نسخة الفرع', $r['note']);
        $this->assertSame('اسم fani.b', $copy->fresh()->handler_label);

        // مدير «المرافق والصيانة» في المركز لا يرى نسخة الشرقية
        $mudirHq = $this->user('mudir.hq', 'department_manager', $this->main, null, $this->hqFm);
        $this->actingAs($mudirHq)->get(route('risk.handlers.index'))->assertOk()->assertDontSee('data-branch-copy="'.$copy->id.'"', false);
    }

    /** كُشف على المنشور (٢٠٢٦-١٠-٠٨، بلاغ ش-0043): الخط الزمني كتب «فرع الشرقية — المركز الرئيسي» — اللاحقة رأس الفرع (headOf) لا الجذر، ورأس الفرع نفسه بلا لاحقة */
    public function test_timeline_suffix_is_the_branch_head_not_the_main_center(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $farea = $this->user('farea.b', 'branch_manager', $this->b, null, $this->branchB);
        $mudirFm = $this->user('mudir.fm', 'department_manager', $this->b, null, $this->dmmFm);
        $copy = $this->activate($this->dmmFm, $salama->id);
        $place = $this->bp('HZ-01')->id;

        // الاسم نفسه داخل الفرع ← «المرافق والصيانة — فرع الشرقية» لا «— المركز الرئيسي»
        $r = HandlerResolver::resolve($this->ref, $place);
        $this->assertSame($mudirFm->id, $r['user_id']);
        $this->assertStringContainsString('«المرافق والصيانة — فرع الشرقية»', $r['note']);
        $this->assertStringNotContainsString('المركز الرئيسي', $r['note']);

        // البديل هو رأس الفرع نفسه ← «فرع الشرقية» بلا لاحقة، والبلاغ لمدير الفرع
        $this->actingAs($salama)->post(route('risk.handling.override', $copy), ['handling_unit_id' => $this->branchB->id])->assertRedirect();
        $r = HandlerResolver::resolve($this->ref, $place);
        $this->assertSame($farea->id, $r['user_id']);
        $this->assertStringContainsString('«فرع الشرقية»', $r['note']);
        $this->assertStringNotContainsString('المركز الرئيسي', $r['note']);
    }
}
