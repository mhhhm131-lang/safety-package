<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentEvent;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ٣ — بلاغ الشاغل. البوابة: سيناريو البلاغ السري كاملاً (BACKEND.md ٧-٢).
 */
class IncidentTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    private Risk $reference;
    private User $salama;
    private User $fani;
    private User $fani2;
    private User $coord;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(AffectedGroupsSeeder::class);
        $cat = RiskCategory::create(['name' => 'مخاطر الكهرباء', 'abbreviation' => 'ELC', 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'أسلاك مكشوفة', 'abbreviation' => 'WIR']);
        $master = app(RiskService::class)->createRisk(null, ['title' => 'سلك كهربائي مكشوف', 'description' => 'x', 'category_id' => $cat->id,
            'sub_category_id' => $sub->id, 'severity' => 4, 'likelihood' => 3], 'master');
        $master->update(['status' => 'approved']);
        $master->phases()->where('phase', 'proactive')->first()->update(['corrective_action' => 'فصل التيار وعزل السلك فوراً']);
        $this->reference = app(RiskCopyService::class)->masterToReference($master->fresh(), null);
        $this->reference->update(['status' => 'approved']);

        $this->salama = $this->user('salama', 'system_admin');
        $this->fani = $this->user('fani', 'field_worker', null, 'HZ-06');
        $this->fani2 = $this->user('fani2', 'field_worker', null, 'HZ-01');
        $this->coord = $this->user('coord', 'safety_coordinator', null, 'HZ-06');
    }

    private function user(string $username, string $role, ?string $unitCode = null, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitCode ? OrganizationUnit::where('code', $unitCode)->value('id') : null,
            'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    private function placeId(string $code): int
    {
        return Place::idByCode($code);
    }

    public function test_gate_secret_report_full_scenario(): void
    {
        // ١) شاغل بلا حساب يرسل بلاغاً سرياً من المكاتب الإدارية مع خطر من السجل العام وصورة
        $r = $this->post('/incident/secret', ['description' => 'سلك مكشوف قرب المصعد في الدور الثاني', 'place_id' => $this->placeId('HZ-06'),
            'location_text' => 'الدور الثاني', 'risk_id' => $this->reference->id, 'photo' => 'data:image/png;base64,'.self::PNG]);
        $r->assertRedirect();
        $incident = Incident::first();
        $this->assertSame('secret', $incident->incident_type);
        $this->assertNull($incident->actor_id);
        $this->assertNotEmpty($incident->secret_tracking_code);
        $this->assertStringContainsString($incident->secret_tracking_code, $r->headers->get('Location'));
        // التوجيه الآلي: منسق المكان وفني المكان من ملفات المستخدمين → وصل الفني بلا نقرة بشرية
        $this->assertSame($this->fani->id, $incident->incident_field_team_id);
        $this->assertSame($this->coord->id, $incident->incident_coordinator_id);
        $this->assertSame('forwarded', $incident->status); // ١٠-٣ (ح-١): الاستلام بيد الفني لا آلياً
        $this->assertNull($incident->field_received_at);
        $this->assertSame('فصل التيار وعزل السلك فوراً', $incident->corrective_action); // موروث من الخطر
        $this->assertSame(1, $incident->attachments()->where('kind', 'report')->count());
        $this->assertSame(['create', 'receive', 'refer', 'ref_receive', 'forward'], $incident->events()->orderBy('id')->pluck('action')->all());
        // لا سجل تدقيق للسري
        $this->assertDatabaseMissing('audit_logs', ['model_name' => 'Incident']);
        // إشعار داخل النظام للمركز والفني
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->salama->id, 'type' => 'incident.new']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->fani->id, 'type' => 'incident.forwarded']);

        // ٢) المبلّغ يتابع بالرمز: الخط الزمني بلا هوية
        $this->get('/incident/track?code='.$incident->secret_tracking_code)->assertOk()->assertSee($incident->code)->assertSee('حُوّل إلى الفني')->assertDontSee('استلمه الفني')->assertDontSee('اسم fani');
        $this->post('/incident/track', ['tracking_code' => 'WRONGCODE1'])->assertSessionHasErrors('tracking_code');

        // ٣) الرؤية: المركز والفني المعيَّن يريانه، فني مكان آخر لا
        $this->actingAs($this->salama)->get('/app/incidents')->assertOk()->assertSee($incident->code);
        $this->actingAs($this->fani2)->get("/app/incidents/{$incident->id}")->assertForbidden();
        $this->assertSame('forwarded', $incident->fresh()->status);
        // ١١-١ (ب، قرار ٣٤): فتح الفني المعيَّن للبلاغ = استلمه؛ وقت الفتح يُسجَّل فتبقى فجوة البلاغ مقيسة
        $this->actingAs($this->fani)->get("/app/incidents/{$incident->id}")->assertOk()->assertDontSee('استلمتُ البلاغ');
        $this->assertNotNull($incident->fresh()->field_opened_at);

        // ٤) الفني: يبدأ، يرفع دليلاً، يعلّم «عولج». فني آخر لا يستطيع
        $this->actingAs($this->fani2)->post("/app/incidents/{$incident->id}/begin-work")->assertSessionHas('error');
        $this->actingAs($this->fani2)->post("/app/incidents/{$incident->id}/field-receive")->assertSessionHas('error');
        $this->assertSame('field_received', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->field_received_at);
        $this->get('/incident/track?code='.$incident->secret_tracking_code)->assertOk()->assertSee('استلمه الفني')->assertDontSee('اسم fani');
        $this->actingAs($this->fani)->post("/app/incidents/{$incident->id}/begin-work")->assertSessionHas('success');
        $this->assertSame('in_progress', $incident->fresh()->status);
        $this->actingAs($this->fani)->post("/app/incidents/{$incident->id}/resolve", ['resolution_summary' => 'فُصل التيار وعُزل السلك وأُعيد الغطاء وفُحص الخط كاملاً'])->assertSessionHas('error'); // بلا دليل
        $this->actingAs($this->fani)->post("/app/incidents/{$incident->id}/upload", ['file' => UploadedFile::fake()->createWithContent('proof.png', base64_decode(self::PNG))])->assertSessionHas('success');
        $this->actingAs($this->fani)->post("/app/incidents/{$incident->id}/resolve", ['resolution_summary' => 'فُصل التيار وعُزل السلك وأُعيد الغطاء وفُحص الخط كاملاً'])->assertSessionHas('success');
        $this->assertSame('resolved', $incident->fresh()->status);

        // ٥) الإغلاق: السري يحتاج تحقق شخص غير المنفّذ. الفني لا يتحقق من عمله
        $this->actingAs($this->salama)->post("/app/incidents/{$incident->id}/close")->assertSessionHas('error');
        $this->actingAs($this->coord)->post("/app/incidents/{$incident->id}/verify")->assertSessionHas('success');
        $this->actingAs($this->salama)->post("/app/incidents/{$incident->id}/close")->assertSessionHas('success');
        $incident = $incident->fresh();
        $this->assertSame('closed', $incident->status);
        $this->assertNotNull($incident->closed_at);
        $this->assertSame(1, $this->reference->fresh()->incident_count);

        // ٦) الرمز يعرض الإغلاق وما تم
        $this->get('/incident/track?code='.$incident->secret_tracking_code)->assertOk()->assertSee('مغلق')->assertSee('فُصل التيار');
        // المرفق يُقدَّم للمخوَّل فقط
        $att = $incident->attachments()->first();
        $this->actingAs($this->salama)->get("/app/incidents/{$incident->id}/attachments/{$att->id}")->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($this->fani2)->get("/app/incidents/{$incident->id}/attachments/{$att->id}")->assertForbidden();
    }

    public function test_normal_report_without_field_worker_waits_for_center_then_links_to_inspection_form(): void
    {
        // القبو: لا فني ولا منسق مسجّلان للمكان → يتوقف عند «وصل المركز»
        $this->post('/incident/normal', ['description' => 'طفاية حريق مفقودة عند المدخل', 'place_id' => $this->placeId('HZ-02'),
            'risk_id' => $this->reference->id, 'reporter_name' => 'سعد', 'reporter_phone' => '0500000000'])->assertRedirect();
        $incident = Incident::first();
        $this->assertSame('received', $incident->status);
        $this->assertNull($incident->incident_field_team_id);
        $this->assertSame('سعد', $incident->reporter_name);
        $this->assertNotEmpty($incident->secret_tracking_code); // رمز تتبع للشاغل بلا حساب
        $this->actingAs($this->salama)->get('/app/incidents')->assertOk()->assertSee('ينتظر إحالة المركز');

        // شريط النموذج فارغ قبل الإحالة
        $this->assertStringNotContainsString($incident->code, InstituteDocument::where('key', 'ipa-occ')->value('data') ?? '');

        // المركز يحيل إلى فني القبو مع ملاحظة
        $this->actingAs($this->salama)->post("/app/incidents/{$incident->id}/refer", ['field_worker_id' => $this->fani2->id, 'note' => 'الطفاية عند المدخل الغربي'])->assertSessionHas('success');
        $incident = $incident->fresh();
        $this->assertSame('forwarded', $incident->status);
        $this->assertSame($this->fani2->id, $incident->incident_field_team_id);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->fani2->id, 'type' => 'incident.forwarded']);

        // الطبقة صفر: وثيقة ipa-occ المشتقة تحمل البلاغ لمكانه بصيغة النموذج
        $doc = json_decode(InstituteDocument::where('key', 'ipa-occ')->value('data'), true);
        $this->assertCount(1, $doc['reports']);
        $this->assertSame($incident->code, $doc['reports'][0]['id']);
        $this->assertSame('HZ-02', $doc['reports'][0]['hz']);
        $this->assertSame('assigned', $doc['reports'][0]['status']);
        $this->assertStringContainsString('الطفاية عند المدخل الغربي', $doc['reports'][0]['note']);
        $this->actingAs($this->fani2)->getJson('/api/store?all=1&keys=ipa-occ')->assertOk()->assertJsonPath('docs.ipa-occ.version', InstituteDocument::where('key', 'ipa-occ')->value('version'));

        // الفني في نموذج المكان يضغط «اربطه» ويفتح بلاغ فحص: النموذج يكتب linked + link → ربط عكسي و«جارٍ»
        $doc['reports'][0]['status'] = 'linked';
        $doc['reports'][0]['link'] = ['key' => 'ipa-hz02-form-v10', 'row' => 'ب — ٠١'];
        $this->actingAs($this->fani2)->putJson('/api/store/ipa-occ', ['data' => json_encode($doc, JSON_UNESCAPED_UNICODE), 'version' => 0])->assertOk();
        $incident = $incident->fresh();
        $this->assertSame(['key' => 'ipa-hz02-form-v10', 'row' => 'ب — ٠١'], $incident->inspection_ref);
        $this->assertSame('in_progress', $incident->status);
        $this->assertTrue(IncidentEvent::where('incident_id', $incident->id)->where('action', 'inspection_linked')->exists());
        // ما رُبط لم يعد في الشريط
        $doc2 = json_decode(InstituteDocument::where('key', 'ipa-occ')->value('data'), true);
        $this->assertCount(0, $doc2['reports']);
        // لا يُحذف من اللوحة
        $this->actingAs($this->salama)->deleteJson('/api/store/ipa-occ')->assertStatus(422);
        // مبلّغ بلا حساب برمز تتبع: «العادي لا يُغلق إلا بموافقتك» (قرار المستخدم ٢٠٢٦-٠٩-١٣) — يوافق من صفحة التتبع
        $this->actingAs($this->fani2)->post("/app/incidents/{$incident->id}/upload", ['file' => UploadedFile::fake()->createWithContent('p.png', base64_decode(self::PNG))]);
        $this->actingAs($this->fani2)->post("/app/incidents/{$incident->id}/resolve", ['resolution_summary' => 'رُكّبت طفاية جديدة معتمدة عند المدخل الغربي وفُحصت'])->assertSessionHas('success');
        $this->actingAs($this->salama)->post("/app/incidents/{$incident->id}/close")->assertSessionHas('error'); // يطلب موافقة المبلّغ
        $this->assertTrue($incident->fresh()->pending_closure);
        auth()->logout();
        $code = $incident->secret_tracking_code;
        $this->get('/incident/track?code='.$code)->assertOk()->assertSee('id="closureApproval"', false)->assertSee('هل عولج فعلاً؟');
        // يرفض أولاً بملاحظة ← يعود للمعالجة ← يُعالج ثانية ← يوافق
        $this->post('/incident/track/reject', ['tracking_code' => $code, 'note' => 'الطفاية بلا خرطوم'])->assertRedirect();
        $this->assertSame('in_progress', $incident->fresh()->status);
        $this->assertFalse($incident->fresh()->pending_closure);
        $this->actingAs($this->fani2)->post("/app/incidents/{$incident->id}/resolve", ['resolution_summary' => 'رُكّب الخرطوم وفُحصت الطفاية كاملة وعُلّقت في مكانها'])->assertSessionHas('success');
        $this->actingAs($this->salama)->post("/app/incidents/{$incident->id}/close")->assertSessionHas('error');
        auth()->logout();
        $this->post('/incident/track/approve', ['tracking_code' => $code])->assertRedirect();
        $this->assertTrue($incident->fresh()->reporter_approved_closure);
        $this->get('/incident/track?code='.$code)->assertOk()->assertDontSee('id="closureApproval"', false)->assertSee('وافق المُبلِّغ على الإغلاق');
        $this->actingAs($this->salama)->post("/app/incidents/{$incident->id}/close")->assertSessionHas('success');
        $this->assertSame('closed', $incident->fresh()->status);
        $this->post('/incident/track/approve', ['tracking_code' => 'NOPE1'])->assertSessionHasErrors('tracking_code');
    }

    public function test_center_can_close_with_note_and_mark_out_of_scope_and_reporter_with_account_approves_closure(): void
    {
        $emp = $this->user('emp', 'employee');
        // إغلاق بملاحظة (لا يحتاج فنياً) — إضافة معهدية
        $this->post('/incident/normal', ['description' => 'باب الطوارئ يصدر صريراً', 'place_id' => $this->placeId('HZ-02'), 'risk_id' => $this->reference->id]);
        $a = Incident::latest('id')->first();
        $this->actingAs($this->salama)->post("/app/incidents/{$a->id}/close-with-note", ['note' => 'زُيّت الباب في الجولة اليومية'])->assertSessionHas('success');
        $this->assertSame('closed', $a->fresh()->status);
        $this->assertTrue(IncidentEvent::where('incident_id', $a->id)->where('action', 'close_with_note')->exists());
        // لا يُغلق بملاحظة ما وصل الفني
        $this->actingAs($emp)->post('/incident/normal', ['description' => 'رائحة احتراق من لوحة الكهرباء', 'place_id' => $this->placeId('HZ-06'), 'risk_id' => $this->reference->id]);
        $b = Incident::latest('id')->first();
        $this->assertSame($emp->id, $b->actor_id);
        $this->assertNull($b->secret_tracking_code); // بحساب: يتابع من حسابه
        $this->actingAs($this->salama)->post("/app/incidents/{$b->id}/close-with-note", ['note' => 'x y z 1'])->assertSessionHas('error');
        // خارج النطاق من أي حالة — المركز فقط
        $this->actingAs($this->fani)->post("/app/incidents/{$b->id}/out-of-scope")->assertSessionHas('error');
        // المبلّغ بحساب: يعالج الفني، المركز يطلب الموافقة، المبلّغ يوافق، ثم الإغلاق
        $this->actingAs($this->fani)->post("/app/incidents/{$b->id}/field-receive");
        $this->actingAs($this->fani)->post("/app/incidents/{$b->id}/begin-work");
        $this->actingAs($this->fani)->post("/app/incidents/{$b->id}/upload", ['file' => UploadedFile::fake()->createWithContent('p.png', base64_decode(self::PNG))]);
        $this->actingAs($this->fani)->post("/app/incidents/{$b->id}/resolve", ['resolution_summary' => 'فُحصت اللوحة وبُدّل القاطع المحترق وأُعيد التيار'])->assertSessionHas('success');
        $this->actingAs($this->salama)->post("/app/incidents/{$b->id}/close")->assertSessionHas('error');
        $this->assertTrue($b->fresh()->pending_closure);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $emp->id, 'type' => 'incident.closure']);
        $this->actingAs($this->fani)->post("/app/incidents/{$b->id}/approve-closure")->assertSessionHas('error'); // ليس المبلّغ
        $this->actingAs($emp)->get("/app/incidents/{$b->id}")->assertOk()->assertSee('أوافق على الإغلاق');
        $this->actingAs($emp)->post("/app/incidents/{$b->id}/approve-closure")->assertSessionHas('success');
        $this->actingAs($this->salama)->post("/app/incidents/{$b->id}/close")->assertSessionHas('success');
        $this->assertSame('closed', $b->fresh()->status);
    }

    /** المرحلة ١١-١ (أ، قرار ٣٤): الخطر اختياري للشاغل — التوجيه بالمكان وحده، والمركز يصنّف من صفحة البلاغ. */
    public function test_normal_report_without_risk_routes_by_place_and_center_classifies(): void
    {
        $this->post('/incident/normal', ['description' => 'بلاط مكسور قرب المصعد', 'place_id' => $this->placeId('HZ-06')])->assertRedirect()->assertSessionHasNoErrors();
        $i = Incident::first();
        $this->assertNotNull($i, 'البلاغ بلا خطر لم يُنشأ');
        $this->assertNull($i->risk_id);
        $this->assertSame($this->fani->id, $i->incident_field_team_id); // فني المكان من ملفات المستخدمين
        $this->assertSame('forwarded', $i->status);
        $this->assertStringStartsWith('بلاط مكسور قرب المصعد', $i->title); // العنوان من الوصف حين لا خطر
        // الصفحة العامة: قوائم التصنيف الثلاث ليست إلزامية
        $html = $this->get('/incident/normal')->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/id="riskId"[^>]*required/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="riskCat"[^>]*required/', $html);
        // المركز يرى أن البلاغ لم يُصنَّف ويصنّفه بزر الربط القائم
        $this->actingAs($this->salama)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('لم يُصنَّف بعد');
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/link-risk", ['risk_id' => $this->reference->id])->assertSessionHas('success');
        $this->assertSame($this->reference->id, $i->fresh()->risk_id);
    }

    public function test_escalation_chain_and_permissions(): void
    {
        $lajna = $this->user('lajna', 'safety_committee');
        $this->post('/incident/urgent', ['description' => 'دخان من غرفة الخوادم', 'place_id' => $this->placeId('HZ-06'), 'risk_id' => $this->reference->id]);
        $i = Incident::first();
        $this->assertSame('urgent', $i->incident_type);
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/field-receive")->assertSessionHas('success'); // ١٠-٣ (ح-١)
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/escalate-to-coord", ['reason' => 'قصير'])->assertSessionHasErrors('reason');
        $resp = $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/escalate-to-coord", ['reason' => 'يحتاج فصل التيار من المصدر الرئيسي وهذا خارج صلاحيتي']);
        $resp->assertSessionHas('success');
        $this->assertSame('escalated_to_coord', $i->fresh()->status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->coord->id, 'type' => 'incident.escalated']);
        $this->actingAs($this->coord)->post("/app/incidents/{$i->id}/escalate-to-manager", ['reason' => 'يحتاج قرار إيقاف العمل في الطابق كاملاً'])->assertSessionHas('success');
        $this->assertSame('escalated_to_manager', $i->fresh()->status);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $lajna->id, 'type' => 'incident.committee']);
        $this->actingAs($lajna)->post("/app/incidents/{$i->id}/resolve-escalation")->assertSessionHas('success');
        $this->assertSame('in_progress', $i->fresh()->status);
        $this->assertSame($lajna->id, $i->fresh()->incident_field_team_id); // من تولّى صار المنفّذ
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => 'محاولة من الفني السابق بعد التولّي يجب أن تُرفض'])->assertSessionHas('error');
        $this->actingAs($lajna)->post("/app/incidents/{$i->id}/upload", ['file' => UploadedFile::fake()->createWithContent('p.png', base64_decode(self::PNG))]);
        $this->actingAs($lajna)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => 'أُوقف العمل في الطابق وفُصل التيار وعولج مصدر الدخان'])->assertSessionHas('success');
        $this->assertSame('resolved', $i->fresh()->status);

        // الصلاحيات: الموظف والفني لا يصلان إلى الإعدادات؛ الضيف يُحوَّل؛ صفحة البلاغ العامة للجميع
        $this->actingAs($this->fani)->get('/app/incidents/settings')->assertForbidden();
        $this->actingAs($this->user('emp2', 'employee'))->get('/app/incidents')->assertForbidden();
        auth()->logout();
        $this->get('/app/incidents')->assertRedirect('/login');
        $this->get('/incident/normal')->assertOk()->assertSee('name="risk_id"', false)->assertSee('HZ-06');
        $this->get('/incident?place=HZ-06')->assertOk()->assertSee('?place=HZ-06', false);
        $this->get('/incident/api/risks?sub_category_id='.$this->reference->sub_category_id)->assertOk()->assertJsonPath('0.id', $this->reference->id)->assertJsonPath('0.corrective_action', 'فصل التيار وعزل السلك فوراً');
        // ١١-١ (أ، قرار ٣٤): الخطر اختياري في الأنواع الثلاثة — كان إلزامياً في العادي
        $this->post('/incident/normal', ['description' => 'بلا خطر مختار', 'place_id' => $this->placeId('HZ-06')])->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/incident/secret', ['description' => 'بلاغ سري بلا تصنيف', 'place_id' => $this->placeId('HZ-06')])->assertRedirect();
        $s = Incident::latest('id')->first();
        $this->assertNull($s->risk_id);
        $this->assertSame('forwarded', $s->status); // فني المكان معروف حتى بلا خطر؛ الاستلام بيده (١٠-٣)
        // مكان له فني بلا منسق (القبو): خطوتا المنسق يؤديهما النظام ويصل الفني مباشرة
        $this->post('/incident/normal', ['description' => 'إنارة الطوارئ مطفأة في المواقف', 'place_id' => $this->placeId('HZ-01'), 'risk_id' => $this->reference->id]);
        $n = Incident::latest('id')->first();
        $this->assertNull($n->incident_coordinator_id);
        $this->assertSame($this->fani2->id, $n->incident_field_team_id);
        $this->assertSame('forwarded', $n->status);
        $this->assertTrue(IncidentEvent::where('incident_id', $n->id)->where('action', 'ref_receive')->where('note', 'like', '%لا منسق%')->exists());
        // المركز يربطه بخطر لاحقاً
        $this->actingAs($this->salama)->post("/app/incidents/{$s->id}/link-risk", ['risk_id' => $this->reference->id])->assertSessionHas('success');
        $this->assertSame($this->reference->id, $s->fresh()->risk_id);
    }

    public function test_deadlines_are_off_until_set_then_overdue_is_marked_and_escalated(): void
    {
        $this->post('/incident/normal', ['description' => 'بلا مهلة', 'place_id' => $this->placeId('HZ-02'), 'risk_id' => $this->reference->id]);
        $this->assertNull(Incident::first()->deadline_at);
        $this->artisan('incidents:check-deadlines')->expectsOutputToContain('overdue marked: 0');

        // مسؤول السلامة يقرر المهل من الشاشة
        $this->actingAs($this->salama)->get('/app/incidents/settings')->assertOk()->assertSee('لم تُقرر');
        $this->actingAs($this->salama)->post('/app/incidents/settings', ['incident_deadline_hours_normal' => 2, 'incident_deadline_hours_urgent' => 0.5, 'incident_deadline_hours_secret' => ''])->assertRedirect();
        $this->assertSame(2.0, Setting::deadlineHours('normal'));
        $this->assertNull(Setting::deadlineHours('secret'));

        $this->post('/incident/normal', ['description' => 'بمهلة ساعتين', 'place_id' => $this->placeId('HZ-02'), 'risk_id' => $this->reference->id]);
        $i = Incident::latest('id')->first();
        $this->assertSame('received', $i->status);
        $this->assertEqualsWithDelta(now()->addHours(2)->timestamp, $i->deadline_at->timestamp, 5);

        $this->travel(3)->hours();
        $this->artisan('incidents:check-deadlines')->expectsOutputToContain('overdue marked: 1');
        $i = $i->fresh();
        $this->assertNotNull($i->overdue_at);
        $this->assertTrue($i->isOverdue());
        $this->assertTrue(IncidentEvent::where('incident_id', $i->id)->where('action', 'overdue')->exists());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->salama->id, 'type' => 'incident.overdue']);
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->salama->id, 'type' => 'incident.committee']); // لا لجنة بعد → مسؤول السلامة
        $this->actingAs($this->salama)->get('/app/incidents?status=overdue')->assertOk()->assertSee($i->code)->assertSee('متجاوز');
        // الإحالة تعيد المهلة
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/refer", ['field_worker_id' => $this->fani2->id])->assertSessionHas('success');
        $i = $i->fresh();
        $this->assertNull($i->overdue_at);
        $this->assertFalse($i->isOverdue());
        $this->artisan('incidents:check-deadlines')->expectsOutputToContain('overdue marked: 0');
    }
}
