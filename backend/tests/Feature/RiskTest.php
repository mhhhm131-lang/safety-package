<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskEvent;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢ — المخاطر. البوابة: مدير إدارة يفعّل خطراً من الكتاب ويسمّي مسؤوله ويُعتمد.
 * (بذرة صغيرة بدل الكتاب الكامل حتى تبقى الاختبارات سريعة؛ الكتاب الكامل يُختبر بالعدّ.)
 */
class RiskTest extends TestCase
{
    use RefreshDatabase;

    private RiskCategory $cat;
    private RiskSubCategory $sub;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(AffectedGroupsSeeder::class);
        $this->cat = RiskCategory::create(['name' => 'مخاطر الحريق', 'abbreviation' => 'HRQ', 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الأعمال الساخنة', 'abbreviation' => 'SKN']);
    }

    private function user(string $username, string $role, ?string $unitCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null]);
        return $u;
    }

    private function masterRisk(string $title = 'حريق من أعمال اللحام'): Risk
    {
        $svc = app(RiskService::class);
        $r = $svc->createRisk(null, ['title' => $title, 'description' => $title, 'category_id' => $this->cat->id,
            'sub_category_id' => $this->sub->id, 'severity' => 5, 'likelihood' => 3, 'status' => 'approved'], 'master');
        $r->update(['status' => 'approved']);
        $r->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->first()->update(['preventive_action' => 'تصريح عمل ساخن قبل البدء']);
        return $r->fresh();
    }

    public function test_full_book_seeder_loads_264_risks_into_master_and_reference(): void
    {
        RiskCategory::query()->delete();
        $this->seed(\Database\Seeders\RiskBookSeeder::class);
        $this->assertSame(9, RiskCategory::count());
        $this->assertSame(64, RiskSubCategory::count());
        $this->assertSame(264, Risk::where('risk_type', 'master')->count());
        $this->assertSame(264, Risk::where('risk_type', 'reference')->count());
        $this->assertSame(264 * 2 * 3, RiskPhase::count());
        $this->assertTrue(RiskPhase::whereNotNull('preventive_action')->count() >= 21);
        // إعادة التشغيل لا تكرر
        $this->seed(\Database\Seeders\RiskBookSeeder::class);
        $this->assertSame(264, Risk::where('risk_type', 'master')->count());
    }

    public function test_screens_permission_matrix(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $dept = $this->user('mudir', 'department_manager', 'it');
        $tech = $this->user('fani', 'field_worker');
        $exec = $this->user('idara', 'top_management');
        $this->masterRisk();

        foreach (['/app/risk/master', '/app/risk/reference', '/app/risk/active', '/app/risk/approval/queue', '/app/risk/master/create', '/app/risk/reference/create', '/app/risk/active/create'] as $p) {
            $this->actingAs($safety)->get($p)->assertOk();
        }
        // مدير الإدارة: يرى ويفعّل، لا يعتمد ولا يعدّل الكتاب
        $this->actingAs($dept)->get('/app/risk/reference')->assertOk();
        $this->actingAs($dept)->get('/app/risk/active/create')->assertOk();
        $this->actingAs($dept)->get('/app/risk/master/create')->assertForbidden();
        $this->actingAs($dept)->get('/app/risk/approval/queue')->assertForbidden();
        // الإدارة العليا: ترى وتعتمد، لا تنشئ
        $this->actingAs($exec)->get('/app/risk/reference')->assertOk();
        $this->actingAs($exec)->get('/app/risk/approval/queue')->assertOk();
        $this->actingAs($exec)->get('/app/risk/reference/create')->assertForbidden();
        // الفني: لا مخاطر
        $this->actingAs($tech)->get('/app/risk/reference')->assertForbidden();
    }

    public function test_master_to_reference_copy_is_idempotent_and_keeps_phases(): void
    {
        $m = $this->masterRisk();
        $copy = app(RiskCopyService::class);
        $ref1 = $copy->masterToReference($m, null);
        $ref2 = $copy->masterToReference($m, null);
        $this->assertSame($ref1->id, $ref2->id);
        $this->assertSame('reference', $ref1->risk_type);
        $this->assertSame('تصريح عمل ساخن قبل البدء', $ref1->phases()->where('phase', 'proactive')->first()->preventive_action);
        $this->assertSame(3, $ref1->phases()->count());
    }

    public function test_gate_department_manager_activates_risk_names_owner_and_it_gets_approved(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $exec = $this->user('idara', 'top_management');
        $hrMgr = $this->user('hr.mgr', 'department_manager', 'hr');
        $itMgr = $this->user('it.mgr', 'department_manager', 'it');
        $m = $this->masterRisk();
        $ref = app(RiskCopyService::class)->masterToReference($m, $safety->id);
        $hr = OrganizationUnit::where('code', 'hr')->first();
        $it = OrganizationUnit::where('code', 'it')->first();
        $group = AffectedGroup::first();

        // ١) مديرة الموارد البشرية تفعّل الخطر لإدارتها وتسمّي المسؤول والمكان
        $this->actingAs($hrMgr)->post("/app/risk/{$ref->id}/activate", [
            'scope_type' => 'org_unit', 'organization_unit_id' => $hr->id, 'place_id' => Place::where('code', 'HZ-06')->value('id'),
            'severity' => 4, 'likelihood' => 3,
            'phases' => ['proactive' => ['responsible_org_unit_id' => $hr->id, 'responsible_user_text' => 'أحمد — مسؤول السلامة في الإدارة',
                'affected_group_ids' => [$group->id], 'affected_impact' => [$group->id => 'high']]],
        ])->assertRedirect('/app/risk/active');
        $active = Risk::where('risk_type', 'active')->first();
        $this->assertNotNull($active);
        $this->assertSame($hr->id, $active->organization_unit_id);
        $this->assertSame('HZ-06', $active->place->code);
        $this->assertSame(12, $active->risk_score);
        $this->assertSame('active', $active->status);
        $proactive = $active->phases()->where('phase', 'proactive')->first();
        $this->assertSame('أحمد — مسؤول السلامة في الإدارة', $proactive->responsible_user_text);
        $this->assertSame('تصريح عمل ساخن قبل البدء', $proactive->preventive_action); // موروث من الكتاب
        $this->assertSame('high', $proactive->affectedGroupDetails()->first()->impact);

        // ٢) لا تفعّل لإدارة غيرها
        $this->actingAs($hrMgr)->post("/app/risk/{$ref->id}/activate", [
            'scope_type' => 'org_unit', 'organization_unit_id' => $it->id, 'severity' => 2, 'likelihood' => 2,
        ])->assertSessionHas('error');
        $this->assertSame(1, Risk::where('risk_type', 'active')->count());

        // ٣) نطاق الرؤية: مدير تقنية المعلومات لا يرى خطر الموارد البشرية في الشجرة ولا يفتحه
        $this->actingAs($itMgr)->getJson('/app/risk/registry/tree/active/categories')->assertOk()->assertExactJson([]);
        $this->actingAs($itMgr)->get("/app/risk/{$active->id}/detail")->assertForbidden();
        $this->actingAs($hrMgr)->getJson('/app/risk/registry/tree/active/categories')->assertOk()->assertJsonCount(1);
        $this->actingAs($hrMgr)->get("/app/risk/{$active->id}/detail")->assertOk()->assertSee('حريق من أعمال اللحام');
        $this->actingAs($safety)->getJson("/app/risk/registry/tree/active/risk/{$active->id}")->assertOk()
            ->assertJsonPath('organization_unit', $hr->name)->assertJsonPath('place', 'المكاتب الإدارية');

        // ٤) الاعتماد: خطر مرجعي جديد يُقدَّم ويُعتمد من الإدارة العليا (آلة الحالة + سجل الأحداث)
        $draft = app(RiskService::class)->createRisk($safety->id, ['title' => 'خطر جديد', 'description' => 'x', 'category_id' => $this->cat->id,
            'severity' => 2, 'likelihood' => 2], 'reference');
        $this->actingAs($safety)->post("/app/risk/{$draft->id}/submit")->assertRedirect();
        $this->assertSame('pending_approval', $draft->fresh()->status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $exec->id, 'type' => 'risk.approve']);
        $this->actingAs($hrMgr)->post("/app/risk/{$draft->id}/approve", ['note' => 'x'])->assertForbidden();
        $this->actingAs($exec)->post("/app/risk/{$draft->id}/approve", ['note' => 'معتمد'])->assertRedirect();
        $this->assertSame('approved', $draft->fresh()->status);
        $this->assertSame(['created', 'submitted', 'approved'], RiskEvent::where('risk_id', $draft->id)->orderBy('id')->pluck('action')->all());
        // انتقال غير مسموح: من approved إلى approved
        $this->actingAs($exec)->post("/app/risk/{$draft->id}/approve", ['note' => 'x'])->assertSessionHas('error');
    }

    public function test_tree_endpoints_and_ajax(): void
    {
        $safety = $this->user('salama', 'system_admin');
        $m = $this->masterRisk();
        $this->actingAs($safety)->getJson('/app/risk/book/tree/categories')->assertOk()->assertJsonCount(1);
        $this->actingAs($safety)->getJson("/app/risk/book/tree/sub-categories/{$this->cat->id}")->assertOk()->assertJsonCount(1);
        $this->actingAs($safety)->getJson("/app/risk/book/tree/risks-by-sub-category/{$this->sub->id}")->assertOk()->assertJsonPath('0.id', $m->id);
        $this->actingAs($safety)->getJson("/app/risk/book/tree/risk/{$m->id}")->assertOk()->assertJsonPath('phases.0.preventive_action', 'تصريح عمل ساخن قبل البدء');
        $this->actingAs($safety)->getJson("/app/risk/ajax/subcategories?category_id={$this->cat->id}")->assertOk()->assertJsonCount(1);
        $this->actingAs($safety)->postJson('/app/risk/taxonomy/cause', ['type_category_id' => $this->sub->id, 'name' => 'شرر اللحام'])->assertOk()->assertJsonPath('name', 'شرر اللحام');
        $this->actingAs($safety)->getJson("/app/risk/ajax/causes?sub_category_id={$this->sub->id}")->assertOk()->assertJsonCount(1);
        $this->actingAs($safety)->postJson("/app/risk/copy-from-master/{$m->id}")->assertOk();
        $this->assertSame(1, Risk::where('risk_type', 'reference')->count());
        $this->assertTrue(\App\Modules\Governance\Models\AuditLog::where('model_name', 'Risk')->exists());
    }
}
