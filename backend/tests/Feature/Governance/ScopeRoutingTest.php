<?php

namespace Tests\Feature\Governance;

use App\Core\Inbox\InboxService;
use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\ScopeService;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٠-٥ (قرار ٥١): النطاق يُشتق من الحساب عند الدخول (المعهد كله / الفرع / الوحدة / التغطية / الذات)،
 * والجولة وبلاغ الفحص يصلان الفني الذي يغطي المكان وتخصصه يطابق النظام (الجدول المعتمد)؛ وإن لم يوجد وصلا مدير المرافق.
 */
class ScopeRoutingTest extends TestCase
{
    use RefreshDatabase;

    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $hub = Place::idByCode('HZ-06');
        $unit = OrganizationUnit::where('place_id', $hub)->first();
        foreach ([
            'salama' => ['system_admin', null, []], 'marafiq' => ['facilities_manager', null, []], 'far3' => ['branch_manager', null, []],
            'mudir' => ['department_manager', $unit->id, []], 'emp' => ['employee', $unit->id, []],
            'kah' => ['tech_electrical', null, ['HZ-02']], 'hvac' => ['tech_hvac', null, ['HZ-02']], 'kah2' => ['tech_electrical', null, ['HZ-01']],
            'fani' => ['field_worker', null, []], 'amn' => ['support_team', null, ['HZ-07']],
        ] as $name => [$role, $ou, $cov]) {
            $u = User::create(['username' => $name, 'name' => "اسم $name", 'password' => '1234']);
            $p = UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $ou, 'place_id' => $name === 'fani' ? Place::idByCode('HZ-02') : null]);
            $p->coverage()->sync(array_map(fn ($c) => Place::idByCode($c), $cov));
            $this->u[$name] = $u;
        }
        $def = fn (string $name) => ['name' => $name, 'code' => '٠١', 'items' => [['بند', 'SBC']], 'sched' => [['شهري', 'فحص', 'الفني المختص']]];
        $stamp = now()->format('Y/m/d').' — '.now()->format('H:i');
        InstituteDocument::create(['key' => 'ipa-elec-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['e01' => $def('لوحات التوزيع')], 'rounds' => new \stdClass,
            'reports' => [['row' => 'e01-i-0', 'id' => 'ب — ٠١', 'sys' => 'لوحات التوزيع', 'item' => 'قاطع محترق', 'due' => '٢٤ ساعة', 'when' => $stamp, 'sent' => '', 'path' => 'إداري', 'levels' => []]]], JSON_UNESCAPED_UNICODE)]);
        InstituteDocument::create(['key' => 'ipa-park-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['p06' => $def('الأرضيات والتصريف')], 'rounds' => new \stdClass, 'reports' => []], JSON_UNESCAPED_UNICODE)]);
        InstituteDocument::create(['key' => 'ipa-hvac-form-v10', 'version' => 1, 'data' => json_encode(['defs' => ['a01' => $def('المبردات')], 'rounds' => new \stdClass, 'reports' => []], JSON_UNESCAPED_UNICODE)]);
    }

    private function tasks(string $name, string $module): array
    {
        return app(InboxService::class)->forUser($this->u[$name])->where('module', $module)->pluck('question')->values()->all();
    }

    public function test_scope_is_derived_from_the_account(): void
    {
        $codes = fn (string $n) => ScopeService::forUser($this->u[$n])->places()->pluck('code')->all();
        $this->assertSame('all', ScopeService::forUser($this->u['salama'])->kind);
        $this->assertSame('all', ScopeService::forUser($this->u['marafiq'])->kind);
        $this->assertCount(9, $codes('salama'));
        $this->assertSame('branch', ScopeService::forUser($this->u['far3'])->kind);
        $this->assertCount(9, $codes('far3'), 'مدير الفرع: أماكن مباني فرعه — كلها في الملز الآن');
        $this->assertSame('unit', ScopeService::forUser($this->u['mudir'])->kind);
        $this->assertSame(['HZ-06'], $codes('mudir'));
        $this->assertSame('coverage', ScopeService::forUser($this->u['kah'])->kind);
        $this->assertSame(['HZ-02'], $codes('kah'));
        $this->assertSame(['HZ-07'], $codes('amn'));
        $this->assertSame(['HZ-02'], $codes('fani'), 'الدور القديم بلا تغطية: مكان حسابه');
        $this->assertSame('self', ScopeService::forUser($this->u['emp'])->kind);
        $this->assertSame(['HZ-06'], $codes('emp'));

        // «الأماكن»: الفني يرى ما يغطيه، والموظف مكانه، ومسؤول السلامة الكل
        $this->actingAs($this->u['kah'])->get('/app/places/units')->assertOk()->assertSee('data-place="HZ-02"', false)->assertDontSee('data-place="HZ-01"', false);
        $this->actingAs($this->u['emp'])->get('/app/places/units')->assertOk()->assertSee('data-place="HZ-06"', false)->assertDontSee('data-place="HZ-02"', false);
        $h = $this->actingAs($this->u['salama'])->get('/app/places/units')->assertOk()->getContent();
        $this->assertSame(9, substr_count($h, 'data-place="HZ-'));
    }

    public function test_rounds_and_reports_reach_the_covering_technician_of_the_matching_specialty(): void
    {
        // جولة الكهرباء e01 في HZ-02: فني الكهرباء الذي يغطي HZ-02 وحده (لا فني التكييف، ولا فني كهرباء يغطي غيره)
        $this->assertCount(1, $this->tasks('kah', 'جولات الفحص'));
        $this->assertStringContainsString('غرف الكهرباء', $this->tasks('kah', 'جولات الفحص')[0]);
        $this->assertSame([], $this->tasks('hvac', 'جولات الفحص'));
        // جولة الأرضيات p06 (بلا تخصص) في HZ-01: أي فني يغطي HZ-01
        $this->assertCount(1, $this->tasks('kah2', 'جولات الفحص'));
        $this->assertStringContainsString('القبو', $this->tasks('kah2', 'جولات الفحص')[0]);
        // الدور القديم: مكانه وكل الأنظمة (حتى يُنقل)
        $this->assertCount(1, $this->tasks('fani', 'جولات الفحص'));
        // لا فني يغطي التكييف (HZ-03): تصل مدير المرافق ليوزعها، ولا تصل مسؤول السلامة كجولة
        $fm = $this->tasks('marafiq', 'جولات الفحص');
        $this->assertCount(1, $fm);
        $this->assertStringContainsString('لا فني يغطي', $fm[0]);
        $this->assertStringContainsString('التكييف', $fm[0]);

        // بلاغ الفحص e01-i-0 عند المستوى ١: فني الكهرباء المغطي وحده (+ الدور القديم في مكانه)
        $this->assertCount(1, $this->tasks('kah', 'بلاغات الفحص'));
        $this->assertSame([], $this->tasks('hvac', 'بلاغات الفحص'));
        $this->assertSame([], $this->tasks('kah2', 'بلاغات الفحص'));
        $this->assertCount(1, $this->tasks('fani', 'بلاغات الفحص'));
        // مسؤول السلامة يتابع كل بلاغ كما كان
        $this->assertCount(1, $this->tasks('salama', 'بلاغات الفحص'));
    }

    public function test_specialty_table_is_the_approved_one(): void
    {
        $S = \App\Modules\Store\Services\SystemSpecialty::MAP;
        $this->assertSame('tech_electrical', $S['e01']);
        $this->assertSame('tech_generator', $S['e04']);
        $this->assertSame('tech_generator', $S['s09']);
        $this->assertSame('tech_fire_alarm', $S['s10']);
        $this->assertSame('tech_electrical', $S['p04']);
        $this->assertSame('tech_fire_pump', $S['s04']);
        $this->assertSame('tech_hvac', $S['s08']);
        $this->assertSame('tech_elevator', $S['s11']);
        $this->assertSame('tech_fire_alarm', $S['c01']);
        $this->assertArrayNotHasKey('p06', $S);
        $this->assertCount(37, $S); // ٣٧ لا ٣٦: عرضتُ الحريق «١١» والقائمة اثنا عشر مفتاحاً — صُحّح في السجل
        $this->assertSame(['tech_electrical' => 10, 'tech_generator' => 2, 'tech_fire_alarm' => 12, 'tech_fire_pump' => 2, 'tech_hvac' => 10, 'tech_elevator' => 1], array_count_values($S));
    }
}
