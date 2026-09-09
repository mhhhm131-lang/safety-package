<?php

namespace Tests\Feature\Permit;

use App\Core\StateMachine\Exceptions\TransitionException;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitEvent;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Worker\Models\Trade;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\TradeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * منقول من OHSMS: `tests/Feature/Permit/PermitServiceTest.php` — دورة حياة التصريح،
 * آلة الحالة، السجل الزمني، البنود، وحارس التفعيل.
 *
 * ما تغيّر عمداً: بلا `tenant_id`؛ الاعتماد النهائي عند مسؤول السلامة والمناوب
 * (فيُمرَّر فاعل يملك الصلاحية)؛ رمز التصريح عربي `ت-<سنة>-<تسلسل>`؛
 * `work_zone_id` صار `place_id`.
 */
class PermitServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private PermitService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([
            PlacesSeeder::class, RiskBookSeeder::class, RiskControlsSeeder::class,
            TradeSeeder::class, PermitTypesSeeder::class, QualificationChecklistSeeder::class,
        ]);

        $this->actor = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '123456']);
        UserProfile::create(['user_id' => $this->actor->id, 'role' => 'system_admin', 'is_active' => true]);

        $this->service = app(PermitService::class);
    }

    private function makeScope(): array
    {
        return [
            Project::create(['name' => 'مشروع اختبار', 'status' => 'active', 'place_id' => Place::idByCode('HZ-06')]),
            ExternalParty::create(['name' => 'مقاول اختبار', 'party_type' => 'contractor', 'status' => 'active']),
        ];
    }

    private function makePermit(string $typeCode = 'contractor_pre_qualification'): Permit
    {
        [$project, $party] = $this->makeScope();
        $type = PermitType::where('code', $typeCode)->firstOrFail();

        return $this->service->create($type, [
            'title'             => "تصريح {$typeCode}",
            'project_id'        => $project->id,
            'external_party_id' => $party->id,
            'place_id'          => Place::idByCode('HZ-06'),
            'subject_type'      => Permit::SUBJECT_EXTERNAL_PARTY,
            'subject_id'        => $party->id,
            'requested_by_id'   => $this->actor->id,
        ]);
    }

    public function test_create_rejects_when_required_context_missing(): void
    {
        $type = PermitType::where('code', 'contractor_pre_qualification')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->service->create($type, ['title' => 'بلا مشروع ولا مقاول']);
    }

    public function test_create_sets_draft_status_code_and_category(): void
    {
        $permit = $this->makePermit();

        $this->assertSame(Permit::STATUS_DRAFT, $permit->status);
        $this->assertSame('qualification', $permit->permit_category);
        $this->assertStringStartsWith('ت-', $permit->code);
    }

    public function test_permit_codes_are_sequential_within_the_year(): void
    {
        $first  = $this->makePermit();
        $second = $this->makePermit();

        $year = now()->format('Y');
        $this->assertSame("ت-{$year}-0001", $first->code);
        $this->assertSame("ت-{$year}-0002", $second->code);
    }

    public function test_happy_path_transitions_write_timestamps_and_approver(): void
    {
        $permit = $this->makePermit();

        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $permit = $this->service->transition($permit, Permit::STATUS_APPROVED, $this->actor->id);

        $this->assertSame(Permit::STATUS_APPROVED, $permit->status);
        $this->assertNotNull($permit->submitted_at);
        $this->assertNotNull($permit->reviewed_at);
        $this->assertNotNull($permit->approved_at);
        $this->assertSame($this->actor->id, $permit->approved_by_id);
    }

    public function test_rejected_captures_reason_via_notes(): void
    {
        $permit = $this->makePermit();
        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $permit = $this->service->transition($permit, Permit::STATUS_REJECTED, $this->actor->id, 'السجل التجاري منتهٍ');

        $this->assertSame(Permit::STATUS_REJECTED, $permit->status);
        $this->assertSame('السجل التجاري منتهٍ', $permit->rejection_reason);
        $this->assertNotNull($permit->reviewed_at);
    }

    public function test_illegal_transition_is_blocked(): void
    {
        $permit = $this->makePermit();

        $this->expectException(TransitionException::class);
        $this->service->transition($permit, Permit::STATUS_ACTIVE, $this->actor->id);
    }

    public function test_activation_is_blocked_until_mandatory_requirements_are_met(): void
    {
        $permit = $this->makePermit();
        $this->service->addRequirement($permit, PermitRequirement::CATEGORY_DOCUMENT, 'document:insurance_liability');

        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_APPROVED, $this->actor->id);

        try {
            $this->service->transition($permit, Permit::STATUS_ACTIVE, $this->actor->id);
            $this->fail('كان يجب رفض التفعيل والبنود الإلزامية ناقصة');
        } catch (TransitionException $e) {
            $this->assertStringContainsString('بنود إلزامية', $e->getMessage());
        }

        // إتمام كل البنود الإلزامية يفتح التفعيل
        $permit->requirements()->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $permit = $this->service->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->actor->id);
        $this->assertSame(Permit::STATUS_ACTIVE, $permit->status);
    }

    public function test_recommended_requirement_does_not_block_activation(): void
    {
        $permit = $this->makePermit();
        // إتمام الإلزامي من الكتالوج، وترك بند موصى به مفتوحاً
        $permit->requirements()->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->update(['status' => PermitRequirement::STATUS_COMPLETED]);
        $this->service->addRequirement(
            $permit, PermitRequirement::CATEGORY_DOCUMENT, 'document:iso_45001',
            PermitRequirement::SEVERITY_RECOMMENDED,
        );

        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_APPROVED, $this->actor->id);
        $permit = $this->service->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->actor->id);

        $this->assertSame(Permit::STATUS_ACTIVE, $permit->status);
    }

    public function test_waived_requirement_does_not_block_activation(): void
    {
        $permit = $this->makePermit();
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_WAIVED]);

        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_APPROVED, $this->actor->id);

        $this->assertSame(Permit::STATUS_ACTIVE,
            $this->service->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->actor->id)->status);
    }

    public function test_cancel_allowed_from_any_state(): void
    {
        $permit = $this->makePermit();
        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $permit = $this->service->transition($permit, Permit::STATUS_CANCELLED, $this->actor->id, 'أُلغي الطلب');

        $this->assertSame(Permit::STATUS_CANCELLED, $permit->status);
    }

    public function test_every_transition_writes_an_event(): void
    {
        $permit = $this->makePermit();
        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);

        $events = PermitEvent::where('permit_id', $permit->id)->orderBy('id')->get();

        $this->assertCount(3, $events, 'إنشاء + انتقالان');
        $this->assertSame('created', $events[0]->event_type);
        $this->assertSame(Permit::STATUS_DRAFT, $events[1]->from_status);
        $this->assertSame(Permit::STATUS_SUBMITTED, $events[1]->to_status);
    }

    public function test_requirement_upsert_is_idempotent_by_code(): void
    {
        $permit = $this->makePermit();
        $before = $permit->requirements()->count();

        $this->service->addRequirement($permit, 'document', 'document:insurance_liability');
        $this->service->addRequirement($permit, 'document', 'document:insurance_liability');

        $this->assertSame($before + 1, $permit->requirements()->count());
    }

    public function test_worker_subject_permit_requires_worker_context(): void
    {
        $type = PermitType::where('code', 'worker_role_authorization')->firstOrFail();

        $this->expectException(InvalidArgumentException::class);
        $this->service->create($type, ['title' => 'بلا عامل']);
    }

    public function test_trade_ids_persisted_to_permit_trades(): void
    {
        $trade = Trade::where('is_active', true)->firstOrFail();
        $type  = PermitType::where('code', 'hot_work')->firstOrFail();

        $permit = $this->service->create($type, [
            'title'           => 'أعمال ساخنة بمهنة',
            'place_id'        => Place::idByCode('HZ-02'),
            'trade_ids'       => [$trade->id],
            'requested_by_id' => $this->actor->id,
        ]);

        $this->assertDatabaseHas('permit_trades', ['permit_id' => $permit->id, 'trade_id' => $trade->id]);
    }

    public function test_default_validity_fills_expiry_when_not_supplied(): void
    {
        $type = PermitType::where('code', 'hot_work')->firstOrFail(); // صلاحيته ٧ أيام
        $permit = $this->service->create($type, [
            'title'           => 'بلا تاريخ انتهاء',
            'place_id'        => Place::idByCode('HZ-02'),
            'starts_at'       => now(),
            'requested_by_id' => $this->actor->id,
        ]);

        $this->assertNotNull($permit->expires_at);
        $this->assertSame(
            now()->addDays($type->default_validity_days)->toDateString(),
            $permit->expires_at->toDateString(),
        );
    }

    public function test_expired_transition_is_refused_before_the_deadline(): void
    {
        $permit = $this->makePermit();
        $permit->update(['expires_at' => now()->addWeek()]);
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_APPROVED, $this->actor->id);
        $this->service->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->actor->id);

        $this->expectException(TransitionException::class);
        $this->service->transition($permit->refresh(), Permit::STATUS_EXPIRED, null, 'قبل الموعد');
    }

    public function test_expire_overdue_command_moves_past_permits(): void
    {
        $permit = $this->makePermit();
        $permit->requirements()->update(['status' => PermitRequirement::STATUS_COMPLETED]);

        $this->service->transition($permit, Permit::STATUS_SUBMITTED, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_UNDER_REVIEW, $this->actor->id);
        $this->service->transition($permit, Permit::STATUS_APPROVED, $this->actor->id);
        $this->service->transition($permit->refresh(), Permit::STATUS_ACTIVE, $this->actor->id);

        $permit->update(['expires_at' => now()->subDays(2)]);
        $this->artisan('permits:expire-overdue')->assertSuccessful();

        $this->assertSame(Permit::STATUS_EXPIRED, $permit->refresh()->status);
    }
}
