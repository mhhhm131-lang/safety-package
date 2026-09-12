<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Services\PermitService;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PermitConflictRulesSeeder;
use Database\Seeders\PermitTypesSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\QualificationChecklistSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Database\Seeders\TradeSeeder;
use Database\Seeders\TrainingTopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ١١-٢ (قرار ٣٤): «ما ينتظرك الآن» — الصفحة الأولى بعد الدخول. البوابة:
 *   بعد الدخول ٠ قفزات (المهام في /app نفسها)؛ مهمة تظهر لصاحبها وحده ثم تختفي بعد الفعل — لكل محوّل من الأربعة.
 */
class InboxTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $salama; private User $fani; private User $coord; private User $idara; private User $emp; private User $mudir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-06');
        $this->coord = $this->user('coord', 'safety_coordinator', 'HZ-06');
        $this->idara = $this->user('idara', 'top_management');
        $this->emp = $this->user('emp', 'employee');
        $this->mudir = $this->user('mudir', 'department_manager', null, OrganizationUnit::first()->id);
    }

    private function user(string $username, string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode), 'organization_unit_id' => $unitId]);
        return $u;
    }

    private function pending(User $u): int
    {
        return $this->actingAs($u)->getJson('/app/inbox/count')->assertOk()->json('count');
    }

    public function test_home_is_the_inbox_with_empty_state_and_hint(): void
    {
        $r = $this->actingAs($this->fani)->get('/app')->assertOk();
        $r->assertSee('لا شيء ينتظرك الآن')->assertSee('كل ما يحتاجك يظهر هنا')->assertDontSee('مرحباً اسم fani');
        $this->assertSame(0, $this->pending($this->fani));
        auth()->logout();
        $this->get('/app/inbox/count')->assertRedirect('/login');
    }

    /** بلاغ شاغل: يظهر للفني المعيَّن (افتحه) ← بعد الفتح (عولج؟) ← بعد «عولج» يختفي عنده ويظهر للمركز (أغلق؟) ← بعد الإغلاق يختفي. */
    public function test_incident_tasks_move_from_technician_to_center_and_vanish(): void
    {
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        $i = Incident::first();
        $this->assertSame('forwarded', $i->status);

        // الفني: مهمة «افتحه» — الموظف والمنسق لا شيء
        $this->actingAs($this->fani)->get('/app')->assertOk()->assertSee('عندك')->assertSee($i->code)->assertSee('افتحه');
        $this->assertSame(1, $this->pending($this->fani));
        $this->assertSame(0, $this->pending($this->emp));
        $this->assertSame(0, $this->pending($this->coord));
        // المركز: البلاغ بلا خطر ← مهمة «صنّف»
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('لم يُصنَّف بعد')->assertSee('صنّف');

        // الفني يفتح (= استلم) فتصير مهمته «عولج»
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk();
        $this->actingAs($this->fani)->get('/app')->assertOk()->assertSee('عولج؟')->assertDontSee('افتحه');
        // يعالج بصورة ← تختفي عنده وتظهر للمركز «أغلق»
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => 'بُدّل البلاط المكسور ونُظّف الممر وأُعيد فتحه للمارة',
            'evidence' => UploadedFile::fake()->createWithContent('after.png', base64_decode(self::PNG))])->assertSessionHas('success');
        $this->assertSame(0, $this->pending($this->fani));
        // مبلّغ بلا حساب: قاعدة الإغلاق القائمة تطلب تحقق شخص غير المنفّذ ← مهمة «تحققتُ ميدانياً» ثم «أغلق»
        $r = $this->actingAs($this->salama)->get('/app')->assertOk();
        $r->assertSee('تحقق ميدانياً قبل الإغلاق')->assertSee('action="'.url("/app/incidents/{$i->id}/verify").'"', false);
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/verify")->assertSessionHas('success');
        $r = $this->actingAs($this->salama)->get('/app')->assertOk();
        $r->assertSee('عولج — أغلقه؟')->assertSee('action="'.url("/app/incidents/{$i->id}/close").'"', false);
        // المركز يغلق من البطاقة نفسها ← لا مهمة
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close")->assertSessionHas('success');
        $this->assertSame('closed', $i->fresh()->status);
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertDontSee($i->code);
    }

    /** بلاغ بلا فني للمكان: المركز يرى «أحِله»؛ ومبلّغ بحساب يرى «هل عولج فعلاً؟» بزر POST. */
    public function test_center_refer_task_and_reporter_closure_task(): void
    {
        $this->actingAs($this->emp)->post('/incident/normal', ['description' => 'رائحة احتراق في المخزن', 'place_id' => Place::idByCode('HZ-08')]);
        $i = Incident::first();
        $this->assertSame('received', $i->status); // لا فني لـ HZ-08
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('لا فني للمكان — أحِله');
        $this->assertSame(0, $this->pending($this->emp));
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $this->fani->id])->assertSessionHas('success');
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}");
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => 'فُحص المخزن وأُزيل مصدر الرائحة وتُهوي المكان',
            'evidence' => UploadedFile::fake()->createWithContent('after.png', base64_decode(self::PNG))])->assertSessionHas('success');
        // مبلّغ بحساب: مهمة المركز «اطلب موافقته» ← بعدها لا مهمة للمركز والمهمة عند المبلّغ
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('اطلب موافقة المبلّغ');
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close"); // يطلب موافقة المبلّغ
        $this->assertTrue($i->fresh()->pending_closure);
        $this->assertSame(0, $this->pending($this->salama));
        $this->actingAs($this->emp)->get('/app')->assertOk()->assertSee('هل عولج فعلاً؟')->assertSee('action="'.url("/app/incidents/{$i->id}/approve-closure").'"', false);
        $this->actingAs($this->emp)->post("/app/incidents/{$i->id}/approve-closure")->assertSessionHas('success');
        $this->assertSame(0, $this->pending($this->emp));
    }

    public function test_form_assignment_is_a_task_until_completed(): void
    {
        $form = FormTemplate::create(['title' => 'إقرار بمخاطر المكاتب', 'form_type' => FormTemplate::TYPE_AWARENESS, 'is_active' => true, 'created_by_id' => $this->salama->id]);
        $a = FormAssignment::create(['form_id' => $form->id, 'assigned_to_id' => $this->fani->id, 'assigned_by_id' => $this->salama->id, 'due_date' => now()->subDay()->toDateString()]);
        $r = $this->actingAs($this->fani)->get('/app')->assertOk();
        $r->assertSee('إقرار بمخاطر المكاتب')->assertSee('عبّئه')->assertSee('متأخر')->assertSee('href="'.url("/app/forms/{$form->id}/fill").'"', false);
        $this->assertSame(0, $this->pending($this->emp));
        $a->update(['status' => FormAssignment::STATUS_COMPLETED]);
        $this->assertSame(0, $this->pending($this->fani));
    }

    public function test_risk_pending_approval_is_a_task_for_approvers_only_and_vanishes_on_approve(): void
    {
        $cat = RiskCategory::create(['name' => 'الحريق والانفجار', 'abbreviation' => 'FI', 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'أعمال ساخنة', 'abbreviation' => 'HW']);
        $risk = app(RiskService::class)->createRisk(null, ['title' => 'لحام بلا تصريح', 'description' => 'x', 'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 4, 'likelihood' => 3], 'master');
        $risk->update(['status' => 'pending_approval', 'organization_unit_id' => OrganizationUnit::first()->id]);

        $this->actingAs($this->idara)->get('/app')->assertOk()->assertSee('لحام بلا تصريح')->assertSee('ينتظر اعتمادك')->assertSee('action="'.url("/app/risk/{$risk->id}/approve").'"', false);
        $this->assertSame(1, $this->pending($this->salama));
        $this->assertSame(0, $this->pending($this->fani));   // لا يملك risk.approve
        $this->assertSame(0, $this->pending($this->mudir));  // مدير إدارة لا يعتمد
        $this->actingAs($this->idara)->post("/app/risk/{$risk->id}/approve")->assertSessionHas('success');
        $this->assertSame(0, $this->pending($this->idara));
    }

    public function test_permit_awaiting_review_is_a_task_for_reviewers_and_final_approval_for_center(): void
    {
        $this->seed([RiskBookSeeder::class, RiskControlsSeeder::class, TradeSeeder::class, TrainingTopicSeeder::class,
            PermitTypesSeeder::class, PermitConflictRulesSeeder::class, QualificationChecklistSeeder::class]);
        $type = PermitType::where('code', 'hot_work')->firstOrFail();
        $permit = app(PermitService::class)->create($type, ['title' => 'لحام دعامة في غرفة الكهرباء', 'place_id' => Place::idByCode('HZ-02'),
            'workers_count' => 2, 'starts_at' => now(), 'expires_at' => now()->addDays(3), 'requested_by_id' => $this->coord->id]);
        $this->assertSame(0, $this->pending($this->salama)); // مسودة: لا شيء ينتظر أحداً
        $permit->update(['status' => Permit::STATUS_SUBMITTED, 'submitted_at' => now()]);

        $this->actingAs($this->coord)->get('/app')->assertOk()->assertSee($permit->code)->assertSee('ينتظر مراجعتك')->assertSee('href="'.url("/app/permits/{$permit->id}/review").'"', false);
        $this->assertSame(1, $this->pending($this->salama));
        $this->assertSame(0, $this->pending($this->fani));
        $this->assertSame(0, $this->pending($this->idara)); // لا يملك permit.review
        // شاشة الطابور تعرض الاستعلام نفسه
        $this->actingAs($this->salama)->get('/app/permits/queue')->assertOk()->assertSee('data-pending="1"', false);

        $permit->update(['status' => Permit::STATUS_SAFETY_APPROVED]);
        $this->assertSame(0, $this->pending($this->coord));  // الاعتماد النهائي ليس للمنسق
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('ينتظر اعتمادك النهائي');
        $permit->update(['status' => Permit::STATUS_APPROVED]);
        $this->assertSame(0, $this->pending($this->salama));
    }

    /** الترتيب: المتأخر أولاً ثم الأقرب مهلةً — والشارة في الشريط تستطلع /app/inbox/count. */
    public function test_overdue_first_and_badge_endpoint_in_layout(): void
    {
        $f1 = FormTemplate::create(['title' => 'نموذج متأخر', 'form_type' => FormTemplate::TYPE_AWARENESS, 'is_active' => true, 'created_by_id' => $this->salama->id]);
        $f2 = FormTemplate::create(['title' => 'نموذج قادم', 'form_type' => FormTemplate::TYPE_AWARENESS, 'is_active' => true, 'created_by_id' => $this->salama->id]);
        FormAssignment::create(['form_id' => $f2->id, 'assigned_to_id' => $this->fani->id, 'assigned_by_id' => $this->salama->id, 'due_date' => now()->addDays(3)->toDateString()]);
        FormAssignment::create(['form_id' => $f1->id, 'assigned_to_id' => $this->fani->id, 'assigned_by_id' => $this->salama->id, 'due_date' => now()->subDays(2)->toDateString()]);
        $r = $this->actingAs($this->fani)->get('/app')->assertOk();
        $r->assertSeeInOrder(['نموذج متأخر', 'نموذج قادم'])->assertSee('عندك <span id="inboxCount">2</span> شيئان ينتظرانك', false);
        $r->assertSee(url('/app/inbox/count'), false)->assertSee('id="inboxN"', false);
    }
}
