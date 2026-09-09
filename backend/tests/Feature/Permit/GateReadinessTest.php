<?php

namespace Tests\Feature\Permit;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\GateLog;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\GateReadinessService;
use App\Modules\Permit\Services\GateService;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Permit\Services\WorkerGapRiskService;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Worker\Models\CompetencyRequirement;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\TrainingTopic;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerDocument;
use App\Modules\Worker\Models\WorkerTrainingRecord;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\TradeSeeder;
use Database\Seeders\TrainingTopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * منقول من OHSMS: `tests/Feature/WorkPermit/QualificationServiceTest.php` و`GateServiceTest.php`
 * («جاهزية البوابة» — BACKEND.md ٥-٦).
 *
 * ما تغيّر: بلا مستأجرين ولا نظام التصاريح القديم؛ الفحص بالمكان لا بمنطقة العمل؛
 * قواعد الثغرات في مصدر واحد (WorkerGapRiskService) تقرأ منه الجاهزية.
 */
class GateReadinessTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private ExternalParty $party;
    private Trade $trade;
    private GateService $gate;
    private PermitService $permits;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PlacesSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class,
            TradeSeeder::class, TrainingTopicSeeder::class,
            PermitTypesSeeder::class, QualificationChecklistSeeder::class,
        ]);

        $this->salama = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '123456']);
        UserProfile::create(['user_id' => $this->salama->id, 'role' => 'system_admin', 'is_active' => true]);

        $this->party = ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor', 'status' => 'active']);
        $this->trade = Trade::where('is_active', true)->firstOrFail();
        $this->gate = app(GateService::class);
        $this->permits = app(PermitService::class);
    }

    private function worker(array $attrs = []): Worker
    {
        static $n = 0;
        $n++;

        return Worker::create(array_merge([
            'external_party_id' => $this->party->id,
            'full_name'         => 'عامل '.$n,
            'national_id'       => '50000000'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'trade_id'          => $this->trade->id,
            'status'            => 'work_authorized',
            'medical_expiry'    => now()->addMonths(6),
        ], $attrs));
    }

    /**
     * تصريح نشط يغطي العامل صراحةً.
     * يُفعَّل أولاً ثم يُسنَد العامل: الإسناد بعد التفعيل هو الواقع (طاقم يُضاف لعمل جارٍ)،
     * ويسمح باختبار عامل ذي ثغرة دون أن يمنعه حارس التفعيل نفسه.
     */
    private function activePermitFor(Worker $worker, ?string $placeCode = 'HZ-06'): Permit
    {
        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'تصريح عمل', 'place_id' => Place::idByCode($placeCode),
            'external_party_id' => $this->party->id,
            'starts_at' => now()->subHour(), 'expires_at' => now()->addHours(8),
            'requested_by_id' => $this->salama->id,
        ]);
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->salama->id);
        $this->permits->transition($permit, Permit::STATUS_APPROVED, $this->salama->id);
        $permit = $this->permits->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->salama->id);

        $permit->workers()->create(['worker_id' => $worker->id]);

        return $permit;
    }

    // ── الفحوص الستة ──

    public function test_unknown_worker_is_denied_and_logged(): void
    {
        $result = $this->gate->checkWorker('0000000000', null, 'main', $this->salama->id);

        $this->assertSame('denied', $result['result']);
        $this->assertNull($result['worker']);
        $this->assertContains('worker_not_found', $result['denial_reasons']);
        $this->assertDatabaseHas('gate_logs', ['worker_id' => null, 'denial_reason' => 'worker_not_found']);
    }

    public function test_qualified_worker_with_assigned_permit_is_allowed(): void
    {
        $worker = $this->worker();
        $permit = $this->activePermitFor($worker);

        $result = $this->gate->checkWorker($worker->national_id, Place::idByCode('HZ-06'), 'main', $this->salama->id);

        $this->assertSame('allowed', $result['result']);
        $this->assertSame($permit->code, $result['permit_code']);
        $this->assertFalse($result['auto_permit']);
        $this->assertDatabaseHas('gate_logs', ['worker_id' => $worker->id, 'result' => 'allowed', 'permit_id' => $permit->id]);
    }

    public function test_lookup_by_worker_id_works(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker);

        $this->assertSame('allowed', $this->gate->checkWorker((string) $worker->id)['result']);
    }

    public function test_unauthorized_status_is_denied(): void
    {
        $worker = $this->worker(['status' => 'draft']);
        $this->activePermitFor($worker);

        $result = $this->gate->checkWorker($worker->national_id);

        $this->assertSame('denied', $result['result']);
        $this->assertContains('status_not_authorized', $result['denial_reasons']);
    }

    public function test_expired_medical_is_denied(): void
    {
        $worker = $this->worker(['medical_expiry' => now()->subDay()]);
        $this->activePermitFor($worker);

        $result = $this->gate->checkWorker($worker->national_id);

        $this->assertSame('denied', $result['result']);
        $this->assertContains('medical_expired', $result['denial_reasons']);
    }

    public function test_missing_medical_is_denied(): void
    {
        $worker = $this->worker(['medical_expiry' => null]);
        $this->activePermitFor($worker);

        $this->assertContains('medical_expired', $this->gate->checkWorker($worker->national_id)['denial_reasons']);
    }

    public function test_expired_certificate_is_denied(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker);
        WorkerDocument::create([
            'worker_id' => $worker->id, 'document_type' => 'safety_certificate',
            'name' => 'شهادة سلامة', 'expiry_date' => now()->subDay(), 'created_at' => now(),
        ]);

        $this->assertContains('certificates_expired', $this->gate->checkWorker($worker->national_id)['denial_reasons']);
    }

    public function test_missing_mandatory_training_is_denied_and_completed_training_passes(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker);

        $topic = TrainingTopic::firstOrFail();
        CompetencyRequirement::create([
            'trade_id' => $this->trade->id, 'training_topic_id' => $topic->id,
            'is_mandatory' => true, 'source' => 'trade_based',
        ]);

        $this->assertContains('training_incomplete', $this->gate->checkWorker($worker->national_id)['denial_reasons']);

        WorkerTrainingRecord::create([
            'worker_id' => $worker->id, 'training_topic_id' => $topic->id,
            'status' => 'completed', 'completed_at' => now()->subMonth(), 'expires_at' => now()->addMonths(11),
        ]);

        $this->assertSame('allowed', $this->gate->checkWorker($worker->national_id)['result']);
    }

    public function test_expired_training_record_does_not_count(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker);

        $topic = TrainingTopic::firstOrFail();
        CompetencyRequirement::create([
            'trade_id' => $this->trade->id, 'training_topic_id' => $topic->id,
            'is_mandatory' => true, 'source' => 'trade_based',
        ]);
        WorkerTrainingRecord::create([
            'worker_id' => $worker->id, 'training_topic_id' => $topic->id,
            'status' => 'completed', 'completed_at' => now()->subYear(), 'expires_at' => now()->subDay(),
        ]);

        $this->assertContains('training_incomplete', $this->gate->checkWorker($worker->national_id)['denial_reasons']);
    }

    public function test_no_active_permit_is_denied_when_contractor_has_none(): void
    {
        $worker = $this->worker();

        $result = $this->gate->checkWorker($worker->national_id);

        $this->assertSame('denied', $result['result']);
        $this->assertContains('no_active_permit', $result['denial_reasons']);
    }

    public function test_permit_outside_its_window_does_not_cover(): void
    {
        $worker = $this->worker();
        $permit = $this->activePermitFor($worker);
        $permit->update(['starts_at' => now()->subDays(3), 'expires_at' => now()->subDay()]);

        $this->assertContains('no_active_permit', $this->gate->checkWorker($worker->national_id)['denial_reasons']);
    }

    public function test_place_filter_requires_a_permit_for_that_place(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker, 'HZ-06');

        $this->assertSame('allowed', $this->gate->checkWorker($worker->national_id, Place::idByCode('HZ-06'))['result']);
        // مكان آخر: التصريح لا يغطيه، ولا تصريح عمل آخر للمقاول هناك
        $this->assertSame('denied', $this->gate->checkWorker($worker->national_id, Place::idByCode('HZ-01'))['result']);
    }

    public function test_multiple_denial_reasons_accumulate(): void
    {
        $worker = $this->worker(['status' => 'draft', 'medical_expiry' => now()->subDay()]);

        $reasons = $this->gate->checkWorker($worker->national_id)['denial_reasons'];

        $this->assertContains('status_not_authorized', $reasons);
        $this->assertContains('medical_expired', $reasons);
        $this->assertContains('no_active_permit', $reasons);
    }

    // ── التصريح الفردي الآلي ──

    public function test_individual_permit_is_generated_when_contractor_has_an_active_work_permit(): void
    {
        $covered = $this->worker();
        $work = $this->activePermitFor($covered, 'HZ-06');

        // عامل آخر للمقاول نفسه، غير مسنَد على التصريح
        $other = $this->worker();
        $result = $this->gate->checkWorker($other->national_id, Place::idByCode('HZ-06'), 'main', $this->salama->id);

        $this->assertSame('allowed', $result['result']);
        $this->assertTrue($result['auto_permit']);

        $auto = Permit::where('scope', Permit::SCOPE_INDIVIDUAL)->where('subject_id', $other->id)->firstOrFail();
        $this->assertSame(Permit::STATUS_ACTIVE, $auto->status);
        $this->assertSame($work->id, $auto->parent_permit_id);
        $this->assertSame(now()->endOfDay()->toDateString(), $auto->expires_at->toDateString(), 'صلاحيته اليوم فقط');
    }

    public function test_individual_permit_is_not_generated_when_another_check_fails(): void
    {
        $covered = $this->worker();
        $this->activePermitFor($covered, 'HZ-06');

        $unfit = $this->worker(['medical_expiry' => now()->subDay()]);
        $result = $this->gate->checkWorker($unfit->national_id, Place::idByCode('HZ-06'));

        $this->assertSame('denied', $result['result']);
        $this->assertFalse($result['auto_permit']);
        $this->assertSame(0, Permit::where('scope', Permit::SCOPE_INDIVIDUAL)->count());
    }

    public function test_second_check_reuses_the_generated_individual_permit(): void
    {
        $covered = $this->worker();
        $this->activePermitFor($covered, 'HZ-06');
        $other = $this->worker();

        $this->gate->checkWorker($other->national_id, Place::idByCode('HZ-06'));
        $second = $this->gate->checkWorker($other->national_id, Place::idByCode('HZ-06'));

        $this->assertSame('allowed', $second['result']);
        $this->assertFalse($second['auto_permit'], 'الفحص الثاني يجد التصريح القائم');
        $this->assertSame(1, Permit::where('scope', Permit::SCOPE_INDIVIDUAL)->count());
    }

    // ── الإحصاءات والسجل ──

    public function test_stats_count_today_only(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker);
        $this->gate->checkWorker($worker->national_id);
        $this->gate->checkWorker('0000000000');

        GateLog::create(['result' => 'allowed', 'gate_name' => 'main', 'created_at' => now()->subDay()]);

        $stats = $this->gate->statsToday();
        $this->assertSame(2, $stats['total_today']);
        $this->assertSame(1, $stats['allowed_today']);
        $this->assertSame(1, $stats['denied_today']);
    }

    public function test_readiness_snapshot_exposes_six_checks(): void
    {
        $worker = $this->worker();
        $snapshot = app(GateReadinessService::class)->computeForWorker($worker);

        foreach (['status', 'training', 'certificates', 'medical', 'competency_gaps', 'permit'] as $key) {
            $this->assertArrayHasKey($key, $snapshot['checks']);
        }
        $this->assertSame('denied', $snapshot['overall']);
    }

    public function test_worker_gap_service_reports_gaps_per_permit(): void
    {
        $worker = $this->worker(['medical_expiry' => now()->subDay()]);
        $permit = $this->permits->create(PermitType::where('code', 'work_permit')->firstOrFail(), [
            'title' => 'عمل', 'place_id' => Place::idByCode('HZ-06'),
            'external_party_id' => $this->party->id, 'requested_by_id' => $this->salama->id,
        ]);
        $permit->workers()->create(['worker_id' => $worker->id]);

        $report = app(WorkerGapRiskService::class)->reportForPermit($permit);

        $this->assertSame(1, $report['total_workers']);
        $this->assertSame(1, $report['workers_with_gaps']);
        $this->assertTrue(collect($report['gaps'][0]['gaps'])->contains('type', 'medical_expired'));
    }

    // ── الشاشة ──

    public function test_gate_screen_and_logs_are_reachable_and_scan_returns_json(): void
    {
        $worker = $this->worker();
        $this->activePermitFor($worker);

        $this->actingAs($this->salama)->get('/app/permits/gate')->assertOk()->assertSee('فحص جاهزية العامل');
        $this->actingAs($this->salama)->get('/app/permits/gate/logs')->assertOk();

        $this->actingAs($this->salama)
            ->postJson('/app/permits/gate/check', ['worker_identifier' => $worker->national_id])
            ->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonStructure(['result', 'checks', 'denial_reasons', 'worker', 'stats']);
    }
}
