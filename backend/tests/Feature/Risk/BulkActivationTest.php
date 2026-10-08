<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Governance\Models\AppNotification;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskCopyService;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * قرار ٧١ (بكلمته ٢٠٢٦-١٠-٠٣: «نقل مخاطر كثيرة من السجل العام إلى إدارة بضغطة واحدة… تحديد الكل… أو تحديد» ثم «موافق»):
 * المحدَّد من السجل العام يُفعَّل دفعةً لوحدة واحدة بمعالج واحد؛ منسقه منسق الوحدة تلقائياً؛ الموجود يُتخطّى ويُقال؛
 * والاعتماد بقرار ٧٠ — غير المعتمد ينتظر، والمعتمد تصله بطاقة واحدة «اعتمدها كلها»؛ والمدير يفعّل فوراً.
 */
class BulkActivationTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskCategory $cat;
    private RiskSubCategory $sub;
    /** @var Risk[] */
    private array $refs = [];
    private OrganizationUnit $hr;
    private OrganizationUnit $it;
    private User $salama;
    private User $coord;
    private User $mudir;
    private User $other;
    private User $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->hr = $this->orgUnit('hr');
        $this->it = $this->orgUnit('it');
        $this->cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'ELC', 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الصعق', 'abbreviation' => 'SHK']);
        foreach (['صعق من مقبس تالف', 'تحميل زائد على التوصيلة', 'سلك مكشوف تحت المكتب'] as $t) $this->refs[] = $this->reference($t);
        $this->salama = $this->makeUser('system_admin');
        $this->coord = $this->makeUser('safety_coordinator', 'hr');
        $this->mudir = $this->makeUser('department_manager', 'hr');
        $this->other = $this->makeUser('department_manager', 'it');
        $this->handler = $this->makeUser('tech_electrical');
    }

    private function reference(string $title, bool $approved = true): Risk
    {
        $m = app(RiskService::class)->createRisk(null, ['title' => $title, 'description' => 'x', 'category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id, 'severity' => 3, 'likelihood' => 3], 'master');
        $m->update(['status' => 'approved']);
        $r = app(RiskCopyService::class)->masterToReference($m->fresh(), null);
        if ($approved) $r->update(['status' => 'approved']);
        return $r->fresh();
    }

    private function ids(): array
    {
        return array_map(fn (Risk $r) => $r->id, $this->refs);
    }

    /** ضغطة «فعّل» في نافذة الدفعة */
    private function bulk(User $by, array $ids, ?OrganizationUnit $unit, array $extra = [])
    {
        return $this->actingAs($by)->postJson(route('risk.activate.bulk'), $extra + [
            'risk_ids' => $ids, 'scope_type' => $unit ? 'org_unit' : 'general', 'organization_unit_id' => $unit?->id,
            'assigned_field_team_id' => $this->handler->id,
        ]);
    }

    private function registered(): \Illuminate\Support\Collection
    {
        return Risk::where('risk_type', 'active')->orderBy('id')->get();
    }

    public function test_coordinator_activates_many_at_once_and_the_manager_approves_them_with_one_press(): void
    {
        $this->bulk($this->coord, $this->ids(), $this->hr)->assertOk()->assertJson(['created' => 3, 'existing' => 0, 'unapproved' => 0, 'awaiting' => true]);
        $risks = $this->registered();
        $this->assertCount(3, $risks);
        foreach ($risks as $r) {
            $this->assertSame('pending_approval', $r->status);
            $this->assertSame($this->hr->id, $r->organization_unit_id);
            $this->assertSame($this->coord->id, $r->assigned_coordinator_id, 'منسق الخطر ليس منسق الوحدة');
            $this->assertSame($this->handler->id, $r->assigned_field_team_id);
            $this->assertSame(1, $r->phases()->count()); // الخطوة ٦: صف واحد بلا أطوار
        }
        // تنبيه واحد للدفعة لا ثلاثة
        $this->assertSame(1, AppNotification::where('user_id', $this->mudir->id)->where('type', 'risk.approve')->count());

        // بطاقة واحدة بعددها وزر «اعتمدها كلها»، وبنودها تحتها
        $home = $this->actingAs($this->mudir)->get('/app')->assertOk();
        $home->assertSee('data-batch="risk.approve"', false)->assertSee('action="'.route('risk.approve.bulk').'"', false)->assertSee('اعتمدها كلها');
        foreach ($risks as $r) $home->assertSee('data-task="risk:'.$r->id.':approve"', false);

        $this->actingAs($this->mudir)->post(route('risk.approve.bulk'), ['ids' => $risks->pluck('id')->all()])->assertRedirect();
        foreach ($risks as $r) {
            $this->assertSame('active', $r->fresh()->status);
            $this->assertSame($this->mudir->id, $r->fresh()->approved_by_id);
        }
        $this->actingAs($this->mudir)->get('/app')->assertOk()->assertDontSee('data-batch="risk.approve"', false);
    }

    public function test_what_is_already_in_the_unit_register_and_what_is_not_approved_is_skipped_and_reported(): void
    {
        app(RiskService::class)->activateFromReference($this->refs[0], null, ['scope_type' => 'org_unit', 'organization_unit_id' => $this->hr->id]);
        $draft = $this->reference('خطر لم يعتمده مسؤول السلامة بعد', false);

        $r = $this->bulk($this->coord, [$this->refs[0]->id, $this->refs[1]->id, $draft->id], $this->hr)->assertOk();
        $r->assertJson(['created' => 1, 'existing' => 1, 'unapproved' => 1]);
        $this->assertStringContainsString('تُخطّي', (string) $r->json('message'));
        $this->assertCount(2, $this->registered());

        // ثانيةً: لا تكرار — ما ينتظر الاعتماد موجود أيضاً
        $this->bulk($this->coord, [$this->refs[0]->id, $this->refs[1]->id, $draft->id], $this->hr)->assertOk()->assertJson(['created' => 0, 'existing' => 2, 'unapproved' => 1]);
        $this->assertCount(2, $this->registered());
    }

    public function test_a_manager_activates_many_and_they_are_active_at_once(): void
    {
        $this->bulk($this->mudir, $this->ids(), $this->hr)->assertOk()->assertJson(['created' => 3, 'awaiting' => false]);
        foreach ($this->registered() as $r) {
            $this->assertSame('active', $r->status);
            $this->assertSame($this->coord->id, $r->assigned_coordinator_id, 'المدير فعّل ولم يُسمَّ منسق وحدته تلقائياً');
        }
        $this->assertSame(0, AppNotification::where('type', 'risk.approve')->count());
    }

    public function test_the_scope_is_the_same_as_single_activation(): void
    {
        $this->bulk($this->coord, $this->ids(), $this->it)->assertStatus(422);
        $this->bulk($this->coord, $this->ids(), null)->assertStatus(422);
        $this->bulk($this->makeUser('safety_coordinator'), $this->ids(), $this->hr)->assertStatus(422);
        $this->bulk($this->mudir, $this->ids(), $this->it)->assertStatus(422);
        $this->assertCount(0, $this->registered());
    }

    public function test_a_unit_without_a_safety_coordinator_is_told_so(): void
    {
        $r = $this->bulk($this->other, $this->ids(), $this->it)->assertStatus(422);
        $this->assertStringContainsString('منسق', (string) $r->json('message'));
        $this->assertCount(0, $this->registered());
    }

    public function test_safety_officer_follows_decision_70(): void
    {
        // «عام»: لا وحدة فلا منسق تلقائياً — يسمّيه؛ وهو معتمده فنشطة فوراً
        $this->bulk($this->salama, $this->ids(), null)->assertStatus(422);
        $this->bulk($this->salama, [$this->refs[0]->id], null, ['assigned_coordinator_id' => $this->coord->id])->assertOk()->assertJson(['created' => 1, 'awaiting' => false]);
        $wide = $this->registered()->last();
        $this->assertSame('active', $wide->status);
        $this->assertNull($wide->organization_unit_id);

        // لإدارة: تنتظر مديرها، ومنسقها منسق الوحدة
        $this->bulk($this->salama, [$this->refs[1]->id, $this->refs[2]->id], $this->hr)->assertOk()->assertJson(['created' => 2, 'awaiting' => true]);
        $unit = Risk::where('risk_type', 'active')->where('organization_unit_id', $this->hr->id)->get();
        $this->assertCount(2, $unit);
        foreach ($unit as $r) {
            $this->assertSame('pending_approval', $r->status);
            $this->assertSame($this->coord->id, $r->assigned_coordinator_id);
        }
        $this->assertSame(1, AppNotification::where('user_id', $this->mudir->id)->where('type', 'risk.approve')->count());
    }

    public function test_approving_all_touches_only_what_the_user_may_approve(): void
    {
        $itCoord = $this->makeUser('safety_coordinator', 'it');
        $this->bulk($this->coord, [$this->refs[0]->id, $this->refs[1]->id], $this->hr)->assertOk();
        $this->bulk($itCoord, [$this->refs[2]->id], $this->it)->assertOk();
        $all = $this->registered();
        $this->assertCount(3, $all);

        $this->actingAs($this->coord)->post(route('risk.approve.bulk'), ['ids' => $all->pluck('id')->all()])->assertForbidden();
        $this->actingAs($this->mudir)->post(route('risk.approve.bulk'), ['ids' => $all->pluck('id')->all()])->assertRedirect();
        foreach ($all as $r) {
            $this->assertSame($r->organization_unit_id === $this->hr->id ? 'active' : 'pending_approval', $r->fresh()->status);
        }
    }

    public function test_the_register_screen_offers_the_batch_only_to_those_who_activate(): void
    {
        $this->actingAs($this->coord)->get(route('risk.reference.index'))->assertOk()->assertSee('id="bulkBar"', false)->assertSee('حدّد الكل');
        $this->actingAs($this->makeUser('safety_committee'))->get(route('risk.reference.index'))->assertOk()->assertDontSee('id="bulkBar"', false);
    }
}
