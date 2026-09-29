<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٦-٦-ب (قرار ٦٨): ملف المكان شاشة واحدة — سطر الحال ← سطر الوحدات (مرشّح) ← سبعة أزرار في عمودين بترتيب ثابت،
 * كل زر يحمل حاله ولونه، ويفتح قسمه تحت الشبكة في مكانه؛ القسم ٦ «الوحدات» صار السطر؛ المحتوى والمعرّفات القديمة كما هي.
 */
class PlaceFileButtonsTest extends TestCase
{
    use RefreshDatabase;

    private Place $park; private User $salama; private User $fani;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, EmergencySeeder::class]);
        $this->park = Place::where('code', 'HZ-01')->first();
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker', $this->park->id);
    }

    private function user(string $username, string $role, ?int $placeId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => $placeId]);
        return $u;
    }

    private function stamp(int $hoursAgo): string
    {
        $t = now()->subHours($hoursAgo);
        return $t->format('Y/m/d').' — '.$t->format('H:i');
    }

    /** القبو كما في PlaceFileTest: أربعة أنظمة (سليم، لم يُفحص، متأخر، عطل)، بلاغ فحص متجاوز يحمل الوحدة b1، فريق معتمد، ووحدتان */
    private function seedBasement(): void
    {
        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        $round = fn (int $ago, int $ok, int $no) => ['d' => $day($ago), 's' => '09:00', 't' => '09:40', 'q' => 'فني', 'n' => 'الفني', 'f' => 'شهري', 'ok' => $ok, 'no' => $no, 'na' => 0, 'tot' => $ok + $no];
        $def = fn (string $name, string $code, string $freq) => ['items' => [['بند', 'SBC']], 'name' => $name, 'code' => $code, 'sched' => [[$freq, 'فحص', 'الفني المختص'], ['سنوي', 'قياس', 'المكتب']]];
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode([
            'defs' => ['p01' => $def('التهوية وسحب العوادم', '٠١', 'شهري'), 'p02' => $def('الإنارة', '٠٢', 'أسبوعي'), 'p03' => $def('كواشف الغاز', '٠٣', 'شهري'), 'p04' => $def('المخارج', '٠٤', 'شهري')],
            'rounds' => ['p01' => [$round(40, 5, 1), $round(10, 8, 0)], 'p03' => [$round(45, 6, 0)], 'p04' => [$round(3, 4, 2)]],
            'reports' => [
                ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'unit' => 'b1', 'item' => 'مراوح السحب لا تعمل', 'imp' => 'none', 'due' => 'فوري', 'when' => $this->stamp(30), 'sent' => $this->stamp(30), 'path' => 'إداري',
                    'levels' => [1 => ['up' => true, 'back' => false, 'by' => 'الفني']]],
            ],
        ], JSON_UNESCAPED_UNICODE)]);
        $row = fn ($role, $name, $phone) => ['role' => $role, 'name' => $name, 'user' => '', 'dept' => '', 'phone' => $phone, 'trained' => '', 'trainer' => ''];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-01' => [
            'plans' => ['sa' => '2026-09-17', 'saBy' => 'مدير الشؤون الإدارية والهندسية', 'ra' => '2026-09-17', 'drill' => '2026-09-20'],
            'units' => ['_' => ['team' => [$row('المنسق', 'عيادة العنزي', '0500000001'), $row('المسعف', 'فيصل قيسي', '0500000002'), $row('المنقذ', 'ماجد', ''), $row('الإطفائي', 'سامي', '')],
                'nom' => ['by' => 'م', 'dept' => '', 'date' => '2026-09-17'], 'appr' => ['by' => 'م', 'date' => '2026-09-17'], 'hr' => []]],
        ]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
        PlaceUnit::create(['place_id' => $this->park->id, 'type' => 'parking_zone', 'name' => 'b1', 'floor' => 'b1', 'location' => 'القبو الأول', 'capacity' => 60]);
        PlaceUnit::create(['place_id' => $this->park->id, 'type' => 'parking_zone', 'name' => 'b2', 'floor' => 'b2', 'location' => 'القبو الثاني', 'capacity' => 80]);
    }

    public function test_units_line_then_seven_state_buttons_then_hidden_sections(): void
    {
        $this->seedBasement();
        $h = $this->actingAs($this->salama)->get("/app/places/{$this->park->id}/file")->assertOk()->getContent();

        // الترتيب: سطر الوحدات ← الشبكة بأزرارها السبعة ← الأقسام
        $ids = ['id="pfUnitsLine"', 'id="pfGrid"', 'id="pfPlanSafety"', 'id="pfPlanResponse"', 'id="pfForms"', 'id="pfRisks"', 'id="pfNow"', 'id="pfTeamH"', 'id="pfPermit"',
                'id="pfSecForms"', 'id="pfSecRisks"', 'id="pfSecNow"', 'id="pfSecTeam"'];
        $pos = array_map(fn ($i) => strpos($h, $i), $ids);
        $this->assertNotContains(false, $pos, 'معرّف ناقص');
        $sorted = $pos; sort($sorted);
        $this->assertSame($sorted, $pos, 'الترتيب: الوحدات ← الشبكة (١ ٢ ٣ ٤ ٥ ٧ ٨) ← الأقسام');
        $this->assertStringNotContainsString('id="pfUnitsH"', $h); // القسم ٦ صار سطر الوحدات
        $this->assertSame(7, substr_count($h, 'class="pf-btn'), 'سبعة أزرار');

        // الأقسام مطوية حتى تُضغط
        foreach (['pfSecForms', 'pfSecRisks', 'pfSecNow', 'pfSecTeam'] as $s) $this->assertMatchesRegularExpression('~id="'.$s.'"[^>]*\shidden~', $h, $s);

        // كل زر يحمل حاله ولونه
        $this->assertMatchesRegularExpression('~id="pfPlanSafety"[^>]*data-state="ok"[^>]*href="/HZ-01-basement/safety-plan.html"~', $h);
        $this->assertMatchesRegularExpression('~id="pfPlanResponse"[^>]*data-state="ok"[^>]*href="/HZ-01-basement/response-plan.html"~', $h);
        $this->assertMatchesRegularExpression('~id="pfForms"[^>]*data-state="bad".*?2 متأخر~s', $h); // الإنارة لم تُفحص + كواشف الغاز متأخرة
        $this->assertMatchesRegularExpression('~id="pfNow"[^>]*data-state="bad".*?متجاوز~s', $h);   // بلاغ «فوري» منذ ٣٠ ساعة
        $this->assertMatchesRegularExpression('~id="pfRisks"[^>]*data-state="none"~', $h);         // لا خطر مفعّل
        $this->assertMatchesRegularExpression('~id="pfTeamH"[^>]*data-state="(ok|warn)"~', $h);
        $this->assertMatchesRegularExpression('~id="pfPermit"[^>]*data-state="act"[^>]*href="'.preg_quote(route('permits.create'), '~').'\?place=HZ-01"~', $h);

        // سطر الوحدات: الكل ثم الوحدتان، وبطاقة بلاغ الفحص تحمل وحدتها ليُرشَّح بها
        $this->assertMatchesRegularExpression('~id="pfUnitsLine".*?data-unit=""[^>]*>.*?الكل.*?data-unit="b1".*?data-unit="b2"~s', $h);
        $this->assertMatchesRegularExpression('~id="pfReports".*?data-unit="b1"~s', $h);
        $this->assertStringContainsString('تعديل الوحدات', $h);

        // المحتوى والمعرّفات القديمة كما هي
        foreach (['pfSystems', 'pfKpi', 'pfReports', 'pfIncidents', 'pfTeams', 'pfCenter', 'pfActs'] as $id) $this->assertStringContainsString('id="'.$id.'"', $h, $id);
        $this->assertStringContainsString('href="tel:0500000001"', $h);
        $this->assertStringContainsString('مراوح السحب لا تعمل', $h);

        // الفني: المخاطر والتصريح باهتان باسم صاحبهما، بلا رابط
        $f = $this->actingAs($this->fani)->get("/app/places/{$this->park->id}/file")->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~id="pfRisks"[^>]*data-state="dim"~', $f);
        $this->assertMatchesRegularExpression('~id="pfPermit"[^>]*data-state="dim"~', $f);
        $this->assertStringNotContainsString(route('permits.create').'?place=HZ-01', $f);
        $this->assertStringNotContainsString('تعديل الوحدات', $f);
        $this->assertSame(7, substr_count($f, 'class="pf-btn'));
    }

    /** المكاتب الإدارية: ٣٢ إدارة — قائمة اختيار ببحث بدل الرقاقات، وفيها الإدارات كلها من الهيكل */
    public function test_offices_units_line_is_a_select_with_every_department(): void
    {
        $offices = Place::where('code', 'HZ-06')->first();
        $n = OrganizationUnit::where('place_id', $offices->id)->where('is_active', true)->count();
        $h = $this->actingAs($this->salama)->get("/app/places/{$offices->id}/file")->assertOk()->getContent();
        $this->assertStringContainsString('id="pfUnitSelect"', $h);
        $this->assertSame($n, substr_count($h, 'data-unit-chip="'));
        $this->assertStringNotContainsString('class="pf-chip', $h);
    }
}
