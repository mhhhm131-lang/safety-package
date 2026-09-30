<?php

namespace Tests\Feature\Risk;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Risk\Models\Risk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٦٩ (بكلمته ٢٠٢٦-٠٩-٣٠: «مدير الإدارة هو من يعتمد مخاطر إدارته ومنسق السلامة في الإدارة يرفعها له… ومسؤول السلامة فقط العام»):
 * خطر الإدارة يرفعه منسقها ويعتمده مديرها (أو مدير ما فوقها)؛ السجل العام والخطر بنطاق المعهد يعتمدهما مسؤول السلامة وحده؛
 * المناوب والمدير العام واللجنة يرون ولا يعتمدون؛ ومدير الإدارة إن كتب خطراً رفعه هو.
 */
class RiskApprovalByManagerTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private User $salama;
    private User $munawib;
    private User $gm;
    private User $lajna;
    private User $coord;
    private User $mudir;
    private User $other;
    private OrganizationUnit $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hr = $this->orgUnit('hr');
        $this->salama = $this->makeUser('system_admin');
        $this->munawib = $this->makeUser('system_staff');
        $this->gm = $this->makeUser('top_management');
        $this->lajna = $this->makeUser('safety_committee');
        $this->coord = $this->makeUser('safety_coordinator', 'hr');
        $this->mudir = $this->makeUser('department_manager', 'hr');
        $this->other = $this->makeUser('department_manager', 'it');
    }

    private function card(User $u, string $key): ?Task
    {
        return app(InboxService::class)->forUser($u->fresh())->first(fn (Task $t) => $t->key === $key);
    }

    private function draft(User $by, array $extra = []): Risk
    {
        return $this->makeRisk($extra + ['title' => 'انزلاق عند مدخل الموارد البشرية', 'created_by_id' => $by->id, 'organization_unit_id' => $this->hr->id, 'status' => 'draft']);
    }

    public function test_coordinator_submits_and_the_departments_manager_approves(): void
    {
        $r = $this->draft($this->coord);
        $this->actingAs($this->coord)->post(route('risk.submit', $r))->assertRedirect();
        $this->assertSame('pending_approval', $r->fresh()->status);

        // البطاقة والطابور والتنبيه: لمدير إدارة الخطر وحده
        $key = "risk:{$r->id}:approve";
        $this->assertNotNull($this->card($this->mudir, $key), 'مدير الإدارة لم تصله بطاقة الاعتماد');
        foreach ([$this->salama, $this->munawib, $this->gm, $this->lajna, $this->other, $this->coord] as $u) {
            $this->assertNull($this->card($u, $key), "{$u->username}: بطاقة اعتماد خطر إدارة أخرى");
        }
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->mudir->id, 'type' => 'risk.approve']);
        $this->assertSame(0, AppNotification::where('type', 'risk.approve')->whereIn('user_id', [$this->salama->id, $this->munawib->id, $this->gm->id, $this->other->id])->count());
        $this->actingAs($this->mudir)->get(route('risk.approval.queue'))->assertOk()->assertSee($r->title);
        $this->actingAs($this->other)->get(route('risk.approval.queue'))->assertOk()->assertDontSee($r->title);

        // لا يعتمده غير مديره
        foreach ([$this->other, $this->salama] as $u) $this->actingAs($u)->post(route('risk.approve', $r))->assertForbidden();
        foreach ([$this->munawib, $this->gm, $this->lajna, $this->coord] as $u) $this->actingAs($u)->post(route('risk.approve', $r))->assertForbidden();
        $this->assertSame('pending_approval', $r->fresh()->status);

        $this->actingAs($this->mudir)->post($this->card($this->mudir, $key)->primary['url'])->assertRedirect();
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame($this->mudir->id, $r->fresh()->approved_by_id);
        $this->assertNull($this->card($this->mudir, $key));
    }

    public function test_manager_approves_risks_of_units_under_him_and_not_above_him(): void
    {
        $section = OrganizationUnit::create(['name' => 'قسم التوظيف', 'code' => 'hr-rec', 'unit_type' => 'section', 'parent_id' => $this->hr->id, 'is_active' => true]);
        $qism = $this->makeUser('section_manager', 'hr-rec');
        $sectionRisk = $this->makeRisk(['created_by_id' => $this->coord->id, 'organization_unit_id' => $section->id, 'status' => 'pending_approval']);
        $deptRisk = $this->makeRisk(['created_by_id' => $this->coord->id, 'organization_unit_id' => $this->hr->id, 'status' => 'pending_approval']);

        $this->actingAs($qism)->post(route('risk.approve', $deptRisk))->assertForbidden(); // مدير القسم لا يعتمد خطر الإدارة فوقه
        $this->actingAs($qism)->post(route('risk.approve', $sectionRisk))->assertRedirect();
        $this->assertSame('approved', $sectionRisk->fresh()->status);
        $this->actingAs($this->mudir)->post(route('risk.approve', $deptRisk))->assertRedirect();
        $this->assertSame('approved', $deptRisk->fresh()->status);

        // مدير الإدارة يعتمد خطر قسم تحته
        $again = $this->makeRisk(['created_by_id' => $this->coord->id, 'organization_unit_id' => $section->id, 'status' => 'pending_approval']);
        $this->actingAs($this->mudir)->post(route('risk.approve', $again))->assertRedirect();
        $this->assertSame('approved', $again->fresh()->status);
    }

    public function test_general_register_and_institute_wide_risks_are_approved_by_the_safety_officer_only(): void
    {
        $ref = $this->makeRisk(['risk_type' => 'reference', 'created_by_id' => $this->coord->id, 'status' => 'pending_approval']);
        $wide = $this->makeRisk(['risk_type' => 'active', 'created_by_id' => $this->coord->id, 'organization_unit_id' => null, 'status' => 'pending_approval']);
        foreach ([$ref, $wide] as $r) {
            foreach ([$this->mudir, $this->munawib, $this->gm, $this->lajna] as $u) $this->actingAs($u)->post(route('risk.approve', $r))->assertForbidden();
            $this->assertNotNull($this->card($this->salama, "risk:{$r->id}:approve"));
            $this->assertNull($this->card($this->mudir, "risk:{$r->id}:approve"));
            $this->actingAs($this->salama)->post(route('risk.approve', $r))->assertRedirect();
            $this->assertSame('approved', $r->fresh()->status);
        }
    }

    /** مدير الإدارة إن كتب خطراً: يرفعه هو (كان المسار يرفضه ٤٠٣)، ثم يعتمده؛ والمرفوض يعيده إلى المسودة */
    public function test_manager_submits_his_own_draft_and_returns_his_rejected_risk_to_draft(): void
    {
        $r = $this->draft($this->mudir);
        $t = $this->card($this->mudir, "risk:{$r->id}:draft");
        $this->assertNotNull($t, 'مسودة المدير بلا بطاقة');
        $this->actingAs($this->mudir)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('pending_approval', $r->fresh()->status);
        $this->actingAs($this->other)->post(route('risk.submit', $this->draft($this->mudir)))->assertForbidden(); // لا يرفع مسودة غيره

        $this->actingAs($this->mudir)->post(route('risk.reject', $r), ['note' => 'يحتاج تفصيلاً'])->assertRedirect();
        $this->assertSame('rejected', $r->fresh()->status);
        $t = $this->card($this->mudir, "risk:{$r->id}:rejected");
        $this->assertNotNull($t);
        $this->actingAs($this->mudir)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('draft', $r->fresh()->status);
        // لا يغيّر حالة أخرى من هذا الباب
        $this->actingAs($this->mudir)->post(route('risk.changeStatus', ['risk' => $r, 'status' => 'approved']))->assertForbidden();
    }

    /** رفع خطر إدارة إلى السجل العام: قرار مسؤول السلامة وحده */
    public function test_promoting_a_department_risk_to_the_general_register_is_for_the_safety_officer_only(): void
    {
        $r = $this->makeRisk(['created_by_id' => $this->coord->id, 'organization_unit_id' => $this->hr->id, 'status' => 'approved', 'code' => 'X-1']);
        $this->actingAs($this->mudir)->post(route('risk.toReference', $r))->assertForbidden();
        $this->actingAs($this->mudir)->get(route('risk.show', $r))->assertOk()->assertDontSee('أضفه إلى السجل العام');
        $this->actingAs($this->salama)->get(route('risk.show', $r))->assertOk()->assertSee('أضفه إلى السجل العام');
    }
}
