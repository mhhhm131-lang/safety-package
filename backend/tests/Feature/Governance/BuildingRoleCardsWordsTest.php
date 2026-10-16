<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\BuildingRoleCard;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Emergency\Services\ResponsePlanSync;
use App\Modules\Emergency\Services\RoleCardSync;
use App\Modules\Emergency\Support\RoleCards;
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
 * المرحلة ٣٠-٣ (قرار ٨٩ «كلام المكان»): بطاقات الأدوار — صف لكل بطاقة لكل مبنى (الاسم، الوصف، المهام، الشاغل والهاتف)
 * يُزرع من ملفات `role-cards/` والقواعد مرة؛ شاشة البطاقات في مبنى الجلسة بـ«تعديل»؛ خطوات الاستجابة تشير إلى بطاقة المبنى.
 *
 * البوابة (BACKEND.md ٧-٢، ٣٠-٣): الدمام يعدّل بطاقة المسعف (هاتفها ومهمة) ← خطوات الدمام تعرضها ← بطاقة الملز كما هي
 * ← ملفات `role-cards/` لم تُمس.
 */
class BuildingRoleCardsWordsTest extends TestCase
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
        if (!is_file($this->root.'/role-cards/response-team/role-09.html')) $this->markTestSkipped('ملفات البطاقات غير موجودة في المجلد الأعلى');
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
        app(RoleCardSync::class)->sync($this->root);
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

    private function card(EmergencyBuilding $b, int $no): BuildingRoleCard
    {
        return BuildingRoleCard::where('building_id', $b->id)->where('card_no', $no)->firstOrFail();
    }

    /** بصمة ملفات البطاقات الـ٢١ معاً */
    private function filesHash(): string
    {
        $h = '';
        foreach (RoleCards::CARDS as $no => $c) $h .= sha1_file($this->root.RoleCards::url($no));
        return sha1($h);
    }

    private function trigger(EmergencyBuilding $building, Place $place, string $type): EmergencyIncident
    {
        $this->actingAs($this->salama)->post("/app/emergency/buildings/{$building->id}/trigger", [
            'incident_type' => $type, 'severity' => 'high', 'place_id' => $place->id,
        ])->assertRedirect();
        return EmergencyIncident::orderByDesc('id')->first();
    }

    public function test_cards_are_seeded_from_files_once_for_every_building(): void
    {
        $this->assertSame(42, BuildingRoleCard::count(), '٢١ بطاقة لكل مبنى من المبنيين');
        $m = $this->card($this->main, 9);
        $this->assertSame('المسعف', $m->name);
        $this->assertSame('لكل إدارة أو قسم أو فريق', $m->description);
        $this->assertSame('منسق القسم + مركز السلامة', $m->reports_to);
        $this->assertSame('م١٣/٩', $m->basis);
        $this->assertSame(['حصر الفريق والاحتياجات الصحية', 'الاستعداد الدائم لتقديم الإسعافات', 'التنسيق مع المنقذين لنقل المصاب ووضع خطة'], $m->lines('tasks_proactive'));
        $this->assertSame(['التأكد من وجود أدوات الإسعافات وفحصها ومتابعتها'], $m->lines('tasks_operational'));
        $this->assertSame('الانتقال السريع للمصاب', $m->lines('tasks_response')[0]);
        $this->assertCount(4, $m->lines('tasks_response'));
        $this->assertNull($m->holder_name);
        $this->assertFalse($m->isEdited());
        $this->assertSame($m->name, $m->source['name']);
        // الاسم من الملف (الوثيقة هي الحقيقة) ولو اختلف عن المختصر في الكود
        $this->assertSame('المشرف العام للسلامة وقائد فريق الطوارئ والإخلاء', $this->card($this->b, 1)->name);
        $this->assertSame('أفراد الأمن المكلفون بأدوار السلامة', $this->card($this->b, 5)->name);
        foreach (RoleCards::CARDS as $no => $c) {
            $d = $this->card($this->b, $no);
            $this->assertNotSame('', trim($d->name), "بطاقة $no بلا اسم");
            $this->assertNotEmpty($d->lines('tasks_response'), "بطاقة $no بلا مهام استجابة");
            $this->assertSame($c['category'], $d->category);
        }
        // لا تغيير ← لا إعادة قراءة
        $r = app(RoleCardSync::class)->sync($this->root);
        $this->assertSame('unchanged', $r[$this->b->code]['status']);
        $this->assertSame(21, $r[$this->b->code]['cards']);
        // البطاقة في مبناها تحمل كلامها فوق الثابت في الكود
        $c9 = RoleCards::forBuilding($this->b->id, 9);
        $this->assertSame(9, $c9['no']);
        $this->assertSame('المسعف', $c9['name']);
        $this->assertSame('/role-cards/response-team/role-09.html', $c9['url']);
        $this->assertSame(['medic'], $c9['team']);
        $this->assertNull($c9['holder_name']);
    }

    public function test_gate_dammam_edits_the_medic_card_and_its_steps_show_it_while_malaz_and_files_stay(): void
    {
        $hashBefore = $this->filesHash();
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $dmmParking = $this->bp('HZ-01');
        $card = $this->card($this->b, 9);

        // شاشة بطاقات مبنى الجلسة: منسق الفرع يرى الدمام بزر التعديل
        $r = $this->actingAs($coord)->get('/app/role-cards');
        $r->assertOk()->assertSee('المسعف')->assertSee($this->b->name)->assertSee('name="holder_phone"', false)->assertSee('/role-cards/response-team/role-09.html');

        // يعدّل هاتف المسعف وشاغله ومهمة في الاستجابة — الرقم والفئة والملف لا تُقبل
        $tasks = implode("\n", array_merge($card->lines('tasks_response'), ['نقل المصاب إلى مستشفى الدمام المركزي (لا عيادة في الفرع)']));
        $r = $this->actingAs($coord)->post("/app/role-cards/{$card->id}", [
            'name' => $card->name, 'description' => $card->description, 'tasks_proactive' => $card->tasks_proactive, 'tasks_operational' => $card->tasks_operational,
            'tasks_response' => $tasks, 'holder_name' => 'سعد المسعف — الدمام', 'holder_phone' => '0551234567', 'card_no' => 4, 'category' => 'leadership',
        ]);
        $r->assertRedirect("/app/role-cards?b={$this->b->id}#c{$card->id}")->assertSessionHas('ok');
        $card->refresh();
        $this->assertSame(9, $card->card_no);
        $this->assertSame('response-team', $card->category);
        $this->assertSame('سعد المسعف — الدمام', $card->holder_name);
        $this->assertSame('0551234567', $card->holder_phone);
        $this->assertCount(5, $card->lines('tasks_response'));
        $this->assertTrue($card->isEdited());
        $this->assertSame($coord->id, $card->edited_by_id);
        $this->assertSame(['tasks_response', 'holder_name', 'holder_phone'], $card->changedFields());
        $this->assertCount(4, explode("\n", $card->source['tasks_response']));
        $this->actingAs($coord)->get('/app/role-cards')->assertOk()->assertSee('سعد المسعف — الدمام')->assertSee('tel:0551234567', false)->assertSee('عدّله اسم coord.dmm');

        // خطوات الدمام تشير إلى بطاقة المبنى: شاشة الخطة والقائمة الحية تعرضان الشاغل وهاتفه
        $this->actingAs($coord)->get("/app/places/{$dmmParking->id}/response-plan")->assertOk()
            ->assertSee('سعد المسعف — الدمام')->assertSee('tel:0551234567', false);
        $inc = $this->trigger($this->b, $dmmParking, 'medical');
        $live = $inc->planSteps()->where('path_key', 'medical')->where('label', '١')->firstOrFail();
        $this->assertContains(9, $live->role_cards);
        $this->assertSame('سعد المسعف — الدمام', $live->cards()[9]['holder_name']);
        $this->actingAs($this->salama)->get("/app/emergency/incidents/{$inc->id}/live")->assertOk()
            ->assertSee('سعد المسعف — الدمام')->assertSee('tel:0551234567', false);

        // بطاقة الملز كما هي — في الصف وفي الشاشة وفي حالة تُفعَّل في الملز
        $mlz9 = $this->card($this->main, 9);
        $this->assertNull($mlz9->holder_name);
        $this->assertFalse($mlz9->isEdited());
        $this->assertCount(4, $mlz9->lines('tasks_response'));
        $this->assertNull(RoleCards::forBuilding($this->main->id, 9)['holder_name']);
        $this->actingAs($this->salama)->get('/app/role-cards')->assertOk()->assertSee($this->main->name)->assertDontSee('سعد المسعف — الدمام');
        $this->actingAs($this->salama)->get('/app/places/'.$this->mp('HZ-01')->id.'/response-plan')->assertOk()->assertDontSee('سعد المسعف — الدمام');
        $mlzInc = $this->trigger($this->main, $this->mp('HZ-01'), 'medical');
        $this->assertNull($mlzInc->planSteps()->where('path_key', 'medical')->where('label', '١')->firstOrFail()->cards()[9]['holder_name']);
        // مسؤول السلامة يبدّل مبنى الجلسة فيرى بطاقات الدمام بتعديلها وسجل التعديلات
        $this->actingAs($this->salama)->post("/app/building/{$this->b->id}")->assertRedirect();
        $this->actingAs($this->salama)->get('/app/role-cards')->assertOk()->assertSee('سعد المسعف — الدمام')->assertSee('سجل التعديلات')->assertSee('اسم coord.dmm');

        // المزامنة بعد التعديل — ولو بالقوة — لا تُرجع القديم؛ الملفات لم تُمس
        app(RoleCardSync::class)->sync($this->root, true);
        $this->assertSame('سعد المسعف — الدمام', $this->card($this->b, 9)->holder_name);
        $this->assertSame($hashBefore, $this->filesHash(), 'لا كتابة عكسية في ملفات البطاقات');
        $log = AuditLog::where('model_name', 'BuildingRoleCard')->get();
        $this->assertCount(1, $log);
        $this->assertSame($coord->id, $log[0]->user_id);
        $this->assertStringContainsString('الهاتف', $log[0]->description);

        // منسق إدارة في الملز لا يعدّل بطاقات مبنى ولا بطاقات الدمام؛ موظف الدمام يقرأ بلا نموذج؛ مدير فرع الدمام يعدّل
        $malazCoord = $this->user('coord.mlz', 'safety_coordinator', null, $this->mp('HZ-06'));
        $this->actingAs($malazCoord)->get('/app/role-cards')->assertOk()->assertDontSee('name="holder_phone"', false);
        $this->actingAs($malazCoord)->post("/app/role-cards/{$card->id}", ['holder_phone' => '1'])->assertForbidden();
        $this->actingAs($malazCoord)->post('/app/role-cards/'.$mlz9->id, ['holder_phone' => '1'])->assertForbidden();
        $emp = $this->user('emp.dmm', 'employee', $this->b, $dmmParking);
        $this->actingAs($emp)->get('/app/role-cards')->assertOk()->assertSee('سعد المسعف — الدمام')->assertDontSee('name="holder_phone"', false);
        $this->actingAs($emp)->post("/app/role-cards/{$card->id}", ['holder_phone' => '1'])->assertForbidden();
        $farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $this->actingAs($farea)->post("/app/role-cards/{$card->id}", ['holder_phone' => '0559999999'])->assertRedirect();
        $this->assertSame('0559999999', $card->fresh()->holder_phone);
        $this->assertNull($card->fresh()->source['holder_phone'] ?? null, 'الأصل من الملف بلا هاتف');
        auth()->logout();
        $this->get('/app/role-cards')->assertRedirect('/login');
    }

    public function test_not_applicable_card_leaves_readiness_and_restore_brings_the_file_back(): void
    {
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $doctor = $this->card($this->b, 4);
        $plan = ResponsePlan::where('place_id', $this->bp('HZ-01')->id)->with('steps')->firstOrFail();
        $this->assertContains(4, $plan->unstaffedCards(collect(), []), 'لا طبيب بحساب ← البطاقة ٤ بلا شاغل');

        // «لا ينطبق هنا» على بطاقة الطبيب في الدمام: تخرج من «دور بلا شاغل» وتبقى في الملز
        $this->actingAs($coord)->post("/app/role-cards/{$doctor->id}", ['not_applicable' => '1'])->assertRedirect();
        $doctor->refresh();
        $this->assertTrue($doctor->not_applicable);
        $this->assertTrue($doctor->isEdited());
        $this->assertSame([], $doctor->changedFields());
        $this->assertNotContains(4, $plan->fresh()->unstaffedCards(collect(), []));
        $mlzPlan = ResponsePlan::where('place_id', $this->mp('HZ-01')->id)->firstOrFail();
        $this->assertContains(4, $mlzPlan->unstaffedCards(collect(), []));
        $this->assertTrue(RoleCards::forBuilding($this->b->id, 4)['not_applicable']);
        $this->actingAs($coord)->get('/app/role-cards')->assertOk()->assertSee('لا ينطبق هنا');

        // استعادة الأصل بضغطة
        $this->actingAs($coord)->post("/app/role-cards/{$doctor->id}/restore")->assertRedirect();
        $doctor->refresh();
        $this->assertFalse($doctor->not_applicable);
        $this->assertFalse($doctor->isEdited());
        $this->assertContains(4, $plan->fresh()->unstaffedCards(collect(), []));
        $this->assertSame(2, AuditLog::where('model_name', 'BuildingRoleCard')->count());
    }

    public function test_doors_and_deploy_command(): void
    {
        $this->actingAs($this->salama)->get('/app/roles')->assertOk()->assertSee('/app/role-cards');
        $this->actingAs($this->salama)->get('/app/places/'.$this->mp('HZ-01')->id.'/response-plan')->assertOk()->assertSee('/app/role-cards');
        // مبنى جديد: الأمر يزرع بطاقاته عند النشر، وفتح الشاشة يزرعها كذلك
        $c = EmergencyBuilding::create(['code' => 'MKK', 'name' => 'فرع مكة', 'branch' => 'فرع مكة']);
        $this->assertSame(0, BuildingRoleCard::where('building_id', $c->id)->count());
        $this->artisan('ipa:sync-plans', ['--from' => $this->root])->assertSuccessful();
        $this->assertSame(63, BuildingRoleCard::count());
    }
}
