<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyNotification;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\BuildingContext;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بكلمته «نعم» (٢٠٢٦-١٠-٠٨): من مركز السلامة وإدارة الطوارئ في المركز الرئيسي يرى مسؤول السلامة ومن يمنحه «يرى كل الفروع»
 * جميع الفروع (بطاقة لكل فرع وضغطة تدخل مركزه)، ولا يرى فرع فرعاً آخر ولا يصله تنبيهه.
 */
class CenterAllBranchesTest extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $branch = OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region']);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الشرقية — المبنى الرئيسي', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $branch->id]);
        Place::createCategoriesFor($this->b);
    }

    private function bp(string $cat, ?EmergencyBuilding $b = null): Place
    {
        return Place::where('building_id', ($b ?? $this->b)->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, bool $all = false): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'sees_all_buildings' => $all]);
        return $u;
    }

    private function openEmergency(EmergencyBuilding $building, Place $place, string $code): EmergencyIncident
    {
        return EmergencyIncident::create(['building_id' => $building->id, 'place_id' => $place->id, 'incident_code' => $code, 'incident_type' => 'fire', 'status' => 'active', 'is_drill' => false, 'triggered_at' => now()]);
    }

    public function test_safety_officer_grants_sees_all_from_the_account_form_and_no_one_else_can(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $munawibHq = $this->user('munawib.hq', 'system_staff', $this->main, Place::find(Place::idByCode('HZ-00')));
        $this->assertFalse(BuildingContext::canSwitch($munawibHq));

        $this->actingAs($salama)->put(route('app.users.update', $munawibHq), ['username' => 'munawib.hq', 'name' => 'مناوب المركز', 'role' => 'system_staff',
            'building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-00'), 'sees_all_buildings' => 1])->assertRedirect();
        $this->assertTrue($munawibHq->profile->fresh()->sees_all_buildings, 'الخانة لم تُحفظ');
        $this->assertTrue(BuildingContext::canSwitch($munawibHq->fresh()), 'من يرى كل الفروع بلا مبدّل');
        $this->assertSame(2, BuildingContext::choices($munawibHq->fresh())->count());
        $this->get(route('app.users.edit', $munawibHq))->assertOk()->assertSee('name="sees_all_buildings"', false);

        // مدير المرافق يسجّل فنيّيه ولا يملك هذه الخانة: تُهمل ولا تظهر
        $marafiq = $this->user('marafiq', 'facilities_manager', $this->main);
        $fani = $this->user('fani', 'tech_electrical', $this->main, Place::find(Place::idByCode('HZ-01')));
        $this->actingAs($marafiq)->get(route('app.users.edit', $fani))->assertOk()->assertDontSee('name="sees_all_buildings"', false);
        $this->actingAs($marafiq)->put(route('app.users.update', $fani), ['username' => 'fani', 'name' => 'فني', 'role' => 'tech_electrical',
            'building_id' => $this->main->id, 'place_id' => Place::idByCode('HZ-01'), 'sees_all_buildings' => 1]);
        $this->assertFalse($fani->profile->fresh()->sees_all_buildings, 'غير مسؤول السلامة منح «يرى كل الفروع»');
    }

    public function test_center_page_shows_all_branches_to_those_who_see_all_and_only_its_own_to_a_branch(): void
    {
        $this->openEmergency($this->main, Place::find(Place::idByCode('HZ-01')), 'ط-9201');
        $this->openEmergency($this->b, $this->bp('HZ-01'), 'ط-9202');
        $salama = $this->user('salama', 'system_admin');
        $munawibM = $this->user('munawib.m', 'system_staff', $this->main, Place::find(Place::idByCode('HZ-00')));
        $munawibB = $this->user('munawib.b', 'system_staff', $this->b, $this->bp('HZ-00'));
        $seer = $this->user('seer', 'system_staff', $this->main, Place::find(Place::idByCode('HZ-00')), true);

        $h = $this->actingAs($munawibB)->get(route('emergency.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('ط-9202', $h);
        $this->assertStringNotContainsString('ط-9201', $h, 'مناوب الشرقية يرى حالة الملز');
        $this->assertStringNotContainsString('data-branch-card', $h, 'مناوب الفرع يرى بطاقات الفروع');

        $h = $this->actingAs($munawibM)->get(route('emergency.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('ط-9201', $h);
        $this->assertStringNotContainsString('ط-9202', $h, 'مناوب الملز يرى حالة الشرقية');

        foreach ([$salama, $seer] as $who) {
            $h = $this->actingAs($who)->get(route('emergency.dashboard'))->assertOk()->getContent();
            $this->assertStringContainsString('ط-9201', $h);
            $this->assertStringContainsString('ط-9202', $h, 'من يرى الكل لا يرى حالة الشرقية');
            $this->assertSame(2, substr_count($h, 'data-branch-card='), 'بطاقات الفروع ناقصة');
            $this->assertStringContainsString('ادخل مركزه', $h);
            $this->assertStringContainsString('اسم munawib.b', $h, 'بطاقة الفرع لا تسمّي مناوبه');
        }
        // ضغطة تدخل مركز الفرع
        $this->actingAs($salama)->post(route('app.building.switch', $this->b))->assertRedirect();
        $this->assertSame($this->b->id, BuildingContext::id($salama));
    }

    public function test_emergency_in_a_branch_alerts_its_duty_and_those_who_see_all_but_not_other_branches(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $munawibM = $this->user('munawib.m', 'system_staff', $this->main, Place::find(Place::idByCode('HZ-00')));
        $munawibB = $this->user('munawib.b', 'system_staff', $this->b, $this->bp('HZ-00'));
        $seer = $this->user('seer', 'system_staff', $this->main, Place::find(Place::idByCode('HZ-00')), true);
        $employeeM = $this->user('mowathaf.m', 'employee', $this->main, Place::find(Place::idByCode('HZ-06')));
        $employeeB = $this->user('mowathaf.b', 'employee', $this->b, $this->bp('HZ-06'));

        $inc = app(EmergencyService::class)->triggerAlarm($this->b, 'fire', $salama, 'high', false, 'دخان', $this->bp('HZ-01')->id);
        $to = EmergencyNotification::where('incident_id', $inc->id)->where('recipient_type', 'user')->pluck('recipient_id')->map(fn ($i) => (int) $i)->all();

        $this->assertContains($munawibB->id, $to, 'مناوب الشرقية لم يُنبَّه');
        $this->assertContains($salama->id, $to, 'مسؤول السلامة لم يُنبَّه');
        $this->assertContains($seer->id, $to, 'من يرى كل الفروع لم يُنبَّه');
        $this->assertContains($employeeB->id, $to, 'شاغل الشرقية لم يُنبَّه بالإخلاء');
        $this->assertNotContains($munawibM->id, $to, 'مناوب الملز نُبّه بحالة الشرقية');
        $this->assertNotContains($employeeM->id, $to, 'شاغل الملز نُبّه بإخلاء الشرقية');
    }
}
