<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\SafetyPlan;
use App\Modules\Governance\Models\SafetyPlanTask;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\SafetyPlanSync;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * المرحلة ٣٠-١ (قرار ٨٩ «كلام المكان»): خطة السلامة — الوثيقة تُقرأ مرة فتُزرع صفوف الجدولين لكل مكان في كل مبنى،
 * والشاشة تعدّل «المسؤول ومتى وكيف والتكرار والتوثيق» و«لا ينطبق هنا»، والمهمة لا تُعدَّل، والمزامنة لا تكتب فوق صف معدَّل.
 *
 * البوابة (BACKEND.md ٧-٢، المرحلة ٣٠-١): منسق قبو الدمام يعدّل «المسؤول» في مهمة ← ملف قبو الدمام يعرض الجديد
 * ← قبو الملز لم يتغير ← ملف `safety-plan.html` لم يتغير بصمةً ← إعادة الزرع لا تُعيد القديم ← حساب الملز لا يفتح تعديل الدمام (403).
 */
class PlaceSafetyPlanWordsTest extends TestCase
{
    use RefreshDatabase;

    /** صفوف الجدولين في الوثائق التسع كما هي (خلايا الجدول ÷ ٤) — تُقرأ ولا تُحسب */
    private const ROWS = ['HZ-00' => 12, 'HZ-01' => 10, 'HZ-02' => 10, 'HZ-03' => 8, 'HZ-04' => 9, 'HZ-05' => 7, 'HZ-06' => 7, 'HZ-07' => 7, 'HZ-08' => 7];

    private const HZ01_TASK1 = 'ضمان وجود وجاهزية أنظمة السلامة وربطها عبر لوحة التحكم وتقديم تقرير فني بجاهزيتها مع جرد كامل للأماكن المغطّاة';
    private const HZ01_TASK2_HOW = 'شراؤها وتوزيعها في أماكن معروفة ومحددة في القبو';

