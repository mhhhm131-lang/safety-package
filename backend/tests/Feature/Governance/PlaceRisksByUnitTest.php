<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * (٢٠٢٦-١٠-٠٤، بكلمته «أريد عندما يدخل مدير الأمن والسلامة يرى مخاطره لا مخاطر كل المكاتب الإدارية» ثم «عدّل هذا الخطأ»):
 * كُشف على المنشور — قسمه فعّل ١٥١ خطراً وملف «المكاتب الإدارية» يقول «مخاطر المكان: ٠» وزر «السجل كاملاً» يفتح سجلاً فارغاً.
 * السبب: خانة «مخاطر المكان» كانت تبحث بخانة المكان المكتوبة على الخطر، والتفعيل لوحدة يكتب الوحدة ولا يكتب مكاناً.
 * الصحيح: خطر الوحدة التي تشغل المكان من مخاطر ذلك المكان، وكلٌّ يرى منه ما يراه في السجل الفعلي — وحدته وما تحتها.
 */
class PlaceRisksByUnitTest extends TestCase
{
    use RefreshDatabase;

    private Place $offices;
    private Place $park;
    private RiskSubCategory $sub;
    private OrganizationUnit $hr;
    private OrganizationUnit $it;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->offices = Place::where('code', 'HZ-06')->firstOrFail();
        $this->park = Place::where('code', 'HZ-01')->firstOrFail();
        $this->hr = OrganizationUnit::where('code', 'hr')->firstOrFail();
        $this->it = OrganizationUnit::where('code', 'it')->firstOrFail();
        $cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
    }

    private function user(string $username, string $role, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unit?->id, 'place_id' => $this->offices->id]);
        return $u;
    }

    private function risk(string $code, ?OrganizationUnit $unit, ?Place $place, string $status = 'active'): Risk
    {
        return Risk::create(['risk_type' => 'active', 'code' => $code, 'title' => 'خطر '.$code, 'description' => 'x', 'category_id' => $this->sub->category_id, 'sub_category_id' => $this->sub->id,
            'severity' => 3, 'likelihood' => 3, 'status' => $status, 'scope_type' => $unit ? 'org_unit' : 'general', 'organization_unit_id' => $unit?->id, 'place_id' => $place?->id]);
    }

    /** @return array{0:string[],1:int} أكواد المخاطر المعروضة في ملف المكان، والعدد المكتوب على زرّه */
    private function fileRisks(User $u, Place $place): array
    {
        $h = $this->actingAs($u)->get(route('app.places.units.file', $place))->assertOk()->getContent();
        preg_match_all('/data-risk="([^"]+)"/u', $h, $m);
        $n = preg_match('/id="pfSecRisks".*?<span class="badge text-bg-dark">(\d+)<\/span>/su', $h, $c) ? (int) $c[1] : -1;
        return [$m[1], $n];
    }

    /** أكواد ما يعرضه السجل الفعلي حين يُفتح من زر «السجل كاملاً» (محصوراً بالمكان) */
    private function registerOfPlace(User $u, Place $place): array
    {
        return collect($this->actingAs($u)->getJson(route('risk.registry.tree.risksBySubCategory', ['type' => 'active', 'subCatId' => $this->sub->id, 'place' => $place->code]))->assertOk()->json())->pluck('code')->all();
    }

    public function test_the_unit_manager_sees_the_risks_of_his_unit_in_the_file_of_its_place(): void
    {
        $this->assertSame($this->offices->id, (int) $this->hr->place_id, 'وحدات الهيكل مكانها المكاتب الإدارية');
        $mine = $this->risk('A-HR', $this->hr, null);                  // كما يكتبه التفعيل للوحدة: وحدة بلا مكان
        $theirs = $this->risk('B-IT', $this->it, null);                // خطر إدارة أخرى في المكان نفسه
        $general = $this->risk('C-GEN', null, $this->offices);         // خطر عام للمعهد كُتب عليه هذا المكان
        $elsewhere = $this->risk('D-HR-PARK', $this->hr, $this->park); // خطر وحدته لكن في مكان آخر
        $waiting = $this->risk('E-HR-WAIT', $this->hr, null, 'pending_approval'); // لم يُعتمد بعد: ليس مفعّلاً

        foreach ([$this->user('mudir', 'department_manager', $this->hr), $this->user('munassiq', 'safety_coordinator', $this->hr)] as $u) {
            [$codes, $n] = $this->fileRisks($u, $this->offices);
            $this->assertEqualsCanonicalizing(['A-HR', 'C-GEN'], $codes, "{$u->username}: ملف المكان لا يعرض مخاطر وحدته (أو يعرض غيرها)");
            $this->assertSame(2, $n, "{$u->username}: عدد «مخاطر المكان»");
            // زر «السجل كاملاً» يفتح السجل الفعلي بالمخاطر نفسها — وفيه ما ينتظر الاعتماد بحالته
            $this->assertEqualsCanonicalizing(['A-HR', 'C-GEN', 'E-HR-WAIT'], $this->registerOfPlace($u, $this->offices), "{$u->username}: «السجل كاملاً» لا يعرض سجل وحدته");
        }
    }

    public function test_the_safety_officer_sees_the_risks_of_every_unit_in_the_place_and_each_place_keeps_its_own(): void
    {
        $this->risk('A-HR', $this->hr, null);
        $this->risk('B-IT', $this->it, null);
        $this->risk('C-GEN', null, $this->offices);
        $this->risk('D-HR-PARK', $this->hr, $this->park);
        $this->risk('F-GEN-NOPLACE', null, null); // عام بلا مكان: ليس من مخاطر مكان بعينه
        $salama = $this->user('salama', 'system_admin');

        [$codes, $n] = $this->fileRisks($salama, $this->offices);
        $this->assertEqualsCanonicalizing(['A-HR', 'B-IT', 'C-GEN'], $codes);
        $this->assertSame(3, $n);
        $this->assertEqualsCanonicalizing(['A-HR', 'B-IT', 'C-GEN'], $this->registerOfPlace($salama, $this->offices));

        // المكان الآخر: ما كُتب عليه وحده — خطر الوحدة المكتوب عليه مكان آخر لا يعود إلى مكان وحدته
        [$codes, $n] = $this->fileRisks($salama, $this->park);
        $this->assertSame(['D-HR-PARK'], $codes);
        $this->assertSame(1, $n);
        $this->assertSame(['D-HR-PARK'], $this->registerOfPlace($salama, $this->park));
    }
}
