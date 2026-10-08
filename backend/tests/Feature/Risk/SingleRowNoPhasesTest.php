<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskEvent;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use App\Modules\Risk\Support\PhaseMerger;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * خطة المعالج — الخطوة ٦ (بكلمته ٢٠٢٦-١٠-٠٧ «الأطوار الثلاثة في سجل المخاطر تلغى ويؤخذ محتواها… لا داعي لوجود قبل أثناء بعد»):
 *   الخطر صف واحد: الأسباب قائمة واحدة والمكرّر مرة؛ الوقائي والتصحيحي نص واحد بترقيم متصل؛ المتأثرون مرة بأعلى أثر؛ لا نص يُحذف.
 *   الدمج في البذر (FI-06-01 من ملفات الكتاب: ١٦+٨+٣ سبباً، سبعة مكرّرة ← قائمة واحدة) وفي ترحيل الصفوف القائمة.
 *   البذر يعمل في كل نشر ولا يكتب فوق ما عدّله شخص من الشاشة (الملاحظة ٤٦). النماذج بلا ألسنة، والبلاغ يأخذ التصحيحي الواحد.
 */
class SingleRowNoPhasesTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    public function test_the_merger_keeps_everything_once_and_renumbers(): void
    {
        $m = PhaseMerger::merge([
            ['preventive_action' => '(١) رشاشات. (٢) أبواب مقاومة.', 'corrective_action' => 'عند عطل: إصلاح', 'residual_assessment' => 'تنخفض إلى ٥',
                'responsible_org_unit_text' => '٢ مدير المرافق · ١٦ فني الإنذار', 'causes' => ['تخزين في القبو', 'تدخين', 'حر الصيف'],
                'affected' => [['name' => 'الموظفون', 'impact' => '5'], ['name' => 'السمعة', 'impact' => '4', 'rep_scope' => 'national']]],
            ['preventive_action' => '(١) الفحص الشهري. (٢) جولة الحارس.', 'corrective_action' => 'عند عطل: إصلاح', 'residual_assessment' => 'تبقى عند ١',
                'responsible_org_unit_text' => '٥ أفراد الأمن · ١٦ فني الإنذار', 'causes' => ['تدخين', ' تخزين  في القبو', 'مركبة في ممر الإطفاء'],
                'affected' => [['name' => 'الموظفون', 'impact' => '3', 'detail' => 'عمال المواقف'], ['name' => 'السمعة', 'impact' => '5']]],
            ['preventive_action' => '(١) اكتشاف. (٢) إنذار. (٣) تدخل.', 'corrective_action' => 'تحقيق مع الدفاع المدني', 'residual_assessment' => null,
                'notes' => 'الجهة والشخص كاملاً: ٢١ مناوب المركز · ١١ الإطفائي', 'responsible_org_unit_text' => '٢١ مناوب المركز · ١١ الإطف',
                'causes' => ['دخان يعمي'], 'affected' => [['name' => 'الفريق الأولي', 'impact' => '4']]],
        ]);
        $this->assertSame("(١) رشاشات. (٢) أبواب مقاومة.\n(٣) الفحص الشهري. (٤) جولة الحارس.\n(٥) اكتشاف. (٦) إنذار. (٧) تدخل.", $m['preventive_action']);
        $this->assertSame("عند عطل: إصلاح\nتحقيق مع الدفاع المدني", $m['corrective_action']); // المكرّر مرة
        $this->assertSame("تنخفض إلى ٥\nتبقى عند ١", $m['residual_assessment']);
        $this->assertSame(['تخزين في القبو', 'تدخين', 'حر الصيف', 'مركبة في ممر الإطفاء', 'دخان يعمي'], $m['causes']);
        $this->assertSame('٢ مدير المرافق · ١٦ فني الإنذار · ٥ أفراد الأمن · ١٦ فني الإنذار · ٢١ مناوب المركز · ١١ الإطفائي', $m['responsible_org_unit_text']);
        $groups = collect($m['affected'])->keyBy('name');
        $this->assertSame('5', $groups['الموظفون']['impact']);
        $this->assertSame('عمال المواقف', $groups['الموظفون']['detail']);
        $this->assertSame('5', $groups['السمعة']['impact']);
        $this->assertSame('national', $groups['السمعة']['rep_scope']);
        $this->assertCount(3, $m['affected']);
    }

    public function test_the_book_seeder_writes_one_row_per_risk_merged_from_the_three_book_phases(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, RiskBookSeeder::class]);
        $r = Risk::where('risk_type', 'reference')->where('code', 'FI-06-01')->firstOrFail();
        $this->assertSame(1, $r->phases()->count(), 'الخطر ليس صفاً واحداً');
        $p = $r->phases()->with(['causes', 'affectedGroups'])->firstOrFail();
        $this->assertSame(RiskPhase::PHASE_SINGLE, $p->phase);
        // الأسباب في ملف الكتاب أسطر (كل سطر مجموعة أسباب): ٥ + ٤ + ٣ = ١٢ سطراً مختلفاً، كلها في الصف الواحد ولا سطر مكرّر
        $n = $p->causes->count();
        $this->assertSame(12, $n);
        $this->assertSame($n, $p->causes->pluck('name')->unique()->count());
        $this->assertContains('بيئية: حر الصيف يرفع حرارة القبو', $p->causes->pluck('name')->all());
        // الوقائي: نصوص الأطوار الثلاثة كلها، بترقيم متصل — لا نص حُذف
        $this->assertStringContainsString('حماية القبو', $p->preventive_action);
        $this->assertStringContainsString('الفحص الشهري', $p->preventive_action);
        $this->assertStringContainsString('اكتشاف: دخان', $p->preventive_action);
        $this->assertSame(1, substr_count($p->preventive_action, '(١)'), 'الترقيم يبدأ مرتين');
        $this->assertStringContainsString('(٢٠)', $p->preventive_action);
        $this->assertStringContainsString('تحقيق مع الدفاع المدني', $p->corrective_action);
        // المتأثرون مرة واحدة (تسع مجموعات في كل طور)
        $this->assertSame($p->affectedGroups->count(), $p->affectedGroups->pluck('name')->unique()->count());
        $this->assertGreaterThanOrEqual(9, $p->affectedGroups->count());
        // من يطبّق الضوابط: أدوار الأطوار الثلاثة بلا تكرار، كاملاً في الملاحظات
        $this->assertStringStartsWith('الجهة والشخص كاملاً: ', (string) $p->notes);
        $this->assertStringContainsString('٢١ مناوب مركز السلامة', (string) $p->notes);
        $this->assertSame(0, RiskPhase::whereIn('phase', RiskPhase::LEGACY_PHASES)->count(), 'بقيت صفوف أطوار');
    }

    public function test_the_seeder_fills_empty_fields_but_never_overwrites_a_risk_someone_edited(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, RiskBookSeeder::class]);
        $r = Risk::where('risk_type', 'reference')->where('code', 'FI-06-01')->firstOrFail();
        $salama = $this->makeUser('system_admin');
        // مسؤول السلامة عدّل من الشاشة: العنوان والتصحيحي والأسباب
        $this->actingAs($salama)->post(route('risk.reference.update', $r), ['category_id' => $r->category_id, 'sub_category_id' => $r->sub_category_id,
            'severity' => 5, 'likelihood' => 1, 'title' => 'اشتعال مركبة في القبو (عُدّل)',
            'phases' => [RiskPhase::PHASE_SINGLE => ['corrective_action' => 'تصحيحي كتبه مسؤول السلامة', 'cause_names' => ['سبب واحد كتبه مسؤول السلامة']]]])->assertRedirect();
        $this->assertTrue(RiskEvent::where('risk_id', $r->id)->where('action', 'modified')->exists());

        (new RiskBookSeeder)->applyTables(); // النشر التالي
        $r->refresh();
        $p = $r->phases()->with('causes')->firstOrFail();
        $this->assertSame('اشتعال مركبة في القبو (عُدّل)', $r->title);
        $this->assertSame(5, $r->severity);
        $this->assertSame('تصحيحي كتبه مسؤول السلامة', $p->corrective_action);
        $this->assertSame(['سبب واحد كتبه مسؤول السلامة'], $p->causes->pluck('name')->all());
        $this->assertStringContainsString('حماية القبو', $p->preventive_action, 'الوقائي لم يُمس فبقي من الكتاب');

        // وخطر لم يعدّله أحد يأخذ الكتاب في كل نشر
        $other = Risk::where('risk_type', 'reference')->where('code', 'FI-06-02')->firstOrFail();
        $other->phases()->first()->update(['corrective_action' => 'كُتب بغير الشاشة']);
        (new RiskBookSeeder)->applyTables();
        $this->assertNotSame('كُتب بغير الشاشة', $other->phases()->first()->corrective_action);
    }

    public function test_the_migration_merges_existing_three_phase_rows_into_one(): void
    {
        $this->seed([PlacesSeeder::class, AffectedGroupsSeeder::class]);
        $cat = $this->makeCategory(); $sub = $this->makeSubCategory($cat);
        $r = Risk::create(['risk_type' => 'reference', 'title' => 'خطر قديم بأطوار', 'description' => 'x', 'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 3, 'likelihood' => 2, 'status' => 'approved']);
        $g = AffectedGroup::first();
        foreach ([['proactive', 'أ', '(١) أول.', 'ص١'], ['operational', 'ب', '(١) ثانٍ.', 'ص١'], ['response', 'ج', '(١) ثالث.', 'ص٢']] as [$key, $cause, $prev, $corr]) {
            $p = RiskPhase::create(['risk_id' => $r->id, 'phase' => $key, 'preventive_action' => $prev, 'corrective_action' => $corr, 'responsible_org_unit_text' => 'دور '.$key]);
            $p->causes()->sync([RiskCause::firstOrCreate(['name' => $cause])->id, RiskCause::firstOrCreate(['name' => 'مشترك'])->id]);
            $p->affectedGroups()->sync([$g->id]);
            DB::table('risk_phase_affected_group_details')->insert(['risk_phase_id' => $p->id, 'affected_group_id' => $g->id, 'impact' => $key === 'response' ? '5' : '2']);
        }
        $this->assertSame(3, $r->phases()->count());

        (require database_path('migrations/2026_10_08_100003_merge_risk_phases_into_one.php'))->up();

        $this->assertSame(1, $r->phases()->count());
        $p = $r->phases()->with(['causes', 'affectedGroupDetails'])->firstOrFail();
        $this->assertSame(RiskPhase::PHASE_SINGLE, $p->phase);
        $this->assertSame("(١) أول.\n(٢) ثانٍ.\n(٣) ثالث.", $p->preventive_action);
        $this->assertSame("ص١\nص٢", $p->corrective_action);
        $this->assertSame(['أ', 'مشترك', 'ب', 'ج'], $p->causes->pluck('name')->all());
        $this->assertSame('دور proactive · دور operational · دور response', $p->responsible_org_unit_text);
        $this->assertSame('5', $p->affectedGroupDetails->first()->impact);
        $this->assertSame(0, DB::table('risk_phase_causes')->whereNotIn('risk_phase_id', [$p->id])->count(), 'بقيت أسباب لصفوف محذوفة');

        // يعمل مرة ثانية بلا أثر
        (require database_path('migrations/2026_10_08_100003_merge_risk_phases_into_one.php'))->up();
        $this->assertSame(1, $r->phases()->count());
        $this->assertSame("(١) أول.\n(٢) ثانٍ.\n(٣) ثالث.", $r->phases()->first()->preventive_action);
    }

    public function test_forms_have_one_panel_and_reports_take_the_single_corrective_action(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'is_active' => true, 'created_at' => now()]);
        $sub = RiskSubCategory::create(['category_id' => $cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
        $salama = $this->makeUser('system_admin');
        $this->actingAs($salama)->post(route('risk.reference.store'), ['category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 3, 'likelihood' => 2, 'title' => 'غطاء مقبس مكسور',
            'phases' => [RiskPhase::PHASE_SINGLE => ['preventive_action' => 'أغطية سليمة', 'corrective_action' => 'فصل التيار وتغيير الغطاء', 'cause_names' => ['تلف', 'عبث']]]])->assertRedirect();
        $r = Risk::where('title', 'غطاء مقبس مكسور')->firstOrFail();
        $this->assertSame(1, $r->phases()->count());
        $this->assertSame('فصل التيار وتغيير الغطاء', $r->phases()->first()->corrective_action);
        $this->assertSame(['تلف', 'عبث'], $r->phases()->first()->causes->pluck('name')->all());

        foreach ([route('risk.reference.create'), route('risk.reference.edit', $r), route('risk.activate.form', $r)] as $url) {
            $html = (string) $this->actingAs($salama)->get($url)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, 'class="tab-pane'), "$url: أكثر من لسان");
            $this->assertStringContainsString('name="phases[single][preventive_action]"', $html, $url);
            foreach (['استباقي', 'phases[proactive]', 'phases[operational]', 'phases[response]', 'phase-proactive', 'phase-response'] as $old) { // («استجابة» كلمة عامة في القالب: خطط الاستجابة)
                $this->assertStringNotContainsString($old, $html, "{$url}: بقي «{$old}»");
            }
        }
        // تفاصيل الخطر: صف واحد، وأعمدة بلا «الطور»
        $j = $this->actingAs($salama)->getJson(url('app/risk/registry/tree/reference/risk/'.$r->id))->assertOk()->json();
        $this->assertCount(1, $j['phases']);
        $this->assertSame('فصل التيار وتغيير الغطاء', $j['corrective_action']);
        $this->assertSame(['تلف', 'عبث'], array_column($j['causes'], 'name'));
        $index = (string) $this->actingAs($salama)->get(route('risk.reference.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<th>الطور</th>', $index);
        $this->assertStringNotContainsString('الطور</th>', $index);

        // البلاغ العادي والعاجل يأخذان التصحيحي الواحد
        $emp = $this->makeUser('employee', 'hr');
        $this->actingAs($emp)->post('/incident/normal', ['description' => 'غطاء مكسور في الممر', 'risk_id' => $r->id, 'place_id' => \App\Modules\Governance\Models\Place::idByCode('HZ-06')])->assertRedirect();
        $this->assertSame('فصل التيار وتغيير الغطاء', \App\Modules\Incident\Models\Incident::latest('id')->first()->corrective_action);
        $this->actingAs($emp)->post('/incident/urgent', ['description' => 'شرر من المقبس الآن', 'risk_id' => $r->id, 'place_id' => \App\Modules\Governance\Models\Place::idByCode('HZ-06')])->assertRedirect();
        $this->assertSame('فصل التيار وتغيير الغطاء', \App\Modules\Incident\Models\Incident::latest('id')->first()->corrective_action);
        // نموذج البلاغ يعرض الإجراءين من الصف الواحد
        $api = $this->actingAs($emp)->getJson('/incident/api/risks?sub_category_id='.$sub->id);
        if ($api->getStatusCode() === 200) $this->assertSame('أغطية سليمة', collect($api->json())->firstWhere('id', $r->id)['preventive_action']);
    }
}
