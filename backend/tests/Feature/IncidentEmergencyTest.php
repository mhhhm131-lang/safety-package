<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Models\IncidentEvent;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * المرحلة ١٠-٣ — من البلاغ إلى الحالة + البلاغ العادي (قرار ٣٠، المكوّنان و + ح). البوابة:
 *   بلاغ عاجل في HZ-06 ← زر التفعيل ← حالة طبية مربوطة ← البلاغ يحمل الحدث ويكمل مساره ← الحالة تعرض مصدرها؛
 *   وبلاغ عادي يعرض الطبقة التشغيلية والفني يستلم بيده.
 */
class IncidentEmergencyTest extends TestCase
{
    use RefreshDatabase;

    private Risk $fireRisk; private Risk $physRisk;
    private User $salama; private User $munawib; private User $fani; private User $coord; private User $mudir; private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(base_path());
        if (!is_file($root.'/HZ-06-offices/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة');
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(AffectedGroupsSeeder::class);
        $this->seed(EmergencySeeder::class);
        app(ResponsePlanSync::class)->sync($root);

        $this->fireRisk = $this->risk('الحريق والانفجار', 'FI', 'حريق في المكاتب', ['operational' => ['preventive_action' => 'جولات تفقد وإطفاء الأجهزة نهاية الدوام', 'corrective_action' => 'إزالة مصدر الاشتعال فوراً'],
            'response' => ['preventive_action' => 'تنبيه المركز والفريق الأولي يباشر الإطفاء', 'corrective_action' => 'فحص فني قبل استعادة التشغيل']]);
        $this->physRisk = $this->risk('الفيزيائية (عوامل البيئة)', 'PH', 'إجهاد حراري', ['operational' => ['preventive_action' => 'ماء وظل وفترات راحة', 'corrective_action' => 'نقل المصاب لمكان بارد'],
            'response' => ['preventive_action' => 'تبريد واستدعاء الطبيب']]);

        $this->salama = $this->user('salama', 'system_admin');
        $this->munawib = $this->user('munawib', 'system_staff');
        $this->fani = $this->user('fani', 'field_worker', 'HZ-06');
        $this->coord = $this->user('coord', 'safety_coordinator', 'HZ-06');
        $this->mudir = $this->user('mudir', 'department_manager');
        $this->employee = $this->user('emp', 'employee');
    }

