<?php

namespace Tests\Feature\Permit;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Equipment;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitDeviation;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use Database\Seeders\PermitConflictRulesSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\TradeSeeder;
use Database\Seeders\TrainingTopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٦-ب — بوابة المرحلة (BACKEND.md ٧-٢): تصريح أعمال ساخنة كاملاً مع منع التعارض.
 *
 * ما يخص المعهد ولا وجود له في OHSMS: منطقة العمل هي المكان، سعة المكان تمنع التجاوز،
 * الاعتماد النهائي عند مسؤول السلامة والمناوب وحدهما، الانحراف المفتوح يمنع الإغلاق،
 * وأدلة البنود base64 في القاعدة.
 */
class PermitScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;   // مسؤول السلامة
    private User $munawib;  // مناوب المركز
    private User $coord;    // منسق السلامة
    private User $fani;     // الفني المنفّذ
    private PermitService $permits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PlacesSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class,
            TradeSeeder::class, TrainingTopicSeeder::class,
            PermitTypesSeeder::class, PermitConflictRulesSeeder::class, QualificationChecklistSeeder::class,
        ]);

        $this->salama  = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->coord   = $this->user('coord', 'safety_coordinator');
        $this->fani    = $this->user('fani', 'field_worker');
        $this->permits = app(PermitService::class);
    }

    private function user(string $username, string $role, ?int $partyId = null): User
    {
        $u = User::create([
            'username' => $username, 'name' => "اسم {$username}", 'password' => '123456',
            'email' => "{$username}@example.test", 'external_party_id' => $partyId,
        ]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);

        return $u;
    }

    private function placeId(string $code): int
    {
        return Place::idByCode($code);
    }

    private function hotWorkPermit(array $overrides = []): Permit
    {
        $type = PermitType::where('code', 'hot_work')->firstOrFail();

        return $this->permits->create($type, array_merge([
            'title'           => 'لحام دعامة في غرفة الكهرباء',
            'place_id'        => $this->placeId('HZ-02'),
            'sub_location'    => 'اللوحة الرئيسية ٧',
            'workers_count'   => 2,
            'starts_at'       => now(),
            'expires_at'      => now()->addDays(3),
            'requested_by_id' => $this->salama->id,
        ], $overrides));
    }

    /** يقود التصريح إلى «معتمد» عبر المسار الصحيح (أعمال ساخنة = اعتماد بمرحلتين). */
    private function approve(Permit $permit): Permit
    {
        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_SAFETY_APPROVED, $this->coord->id);

        return $this->permits->transition($permit, Permit::STATUS_APPROVED, $this->salama->id);
    }

    private function completeMandatory(Permit $permit): void
    {
        $permit->requirements()
            ->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->update(['status' => PermitRequirement::STATUS_COMPLETED, 'completed_at' => now()]);
    }

    // ════════════ البوابة: السيناريو الكامل ════════════

    public function test_gate_hot_work_permit_full_cycle_with_conflict_block(): void
    {
        $s = $this->actingAs($this->salama);

        // ١) الشاشات تفتح لمسؤول السلامة
        foreach (['/app/permits', '/app/permits/create', '/app/permits/queue', '/app/permits/dashboard',
                  '/app/permits/settings', '/app/permits/gate', '/app/permits/gate/logs', '/app/equipment'] as $url) {
            $s->get($url)->assertOk();
        }

        // ٢) إنشاء تصريح أعمال ساخنة في غرفة الكهرباء — يرث مخاطر المكان ويولّد بنود التحكم
        $hzRiskCount = $this->seedPlaceRisk('HZ-02', 'مخاطر الحريق');
        $permit = $this->hotWorkPermit();

        $this->assertSame(Permit::STATUS_DRAFT, $permit->status);
        $this->assertStringStartsWith('ت-'.now()->format('Y'), $permit->code);
        $this->assertGreaterThan(0, $permit->requirements()->count(), 'بنود التحكم تُشتق من نوع التصريح');
        $this->assertSame($hzRiskCount, $permit->risks()->count(), 'مخاطر المكان الفعّالة تُربط آلياً');

        // ٣) اعتماد بمرحلتين: الاعتماد المباشر من المراجعة مرفوض
        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        try {
            $this->permits->transition($permit, Permit::STATUS_APPROVED, $this->salama->id);
            $this->fail('كان يجب رفض الاعتماد المباشر لنوع بمرحلتين');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('اعتماد السلامة', $e->getMessage());
        }

        // ٤) المسار الصحيح: اعتماد السلامة ثم الاعتماد النهائي
        $this->permits->transition($permit, Permit::STATUS_SAFETY_APPROVED, $this->coord->id);
        $permit = $this->permits->transition($permit, Permit::STATUS_APPROVED, $this->salama->id);
        $this->assertNotNull($permit->safety_approved_at);
        $this->assertSame($this->salama->id, $permit->approved_by_id);

        // ٥) التفعيل ممنوع والبنود الإلزامية ناقصة
        try {
            $this->permits->transition($permit, Permit::STATUS_ACTIVE, $this->salama->id);
            $this->fail('كان يجب منع التفعيل قبل اكتمال البنود الإلزامية');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('بنود إلزامية', $e->getMessage());
        }

        // ٦) اكتمال البنود ← التفعيل
        $this->completeMandatory($permit);
        $permit = $this->permits->transition($permit, Permit::STATUS_ACTIVE, $this->salama->id);
        $this->assertSame(Permit::STATUS_ACTIVE, $permit->status);

        // ٧) منع التعارض: نقل مواد خطرة في المكان نفسه لا يُعتمد (قاعدة مانعة مبذورة)
        $hazmat = $this->permits->create(
            PermitType::where('code', 'hazmat_transport')->firstOrFail(),
            ['title' => 'نقل أسطوانات', 'place_id' => $this->placeId('HZ-02'),
             'starts_at' => now(), 'expires_at' => now()->addDay(), 'requested_by_id' => $this->salama->id],
        );
        $this->permits->transition($hazmat, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($hazmat, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        $this->permits->transition($hazmat, Permit::STATUS_SAFETY_APPROVED, $this->coord->id);
        try {
            $this->permits->transition($hazmat, Permit::STATUS_APPROVED, $this->salama->id);
            $this->fail('كان يجب منع الاعتماد لتعارض مانع');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('تعارض مانع', $e->getMessage());
        }

        // ٨) في مكان آخر لا تعارض
        $hazmat->update(['place_id' => $this->placeId('HZ-08')]);
        $hazmat = $this->permits->transition($hazmat->refresh(), Permit::STATUS_APPROVED, $this->salama->id);
        $this->assertSame(Permit::STATUS_APPROVED, $hazmat->status);

        // ٩) انحراف مفتوح يمنع الإغلاق
        PermitDeviation::create([
            'permit_id' => $permit->id, 'description' => 'وُجد كرتون قرب موضع اللحام لم يكن في الخطة',
            'severity' => 'high', 'status' => PermitDeviation::STATUS_OPEN,
            'recorded_by_id' => $this->fani->id, 'recorded_at' => now(),
        ]);
        try {
            $this->permits->transition($permit, Permit::STATUS_COMPLETED, $this->salama->id);
            $this->fail('كان يجب منع الإغلاق وفيه انحراف مفتوح');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('انحراف مفتوح', $e->getMessage());
        }

        // ١٠) معالجة الانحراف ← الإغلاق
        $permit->deviations()->update(['status' => PermitDeviation::STATUS_RESOLVED, 'resolved_at' => now()]);
        $permit = $this->permits->transition($permit->refresh(), Permit::STATUS_COMPLETED, $this->salama->id);
        $this->assertSame(Permit::STATUS_COMPLETED, $permit->status);

        // ١١) السجل الزمني يحفظ الرحلة كاملة
        $events = $permit->events()->pluck('to_status')->filter()->all();
        $this->assertEqualsCanonicalizing(
            ['submitted', 'under_review', 'safety_approved', 'approved', 'active', 'completed'],
            $events,
        );
    }

    // ════════════ الأدوار ════════════

    public function test_final_approval_is_limited_to_safety_officer_and_duty_staff(): void
    {
        $permit = $this->hotWorkPermit();
        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_SAFETY_APPROVED, $this->coord->id);

        // المنسق يراجع ويعتمد أمنياً، ولا يعتمد نهائياً (قرار ٤-٣-ب)
        $this->assertFalse($this->coord->can('approve', $permit));
        $this->assertTrue($this->salama->can('approve', $permit));
        $this->assertTrue($this->munawib->can('approve', $permit));
        $this->assertFalse($this->fani->can('approve', $permit));
    }

    public function test_field_worker_is_forbidden_from_permit_screens(): void
    {
        $permit = $this->hotWorkPermit();

        $this->actingAs($this->fani)->get('/app/permits')->assertForbidden();
        $this->actingAs($this->fani)->get('/app/permits/'.$permit->id)->assertForbidden();
        $this->actingAs($this->fani)->get('/app/permits/settings')->assertForbidden();
    }

    public function test_contractor_account_sees_only_its_own_permits(): void
    {
        $mine   = ExternalParty::create(['name' => 'شركتنا', 'party_type' => 'contractor', 'status' => 'active']);
        $theirs = ExternalParty::create(['name' => 'شركة أخرى', 'party_type' => 'contractor', 'status' => 'active']);
        $muqawil = $this->user('muqawil', 'contractor_supervisor', $mine->id);

        $type = PermitType::where('code', 'work_permit')->firstOrFail();
        $mineP = $this->permits->create($type, ['title' => 'تصريحنا', 'place_id' => $this->placeId('HZ-06'),
            'external_party_id' => $mine->id, 'requested_by_id' => $this->salama->id]);
        $theirsP = $this->permits->create($type, ['title' => 'تصريحهم', 'place_id' => $this->placeId('HZ-06'),
            'external_party_id' => $theirs->id, 'requested_by_id' => $this->salama->id]);

        $res = $this->actingAs($muqawil)->get('/app/permits');
        $res->assertOk()->assertSee($mineP->code)->assertDontSee($theirsP->code);

        // فتح تصريح طرف آخر ممنوع
        $this->actingAs($muqawil)->get('/app/permits/'.$theirsP->id)->assertForbidden();
        $this->actingAs($muqawil)->get('/app/permits/'.$mineP->id)->assertOk();
    }

    // ════════════ سعة المكان ════════════

    public function test_place_capacity_blocks_approval_when_limit_exceeded(): void
    {
        $place = Place::where('code', 'HZ-02')->first();
        $place->update(['max_workers' => 5]);

        // تصريح نشط يشغل ٤ عمال
        $running = $this->hotWorkPermit(['title' => 'عمل جارٍ', 'workers_count' => 4]);
        $this->approve($running);
        $this->completeMandatory($running);
        $this->permits->transition($running, Permit::STATUS_ACTIVE, $this->salama->id);

        // تصريح ثانٍ بعاملين يتجاوز الحد (٦ > ٥)
        $second = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل ثانٍ', 'place_id' => $place->id, 'workers_count' => 2,
            'starts_at' => now(), 'expires_at' => now()->addDay(), 'requested_by_id' => $this->salama->id,
        ]);
        $this->permits->transition($second, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($second, Permit::STATUS_UNDER_REVIEW, $this->salama->id);

        try {
            $this->permits->transition($second, Permit::STATUS_APPROVED, $this->salama->id);
            $this->fail('كان يجب منع الاعتماد لتجاوز سعة المكان');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('حد العمال', $e->getMessage());
        }

        // بلا حد مُدخَل لا يوجد فحص سعة
        $place->update(['max_workers' => null]);
        $second = $this->permits->transition($second->refresh(), Permit::STATUS_APPROVED, $this->salama->id);
        $this->assertSame(Permit::STATUS_APPROVED, $second->status);
    }

    // ════════════ العمال وجاهزيتهم ════════════

    public function test_worker_with_expired_medical_blocks_activation_and_gate(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول الصيانة', 'party_type' => 'contractor', 'status' => 'active']);
        $trade = Trade::where('is_active', true)->first();

        $worker = Worker::create([
            'external_party_id' => $party->id, 'full_name' => 'عامل منتهي الفحص', 'national_id' => '2000000001',
            'trade_id' => $trade?->id, 'status' => 'work_authorized', 'place_id' => $this->placeId('HZ-02'),
            'medical_expiry' => now()->subDay(),
        ]);

        $permit = $this->hotWorkPermit(['external_party_id' => $party->id]);
        $this->approve($permit);
        $this->completeMandatory($permit);

        $this->actingAs($this->salama)
            ->post("/app/permits/{$permit->id}/workers", ['worker_id' => $worker->id])
            ->assertRedirect();

        $this->assertDatabaseHas('permit_workers', [
            'permit_id' => $permit->id, 'worker_id' => $worker->id, 'qualification_status' => 'not_qualified',
        ]);

        try {
            $this->permits->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->salama->id);
            $this->fail('كان يجب منع التفعيل لثغرة عالية في عامل');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('الفحص الطبي منتهٍ', $e->getMessage());
        }

        // شاشة الجاهزية تمنعه أيضاً وتسجّل السبب
        $res = $this->actingAs($this->salama)->postJson('/app/permits/gate/check', [
            'worker_identifier' => '2000000001', 'place_id' => $this->placeId('HZ-02'),
        ]);
        $res->assertOk()->assertJsonPath('allowed', false);
        $this->assertContains('medical_expired', collect($res->json('denial_reasons'))->pluck('code')->all());
        $this->assertDatabaseHas('gate_logs', ['worker_id' => $worker->id, 'result' => 'denied']);
    }

    public function test_gate_auto_creates_individual_permit_when_only_blocker_is_missing_permit(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول جاهز', 'party_type' => 'contractor', 'status' => 'active']);
        $worker = Worker::create([
            'external_party_id' => $party->id, 'full_name' => 'عامل مؤهَّل', 'national_id' => '2000000002',
            'trade_id' => Trade::where('is_active', true)->value('id'),
            'status' => 'work_authorized', 'medical_expiry' => now()->addYear(),
        ]);

        // تصريح عمل تشغيلي نشط للمقاول = الاعتماد التنظيمي
        $work = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'تصريح المقاول التشغيلي', 'place_id' => $this->placeId('HZ-06'),
            'external_party_id' => $party->id, 'starts_at' => now(), 'expires_at' => now()->addDays(30),
            'requested_by_id' => $this->salama->id,
        ]);
        $this->permits->transition($work, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($work, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        $this->permits->transition($work, Permit::STATUS_APPROVED, $this->salama->id);
        $this->completeMandatory($work);
        $this->permits->transition($work->refresh(), Permit::STATUS_ACTIVE, $this->salama->id);

        $res = $this->actingAs($this->salama)->postJson('/app/permits/gate/check', [
            'worker_identifier' => '2000000002', 'place_id' => $this->placeId('HZ-06'),
        ]);

        $res->assertOk()->assertJsonPath('allowed', true)->assertJsonPath('auto_permit', true);

        $auto = Permit::where('scope', Permit::SCOPE_INDIVIDUAL)->where('subject_id', $worker->id)->first();
        $this->assertNotNull($auto, 'يُنشأ تصريح دخول فردي آلياً');
        $this->assertSame(Permit::STATUS_ACTIVE, $auto->status);
        $this->assertSame($work->id, $auto->parent_permit_id);
    }

    // ════════════ البنود والأدلة ════════════

    public function test_measurement_below_threshold_is_recorded_as_failed_and_blocks_activation(): void
    {
        $permit = $this->hotWorkPermit();
        $this->approve($permit);
        $this->completeMandatory($permit);

        // بند قياس بحدّ صريح
        $req = PermitRequirement::create([
            'permit_id' => $permit->id, 'category' => PermitRequirement::CATEGORY_RISK_CONTROL,
            'requirement_code' => 'rc:test-measure', 'description_ar' => 'قياس نسبة الأكسجين',
            'phase' => 'preventive', 'evidence_type' => 'measurement',
            'severity' => PermitRequirement::SEVERITY_MANDATORY,
            'risk_control_id' => \App\Modules\Risk\Models\RiskControl::create([
                'risk_category_id' => RiskCategory::first()->id, 'phase' => 'preventive',
                'description_ar' => 'قياس نسبة الأكسجين', 'evidence_type' => 'measurement',
                'measurement_unit' => '%', 'measurement_threshold' => '>= 19.5',
            ])->id,
        ]);

        $this->actingAs($this->salama)
            ->post("/app/permits/{$permit->id}/requirements/{$req->id}/complete", ['evidence_value' => '17.2'])
            ->assertRedirect();

        $req->refresh();
        $this->assertSame(PermitRequirement::STATUS_FAILED, $req->status);
        $this->assertFalse($req->measurement_passed);

        try {
            $this->permits->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->salama->id);
            $this->fail('كان يجب منع التفعيل وبند القياس لم يجتز');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('بنود إلزامية', $e->getMessage());
        }

        // قراءة مطابقة تُجيز
        $this->actingAs($this->salama)
            ->post("/app/permits/{$permit->id}/requirements/{$req->id}/complete", ['evidence_value' => '20.9'])
            ->assertRedirect();
        $req->refresh();
        $this->assertSame(PermitRequirement::STATUS_COMPLETED, $req->status);
        $this->assertTrue($req->measurement_passed);

        $this->assertSame(Permit::STATUS_ACTIVE,
            $this->permits->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->salama->id)->status);
    }

    public function test_evidence_file_is_stored_in_database_and_downloadable(): void
    {
        $permit = $this->hotWorkPermit();
        $req = $permit->requirements()->first();

        $this->actingAs($this->salama)->post("/app/permits/{$permit->id}/requirements/{$req->id}/complete", [
            'evidence_value' => 'شهادة رقم ١٢',
            'evidence_file'  => UploadedFile::fake()->createWithContent('cert.pdf', '%PDF-1.4 gate6b'),
        ])->assertRedirect();

        $req->refresh();
        $this->assertTrue($req->hasEvidenceFile(), 'الدليل يُحفظ base64 في القاعدة');
        $this->assertSame('%PDF-1.4 gate6b', base64_decode($req->evidence_file_data));

        $this->actingAs($this->salama)
            ->get("/app/permits/{$permit->id}/requirements/{$req->id}/evidence")
            ->assertOk();
    }

    public function test_rejection_requires_a_written_reason(): void
    {
        $permit = $this->hotWorkPermit();
        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->salama->id);

        $this->expectException(TransitionException::class);
        $this->permits->transition($permit, Permit::STATUS_REJECTED, $this->salama->id);
    }

    // ════════════ التأهيل والتقييم البعدي ════════════

    public function test_qualification_permit_uses_checklist_catalog_not_risk_controls(): void
    {
        $project = Project::create(['name' => 'مشروع صيانة', 'status' => 'active', 'place_id' => $this->placeId('HZ-06')]);
        $party = ExternalParty::create(['name' => 'مقاول التأهيل', 'party_type' => 'contractor', 'status' => 'active']);

        $permit = $this->permits->create(PermitType::where('code', 'contractor_pre_qualification')->firstOrFail(), [
            'title' => 'تأهيل مقاول التأهيل', 'project_id' => $project->id, 'external_party_id' => $party->id,
            'subject_type' => Permit::SUBJECT_EXTERNAL_PARTY, 'subject_id' => $party->id,
            'requested_by_id' => $this->salama->id,
        ]);

        $this->assertGreaterThanOrEqual(9, $permit->requirements()->count());
        $this->assertSame($permit->requirements()->count(),
            $permit->requirements()->where('category', PermitRequirement::CATEGORY_QUALIFICATION)->count());
        // تصاريح التأهيل لا تشغل سعة ولا تتعارض
        $this->assertFalse(app(\App\Modules\Permit\Services\PermitConflictService::class)->check($permit)['has_blocks']);
    }

    public function test_work_permit_blocked_when_contractor_not_post_approved(): void
    {
        $project = Project::create(['name' => 'مشروع', 'status' => 'active', 'place_id' => $this->placeId('HZ-06')]);
        $party = ExternalParty::create(['name' => 'مقاول غير مؤهَّل', 'party_type' => 'contractor', 'status' => 'active']);
        ProjectContractor::create([
            'project_id' => $project->id, 'external_party_id' => $party->id,
            'role' => 'main', 'qualification_status' => 'pre_review',
        ]);

        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل قبل التأهيل', 'place_id' => $this->placeId('HZ-06'),
            'project_id' => $project->id, 'external_party_id' => $party->id, 'requested_by_id' => $this->salama->id,
        ]);

        $blockers = app(\App\Modules\Permit\Services\PermitEligibilityService::class)->blockers($permit);
        $this->assertTrue(collect($blockers)->contains(fn ($b) => str_contains($b, 'تأهيل ما بعد التعاقد')));
    }

    public function test_post_closure_evaluation_links_incidents_and_flags_controls(): void
    {
        $permit = $this->hotWorkPermit();
        $this->approve($permit);
        $this->completeMandatory($permit);
        $this->permits->transition($permit, Permit::STATUS_ACTIVE, $this->salama->id);
        $permit = $this->permits->transition($permit->refresh(), Permit::STATUS_COMPLETED, $this->salama->id);

        // بلاغ شاغل وقع في المكان أثناء مدة التصريح (البلاغ يلزمه خطر — مراقب البلاغات)
        $incident = \App\Modules\Incident\Models\Incident::create([
            'code' => 'ش-9001', 'title' => 'دخان في غرفة الكهرباء', 'incident_type' => 'urgent',
            'status' => 'new', 'place_id' => $this->placeId('HZ-02'),
            'risk_id' => Risk::where('place_id', $this->placeId('HZ-02'))->value('id')
                ?? Risk::where('risk_type', 'master')->value('id'),
        ]);

        $this->actingAs($this->salama)->post("/app/permits/{$permit->id}/evaluate", [
            'overall_rating' => 2, 'severity_match' => 'underestimated',
            'what_failed' => 'الحاجز الواقي لم يمنع تطاير الشرر إلى الممر المجاور.',
        ])->assertRedirect();

        $this->assertSame($permit->id, $incident->refresh()->permit_id, 'بلاغات فترة التصريح تُربط به');

        $controlIds = $permit->requirements()->whereNotNull('risk_control_id')->pluck('risk_control_id');
        $this->assertGreaterThan(0,
            \App\Modules\Risk\Models\RiskControl::whereIn('id', $controlIds)->where('review_flag', true)->count(),
            'التقييم الضعيف يعلّم بنود التحكم للمراجعة');
    }

    // ════════════ المعدات ════════════

    public function test_failed_equipment_inspection_takes_it_out_of_service(): void
    {
        $eq = Equipment::create([
            'name' => 'رافعة شوكية', 'code' => 'FL-1', 'equipment_type' => 'lifting',
            'status' => 'active', 'place_id' => $this->placeId('HZ-08'), 'inspection_frequency_days' => 90,
        ]);

        $this->actingAs($this->salama)->post("/app/equipment/{$eq->id}/inspections", [
            'inspection_date' => now()->toDateString(), 'result' => 'fail', 'findings' => 'تسرّب زيت',
        ])->assertRedirect();

        $eq->refresh();
        $this->assertSame('out_of_service', $eq->status);
        $this->assertSame(now()->addDays(90)->toDateString(), $eq->next_inspection_date->toDateString());
    }

    /** يبذر خطراً فعّالاً على مكان ويعيد عدد المخاطر الفعّالة فيه. */
    private function seedPlaceRisk(string $placeCode, string $categoryName): int
    {
        $category = RiskCategory::where('name', $categoryName)->firstOrFail();
        Risk::create([
            'risk_type' => 'active', 'title' => 'شرر اللحام قرب مواد قابلة للاشتعال',
            'category_id' => $category->id, 'place_id' => $this->placeId($placeCode),
            'severity' => 4, 'likelihood' => 3, 'status' => 'active',
        ]);

        return Risk::where('place_id', $this->placeId($placeCode))->where('risk_type', 'active')
            ->whereNotIn('status', ['closed', 'rejected'])->count();
    }
}
