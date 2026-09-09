<?php

namespace Tests\Feature\Permit;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use Database\Seeders\PermitConflictRulesSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS: `tests/Feature/Permit/PermitHubControllerTest.php` (شاشات المركز والمعالج)
 * و`PermitActivationFlowTest` (التفعيل الميداني) و`PostClosureEvaluationTest` (التقييم البعدي).
 * ما تغيّر: المسارات تحت `/app/permits`، وكل مسار محميّ بصلاحية (إصلاح خطأ OHSMS: كانت `auth` وحدها).
 */
class PermitControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private PermitService $permits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PlacesSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class, TradeSeeder::class,
            PermitTypesSeeder::class, PermitConflictRulesSeeder::class, QualificationChecklistSeeder::class,
        ]);

        $this->salama = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '123456']);
        UserProfile::create(['user_id' => $this->salama->id, 'role' => 'system_admin', 'is_active' => true]);
        $this->permits = app(PermitService::class);
        $this->actingAs($this->salama);
    }

    private function permit(string $typeCode = 'hot_work', array $attrs = []): Permit
    {
        return $this->permits->create(PermitType::where('code', $typeCode)->firstOrFail(), array_merge([
            'title'           => 'تصريح اختبار',
            'place_id'        => Place::idByCode('HZ-02'),
            'requested_by_id' => $this->salama->id,
        ], $attrs));
    }

    private function approved(): Permit
    {
        $permit = $this->permit();
        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_SAFETY_APPROVED, $this->salama->id);

        return $this->permits->transition($permit, Permit::STATUS_APPROVED, $this->salama->id);
    }

    // ── القائمة والمعالج ──

    public function test_index_renders_with_empty_state(): void
    {
        $this->get('/app/permits')->assertOk()->assertSee('التصاريح')->assertSee('لا تصاريح مطابقة');
    }

    public function test_index_filters_by_category(): void
    {
        $this->permit('hot_work', ['title' => 'تصريح خاص للفلترة']);

        $this->get('/app/permits?category=special')->assertOk()->assertSee('تصريح خاص للفلترة');
        $this->get('/app/permits?category=qualification')->assertOk()->assertDontSee('تصريح خاص للفلترة');
    }

    public function test_index_filters_by_place(): void
    {
        $this->permit('hot_work', ['title' => 'في غرف الكهرباء', 'place_id' => Place::idByCode('HZ-02')]);

        $this->get('/app/permits?place_id='.Place::idByCode('HZ-02'))->assertOk()->assertSee('في غرف الكهرباء');
        $this->get('/app/permits?place_id='.Place::idByCode('HZ-08'))->assertOk()->assertDontSee('في غرف الكهرباء');
    }

    public function test_wizard_step_one_lists_types_by_category(): void
    {
        $this->get('/app/permits/create')->assertOk()
            ->assertSee('تأهيل')->assertSee('تصريح الأعمال الساخنة');
    }

    public function test_wizard_step_two_renders_for_selected_type(): void
    {
        $type = PermitType::where('code', 'hot_work')->firstOrFail();

        $this->get('/app/permits/create?step=2&permit_type_id='.$type->id)
            ->assertOk()->assertSee($type->name)->assertSee('المكان');
    }

    public function test_store_creates_draft_and_redirects_to_review(): void
    {
        $type = PermitType::where('code', 'hot_work')->firstOrFail();

        $res = $this->post('/app/permits', [
            'permit_type_id' => $type->id,
            'title'          => 'لحام في القبو',
            'place_id'       => Place::idByCode('HZ-01'),
        ]);

        $permit = Permit::where('title', 'لحام في القبو')->firstOrFail();
        $res->assertRedirect(route('permits.review', $permit));
        $this->assertSame(Permit::STATUS_DRAFT, $permit->status);
        $this->assertSame($this->salama->id, $permit->requested_by_id);
    }

    public function test_store_refuses_when_eligibility_blocks(): void
    {
        $type = PermitType::where('code', 'hot_work')->firstOrFail(); // يتطلب مكاناً

        // فحص الأهلية يمنع قبل الإنشاء ويشرح السبب بالعربية
        $this->post('/app/permits', ['permit_type_id' => $type->id, 'title' => 'بلا مكان'])
            ->assertRedirect()
            ->assertSessionHas('err', fn ($msg) => str_contains($msg, 'المكان'));

        $this->assertSame(0, Permit::where('title', 'بلا مكان')->count());
    }

    public function test_show_renders_detail_with_timeline(): void
    {
        $permit = $this->permit();

        $this->get('/app/permits/'.$permit->id)->assertOk()
            ->assertSee($permit->code)->assertSee('سجل الأحداث')->assertSee('إنشاء التصريح');
    }

    public function test_transition_moves_state_via_service(): void
    {
        $permit = $this->permit();

        $this->post("/app/permits/{$permit->id}/transition", ['to_status' => Permit::STATUS_SUBMITTED])
            ->assertRedirect(route('permits.show', $permit));

        $this->assertSame(Permit::STATUS_SUBMITTED, $permit->refresh()->status);
    }

    public function test_edit_is_limited_to_draft(): void
    {
        $permit = $this->permit();
        $this->get("/app/permits/{$permit->id}/edit")->assertOk();

        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->get("/app/permits/{$permit->id}/edit")->assertForbidden();
    }

    public function test_update_saves_draft_changes(): void
    {
        $permit = $this->permit();

        $this->put("/app/permits/{$permit->id}", [
            'title' => 'عنوان معدَّل', 'place_id' => Place::idByCode('HZ-08'),
        ])->assertRedirect();

        $permit->refresh();
        $this->assertSame('عنوان معدَّل', $permit->title);
        $this->assertSame(Place::idByCode('HZ-08'), $permit->place_id);
    }

    // ── التفعيل الميداني ──

    public function test_activate_page_requires_approved_status(): void
    {
        $permit = $this->permit();
        $this->get("/app/permits/{$permit->id}/activate")->assertForbidden();
    }

    public function test_activate_page_accessible_when_approved(): void
    {
        $permit = $this->approved();

        $this->get("/app/permits/{$permit->id}/activate")->assertOk()
            ->assertViewIs('modules.permits.activate')
            ->assertViewHas('allMandatoryPreventiveDone', false);
    }

    public function test_activate_page_flags_done_when_mandatory_preventive_complete(): void
    {
        $permit = $this->approved();
        $permit->requirements()->where('phase', 'preventive')
            ->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $this->get("/app/permits/{$permit->id}/activate")
            ->assertOk()->assertViewHas('allMandatoryPreventiveDone', true);
    }

    public function test_complete_requirement_stores_evidence_and_verifier(): void
    {
        $permit = $this->approved();
        $req = $permit->requirements()->where('evidence_type', 'check')->firstOrFail();

        $this->post("/app/permits/{$permit->id}/requirements/{$req->id}/complete", [
            'evidence_value' => 'تم التحقق', 'notes' => 'فُحص ميدانياً',
        ])->assertRedirect();

        $req->refresh();
        $this->assertSame(PermitRequirement::STATUS_COMPLETED, $req->status);
        $this->assertSame($this->salama->id, $req->completed_by_id);
        $this->assertNotNull($req->completed_at);
    }

    public function test_complete_requirement_rejects_wrong_permit(): void
    {
        $a = $this->permit();
        $b = $this->permit(attrs: ['title' => 'تصريح آخر']);
        $req = $a->requirements()->firstOrFail();

        $this->post("/app/permits/{$b->id}/requirements/{$req->id}/complete", ['evidence_value' => 'x'])
            ->assertNotFound();
    }

    public function test_toggle_requirement_reopens_a_completed_item(): void
    {
        $permit = $this->permit();
        $req = $permit->requirements()->firstOrFail();

        $this->post("/app/permits/{$permit->id}/requirements/{$req->id}/toggle")->assertRedirect();
        $this->assertTrue($req->refresh()->isComplete());

        $this->post("/app/permits/{$permit->id}/requirements/{$req->id}/toggle")->assertRedirect();
        $this->assertSame(PermitRequirement::STATUS_REQUIRED, $req->refresh()->status);
    }

    public function test_waiving_a_requirement_requires_a_reason(): void
    {
        $permit = $this->permit();
        $req = $permit->requirements()->firstOrFail();

        $this->post("/app/permits/{$permit->id}/requirements/{$req->id}/waive", [])
            ->assertSessionHasErrors('notes');

        $this->post("/app/permits/{$permit->id}/requirements/{$req->id}/waive",
            ['notes' => 'لا ينطبق على هذا الموضع لعدم وجود مواد قابلة للاشتعال'])->assertRedirect();

        $this->assertSame(PermitRequirement::STATUS_WAIVED, $req->refresh()->status);
    }

    // ── الانحرافات والتقييم ──

    public function test_deviation_can_only_be_recorded_on_active_permits(): void
    {
        $permit = $this->approved();

        $this->post("/app/permits/{$permit->id}/deviations", [
            'description' => 'وصف انحراف كافٍ الطول', 'severity' => 'medium',
        ])->assertForbidden();
    }

    public function test_evaluate_page_requires_completed_status(): void
    {
        $permit = $this->approved();
        $this->get("/app/permits/{$permit->id}/evaluate")->assertForbidden();
    }

    public function test_save_evaluation_stores_fields_and_rejects_invalid_rating(): void
    {
        $permit = $this->approved();
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);
        $this->permits->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->salama->id);
        $permit = $this->permits->transition($permit->refresh(), Permit::STATUS_COMPLETED, $this->salama->id);

        $this->get("/app/permits/{$permit->id}/evaluate")->assertOk();

        $this->post("/app/permits/{$permit->id}/evaluate", [
            'overall_rating' => 9, 'severity_match' => 'accurate',
        ])->assertSessionHasErrors('overall_rating');

        $this->post("/app/permits/{$permit->id}/evaluate", [
            'overall_rating' => 4, 'severity_match' => 'accurate', 'what_worked' => 'الحواجز أدت الغرض',
        ])->assertRedirect(route('permits.show', $permit));

        $eval = $permit->refresh()->metadata['evaluation'];
        $this->assertSame(4, $eval['overall_rating']);
        $this->assertSame($this->salama->id, $eval['evaluated_by']);
    }

    // ── اللوحة والتقرير والإعدادات ──

    public function test_dashboard_and_report_render_and_serve_json(): void
    {
        $this->get('/app/permits/dashboard')->assertOk()->assertSee('لوحة التصاريح');
        $this->getJson('/app/permits/dashboard')->assertOk()
            ->assertJsonStructure(['counts', 'expiring', 'queue', 'places', 'conflicts', 'deviations', 'equipment', 'gate']);

        $this->get('/app/permits/report')->assertOk()->assertSee('التقرير الشهري');
        $this->getJson('/app/permits/report?year='.now()->year.'&month='.now()->month)
            ->assertOk()->assertJsonPath('period.month', now()->month);
    }

    public function test_place_capacity_can_be_set_and_cleared(): void
    {
        $place = Place::where('code', 'HZ-06')->firstOrFail();

        $this->put("/app/permits/settings/places/{$place->id}", ['max_workers' => 12])->assertRedirect();
        $this->assertSame(12, $place->refresh()->max_workers);

        $this->put("/app/permits/settings/places/{$place->id}", ['max_workers' => null])->assertRedirect();
        $this->assertNull($place->refresh()->max_workers, 'الفراغ يعني بلا حد');
    }

    public function test_conflict_rule_can_be_added_and_toggled(): void
    {
        $a = PermitType::where('code', 'work_permit')->value('id');
        $b = PermitType::where('code', 'night_work')->value('id');

        $this->post('/app/permits/settings/conflict-rules', [
            'permit_type_a_id' => $a, 'permit_type_b_id' => $b, 'severity' => 'warn', 'reason' => 'سبب',
        ])->assertRedirect();

        $rule = \App\Modules\Permit\Models\PermitTypeConflictRule::where('permit_type_a_id', $a)
            ->where('permit_type_b_id', $b)->firstOrFail();
        $this->assertTrue($rule->is_active);

        $this->post("/app/permits/settings/conflict-rules/{$rule->id}/toggle")->assertRedirect();
        $this->assertFalse($rule->refresh()->is_active);
    }

    public function test_conflict_rule_refuses_same_type_on_both_sides(): void
    {
        $a = PermitType::where('code', 'work_permit')->value('id');

        $this->post('/app/permits/settings/conflict-rules', [
            'permit_type_a_id' => $a, 'permit_type_b_id' => $a, 'severity' => 'block',
        ])->assertSessionHasErrors('permit_type_b_id');
    }

    public function test_queue_lists_permits_waiting_for_a_decision(): void
    {
        $permit = $this->permit(attrs: ['title' => 'ينتظر مراجعة']);
        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);

        $this->get('/app/permits/queue')->assertOk()->assertSee('ينتظر مراجعة')->assertSee($permit->code);
    }

    public function test_attachment_upload_and_download_round_trip(): void
    {
        $permit = $this->permit();

        $this->post("/app/permits/{$permit->id}/attachments", [
            'name' => 'خطة الرفع',
            'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('plan.pdf', '%PDF-1.4 plan'),
        ])->assertRedirect();

        $att = $permit->attachments()->firstOrFail();
        $this->assertSame('%PDF-1.4 plan', base64_decode($att->data));

        $this->get("/app/permits/{$permit->id}/attachments/{$att->id}")->assertOk();
        $this->delete("/app/permits/{$permit->id}/attachments/{$att->id}")->assertRedirect();
        $this->assertSame(0, $permit->attachments()->count());
    }
}
