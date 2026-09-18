<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ١٩-٣ (قرار ٤٨): من العمل اليومي إلى «ما ينتظرك» —
 * (أ) مهامي: الجولات المستحقة على دوري (بطاقة لكل نموذج)، (ب) أين تقف البلاغات: سكة المستويات الأربعة، (ج) بلاغاتي: قررتُ فيها ولم تُغلق.
 */
class InspectionFollowTest extends TestCase
{
    use RefreshDatabase;

    private User $fani; private User $marafiq; private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->fani = $this->user('fani', 'field_worker');
        $this->marafiq = $this->user('marafiq', 'facilities_manager');
        $this->emp = $this->user('emp', 'employee');

        $day = fn (int $ago) => now()->subDays($ago)->toDateString();
        $stamp = fn (int $hoursAgo) => now()->subHours($hoursAgo)->format('Y/m/d').' — '.now()->subHours($hoursAgo)->format('H:i');
        $round = fn (int $ago, string $f) => ['d' => $day($ago), 's' => '09:00', 't' => '09:30', 'q' => 'فني', 'n' => 'سعد', 'f' => $f, 'ok' => 3, 'no' => 0, 'na' => 0, 'tot' => 3];
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode([
            'defs' => [
                'p01' => ['name' => 'التهوية', 'code' => '٠١', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'تشغيل تجريبي للمراوح', 'الفني المختص'], ['سنوي', 'قياس معدل التصريف', 'المكتب المرخّص']]],
                'p02' => ['name' => 'الإنارة', 'code' => '٠٢', 'items' => [['بند', 'SBC']], 'sched' => [['أسبوعي', 'فحص إنارة الطوارئ', 'الفني المختص'], ['نصف سنوي', 'مراجعة العقد', 'إدارة المرافق والصيانة']]],
                'p03' => ['name' => 'الكواشف', 'code' => '٠٣', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'اختبار الكواشف', 'الفني المختص']]],
            ],
            'rounds' => ['p01' => [$round(45, 'شهري')], 'p03' => [$round(2, 'شهري')]], // p01 متأخرة ١٥ يوماً · p02 لم تُنفَّذ · p03 في موعدها (بعد ٢٨ يوماً)
            'reports' => [
                ['row' => 'p01-i-0', 'id' => 'ب — ٠١', 'sys' => 'التهوية', 'item' => 'مراوح لا تعمل', 'due' => 'فوري', 'when' => $stamp(30), 'sent' => $stamp(30), 'path' => 'إداري',
                    'levels' => [1 => ['up' => true, 'back' => false, 'by' => 'الفني']]],                                    // عند مدير المرافق (٢)، متأخر
                ['row' => 'p02-i-0', 'id' => 'ب — ٠٢', 'sys' => 'الإنارة', 'item' => 'لمبة محترقة', 'due' => '٧٢ ساعة', 'when' => $stamp(2), 'sent' => $stamp(2), 'path' => 'إداري',
                    'levels' => [1 => ['up' => true, 'back' => false], 2 => ['up' => true, 'back' => false, 'by' => 'مدير المرافق']]], // عند مدير الشؤون (٣) — قرر فيه مدير المرافق ولم يُغلق
                ['row' => 'p03-i-0', 'id' => 'ب — ٠٣', 'sys' => 'الكواشف', 'item' => 'كاشف معطل', 'due' => '٢٤ ساعة', 'when' => $stamp(3), 'sent' => '', 'path' => 'إداري', 'levels' => []], // عند الفني (١)
                ['row' => 'p03-i-1', 'id' => 'ب — ٠٤', 'sys' => 'الكواشف', 'item' => 'أُصلح', 'due' => '٢٤ ساعة', 'when' => $stamp(50), 'sent' => $stamp(50), 'path' => 'إداري',
                    'levels' => [1 => ['up' => false, 'back' => false]]],                                                     // مغلق
            ],
        ], JSON_UNESCAPED_UNICODE)]);
    }

    private function user(string $username, string $role): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    /** (أ) مهامي: بطاقة واحدة لنموذج القبو بعدد المستحق والمتأخر، للدور الذي يخصه السطر فقط. */
    public function test_due_rounds_are_one_inbox_card_per_form_for_the_owning_role(): void
    {
        $h = $this->actingAs($this->fani)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('data-task="rounds:ipa-park-form-v10"', $h);
        // الفني: p01 شهري متأخرة + p02 أسبوعي لم تُنفَّذ = ٢ مستحقتان كلتاهما متأخرة/لم تُنفَّذ؛ p03 في موعدها لا تُعد
        $this->assertStringContainsString('جولتان مستحقتان في القبو ومواقف السيارات', $h);
        $this->assertStringContainsString('كلتاهما متأخرة أو لم تُنفَّذ', $h);
        $this->assertStringContainsString('data-target="/HZ-01-basement/inspection-form.html"', $h);
        // مدير المرافق: سطره «إدارة المرافق والصيانة» نصف سنوي لم يُنفَّذ = ١
        $h2 = $this->actingAs($this->marafiq)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('جولة مستحقة في القبو ومواقف السيارات', $h2);
        // الموظف: لا شيء
        $this->actingAs($this->emp)->get('/app')->assertOk()->assertDontSee('data-task="rounds:', false);
    }

    /** (ب) أين تقف البلاغات: ٣ مفتوحة — مستوى ١:١، مستوى ٢:١ (متأخر)، مستوى ٣:١؛ و«أنت» على مستوى صاحب الدور. */
    public function test_levels_rail_counts_and_marks_me(): void
    {
        $h = $this->actingAs($this->marafiq)->get('/app')->assertOk()->getContent();
        foreach ([1 => [1, 0], 2 => [1, 1], 3 => [1, 0], 4 => [0, 0]] as $lvl => [$n, $od]) {
            $this->assertStringContainsString('data-lvl="'.$lvl.'" data-n="'.$n.'" data-od="'.$od.'"', $h);
        }
        $this->assertStringContainsString('data-lvl="2" data-n="1" data-od="1" data-me="1"', $h);
        $this->actingAs($this->fani)->get('/app')->assertOk()->assertSee('data-lvl="1" data-n="1" data-od="0" data-me="1"', false);
        $this->actingAs($this->emp)->get('/app')->assertOk()->assertDontSee('id="flowRail"', false);
    }

    /** (ج) بلاغاتي: ما قررتُ فيه ولم يُغلق — مدير المرافق يرى ب — ٠٢ (صعّده)، ولا يرى ما ينتظره هو ولا المغلق. */
    public function test_my_decided_reports_still_open(): void
    {
        $h = $this->actingAs($this->marafiq)->get('/app')->assertOk()->getContent();
        $i = strpos($h, 'id="myReports"'); $this->assertNotFalse($i);
        $sec = substr($h, $i, 2500);
        $this->assertStringContainsString('ب — ٠٢', $sec);
        $this->assertStringNotContainsString('ب — ٠٤', $sec);
        $this->assertStringContainsString('href="/HZ-01-basement/inspection-form.html#open=p02-i-0"', $sec);
        // الفني: ما أرسله ولم يُغلق (ب — ٠١ و ب — ٠٢)، لا ما لم يُرسل بعد (ب — ٠٣)
        $hf = $this->actingAs($this->fani)->get('/app')->assertOk()->getContent();
        $secF = substr($hf, strpos($hf, 'id="myReports"'), 2500);
        $this->assertStringContainsString('ب — ٠١', $secF);
        $this->assertStringContainsString('ب — ٠٢', $secF);
        $this->assertStringNotContainsString('ب — ٠٣', $secF);
    }
}
