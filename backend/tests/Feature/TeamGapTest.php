<?php

namespace Tests\Feature;

use App\Core\Inbox\InboxService;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٥-٥ (قرار ٤٣): «ينقصك فريق» في «ما ينتظرك» — لمدير الوحدة ومسؤول السلامة،
 * حين تكون الفرق الجاهزة (أربعة أسماء) أقل من المطلوب (فريق لكل ٢٥ موظفاً)، وتختفي حين يكتمل العدد.
 */
class TeamGapTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationUnit $unit; private OrganizationUnit $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->unit = OrganizationUnit::where('place_id', Place::idByCode('HZ-06'))->first();
        $this->other = OrganizationUnit::where('place_id', Place::idByCode('HZ-06'))->where('id', '!=', $this->unit->id)->first();
    }

    private function user(string $username, string $role, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function team(string $p, int $names = 4): array
    {
        return array_map(fn ($i) => ['role' => ['المنسق', 'المسعف', 'المنقذ', 'الإطفائي'][$i], 'name' => $i < $names ? "$p $i" : '', 'dept' => '', 'phone' => '', 'trained' => '', 'trainer' => ''], [0, 1, 2, 3]);
    }

    private function doc(array $unit): void
    {
        $data = ['HZ-06' => ['plans' => [], 'units' => [$this->unit->code => $unit]]];
        InstituteDocument::updateOrCreate(['key' => 'ipa-place'], ['version' => 1, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    private function questions(User $u): array
    {
        return app(InboxService::class)->forUser($u)->where('module', 'الفرق الأولية')->pluck('question')->all();
    }

    public function test_gap_shows_for_unit_manager_and_safety_and_hides_when_complete(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $mudir = $this->user('mudir', 'department_manager', $this->unit->id);
        $otherMudir = $this->user('mudir2', 'department_manager', $this->other->id);
        $employee = $this->user('emp', 'employee');

        $nom = ['by' => 'م', 'dept' => $this->unit->code, 'date' => '2026-09-01'];
        $appr = ['by' => 'مدير الشؤون', 'date' => '2026-09-02'];

        // ٣٠ موظفاً وفريق واحد مرشَّح غير معتمد ← ينقص فريقان، والمعتمد لا فريق (كما تعدّ اللوحة: المعتمد وحده)
        $this->doc(['staff' => 30, 'team' => $this->team('أول'), 'nom' => $nom, 'appr' => [], 'hr' => []]);
        foreach ([$salama, $mudir] as $u) {
            $q = $this->questions($u);
            $this->assertCount(1, $q, 'لا مهمة نقص فريق');
            $this->assertStringContainsString($this->unit->name, $q[0]);
            $this->assertStringContainsString('٣٠', $q[0]);
            $this->assertStringContainsString('فريقين', $q[0]);
            $this->assertStringContainsString('المعتمد: لا فريق', $q[0]);
        }
        $this->assertSame([], $this->questions($otherMudir), 'مدير إدارة أخرى يرى نقص غيره');
        $this->assertSame([], $this->questions($employee));
        $this->actingAs($mudir)->get('/app')->assertOk()->assertSee('الفرق الأولية');

        // الأول معتمد والثاني مرشَّح ← المعتمد فريق واحد
        $this->doc(['staff' => 30, 'team' => $this->team('أول'), 'nom' => $nom, 'appr' => $appr, 'hr' => [],
            'more' => [['team' => $this->team('ثانٍ'), 'nom' => $nom, 'appr' => [], 'hr' => []]]]);
        $this->assertStringContainsString('المعتمد: فريق واحد', $this->questions($salama)[0]);

        // الثاني معتمد أيضاً (أو مُحال للموارد البشرية) ← تختفي
        $this->doc(['staff' => 30, 'team' => $this->team('أول'), 'nom' => $nom, 'appr' => $appr, 'hr' => ['date' => '2026-09-03'],
            'more' => [['team' => $this->team('ثانٍ'), 'nom' => $nom, 'appr' => $appr, 'hr' => []]]]);
        $this->assertSame([], $this->questions($salama), 'اكتمل العدد المعتمد والمهمة باقية');

        // ٢٥ موظفاً بفريق معتمد ← لا نقص؛ و٦٠ بفريق معتمد ← «ثلاثة فرق»
        $this->doc(['staff' => 25, 'team' => $this->team('أول'), 'nom' => $nom, 'appr' => $appr, 'hr' => []]);
        $this->assertSame([], $this->questions($salama));
        $this->doc(['staff' => 60, 'team' => $this->team('أول'), 'nom' => $nom, 'appr' => $appr, 'hr' => []]);
        $this->assertStringContainsString('ثلاثة فرق', $this->questions($salama)[0]);
    }

    public function test_no_staff_recorded_means_no_task(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $this->doc(['team' => $this->team('أول'), 'nom' => ['by' => 'م', 'dept' => $this->unit->code, 'date' => '2026-09-01'], 'appr' => [], 'hr' => []]);
        $this->assertSame([], $this->questions($salama), 'بلا عدد موظفين لا يُحسب نقص');
    }
}
