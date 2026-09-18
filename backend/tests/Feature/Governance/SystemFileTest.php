<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٩-٢ (قرار ٤٨): ملف النظام في الخلفية — ما كان يعرضه `renderSystem` في dashboard.html:715-740:
 * سجل الجولات، الجدول الدوري، البنود بعلاماتها، القراءات بمرجعيتها والمقاسة، وبلاغات النظام بمدة العطل.
 * ومعها تصحيح «آخر جولة» في شاشة «نماذج الفحص».
 */
class SystemFileTest extends TestCase
{
    use RefreshDatabase;

    private User $fani; private Place $park;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->park = Place::where('code', 'HZ-01')->first();
        $u = User::create(['username' => 'fani', 'name' => 'الفني', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'field_worker', 'is_active' => true, 'place_id' => $this->park->id]);
        $this->fani = $u;
        $stamp = fn (int $daysAgo) => now()->subDays($daysAgo)->format('Y/m/d').' — 10:00';
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode([
            'defs' => ['p01' => ['name' => 'التهوية وسحب العوادم', 'code' => '٠١',
                'items' => [['مراوح السحب تعمل بالاتجاه الصحيح', 'SBC ٥٠١'], ['كواشف أول أكسيد الكربون تعمل', 'SBC ٥٠١'], ['فتحات السحب غير مسدودة', 'SBC ٥٠١']],
                'reads' => ['تركيز أول أكسيد الكربون'],
                'sched' => [['شهري', 'تشغيل تجريبي لمراوح السحب', 'الفني المختص'], ['سنوي', 'قياس معدل التصريف', 'المكتب المرخّص']]]],
            'marks' => ['p01-i-0' => 'no', 'p01-i-1' => 'ok', 'p01-r-0' => 'ok'],
            'vals' => ['p01-r-0|ref' => '٢٥ ppm', 'p01-r-0|act' => '١٨ ppm'],
            'rounds' => ['p01' => [
                ['d' => now()->subDays(40)->toDateString(), 's' => '09:00', 't' => '09:30', 'q' => 'فني مختص', 'n' => 'سعد', 'f' => 'شهري', 'ok' => 3, 'no' => 0, 'na' => 0, 'tot' => 4],
                ['d' => now()->subDays(5)->toDateString(), 's' => '11:00', 't' => '11:40', 'q' => 'فني مختص', 'n' => 'سعد', 'f' => 'شهري', 'ok' => 2, 'no' => 1, 'na' => 1, 'tot' => 4]]],
            'reports' => [
                ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'مراوح السحب لا تعمل', 'imp' => 'none', 'due' => 'فوري', 'when' => $stamp(5), 'sent' => $stamp(5), 'path' => 'إداري', 'oos' => true,
                    'levels' => [1 => ['up' => true, 'back' => false, 'by' => 'الفني']]],
                ['row' => 'p01-i-2', 'id' => 'ب — ٠٢', 'sys' => 'التهوية', 'item' => 'فتحة مسدودة', 'imp' => 'alt', 'due' => '٧٢ ساعة', 'when' => $stamp(30), 'sent' => $stamp(30), 'path' => 'إداري',
                    'levels' => [1 => ['up' => false, 'back' => false, 'by' => 'الفني', 'date' => now()->subDays(27)->format('Y/m/d').' — 10:00']]],
                ['row' => 'p02-i-0', 'id' => 'ب — ٠٣', 'sys' => 'نظام آخر', 'item' => 'لا يخص هذا النظام', 'due' => '٧٢ ساعة', 'when' => $stamp(1), 'sent' => $stamp(1), 'levels' => []],
            ],
        ], JSON_UNESCAPED_UNICODE)]);
    }

    public function test_system_file_shows_rounds_schedule_items_reads_and_reports(): void
    {
        $url = "/app/places/{$this->park->id}/systems/ipa-park-form-v10/p01";
        $this->get($url)->assertRedirect('/login');
        $h = $this->actingAs($this->fani)->get($url)->assertOk()->getContent();

        $this->assertStringContainsString('التهوية وسحب العوادم', $h);
        $this->assertStringContainsString('data-st="fault"', $h);                 // آخر جولة فيها ✗
        $this->assertSame(2, substr_count($h, 'data-round='));                     // جولتان، الأحدث أولاً
        $this->assertTrue(strpos($h, now()->subDays(5)->toDateString()) < strpos($h, now()->subDays(40)->toDateString()));
        $this->assertStringContainsString('تشغيل تجريبي لمراوح السحب', $h);        // الجدول الدوري
        $this->assertSame(3, substr_count($h, 'data-item='));                      // البنود الثلاثة
        $this->assertStringContainsString('data-item="0" data-mark="no"', $h);
        $this->assertStringContainsString('data-item="1" data-mark="ok"', $h);
        $this->assertStringContainsString('data-item="2" data-mark=""', $h);       // بلا علامة
        $this->assertStringContainsString('٢٥ ppm', $h);                           // القراءة: المرجعية والمقاسة
        $this->assertStringContainsString('١٨ ppm', $h);
        // بلاغات النظام: المفتوح أولاً بمدة العطل، والمغلق بعده، وبلاغ نظام آخر لا يظهر
        $this->assertSame(2, substr_count($h, 'data-report='));
        $this->assertTrue(strpos($h, 'مراوح السحب لا تعمل') < strpos($h, 'فتحة مسدودة'));
        $this->assertStringContainsString('مضى على العطل', $h);
        $this->assertStringContainsString('بقي العطل', $h);
        $this->assertStringContainsString('خرج عن الخدمة', $h);
        $this->assertStringNotContainsString('لا يخص هذا النظام', $h);
        $this->assertStringContainsString('href="/HZ-01-basement/inspection-form.html#sys=p01"', $h);
        // نظام مجهول ونموذج مكان آخر
        $this->actingAs($this->fani)->get("/app/places/{$this->park->id}/systems/ipa-park-form-v10/zzz")->assertNotFound();
        $this->actingAs($this->fani)->get("/app/places/{$this->park->id}/systems/ipa-elec-form-v10/p01")->assertNotFound();
    }

    public function test_place_file_rows_open_the_system_file_and_inspections_screen_shows_last_round(): void
    {
        $this->actingAs($this->fani)->get("/app/places/{$this->park->id}/file")->assertOk()
            ->assertSee('href="'.url("/app/places/{$this->park->id}/systems/ipa-park-form-v10/p01").'"', false);
        // «نماذج الفحص»: آخر جولة كانت فارغة دائماً (يقرأ rounds قائمةً وهي مفاتيح أنظمة)
        $this->actingAs($this->fani)->get('/app/inspections')->assertOk()->assertSee(now()->subDays(5)->toDateString());
    }
}
