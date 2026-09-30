<?php

namespace Tests\Feature\Permit;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitDeviation;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Project\Models\ExternalParty;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitConflictRulesSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ٢٧-ب (قرار ٦٧، بكلمته «ابدأ» ٢٠٢٦-٠٩-٣٠) — التصاريح: كل حالة تنتظر فعلاً في دورة التصريح تصل صاحبها بطاقةً وتختفي حين يُفعل.
 * معتمد بشرط · معتمد ينتظر التفعيل · بنود ناقصة عند مقدّم الطلب · انحراف مفتوح · مكتمل بلا تقييم · مسودة لم تُقدَّم.
 * «المرفوض» ليس حالة انتظار: الرفض نهاية في آلة الحالة.
 */
class PermitDecisionCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $munawib;
    private User $coord;
    private User $mushrif;
    private User $other;
    private User $employee;
    private ExternalParty $party;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, PermitTypesSeeder::class, PermitConflictRulesSeeder::class]);
        $this->party = ExternalParty::create(['name' => 'مقاول التكييف', 'party_type' => 'contractor', 'status' => 'active']);
        $elsewhere = ExternalParty::create(['name' => 'مقاول آخر', 'party_type' => 'contractor', 'status' => 'active']);
        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->coord = $this->user('coord', 'safety_coordinator');
        $this->mushrif = $this->user('mushrif', 'contractor_supervisor', $this->party->id);
        $this->other = $this->user('other', 'contractor_supervisor', $elsewhere->id);
        $this->employee = $this->user('emp', 'employee');
    }

    private function user(string $username, string $role, ?int $partyId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test", 'external_party_id' => $partyId]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    private function permit(string $status, array $extra = []): Permit
    {
        $typeId = PermitType::where('code', 'work_permit')->value('id');
        $id = DB::table('permits')->insertGetId($extra + [
            'permit_type_id' => $typeId, 'permit_category' => PermitType::find($typeId)->category,
            'code' => 'ت-اختبار-'.random_int(1000, 9999), 'title' => 'صيانة وحدة التكييف', 'place_id' => Place::idByCode('HZ-06'),
            'external_party_id' => $this->party->id, 'requested_by_id' => $this->mushrif->id,
            'status' => $status, 'starts_at' => now()->addHour(), 'expires_at' => now()->addHours(8), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Permit::findOrFail($id);
    }

    private function card(User $u, string $key): ?Task
    {
        return app(InboxService::class)->forUser($u->fresh())->first(fn (Task $t) => $t->key === $key);
    }

    private function assertOwners(string $key, array $owners, array $others): void
    {
        foreach ($owners as $u) $this->assertNotNull($this->card($u, $key), "{$u->username}: بطاقة «{$key}» غائبة عن صاحبها");
        foreach ($others as $u) $this->assertNull($this->card($u, $key), "{$u->username}: بطاقة «{$key}» تظهر لغير صاحبها");
    }

    private function assertGone(string $key): void
    {
        foreach ([$this->salama, $this->munawib, $this->coord, $this->mushrif, $this->other, $this->employee] as $u) {
            $this->assertNull($this->card($u, $key), "{$u->username}: بطاقة «{$key}» باقية بعد الفعل");
        }
    }

    public function test_approved_permit_asks_those_who_activate_and_tells_what_is_left(): void
    {
        $p = $this->permit(Permit::STATUS_APPROVED);
        $key = "permit:{$p->id}:activate";
        $this->assertOwners($key, [$this->salama, $this->munawib, $this->coord], [$this->mushrif, $this->other, $this->employee]);
        $this->assertStringNotContainsString('باقية', $this->card($this->coord, $key)->question);
        PermitRequirement::create(['permit_id' => $p->id, 'category' => 'document', 'requirement_code' => 'doc-1', 'severity' => 'mandatory', 'status' => 'required']);
        $t = $this->card($this->coord, $key);
        $this->assertStringContainsString('بنود إلزامية باقية: 1', $t->question);
        $this->assertSame(route('permits.activate', $p), $t->primary['url']);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk();

        $p->update(['status' => Permit::STATUS_ACTIVE]);
        $this->assertGone($key);
    }

    public function test_conditional_permit_asks_the_final_approvers(): void
    {
        $p = $this->permit(Permit::STATUS_CONDITIONAL);
        $key = "permit:{$p->id}:final";
        $this->assertOwners($key, [$this->salama, $this->munawib], [$this->coord, $this->mushrif, $this->employee]);
        $this->assertStringContainsString('معتمد بشرط', $this->card($this->salama, $key)->question);
        $this->actingAs($this->salama)->get($this->card($this->salama, $key)->primary['url'])->assertOk();

        $p->update(['status' => Permit::STATUS_APPROVED]);
        $this->assertGone($key);
    }

    /** المقاول يرفع أدلة تصريحه: بنوده الناقصة تصله هو وحسابات طرفه، لا مقاولاً آخر ولا من يفعّل (بطاقته «فعّله» تذكر العدد) */
    public function test_open_requirements_ask_the_requesters_party_until_fulfilled(): void
    {
        $p = $this->permit(Permit::STATUS_APPROVED);
        $key = "permit:{$p->id}:reqs";
        $this->assertGone($key); // لا بنود ناقصة ← لا بطاقة
        $req = PermitRequirement::create(['permit_id' => $p->id, 'category' => 'document', 'requirement_code' => 'doc-1', 'severity' => 'mandatory', 'status' => 'required']);
        $this->assertOwners($key, [$this->mushrif], [$this->other, $this->salama, $this->coord, $this->employee]);
        $t = $this->card($this->mushrif, $key);
        $this->assertStringContainsString('بنود إلزامية باقية قبل التفعيل: 1', $t->question);
        $this->actingAs($this->mushrif)->get($t->primary['url'])->assertOk();

        $this->actingAs($this->mushrif)->post(route('permits.requirements.complete', [$p, $req]), ['evidence_value' => 'مرفق'])->assertRedirect();
        $this->assertGone($key);
    }

    public function test_open_deviation_asks_those_who_supervise_until_resolved(): void
    {
        $p = $this->permit(Permit::STATUS_ACTIVE);
        $key = "permit:{$p->id}:deviations";
        $this->assertGone($key);
        $d = PermitDeviation::create(['permit_id' => $p->id, 'description' => 'عمل بلا حزام أمان على السلم', 'severity' => 'high', 'status' => PermitDeviation::STATUS_OPEN, 'recorded_by_id' => $this->mushrif->id, 'recorded_at' => now()]);
        $this->assertOwners($key, [$this->salama, $this->munawib, $this->coord], [$this->mushrif, $this->other, $this->employee]);
        $t = $this->card($this->coord, $key);
        $this->assertStringContainsString('انحرافات مفتوحة: 1', $t->question);
        $this->assertTrue($t->isOverdue, 'انحراف عالٍ يُعلَّم متأخراً');

        $this->actingAs($this->coord)->post(route('permits.deviations.resolve', [$p, $d]), ['corrective_action_taken' => 'أُوقف العمل ورُكّب الحزام', 'resolution_status' => 'resolved'])->assertRedirect();
        $this->assertGone($key);
    }

    public function test_completed_permit_asks_the_reviewers_to_evaluate(): void
    {
        $p = $this->permit(Permit::STATUS_COMPLETED);
        $key = "permit:{$p->id}:evaluate";
        $this->assertOwners($key, [$this->salama, $this->munawib, $this->coord], [$this->mushrif, $this->other, $this->employee]);
        $t = $this->card($this->salama, $key);
        $this->actingAs($this->salama)->get($t->primary['url'])->assertOk();

        $this->actingAs($this->salama)->post(route('permits.evaluate.save', $p), ['overall_rating' => 4, 'severity_match' => 'accurate'])->assertRedirect();
        $this->assertGone($key);
    }

    public function test_draft_asks_its_requester_and_one_press_submits_it(): void
    {
        $p = $this->permit(Permit::STATUS_DRAFT);
        $key = "permit:{$p->id}:draft";
        $this->assertOwners($key, [$this->mushrif], [$this->other, $this->salama, $this->coord, $this->employee]);
        $t = $this->card($this->mushrif, $key);
        $this->assertSame('POST', $t->primaryMethod());
        $this->actingAs($this->mushrif)->get($t->secondary['url'])->assertOk();

        $this->actingAs($this->mushrif)->post($t->primary['url'])->assertRedirect();
        $this->assertSame(Permit::STATUS_SUBMITTED, $p->fresh()->status);
        $this->assertGone($key);
        $this->assertNotNull($this->card($this->coord, "permit:{$p->id}:review"), 'بعد التقديم يصل المراجع');
    }

    /**
     * حساب المقاول يفتح على بوابته لا على الرئيسية (HomeController) ← بطاقاته تُعرض في بوابته بأزرارها،
     * وإلا بقيت عدداً في الشريط بلا قائمة (كشفته جولة webkit-27b: الاختبارات أعلاه تسأل المصدر لا الشاشة).
     */
    public function test_contractor_sees_his_cards_on_his_portal_screen(): void
    {
        $draft = $this->permit(Permit::STATUS_DRAFT);
        $this->actingAs($this->mushrif)->get('/app')->assertRedirect('/app/contractor');
        $this->actingAs($this->mushrif)->get('/app/contractor')->assertOk()
            ->assertSee('id="inboxList"', false)
            ->assertSee('data-task="permit:'.$draft->id.':draft"', false)
            ->assertSee('action="'.route('permits.transition', ['permit' => $draft, 'to_status' => Permit::STATUS_SUBMITTED]).'"', false);

        // مقاول آخر بلا شيء ينتظره: لا قسم فارغ
        $this->actingAs($this->other)->get('/app/contractor')->assertOk()->assertDontSee('id="inboxList"', false);
    }

    /** الرفض نهاية: لا بطاقة تَعِد بإعادة تقديم لا مسار لها */
    public function test_rejected_permit_is_not_a_waiting_state(): void
    {
        $p = $this->permit(Permit::STATUS_REJECTED);
        foreach ([$this->salama, $this->coord, $this->mushrif] as $u) {
            $this->assertNull(app(InboxService::class)->forUser($u)->first(fn (Task $t) => str_starts_with($t->key, "permit:{$p->id}:")), $u->username);
        }
    }
}
