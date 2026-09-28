<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-٦ (قرار ٦٦، بكلمته «بقية الأماكن باستثناء المركز»): ملف المكان بالبنود الثمانية بالترتيب —
 * خطة السلامة ← خطة الاستجابة ← نماذج الفحص ← مخاطر المكان ← المفتوح الآن ← الوحدات ← الفريق الأولي ← طلب تصريح هنا.
 * ما ليس للشخص يظهر باهتاً باسم صاحبه، لا رابطاً يفتح «ليس لديك صلاحية».
 */
class PlaceFileOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-01' => ['plans' => ['sa' => '2026-09-01', 'sa_by' => 'الإدارة العليا', 'ra' => '2026-09-02', 'drill' => '2026-09-10']]], JSON_UNESCAPED_UNICODE)]);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_eight_sections_in_order_with_place_risks_and_permit_here(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $mudir = $this->user('mudir', 'department_manager', 'HZ-06');
        $fani = $this->user('fani', 'field_worker', 'HZ-01');
        $park = Place::where('code', 'HZ-01')->first();
        $cat = RiskCategory::create(['name' => 'الفيزيائية', 'abbreviation' => 'PH', 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'التهوية', 'abbreviation' => 'VE']);
        Risk::create(['risk_type' => 'active', 'code' => 'PH-01-01/PARK', 'title' => 'تراكم أول أكسيد الكربون', 'description' => 'x', 'category_id' => $cat->id, 'sub_category_id' => $sub->id,
            'severity' => 4, 'likelihood' => 3, 'risk_score' => 12, 'status' => 'active', 'place_id' => $park->id, 'assigned_coordinator_id' => $mudir->id, 'assigned_field_team_id' => $fani->id]);

        $h = $this->actingAs($salama)->get("/app/places/{$park->id}/file")->assertOk()->getContent();
        $ids = ['id="pfPlanSafety"', 'id="pfPlanResponse"', 'id="pfForms"', 'id="pfRisks"', 'id="pfNow"', 'id="pfUnitsH"', 'id="pfTeamH"', 'id="pfPermit"'];
        $pos = array_map(fn ($i) => strpos($h, $i), $ids);
        $this->assertNotContains(false, $pos, 'قسم ناقص');
        $sorted = $pos; sort($sorted);
        $this->assertSame($sorted, $pos, 'الترتيب: الخطتان ← النماذج ← المخاطر ← المفتوح ← الوحدات ← الفريق ← التصريح');
        // ١ خطة السلامة بطبقتيها تفتح الخطة نفسها، ٢ خطة الاستجابة بآخر تمرين
        $this->assertMatchesRegularExpression('~id="pfPlanSafety".*?href="/HZ-01-basement/safety-plan.html".*?الاستباقية والتشغيلية.*?معتمدة~s', $h);
        $this->assertMatchesRegularExpression('~id="pfPlanResponse".*?href="/HZ-01-basement/response-plan.html".*?آخر تمرين 2026-09-10~s', $h);
        // ٤ مخاطر المكان بمنسقها ومعالجها لمن يملك السجل
        $this->assertMatchesRegularExpression('~data-risk="PH-01-01/PARK".*?المنسق: اسم mudir · المعالج: اسم fani~s', $h);
        // ٥ المفتوح الآن يجمع الأرقام والبلاغات
        $this->assertMatchesRegularExpression('~id="pfNow".*?id="pfKpi".*?id="pfReports".*?id="pfIncidents"~s', $h);
        // ٨ طلب تصريح هنا بالمكان محدداً
        $this->assertStringContainsString('href="'.route('permits.create').'?place=HZ-01" data-act="permit"', $h);

        // الفني: لا يملك سجل المخاطر ولا طلب التصاريح ← القسمان باهتان باسم صاحبهما، بلا رابط
        $f = $this->actingAs($fani)->get("/app/places/{$park->id}/file")->assertOk()->getContent();
        $this->assertStringContainsString('class="sec-h pf-dim" id="pfRisks"', $f);
        $this->assertStringNotContainsString('data-risk="PH-01-01/PARK"', $f);
        $this->assertStringNotContainsString(route('risk.active.index').'?place=HZ-01', $f);
        $this->assertStringContainsString('<span class="btn btn-o pf-dim" data-act="permit"', $f);
        $this->assertStringNotContainsString(route('permits.create').'?place=HZ-01', $f);
        // والفني يرى نموذج فحصه بضغطة، والأقسام الثمانية كلها ظاهرة له
        $this->assertStringContainsString('href="/HZ-01-basement/inspection-form.html"', $f);
        foreach ($ids as $i) $this->assertStringContainsString($i, $f, $i);
    }
}
