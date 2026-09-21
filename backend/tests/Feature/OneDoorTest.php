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

    public function test_the_two_doors_became_one_with_the_chosen_name(): void
    {
        $munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $html = $this->actingAs($munawib)->get('/app')->assertOk()->getContent();

        $head = fn (string $t) => '<div class="small text-muted px-2 mb-1">'.$t.'</div>';

        $this->assertStringContainsString($head('مركز السلامة وإدارة الطوارئ'), $html, 'الباب الواحد باسمه غير موجود');
        $this->assertStringNotContainsString($head('بلاغات الشاغلين'), $html, 'الباب القديم ما زال قائماً');
        $this->assertStringNotContainsString($head('الطوارئ'), $html, 'الباب القديم ما زال قائماً');
        // عناوين النوايا في «أريد أن…» تبقى موضوعية (الطوارئ، البلاغ…) — ليست أبواباً في القائمة
    }

    /** الترتيب داخله: ما يجري الآن ← البلاغات ← الخطط ← الفريق. */
    public function test_order_inside_the_door_puts_the_live_work_first(): void
    {
        $munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $this->actingAs($munawib)->get('/app')->assertOk()->assertSeeInOrder([
            'مركز السلامة وإدارة الطوارئ',
            'الحالات الطارئة',
            'سجل مركز السلامة',
            'خطط الاستجابة',
            'الفريق الأولي',
        ], false);
    }

    /** لا يُخفى شيء (قرار ٦١): أبواب الأجهزة تبقى، وتحت عنوانها. */
    public function test_nothing_is_hidden_and_device_screens_sit_under_their_own_heading(): void
    {
        $munawib = $this->user('munawib', 'system_staff', 'HZ-00');
        $html = $this->actingAs($munawib)->get('/app')->assertOk()->getContent();

        $this->assertStringContainsString('تنتظر التركيب', $html, 'عنوان ما ينتظر الأجهزة غير موجود');
        foreach (self::DEVICE_SCREENS as $url) {
            $this->assertStringContainsString('href="'.url($url).'"', $html, "الباب {$url} اختفى — والقرار ألّا يُخفى شيء");
        }
        // وهي بعد العمل اليومي لا قبله
        $this->actingAs($munawib)->get('/app')->assertSeeInOrder(['الحالات الطارئة', 'تنتظر التركيب'], false);
    }

    /** ولا صلاحية تتغير: كلٌّ يرى ما له. */
    public function test_no_permission_changed(): void
    {
        $fani = $this->user('fani', 'tech_electrical', 'HZ-02');
        $html = $this->actingAs($fani)->get('/app')->assertOk()->getContent();

        $this->assertStringContainsString('مركز السلامة وإدارة الطوارئ', $html);
        $this->assertStringNotContainsString('href="'.url('/app/emergency/settings').'"', $html, 'الفني يرى مهل التصعيد');

        $employee = $this->user('emp', 'employee', 'HZ-06');
        $empHtml = $this->actingAs($employee)->get('/app')->assertOk()->getContent();
        $this->assertStringNotContainsString('مركز السلامة وإدارة الطوارئ', $empHtml, 'الموظف صار يرى باب المركز');
    }
}
