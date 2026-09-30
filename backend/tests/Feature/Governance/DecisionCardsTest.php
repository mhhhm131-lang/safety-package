<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormField;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Services\FormService;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyEvaluation;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Risk\Models\Risk;
use App\Modules\Worker\Models\Worker;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ٢٧-ب (قرار ٦٧، بكلمته «ابدأ» ٢٠٢٦-٠٩-٣٠) — القرارات الباقية خارج التصاريح: لكل واحدة الحالة ← البطاقة لصاحبها وحده ← الفعل ← تختفي.
 * خطر مسودة أو مرفوض عند صاحبه · حساب أُعيد إلى من سجّله · نموذج فات موعده ولم يُذكَّر أصحابه · عامل في التعريف أو التدريب ·
 * طرف خارجي قيد التسجيل · تقييم الطرف بعد اكتمال مشروعه.
 */
class DecisionCardsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $munawib;
    private User $coord;
    private User $mudir;
    private User $employee;
    private OrganizationUnit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->unit = OrganizationUnit::whereNotNull('place_id')->firstOrFail();
        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->coord = $this->user('coord', 'safety_coordinator', $this->unit->id);
        $this->mudir = $this->user('mudir', 'department_manager', $this->unit->id);
        $this->employee = $this->user('emp', 'employee', $this->unit->id);
    }

    private function user(string $username, string $role, ?int $unitId = null, bool $active = true): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => $active, 'organization_unit_id' => $unitId]);
        return $u;
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
        foreach ([$this->salama, $this->munawib, $this->coord, $this->mudir, $this->employee] as $u) {
            $this->assertNull($this->card($u, $key), "{$u->username}: بطاقة «{$key}» باقية بعد الفعل");
        }
    }

    /** ٤٧ و٤٦: الخطر المسودة عند من كتبه ← «قدّمه» بضغطة؛ والمرفوض ← «أعده للتعديل» فيصير مسودة */
    public function test_draft_and_rejected_risk_ask_their_author(): void
    {
        $r = Risk::create(['title' => 'انزلاق عند مدخل المطعم', 'description' => 'وصف', 'risk_type' => 'active', 'status' => 'draft', 'severity' => 3, 'likelihood' => 3,
            'created_by_id' => $this->coord->id, 'organization_unit_id' => $this->unit->id]);
        $key = "risk:{$r->id}:draft";
        $this->assertOwners($key, [$this->coord], [$this->salama, $this->munawib, $this->mudir, $this->employee]);
        $t = $this->card($this->coord, $key);
        $this->assertStringContainsString('انزلاق عند مدخل المطعم', $t->question);
        $this->assertSame('POST', $t->primaryMethod());
        $this->actingAs($this->coord)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('pending_approval', $r->fresh()->status);
        $this->assertGone($key);

        // يرفضه مدير الإدارة (قرار ٦٩: خطر الإدارة يعتمده مديرها) ← يعود إلى كاتبه بسبب الرفض
        $this->actingAs($this->mudir)->post(route('risk.reject', $r), ['note' => 'الدرجة أقل من الواقع'])->assertRedirect();
        $rej = "risk:{$r->id}:rejected";
        $this->assertOwners($rej, [$this->coord], [$this->salama, $this->mudir, $this->employee]);
        $t = $this->card($this->coord, $rej);
        $this->assertStringContainsString('الدرجة أقل من الواقع', $t->question);
        $this->actingAs($this->coord)->post($t->primary['url'])->assertRedirect();
        $this->assertSame('draft', $r->fresh()->status);
        $this->assertGone($rej);
        $this->assertNotNull($this->card($this->coord, $key), 'بعد إعادته للمسودة تعود بطاقة «قدّمه»');
    }

    /** ٧٣: حساب أعاده مسؤول السلامة ← يصل من سجّله بسبب الإعادة؛ وتصحيحه يعيده للاعتماد */
    public function test_returned_account_asks_whoever_registered_it_and_correcting_it_resubmits(): void
    {
        $this->actingAs($this->mudir)->post('/app/users', ['name' => 'منسق جديد', 'username' => 'newcoord', 'password' => 'abcd1234', 'role' => 'safety_coordinator',
            'organization_unit_id' => $this->unit->id])->assertRedirect();
        $new = User::where('username', 'newcoord')->firstOrFail();
        $this->assertTrue($new->profile->isPending());
        $this->actingAs($this->salama)->post(route('app.users.return', $new), ['note' => 'المسمى الوظيفي ناقص'])->assertRedirect();

        $key = "account:{$new->id}:returned";
        $this->assertOwners($key, [$this->mudir], [$this->salama, $this->munawib, $this->coord, $this->employee]);
        $t = $this->card($this->mudir, $key);
        $this->assertStringContainsString('المسمى الوظيفي ناقص', $t->question);
        $this->actingAs($this->mudir)->get($t->primary['url'])->assertOk();

        // يصحّح حقلاً لا يمنح صلاحية ← يعود للاعتماد (كان يبقى معاداً بلا مخرج)
        $this->actingAs($this->mudir)->put(route('app.users.update', $new), ['name' => 'منسق جديد', 'username' => 'newcoord', 'role' => 'safety_coordinator',
            'organization_unit_id' => $this->unit->id, 'job_title' => 'منسق سلامة الإدارة'])->assertRedirect();
        $this->assertTrue($new->profile->fresh()->isPending(), 'التصحيح لم يُعد الحساب للاعتماد');
        $this->assertGone($key);
        $this->assertNotNull($this->card($this->salama, 'account:'.$new->id), 'مسؤول السلامة لم تصله بطاقة الاعتماد بعد التصحيح');
    }

    /** ٤٩: نموذج أرسلتُه وفات موعده ولم يُذكَّر أصحابه ← «ذكّرهم» مرة */
    public function test_overdue_form_asks_its_sender_to_remind_once(): void
    {
        $form = FormTemplate::create(['title' => 'إقرار قراءة خطة الإخلاء', 'is_active' => true, 'created_by_id' => $this->salama->id]);
        FormField::create(['form_id' => $form->id, 'label' => 'قرأتُ الخطة', 'field_type' => 'checkbox', 'is_required' => true, 'order' => 1]);
        $svc = app(FormService::class);
        $svc->assignToUsers($form, [$this->employee->id], now()->addDays(3)->toDateString(), $this->salama->id);
        $key = "formremind:{$form->id}";
        $this->assertGone($key); // لم يفت الموعد بعد

        FormAssignment::where('form_id', $form->id)->update(['due_date' => now()->subDays(2)->toDateString()]);
        $this->assertOwners($key, [$this->salama], [$this->munawib, $this->coord, $this->mudir, $this->employee]);
        $t = $this->card($this->salama, $key);
        $this->assertStringContainsString('إقرار قراءة خطة الإخلاء', $t->question);
        $this->assertSame('POST', $t->primaryMethod());
        $this->actingAs($this->salama)->post($t->primary['url'])->assertRedirect();
        $this->assertNotNull(FormAssignment::where('form_id', $form->id)->value('reminded_at'));
        $this->assertGone($key);
    }

    /** ٦١: عامل في التعريف أو التدريب ← من يعتمد العمال */
    public function test_worker_in_induction_or_training_asks_those_who_approve_workers(): void
    {
        $w = Worker::factory()->create(['status' => 'induction', 'full_name' => 'خالد العامل']);
        $key = "worker:{$w->id}:induction";
        $this->assertOwners($key, [$this->salama, $this->munawib, $this->coord], [$this->mudir, $this->employee]);
        $t = $this->card($this->coord, $key);
        $this->assertStringContainsString('خالد العامل', $t->question);
        $this->actingAs($this->coord)->get($t->primary['url'])->assertOk();

        $w->update(['status' => 'training']);
        $this->assertGone($key);
        $this->assertNotNull($this->card($this->coord, "worker:{$w->id}:training"));
        $w->update(['status' => 'approved']);
        $this->assertGone("worker:{$w->id}:training");
    }

    /** ٦٩ و٧٠: طرف قيد التسجيل ← من يعدّل الأطراف؛ وتقييمه بعد اكتمال مشروعه ← من يقيّم */
    public function test_pending_party_and_evaluation_after_a_finished_project(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول التكييف', 'party_type' => 'contractor', 'status' => 'pending']);
        $key = "party:{$party->id}:pending";
        $this->assertOwners($key, [$this->salama, $this->munawib], [$this->coord, $this->mudir, $this->employee]);
        $this->actingAs($this->salama)->get($this->card($this->salama, $key)->primary['url'])->assertOk();
        $party->update(['status' => 'active']);
        $this->assertGone($key);

        $project = Project::create(['name' => 'تجديد التكييف', 'status' => 'active', 'place_id' => Place::idByCode('HZ-06')]);
        ProjectContractor::create(['project_id' => $project->id, 'external_party_id' => $party->id, 'role' => 'main', 'qualification_status' => ProjectContractor::STATUS_POST_APPROVED]);
        $eval = "party:{$party->id}:eval:{$project->id}";
        $this->assertGone($eval); // المشروع لم يكتمل
        $project->update(['status' => 'completed']);
        $this->assertOwners($eval, [$this->salama, $this->munawib, $this->coord], [$this->mudir, $this->employee]);
        $t = $this->card($this->coord, $eval);
        $this->assertStringContainsString('تجديد التكييف', $t->question);
        $page = $this->actingAs($this->coord)->get($t->primary['url'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('~<option value="'.$project->id.'" selected~', $page, 'المشروع غير محدد في نموذج التقييم');

        ExternalPartyEvaluation::create(['external_party_id' => $party->id, 'project_id' => $project->id, 'period_from' => now()->subMonth(), 'period_to' => now(),
            'safety_score' => 80, 'compliance_score' => 80, 'quality_score' => 80, 'overall_score' => 80, 'evaluated_by_id' => $this->coord->id, 'created_at' => now()]);
        $this->assertGone($eval);
    }
}
