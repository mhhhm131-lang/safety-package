<?php

namespace Tests\Feature\Risk;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Project\Models\ExternalParty;
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
 * قرار ٧٥ (بكلمته ٢٠٢٦-١٠-٠٥، بعد تجربته بحساب المناوب: «لم أجده في العام، وجدته في مخاطر في انتظار اعتمادك»، ثم
 * «نعم، ويُشعَر المقترِح بأنه لا يظهر في العام إلا بعد اعتماد مسؤول السلامة. عندما ينقر المقترِح حفظ يظهر له رسالة»):
 * المقترح لا يظهر في السجل العام ولا يُعمل به حتى يعتمده مسؤول السلامة؛ وبعد اعتماده يظهر دائماً؛ والمقترِح يُقال له ذلك عند الحفظ.
 * كُشف بالتجربة: المقترح بفئة فرعية كان يظهر في الشجرة من أول لحظة بلا علامة، وبلا فئة فرعية لا يظهر أبداً ولو اعتُمد،
 * والتفعيل المفرد كان يفعّله قبل اعتماده. كل فحص هنا يقرأ ما تعيده الشاشة برقم الخطر، أو ما في القاعدة.
 */
class ProposalHiddenUntilApprovedTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private const NOTE = 'أُرسل مقترحك إلى مسؤول السلامة. يظهر في السجل العام بعد اعتماده.';

    private RiskCategory $cat;
    private RiskSubCategory $sub;
    private User $salama;
    private User $duty;
    private User $mudir;
    private User $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'is_active' => true, 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
        $r = Risk::create(['risk_type' => 'reference', 'title' => 'خطر معتمد قائم', 'description' => 'x', 'category_id' => $this->cat->id,
            'sub_category_id' => $this->sub->id, 'severity' => 3, 'likelihood' => 3, 'status' => 'approved']);
        app(RiskService::class)->ensurePhases($r);
        $this->salama = $this->makeUser('system_admin');
        $this->duty = $this->makeUser('system_staff');
        $this->mudir = $this->makeUser('department_manager', 'hr');
        $this->handler = $this->makeUser('tech_electrical');
    }

    /** المقترِح يضغط «حفظ» في نموذج الإضافة */
    private function propose(User $by, string $title, ?int $subId, ?int $catId = null): Risk
    {
        $this->actingAs($by)->post(route('risk.reference.store'), ['category_id' => $catId ?? $this->cat->id, 'sub_category_id' => $subId,
            'severity' => 2, 'likelihood' => 2, 'title' => $title])->assertRedirect(route('risk.reference.index'));
        return Risk::where('title', $title)->firstOrFail();
    }

    private function treeJson(User $u, string $path): array
    {
        return $this->actingAs($u)->getJson(url('app/risk/registry/tree/reference/'.$path))->assertOk()->json();
    }

    /** هل يجد صاحب الحساب الخطر في شجرة السجل العام: فئته ← فرعياتها كلها ← أخطارها */
    private function inTree(User $u, Risk $r): bool
    {
        if (!in_array($r->category_id, array_column($this->treeJson($u, 'categories'), 'id'), true)) return false;
        foreach ($this->treeJson($u, 'sub-categories/'.$r->category_id) as $s) {
            if (in_array($r->id, array_column($this->treeJson($u, 'risks-by-sub-category/'.$s['id']), 'id'), true)) return true;
        }
        return false;
    }

    private function inTable(User $u, Risk $r): bool
    {
        return str_contains((string) $this->actingAs($u)->get(route('risk.reference.index'))->assertOk()->getContent(), route('risk.show', $r));
    }

    private function cards(User $u, Risk $r): array
    {
        return app(InboxService::class)->forUser($u->fresh())->filter(fn (Task $t) => str_starts_with($t->key, "risk:{$r->id}:"))->map(fn (Task $t) => $t->key)->values()->all();
    }

    public function test_a_proposal_is_not_in_the_general_register_until_approved_then_it_is(): void
    {
        // قرار ٧٦: «حفظ» أرسله إلى مسؤول السلامة
        $p = $this->propose($this->duty, 'انزلاق عند مدخل القاعة', $this->sub->id);
        $this->assertSame('pending_approval', $p->status);

        // ينتظر الاعتماد: لا أحد يراه في العام — لا صاحبه ولا مسؤول السلامة ولا مدير
        $hidden = function (string $stage) use ($p) {
            $this->assertSame($stage, $p->fresh()->status);
            foreach ([$this->duty, $this->salama, $this->mudir] as $u) {
                $this->assertFalse($this->inTree($u, $p), "مقترح ({$stage}) ظهر في شجرة العام لـ{$u->profile->role}");
                $this->assertFalse($this->inTable($u, $p), "مقترح ({$stage}) ظهر في جدول العام لـ{$u->profile->role}");
                $this->actingAs($u)->getJson(url('app/risk/registry/tree/reference/risk/'.$p->id))->assertNotFound();
            }
        };
        $hidden('pending_approval');
        // مكانه: «ما ينتظرك» عند مسؤول السلامة وطابور اعتماده — ولا بطاقة عند صاحبه (لا ضغطة ثانية عليه)
        $this->assertSame(["risk:{$p->id}:approve"], $this->cards($this->salama, $p));
        $this->assertSame([], $this->cards($this->duty, $p));
        $this->actingAs($this->salama)->get(route('risk.approval.queue'))->assertOk()->assertSee('انزلاق عند مدخل القاعة');

        // مرفوض، ثم معاد للتعديل: يبقى خارج العام
        $this->actingAs($this->salama)->post(route('risk.reject', $p), ['note' => 'ناقص'])->assertRedirect();
        $hidden('rejected');
        $this->actingAs($this->duty)->post(route('risk.changeStatus', ['risk' => $p, 'status' => 'draft']));
        $hidden('draft');

        // صحّحه صاحبه فأُرسل، فاعتُمد: يظهر للجميع
        $this->actingAs($this->duty)->post(route('risk.reference.update', $p), ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 2, 'likelihood' => 2, 'title' => 'انزلاق عند مدخل القاعة']);
        $this->assertSame('pending_approval', $p->fresh()->status);
        $this->actingAs($this->salama)->post(route('risk.approve', $p))->assertRedirect();
        $this->assertSame('approved', $p->fresh()->status);
        foreach ([$this->duty, $this->salama, $this->mudir] as $u) {
            $this->assertTrue($this->inTree($u, $p), "خطر معتمد لم يظهر في شجرة العام لـ{$u->profile->role}");
            $this->assertTrue($this->inTable($u, $p), "خطر معتمد لم يظهر في جدول العام لـ{$u->profile->role}");
        }
    }

    public function test_an_approved_risk_without_a_subcategory_shows_in_the_tree(): void
    {
        $p = $this->propose($this->duty, 'خطر بلا فئة فرعية', null);
        $this->assertNull($p->sub_category_id);
        $names = fn () => array_column($this->treeJson($this->salama, 'sub-categories/'.$this->cat->id), 'name');
        $this->assertNotContains('بلا فئة فرعية', $names(), 'ظهرت خانة «بلا فئة فرعية» لمقترح لم يُعتمد');
        $this->assertFalse($this->inTree($this->salama, $p));

        $this->actingAs($this->salama)->post(route('risk.approve', $p))->assertRedirect();
        $this->assertContains('بلا فئة فرعية', $names());
        foreach ([$this->salama, $this->duty, $this->mudir] as $u) {
            $this->assertTrue($this->inTree($u, $p), "خطر معتمد بلا فئة فرعية لم يظهر في الشجرة لـ{$u->profile->role}");
        }
    }

    public function test_a_category_holding_proposals_only_is_not_listed(): void
    {
        $empty = RiskCategory::create(['name' => 'فئة بلا معتمد', 'abbreviation' => 'NW', 'is_active' => true, 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $empty->id, 'name' => 'فرعية جديدة', 'abbreviation' => 'NS']);
        $p = $this->propose($this->duty, 'مقترح في فئة جديدة', $sub->id, $empty->id);
        $listed = fn () => in_array($empty->id, array_column($this->treeJson($this->mudir, 'categories'), 'id'), true);
        $this->assertFalse($listed(), 'فئة ليس فيها إلا مقترح ظهرت في العام');
        $this->assertSame([], $this->treeJson($this->mudir, 'sub-categories/'.$empty->id));

        $this->actingAs($this->salama)->post(route('risk.approve', $p));
        $this->assertTrue($listed());
    }

    /** قرار ٧٦: «حفظ» ضغطة واحدة — يرسل ويقول للمقترِح أين مقترحه؛ لا «قدّمه» ولا «ما ينتظرك» */
    public function test_the_proposer_is_told_on_save_that_it_was_sent_to_the_safety_officer(): void
    {
        $body = fn (string $title) => ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id, 'severity' => 2, 'likelihood' => 2, 'title' => $title];

        // المقترِح: الرسالة عند «حفظ»، وتُعرض في الصفحة التي يعود إليها
        $this->actingAs($this->duty)->post(route('risk.reference.store'), $body('مقترح برسالة'))->assertRedirect(route('risk.reference.index'));
        $this->assertSame(self::NOTE, (string) session('success'));
        $this->assertStringNotContainsString('قدّمه', (string) session('success'));
        $this->actingAs($this->duty)->followingRedirects()->post(route('risk.reference.store'), $body('مقترح برسالة ٢'))->assertOk()->assertSee(self::NOTE);

        // أُعيد له فصحّحه: الحفظ يعيد إرساله ويقول ذلك
        $p = Risk::where('title', 'مقترح برسالة')->firstOrFail();
        $this->actingAs($this->salama)->post(route('risk.requestModification', $p), ['notes' => 'أوضح'])->assertRedirect();
        $this->actingAs($this->duty)->post(route('risk.reference.update', $p), $body('مقترح برسالة صُحّح'))->assertRedirect(route('risk.reference.index'));
        $this->assertSame(self::NOTE, (string) session('success'));
        $this->assertSame('pending_approval', $p->fresh()->status);

        // مسؤول السلامة يضيف فيُعتمد ويظهر فوراً: يُقال له ذلك
        $this->actingAs($this->salama)->post(route('risk.reference.store'), $body('خطر يكتبه مسؤول السلامة'))->assertRedirect(route('risk.reference.index'));
        $this->assertSame('أُضيف الخطر إلى السجل العام.', (string) session('success'));
        $mine = Risk::where('title', 'خطر يكتبه مسؤول السلامة')->firstOrFail();
        $this->assertSame('approved', $mine->status);
        $this->assertTrue($this->inTree($this->mudir, $mine), 'خطر أضافه مسؤول السلامة لم يظهر في العام');

        // وتعديله خطراً معتمداً: بلا ملاحظة المقترح
        $g = Risk::where('title', 'خطر معتمد قائم')->firstOrFail();
        $this->actingAs($this->salama)->post(route('risk.reference.update', $g), ['category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 3, 'likelihood' => 3, 'title' => 'خطر معتمد قائم']);
        $this->assertStringNotContainsString('اعتماد', (string) session('success'));
    }

    public function test_a_proposal_is_not_activated_before_approval(): void
    {
        $coord = $this->makeUser('safety_coordinator', 'hr');
        $body = ['scope_type' => 'org_unit', 'organization_unit_id' => $this->orgUnit('hr')->id, 'severity' => 2, 'likelihood' => 2,
            'assigned_coordinator_id' => $coord->id, 'assigned_field_team_id' => $this->handler->id];
        $copies = fn (Risk $r) => Risk::where('risk_type', 'active')->where('parent_reference_id', $r->id)->count();

        $p = $this->propose($this->duty, 'مقترح لا يُفعَّل', $this->sub->id);
        foreach (['draft', 'pending_approval', 'rejected'] as $stage) {
            $p->update(['status' => $stage]);
            foreach ([$this->duty, $this->salama, $this->mudir] as $u) {
                $this->actingAs($u)->get(route('risk.activate.form', $p))->assertNotFound();
                $this->actingAs($u)->post(route('risk.activate', $p), $body)->assertNotFound();
            }
            $this->assertSame(0, $copies($p), "مقترح ({$stage}) فُعّل قبل اعتماده");
        }
        // خطر خاص لا يُفعَّل ثانية (نسخة من نسخة)
        $g = Risk::where('title', 'خطر معتمد قائم')->firstOrFail();
        $this->actingAs($this->mudir)->post(route('risk.activate', $g), $body)->assertRedirect(route('risk.active.index'));
        $copy = Risk::where('risk_type', 'active')->where('parent_reference_id', $g->id)->firstOrFail();
        $this->actingAs($this->mudir)->post(route('risk.activate', $copy), $body)->assertNotFound();
        $this->assertSame(0, $copies($copy));

        // اعتُمد المقترح: يُفعَّل
        $p->update(['status' => 'pending_approval']);
        $this->actingAs($this->salama)->post(route('risk.approve', $p));
        $this->actingAs($this->mudir)->get(route('risk.activate.form', $p))->assertOk();
        $this->actingAs($this->mudir)->post(route('risk.activate', $p), $body)->assertRedirect(route('risk.active.index'));
        $this->assertSame(1, $copies($p));
    }

    public function test_search_and_the_party_list_do_not_present_a_proposal_as_general(): void
    {
        $p = $this->propose($this->duty, 'تسرب غاز المطبخ التجريبي', $this->sub->id);
        $found = fn (User $u) => (string) $this->actingAs($u)->get(route('app.search', ['q' => 'تسرب غاز المطبخ']))->assertOk()->getContent();

        // غير صاحبه وغير مسؤول السلامة لا يجده؛ وصاحبه ومسؤول السلامة يجدانه باسمه «مقترح»
        $this->assertStringNotContainsString(route('risk.show', $p), $found($this->mudir));
        foreach ([$this->duty, $this->salama] as $u) {
            $html = $found($u);
            $this->assertStringContainsString(route('risk.show', $p), $html);
            $this->assertStringContainsString('مقترح للسجل العام', $html);
        }
        // قائمة ربط خطر بطرف خارجي: المعتمد وحده
        $party = ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor']);
        $options = fn () => (string) $this->actingAs($this->salama)->get(route('external-parties.risks', $party))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="'.$p->id.'"', $options());

        $this->actingAs($this->salama)->post(route('risk.approve', $p));
        $html = $found($this->mudir);
        $this->assertStringContainsString(route('risk.show', $p), $html);
        $this->assertStringNotContainsString('مقترح للسجل العام', $html);
        $this->assertStringContainsString('value="'.$p->id.'"', $options());
    }
}
