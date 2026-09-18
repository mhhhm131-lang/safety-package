<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٩-١ (قرار ٤٨): ملف المكان في الخلفية — ما كان يعرضه `renderPlace` في dashboard.html بالمنطق نفسه:
 * الأنظمة بحالاتها الأربع، الأرقام الأربعة، الجاهزية (والبطاقة تفتح الخطة)، الوحدات، بلاغات الفحص والشاغلين المفتوحة، الفريق بهواتفه.
 */
class PlaceFileTest extends TestCase
{
    use RefreshDatabase;

    private User $fani; private Place $park;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->park = Place::where('code', 'HZ-01')->first();
        $u = User::create(['username' => 'fani', 'name' => 'الفني', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'field_worker', 'is_active' => true, 'place_id' => $this->park->id]);
        $this->fani = $u;
    }

    private function stamp(int $hoursAgo): string
    {
        $t = now()->subHours($hoursAgo);
        return $t->format('Y/m/d').' — '.$t->format('H:i');
    }

    private function seedBasement(): void
    {
        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        $round = fn (int $ago, int $ok, int $no) => ['d' => $day($ago), 's' => '09:00', 't' => '09:40', 'q' => 'فني', 'n' => 'الفني', 'f' => 'شهري', 'ok' => $ok, 'no' => $no, 'na' => 0, 'tot' => $ok + $no];
        $def = fn (string $name, string $code, string $freq) => ['items' => [['بند', 'SBC']], 'name' => $name, 'code' => $code, 'sched' => [[$freq, 'فحص', 'الفني المختص'], ['سنوي', 'قياس', 'المكتب']]];
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode([
            'defs' => ['p01' => $def('التهوية وسحب العوادم', '٠١', 'شهري'), 'p02' => $def('الإنارة', '٠٢', 'أسبوعي'), 'p03' => $def('كواشف الغاز', '٠٣', 'شهري'), 'p04' => $def('المخارج', '٠٤', 'شهري')],
            'rounds' => ['p01' => [$round(40, 5, 1), $round(10, 8, 0)], 'p03' => [$round(45, 6, 0)], 'p04' => [$round(3, 4, 2)]],
            'reports' => [
                ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'مراوح السحب لا تعمل', 'imp' => 'none', 'due' => 'فوري', 'when' => $this->stamp(30), 'sent' => $this->stamp(30), 'path' => 'إداري',
                    'levels' => [1 => ['up' => true, 'back' => false, 'by' => 'الفني']]],
                ['row' => 'p04-i-1', 'id' => 'ب — ٠٢', 'sys' => 'المخارج', 'item' => 'مخرج مسدود', 'imp' => 'alt', 'due' => '٧٢ ساعة', 'when' => $this->stamp(5), 'sent' => $this->stamp(5), 'path' => 'إداري',
                    'levels' => [1 => ['up' => false, 'back' => false, 'by' => 'الفني']]], // أُغلق
            ],
        ], JSON_UNESCAPED_UNICODE)]);
        $row = fn ($role, $name, $phone) => ['role' => $role, 'name' => $name, 'user' => '', 'dept' => '', 'phone' => $phone, 'trained' => '', 'trainer' => ''];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-01' => [
            'plans' => ['sa' => '2026-09-17', 'saBy' => 'مدير الشؤون الإدارية والهندسية', 'ra' => '2026-09-17', 'drill' => ''],
            'units' => ['_' => ['team' => [$row('المنسق', 'عيادة العنزي', '0500000001'), $row('المسعف', 'فيصل قيسي', '0500000002'), $row('المنقذ', 'ماجد', ''), $row('الإطفائي', 'سامي', '')],
                'nom' => ['by' => 'م', 'dept' => '', 'date' => '2026-09-17'], 'appr' => ['by' => 'م', 'date' => '2026-09-17'], 'hr' => []]],
        ]], JSON_UNESCAPED_UNICODE)]);
        app(TeamSync::class)->sync();
        PlaceUnit::create(['place_id' => $this->park->id, 'type' => 'parking_zone', 'name' => 'b1', 'floor' => 'b1', 'location' => 'القبو', 'capacity' => 60]);
    }

    public function test_place_file_shows_what_the_dashboard_place_file_showed(): void
    {
        $this->seedBasement();
        $this->post('/incident/normal', ['description' => 'تسرب زيت في الموقف', 'place_id' => $this->park->id])->assertRedirect();
        $i = Incident::first();

        $this->get("/app/places/{$this->park->id}/file")->assertRedirect('/login');
        $h = $this->actingAs($this->fani)->get("/app/places/{$this->park->id}/file")->assertOk()->getContent();

        // الرأس والملخص
        $this->assertStringContainsString('القبو ومواقف السيارات', $h);
        $this->assertStringContainsString('data-sum-ok="1"', $h);      // p01 سليم في موعده
        $this->assertStringContainsString('data-sum-late="2"', $h);    // p02 لم يُفحص + p03 متأخر
        $this->assertStringContainsString('data-sum-fault="1"', $h);   // p04 في آخر فحصه ✗
        // الأرقام الأربعة
        foreach (['open' => 1, 'overdue' => 1, 'cat-a' => 1, 'closed' => 1] as $k => $v) $this->assertStringContainsString('data-kpi="'.$k.'" data-v="'.$v.'"', $h);
        // الأنظمة بحالاتها الأربع
        foreach (['p01' => 'ok', 'p02' => 'none', 'p03' => 'late', 'p04' => 'fault'] as $k => $st) $this->assertStringContainsString('data-sys="'.$k.'" data-st="'.$st.'"', $h);
        $this->assertStringContainsString('التهوية وسحب العوادم', $h);
        $this->assertStringContainsString('فُحص في موعده وسليم', $h);
        $this->assertStringContainsString('لم يُفحص بعد', $h);
        // الجاهزية: البطاقة تفتح الخطة نفسها
        $this->assertStringContainsString('href="/HZ-01-basement/safety-plan.html"', $h);
        $this->assertStringContainsString('href="/HZ-01-basement/response-plan.html"', $h);
        $this->assertStringContainsString('معتمدة', $h);
        $this->assertStringContainsString('بلا تمرين', $h);
        // الوحدات والفريق بهواتفه وبلاغ الشاغل وبلاغ الفحص المفتوح
        $this->assertStringContainsString('b1', $h);
        $this->assertStringContainsString('href="tel:0500000001"', $h);
        $this->assertStringContainsString('عيادة العنزي', $h);
        $this->assertStringContainsString($i->code, $h);
        $this->assertStringContainsString('مراوح السحب لا تعمل', $h);
        $this->assertStringNotContainsString('مخرج مسدود', $h); // المغلق لا يظهر في المفتوحة
        $this->assertStringContainsString('href="/HZ-01-basement/inspection-form.html"', $h);
        // الفني لا يعدّل الوحدات
        $this->assertStringNotContainsString('تعديل الوحدات', $h);
    }

    public function test_hub_opens_the_place_file_and_empty_place_is_calm(): void
    {
        $this->actingAs($this->fani)->get('/app/places/units')->assertOk()->assertSee('href="'.url("/app/places/{$this->park->id}/file").'"', false);
        $offices = Place::where('code', 'HZ-06')->first();
        $this->actingAs($this->fani)->get("/app/places/{$offices->id}/file")->assertOk()->assertSee('لم تُفتح جولة لهذا المكان بعد');
    }
}
