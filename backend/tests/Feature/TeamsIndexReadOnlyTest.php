<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Services\TeamSync;
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
 * المرحلة ١٦ الدفعة ٦ (قرار ٤٤): فتح شاشة الفرق طلب قراءة — لا يكتب في قاعدة البيانات.
 * المزامنة من ملف المكان تقع عند حفظ الوثيقة (StoreController) وبزر «مزامنة من اللوحة» (POST).
 */
class TeamsIndexReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function team(string $prefix): array
    {
        return array_map(fn ($r) => ['role' => $r, 'name' => "$prefix $r", 'dept' => '', 'phone' => '', 'trained' => '', 'trainer' => ''],
            ['المنسق', 'المسعف', 'المنقذ', 'الإطفائي']);
    }

    private function doc(array $unit): void
    {
        $code = OrganizationUnit::first()->code;
        InstituteDocument::updateOrCreate(['key' => 'ipa-place'],
            ['version' => 1, 'data' => json_encode(['HZ-06' => ['plans' => [], 'units' => [$code => $unit]]], JSON_UNESCAPED_UNICODE)]);
    }

    public function test_opening_the_teams_screen_does_not_write_to_the_database(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $u = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234', 'email' => 'salama@example.test']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'system_admin', 'is_active' => true]);

        $nom = ['by' => 'م', 'dept' => OrganizationUnit::first()->code, 'date' => '2026-09-01'];
        $this->doc(['team' => $this->team('أول'), 'nom' => $nom, 'appr' => [], 'hr' => []]);
        app(TeamSync::class)->sync();
        $this->assertSame(1, EmergencyTeam::where('source', 'place_profile')->count());
        $before = EmergencyTeam::where('source', 'place_profile')->first()->synced_at;

        // تتغيّر الوثيقة بلا حفظ عبر المخزن (لا مزامنة): فتح الشاشة يجب ألا يشتقّ منها شيئاً
        $this->doc(['team' => $this->team('أول'), 'nom' => $nom, 'appr' => [], 'hr' => [],
            'more' => [['team' => $this->team('ثانٍ'), 'nom' => $nom, 'appr' => [], 'hr' => []]]]);

        $this->actingAs($u)->get(route('emergency.teams.index'))->assertOk();

        $this->assertSame(1, EmergencyTeam::where('source', 'place_profile')->count(), 'فتح الشاشة اشتقّ فريقاً جديداً — أي أنه كتب في القاعدة');
        $this->assertEquals($before, EmergencyTeam::where('source', 'place_profile')->first()->fresh()->synced_at, 'فتح الشاشة غيّر وقت المزامنة');

        // والزر يزامن فعلاً
        $this->actingAs($u)->post(route('emergency.teams.sync'))->assertRedirect();
        $this->assertSame(2, EmergencyTeam::where('source', 'place_profile')->count(), 'زر المزامنة لم يعد يزامن');
    }
}
