<?php

namespace Tests\Feature\Permit;

use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\PermitTypeConflictRule;
use App\Modules\Permit\Services\PermitConflictService;
use Database\Seeders\PermitConflictRulesSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * منقول من OHSMS: `tests/Feature/Permit/PermitConflictServiceTest.php`.
 * ما تغيّر: **منطقة العمل صارت المكان** (`places.max_workers/max_equipment` بدل `work_zones`)،
 * وبلا `tenant_id`. المكان بلا حد مُدخَل لا تُفحص سعته (الأرقام قرار المستخدم).
 */
class PermitConflictServiceTest extends TestCase
{
    use RefreshDatabase;

    private PermitConflictService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, PermitTypesSeeder::class, PermitConflictRulesSeeder::class]);
        $this->service = app(PermitConflictService::class);
    }

    private function place(string $code = 'HZ-06', ?int $maxWorkers = null, ?int $maxEquipment = null): Place
    {
        $place = Place::where('code', $code)->firstOrFail();
        $place->update(['max_workers' => $maxWorkers, 'max_equipment' => $maxEquipment]);

        return $place->refresh();
    }

    private function insertPermit(Place $place, string $status, int $workers = 0, int $equipment = 0, ?int $typeId = null): Permit
    {
        $typeId ??= PermitType::where('code', 'work_permit')->value('id');
        $id = DB::table('permits')->insertGetId([
            'permit_type_id'  => $typeId,
            'permit_category' => PermitType::find($typeId)->category,
            'code'            => 'ت-اختبار-'.random_int(1000, 9999),
            'title'           => 'تصريح قائم',
            'place_id'        => $place->id,
            'workers_count'   => $workers,
            'equipment_count' => $equipment,
            'status'          => $status,
            'starts_at'       => now()->subHour(),
            'expires_at'      => now()->addHours(8),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return Permit::with(['type', 'place'])->find($id);
    }

    // ─── سعة المكان ───

    public function test_no_conflicts_when_place_has_no_limits(): void
    {
        $place = $this->place('HZ-06');
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED, workers: 50, equipment: 20);

        $result = $this->service->check($permit);

        $this->assertFalse($result['has_blocks']);
        $this->assertEmpty($result['conflicts']);
    }

    public function test_no_capacity_conflict_when_under_limit(): void
    {
        $place = $this->place('HZ-06', maxWorkers: 20, maxEquipment: 5);
        $this->insertPermit($place, Permit::STATUS_ACTIVE, workers: 10, equipment: 2);
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED, workers: 8, equipment: 2);

        $this->assertFalse($this->service->check($permit)['has_blocks']);
    }

    public function test_capacity_conflict_blocks_when_workers_exceed_max(): void
    {
        $place = $this->place('HZ-06', maxWorkers: 10);
        $this->insertPermit($place, Permit::STATUS_ACTIVE, workers: 8);
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED, workers: 5);

        $result = $this->service->check($permit);

        $this->assertTrue($result['has_blocks']);
        $this->assertNotEmpty(array_filter($result['conflicts'], fn ($c) => $c['type'] === 'capacity'));
    }

    public function test_capacity_conflict_blocks_when_equipment_exceeds_max(): void
    {
        $place = $this->place('HZ-08', maxEquipment: 4);
        $this->insertPermit($place, Permit::STATUS_ACTIVE, equipment: 3);
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED, equipment: 2);

        $this->assertTrue($this->service->check($permit)['has_blocks']);
    }

    public function test_only_approved_and_active_permits_count_toward_capacity(): void
    {
        $place = $this->place('HZ-06', maxWorkers: 5);
        $this->insertPermit($place, Permit::STATUS_DRAFT, workers: 100);
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED, workers: 5);

        $this->assertFalse($this->service->check($permit)['has_blocks'], 'المسودة لا تشغل سعة');
    }

    public function test_capacity_is_scoped_to_the_same_place(): void
    {
        $busy = $this->place('HZ-06', maxWorkers: 5);
        $other = $this->place('HZ-08', maxWorkers: 5);
        $this->insertPermit($busy, Permit::STATUS_ACTIVE, workers: 5);

        $permit = $this->insertPermit($other, Permit::STATUS_SUBMITTED, workers: 4);
        $this->assertFalse($this->service->check($permit)['has_blocks'], 'سعة مكان لا تؤثر في مكان آخر');
    }

    // ─── تعارض الأنواع ───

    public function test_no_type_conflict_when_no_rule_applies(): void
    {
        $place = $this->place('HZ-06');
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED,
            typeId: PermitType::where('code', 'contractor_pre_qualification')->value('id'));

        $this->assertFalse($this->service->check($permit)['has_blocks']);
    }

    public function test_type_conflict_is_detected_when_conflicting_permit_is_active(): void
    {
        $place = $this->place('HZ-02');
        $this->insertPermit($place, Permit::STATUS_ACTIVE,
            typeId: PermitType::where('code', 'hazmat_transport')->value('id'));

        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED,
            typeId: PermitType::where('code', 'hot_work')->value('id'));

        $result = $this->service->check($permit);

        $this->assertTrue($result['has_blocks']);
        $this->assertNotEmpty(array_filter($result['conflicts'], fn ($c) => $c['type'] === 'work_type'));
    }

    public function test_warn_severity_does_not_set_has_blocks(): void
    {
        $place = $this->place('HZ-02');
        // أعمال ساخنة × عمل على ارتفاع مبذورة «تنبيه» لا «مانع»
        $this->insertPermit($place, Permit::STATUS_ACTIVE,
            typeId: PermitType::where('code', 'work_at_height')->value('id'));

        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED,
            typeId: PermitType::where('code', 'hot_work')->value('id'));

        $result = $this->service->check($permit);

        $this->assertFalse($result['has_blocks'], 'التنبيه لا يمنع');
        $this->assertNotEmpty($result['conflicts'], 'ويظهر مع ذلك في القائمة');
    }

    public function test_inactive_conflict_rule_is_ignored(): void
    {
        $place = $this->place('HZ-02');
        PermitTypeConflictRule::query()->update(['is_active' => false]);

        $this->insertPermit($place, Permit::STATUS_ACTIVE,
            typeId: PermitType::where('code', 'hazmat_transport')->value('id'));
        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED,
            typeId: PermitType::where('code', 'hot_work')->value('id'));

        $this->assertFalse($this->service->check($permit)['has_blocks']);
    }

    public function test_conflict_in_a_different_place_is_ignored(): void
    {
        $hot = $this->place('HZ-02');
        $far = $this->place('HZ-08');

        $this->insertPermit($far, Permit::STATUS_ACTIVE,
            typeId: PermitType::where('code', 'hazmat_transport')->value('id'));
        $permit = $this->insertPermit($hot, Permit::STATUS_SUBMITTED,
            typeId: PermitType::where('code', 'hot_work')->value('id'));

        $this->assertFalse($this->service->check($permit)['has_blocks']);
    }

    public function test_place_scoped_rule_applies_only_in_that_place(): void
    {
        $basement = $this->place('HZ-01');
        $offices  = $this->place('HZ-06');

        $a = PermitType::where('code', 'work_permit')->value('id');
        $b = PermitType::where('code', 'night_work')->value('id');
        PermitTypeConflictRule::create([
            'permit_type_a_id' => $a, 'permit_type_b_id' => $b,
            'place_id' => $basement->id, 'severity' => 'block', 'reason' => 'قاعدة خاصة بالقبو',
        ]);

        // في القبو: تُطبَّق
        $this->insertPermit($basement, Permit::STATUS_ACTIVE, typeId: $b);
        $inBasement = $this->insertPermit($basement, Permit::STATUS_SUBMITTED, typeId: $a);
        $this->assertTrue($this->service->check($inBasement)['has_blocks']);

        // في المكاتب: لا تُطبَّق
        $this->insertPermit($offices, Permit::STATUS_ACTIVE, typeId: $b);
        $inOffices = $this->insertPermit($offices, Permit::STATUS_SUBMITTED, typeId: $a);
        $this->assertFalse($this->service->check($inOffices)['has_blocks']);
    }

    public function test_qualification_permits_neither_consume_capacity_nor_conflict(): void
    {
        $place = $this->place('HZ-02', maxWorkers: 1);
        $this->insertPermit($place, Permit::STATUS_ACTIVE, workers: 5,
            typeId: PermitType::where('code', 'hazmat_transport')->value('id'));

        $permit = $this->insertPermit($place, Permit::STATUS_SUBMITTED, workers: 99,
            typeId: PermitType::where('code', 'contractor_pre_qualification')->value('id'));

        $result = $this->service->check($permit);
        $this->assertFalse($result['has_blocks']);
        $this->assertEmpty($result['conflicts'], 'تصريح التأهيل تقييم إداري لا عمل ميداني');
    }

    // ─── لقطات اللوحة ───

    public function test_place_snapshot_counts_active_load(): void
    {
        $place = $this->place('HZ-06', maxWorkers: 10, maxEquipment: 4);
        $this->insertPermit($place, Permit::STATUS_ACTIVE, workers: 4, equipment: 1);
        $this->insertPermit($place, Permit::STATUS_APPROVED, workers: 3, equipment: 1);
        $this->insertPermit($place, Permit::STATUS_DRAFT, workers: 90, equipment: 90);

        $snapshot = $this->service->placeSnapshot($place);

        $this->assertSame(7, $snapshot['active_workers']);
        $this->assertSame(2, $snapshot['active_equipment']);
        $this->assertSame(2, $snapshot['active_permits']);
        $this->assertSame(70, $snapshot['workers_pct']);
    }

    public function test_active_conflicts_lists_live_violating_pairs(): void
    {
        $place = $this->place('HZ-02');
        $this->insertPermit($place, Permit::STATUS_ACTIVE,
            typeId: PermitType::where('code', 'hazmat_transport')->value('id'));
        $this->insertPermit($place, Permit::STATUS_ACTIVE,
            typeId: PermitType::where('code', 'hot_work')->value('id'));

        $conflicts = $this->service->activeConflicts();

        $this->assertNotEmpty($conflicts);
        $this->assertSame('block', $conflicts[0]['severity']);
    }
}
