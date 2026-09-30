<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\PlaceSnapshot;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٥-٢ (قرار ٦٤): الرسم في الصفحة الأولى «حال الآن» — ستة أعمدة من مصدر واحد (`PlaceSnapshot`) لنطاق الحساب،
 * تتغيّر بالمكان المضغوط بلا طلب ثانٍ، وكل عمود يفتح قائمته. لا رسم شهري ولا «صورة المبنى» في الصفحة الأولى.
 */
class HomeChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, PermitTypesSeeder::class]);
        $stamp = now()->subHours(30)->format('Y/m/d').' — '.now()->subHours(30)->format('H:i');
        // القبو: بلاغ فحص مفتوح متجاوز، وجدول دوري شهري للفني لم تُنفَّذ جولته قط = جولة مستحقة
        $def = ['name' => 'التهوية', 'code' => '٠١', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'فحص المراوح', 'الفني المختص']]];
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['p01' => $def], 'reports' => [
            ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'مراوح لا تعمل', 'imp' => 'none', 'due' => 'فوري', 'when' => $stamp, 'sent' => $stamp, 'path' => 'إداري',
                'levels' => [1 => ['up' => true, 'back' => false]]],
        ]], JSON_UNESCAPED_UNICODE)]);
        $offices = Place::idByCode('HZ-06');
        // المكاتب: حالة طارئة مفتوحة (لا تمرين)، وتمرين مفتوح لا يُعدّ، وحالة منتهية لا تُعدّ
        $b = EmergencyBuilding::main();
        EmergencyIncident::create(['building_id' => $b->id, 'place_id' => $offices, 'incident_code' => 'ط-9001', 'incident_type' => 'fire', 'status' => 'active', 'is_drill' => false, 'triggered_at' => now()]);
        EmergencyIncident::create(['building_id' => $b->id, 'place_id' => $offices, 'incident_code' => 'ط-9002', 'incident_type' => 'fire', 'status' => 'active', 'is_drill' => true, 'triggered_at' => now()]);
        EmergencyIncident::create(['building_id' => $b->id, 'place_id' => $offices, 'incident_code' => 'ط-9003', 'incident_type' => 'fire', 'status' => 'ended', 'is_drill' => false, 'triggered_at' => now()->subDay()]);
        // المكاتب: تصريح فعال وآخر منتهٍ
        $type = PermitType::query()->firstOrFail();
        Permit::create(['code' => 'P-9001', 'permit_type_id' => $type->id, 'permit_category' => $type->category ?? 'work', 'title' => 'أعمال ساخنة', 'place_id' => $offices, 'status' => Permit::STATUS_ACTIVE]);
        Permit::create(['code' => 'P-9002', 'permit_type_id' => $type->id, 'permit_category' => $type->category ?? 'work', 'title' => 'منتهٍ', 'place_id' => $offices, 'status' => 'expired']);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_snapshot_counts_now_from_existing_sources_and_respects_scope(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $emp = $this->user('emp', 'employee', 'HZ-06');
        // بلاغ شاغل مفتوح في المكاتب، وآخر مغلق لا يُعدّ
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $this->post('/incident/normal', ['description' => 'مغلق', 'place_id' => Place::idByCode('HZ-01')])->assertRedirect();
        \App\Modules\Incident\Models\Incident::where('description', 'مغلق')->update(['status' => 'closed']);

        $all = PlaceSnapshot::forUser($salama);
        $this->assertTrue($all['all']);
        $this->assertSame(9, count($all['places']));
        $this->assertSame(['incidents' => 1, 'reports' => 0, 'overdue' => 0, 'rounds' => 0, 'emergency' => 1, 'permits' => 1], $all['places']['HZ-06']['n']);
        $this->assertSame(['incidents' => 0, 'reports' => 1, 'overdue' => 1, 'rounds' => 1, 'emergency' => 0, 'permits' => 0], $all['places']['HZ-01']['n']);
        $this->assertSame(['incidents' => 1, 'reports' => 1, 'overdue' => 1, 'rounds' => 1, 'emergency' => 1, 'permits' => 1], $all['total']);

        $mine = PlaceSnapshot::forUser($emp);
        $this->assertFalse($mine['all']);
        $this->assertSame(['HZ-06'], array_keys($mine['places']));
        $this->assertSame(['incidents' => 1, 'reports' => 0, 'overdue' => 0, 'rounds' => 0, 'emergency' => 1, 'permits' => 1], $mine['total']);
    }

    public function test_home_chart_has_six_clickable_columns_and_place_data_for_filtering(): void
    {
        $salama = $this->user('salama', 'system_admin');
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $html = $this->actingAs($salama)->get('/app')->assertOk()->getContent();

        // الرسم حال الآن للمبنى كله: ستة أعمدة بأرقامها، كل عمود رابط إلى قائمته
        $this->assertStringContainsString('id="chart"', $html);
        foreach (['incidents' => 1, 'reports' => 1, 'overdue' => 1, 'rounds' => 1, 'emergency' => 1, 'permits' => 1] as $k => $n) {
            $this->assertMatchesRegularExpression('~<a class="bar[^"]*" [^>]*data-k="'.$k.'" data-n="'.$n.'"~', $html, $k);
        }
        $this->assertStringContainsString('href="'.route('incidents.index', ['status' => 'open']).'"', $html);
        $this->assertStringContainsString('href="'.route('permits.index', ['status' => 'active']).'"', $html);
        $this->assertStringContainsString('href="'.url('/app/places/units?k=open').'"', $html);
        // بيانات كل مكان في الصفحة نفسها حتى يتغيّر الرسم بضغطة المربع بلا طلب ثانٍ
        $this->assertStringContainsString('id="snapshot"', $html);
        $json = json_decode(substr($html, strpos($html, '>', strpos($html, 'id="snapshot"')) + 1, strpos($html, '</script>', strpos($html, 'id="snapshot"')) - strpos($html, '>', strpos($html, 'id="snapshot"')) - 1), true);
        $this->assertSame(1, $json['places']['HZ-06']['n']['emergency']);
        $this->assertSame(1, $json['places']['HZ-01']['n']['overdue']);
        $this->assertStringContainsString(url('/app/places/'.Place::idByCode('HZ-01').'/file').'#pfReports', $json['places']['HZ-01']['links']['reports']);
        $this->assertStringContainsString('place=HZ-06', $json['places']['HZ-06']['links']['incidents']);
        // تسعة أماكن = المربع يرشّح الرسم (وزر «افتح ملف المكان» يفتح الملف)، ويبقى رابطه إلى الملف بلا سكربت
        // ٢٦-١٤: مربع المركز يفتح صفحته مباشرة ولا يرشّح — ثمانية ترشّح
        $this->assertSame(8, substr_count($html, 'data-filter="1"'));
        $this->assertStringContainsString('id="chartAll"', $html);
        $this->assertStringContainsString('id="chartFile"', $html);
        // لا رسم شهري ولا «صورة المبنى» في الصفحة الأولى
        $this->assertStringNotContainsString('id="trend"', $html);
        $this->assertStringNotContainsString('id="homeDetails"', $html);
    }

    public function test_employee_sees_the_chart_for_his_place_only_and_his_tile_still_opens_the_file(): void
    {
        $emp = $this->user('emp', 'employee', 'HZ-06');
        $html = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('id="chart"', $html);
        $this->assertMatchesRegularExpression('~data-k="emergency" data-n="1"~', $html);
        $this->assertMatchesRegularExpression('~data-k="overdue" data-n="0"~', $html); // متجاوز القبو ليس في نطاقه
        $this->assertStringNotContainsString('"HZ-01"', $html);
        // مكان واحد: لا ترشيح — المربع يفتح الملف مباشرة (ضغطة واحدة تبقى)
        $this->assertStringNotContainsString('data-filter="1"', $html);
        $this->assertStringNotContainsString('id="chartAll"', $html);
        // الموظف ليس من أدوار القرار: عمود بلاغات الفحص يفتح ملف مكانه لا قائمة المبنى
        $this->assertStringNotContainsString('/app/places/units?k=', $html);
        $this->assertStringContainsString(url('/app/places/'.Place::idByCode('HZ-06').'/file').'#pfReports', $html);
    }
}
