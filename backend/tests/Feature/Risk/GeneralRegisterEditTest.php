<?php

namespace Tests\Feature\Risk;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٧٤ (بكلمته ٢٠٢٦-١٠-٠٥: «العام لا يعدل إلا من مسؤول السلامة… ولا يتغير العام حتى لو عُدّل الخطر في الخاص»،
 * ثم «نعم تعديل العام فقط لمسؤول السلامة»، وعن الإضافة «تبقى اقتراحاً»):
 * الخطر في السجل العام يعدّله مسؤول السلامة وحده؛ وغيره ممن يملك الإنشاء يقترح خطراً جديداً ويصحّح مقترحه هو ما دام مسودة.
 * كُشف بالتجربة: خطر عام معتمد غيّره المنسق والمناوب والمكتب الاستشاري من باب العام، والمديرون والمنسق والمناوب من باب الخاص —
 * وبقي «معتمد». كل فحص هنا يقرأ ما في القاعدة بعد الطلب.
 */
class GeneralRegisterEditTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskCategory $cat;
    private RiskSubCategory $sub;
    private User $salama;
    private User $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'is_active' => true, 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
        $this->salama = $this->makeUser('system_admin');
        $this->handler = $this->makeUser('tech_electrical');
    }

    private function general(string $title = 'صعق من مقبس تالف', string $status = 'approved', ?User $by = null): Risk
    {
        $r = Risk::create(['risk_type' => 'reference', 'title' => $title, 'description' => 'وصف العام', 'category_id' => $this->cat->id,
            'sub_category_id' => $this->sub->id, 'severity' => 3, 'likelihood' => 3, 'scope_type' => 'general', 'status' => $status, 'created_by_id' => $by?->id]);
        app(RiskService::class)->ensurePhases($r);
        return $r;
    }

    private function special(string $unit): Risk
    {
        $r = Risk::create(['risk_type' => 'active', 'title' => 'خطر خاص', 'description' => 'x', 'category_id' => $this->cat->id,
            'sub_category_id' => $this->sub->id, 'severity' => 2, 'likelihood' => 2, 'scope_type' => 'org_unit',
            'organization_unit_id' => $this->orgUnit($unit)->id, 'status' => 'active']);
        app(RiskService::class)->ensurePhases($r);
        return $r;
    }

    /** ما يرسله نموذج تعديل العام */
    private function generalBody(string $title = 'غُيّر'): array
    {
        return ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id, 'severity' => 5, 'likelihood' => 5, 'title' => $title];
    }

    /** ما يرسله نموذج تعديل الخاص */
    private function specialBody(User $by, string $title = 'غُيّر'): array
    {
        return $this->generalBody($title) + ['scope_type' => 'org_unit', 'organization_unit_id' => $this->orgUnit('hr')->id,
            'assigned_coordinator_id' => $by->id, 'assigned_field_team_id' => $this->handler->id];
    }

    private function assertUntouched(Risk $g, string $why): void
    {
        $f = $g->fresh();
        $this->assertNotNull($f, $why.' — حُذف');
        $this->assertSame(['صعق من مقبس تالف', 3, 3, 'approved', null, 'reference'],
            [$f->title, $f->severity, $f->likelihood, $f->status, $f->organization_unit_id, $f->risk_type], $why);
    }

    public function test_no_role_but_the_safety_officer_changes_an_approved_general_risk_from_either_door(): void
    {
        foreach (array_keys(PermissionRegistry::ROLES) as $role) {
            if ($role === 'system_admin') continue;
            $u = $this->makeUser($role, 'hr');
            $g = $this->general();

            $this->assertNotSame(200, $this->actingAs($u)->get(route('risk.reference.edit', $g))->getStatusCode(), "{$role} فتح نموذج تعديل العام");
            $this->actingAs($u)->post(route('risk.reference.update', $g), $this->generalBody());
            $this->assertUntouched($g, "{$role} غيّر خطراً عاماً معتمداً من باب العام");

            $this->assertNotSame(200, $this->actingAs($u)->get(route('risk.active.edit', $g))->getStatusCode(), "{$role} فتح الخطر العام من باب الخاص");
            $this->actingAs($u)->post(route('risk.active.update', $g), $this->specialBody($u));
            $this->assertUntouched($g, "{$role} غيّر خطراً عاماً معتمداً من باب الخاص");
        }
    }

    public function test_the_safety_officer_edits_the_general_risk_and_it_stays_approved(): void
    {
        $g = $this->general();
        $this->actingAs($this->salama)->get(route('risk.reference.edit', $g))->assertOk();
        $this->actingAs($this->salama)->post(route('risk.reference.update', $g), $this->generalBody('صعق من مقبس أو تمديد تالف'))->assertRedirect(route('risk.reference.index'));
        $f = $g->fresh();
        $this->assertSame(['صعق من مقبس أو تمديد تالف', 5, 'approved'], [$f->title, $f->severity, $f->status]);

        // وباب الخاص ليس بابه: مسؤول السلامة نفسه يعدّل العام من بابه
        $this->actingAs($this->salama)->get(route('risk.active.edit', $g))->assertNotFound();
        $this->actingAs($this->salama)->post(route('risk.active.update', $g), $this->specialBody($this->salama, 'من باب الخاص'));
        $this->assertSame(['صعق من مقبس أو تمديد تالف', null], [$g->fresh()->title, $g->fresh()->organization_unit_id]);
    }

    public function test_a_proposer_corrects_his_own_draft_until_he_submits_it(): void
    {
        $coord = $this->makeUser('safety_coordinator', 'hr');
        $this->actingAs($coord)->post(route('risk.reference.store'), $this->generalBody('انزلاق عند مدخل القاعة'))->assertRedirect(route('risk.reference.index'));
        $p = Risk::where('title', 'انزلاق عند مدخل القاعة')->firstOrFail();
        $this->assertSame(['reference', 'draft', $coord->id], [$p->risk_type, $p->status, (int) $p->created_by_id]);

        // مقترحه مسودة: يفتحه ويصحّحه
        $this->actingAs($coord)->get(route('risk.reference.edit', $p))->assertOk();
        $this->actingAs($coord)->post(route('risk.reference.update', $p), $this->generalBody('انزلاق عند مدخل القاعة الكبرى'));
        $this->assertSame('انزلاق عند مدخل القاعة الكبرى', $p->fresh()->title);

        // مقترح غيره لا يلمسه: منسق آخر، والمناوب، والمكتب الاستشاري
        foreach ([$this->makeUser('safety_coordinator', 'it'), $this->makeUser('system_staff'), $this->makeUser('consultant_office')] as $other) {
            $this->actingAs($other)->get(route('risk.reference.edit', $p))->assertForbidden();
            $this->actingAs($other)->post(route('risk.reference.update', $p), $this->generalBody('غيّره غير صاحبه'))->assertForbidden();
            $this->assertSame('انزلاق عند مدخل القاعة الكبرى', $p->fresh()->title);
        }
        // مسؤول السلامة يعدّل أي مقترح
        $this->actingAs($this->salama)->get(route('risk.reference.edit', $p))->assertOk();

        // رفعه: ينتظر مسؤول السلامة، ولا يصحّحه صاحبه
        $this->actingAs($coord)->post(route('risk.submit', $p))->assertRedirect();
        $this->assertSame('pending_approval', $p->fresh()->status);
        $this->actingAs($coord)->post(route('risk.reference.update', $p), $this->generalBody('بعد الرفع'))->assertForbidden();

        // اعتُمد: صار من العام، لا يلمسه إلا مسؤول السلامة
        $this->actingAs($this->salama)->post(route('risk.approve', $p))->assertRedirect();
        $this->assertSame('approved', $p->fresh()->status);
        $this->actingAs($coord)->get(route('risk.reference.edit', $p))->assertForbidden();
        $this->actingAs($coord)->post(route('risk.reference.update', $p), $this->generalBody('بعد الاعتماد'))->assertForbidden();
        $this->assertSame(['انزلاق عند مدخل القاعة الكبرى', 'approved'], [$p->fresh()->title, $p->fresh()->status]);
    }

    public function test_a_rejected_proposal_returns_to_its_author_alone(): void
    {
        $coord = $this->makeUser('safety_coordinator', 'hr');
        $p = $this->general('مقترح يُرفض', 'pending_approval', $coord);
        $this->actingAs($this->salama)->post(route('risk.reject', $p), ['note' => 'الوصف ناقص'])->assertRedirect();
        $this->assertSame('rejected', $p->fresh()->status);

        // مرفوض: لا يصحّحه قبل إعادته، ولا يعيده غير صاحبه
        $this->actingAs($coord)->post(route('risk.reference.update', $p), $this->generalBody('قبل الإعادة'))->assertForbidden();
        $this->actingAs($this->makeUser('safety_coordinator', 'it'))->post(route('risk.changeStatus', ['risk' => $p, 'status' => 'draft']))->assertForbidden();
        $this->assertSame('rejected', $p->fresh()->status);

        // بطاقته «أعده للتعديل» ثم «عدّله» ثم «قدّمه»
        $this->actingAs($coord)->post(route('risk.changeStatus', ['risk' => $p, 'status' => 'draft']))->assertRedirect();
        $this->assertSame('draft', $p->fresh()->status);
        $this->actingAs($coord)->post(route('risk.reference.update', $p), $this->generalBody('مقترح صُحّح'));
        $this->assertSame('مقترح صُحّح', $p->fresh()->title);
        $this->actingAs($coord)->post(route('risk.submit', $p));
        $this->assertSame('pending_approval', $p->fresh()->status);
    }

    public function test_the_status_of_a_general_risk_moves_only_by_the_safety_officer(): void
    {
        $g = $this->general();
        foreach (['safety_coordinator', 'system_staff', 'consultant_office', 'department_manager'] as $role) {
            $u = $this->makeUser($role, 'hr');
            foreach (['active', 'in_progress', 'closed', 'draft'] as $to) {
                $this->actingAs($u)->post(route('risk.changeStatus', ['risk' => $g, 'status' => $to]))->assertForbidden();
            }
            $this->assertUntouched($g, "{$role} غيّر حالة خطر عام");
        }
        $this->actingAs($this->salama)->post(route('risk.changeStatus', ['risk' => $g, 'status' => 'active']))->assertRedirect();
        $this->assertSame('active', $g->fresh()->status);
    }

    public function test_the_general_door_opens_general_risks_only(): void
    {
        $s = $this->special('it');
        foreach ([$this->makeUser('safety_coordinator', 'hr'), $this->makeUser('system_staff'), $this->salama] as $u) {
            $this->actingAs($u)->get(route('risk.reference.edit', $s))->assertNotFound();
            $this->actingAs($u)->post(route('risk.reference.update', $s), $this->generalBody())->assertNotFound();
            $this->assertSame(['خطر خاص', 2], [$s->fresh()->title, $s->fresh()->severity], 'خطر إدارة غُيّر من باب السجل العام');
        }
        // والخاص من بابه كما كان
        $this->actingAs($this->salama)->get(route('risk.active.edit', $s))->assertOk();
    }

    public function test_a_draft_proposal_is_deleted_by_its_author_or_the_safety_officer(): void
    {
        $coord = $this->makeUser('safety_coordinator', 'hr');
        $p = $this->general('مقترح للحذف', 'draft', $coord);
        $this->actingAs($this->makeUser('safety_coordinator', 'it'))->post(route('risk.destroy', $p))->assertForbidden();
        $this->actingAs($this->makeUser('system_staff'))->post(route('risk.destroy', $p))->assertForbidden();
        $this->assertNotNull($p->fresh(), 'مقترح حذفه غير صاحبه');

        $this->actingAs($coord)->post(route('risk.destroy', $p));
        $this->assertNull($p->fresh());

        $p2 = $this->general('مقترح ثانٍ', 'draft', $coord);
        $this->actingAs($this->salama)->post(route('risk.destroy', $p2));
        $this->assertNull($p2->fresh());
    }

    public function test_the_edit_button_shows_only_to_who_can_edit(): void
    {
        $coord = $this->makeUser('safety_coordinator', 'hr');
        $g = $this->general();
        $detail = fn (User $u) => $this->actingAs($u)->getJson(url('app/risk/registry/tree/reference/risk/'.$g->id))->assertOk()->json('can_edit');
        $link = fn (User $u) => str_contains((string) $this->actingAs($u)->get(route('risk.reference.index'))->assertOk()->getContent(), route('risk.reference.edit', $g));

        // الشجرة: ما يبني به زر «تعديل»؛ والجدول: الرابط نفسه
        $this->assertTrue($detail($this->salama));
        $this->assertTrue($link($this->salama));
        foreach ([$coord, $this->makeUser('system_staff'), $this->makeUser('department_manager', 'hr')] as $u) {
            $this->assertFalse($detail($u), $u->profile->role);
            $this->assertFalse($link($u), $u->profile->role);
        }

        // قرار ٧٥: المقترح لا يظهر في العام — صاحبه يصحّحه من بطاقته «عدّله» في «ما ينتظرك»
        $p = $this->general('مقترح المنسق', 'draft', $coord);
        $card = app(InboxService::class)->forUser($coord->fresh())->first(fn (Task $t) => $t->key === "risk:{$p->id}:draft");
        $this->assertNotNull($card, 'لا بطاقة لمسودة المقترِح');
        $this->assertSame(route('risk.reference.edit', $p), $card->secondary['url']);
        $this->actingAs($coord)->get($card->secondary['url'])->assertOk();
    }
}
