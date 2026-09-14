<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٥-٤ (قرار ٤٣): ربط عضو الفريق بحسابه — في الترشيح يُكتب اسم الدخول بجانب الاسم (`user`)،
 * و`TeamSync` يملأ user_id، فيُنبَّه العضو بخطوته عند حالة في مكانه وحده.
 */
class TeamAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $salama; private User $munawib; private User $saad; private User $fahd;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(base_path());
        if (!is_file($root.'/HZ-01-basement/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة');
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        app(ResponsePlanSync::class)->sync($root);
        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->saad = $this->user('saad', 'medic', 'HZ-01');
        $this->fahd = $this->user('fahd', 'firefighter', 'HZ-01');
    }

    private function user(string $username, string $role, ?string $place = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($place)]);
        return $u;
    }

    private function basementTeam(): void
    {
        $row = fn ($role, $name, $user) => ['role' => $role, 'name' => $name, 'user' => $user, 'dept' => '', 'phone' => '', 'trained' => '', 'trainer' => ''];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-01' => ['plans' => [], 'units' => ['_' => [
            'team' => [$row('المنسق', 'ناصر', ''), $row('المسعف', 'سعد', 'SAAD'), $row('المنقذ', 'خالد', 'غير-موجود'), $row('الإطفائي', '', 'fahd')],
            'nom' => ['by' => 'م', 'dept' => '', 'date' => '2026-09-01'], 'appr' => [], 'hr' => [],
        ]]]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
    }

    private function trigger(string $place): EmergencyIncident
    {
        $b = EmergencyBuilding::main();
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$b->id}/trigger", ['incident_type' => 'fire', 'severity' => 'high', 'place_id' => Place::idByCode($place)])->assertRedirect();
        return EmergencyIncident::orderByDesc('id')->first();
    }

    public function test_team_sync_links_member_to_account_by_username(): void
    {
        $this->basementTeam();
        $m = EmergencyTeam::where('source', 'place_profile')->first()->members->keyBy('role_key');
        $this->assertSame($this->saad->id, $m['medic']->user_id);        // اسم الدخول بلا حساسية لحالة الحروف
        $this->assertNull($m['rescuer']->user_id);                         // حساب غير موجود: الاسم يبقى بلا ربط
        $this->assertNull($m['coordinator']->user_id);
        $this->assertSame($this->fahd->id, $m['firefighter']->user_id);
        $this->assertSame('اسم fahd', $m['firefighter']->name);           // بلا اسم مكتوب: اسم الحساب
    }

    public function test_linked_member_is_alerted_in_own_place_only(): void
    {
        $this->basementTeam();
        $this->trigger('HZ-01');
        $this->assertSame(1, AppNotification::where('user_id', $this->saad->id)->where('type', 'emergency.plan_step')->count(), 'مسعف القبو لم يُنبَّه بحالة في القبو');
        $this->assertSame(1, AppNotification::where('user_id', $this->fahd->id)->where('type', 'emergency.plan_step')->count());

        $i = EmergencyIncident::orderByDesc('id')->first();
        $this->actingAs($this->munawib)->post("/app/emergency/incidents/{$i->id}/cancel", ['reason' => 'اختبار']);
        $this->trigger('HZ-02');
        $this->assertSame(1, AppNotification::where('user_id', $this->saad->id)->where('type', 'emergency.plan_step')->count(), 'مسعف القبو نُبّه بحالة في غرف الكهرباء');
    }

    public function test_nominators_get_account_suggestions_and_others_do_not(): void
    {
        $this->actingAs($this->salama)->getJson('/api/team-accounts')->assertOk()
            ->assertJsonFragment(['u' => 'saad', 'n' => 'اسم saad', 'r' => 'مسعف']);
        $mudir = $this->user('mudir', 'department_manager');
        $this->actingAs($mudir)->getJson('/api/team-accounts')->assertOk();
        $this->actingAs($this->saad)->getJson('/api/team-accounts')->assertStatus(403);
        $this->actingAs($this->user('fani', 'field_worker'))->getJson('/api/team-accounts')->assertStatus(403);
    }
}
