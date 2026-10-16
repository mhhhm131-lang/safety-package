<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Models\ResponsePlanStep;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * المرحلة ٣٠-٢ (قرار ٨٩ «كلام المكان»): خطة الاستجابة — الخطوة المزروعة تصير قابلة للتعديل (مَن، أين، كيف، الهاتف، «لا ينطبق هنا»)
 * ويبقى متى والنافذة والمسار والعنوان للصنف؛ المزامنة تزرع الجديد ولا تمسّ المعدَّل؛ الطوارئ تقرأ صفوف المكان.
 *
 * البوابة (BACKEND.md ٧-٢، ٣٠-٢): الدمام يعدّل «مَن» في خطوة ← حالة طارئة تُفعَّل في قبو الدمام فتعرض الجديد ← الملز يعرض الأصل
 * ← المزامنة بعد التعديل لا تُرجعه ← الوثيقة لم تُمس.
 */
class PlaceResponsePlanWordsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;
    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $branchB;
    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(base_path());
        if (!is_file($this->root.'/HZ-01-basement/response-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة في المجلد الأعلى');
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]);
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => $this->bp('HZ-06')->id]);
        $this->salama = $this->user('salama', 'system_admin');
        app(ResponsePlanSync::class)->sync($this->root);
    }

    private function bp(string $cat): Place
    {
        return Place::where('building_id', $this->b->id)->where('category', $cat)->firstOrFail();
    }

    private function mp(string $cat): Place
    {
        return Place::where('building_id', $this->main->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    private function plan(Place $place): ResponsePlan
    {
        return ResponsePlan::where('place_id', $place->id)->with('steps')->firstOrFail();
    }

    /** خطوة بمسارها ورقمها كما في الوثيقة */
    private function step(Place $place, string $path, string $label): ResponsePlanStep
    {
        return $this->plan($place)->steps->where('path_key', $path)->where('label', $label)->first();
    }

    private function trigger(EmergencyBuilding $building, Place $place, string $type): EmergencyIncident
    {
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$building->id}/trigger", [
            'incident_type' => $type, 'severity' => 'high', 'place_id' => $place->id,
        ])->assertRedirect();
        return EmergencyIncident::orderByDesc('id')->first();
    }

    public function test_seed_keys_steps_and_resync_updates_title_without_touching_edited_words(): void
    {
        $dmm = $this->plan($this->bp('HZ-01'));
        $mlz = $this->plan($this->mp('HZ-01'));
        $this->assertSame($mlz->steps->count(), $dmm->steps->count(), 'الوثيقة نفسها للصنف في المبنيين');
        $this->assertSame(3, $dmm->steps->where('path_key', 'medical')->count());
        $this->assertSame(13, $dmm->steps->where('path_key', 'fire')->count());
        foreach ($dmm->steps as $s) {
            $this->assertNotEmpty($s->step_key, 'لكل خطوة مفتاح');
            $this->assertFalse($s->isEdited());
            $this->assertSame($s->who_text, $s->source['who_text'] ?? null, 'الأصل محفوظ مع الصف');
        }
        $this->assertSame($dmm->steps->count(), $dmm->steps->pluck('step_key')->unique()->count(), 'المفاتيح فريدة في الخطة');
        $this->assertSame(0, $dmm->edited_count);

        // لا تغيير ← لا إعادة قراءة؛ وبالقوة ← الصفوف نفسها بمعرّفاتها (لا حذف وإعادة زرع)
        $ids = $dmm->steps->pluck('id')->sort()->values()->all();
        $r = app(ResponsePlanSync::class)->sync($this->root);
        $this->assertSame('unchanged', $r[$this->bp('HZ-01')->code]['status']);
        // خطة زُرعت قبل ٣٠-٢ (صفوف بلا مفتاح ولا أصل، كما على المنشور) تُعاد قراءتها ولو لم تتغير بصمتها — كشفتها البوابة على المحلي
        ResponsePlanStep::where('plan_id', $dmm->id)->update(['step_key' => null, 'source' => null]);
        $r = app(ResponsePlanSync::class)->sync($this->root);
        $this->assertSame('synced', $r[$this->bp('HZ-01')->code]['status']);
        $legacy = $this->plan($this->bp('HZ-01'));
        $this->assertSame($dmm->steps->count(), $legacy->steps->count());
        foreach ($legacy->steps as $s) { $this->assertNotEmpty($s->step_key); $this->assertSame($s->who_text, $s->source['who_text'] ?? null); $this->assertSame([], $s->changedFields()); }
        $this->assertSame('unchanged', app(ResponsePlanSync::class)->sync($this->root)[$this->bp('HZ-01')->code]['status']);
        $ids = $legacy->steps->pluck('id')->sort()->values()->all();
        $r = app(ResponsePlanSync::class)->sync($this->root, true);
        $this->assertSame('synced', $r[$this->bp('HZ-01')->code]['status']);
        $this->assertSame($ids, $this->plan($this->bp('HZ-01'))->steps->pluck('id')->sort()->values()->all());

        // الدمام يعدّل «من» في خطوة الطبيب ← وثيقة معدَّلة (نسخة مؤقتة) تغيّر عنوان الخطوة نفسها: العنوان يتبع الوثيقة والكلام يبقى
        $doc = $this->step($this->bp('HZ-01'), 'medical', '٣');
        $this->assertNotNull($doc);
        $doc->applyWords(['who_text' => 'الإسعاف ٩٩٧ — من خارج الفرع'], false, $this->salama);
        $doc->save();
        $this->plan($this->bp('HZ-01'))->refreshCounts();
        $this->assertSame(1, $this->plan($this->bp('HZ-01'))->edited_count);

        $tmp = storage_path('framework/testing/response-'.uniqid());
        File::copyDirectory($this->root.'/HZ-01-basement', $tmp.'/HZ-01-basement');
        $copy = $tmp.'/HZ-01-basement/response-plan.html';
        $html = file_get_contents($copy);
        $this->assertStringContainsString('>'.$doc->title.'</div>', $html);
        file_put_contents($copy, str_replace('>'.$doc->title.'</div>', '>'.$doc->title.' — تعديل تجريبي</div>', $html));
        $r = app(ResponsePlanSync::class)->sync($tmp);
        $this->assertSame('synced', $r[$this->bp('HZ-01')->code]['status']);
        $this->assertSame('missing', $r['HZ-06']['status']);
        $after = $this->step($this->bp('HZ-01'), 'medical', '٣');
        $this->assertSame($doc->id, $after->id, 'الصف نفسه لا يُحذف');
        $this->assertSame($doc->title.' — تعديل تجريبي', $after->title, 'العنوان للصنف يتبع الوثيقة');
        $this->assertSame('الإسعاف ٩٩٧ — من خارج الفرع', $after->who_text, 'كلام المكان لا تكتبه المزامنة');
        $this->assertSame($doc->source['who_text'], $after->source['who_text']);
        $this->assertSame($doc->title.' — تعديل تجريبي', $this->step($this->mp('HZ-01'), 'medical', '٣')->title);
        $this->assertSame($doc->source['who_text'], $this->step($this->mp('HZ-01'), 'medical', '٣')->who_text);
        app(ResponsePlanSync::class)->sync($this->root);
        $this->assertSame($doc->title, $this->step($this->bp('HZ-01'), 'medical', '٣')->title);
        $this->assertSame('الإسعاف ٩٩٧ — من خارج الفرع', $this->step($this->bp('HZ-01'), 'medical', '٣')->who_text);
        File::deleteDirectory($tmp);
    }

    public function test_gate_dammam_edits_who_and_the_live_emergency_shows_it_while_malaz_keeps_the_original(): void
    {
        $dmmParking = $this->bp('HZ-01');
        $mlzParking = $this->mp('HZ-01');
        $file = $this->root.'/HZ-01-basement/response-plan.html';
        $hashBefore = sha1_file($file);
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $step = $this->step($dmmParking, 'medical', '٣');
        $originalWho = $step->who_text;
        $this->assertNotNull($originalWho);
        $originalCards = $step->role_cards;

        // الشاشة في ملف المكان: الخطوات بمساراتها، وللمنسق زر التعديل، ومتى والنافذة والعنوان بلا خانات
        $r = $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/response-plan");
        $r->assertOk()->assertSee($step->title)->assertSee($originalWho)->assertSee('المسار الطبي')->assertSee('لا ينطبق هنا')
            ->assertSee('/HZ-01-basement/response-plan.html')->assertSee('name="who_text"', false)->assertSee('name="phone_text"', false)
            ->assertDontSee('name="when_text"', false)->assertDontSee('name="title"', false);

        // يعدّل «من» والهاتف — العنوان و«متى» لا يُقبلان
        $r = $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/response-plan/{$step->id}", [
            'who_text' => 'الإسعاف ٩٩٧ من خارج المعهد', 'where_text' => $step->where_text, 'how_text' => $step->how_text,
            'phone_text' => '997', 'title' => 'عنوان مزوّر', 'when_text' => 'أبداً',
        ]);
        $r->assertRedirect("/app/places/{$dmmParking->id}/response-plan#s{$step->id}")->assertSessionHas('ok');
        $step->refresh();
        $this->assertSame('الإسعاف ٩٩٧ من خارج المعهد', $step->who_text);
        $this->assertSame('997', $step->phone_text);
        $this->assertNotSame('عنوان مزوّر', $step->title);
        $this->assertNotSame('أبداً', $step->when_text);
        $this->assertTrue($step->isEdited());
        $this->assertSame($coord->id, $step->edited_by_id);
        $this->assertSame(['who_text', 'phone_text'], $step->changedFields());
        $this->assertSame($originalWho, $step->source['who_text']);
        $this->assertSame([], $step->role_cards ?? [], 'بطاقات الدور تُستخرج من «من» الجديد — لا بطاقة للإسعاف الخارجي');
        $this->assertNull($step->role_card_no);
        $this->assertSame(1, $this->plan($dmmParking)->edited_count);
        $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/response-plan")->assertOk()
            ->assertSee('الإسعاف ٩٩٧ من خارج المعهد')->assertSee('997')->assertSee('عدّله اسم coord.dmm');

        // حالة طبية تُفعَّل في قبو الدمام ← القائمة الحية تحمل الكلام الجديد والهاتف
        $dmmInc = $this->trigger($this->b, $dmmParking, 'medical');
        $this->assertSame($dmmParking->id, $dmmInc->place_id);
        $live = $dmmInc->planSteps()->where('path_key', 'medical')->where('label', '٣')->firstOrFail();
        $this->assertSame('الإسعاف ٩٩٧ من خارج المعهد', $live->who_text);
        $this->assertSame('997', $live->phone_text);
        $this->assertNull($live->role_card_no);
        $this->assertSame($step->title, $live->title);
        $this->assertSame(3, $dmmInc->planSteps()->count());
        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$dmmInc->id}/live")->assertOk()
            ->assertSee('الإسعاف ٩٩٧ من خارج المعهد')->assertSee('tel:997', false);

        // الملز يعرض الأصل — في الشاشة وفي حالة تُفعَّل فيه
        $this->assertSame($originalWho, $this->step($mlzParking, 'medical', '٣')->who_text);
        $this->assertFalse($this->step($mlzParking, 'medical', '٣')->isEdited());
        $this->actingAs($this->salama)->get("/app/places/{$mlzParking->id}/response-plan")->assertOk()
            ->assertSee($originalWho)->assertDontSee('الإسعاف ٩٩٧');
        $mlzInc = $this->trigger($this->main, $mlzParking, 'medical');
        $mlzLive = $mlzInc->planSteps()->where('path_key', 'medical')->where('label', '٣')->firstOrFail();
        $this->assertSame($originalWho, $mlzLive->who_text);
        $this->assertSame($originalCards, $mlzLive->role_cards);
        $this->assertNull($mlzLive->phone_text);

        // المزامنة بعد التعديل — ولو بالقوة — لا تُرجع القديم؛ الوثيقة لم تُمس
        app(ResponsePlanSync::class)->sync($this->root, true);
        $this->assertSame('الإسعاف ٩٩٧ من خارج المعهد', $this->step($dmmParking, 'medical', '٣')->who_text);
        $this->assertSame($hashBefore, sha1_file($file), 'لا كتابة عكسية في الورقة');

        // سجل من عدّل وفي كذا — لمسؤول السلامة
        $log = AuditLog::where('model_name', 'ResponsePlanStep')->get();
        $this->assertCount(1, $log);
        $this->assertSame($coord->id, $log[0]->user_id);
        $this->assertStringContainsString('من:', $log[0]->description);
        $this->assertStringContainsString('الهاتف:', $log[0]->description);
        $this->actingAs($this->salama)->get("/app/places/{$dmmParking->id}/response-plan")->assertOk()->assertSee('سجل التعديلات')->assertSee('اسم coord.dmm');

        // حساب الملز لا يفتح شاشة الدمام ولا تعديلها؛ موظف الدمام يقرأ بلا نموذج ولا يعدّل؛ خطوة مكان آخر 404
        $malazCoord = $this->user('coord.mlz', 'safety_coordinator', null, $this->mp('HZ-06'));
        $this->actingAs($malazCoord)->get("/app/places/{$dmmParking->id}/response-plan")->assertForbidden();
        $this->actingAs($malazCoord)->post("/app/places/{$dmmParking->id}/response-plan/{$step->id}", ['who_text' => 'س'])->assertForbidden();
        $emp = $this->user('emp.dmm', 'employee', $this->b, $dmmParking);
        $this->actingAs($emp)->get("/app/places/{$dmmParking->id}/response-plan")->assertOk()->assertSee('الإسعاف ٩٩٧')->assertDontSee('name="who_text"', false);
        $this->actingAs($emp)->post("/app/places/{$dmmParking->id}/response-plan/{$step->id}", ['who_text' => 'س'])->assertForbidden();
        $mlzStep = $this->step($mlzParking, 'medical', '٣');
        $this->actingAs($this->salama)->post("/app/places/{$dmmParking->id}/response-plan/{$mlzStep->id}", ['who_text' => 'س'])->assertNotFound();
        $this->assertSame('الإسعاف ٩٩٧ من خارج المعهد', $step->fresh()->who_text);
        // مركز السلامة بلا خطة استجابة
        $this->actingAs($this->salama)->get('/app/places/'.$this->mp('HZ-00')->id.'/response-plan')->assertNotFound();
        auth()->logout();
        $this->get("/app/places/{$dmmParking->id}/response-plan")->assertRedirect('/login');
    }

    public function test_not_applicable_step_is_left_out_of_the_live_list_and_restore_brings_the_document_back(): void
    {
        $dmmParking = $this->bp('HZ-01');
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $fire = $this->plan($dmmParking)->steps->where('path_key', 'fire')->sortBy('sort')->values();
        $na = $fire[12]; // الخطوة ١٣ في مسار القبو
        $liveBefore = $this->plan($dmmParking)->steps_count;

        // «لا ينطبق هنا» بضغطة: الكلام كما هو، الصف موسوم ومعدود، ولا يدخل القائمة الحية
        $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/response-plan/{$na->id}", [
            'who_text' => $na->who_text, 'where_text' => $na->where_text, 'how_text' => $na->how_text, 'not_applicable' => '1',
        ])->assertRedirect();
        $na->refresh();
        $this->assertTrue($na->not_applicable);
        $this->assertTrue($na->isEdited());
        $this->assertSame([], $na->changedFields());
        $this->assertSame(1, $this->plan($dmmParking)->edited_count);
        $this->assertSame($liveBefore, $this->plan($dmmParking)->steps_count, 'عدّ خطوات الوثيقة لا يتغير');
        $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/response-plan")->assertOk()->assertSee('لا ينطبق هنا');

        $inc = $this->trigger($this->b, $dmmParking, 'fire');
        $this->assertSame(3 + 13 - 1, $inc->planSteps()->count(), 'الطبي ٣ + القبو ١٣ ناقص «لا ينطبق هنا»');
        $this->assertNull($inc->planSteps()->where('plan_step_id', $na->id)->first());
        $this->assertStringContainsString('15 خطوة', $inc->eventLogs()->where('event_type', 'plan_step')->where('message', 'like', 'قائمة خطوات الخطة:%')->value('message'));

        // الملز لا يتأثر
        $mlzInc = $this->trigger($this->main, $this->mp('HZ-01'), 'fire');
        $this->assertSame(16, $mlzInc->planSteps()->count());

        // استعادة الأصل بضغطة ← يعود إلى القائمة الحية
        $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/response-plan/{$na->id}/restore")->assertRedirect();
        $na->refresh();
        $this->assertFalse($na->not_applicable);
        $this->assertFalse($na->isEdited());
        $this->assertSame(0, $this->plan($dmmParking)->edited_count);
        $this->actingAs($this->salama)->post("/app/emergency/incidents/{$inc->id}/cancel", ['reason' => 'اختبار']);
        $inc2 = $this->trigger($this->b, $dmmParking, 'fire');
        $this->assertSame(16, $inc2->planSteps()->count());
        $this->assertSame(2, AuditLog::where('model_name', 'ResponsePlanStep')->count());
    }

    public function test_place_file_button_opens_the_system_screen_and_center_screen_shows_edits(): void
    {
        $mlzParking = $this->mp('HZ-01');
        $r = $this->actingAs($this->salama)->get("/app/places/{$mlzParking->id}/file");
        $r->assertOk()->assertSee("/app/places/{$mlzParking->id}/response-plan")->assertSee('16 خطوة');

        // مسؤول السلامة يعدّل في الملز ويسم خطوة «لا ينطبق» ← شاشة المركز (الخطط) تعرضهما
        $s = $this->step($mlzParking, 'fire', '١');
        $this->actingAs($this->salama)->post("/app/places/{$mlzParking->id}/response-plan/{$s->id}", ['who_text' => 'فريق الملز الأولي — تجربة', 'phone_text' => '0500000000'])->assertRedirect();
        $s2 = $this->step($mlzParking, 'fire', '٢');
        $this->actingAs($this->salama)->post("/app/places/{$mlzParking->id}/response-plan/{$s2->id}", ['not_applicable' => '1'])->assertRedirect();
        $this->actingAs($this->salama)->get('/app/emergency/plans/HZ-01')->assertOk()
            ->assertSee('فريق الملز الأولي — تجربة')->assertSee('0500000000')->assertSee('لا ينطبق هنا');
        $this->actingAs($this->salama)->get("/app/places/{$mlzParking->id}/file")->assertOk()->assertSee('2 معدّلة هنا');
    }
}
