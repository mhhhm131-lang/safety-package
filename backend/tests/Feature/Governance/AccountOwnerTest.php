<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٠-٢ (قرار ٥١): الحساب يحمل صاحبه — المسمى الوظيفي (مؤقت حتى البوابة)، والمبنى، والتغطية من الأماكن (أكثر من مكان).
 * «مكاني» يبقى مكان الحساب أو إدارته؛ التغطية للتوجيه (٢٠-٥).
 */
class AccountOwnerTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->salama = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234']);
        UserProfile::create(['user_id' => $this->salama->id, 'role' => 'system_admin', 'is_active' => true]);
    }

    public function test_account_carries_title_building_and_coverage(): void
    {
        $main = EmergencyBuilding::main();
        [$park, $elec, $store] = [Place::idByCode('HZ-01'), Place::idByCode('HZ-02'), Place::idByCode('HZ-08')];

        // الشاشة تعرض الحقول الثلاثة
        $h = $this->actingAs($this->salama)->get('/app/users/create')->assertOk()->getContent();
        $this->assertStringContainsString('name="job_title"', $h);
        $this->assertStringContainsString('name="building_id"', $h);
        $this->assertStringContainsString('name="coverage[]"', $h);
        $this->assertStringContainsString('مؤقت حتى', $h); // المسمى يأتي من بوابة المعهد لاحقاً

        $this->actingAs($this->salama)->post('/app/users', ['username' => 'kahraba', 'name' => 'فني', 'password' => '123456', 'role' => 'field_worker',
            'place_id' => $elec, 'job_title' => 'فني كهرباء أول', 'building_id' => $main->id, 'coverage' => [$park, $elec, $store]])->assertRedirect('/app/users');
        $p = User::where('username', 'kahraba')->first()->profile;
        $this->assertSame('فني كهرباء أول', $p->job_title);
        $this->assertSame($main->id, $p->building_id);
        $this->assertSame(['HZ-01', 'HZ-02', 'HZ-08'], $p->coverage->pluck('code')->all());
        $this->assertTrue($p->covers(Place::find($store)));
        $this->assertFalse($p->covers(Place::find(Place::idByCode('HZ-03'))));

        // التعديل يعرض ما حُفظ ويستبدل التغطية
        $u = User::where('username', 'kahraba')->first();
        $e = $this->actingAs($this->salama)->get("/app/users/{$u->id}/edit")->assertOk()->getContent();
        $this->assertStringContainsString('value="فني كهرباء أول"', $e);
        $this->assertStringContainsString('value="'.$store.'" checked', $e);
        $this->actingAs($this->salama)->put("/app/users/{$u->id}", ['username' => 'kahraba', 'name' => 'فني', 'role' => 'field_worker',
            'place_id' => $elec, 'job_title' => 'فني كهرباء', 'building_id' => $main->id, 'coverage' => [$elec]])->assertRedirect();
        $this->assertSame(['HZ-02'], $p->fresh()->coverage->pluck('code')->all());

        // القائمة تعرض المسمى والتغطية
        $i = $this->actingAs($this->salama)->get('/app/users')->assertOk()->getContent();
        $this->assertStringContainsString('فني كهرباء', $i);
        $this->assertStringContainsString('يغطي ١', $i);

        // «مكاني» يبقى مكان الحساب لا التغطية
        $this->assertSame('HZ-02', $p->fresh()->myPlace()->code);
    }

    public function test_coverage_must_be_places_of_the_account_building_and_is_optional(): void
    {
        $main = EmergencyBuilding::main();
        $other = EmergencyBuilding::create(['code' => 'DMM-1', 'name' => 'مبنى الدمام', 'branch' => 'الدمام']);
        $far = Place::create(['code' => 'HZ-09', 'name' => 'قبو الدمام', 'sort' => 90, 'building_id' => $other->id]);

        $this->actingAs($this->salama)->post('/app/users', ['username' => 'x1', 'name' => 'فني', 'password' => '123456', 'role' => 'field_worker',
            'building_id' => $main->id, 'coverage' => [$far->id]])->assertSessionHasErrors('coverage.0');
        $this->assertNull(User::where('username', 'x1')->first());

        $this->actingAs($this->salama)->post('/app/users', ['username' => 'x2', 'name' => 'موظف', 'password' => '123456', 'role' => 'employee'])->assertRedirect('/app/users');
        $p = User::where('username', 'x2')->first()->profile;
        $this->assertSame($main->id, $p->building_id, 'بلا مبنى محدد = الرئيسي');
        $this->assertCount(0, $p->coverage);
        $this->assertNull($p->job_title);
    }
}