    private string $root;
    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $branchB;
    private OrganizationUnit $dmmOps;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(base_path());
        if (!is_file($this->root.'/HZ-01-basement/safety-plan.html')) $this->markTestSkipped('وثائق المعهد غير موجودة في المجلد الأعلى');
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]);
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => $this->bp('HZ-06')->id]);
        $this->dmmOps = OrganizationUnit::create(['code' => 'dmm-ops', 'name' => 'التشغيل', 'unit_type' => 'department', 'parent_id' => $this->branchB->id, 'place_id' => $this->bp('HZ-06')->id]);
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

    private function plan(Place $place): SafetyPlan
    {
        return SafetyPlan::where('place_id', $place->id)->with('tasks')->firstOrFail();
    }

    /** أول مهمة في الطبقة الاستباقية لخطة مكان */
    private function firstTask(Place $place, string $layer = 'proactive'): SafetyPlanTask
    {
        return $this->plan($place)->tasks->where('layer', $layer)->sortBy('sort')->first();
    }

    public function test_documents_are_read_once_into_rows_for_every_place_in_every_building(): void
    {
        $r = app(SafetyPlanSync::class)->sync($this->root);
        $this->assertCount(18, $r, 'تسعة أماكن في الملز وتسعة في الدمام');
        foreach ($r as $code => $x) $this->assertSame('synced', $x['status'], $code);
        $this->assertSame(18, SafetyPlan::count());
        $this->assertSame(2 * array_sum(self::ROWS), SafetyPlanTask::count());

        foreach (self::ROWS as $cat => $rows) {
            $plan = $this->plan($this->mp($cat));
            $this->assertSame($rows, $plan->tasks->count(), "$cat: صفوف الجدولين");
            $this->assertSame($rows, $plan->tasks_count);
            $this->assertSame(0, $plan->edited_count);
            foreach ($plan->tasks as $t) {
                $this->assertNotSame('', trim($t->task), "$cat: مهمة فارغة");
                $this->assertContains($t->layer, ['proactive', 'operational']);
                $this->assertFalse($t->isEdited());
            }
        }

        // قبو الملز: ٦ استباقية (المهمة، المسؤول، متى، كيف) + ٤ تشغيلية (المهمة، المسؤول، التكرار، التوثيق) كما في الورقة
        $hz01 = $this->plan($this->mp('HZ-01'));
        $this->assertSame(6, $hz01->proactive_count);
        $this->assertSame(4, $hz01->operational_count);
        $pro = $hz01->tasks->where('layer', 'proactive')->sortBy('sort')->values();
        $this->assertSame(self::HZ01_TASK1, $pro[0]->task);
        $this->assertSame('مدير المرافق والصيانة', $pro[0]->responsible);
        $this->assertSame('بأسرع وقت', $pro[0]->when_text);
        $this->assertStringStartsWith('التعاقد مع مكتب استشاري', $pro[0]->how_text);
        $this->assertNull($pro[0]->frequency);
        $this->assertSame(self::HZ01_TASK2_HOW, $pro[1]->how_text);
        $this->assertSame('شهري', $pro[5]->when_text);
        $op = $hz01->tasks->where('layer', 'operational')->sortBy('sort')->values();
        $this->assertSame('تنفيذ الفحوصات الدورية لكل الأنظمة والأدوات وصيانتها وتقديم تقاريرها', $op[0]->task);
        $this->assertSame('الفني المعتمد + الموظف المسؤول عن التقارير · تُعتمد من مدير المرافق', $op[0]->responsible);
        $this->assertSame('حسب التواتر المعتمد', $op[0]->frequency);
        $this->assertSame('سجل السلامة', $op[0]->documentation);
        $this->assertNull($op[0]->when_text);
        $this->assertSame('خطة الاستجابة في القبو', $op[2]->documentation);
        // الأصل محفوظ مع الصف
        $this->assertSame('مدير المرافق والصيانة', $pro[0]->source['responsible']);

        // قبو الدمام نسخة من الوثيقة نفسها
        $this->assertSame(self::HZ01_TASK1, $this->firstTask($this->bp('HZ-01'))->task);
        $this->assertSame('مدير المرافق والصيانة', $this->firstTask($this->bp('HZ-01'))->responsible);

        // لا تغيير ← لا إعادة قراءة
        $r = app(SafetyPlanSync::class)->sync($this->root);
        foreach ($r as $code => $x) $this->assertSame('unchanged', $x['status'], $code);
    }

    public function test_gate_dammam_coordinator_edits_responsible_and_malaz_and_document_stay(): void
    {
        $sync = app(SafetyPlanSync::class);
        $sync->sync($this->root);
        $dmmParking = $this->bp('HZ-01');
        $malazParking = $this->mp('HZ-01');
        $file = $this->root.'/HZ-01-basement/safety-plan.html';
        $hashBefore = sha1_file($file);

        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB); // منسق سلامة فرع الشرقية (قرار ٨١)
        $task = $this->firstTask($dmmParking);

        // الشاشة تعرض الجدول كما في الورقة، ولمنسق المكان زر التعديل
        $r = $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/safety-plan");
        $r->assertOk()->assertSee(self::HZ01_TASK1)->assertSee('مدير المرافق والصيانة')->assertSee('الطبقة الاستباقية')->assertSee('الطبقة التشغيلية')
            ->assertSee('سجل السلامة')->assertSee('لا ينطبق هنا')->assertSee('/HZ-01-basement/safety-plan.html');

        // يعدّل «المسؤول» في المهمة الأولى — المهمة نفسها لا تُرسل ولا تُقبل
        $r = $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/safety-plan/{$task->id}", [
            'responsible' => 'مدير فرع الدمام', 'when_text' => 'بأسرع وقت', 'how_text' => $task->how_text, 'task' => 'مهمة مزوّرة',
        ]);
        $r->assertRedirect("/app/places/{$dmmParking->id}/safety-plan#t{$task->id}")->assertSessionHas('ok');

        $task->refresh();
        $this->assertSame('مدير فرع الدمام', $task->responsible);
        $this->assertSame(self::HZ01_TASK1, $task->task, 'المهمة لا تُعدَّل');
        $this->assertSame('مدير المرافق والصيانة', $task->source['responsible'], 'الأصل محفوظ');
        $this->assertTrue($task->isEdited());
        $this->assertSame($coord->id, $task->edited_by_id);
        $this->assertSame(['responsible'], $task->changedFields());
        $this->assertSame(1, $this->plan($dmmParking)->edited_count);

        // ملف قبو الدمام يعرض الجديد ومن عدّله
        $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/safety-plan")->assertOk()
            ->assertSee('مدير فرع الدمام')->assertSee('عدّله اسم coord.dmm');

        // قبو الملز كما كان
        $this->assertSame('مدير المرافق والصيانة', $this->firstTask($malazParking)->responsible);
        $this->assertFalse($this->firstTask($malazParking)->isEdited());
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama)->get("/app/places/{$malazParking->id}/safety-plan")->assertOk()
            ->assertSee('مدير المرافق والصيانة')->assertDontSee('مدير فرع الدمام');

        // الوثيقة لم تُمس
        $this->assertSame($hashBefore, sha1_file($file), 'لا كتابة عكسية في الورقة');

        // إعادة الزرع — ولو بالقوة — لا تُعيد القديم
        $r = $sync->sync($this->root, true);
        $this->assertSame('synced', $r[$dmmParking->code]['status']);
        $this->assertSame('مدير فرع الدمام', $this->firstTask($dmmParking)->responsible);
        $this->assertSame(10, $this->plan($dmmParking)->tasks->count());

        // الوثيقة تتغير (نسخة مؤقتة): الصف غير المعدَّل يتبعها، والصف المعدَّل يحتفظ بكلامه ويحدّث أصله فقط
        $tmp = storage_path('framework/testing/safety-'.uniqid());
        File::copyDirectory($this->root.'/HZ-01-basement', $tmp.'/HZ-01-basement');
        $copy = $tmp.'/HZ-01-basement/safety-plan.html';
        $html = file_get_contents($copy);
        $this->assertStringContainsString(self::HZ01_TASK2_HOW, $html);
        $html = str_replace(self::HZ01_TASK2_HOW, self::HZ01_TASK2_HOW.' — تعديل تجريبي', $html);
        $html = str_replace('>مدير المرافق والصيانة</div>', '>مدير المرافق والصيانة — نسخة</div>', $html);
        file_put_contents($copy, $html);
        $r = $sync->sync($tmp);
        $this->assertSame('synced', $r[$dmmParking->code]['status']);
        $this->assertSame('missing', $r['HZ-06']['status']);
        $dmm = $this->plan($dmmParking)->tasks->where('layer', 'proactive')->sortBy('sort')->values();
        $this->assertSame('مدير فرع الدمام', $dmm[0]->responsible, 'المعدَّل لا تكتبه المزامنة');
        $this->assertSame('مدير المرافق والصيانة — نسخة', $dmm[0]->source['responsible'], 'أصله يتبع الوثيقة');
        $this->assertSame(self::HZ01_TASK2_HOW.' — تعديل تجريبي', $dmm[1]->how_text, 'غير المعدَّل يتبع الوثيقة');
        $this->assertSame('مدير المرافق والصيانة — نسخة', $dmm[1]->responsible);
        $this->assertSame('مدير المرافق والصيانة — نسخة', $this->firstTask($malazParking)->responsible);
        $this->assertSame($hashBefore, sha1_file($file));
        // العودة إلى الأصل
        $sync->sync($this->root);
        $this->assertSame('مدير فرع الدمام', $this->firstTask($dmmParking)->responsible);
        $this->assertSame('مدير المرافق والصيانة', $this->firstTask($malazParking)->responsible);
        $this->assertSame(self::HZ01_TASK2_HOW, $this->plan($dmmParking)->tasks->where('layer', 'proactive')->sortBy('sort')->values()[1]->how_text);
        File::deleteDirectory($tmp);

        // سجل من عدّل ومتى وفي كذا — يراه مسؤول السلامة
        $log = AuditLog::where('model_name', 'SafetyPlanTask')->get();
        $this->assertCount(1, $log);
        $this->assertSame($coord->id, $log[0]->user_id);
        $this->assertStringContainsString('المسؤول', $log[0]->description);
        $this->assertStringContainsString('مدير فرع الدمام', $log[0]->description);
        $this->actingAs($salama)->get("/app/places/{$dmmParking->id}/safety-plan")->assertOk()->assertSee('سجل التعديلات')->assertSee('اسم coord.dmm');

        // حساب الملز لا يفتح تعديل الدمام ولا شاشته (قرار ٨٣)
        $malazCoord = $this->user('coord.mlz', 'safety_coordinator', null, $this->mp('HZ-06'));
        $this->actingAs($malazCoord)->get("/app/places/{$dmmParking->id}/safety-plan")->assertForbidden();
        $this->actingAs($malazCoord)->post("/app/places/{$dmmParking->id}/safety-plan/{$task->id}", ['responsible' => 'س'])->assertForbidden();
        $this->assertSame('مدير فرع الدمام', $task->fresh()->responsible);
        // موظف الدمام يقرأ ولا يعدّل
        $emp = $this->user('emp.dmm', 'employee', $this->b, $dmmParking);
        $this->actingAs($emp)->get("/app/places/{$dmmParking->id}/safety-plan")->assertOk()->assertSee('مدير فرع الدمام')->assertDontSee('name="responsible"', false);
        $this->actingAs($emp)->post("/app/places/{$dmmParking->id}/safety-plan/{$task->id}", ['responsible' => 'س'])->assertForbidden();
        // مدير إدارة في الدمام مكانها المكاتب: يعدّل مكاتب الدمام لا قبوها
        $nasr = $this->user('nasr', 'department_manager', $this->b, $this->bp('HZ-06'), $this->dmmOps);
        $this->actingAs($nasr)->post("/app/places/{$dmmParking->id}/safety-plan/{$task->id}", ['responsible' => 'س'])->assertForbidden();
        $officeTask = $this->firstTask($this->bp('HZ-06'));
        $this->actingAs($nasr)->post("/app/places/{$this->bp('HZ-06')->id}/safety-plan/{$officeTask->id}", ['responsible' => 'مدير التشغيل'])->assertRedirect();
        $this->assertSame('مدير التشغيل', $officeTask->fresh()->responsible);
        // مهمة مكان آخر على رابط هذا المكان ← ٤٠٤
        $this->actingAs($salama)->post("/app/places/{$dmmParking->id}/safety-plan/{$officeTask->id}", ['responsible' => 'س'])->assertNotFound();
        $this->assertSame('مدير فرع الدمام', $task->fresh()->responsible);

        auth()->logout();
        $this->get("/app/places/{$dmmParking->id}/safety-plan")->assertRedirect('/login');
    }

    public function test_not_applicable_here_and_restore_from_document(): void
    {
        app(SafetyPlanSync::class)->sync($this->root);
        $dmmParking = $this->bp('HZ-01');
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $op = $this->firstTask($dmmParking, 'operational');

        // «لا ينطبق هنا» بضغطة: الكلام كما هو والصف موسوم ومعدود
        $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/safety-plan/{$op->id}", [
            'responsible' => $op->responsible, 'frequency' => $op->frequency, 'documentation' => $op->documentation, 'not_applicable' => '1',
        ])->assertRedirect();
        $op->refresh();
        $this->assertTrue($op->not_applicable);
        $this->assertTrue($op->isEdited());
        $this->assertSame([], $op->changedFields());
        $this->assertSame(1, $this->plan($dmmParking)->edited_count);
        $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/safety-plan")->assertOk()->assertSee('لا ينطبق هنا');
        $this->assertFalse($this->firstTask($this->mp('HZ-01'), 'operational')->not_applicable);

        // تعديل التكرار ثم استعادة الأصل من الوثيقة بضغطة
        $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/safety-plan/{$op->id}", [
            'responsible' => $op->responsible, 'frequency' => 'أسبوعي', 'documentation' => $op->documentation,
        ])->assertRedirect();
        $op->refresh();
        $this->assertSame('أسبوعي', $op->frequency);
        $this->assertFalse($op->not_applicable);
        $this->assertSame(['frequency'], $op->changedFields());

        $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/safety-plan/{$op->id}/restore")->assertRedirect();
        $op->refresh();
        $this->assertSame('حسب التواتر المعتمد', $op->frequency);
        $this->assertFalse($op->isEdited());
        $this->assertNull($op->edited_by_id);
        $this->assertSame(0, $this->plan($dmmParking)->edited_count);

        // الحفظ بالكلام الأصلي نفسه لا يَسِم الصف معدَّلاً
        $this->actingAs($coord)->post("/app/places/{$dmmParking->id}/safety-plan/{$op->id}", [
            'responsible' => $op->responsible, 'frequency' => $op->frequency, 'documentation' => $op->documentation,
        ])->assertRedirect();
        $this->assertFalse($op->fresh()->isEdited());
        $this->assertSame(3, AuditLog::where('model_name', 'SafetyPlanTask')->count(), 'ثلاث كتابات غيّرت شيئاً');
    }

    public function test_place_file_button_opens_the_system_screen_and_counts_rows(): void
    {
        app(SafetyPlanSync::class)->sync($this->root);
        $salama = $this->user('salama', 'system_admin');
        $malazParking = $this->mp('HZ-01');
        $r = $this->actingAs($salama)->get("/app/places/{$malazParking->id}/file");
        $r->assertOk()->assertSee("/app/places/{$malazParking->id}/safety-plan")->assertSee('10 مهام');

        // مكان لم تُزرع خطته بعد (مبنى جديد): فتح ملفه يزرعها
        $c = EmergencyBuilding::create(['code' => 'MKK', 'name' => 'فرع مكة', 'branch' => 'فرع مكة']);
        Place::createCategoriesFor($c);
        $mkkParking = Place::where('building_id', $c->id)->where('category', 'HZ-01')->firstOrFail();
        $this->assertNull(SafetyPlan::where('place_id', $mkkParking->id)->first());
        $this->actingAs($salama)->get("/app/places/{$mkkParking->id}/file")->assertOk()->assertSee('10 مهام');
        $this->assertSame(10, $this->plan($mkkParking)->tasks->count());

        // الأمر يزرع الكل (يعمل عند كل نشر)
        $this->artisan('ipa:sync-plans', ['--from' => $this->root])->assertSuccessful();
        $this->assertSame(27, SafetyPlan::count());
    }
}
