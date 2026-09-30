<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Emergency\Models\ResponsePlan;
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
 * ٢٧-ب (قرار ٦٧، بكلمته «ابدأ» ٢٠٢٦-٠٩-٣٠) — الاستعداد قبل الحالة: ما ينقص يصل صاحبه بطاقةً.
 * فريق فعالية مرشَّح ينتظر اعتماد رئيس الأمن والسلامة · أماكن بلا خطة استجابة في النظام (الحالة فيها تبدأ بلا قائمة خطوات) ·
 * إدارة بلا منسق سلامة عند مديرها.
 */
class ReadinessCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $munawib;
    private User $amn;
    private User $mudir;
    private User $employee;
    private OrganizationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->unit = OrganizationUnit::whereNotNull('place_id')->whereNull('parent_id')->first() ?? OrganizationUnit::whereNotNull('place_id')->firstOrFail();
        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->amn = $this->user('amn', 'security_safety_head');
        $this->mudir = $this->user('mudir', 'department_manager', $this->unit->id);
        $this->employee = $this->user('emp', 'employee', $this->unit->id);
    }

    private function user(string $username, string $role, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function cards(User $u, string $prefix)
    {
        return app(InboxService::class)->forUser($u->fresh())->filter(fn (Task $t) => str_starts_with($t->key, $prefix))->values();
    }

    private function assertOwners(string $prefix, array $owners, array $others): void
    {
        foreach ($owners as $u) $this->assertCount(1, $this->cards($u, $prefix), "{$u->username}: بطاقة «{$prefix}» غائبة عن صاحبها");
        foreach ($others as $u) $this->assertCount(0, $this->cards($u, $prefix), "{$u->username}: بطاقة «{$prefix}» تظهر لغير صاحبها");
    }

    private function assertGone(string $prefix): void
    {
        foreach ([$this->salama, $this->munawib, $this->amn, $this->mudir, $this->employee] as $u) {
            $this->assertCount(0, $this->cards($u, $prefix), "{$u->username}: بطاقة «{$prefix}» باقية بعد الفعل");
        }
    }

    /** ٤٣: فعالية قادمة في القاعات رُشّح فريقها ← رئيس الأمن والسلامة وحده (قرار ٤٣): «اعتمده» بضغطة */
    public function test_nominated_event_team_asks_the_security_head_and_one_press_approves(): void
    {
        $events = [
            ['name' => 'ملتقى القيادات', 'date' => now()->addDays(5)->toDateString(), 'team' => [['name' => 'سعد المنسق']], 'nom' => ['by' => 'مدير التدريب', 'date' => now()->toDateString()], 'appr' => []],
            ['name' => 'ورشة منتهية', 'date' => now()->subDays(5)->toDateString(), 'team' => [['name' => 'فهد']], 'nom' => ['by' => 'مدير التدريب', 'date' => now()->subDays(9)->toDateString()], 'appr' => []],
            ['name' => 'بلا ترشيح', 'date' => now()->addDays(9)->toDateString(), 'team' => [], 'nom' => [], 'appr' => []],
        ];
        InstituteDocument::create(['key' => 'ipa-place', 'version' => 1, 'data' => json_encode(['HZ-07' => ['plans' => new \stdClass, 'units' => new \stdClass, 'events' => $events]], JSON_UNESCAPED_UNICODE)]);

        $this->assertOwners('eventteam:', [$this->amn], [$this->salama, $this->munawib, $this->mudir, $this->employee]);
        $t = $this->cards($this->amn, 'eventteam:')->first();
        $this->assertStringContainsString('ملتقى القيادات', $t->question);
        $this->assertSame('POST', $t->primaryMethod());
        $this->actingAs($this->amn)->get($t->secondary['url'])->assertOk();

        $this->actingAs($this->amn)->post($t->primary['url'])->assertRedirect();
        $doc = json_decode(InstituteDocument::where('key', 'ipa-place')->value('data'), true);
        $this->assertSame('اسم amn', $doc['HZ-07']['events'][0]['appr']['by']);
        $this->assertGone('eventteam:');
    }

    /** ٣٨: أماكن بلا خطة استجابة في النظام ← المركز: «زامنها» بضغطة؛ والحالة فيها تبدأ بلا قائمة خطوات حتى تُزامَن */
    public function test_places_without_a_response_plan_ask_the_center_to_sync(): void
    {
        $this->assertSame(0, ResponsePlan::count());
        $this->assertOwners('emplans', [$this->salama, $this->munawib], [$this->amn, $this->mudir, $this->employee]);
        $t = $this->cards($this->salama, 'emplans')->first();
        $this->assertStringContainsString('8', $t->question);
        $this->assertSame('POST', $t->primaryMethod());

        $this->actingAs($this->salama)->post($t->primary['url'])->assertRedirect();
        $this->assertSame(8, ResponsePlan::count(), 'وثائق خطط الاستجابة الثماني لم تُزامَن');
        $this->assertGone('emplans');

        // خطة واحدة غابت ← البطاقة باسم مكانها
        ResponsePlan::where('place_id', Place::idByCode('HZ-05'))->delete();
        $this->assertStringContainsString('المطاعم', $this->cards($this->munawib, 'emplans')->first()->question);
    }

    /** ٧٤: إدارة بلا منسق سلامة ← مديرها: «رشّح منسقاً»؛ حين يُرشَّح (ولو قبل الاعتماد) تختفي */
    public function test_department_without_a_coordinator_asks_its_manager(): void
    {
        $this->assertOwners('coordgap:', [$this->mudir], [$this->salama, $this->munawib, $this->amn, $this->employee]);
        $t = $this->cards($this->mudir, 'coordgap:')->first();
        $this->assertStringContainsString($this->unit->name, $t->question);
        $this->actingAs($this->mudir)->get($t->primary['url'])->assertOk();

        $this->actingAs($this->mudir)->post('/app/users', ['name' => 'منسق جديد', 'username' => 'newcoord', 'password' => 'abcd1234', 'role' => 'safety_coordinator',
            'organization_unit_id' => $this->unit->id])->assertRedirect();
        $this->assertGone('coordgap:');

        // أُعيد المرشَّح للتصحيح ← بطاقته «صحّحه» وحدها تسأل المدير؛ لا بطاقتان للسؤال نفسه (كشفته جولة webkit-27b)
        $new = User::where('username', 'newcoord')->firstOrFail();
        $this->actingAs($this->salama)->post(route('app.users.return', $new), ['note' => 'المسمى الوظيفي ناقص'])->assertRedirect();
        $this->assertCount(1, $this->cards($this->mudir, "account:{$new->id}:returned"));
        $this->assertGone('coordgap:');

        // مدير قسم تحت إدارة لها منسق: مغطّى بمنسق إدارته
        $child = OrganizationUnit::create(['name' => 'قسم تابع', 'code' => 'child-x', 'unit_type' => 'section', 'parent_id' => $this->unit->id, 'is_active' => true]);
        $qism = $this->user('qism', 'section_manager', $child->id);
        $this->assertCount(0, $this->cards($qism, 'coordgap:'));
    }
}
