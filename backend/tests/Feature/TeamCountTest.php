<?php

namespace Tests\Feature;

use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٥-٣ (قرار ٤٣): فريق لكل ٢٥ موظفاً أو جزء منها — الوحدة في ملف المكان تحمل عدد موظفيها (`staff`)
 * وفرقها الإضافية (`more`)؛ الفريق الأول يبقى في جذر الوحدة كما كان، فلا يُفقد فريق قائم.
 */
class TeamCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
    }

    private function team(string $prefix): array
    {
        return array_map(fn ($r) => ['role' => $r, 'name' => "$prefix $r", 'dept' => '', 'phone' => '', 'trained' => '', 'trainer' => ''],
            ['المنسق', 'المسعف', 'المنقذ', 'الإطفائي']);
    }

    public function test_unit_with_second_team_syncs_two_teams_and_old_format_keeps_one(): void
    {
        $unit = OrganizationUnit::first();
        $doc = [
            'HZ-06' => ['plans' => [], 'units' => [$unit->code => [
                'staff' => 30,
                'team' => $this->team('أول'), 'nom' => ['by' => 'م', 'dept' => $unit->code, 'date' => '2026-09-01'], 'appr' => ['date' => '2026-09-02'], 'hr' => [],
                'more' => [['team' => $this->team('ثانٍ'), 'nom' => ['by' => 'م', 'dept' => $unit->code, 'date' => '2026-09-03'], 'appr' => [], 'hr' => []]],
            ]]],
            'HZ-01' => ['plans' => [], 'units' => ['_' => [
                'team' => $this->team('قبو'), 'nom' => ['by' => 'م', 'dept' => '', 'date' => '2026-09-01'], 'appr' => [], 'hr' => [],
            ]]],
        ];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode($doc, JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();

        $hz06 = EmergencyTeam::where('source', 'place_profile')->where('place_id', Place::idByCode('HZ-06'))->orderBy('unit_key')->get();
        $this->assertSame([$unit->code, $unit->code.'#2'], $hz06->pluck('unit_key')->all());
        $this->assertSame(['approved', 'nominated'], $hz06->pluck('readiness')->all());
        $this->assertSame('ثانٍ المسعف', $hz06[1]->members->firstWhere('role_key', 'medic')->name);
        $this->assertStringContainsString('الفريق ٢', $hz06[1]->name);
        $this->assertSame($unit->id, $hz06[1]->organization_unit_id);

        // الصيغة القديمة: فريق واحد كما كان
        $this->assertSame(['_'], EmergencyTeam::where('source', 'place_profile')->where('place_id', Place::idByCode('HZ-01'))->pluck('unit_key')->all());

        // حذف الفريق الثاني من الوثيقة يعطّله ولا يحذفه
        unset($doc['HZ-06']['units'][$unit->code]['more']);
        InstituteDocument::where('key', 'ipa-place')->update(['data' => json_encode($doc, JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
        $this->assertFalse($hz06[1]->fresh()->is_active);
        $this->assertTrue($hz06[0]->fresh()->is_active);
    }
}
