<?php

namespace Tests\Feature\Risk;

use App\Core\Inbox\InboxService;
use App\Core\Inbox\Task;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use App\Modules\Risk\Services\RiskService;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * خطة المعالج — الخطوة ٥ (معتمدة بكلمته ٢٠٢٦-١٠-٠٨): «غير المغطى يظهر» — كلمته «ويحمي نفسه».
 *   مسؤول السلامة في «ما ينتظرك»: «كذا خطراً بلا إدارة معالجة» و«كذا مكاناً بلا فني لتخصصٍ ما». مدير الإدارة المعالجة: «كذا خطراً على إدارتك بلا معالج».
 *   البوابة: إفراغ خانة يرفع العدد، وملؤها ينزله. كل فحص يقرأ البطاقات كما يبنيها الصندوق.
 */
class UncoveredHandlersCountersTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    private RiskCategory $cat;
    private RiskSubCategory $sub;
    private OrganizationUnit $fac;
    private User $salama;
    private User $marafiq;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, AffectedGroupsSeeder::class]);
        $this->cat = RiskCategory::create(['name' => 'الكهربائية', 'abbreviation' => 'EL', 'is_active' => true, 'created_at' => now()]);
        $this->sub = RiskSubCategory::create(['category_id' => $this->cat->id, 'name' => 'الصعق', 'abbreviation' => 'SH']);
        $this->fac = OrganizationUnit::create(['code' => 'fac', 'name' => 'المرافق والصيانة', 'unit_type' => 'section', 'parent_id' => $this->orgUnit('adm-eng')->id, 'order' => 99]);
        $this->salama = $this->makeUser('system_admin');
        $this->marafiq = $this->makeUser('facilities_manager', 'fac', 'مدير المرافق');
    }

    private function risk(string $title, ?OrganizationUnit $unit = null, string $status = 'approved'): Risk
    {
        $r = Risk::create(['risk_type' => 'reference', 'title' => $title, 'description' => 'x', 'category_id' => $this->cat->id, 'sub_category_id' => $this->sub->id,
            'severity' => 3, 'likelihood' => 2, 'status' => $status, 'handling_unit_id' => $unit?->id, 'handling_unit_name' => $unit?->name]);
        app(RiskService::class)->ensurePhases($r);
        return $r;
    }

    private function card(User $u, string $key): ?Task
    {
        return app(InboxService::class)->forUser($u->fresh())->first(fn (Task $t) => $t->key === $key);
    }

    public function test_the_safety_officer_sees_how_many_general_risks_have_no_handling_unit(): void
    {
        $a = $this->risk('خطر أ'); $b = $this->risk('خطر ب'); $this->risk('مقترح', null, 'pending_approval'); $c = $this->risk('خطر ج', $this->fac);
        $card = $this->card($this->salama, 'risk:uncovered:units');
        $this->assertNotNull($card);
        $this->assertStringStartsWith('خطران في السجل العام بلا إدارة معالجة', $card->question); // المقترح غير المعتمد لا يُعدّ
        $this->assertSame(route('risk.reference.index'), $card->primary['url']);
        $this->assertNull($this->card($this->marafiq, 'risk:uncovered:units'), 'العدّاد ظهر لغير مسؤول السلامة');

        // ملء خانة ينزل العدد، وإفراغها يرفعه
        $a->update(['handling_unit_id' => $this->fac->id, 'handling_unit_name' => $this->fac->name]);
        $this->assertStringStartsWith('خطر واحد في السجل العام', $this->card($this->salama, 'risk:uncovered:units')->question);
        $b->update(['handling_unit_id' => $this->fac->id, 'handling_unit_name' => $this->fac->name]);
        $this->assertNull($this->card($this->salama, 'risk:uncovered:units'));
        $c->update(['handling_unit_id' => null, 'handling_unit_name' => null]);
        $this->assertStringStartsWith('خطر واحد', $this->card($this->salama, 'risk:uncovered:units')->question);
        $this->actingAs($this->salama)->get('/app')->assertOk()->assertSee('بلا إدارة معالجة');
    }

    public function test_the_handling_unit_manager_sees_how_many_of_his_risks_have_no_handler(): void
    {
        $a = $this->risk('خطر أ', $this->fac); $b = $this->risk('خطر ب', $this->fac); $this->risk('خطر غيره', $this->orgUnit('hr'));
        $card = $this->card($this->marafiq, 'risk:uncovered:handlers');
        $this->assertNotNull($card);
        $this->assertStringStartsWith('خطران على إدارتك بلا معالج', $card->question);
        $this->assertSame(route('risk.handlers.index'), $card->primary['url']);
        $this->assertNull($this->card($this->salama, 'risk:uncovered:handlers'));
        $hr = $this->makeUser('department_manager', 'hr');
        $this->assertStringStartsWith('خطر واحد على إدارتك', $this->card($hr, 'risk:uncovered:handlers')->question);

        // التسمية من «إدارتي» تنزل العدد
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $a), ['handler' => 'spec:tech_electrical'])->assertRedirect();
        $this->assertStringStartsWith('خطر واحد على إدارتك', $this->card($this->marafiq, 'risk:uncovered:handlers')->question);
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $b), ['handler' => 'user:'.$this->makeUser('employee', 'fac')->id])->assertRedirect();
        $this->assertNull($this->card($this->marafiq, 'risk:uncovered:handlers'));
        // والمحو يرفعه
        $this->actingAs($this->marafiq)->post(route('risk.handlers.set', $a), ['handler' => ''])->assertRedirect();
        $this->assertNotNull($this->card($this->marafiq, 'risk:uncovered:handlers'));
        $this->actingAs($this->marafiq)->get('/app')->assertOk()->assertSee('على إدارتك بلا معالج');
    }

    public function test_the_safety_officer_sees_places_without_a_technician_for_a_named_specialty(): void
    {
        $a = $this->risk('خطر أ', $this->fac);
        $this->assertNull($this->card($this->salama, 'risk:uncovered:places'), 'لا تخصص مسمّى بعد فلا عدّاد');

        $a->forceFill(['handler_specialty' => 'tech_elevator'])->save();
        $card = $this->card($this->salama, 'risk:uncovered:places');
        $this->assertNotNull($card);
        $this->assertStringStartsWith(Place::count().' ', $card->question); // لا فني مصاعد في أي مكان: كل الأماكن
        $this->assertStringContainsString('فني المصاعد في', $card->question);

        // فني يغطي مكانين ← ينقص العدد مكانين؛ وفني مكانه مكان بلا تغطية ← ينقص مكاناً
        $t = $this->makeUser('tech_elevator', 'fac');
        $t->profile->coverage()->sync([Place::idByCode('HZ-06'), Place::idByCode('HZ-07')]);
        $this->assertStringStartsWith((Place::count() - 2).' ', $this->card($this->salama, 'risk:uncovered:places')->question);
        $u = $this->makeUser('tech_elevator', 'fac');
        $u->profile->update(['place_id' => Place::idByCode('HZ-08')]);
        $this->assertStringStartsWith((Place::count() - 3).' ', $this->card($this->salama, 'risk:uncovered:places')->question);
        $this->assertNull($this->card($this->marafiq, 'risk:uncovered:places'));
    }
}
