<?php

namespace Tests\Feature\Risk;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٢١ (المرحلة ٩): طبقة واحدة — السجل العام هو كتاب المعهد.
 * البذرة تكتب reference مباشرة؛ نموذج السجل العام يقبل العنوان والوصف وقناة الاتصال
 * والتفصيل لكل فئة متأثرة؛ «كتاب المخاطر» مخفي من القائمة؛ والمتأثرون تسع فئات (قرار ٢٤).
 */
class ReferenceSingleLayerTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    public function test_seeder_writes_reference_directly_without_master(): void
    {
        $this->seed(AffectedGroupsSeeder::class);
        $this->seed(RiskBookSeeder::class);

        $this->assertSame(0, Risk::where('risk_type', 'master')->count());
        $this->assertGreaterThan(0, Risk::where('risk_type', 'reference')->count());
        $this->assertSame(Risk::count(), Risk::where('risk_type', 'reference')->count());
    }

    public function test_affected_groups_are_nine_with_split_continuity_and_financial(): void
    {
        $this->seed(AffectedGroupsSeeder::class);
        $names = AffectedGroup::pluck('name')->all();

        $this->assertCount(9, $names);
        $this->assertContains('استمرارية الأعمال', $names);
        $this->assertContains('السمعة', $names);
        $this->assertContains('الخسائر المالية والقانونية', $names);
        $this->assertNotContains('استمرارية الأعمال والسمعة', $names);
    }

    public function test_seeder_renames_old_combined_group_instead_of_duplicating(): void
    {
        $old = AffectedGroup::create(['name' => 'استمرارية الأعمال والسمعة', 'name_en' => 'x', 'vulnerability_level' => 'medium']);
        $this->seed(AffectedGroupsSeeder::class);

        $this->assertSame('استمرارية الأعمال', $old->fresh()->name);
        $this->assertSame(9, AffectedGroup::count());
    }

    public function test_reference_form_saves_free_title_description_contact_and_affected_detail(): void
    {
        $this->seed(AffectedGroupsSeeder::class);
        $admin = $this->makeUser('system_admin');
        $cat = RiskCategory::create(['name' => 'الكيميائية', 'is_active' => true]);
        $group = AffectedGroup::where('name', 'الموظفون')->firstOrFail();

        $resp = $this->actingAs($admin)->post(route('risk.reference.store'), [
            'category_id' => $cat->id,
            'severity' => 4, 'likelihood' => 3,
            'title' => 'تراكم أول أكسيد الكربون من عوادم المركبات في مواقف القبو',
            'description' => 'المصدر: مركبات في حيز مغلق · الحدث: تراكم CO',
            'contact_channel' => 'مركز السلامة — مسؤول السلامة',
            'phases' => [
                RiskPhase::PHASE_PROACTIVE => [
                    'affected_group_ids' => [$group->id],
                    'affected_impact' => [$group->id => 'high'],
                    'affected_rep_scope' => [$group->id => 'local'],
                    'affected_detail' => [$group->id => 'الأمن والمواقف والفنيون'],
                ],
            ],
        ]);
        $resp->assertRedirect(route('risk.reference.index'));

        $risk = Risk::where('risk_type', 'reference')->latest('id')->firstOrFail();
        $this->assertSame('تراكم أول أكسيد الكربون من عوادم المركبات في مواقف القبو', $risk->title);
        $this->assertSame('المصدر: مركبات في حيز مغلق · الحدث: تراكم CO', $risk->description);
        $this->assertSame('مركز السلامة — مسؤول السلامة', $risk->contact_channel);

        $phase = $risk->phases()->where('phase', RiskPhase::PHASE_PROACTIVE)->firstOrFail();
        $detail = RiskPhaseAffectedGroupDetail::where('risk_phase_id', $phase->id)->where('affected_group_id', $group->id)->firstOrFail();
        $this->assertSame('high', $detail->impact);
        $this->assertSame('الأمن والمواقف والفنيون', $detail->impact_description);

        // شجرة السجل العام تُظهر التفصيل
        $json = $this->actingAs($admin)->getJson("/app/risk/registry/tree/reference/risk/{$risk->id}")->assertOk()->json();
        $groups = collect($json['phases'])->firstWhere('phase', RiskPhase::PHASE_PROACTIVE)['affected_groups'];
        $this->assertSame('الأمن والمواقف والفنيون', $groups[0]['detail']);
    }

    public function test_reference_title_falls_back_to_taxonomy_when_left_blank(): void
    {
        $admin = $this->makeUser('system_admin');
        $cat = RiskCategory::create(['name' => 'الفيزيائية', 'is_active' => true]);
        $sub = $cat->subCategories()->create(['name' => 'الضجيج']);

        $this->actingAs($admin)->post(route('risk.reference.store'), [
            'category_id' => $cat->id, 'sub_category_id' => $sub->id, 'severity' => 2, 'likelihood' => 2, 'title' => '',
        ])->assertRedirect(route('risk.reference.index'));

        $this->assertSame('الضجيج', Risk::where('risk_type', 'reference')->latest('id')->firstOrFail()->title);
    }

    public function test_master_book_link_is_hidden_from_menu(): void
    {
        $admin = $this->makeUser('system_admin');
        $html = $this->actingAs($admin)->get(route('risk.reference.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('>كتاب المخاطر<', $html);
        $this->assertStringContainsString('السجل العام للمعهد', $html);
    }
}
