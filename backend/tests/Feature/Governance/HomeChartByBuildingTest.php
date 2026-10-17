<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٣٢-١ (قرار ٩١، بكلمته «موافق» ٢٠٢٦-١٠-١٧): الرسم «حال الآن» يتبع الفرع المختار — بيانات الصفحة تحمل مجموع كل مبنى على حدة
 * بجانب المجموع الكلي، والأعمدة تبدأ بأرقام مبنى الجلسة لا بمجموع المباني. من يرى فرعه وحده (مدير الفرع) كما كان.
 */
class HomeChartByBuildingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    /** بيانات الرسم كما تصل إلى سكربت الصفحة */
    private function snapshot(string $html): array
    {
        $this->assertSame(1, preg_match('~<script type="application/json" id="snapshot">(.*?)</script>~s', $html, $m), 'بيانات الرسم في الصفحة');
        return json_decode($m[1], true);
    }

    public function test_chart_follows_the_selected_building_and_branch_manager_is_unchanged(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $main = EmergencyBuilding::main();
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $main->update(['branch_unit_id' => $hq->id]);
        $branch = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $branch->id]);
        Place::createCategoriesFor($b);
        $dmmBasement = Place::where('building_id', $b->id)->where('category', 'HZ-01')->firstOrFail();
        // بلاغ شاغل مفتوح في قبو الدمام وحده
        Incident::create(['code' => 'ش-3201', 'title' => 'ق', 'description' => 'بلاغ قبو الدمام', 'incident_type' => 'normal', 'status' => 'new', 'place_id' => $dmmBasement->id]);

        // مسؤول السلامة يرى الكل ومبنى جلسته الملز: الرسم يبدأ بأرقام الملز (صفر بلاغات)، ومعه مجموع كل مبنى والمجموع الكلي
        $salama = $this->user('salama', 'system_admin');
        $h = $this->actingAs($salama)->get(route('app.home'))->assertOk()->getContent();
        $D = $this->snapshot($h);
        $this->assertSame(1, $D['total']['incidents'], 'المجموع الكلي');
        $this->assertArrayHasKey('buildings', $D, 'لا مجموع لكل مبنى في بيانات الرسم');
        $this->assertSame(0, $D['buildings'][$main->id]['total']['incidents'], 'مجموع الملز');
        $this->assertSame(1, $D['buildings'][$b->id]['total']['incidents'], 'مجموع الدمام');
        $this->assertSame('معهد الادارة فرع الدمام', $D['buildings'][$b->id]['name']);
        $this->assertSame($main->id, $D['sel'], 'الفرع المختار مبنى الجلسة');
        $this->assertStringContainsString('id="chartScope">'.e($main->name).'</span>', $h, 'عنوان الرسم ليس اسم الفرع المختار');
        $this->assertStringContainsString('data-k="incidents" data-n="0"', $h, 'عمود البلاغات يبدأ بمجموع المباني لا بالملز');
        $this->assertStringContainsString('>الفرع كله</button>', $h);

        // بعد التبديل إلى الدمام: الرسم يبدأ بأرقام الدمام
        $this->actingAs($salama)->post(route('app.building.switch', $b));
        $h = $this->actingAs($salama)->get(route('app.home'))->assertOk()->getContent();
        $D = $this->snapshot($h);
        $this->assertSame($b->id, $D['sel']);
        $this->assertStringContainsString('id="chartScope">'.e($b->name).'</span>', $h);
        $this->assertStringContainsString('data-k="incidents" data-n="1"', $h);

        // مدير فرع الدمام: فرعه وحده كما كان — بلا صف، العنوان «أماكنك»، ولا يحمل مجموع مبنى غيره
        $mgr = $this->user('mgr.dmm', 'branch_manager', $b, null, $branch);
        $h = $this->actingAs($mgr)->get(route('app.home'))->assertOk()->getContent();
        $D = $this->snapshot($h);
        $this->assertNull($D['sel']);
        $this->assertSame([$b->id], array_map('intval', array_keys($D['buildings'])), 'مدير الفرع يحمل مجموع مبنى غيره');
        $this->assertSame(1, $D['total']['incidents']);
        $this->assertStringContainsString('id="chartScope">أماكنك</span>', $h);
        $this->assertStringContainsString('data-k="incidents" data-n="1"', $h);
        $this->assertStringNotContainsString('id="bldTabs"', $h);
    }
}
