<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\BuildingComplianceItem;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Services\ComplianceSync;
use App\Modules\Governance\Models\AuditLog;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٣١-١ (قرار ٩٠ «المقارنة لكل مبنى»): وثيقة المقارنة (١٦ مادة + ٣٣ بنداً تحت ١٢ و١٣ و١٤) تُقرأ مرة فتُزرع ٤٩ صفاً لكل مبنى؛
 * نص اللائحة وما أعدّه مسؤول السلامة للصنف؛ الحالة والوضع الحالي والإجراء والمكلّف والموعد والملاحظات كلام المبنى؛
 * الجاهزية على ٤٦ صفاً قابلاً للتقييم (المواد ١٢ و١٣ و١٤ تُقرأ من بنودها).
 *
 * البوابة (BACKEND.md ٧-٢، ٣١-١): الدمام يجعل المادة ٨ «قيد الإعداد» بمكلّف وموعد والبند ١٣-٤ مستوفى ← جاهزية الدمام تتغير
 * والمادة ١٣ «مستوفى ١ من ١٢» ← الملز كما كان ← الوثيقة لم تتغير بصمةً ← إعادة الزرع لا تُعيد القديم ← منسق إدارة 403.
 */
class BuildingComplianceWordsTest extends TestCase
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
        if (!is_file($this->root.'/comparison.html')) $this->markTestSkipped('وثيقة المقارنة غير موجودة في المجلد الأعلى');
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        Place::whereNull('building_id')->update(['building_id' => $this->main->id]);
        $hq = OrganizationUnit::create(['code' => 'hq', 'name' => 'المركز الرئيسي', 'unit_type' => 'company']);
        $this->main->update(['branch_unit_id' => $hq->id]);
        $this->branchB = OrganizationUnit::create(['code' => 'br-dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'region', 'parent_id' => $hq->id]);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'معهد الادارة فرع الدمام', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branchB->id]);
        Place::createCategoriesFor($this->b);
        $this->branchB->update(['place_id' => Place::where('building_id', $this->b->id)->where('category', 'HZ-06')->value('id')]);
        $this->salama = $this->user('salama', 'system_admin');
        app(ComplianceSync::class)->sync($this->root);
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    private function row(EmergencyBuilding $b, string $key): BuildingComplianceItem
    {
        return BuildingComplianceItem::where('building_id', $b->id)->where('key', $key)->firstOrFail();
    }

    public function test_document_is_read_once_into_49_rows_per_building(): void
    {
        $this->assertSame(98, BuildingComplianceItem::count(), '٤٩ صفاً لكل مبنى من المبنيين');
        $dmm = BuildingComplianceItem::where('building_id', $this->b->id)->get();
        $this->assertSame(16, $dmm->whereNull('item_no')->count());
        $this->assertSame(33, $dmm->whereNotNull('item_no')->count());
        $this->assertSame(['12' => 11, '13' => 12, '14' => 10], $dmm->whereNotNull('item_no')->groupBy('article_no')->map->count()->sortKeys()->mapWithKeys(fn ($n, $k) => [(string) $k => $n])->all());
        $this->assertSame(49, $dmm->pluck('key')->unique(null, true)->count(), 'المفاتيح نصوص: «12.1» و«12.10» مختلفان (المقارنة الصارمة)');

        $a1 = $this->row($this->b, '1');
        $this->assertSame('التعاريف', $a1->title);
        $this->assertStringStartsWith('اثنا عشر تعريفاً', $a1->requirement);
        $this->assertNull($a1->current_state, 'الوضع الراهن «—» في الوثيقة = لا نص');
        $this->assertStringStartsWith('اعتُمد هذا التمييز', $a1->prepared);
        $a8 = $this->row($this->b, '8');
        $this->assertSame('المسؤولية الإشرافية', $a8->title);
        $this->assertStringStartsWith('كل شيءٍ يُرفع لرئيس القسم', $a8->current_state);
        $i = $this->row($this->b, '13.4');
        $this->assertSame(13, $i->article_no);
        $this->assertSame(4, $i->item_no);
        $this->assertSame('إنقاذ غير القادرين', $i->title);
        $this->assertSame('لا يوجد.', $i->current_state);
        $this->assertStringStartsWith('مُنقذٌ وبديلٌ', $i->prepared);
        $this->assertNull($i->requirement, 'البند بلا نص لائحة مستقل — عنوانه هو المطلوب');
        $this->assertSame('المسؤولية الإشرافية', $this->row($this->main, '8')->title);
        foreach ($dmm as $r) {
            $this->assertSame(BuildingComplianceItem::UNRATED, $r->status, "$r->key يبدأ غير مقيَّم");
            $this->assertFalse($r->isEdited());
            $this->assertSame($r->current_state, $r->source['current_state'] ?? null);
        }
        // المواد ١٢ و١٣ و١٤ لها بنود فلا تُقيَّم بنفسها
        $this->assertTrue($this->row($this->b, '13')->hasItems());
        $this->assertFalse($this->row($this->b, '8')->hasItems());

        // الجاهزية قبل أي تقييم: ٤٦ قابلة للتقييم، صفر مستوفى، ٤٦ لم تُقيَّم
        $r = BuildingComplianceItem::readiness($this->b->id);
        $this->assertSame(['ratable' => 46, 'met' => 0, 'in_progress' => 0, 'not_met' => 0, 'na' => 0, 'unrated' => 46, 'percent' => 0], $r);

        // لا تغيير ← لا إعادة قراءة
        $s = app(ComplianceSync::class)->sync($this->root);
        $this->assertSame('unchanged', $s[$this->b->code]['status']);
        $this->assertSame(49, $s[$this->b->code]['rows']);
    }

    public function test_gate_dammam_rates_and_malaz_and_document_stay(): void
    {
        $file = $this->root.'/comparison.html';
        $hashBefore = sha1_file($file);
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $a8 = $this->row($this->b, '8');
        $i134 = $this->row($this->b, '13.4');

        // الشاشة لمبنى الجلسة: المواد والبنود، الجاهزية صفر، أزرار التعديل، رابط الوثيقة
        $r = $this->actingAs($coord)->get('/app/compliance');
        $r->assertOk()->assertSee($this->b->name)->assertSee('المسؤولية الإشرافية')->assertSee('إنقاذ غير القادرين')->assertSee('/comparison.html')
            ->assertSee('name="status"', false)->assertSee('لم يُقيَّم 46');

        // المادة ٨: قيد الإعداد بمكلّف وموعد وإجراء — نص اللائحة والعنوان لا يُقبلان
        $r = $this->actingAs($coord)->post("/app/compliance/{$a8->id}", [
            'status' => 'in_progress', 'current_state' => $a8->current_state, 'action' => 'اعتماد صلاحيات مسؤول السلامة في الفرع',
            'holder_name' => 'مدير فرع الدمام', 'due_date' => '2026-12-31', 'notes' => 'ينتظر خطاب الفرع', 'requirement' => 'مزوّر', 'title' => 'مزوّر',
        ]);
        $r->assertRedirect("/app/compliance?b={$this->b->id}#r{$a8->id}")->assertSessionHas('ok');
        $a8->refresh();
        $this->assertSame('in_progress', $a8->status);
        $this->assertSame('المسؤولية الإشرافية', $a8->title);
        $this->assertStringStartsWith('مسؤول السلامة مسؤولٌ', $a8->requirement);
        $this->assertSame('مدير فرع الدمام', $a8->holder_name);
        $this->assertSame('2026-12-31', $a8->due_date->toDateString());
        $this->assertTrue($a8->isEdited());
        $this->assertSame($coord->id, $a8->edited_by_id);
        $this->assertSame(['status', 'action', 'holder_name', 'due_date', 'notes'], $a8->changedFields());
        // البند ١٣-٤ مستوفى
        $this->actingAs($coord)->post("/app/compliance/{$i134->id}", ['status' => 'met', 'current_state' => 'منقذ وبديل في كل إدارة بالفرع'])->assertRedirect();
        $i134->refresh();
        $this->assertSame('met', $i134->status);
        $this->assertSame(['status', 'current_state'], $i134->changedFields());

        // الجاهزية تغيّرت والمادة ١٣ تقول «مستوفى ١ من ١٢»
        $rd = BuildingComplianceItem::readiness($this->b->id);
        $this->assertSame(1, $rd['met']);
        $this->assertSame(1, $rd['in_progress']);
        $this->assertSame(44, $rd['unrated']);
        $this->assertSame(2, $rd['percent']); // ١ من ٤٦ = ٢٪
        $this->assertSame(['met' => 1, 'total' => 12, 'na' => 0], $this->row($this->b, '13')->itemsSummary());
        $r = $this->actingAs($coord)->get('/app/compliance');
        $r->assertOk()->assertSee('مدير فرع الدمام')->assertSee('قيد الإعداد')->assertSee('مستوفى 1 من 12')->assertSee('عدّله اسم coord.dmm')->assertSee('2%');

        // الملز كما كان
        $this->assertSame(BuildingComplianceItem::UNRATED, $this->row($this->main, '8')->status);
        $this->assertSame('لا يوجد.', $this->row($this->main, '13.4')->current_state);
        $this->assertSame(0, BuildingComplianceItem::readiness($this->main->id)['met']);
        $this->actingAs($this->salama)->get('/app/compliance')->assertOk()->assertSee($this->main->name)->assertDontSee('مدير فرع الدمام');
        // مسؤول السلامة يبدّل إلى الدمام فيرى التعديل والسجل
        $this->actingAs($this->salama)->get("/app/compliance?b={$this->b->id}")->assertOk()->assertSee('مدير فرع الدمام')->assertSee('سجل التعديلات')->assertSee('اسم coord.dmm');

        // الوثيقة لم تُمس؛ المزامنة بالقوة لا تُرجع القديم
        app(ComplianceSync::class)->sync($this->root, true);
        $this->assertSame('in_progress', $this->row($this->b, '8')->status);
        $this->assertSame('met', $this->row($this->b, '13.4')->status);
        $this->assertSame($hashBefore, sha1_file($file), 'لا كتابة عكسية في الوثيقة');
        $log = AuditLog::where('model_name', 'BuildingComplianceItem')->get();
        $this->assertCount(2, $log);
        $this->assertSame($coord->id, $log[0]->user_id);
        $this->assertStringContainsString('الحالة', $log[0]->description);

        // مادة لها بنود لا تُقيَّم بنفسها؛ منسق إدارة في الملز لا يعدّل؛ موظف الدمام يقرأ بلا نموذج؛ مدير الفرع يعدّل
        $this->actingAs($coord)->post('/app/compliance/'.$this->row($this->b, '13')->id, ['status' => 'met'])->assertStatus(422);
        $malazCoord = $this->user('coord.mlz', 'safety_coordinator', null, Place::where('building_id', $this->main->id)->where('category', 'HZ-06')->first());
        $this->actingAs($malazCoord)->get('/app/compliance')->assertOk()->assertDontSee('name="status"', false);
        $this->actingAs($malazCoord)->post("/app/compliance/{$a8->id}", ['status' => 'met'])->assertForbidden();
        $this->actingAs($malazCoord)->post('/app/compliance/'.$this->row($this->main, '8')->id, ['status' => 'met'])->assertForbidden();
        $emp = $this->user('emp.dmm', 'employee', $this->b, Place::where('building_id', $this->b->id)->where('category', 'HZ-01')->first());
        $this->actingAs($emp)->get('/app/compliance')->assertOk()->assertSee('مدير فرع الدمام')->assertDontSee('name="status"', false);
        $this->actingAs($emp)->post("/app/compliance/{$a8->id}", ['status' => 'met'])->assertForbidden();
        $farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $this->actingAs($farea)->post("/app/compliance/{$a8->id}", ['status' => 'met'])->assertRedirect();
        $this->assertSame('met', $a8->fresh()->status);
        auth()->logout();
        $this->get('/app/compliance')->assertRedirect('/login');
    }

    public function test_not_applicable_leaves_the_denominator_and_restore_brings_the_document_back(): void
    {
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $a3 = $this->row($this->b, '3');
        $this->actingAs($coord)->post("/app/compliance/{$a3->id}", ['status' => 'na', 'notes' => 'لا مكتب هندسي في الفرع'])->assertRedirect();
        $rd = BuildingComplianceItem::readiness($this->b->id);
        $this->assertSame(1, $rd['na']);
        $this->assertSame(45, $rd['unrated']);
        $this->actingAs($coord)->post('/app/compliance/'.$this->row($this->b, '2')->id, ['status' => 'met'])->assertRedirect();
        $this->assertSame(2, BuildingComplianceItem::readiness($this->b->id)['percent']); // ١ من (٤٦−١)
        $this->assertSame(0, BuildingComplianceItem::readiness($this->main->id)['na']);

        $this->actingAs($coord)->post("/app/compliance/{$a3->id}/restore")->assertRedirect();
        $a3->refresh();
        $this->assertSame(BuildingComplianceItem::UNRATED, $a3->status);
        $this->assertNull($a3->notes);
        $this->assertFalse($a3->isEdited());
        $this->assertSame(0, BuildingComplianceItem::readiness($this->b->id)['na']);
        // الحفظ بالكلام الأصلي نفسه لا يَسِم الصف
        $this->actingAs($coord)->post("/app/compliance/{$a3->id}", ['status' => 'unrated', 'current_state' => $a3->current_state])->assertRedirect();
        $this->assertFalse($a3->fresh()->isEdited());
        $this->assertSame(3, AuditLog::where('model_name', 'BuildingComplianceItem')->count());
    }

    public function test_doors_readiness_in_building_file_and_deploy_command(): void
    {
        $this->actingAs($this->salama)->get("/app/emergency/buildings/{$this->main->id}")->assertOk()->assertSee('/app/compliance')->assertSee('لم يُقيَّم 46');
        $this->actingAs($this->salama)->get('/app/role-cards')->assertOk()->assertSee('/app/compliance');
        $this->actingAs($this->salama)->get('/app/compliance')->assertOk()->assertSee('/app/role-cards');
        $c = EmergencyBuilding::create(['code' => 'MKK', 'name' => 'فرع مكة', 'branch' => 'فرع مكة']);
        $this->assertSame(0, BuildingComplianceItem::where('building_id', $c->id)->count());
        $this->artisan('ipa:sync-plans', ['--from' => $this->root])->assertSuccessful();
        $this->assertSame(147, BuildingComplianceItem::count());
    }
}
