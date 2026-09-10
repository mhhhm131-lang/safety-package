<?php

namespace Tests\Feature\Risk;

use App\Core\Services\CloseoutService;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use App\Modules\Risk\Models\RiskSubCategory;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OhsmsRiskBookSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Database\Seeders\RiskControlsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٩ (٩-١-ب): كتاب المعهد يُبذر من الشجرة المعتمدة (٨/٤٩/١٧٧)، ويُستبدل على قاعدة فيها
 * كتاب OHSMS القديم من شاشة الإغلاق، والسجل الفعلي والتفعيل يحملان الحقول الجديدة.
 */
class InstituteBookTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    public function test_seeded_tree_matches_the_approved_book(): void
    {
        $this->seed([AffectedGroupsSeeder::class, RiskBookSeeder::class]);

        $this->assertSame(['الفيزيائية (عوامل البيئة)', 'الكيميائية', 'البيولوجية والصحية', 'الميكانيكية والإنشائية', 'الكهربائية', 'الحريق والانفجار', 'الإرجونومية', 'التنظيمية والعوامل البشرية'],
            RiskCategory::orderBy('id')->pluck('name')->all());
        $this->assertSame(49, RiskSubCategory::count());
        $this->assertSame(177, Risk::where('risk_type', 'reference')->where('status', 'approved')->count());
        $this->assertSame(0, Risk::whereNull('sub_category_id')->count());
        $this->assertSame(21, Risk::where('code', 'like', 'PH-%')->count());
        $this->assertSame(31, Risk::where('code', 'like', 'ME-%')->count());
        $fire = RiskCategory::where('name', 'الحريق والانفجار')->firstOrFail();
        $this->assertSame(7, $fire->subCategories()->count());
        $this->assertSame('تراكم أول أكسيد الكربون من عوادم المركبات في مواقف القبو', Risk::where('code', 'CH-01-01')->value('title'));
    }

    public function test_replace_book_swaps_old_ohsms_tree_for_institute_book(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, OhsmsRiskBookSeeder::class, RiskControlsSeeder::class]);
        $this->assertSame(264, Risk::where('risk_type', 'reference')->count());
        $this->assertSame(9, RiskCategory::count());
        $groupsBefore = AffectedGroup::count();

        $status = app(CloseoutService::class)->replaceBook();

        $this->assertSame(8, $status['الأصناف الرئيسية']);
        $this->assertSame(49, $status['الفروع']);
        $this->assertSame(177, $status['مخاطر السجل العام']);
        $this->assertSame(0, $status['منها بأكواد أخرى (OHSMS أو مضافة يدوياً)']);
        $this->assertSame(0, Risk::where('code', 'like', 'MR-%')->count());
        $this->assertSame($groupsBefore, AffectedGroup::count()); // المتأثرون لا يُمسّون
        $this->assertGreaterThan(0, \DB::table('risk_controls')->count()); // بنود التحكم أُعيد بذرها على الأصناف الجديدة
    }

    public function test_replace_book_is_refused_while_operational_work_points_to_risks(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, OhsmsRiskBookSeeder::class]);
        $ref = Risk::where('risk_type', 'reference')->firstOrFail();
        Incident::create(['code' => 'ش-0001', 'title' => 'اختبار', 'incident_type' => 'urgent', 'status' => 'new', 'risk_id' => $ref->id]);

        $this->expectException(\RuntimeException::class);
        app(CloseoutService::class)->replaceBook();
    }

    public function test_closeout_screen_shows_book_status_and_replace_button(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, OhsmsRiskBookSeeder::class]);
        $admin = $this->makeUser('system_admin');

        $html = $this->actingAs($admin)->get('/app/closeout')->assertOk()->getContent();
        $this->assertStringContainsString('data-book="منها بأكواد أخرى (OHSMS أو مضافة يدوياً)"', $html);
        $this->assertStringContainsString('استبدل الكتاب بكتاب المعهد', $html);

        $this->actingAs($admin)->post('/app/closeout/book-replace', ['confirm' => 'خطأ'])->assertSessionHasErrors('confirm');
        $this->actingAs($admin)->post('/app/closeout/book-replace', ['confirm' => 'استبدل'])->assertSessionHas('success');
        $this->assertSame(177, Risk::where('risk_type', 'reference')->count());
    }

    public function test_activation_carries_title_description_and_affected_detail_into_active_registry(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class, RiskBookSeeder::class]);
        $ref = Risk::where('code', 'PH-01-01')->firstOrFail();
        $group = AffectedGroup::where('name', 'الموظفون')->firstOrFail();
        $phase = $ref->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->firstOrFail();
        $phase->affectedGroups()->sync([$group->id]);
        RiskPhaseAffectedGroupDetail::create(['risk_phase_id' => $phase->id, 'affected_group_id' => $group->id, 'impact' => 'high', 'rep_scope' => 'local', 'impact_description' => 'الأمن والمواقف']);
        $ref->update(['description' => 'المصدر: حرارة الصيف', 'contact_channel' => 'مركز السلامة']);

        $manager = $this->makeUser('department_manager', 'hr');
        $unitId = $this->orgUnit('hr')->id;
        $this->actingAs($manager)->post("/app/risk/{$ref->id}/activate", [
            'scope_type' => 'org_unit', 'organization_unit_id' => $unitId, 'severity' => 4, 'likelihood' => 3,
            'title' => 'ضربة الحرارة لحراس بوابة الموارد البشرية', 'description' => 'المصدر: حرارة الصيف — بوابة HR',
        ])->assertRedirect(route('risk.active.index'));

        $active = Risk::where('risk_type', 'active')->where('parent_reference_id', $ref->id)->firstOrFail();
        $this->assertSame('ضربة الحرارة لحراس بوابة الموارد البشرية', $active->title);
        $this->assertSame('المصدر: حرارة الصيف — بوابة HR', $active->description);
        $this->assertSame('مركز السلامة', $active->contact_channel); // نُسخت من المرجعي
        $ap = $active->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->firstOrFail();
        $this->assertSame('الأمن والمواقف', RiskPhaseAffectedGroupDetail::where('risk_phase_id', $ap->id)->value('impact_description'));

        // ثم يعدّل المنسق تفصيل المتأثرين واسم الخطر من لوحة السجل الفعلي
        $this->actingAs($manager)->post("/app/risk/active/{$active->id}/edit", [
            'category_id' => $ref->category_id, 'sub_category_id' => $ref->sub_category_id, 'severity' => 4, 'likelihood' => 2,
            'title' => 'ضربة الحرارة — بوابة الموارد البشرية', 'scope_type' => 'org_unit', 'organization_unit_id' => $unitId,
            'phases' => [RiskPhase::PHASE_PROACTIVE => ['affected_group_ids' => [$group->id], 'affected_impact' => [$group->id => 'critical'], 'affected_detail' => [$group->id => 'حارسا البوابة الشمالية']]],
        ])->assertRedirect(route('risk.active.index'));
        $this->assertSame('ضربة الحرارة — بوابة الموارد البشرية', $active->fresh()->title);
        $this->assertSame('حارسا البوابة الشمالية', RiskPhaseAffectedGroupDetail::where('risk_phase_id', $ap->id)->value('impact_description'));
    }
}