    private function risk(string $catName, string $abbr, string $title, array $phases): Risk
    {
        $cat = RiskCategory::firstOrCreate(['name' => $catName], ['abbreviation' => $abbr, 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'فرع '.$title, 'abbreviation' => 'X'.$abbr]);
        $master = app(RiskService::class)->createRisk(null, ['title' => $title, 'description' => 'x', 'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 4, 'likelihood' => 3], 'master');
        $master->update(['status' => 'approved']);
        foreach ($phases as $key => $data) $master->phases()->where('phase', $key)->first()->update($data);
        $ref = app(RiskCopyService::class)->masterToReference($master->fresh(), null);
        $ref->update(['status' => 'approved']);
        return $ref;
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode),
            'organization_unit_id' => $role === 'department_manager' ? OrganizationUnit::first()->id : null]);
        return $u;
    }

    public function test_gate_urgent_report_to_emergency_and_back(): void
    {
        // بلاغ عاجل من شاغل بلا حساب على خطر حريق في المكاتب الإدارية
        $this->post('/incident/urgent', ['description' => 'دخان كثيف من مكتب في الدور الثاني', 'place_id' => Place::idByCode('HZ-06'), 'location_text' => 'الدور الثاني', 'risk_id' => $this->fireRisk->id, 'reporter_name' => 'سعد'])->assertRedirect();
        $i = Incident::first();
        $this->assertSame('urgent', $i->incident_type);
        $this->assertSame('forwarded', $i->status); // (ح-١) الاستلام بيد الفني
        $this->assertSame('فحص فني قبل استعادة التشغيل', $i->corrective_action); // العاجل يرث تصحيحي طبقة الاستجابة (٢٠٢٦-٠٩-١١)

        // المركز يفتح البلاغ: الطبقة «الاستجابة» بنصها، والنوع المقترح «حريق»، وزر التفعيل
        $r = $this->actingAs($this->munawib)->get("/app/incidents/{$i->id}");
        $r->assertOk()->assertSee('الطبقة الاستجابة')->assertSee('تنبيه المركز والفريق الأولي يباشر الإطفاء')->assertDontSee('جولات تفقد وإطفاء الأجهزة')
            ->assertSee('تفعيل حالة طارئة')->assertSee('<option value="fire" selected>', false)->assertSee('trigger-emergency');
        // ١١-١ (د): الخطورة مقترحة من خطورة الخطر (٤ ← مرتفع) والزر الواحد يسمّي النوع المقترح
        $r->assertSee('<option value="high" selected>', false)->assertSee('فعّل حالة حريق الآن');
        // مدير الإدارة يرى البلاغ (إن رآه) بلا زر؛ الفني بلا زر؛ الموظف ممنوع من التفعيل
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk()->assertDontSee('trigger-emergency'); // ١١-١ (ب): هذا الفتح = استلام
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/trigger-emergency", ['incident_type' => 'fire', 'severity' => 'high'])->assertForbidden();
        $this->actingAs($this->employee)->post("/app/incidents/{$i->id}/trigger-emergency", ['incident_type' => 'fire', 'severity' => 'high'])->assertForbidden();
        $this->actingAs($this->munawib)->post("/app/incidents/{$i->id}/trigger-emergency", ['incident_type' => 'lockdown', 'severity' => 'high'])->assertSessionHasErrors('incident_type');

        // (و) المركز يغيّر النوع المقترح إلى «طبي» ويفعّل
        $r = $this->actingAs($this->munawib)->post("/app/incidents/{$i->id}/trigger-emergency", ['incident_type' => 'medical', 'severity' => 'high', 'note' => 'مصاب باختناق']);
        $e = EmergencyIncident::first();
        $this->assertNotNull($e);
        $r->assertRedirect("/app/emergency/incidents/{$e->id}/live")->assertSessionHas('success');
        $this->assertSame('medical', $e->incident_type);
        $this->assertSame(Place::idByCode('HZ-06'), $e->place_id);
        $this->assertSame($i->id, $e->linked_incident_id);
        $this->assertSame($this->munawib->id, $e->triggered_by_id);
        $this->assertStringContainsString('من بلاغ الشاغل '.$i->code, $e->description);
        $this->assertStringContainsString('مصاب باختناق', $e->description);
        $this->assertSame(3, $e->planSteps()->count()); // حالة طبية: المسار الطبي وحده (١٠-٢)

        // البلاغ يحمل الحدث ويكمل مساره برقمه ومهله
        $i->refresh();
        $this->assertSame('field_received', $i->status); // استلمه الفني بفتحه أعلاه (١١-١ ب)
        $ev = IncidentEvent::where('incident_id', $i->id)->where('action', 'emergency_triggered')->first();
        $this->assertNotNull($ev);
        $this->assertStringContainsString('فُعّلت الحالة الطارئة '.$e->incident_code, $ev->note);
        $this->assertSame($this->munawib->id, $ev->actor_id);
        // الشاغل يراه في التتبع بالرمز
        $this->get('/incident/track?code='.$i->secret_tracking_code)->assertOk()->assertSee('فُعّلت حالة طارئة بناءً على البلاغ')->assertSee($e->incident_code);
        // صفحة البلاغ تعرض الحالة المربوطة بلا زر ثانٍ؛ الحالة تعرض مصدرها
        $this->actingAs($this->munawib)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('فُعّلت من هذا البلاغ الحالة الطارئة')->assertSee($e->incident_code)->assertDontSee('id="emergencyModal"', false);
        $this->actingAs($this->munawib)->get("/app/emergency/incidents/{$e->id}/live")->assertOk()->assertSee('المصدر: بلاغ')->assertSee($i->code);
        // لا تفعيل ثانٍ والحالة مفتوحة
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/trigger-emergency", ['incident_type' => 'fire', 'severity' => 'high'])->assertSessionHas('error');
        $this->assertSame(1, EmergencyIncident::count());

        // البلاغ يكمل مساره: استُلم بالفتح، والفني يبدأ
        $this->assertNotNull($i->fresh()->field_received_at);
        $this->assertNotNull($i->fresh()->field_opened_at);
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/begin-work")->assertSessionHas('success');
        // تقرير الحالة يذكر البلاغ المرتبط
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$e->id}/end", ['final_report' => 'انتهى'])->assertRedirect();
        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$e->id}/report")->assertOk()->assertSee('بلاغ الشاغل المرتبط')->assertSee($i->code);
        // بلاغ مغلق لا يُفعَّل منه
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/out-of-scope", ['note' => 'اختبار']);
        $this->actingAs($this->salama)->post("/app/incidents/{$i->id}/trigger-emergency", ['incident_type' => 'fire', 'severity' => 'high'])->assertSessionHas('error');
    }

    public function test_normal_report_shows_operational_layer_and_tech_receives_by_opening(): void
    {
        $this->post('/incident/normal', ['description' => 'حرارة مرتفعة في المكتب', 'place_id' => Place::idByCode('HZ-06'), 'risk_id' => $this->physRisk->id, 'reporter_name' => 'سعد'])->assertRedirect();
        $i = Incident::first();
        $this->assertSame('forwarded', $i->status);
        $this->assertNull($i->field_received_at);
        $this->assertSame('نقل المصاب لمكان بارد', $i->corrective_action); // العادي يرث تصحيحي الطبقة التشغيلية
        $this->get('/incident/api/risks?sub_category_id='.$this->physRisk->sub_category_id)->assertOk()->assertJsonPath('0.corrective_action', 'نقل المصاب لمكان بارد')->assertJsonPath('0.preventive_action', 'ماء وظل وفترات راحة');
        $this->assertSame(['create', 'receive', 'refer', 'ref_receive', 'forward'], $i->events()->orderBy('id')->pluck('action')->all());

        // (ح-٢) الطبقة التشغيلية: الضوابط القائمة والإجراء التصحيحي — لا نص الاستجابة
        $r = $this->actingAs($this->munawib)->get("/app/incidents/{$i->id}");
        $r->assertOk()->assertSee('الطبقة التشغيلية')->assertSee('ماء وظل وفترات راحة')->assertSee('نقل المصاب لمكان بارد')->assertDontSee('تبريد واستدعاء الطبيب');
        // الزر متاح للمركز على أي بلاغ مفتوح (القرار بيده) والنوع المقترح «أخرى» لصنف فيزيائي
        $r->assertSee('trigger-emergency')->assertSee('<option value="other" selected>', false);
        // ١١-١ (د): خطورة الخطر ٥ ← «حرج» مقترحاً
        $this->physRisk->update(['severity' => 5]);
        $this->actingAs($this->munawib)->get("/app/incidents/{$i->id}")->assertOk()->assertSee('<option value="critical" selected>', false);

        // ١١-١ (ب، قرار ٣٤): فتح الفني المعيَّن للبلاغ = استلمه؛ غير المعيَّن لا يستلم بالفتح
        $this->actingAs($this->coord)->get("/app/incidents/{$i->id}")->assertOk();
        $this->assertSame('forwarded', $i->fresh()->status);
        $this->assertNull($i->fresh()->field_opened_at);
        $r = $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk();
        $i->refresh();
        $this->assertSame('field_received', $i->status);
        $this->assertNotNull($i->field_received_at);
        $this->assertNotNull($i->field_opened_at);
        $ev = IncidentEvent::where('incident_id', $i->id)->where('action', 'field_receive')->first();
        $this->assertSame($this->fani->id, $ev->actor_id);
        $this->assertStringContainsString('بفتح البلاغ', (string) $ev->note);
        $r->assertDontSee('استلمتُ البلاغ')->assertSee('عولج');
        // الفتح الثاني لا يكرر الاستلام
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}")->assertOk();
        $this->assertSame(1, IncidentEvent::where('incident_id', $i->id)->where('action', 'field_receive')->count());
    }

    /** المرحلة ١١-١ (ج): «عولج» بصورة في الطلب نفسه من «استلمه الفني» — الصورة تُرفق، والبدء يُسجَّل، ثم عولج. */
    public function test_resolve_with_inline_photo_from_field_received(): void
    {
        $this->post('/incident/normal', ['description' => 'حرارة مرتفعة في المكتب', 'place_id' => Place::idByCode('HZ-06'), 'risk_id' => $this->physRisk->id]);
        $i = Incident::first();
        $this->actingAs($this->fani)->get("/app/incidents/{$i->id}"); // = استلم
        $this->assertSame('field_received', $i->fresh()->status);
        $summary = 'نُقل الجهاز وضُبط التكييف وعادت الحرارة إلى طبيعتها';
        // بلا صورة ولا دليل سابق: يُرفض
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => $summary])->assertSessionHas('error');
        $this->assertSame('field_received', $i->fresh()->status);
        // بصورة في الطلب نفسه: تُرفق ← يبدأ ← عولج
        $this->actingAs($this->fani)->post("/app/incidents/{$i->id}/resolve", ['resolution_summary' => $summary,
            'evidence' => UploadedFile::fake()->createWithContent('after.png', base64_decode(self::PNG))])->assertSessionHas('success');
        $i->refresh();
        $this->assertSame('resolved', $i->status);
        $this->assertNotNull($i->in_progress_at);
        $this->assertSame(1, $i->attachments()->where('kind', 'evidence')->count());
        $this->assertSame(['field_receive', 'begin_work', 'resolve'],
            IncidentEvent::where('incident_id', $i->id)->whereIn('action', ['field_receive', 'begin_work', 'resolve'])->orderBy('id')->pluck('action')->all());
    }

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
}
