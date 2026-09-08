<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Project\Models\ContractorProfile;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\TradeSeeder;
use Database\Seeders\TrainingTopicSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٦ — سيناريو المعهد (البوابة ٧-٢): مقاول يُسجَّل ← يُؤهَّل ← مشروع في مكان ← عماله ← بوابته.
 * ما ليس في OHSMS: المكان إلزامي للمشروع، حساب المقاول يرى طرفه فقط، المستندات base64، الاعتماد بالصلاحية، شاشة المهن.
 */
class ContractorsTest extends TestCase
{
    use RefreshDatabase;

    private User $salama;
    private User $coord;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(TradeSeeder::class);
        $this->seed(TrainingTopicSeeder::class);
        $this->salama = $this->user('salama', 'system_admin');
        $this->coord = $this->user('coord', 'safety_coordinator');
        $this->employee = $this->user('emp', 'employee');
    }

    private function user(string $username, string $role, ?int $partyId = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '123456', 'email' => "$username@example.test", 'external_party_id' => $partyId]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true]);
        return $u;
    }

    private function place(string $code): int
    {
        return Place::idByCode($code);
    }

    public function test_gate_contractor_registered_qualified_project_in_place_workers_portal(): void
    {
        $s = $this->actingAs($this->salama);

        // ١) التسجيل
        $s->post('/app/external-parties', ['name' => 'شركة الصيانة المتحدة', 'party_type' => 'contractor', 'cr_number' => '1010123456', 'contact_person' => 'سعد', 'phone' => '0500000001'])->assertRedirect();
        $party = ExternalParty::firstOrFail();

        // ٢) التأهيل: مستندان إلزاميان (base64) ← توثيق ← ملف ← ثقة ١٠٠ (القنوات الخارجية معطّلة فتُمنح كاملة)
        foreach (['cr', 'insurance'] as $t) {
            $s->post("/app/external-parties/{$party->id}/documents", ['name' => $t, 'document_type' => $t, 'expiry_date' => '2027-12-31', 'file' => UploadedFile::fake()->createWithContent("$t.pdf", '%PDF-1.4 '.$t)])->assertRedirect();
        }
        $docs = ExternalPartyDocument::where('external_party_id', $party->id)->get();
        $this->assertCount(2, $docs);
        $this->assertNotEmpty($docs[0]->file_data, 'الملف base64 في القاعدة');
        $s->get("/app/external-parties/{$party->id}/documents/{$docs[0]->id}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        foreach ($docs as $d) {
            $s->post("/app/external-parties/{$party->id}/documents/{$d->id}/verify")->assertRedirect();
        }
        $s->put("/app/external-parties/{$party->id}/profile", ['cr_expiry_date' => '2027-12-31', 'insurance_provider' => 'التعاونية', 'insurance_expiry_date' => '2027-06-30'])->assertRedirect();
        $s->get("/app/external-parties/{$party->id}/profile")->assertOk()->assertSee('data-check="docs_complete" data-passed="1"', false);
        $this->assertSame(100, ContractorProfile::where('external_party_id', $party->id)->value('trust_score'));

        // ٣) مشروع في مكان (إلزامي) ← ربط ← التأهيل المسبق واللاحق بآلة الحالة
        $s->post('/app/projects', ['name' => 'صيانة التكييف', 'status' => 'active'])->assertSessionHasErrors('place_id');
        $s->post('/app/projects', ['name' => 'صيانة التكييف', 'status' => 'active', 'place_id' => $this->place('HZ-06')])->assertRedirect();
        $project = Project::firstOrFail();
        $this->assertSame($this->place('HZ-06'), $project->place_id);
        $s->post("/app/projects/{$project->id}/contractors/assign", ['external_party_id' => $party->id, 'role' => 'main', 'activity_scope' => 'صيانة'])->assertRedirect();
        $pc = ProjectContractor::firstOrFail();
        $this->assertSame('draft', $pc->qualification_status);
        $s->post("/app/projects/{$project->id}/contractors/{$pc->id}/transition", ['status' => 'post_approved'])->assertSessionHas('error'); // قفزة غير مسموحة
        foreach (['pre_review', 'pre_approved', 'post_review', 'post_approved'] as $st) {
            $s->post("/app/projects/{$project->id}/contractors/{$pc->id}/transition", ['status' => $st])->assertSessionHas('success');
        }
        $pc->refresh();
        $this->assertTrue($pc->isWorkReady());
        $this->assertNotNull($pc->pre_approved_at);
        $this->assertSame(5, $pc->events()->count(), 'created + 4 status_changed');

        // ٤) حساب المقاول من شاشة المستخدمين مربوطاً بالطرف
        $s->post('/app/users', ['name' => 'مشرف المقاول', 'username' => 'muqawil', 'password' => '123456', 'role' => 'contractor_supervisor', 'external_party_id' => $party->id])->assertRedirect();
        $mq = User::where('username', 'muqawil')->firstOrFail();
        $this->assertSame($party->id, $mq->external_party_id);
        $this->assertTrue($mq->isContractor());
        // إيقاف ثم إعادة الاعتماد: تغيّر التأهيل يُنبّه حساب المقاول داخل النظام
        $s->post("/app/projects/{$project->id}/contractors/{$pc->id}/transition", ['status' => 'suspended', 'notes' => 'انتهى التأمين'])->assertSessionHas('success');
        $s->post("/app/projects/{$project->id}/contractors/{$pc->id}/transition", ['status' => 'post_approved'])->assertSessionHas('success');
        $this->assertSame(2, AppNotification::where('user_id', $mq->id)->where('type', 'contractor.qualification')->count(), 'تنبيهات التأهيل تصل حساب المقاول');

        // ٥) بوابة المقاول: يرى طرفه فقط، يسجّل عاملاً ويقدّمه، لا يعتمد
        $other = ExternalParty::create(['name' => 'طرف آخر', 'party_type' => 'supplier']);
        $m = $this->actingAs($mq);
        $m->get('/app')->assertRedirect('/app/contractor');
        $m->get('/app/contractor')->assertOk()->assertSee('شركة الصيانة المتحدة')->assertSee('data-status="post_approved"', false);
        $m->get('/app/external-parties')->assertOk()->assertDontSee('طرف آخر');
        $m->get("/app/external-parties/{$other->id}")->assertForbidden();
        $m->get('/app/projects/create')->assertForbidden();
        $m->get('/app/projects')->assertOk()->assertSee('صيانة التكييف');
        $trade = Trade::where('level', 'occupation')->firstOrFail();
        $m->post('/app/workers', ['full_name' => 'عامل أول', 'national_id' => '2000000001', 'trade_id' => $trade->id, 'external_party_id' => $other->id, 'project_id' => $project->id, 'place_id' => $this->place('HZ-06')])->assertRedirect();
        $worker = Worker::firstOrFail();
        $this->assertSame($party->id, $worker->external_party_id, 'مشرف المقاول لا يسجّل عاملاً لطرف آخر');
        $this->assertSame('draft', $worker->status);
        $m->post("/app/workers/{$worker->id}/transition", ['status' => 'submitted'])->assertSessionHas('success');
        $m->post("/app/workers/{$worker->id}/transition", ['status' => 'induction'])->assertForbidden();
        $this->assertTrue(AppNotification::where('user_id', $this->salama->id)->where('type', 'worker.submitted')->exists());

        // ٦) الاعتماد بالصلاحية حتى «مصرّح بالعمل»؛ المشرف يرى؛ الموظف لا يرى شيئاً
        $s = $this->actingAs($this->salama);
        $s->get('/app/workers/approval-queue')->assertOk()->assertSee('عامل أول');
        foreach (['induction', 'training', 'approved', 'work_authorized'] as $st) {
            $s->post("/app/workers/{$worker->id}/transition", ['status' => $st])->assertSessionHas('success');
        }
        $this->assertSame('work_authorized', $worker->fresh()->status);
        $this->assertSame(5, $worker->statusEvents()->count());
        $this->actingAs($mq)->get("/app/workers/{$worker->id}")->assertOk()->assertSee('data-worker-status="work_authorized"', false);
        $this->actingAs($this->employee)->get('/app/workers')->assertForbidden();
        $this->actingAs($this->employee)->get('/app/contractor')->assertForbidden();

        // ٧) الكفاءة والمهن والشاشات
        $s = $this->actingAs($this->salama);
        $s->get("/app/competency/worker/{$worker->id}")->assertOk();
        $s->get("/app/competency/contractor/{$party->id}")->assertOk();
        $s->get("/app/projects/{$project->id}/dashboard")->assertOk()->assertSee('شركة الصيانة المتحدة');
        $s->post('/app/competency/trades', ['code' => 'IPA-HVAC', 'name' => 'فني تكييف', 'level' => 'occupation'])->assertRedirect();
        $this->assertDatabaseHas('trades', ['code' => 'IPA-HVAC']);
        $this->actingAs($this->coord)->post('/app/competency/trades', ['code' => 'X', 'name' => 'x', 'level' => 'occupation'])->assertRedirect(); // المنسق يملك competency.manage
        $this->actingAs($this->employee)->post('/app/competency/trades', ['code' => 'Y', 'name' => 'y', 'level' => 'occupation'])->assertForbidden();
        $s = $this->actingAs($this->salama); // actingAs يبدّل المستخدم عالمياً
        foreach (['/app/external-parties/'.$party->id, '/app/external-parties/'.$party->id.'/risks', '/app/external-parties/'.$party->id.'/evaluation/create', '/app/projects/'.$project->id, '/app/projects/'.$project->id.'/contractors', '/app/projects/'.$project->id.'/manhours', '/app/projects/'.$project->id.'/comparison', '/app/projects/'.$project->id.'/risks', '/app/workers', '/app/workers/'.$worker->id.'/documents', '/app/competency/matrix', '/app/competency/trades', '/app/settings/contractor-channels', '/app/users/'.$mq->id.'/edit'] as $u) {
            $s->get($u)->assertOk();
        }
    }

    public function test_portal_link_without_account_uploads_documents_once(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor']);
        $s = $this->actingAs($this->salama);
        $s->post("/app/external-parties/{$party->id}/portal-link")->assertRedirect()->assertSessionHas('portal_link');
        $token = ContractorProfile::where('external_party_id', $party->id)->value('portal_link_token');
        $this->assertSame(48, strlen($token));

        auth()->logout();
        $this->get("/contractor-portal/{$token}")->assertOk();
        $this->post("/contractor-portal/{$token}/submit", ['documents' => [['file' => UploadedFile::fake()->createWithContent('cr.pdf', '%PDF-1.4 cr'), 'document_type' => 'cr', 'expiry_date' => now()->addYear()->toDateString()]]])->assertRedirect("/contractor-portal/{$token}/done");
        $doc = ExternalPartyDocument::where('external_party_id', $party->id)->firstOrFail();
        $this->assertSame('portal_link', $doc->source_channel);
        $this->assertFalse($doc->is_verified);
        $this->assertNotNull($doc->file_data);
        $this->get("/contractor-portal/{$token}")->assertStatus(410); // مرة واحدة
        $this->get('/contractor-portal/wrong-token')->assertNotFound();
        $this->assertTrue(AppNotification::where('user_id', $this->salama->id)->where('type', 'contractor.documents')->exists());
    }

    public function test_contractor_channels_settings_and_prequalification_skip_rules(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول', 'party_type' => 'contractor']);
        $s = $this->actingAs($this->salama);
        $s->get('/app/settings/contractor-channels')->assertOk();
        $s->post('/app/settings/contractor-channels', ['channels' => ['pdf_upload' => ['enabled' => 1, 'priority' => 10], 'etimad' => ['enabled' => 1, 'priority' => 50, 'api_key' => 'k', 'base_url' => 'https://etimad.example']]])->assertRedirect();
        $this->assertDatabaseHas('contractor_channels', ['channel_type' => 'pdf_upload', 'enabled' => 1]);
        $this->assertDatabaseHas('contractor_channels', ['channel_type' => 'etimad', 'enabled' => 1]);
        // اعتماد مفعّل بلا تحقق → الفحص الخامس يسقط (لا محاكاة)
        $s->get("/app/external-parties/{$party->id}/profile")->assertOk()->assertSee('data-check="etimad_active" data-passed="0"', false);
        $this->actingAs($this->coord)->get('/app/settings/contractor-channels')->assertForbidden();
    }
}
