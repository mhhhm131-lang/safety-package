<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Models\User;
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
 * المرحلة ١٩-٦ (قرار ٤٩): «مكاني» زر وسطر لا صفحة — كل حساب له مكان (ومنهم الموظف) يصل ملف مكانه بضغطة،
 * ورقم المركز في الرأس وفي ملف المكان، و«بطاقة دوري» مباشرة، ومهام مكانه أولاً عند التساوي.
 */
class MakaniTest extends TestCase
{
    use RefreshDatabase;

    private Place $hub; private Place $park; private OrganizationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->hub = Place::where('code', 'HZ-06')->first();
        $this->park = Place::where('code', 'HZ-01')->first();
        $this->unit = OrganizationUnit::where('place_id', $this->hub->id)->first();
    }

    private function user(string $username, string $role, ?int $unitId = null, ?int $placeId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId, 'place_id' => $placeId]);
        return $u;
    }

    public function test_everyone_with_a_place_reaches_their_place_file_in_one_press(): void
    {
        $emp = $this->user('emp', 'employee', $this->unit->id);          // مكانه = مكان إدارته
        $fani = $this->user('fani', 'field_worker', null, $this->park->id); // مكانه = مكان حسابه
        $salama = $this->user('salama', 'system_admin');                  // بلا مكان

        $h = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('data-intent="makani"', $h);
        $this->assertStringContainsString('id="makaniLine"', $h);
        $this->assertStringContainsString('href="/app/places/'.$this->hub->id.'/file"', $h);
        $this->assertStringContainsString('المكاتب الإدارية', $h);
        $this->assertStringContainsString('href="tel:0505498966"', $h);
        // الموظف يفتح ملف مكانه ويجد رقم المركز مع الفريق
        $f = $this->actingAs($emp)->get("/app/places/{$this->hub->id}/file")->assertOk()->getContent();
        $this->assertStringContainsString('id="pfCenter"', $f);
        $this->assertStringContainsString('href="tel:0505498966"', $f);

        // في المكاتب ٣٢ إدارة: فريق إدارة الشخص أولاً (هاتف فريقه بلا بحث)
        $last = OrganizationUnit::where('place_id', $this->hub->id)->where('is_active', true)->orderByDesc('order')->orderByDesc('id')->first();
        $emp2 = $this->user('emp2', 'employee', $last->id);
        $f = $this->actingAs($emp2)->get("/app/places/{$this->hub->id}/file")->assertOk()->getContent();
        preg_match('/data-unit-team="([^"]+)"/', $f, $m);
        $this->assertSame((string) $last->code, $m[1] ?? null, 'إدارة الشخص ليست أولاً في ملف مكانه');

        $h = $this->actingAs($fani)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('href="/app/places/'.$this->park->id.'/file"', $h);
        $this->assertStringContainsString('id="makaniLine"', $h);

        $h = $this->actingAs($salama)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-intent="makani"', $h);
        $this->assertStringNotContainsString('id="makaniLine"', $h);
    }

    public function test_my_role_card_opens_directly(): void
    {
        $emp = $this->user('emp', 'employee', $this->unit->id);
        $medic = $this->user('musif', 'employee', $this->unit->id);
        $salama = $this->user('salama', 'system_admin');
        $fani = $this->user('fani', 'field_worker', null, $this->park->id);

        // «musif» مسعف في فريق إدارته
        $row = fn ($role, $name, $user) => ['role' => $role, 'name' => $name, 'user' => $user, 'dept' => '', 'phone' => '', 'trained' => '', 'trainer' => ''];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-06' => ['plans' => new \stdClass, 'units' => [$this->unit->code => [
            'team' => [$row('المنسق', 'منسق', ''), $row('المسعف', 'اسم musif', 'musif'), $row('المنقذ', '', ''), $row('الإطفائي', '', '')],
            'nom' => ['by' => 'م', 'dept' => $this->unit->code, 'date' => '2026-09-01'], 'appr' => new \stdClass, 'hr' => new \stdClass]]]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();

        $this->actingAs($emp)->get('/app')->assertSee('href="/role-cards/occupants/role-12.html"', false)->assertSee('بطاقة دوري');
        $this->actingAs($medic)->get('/app')->assertSee('href="/role-cards/response-team/role-09.html"', false);
        $this->actingAs($salama)->get('/app')->assertSee('href="/role-cards/leadership/role-20.html"', false);
        // الفني: ست بطاقات بحسب التخصص — يبقى الفهرس
        $this->actingAs($fani)->get('/app')->assertSee('href="/role-cards/index.html"', false);
    }

    public function test_among_equally_late_tasks_my_place_comes_first_but_late_still_beats_place(): void
    {
        $def = ['name' => 'نظام', 'code' => '٠١', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'فحص', 'الفني المختص']]];
        $round = fn (int $ago) => [['d' => now()->subDays($ago)->toDateString(), 's' => '09:00', 't' => '09:30', 'q' => 'فني', 'n' => 'سعد', 'f' => 'شهري', 'ok' => 1, 'no' => 0, 'na' => 0, 'tot' => 1]];
        // الكهرباء متأخرة ٣٠ يوماً (الأقدم مهلةً)، التكييف متأخرة ١٠ أيام، القبو مستحقة بعد ٥ أيام (غير متأخرة)
        foreach (['ipa-elec-form-v10' => 60, 'ipa-hvac-form-v10' => 40, 'ipa-park-form-v10' => 25] as $key => $ago) {
            InstituteDocument::create(['key' => $key, 'version' => 1, 'data' => json_encode(['defs' => ['s01' => $def], 'rounds' => ['s01' => $round($ago)], 'reports' => []], JSON_UNESCAPED_UNICODE)]);
        }
        $places = fn (User $u) => app(InboxService::class)->forUser($u)->where('module', 'جولات الفحص')->map(fn ($t) => substr((string) $t->place, 0, 5))->values()->all();

        $cover3 = fn (User $u) => $u->profile->coverage()->sync([Place::idByCode('HZ-01'), Place::idByCode('HZ-02'), Place::idByCode('HZ-03')]); // ٢٠-٥: الفني يرى ما يغطيه
        $inHvac = $this->user('takyif', 'field_worker', null, Place::where('code', 'HZ-03')->value('id')); $cover3($inHvac);
        $this->assertSame(['HZ-03', 'HZ-02', 'HZ-01'], $places($inHvac), 'بين المتأخرات: مكان الفني أولاً');
        // المتأخر يغلب المكان: فني القبو يرى المتأخرتين قبل مستحقة مكانه
        $inPark = $this->user('qabu', 'field_worker', null, $this->park->id); $cover3($inPark);
        $this->assertSame(['HZ-02', 'HZ-03', 'HZ-01'], $places($inPark));
    }
}
