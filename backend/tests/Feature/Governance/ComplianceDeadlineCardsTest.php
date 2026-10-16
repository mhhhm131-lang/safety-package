<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Emergency\Models\BuildingComplianceItem;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Services\ComplianceSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٣١-٢ (قرار ٩٠): الموعد في «ما ينتظرك» — بطاقة واحدة لكل مبنى بعدد مواده التي يحين موعدها خلال سبعة أيام أو فات،
 * لمن يعدّل كلام المبنى، تفتح شاشة المقارنة على المادة، وتختفي حين تصير مستوفاة أو «لا ينطبق». وباب «المقارنة مع اللائحة» في «أريد أن».
 *
 * البوابة (BACKEND.md ٧-٢، ٣١-٢): مادة موعدها بعد ثلاثة أيام ← البطاقة عند مدير الفرع لا عند الموظف ← «مستوفى» يُخفيها.
 */
class ComplianceDeadlineCardsTest extends TestCase
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

    /** بطاقات المقارنة في «ما ينتظرك» لحساب */
    private function cards(User $u): \Illuminate\Support\Collection
    {
        BuildingComplianceItem::flush();
        return app(InboxService::class)->forUser($u)->filter(fn (Task $t) => str_starts_with($t->key, 'compliance:'))->values();
    }

    private function set(BuildingComplianceItem $r, array $words, User $by): void
    {
        $r->applyWords($words, $by); $r->save(); BuildingComplianceItem::flush();
    }

    public function test_gate_due_item_is_a_card_for_building_editors_until_met(): void
    {
        $farea = $this->user('farea', 'branch_manager', $this->b, null, $this->branchB);
        $coord = $this->user('coord.dmm', 'safety_coordinator', $this->b, null, $this->branchB);
        $emp = $this->user('emp.dmm', 'employee', $this->b, Place::where('building_id', $this->b->id)->where('category', 'HZ-01')->first());
        $malazCoord = $this->user('coord.mlz', 'safety_coordinator', null, Place::where('building_id', $this->main->id)->where('category', 'HZ-06')->first());
        $a8 = $this->row($this->b, '8');

        // لا موعد ← لا بطاقة
        $this->assertCount(0, $this->cards($farea));

        // موعد بعد ثلاثة أيام ← بطاقة واحدة للمبنى عند مدير الفرع ومنسق سلامته ومسؤول السلامة، لا عند الموظف ولا منسق إدارة في الملز
        $this->set($a8, ['status' => 'in_progress', 'holder_name' => 'مدير الفرع', 'due_date' => now()->addDays(3)->toDateString()], $coord);
        foreach ([$farea, $coord, $this->salama] as $u) {
            $cards = $this->cards($u);
            $this->assertCount(1, $cards, $u->username);
            $t = $cards[0];
            $this->assertSame('compliance:'.$this->b->id, $t->key);
            $this->assertStringContainsString($this->b->name, $t->question);
            $this->assertStringContainsString('1', $t->question);
            $this->assertStringContainsString('المسؤولية الإشرافية', $t->question);
            $this->assertFalse($t->isOverdue);
            $this->assertSame(now()->addDays(3)->toDateString(), $t->dueAt->toDateString());
            $this->assertSame(route('app.compliance', ['b' => $this->b->id]).'#r'.$a8->id, $t->primary['url']);
        }
        $this->assertCount(0, $this->cards($emp));
        $this->assertCount(0, $this->cards($malazCoord));
        $this->actingAs($farea)->get('/app')->assertOk()->assertSee('المقارنة')->assertSee('#r'.$a8->id, false);
        $this->actingAs($emp)->get('/app')->assertOk()->assertDontSee('compliance:'.$this->b->id);

        // موعد بعد عشرة أيام ← لا بطاقة؛ موعد فات ← متأخرة وتقول «فات»
        $this->set($a8, ['due_date' => now()->addDays(10)->toDateString()], $coord);
        $this->assertCount(0, $this->cards($farea));
        $this->set($a8, ['due_date' => now()->subDays(2)->toDateString()], $coord);
        $t = $this->cards($farea)[0];
        $this->assertTrue($t->isOverdue);
        $this->assertStringContainsString('فات', $t->question);

        // مادة ثانية بموعد ← البطاقة نفسها بعدد ٢ والموعد الأقرب
        $i134 = $this->row($this->b, '13.4');
        $this->set($i134, ['status' => 'not_met', 'due_date' => now()->addDays(5)->toDateString()], $coord);
        $cards = $this->cards($farea);
        $this->assertCount(1, $cards);
        $this->assertStringContainsString('2', $cards[0]->question);
        $this->assertSame(now()->subDays(2)->toDateString(), $cards[0]->dueAt->toDateString());

        // «مستوفى» يُخفي المادة من البطاقة، و«لا ينطبق» كذلك
        $this->set($a8, ['status' => 'met'], $coord);
        $cards = $this->cards($farea);
        $this->assertCount(1, $cards);
        $this->assertStringContainsString('إنقاذ غير القادرين', $cards[0]->question);
        $this->assertFalse($cards[0]->isOverdue);
        $this->set($i134, ['status' => 'na'], $coord);
        $this->assertCount(0, $this->cards($farea));

        // الملز مستقل: مادة بموعد في الملز بطاقة لمسؤول السلامة لا لمدير فرع الدمام
        $this->set($this->row($this->main, '3'), ['status' => 'in_progress', 'due_date' => now()->addDay()->toDateString()], $this->salama);
        $this->assertCount(0, $this->cards($farea));
        $this->assertCount(1, $this->cards($this->salama));
        $this->assertSame('compliance:'.$this->main->id, $this->cards($this->salama)[0]->key);
    }

    public function test_door_in_i_want_to_opens_my_building_comparison(): void
    {
        $emp = $this->user('emp.dmm', 'employee', $this->b, Place::where('building_id', $this->b->id)->where('category', 'HZ-01')->first());
        $h = $this->actingAs($emp)->get('/app')->assertOk()->getContent();
        $this->assertSame(1, preg_match('~<a class="btn btn-o btn-sm" href="([^"]+)" data-intent="compliance"~', $h, $m), 'زر «المقارنة مع اللائحة» في «أريد أن»');
        $this->assertSame(route('app.compliance'), html_entity_decode($m[1]));
        $this->assertStringContainsString('المقارنة مع اللائحة', $h);
        $this->actingAs($emp)->get($m[1])->assertOk()->assertSee($this->b->name);
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('data-intent="compliance"', false);
        auth()->logout();
        $this->get('/app')->assertRedirect('/login'); // الضيف بلا مبنى ولا باب
    }
}
