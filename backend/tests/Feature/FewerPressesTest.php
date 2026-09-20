<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyMassMessage;
use App\Modules\Emergency\Models\EmergencyMessageResponse;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Project\Models\Project;
use App\Modules\Project\Models\ProjectContractor;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ١٨-١ «الأزرار تفي بوعدها» (قرار ٤٦): كل زر في «ما ينتظرك» يُنجز ما يَعِد به.
 *   (أ) «اطلب موافقته» ينجح ويقول نجح · (ب) موافقة المبلّغ = إغلاق آلي · (ج) أزرار الإنبوكس تفتح النافذة لا الصفحة فقط
 *   (د) رسالة الطوارئ تُجاب من البطاقة · (هـ) مهام المقاولين تفتح الصف · (و) لا زر «بدء المعالجة» · (ز) تنبيه المهلة غير المضبوطة.
 */
class FewerPressesTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private User $salama; private User $fani; private User $coord; private User $emp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-06');
        $this->coord = $this->user('coord', 'safety_coordinator', 'HZ-06');
        $this->emp = $this->user('emp', 'employee');
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function pending(User $u): int
    {
        return $this->actingAs($u)->getJson('/app/inbox/count')->assertOk()->json('count');
    }

    /** بلاغ في HZ-06 يصل الفني ويُعالَج بصورة؛ يعيد البلاغ في حالة «عولج». */
    private function resolvedIncident(?User $reporter): Incident
    {
        $req = $reporter ? $this->actingAs($reporter) : $this;
        $req->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')])->assertRedirect();
        auth()->logout();
        $i = Incident::first();
        app(\App\Modules\Incident\Services\IncidentService::class)->referToField($i, $this->salama->id, $this->fani->id, $this->coord->id); // ٢١-٤ (قرار ٥٤): لا قفز آلي إلى فني المكان — المركز يحيل ويسمّي المنسق
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk();
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => 'بُدّل البلاط المكسور ونُظّف الممر وأُعيد فتحه للمارة',
            'evidence' => UploadedFile::fake()->createWithContent('after.png', base64_decode(self::PNG))])->assertSessionHas('success');
        $this->assertSame('resolved', $i->fresh()->status);
        return $i->fresh();
    }

    /** (أ) «اطلب موافقته» من البطاقة: الطلب يُسجَّل والشاشة تقول نجح، لا «خطأ». */
    public function test_request_reporter_approval_reports_success(): void
    {
        $i = $this->resolvedIncident($this->emp);
        $r = $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close");
        $r->assertRedirect()->assertSessionHas('success', 'أُرسل طلب الموافقة إلى المبلّغ — يُغلق البلاغ فور موافقته.')->assertSessionMissing('error');
        $this->assertTrue($i->fresh()->pending_closure);
        $this->assertSame('resolved', $i->fresh()->status);
        $this->assertSame(0, $this->pending($this->salama));
    }

    /** (ب) موافقة المبلّغ بحساب تُغلق البلاغ وحدها — لا يعود المناوب ليضغط «أغلق». */
    public function test_reporter_approval_by_account_closes_the_incident(): void
    {
        $i = $this->resolvedIncident($this->emp);
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close")->assertSessionHas('success');
        $this->actingAs($this->emp)->post("/app/incidents/{$i->id}/approve-closure")->assertSessionHas('success');
        $this->assertSame('closed', $i->fresh()->status);
        $this->assertNotNull($i->fresh()->handled_at);
        $this->assertSame(0, $this->pending($this->salama));
        $this->assertSame(0, $this->pending($this->emp));
        $this->assertDatabaseHas('incident_events', ['incident_id' => $i->id, 'action' => 'close']);
    }

    /** (ب) موافقة المبلّغ برمز التتبع تُغلق البلاغ وحدها كذلك. */
    public function test_reporter_approval_by_code_closes_the_incident(): void
    {
        $i = $this->resolvedIncident(null);
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close")->assertSessionHas('success');
        auth()->logout();
        $this->post('/incident/track/approve', ['tracking_code' => $i->secret_tracking_code])->assertRedirect()->assertSessionHas('success');
        $this->assertSame('closed', $i->fresh()->status);
        $this->assertSame(0, $this->pending($this->salama));
    }

    /** (ج) «عولج» و«تعذّر — صعّد» يفتحان صفحة البلاغ والنافذة مفتوحة؛ و(و) لا زر «بدء المعالجة». */
    public function test_field_inbox_buttons_open_the_modal_and_no_dead_begin_button(): void
    {
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => Place::idByCode('HZ-06')]);
        $i = Incident::first();
        app(\App\Modules\Incident\Services\IncidentService::class)->referToField($i, $this->salama->id, $this->fani->id); // ٢١-٤ (قرار ٥٤): لا قفز آلي إلى فني المكان — المركز يحيل
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk()->assertDontSee('بدء المعالجة')->assertDontSee('begin-work');
        $this->assertSame('field_received', $i->fresh()->status);
        $h = $this->actingAs($this->fani)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('data-target="'.url("/app/incidents/{$i->id}").'?do=resolve"', $h);
        $this->assertStringContainsString('href="'.url("/app/incidents/{$i->id}").'?do=escalate"', $h);
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}?do=resolve")->assertOk()->assertSee('data-open-modal="resolveModal"', false);
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}?do=escalate")->assertOk()->assertSee('data-open-modal="escCoordModal"', false);
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}?do=whatever")->assertOk()->assertDontSee('data-open-modal=', false);
    }

    /** (ج) «لا، أعِده» عند المبلّغ و«صعّد للجنة» عند المنسق يفتحان نافذتيهما. */
    public function test_reporter_and_coordinator_secondary_buttons_open_their_modals(): void
    {
        $i = $this->resolvedIncident($this->emp);
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/close");
        $h = $this->actingAs($this->emp)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.url("/app/incidents/{$i->id}").'?do=reject"', $h);
        $this->actingAs($this->emp)->get("/app/incidents/{$i->id}?do=reject")->assertOk()->assertSee('data-open-modal="rejectModal"', false)
            ->assertSee('action="'.url("/app/incidents/{$i->id}/reject-closure-as-reporter").'"', false);

        // المبلّغ بحساب يرد البلاغ بنفسه (كان زرّه يرسل إلى مسار المركز فيُرفض ٤٠٣)؛ غيره لا يستطيع
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/reject-closure-as-reporter", ['note' => 'لست المبلّغ'])->assertSessionHas('error');
        $this->actingAs($this->emp)->post("/app/incidents/{$i->id}/reject-closure-as-reporter", ['note' => 'لم يُعالج، البلاط ما زال مكسوراً'])->assertSessionHas('success');
        $this->assertSame('in_progress', $i->fresh()->status);
        $this->assertFalse($i->fresh()->pending_closure);

        // المنسق: تصعيد من الفني ← «صعّد للجنة» يفتح نافذته
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/escalate-to-coord", ['reason' => 'أحتاج قطعة غيار غير متوفرة'])->assertSessionHas('success');
        $h = $this->actingAs($this->coord)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.url("/app/incidents/{$i->id}").'?do=escalate-manager"', $h);
        $this->actingAs($this->coord)->get("/app/incidents/{$i->id}?do=escalate-manager")->assertOk()->assertSee('data-open-modal="escMgrModal"', false);
    }

    /** (د) رسالة جماعية من المركز: تُجاب من البطاقة بضغطة («أنا بخير» / «أحتاج مساعدة») بدل فتح مركز الطوارئ. */
    public function test_emergency_message_is_answered_from_the_inbox_card(): void
    {
        $this->actingAs($this->salama)->postJson('/api/emergency/messages/send', ['title' => 'تنبيه', 'message' => 'ابقوا في أماكنكم',
            'target_type' => 'place', 'target_place_id' => Place::idByCode('HZ-06'), 'channels' => ['app']])->assertCreated();
        $m = EmergencyMassMessage::first();
        $h = $this->actingAs($this->fani)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('أنا بخير', $h);
        $this->assertStringContainsString('action="'.url("/api/emergency/messages/{$m->id}/quick/safe").'"', $h);
        $this->assertStringContainsString('action="'.url("/api/emergency/messages/{$m->id}/quick/need_help").'"', $h);
        $this->assertStringNotContainsString('>أقرّ<', $h);
        $this->actingAs($this->fani)->post("/api/emergency/messages/{$m->id}/quick/safe")->assertRedirect()->assertSessionHas('success');
        $this->assertSame('safe', EmergencyMessageResponse::where('message_id', $m->id)->where('user_id', $this->fani->id)->value('response_type'));
        $this->assertSame(1, $m->fresh()->responded_count);
        $this->assertSame(0, $this->pending($this->fani));
        $this->actingAs($this->fani)->post("/api/emergency/messages/{$m->id}/quick/bogus")->assertNotFound();
    }

    /** (هـ) مهام المقاولين تفتح الصف المعني لا القائمة كلها. */
    public function test_contractor_tasks_open_the_row(): void
    {
        $party = ExternalParty::create(['name' => 'مقاول التكييف', 'party_type' => 'contractor', 'status' => 'active']);
        $doc = ExternalPartyDocument::create(['external_party_id' => $party->id, 'name' => 'السجل التجاري', 'document_type' => 'cr', 'file' => 'cr.pdf', 'is_verified' => false]);
        $project = Project::create(['name' => 'مشروع التكييف', 'status' => 'active', 'place_id' => Place::idByCode('HZ-06')]);
        $pc = ProjectContractor::create(['project_id' => $project->id, 'external_party_id' => $party->id, 'role' => 'main', 'qualification_status' => ProjectContractor::STATUS_PRE_REVIEW]);

        $h = $this->actingAs($this->salama)->get('/app')->assertOk()->getContent();
        $this->assertStringContainsString('data-target="'.url("/app/external-parties/{$party->id}/documents")."#doc-{$doc->id}\"", $h);
        $this->assertStringContainsString('data-target="'.url("/app/projects/{$project->id}/contractors")."#contractor-{$pc->id}\"", $h);
        $this->actingAs($this->salama)->get("/app/external-parties/{$party->id}/documents")->assertOk()->assertSee("id=\"doc-{$doc->id}\"", false);
        $this->actingAs($this->salama)->get("/app/projects/{$project->id}/contractors")->assertOk()->assertSee("id=\"contractor-{$pc->id}\"", false);
    }

    /** (ز) مهلة بلاغ الشاغل غير مضبوطة ← تنبيه لمن يملك الإعدادات في «ما ينتظرك» بلا عدّ؛ يختفي بعد إدخال الأرقام. */
    public function test_unset_deadline_notice_for_settings_holder(): void
    {
        $r = $this->actingAs($this->salama)->get('/app')->assertOk();
        $r->assertSee('لم تُضبط مهلة')->assertSee('href="'.url('/app/incidents/settings').'"', false);
        $this->assertSame(0, $this->pending($this->salama));
        $this->actingAs($this->fani)->get('/app')->assertOk()->assertDontSee('لم تُضبط مهلة');
        $this->actingAs($this->salama)->post('/app/incidents/settings', ['incident_deadline_hours_normal' => 24, 'incident_deadline_hours_urgent' => 1, 'incident_deadline_hours_secret' => 48])->assertRedirect();
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertDontSee('لم تُضبط مهلة');
    }
}
