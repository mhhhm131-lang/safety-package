<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٨-٣ (د) (قرار ٤٧، بإذن المستخدم «مأذون»): النماذج العشرة — حقل «الموقع/المنطقة» يقترح وحدات المكان
 * من /api/place-units ويبقى نصاً حراً، وبلاغ الفحص يحمل الوحدة (unit). حارس يقرأ الملفات العشرة من المجلد الأعلى.
 */
class FormsUnitTest extends TestCase
{
    use RefreshDatabase;

    private const FORMS = ['HZ-00-safety-center/inspection-form.html', 'HZ-00-safety-center/fire-inspection.html', 'HZ-01-basement/inspection-form.html',
        'HZ-02-electrical/inspection-form.html', 'HZ-03-hvac/inspection-form.html', 'HZ-04-datacenter/inspection-form.html', 'HZ-05-restaurants/inspection-form.html',
        'HZ-06-offices/inspection-form.html', 'HZ-07-halls/inspection-form.html', 'HZ-08-storage/inspection-form.html'];

    public function test_ten_forms_offer_place_units_and_reports_carry_the_unit(): void
    {
        foreach (self::FORMS as $rel) {
            $path = base_path('../'.$rel);
            $this->assertFileExists($path);
            $h = file_get_contents($path);
            $this->assertSame(1, substr_count($h, 'id="loc" list="unitList"'), "$rel: حقل الموقع بلا قائمة الوحدات");
            $this->assertSame(1, substr_count($h, '<datalist id="unitList">'), "$rel: لا datalist");
            $this->assertSame(1, substr_count($h, 'id="mobLoc" list="unitList"'), "$rel: حقل الجوال بلا قائمة");
            $this->assertSame(1, substr_count($h, "fetch('/api/place-units?place='+MYHZ"), "$rel: لا تحميل للوحدات");
            $this->assertSame(1, substr_count($h, "unit:(\$('loc').value||'').trim()"), "$rel: بلاغ الفحص لا يحمل الوحدة");
        }
    }

    /** بلاغ فحص يحمل unit ← بطاقة «ما ينتظرك» تعرض الوحدة بعد اسم المكان. */
    public function test_inspection_report_card_shows_the_unit(): void
    {
        $this->seed(PlacesSeeder::class);
        $u = User::create(['username' => 'marafiq', 'name' => 'مدير المرافق', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'facilities_manager', 'is_active' => true]);
        $stamp = now()->subHours(30)->format('Y/m/d').' — '.now()->subHours(30)->format('H:i');
        \App\Modules\Store\Models\InstituteDocument::create(['key' => 'ipa-halls-form-v10', 'version' => 1, 'data' => json_encode(['reports' => [
            ['row' => 'h03', 'id' => 'ب — ٠١', 'sys' => 'الإنارة', 'item' => 'إضاءة طوارئ معطلة', 'unit' => 'قاعة تدريب ٣١٢ · الدور ٣', 'due' => '٢٤ ساعة', 'when' => $stamp, 'sent' => $stamp, 'path' => 'إداري',
             'levels' => [1 => ['up' => true, 'back' => false, 'by' => 'الفني']]],
        ]], JSON_UNESCAPED_UNICODE)]);
        $this->actingAs($u)->get('/app')->assertOk()->assertSee('بلاغ فحص ب — ٠١ في القاعات التدريبية · قاعة تدريب ٣١٢ · الدور ٣: إضاءة طوارئ معطلة');
    }

    /** الواجهة: وحدات المكان بجلسة الدخول؛ بلا دخول تحويل؛ مكان مجهول قائمة فارغة. */
    public function test_place_units_api(): void
    {
        $this->seed(PlacesSeeder::class);
        $halls = Place::where('code', 'HZ-07')->first();
        PlaceUnit::create(['place_id' => $halls->id, 'type' => 'training_hall', 'name' => '٣١٢', 'floor' => '٣', 'capacity' => 25]);
        PlaceUnit::create(['place_id' => $halls->id, 'type' => 'event_hall', 'name' => 'القاعة الكبرى', 'is_active' => false]);
        $this->getJson('/api/place-units?place=HZ-07')->assertStatus(401);
        $u = User::create(['username' => 'fani', 'name' => 'فني', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'field_worker', 'is_active' => true]);
        $this->actingAs($u)->getJson('/api/place-units?place=HZ-07')->assertOk()->assertJsonCount(1)->assertJsonFragment(['label' => 'قاعة تدريب ٣١٢ · الدور ٣']);
        $this->actingAs($u)->getJson('/api/place-units?place=HZ-99')->assertOk()->assertJsonCount(0);
    }
}
