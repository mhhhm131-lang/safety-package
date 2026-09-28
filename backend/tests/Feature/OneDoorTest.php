<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٩ (د) — قرارا ٥٨ و٦١: باب واحد باسم «مركز السلامة وإدارة الطوارئ»، وترتيب بلا إخفاء.
 *
 * الحال قبلها: بابان في القائمة («بلاغات الشاغلين» و«الطوارئ») والغرفة واحدة والمناوب واحد
 * والهاتف واحد، فيُجبَر المناوب أن يخمّن أيّهما يفتح.
 *
 * القاعدة: **لا يُخفى شيء** (قرار ٦١) — كل باب يبقى، وما ينتظر أجهزة يُجمع في آخر القائمة
 * تحت عنوان «تنتظر التركيب»، ظاهراً لا مخفيّاً. ولا صلاحية تتغير.
 */
class OneDoorTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_SCREENS = [
        '/app/emergency/iot',            // أنظمة المبنى
        '/app/emergency/iot/wearables',  // الأساور
        '/app/emergency/iot/cameras',    // الكاميرات
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    /** ٢٦-٧ (قرار ٦٦): الباب الواحد صار صفحة واحدة يفتحها مربع «مركز السلامة»؛ والقائمة بلا مجموعة طوارئ */
    public function test_the_door_is_one_page_opened_by_the_center_tile(): void
    {
        $munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $html = $this->actingAs($munawib)->get('/app')->assertOk()->getContent();
        $head = fn (string $t) => '<div class="small text-muted px-2 mb-1">'.$t.'</div>';
        $this->assertStringNotContainsString($head('مركز السلامة وإدارة الطوارئ'), $html, 'مجموعة الطوارئ ما زالت في المزيد');
        $this->assertStringNotContainsString('تنتظر التركيب', $html);
        $this->assertStringContainsString('href="'.route('emergency.dashboard').'" data-place="HZ-00"', $html, 'مربع المركز لا يفتح صفحة المركز');
        $this->assertStringContainsString('مهل التصعيد', $html); // إعداد يبقى في المزيد
    }

    /** الترتيب داخل الصفحة: الآن ← الاستعداد ← السجلات والأجهزة ← المركز كمكان. */
    public function test_order_inside_the_page_puts_the_live_work_first(): void
    {
        $munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $this->actingAs($munawib)->get('/app/emergency')->assertOk()->assertSeeInOrder(['id="cNow"', 'id="cReady"', 'id="cRecords"', 'تنتظر التركيب', 'id="cPlace"'], false);
    }

    /** لا يُخفى شيء (قرار ٦١): كل شاشات الطوارئ لها رابط في صفحة المركز، ومنها الأجهزة تحت عنوانها. */
    public function test_nothing_is_hidden_every_emergency_screen_has_a_door_in_the_page(): void
    {
        $munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $html = $this->actingAs($munawib)->get('/app/emergency')->assertOk()->getContent();
        foreach (array_merge(self::DEVICE_SCREENS, ['/app/emergency/incidents', '/app/incidents', '/app/emergency/plans', '/app/emergency/teams', '/app/emergency/panic', '/app/emergency/aar', '/app/emergency/drills', '/app/emergency/equipment', '/app/emergency/contacts', '/app/emergency/visitors', '/app/emergency/analytics']) as $url) {
            $this->assertStringContainsString('href="'.url($url), $html, "الباب {$url} بلا رابط في صفحة المركز");
        }
        // الملفات الطبية للطبيب وحده (٢٢-٦ب): الباب ظاهر باهتاً باسم صاحبه، بلا رابط
        $this->assertStringContainsString('data-door="الملفات الطبية"', $html);
        $this->assertSame(9, substr_count($html, '<tr data-place="HZ-'), 'الاستعداد لكل مكان من التسعة');
    }

    /** ولا صلاحية تتغير: ما ليس للشخص يظهر باهتاً باسم صاحبه، والموظف بلا مربع مركز. */
    public function test_no_permission_changed_and_what_is_not_yours_is_dimmed(): void
    {
        $fani = $this->user('fani', 'tech_electrical', 'HZ-02');
        $html = $this->actingAs($fani)->get('/app/emergency')->assertOk()->getContent();
        $this->assertStringContainsString('<span class="btn btn-sm btn-o dim" data-door="الملفات الطبية"', $html, 'الملفات الطبية ليست للفني فتظهر باهتة بلا رابط');
        $this->assertStringNotContainsString('href="'.url('/app/emergency/medical').'"', $html);
        $this->assertStringContainsString('لطبيب العيادة', $html);
        $this->assertStringNotContainsString('href="'.url('/app/emergency/settings').'"', $html, 'الفني يرى مهل التصعيد');

        $employee = $this->user('emp', 'employee', 'HZ-06');
        $empHtml = $this->actingAs($employee)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-place="HZ-00"', $empHtml, 'الموظف يرى مربع المركز خارج نطاقه');
        $this->actingAs($employee)->get('/app/emergency')->assertForbidden();
    }
}
