<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Emergency\Services\PlaceProfile;
use App\Modules\Emergency\Services\TeamSync;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Governance\Services\DeptSync;
use App\Modules\Incident\Models\Incident;
use App\Modules\Incident\Services\OccSync;
use App\Modules\Store\Inbox\InspectionReportTasks;
use App\Modules\Store\Models\InstituteDocument;
use App\Modules\Store\Services\InspectionWatch;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * مرحلة الفروع — الخطوة ٣ (قرار ٧٨): الوثائق التشغيلية بمبنى الجلسة من الخادم، وصفحات المعهد كما هي.
 * مبنى الحساب لمن له مبنى واحد، ومبدّل «المبنى» لمن يرى الكل. كل ما يقرأ بالرمز يقرأ بالمبنى والصنف.
 * مزامنة الهيكل داخل فرع الجلسة ولا تحذف خارجه.
 */
class BranchesStep3Test extends TestCase
{
    use RefreshDatabase;

    private EmergencyBuilding $main;
    private EmergencyBuilding $b;
    private OrganizationUnit $branch;
    private OrganizationUnit $dept;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class]);
        $this->main = EmergencyBuilding::main();
        $this->branch = OrganizationUnit::create(['code' => 'dmm', 'name' => 'فرع الشرقية', 'unit_type' => 'branch']);
        $this->b = EmergencyBuilding::create(['code' => 'DMM', 'name' => 'فرع الشرقية — المبنى الرئيسي', 'branch' => 'فرع الشرقية', 'branch_unit_id' => $this->branch->id]);
        Place::createCategoriesFor($this->b);
        $this->dept = OrganizationUnit::create(['code' => 'dmm-fm', 'name' => 'المرافق والصيانة', 'unit_type' => 'department', 'parent_id' => $this->branch->id, 'place_id' => $this->bp('HZ-06')->id]);
    }

    private function bp(string $cat): Place
    {
        return Place::where('building_id', $this->b->id)->where('category', $cat)->firstOrFail();
    }

    private function user(string $username, string $role, ?EmergencyBuilding $building = null, ?Place $place = null, ?OrganizationUnit $unit = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'building_id' => $building?->id, 'place_id' => $place?->id, 'organization_unit_id' => $unit?->id]);
        return $u;
    }

    private function doc(string $key, int $buildingId, array $data, int $version = 1): InstituteDocument
    {
        return InstituteDocument::create(['key' => $key, 'building_id' => $buildingId, 'version' => $version, 'data' => json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public function test_store_reads_and_writes_within_the_session_building(): void
    {
        $this->doc('ipa-park-form-v10', $this->main->id, ['m' => 1], 3);
        $fani = $this->user('fani.b', 'field_worker', $this->b, $this->bp('HZ-01'));
        $this->actingAs($fani);

        $this->putJson('/api/store/ipa-park-form-v10', ['data' => '{"a":1}', 'version' => 0])->assertOk();
        $this->assertSame(1, InstituteDocument::where('key', 'ipa-park-form-v10')->where('building_id', $this->b->id)->count(), 'لم تُكتب في مبنى الحساب');
        $m = InstituteDocument::where('key', 'ipa-park-form-v10')->where('building_id', $this->main->id)->first();
        $this->assertSame(3, $m->version, 'وثيقة الملز مُسّت');
        $this->assertSame('{"m":1}', $m->data);

        $r = $this->getJson('/api/store?all=1')->assertOk()->json();
        $this->assertSame($this->b->id, $r['session']['b'], 'الجلسة بلا مبنى');
        $this->assertSame('HZ-01', $r['session']['p'], 'مكان الجلسة يجب أن يكون الصنف لا الرمز الكامل');
        $this->assertSame('{"a":1}', $r['docs']['ipa-park-form-v10']['data'], 'جاءت وثيقة مبنى آخر');
        $this->assertSame([$this->b->id], array_values(array_unique([$r['session']['b']])));
        $v = $this->getJson('/api/store?versions=1')->assertOk()->json('versions');
        $this->assertSame(1, $v['ipa-park-form-v10']);
    }

    public function test_all_scope_user_switches_building_and_others_cannot(): void
    {
        $this->doc('ipa-park-form-v10', $this->main->id, ['m' => 1]);
        $this->doc('ipa-park-form-v10', $this->b->id, ['d' => 1]);
        $salama = $this->user('salama', 'system_admin');
        $this->actingAs($salama);
        $this->assertSame($this->main->id, $this->getJson('/api/store?all=1')->json('session.b'));
        $this->post(route('app.building.switch', $this->b))->assertRedirect();
        $r = $this->getJson('/api/store?all=1')->json();
        $this->assertSame($this->b->id, $r['session']['b']);
        $this->assertSame('{"d":1}', $r['docs']['ipa-park-form-v10']['data']);
        // ?b= يبدّل أيضاً (رابط إشعار يفتح نموذج مبنى بعينه)
        $this->assertSame($this->main->id, $this->getJson('/api/store?all=1&b='.$this->main->id)->json('session.b'));
        $this->assertSame($this->main->id, $this->getJson('/api/store?all=1')->json('session.b'));
        $this->get(route('app.home'))->assertOk()->assertSee('فرع الشرقية — المبنى الرئيسي'); // المبدّل في الشريط

        $fani = $this->user('fani.b', 'field_worker', $this->b, $this->bp('HZ-01'));
        $this->actingAs($fani);
        $this->post(route('app.building.switch', $this->main))->assertForbidden();
        $this->assertSame($this->b->id, $this->getJson('/api/store?all=1&b='.$this->main->id)->json('session.b'), 'الفني بدّل مبناه');
        $this->get(route('app.home'))->assertOk()->assertDontSee('name="building_switch"', false);
    }

    public function test_write_from_a_stale_building_is_refused(): void
    {
        $this->actingAs($this->user('salama', 'system_admin'));
        $this->putJson('/api/store/ipa-park-form-v10', ['data' => '{"a":1}', 'version' => 0, 'b' => $this->b->id])->assertStatus(422);
        $this->assertSame(0, InstituteDocument::where('key', 'ipa-park-form-v10')->count());
        $this->putJson('/api/store/ipa-park-form-v10', ['data' => '{"a":1}', 'version' => 0, 'b' => $this->main->id])->assertOk();
        $this->assertSame($this->main->id, InstituteDocument::where('key', 'ipa-park-form-v10')->value('building_id'));
    }

    public function test_place_profile_plans_and_teams_are_per_building(): void
    {
        $uid = $this->user('salama', 'system_admin')->id;
        $p = app(PlaceProfile::class);
        $p->savePlans('HZ-06', ['sa' => '2026-10-01', 'sa_by' => 'مسؤول السلامة'], $uid, $this->b->id);
        $this->assertSame('2026-10-01', PlaceProfile::get('HZ-06', $this->b->id)['plans']['sa']);
        $this->assertSame([], PlaceProfile::get('HZ-06', $this->main->id)['plans'], 'خطة الشرقية ظهرت في الملز');

        $un = ['uid' => 'dmm-fm', 'dept' => 'dmm-fm'];
        $team = [['name' => 'سعد المنسق'], ['name' => 'فهد المسعف'], ['name' => 'بدر المنقذ'], ['name' => 'عمر الإطفائي']];
        $p->saveTeam('HZ-06', $un, 0, ['team' => $team, 'nom_by' => 'مدير المرافق', 'staff' => 10], $uid, $this->b->id);
        $t = EmergencyTeam::where('source', 'place_profile')->get();
        $this->assertCount(1, $t);
        $this->assertSame($this->b->id, $t[0]->building_id, 'فريق الشرقية نُسب إلى الملز');
        $this->assertSame($this->bp('HZ-06')->id, $t[0]->place_id);
        $this->assertSame(4, $t[0]->members()->count());
        $this->assertSame(0, EmergencyTeam::where('building_id', $this->main->id)->where('source', 'place_profile')->count());
        $this->assertSame(1, app(TeamSync::class)->sync($this->b->id), 'مزامنة مبنى واحد تعيد فرقه');

        // ملف مكان الشرقية يعرض فريقه، وملف مكان الملز لا
        $this->actingAs(User::find($uid));
        $this->get(route('app.places.units.file', $this->bp('HZ-06')))->assertOk()->assertSee('سعد المنسق');
        $this->get(route('app.places.units.file', Place::find(Place::idByCode('HZ-06'))))->assertOk()->assertDontSee('سعد المنسق');
    }

    public function test_structure_document_is_scoped_to_the_building_branch_and_never_deletes_outside_it(): void
    {
        $sync = app(DeptSync::class);
        $doc = $sync->toDocument($this->b->id);
        $this->assertSame(['dmm', 'dmm-fm'], array_column($doc, 'id'), 'وثيقة الشرقية ليست فرعها وحده');
        $this->assertSame('HZ-06', collect($doc)->firstWhere('id', 'dmm-fm')['place'], 'المكان في الوثيقة صنف لا رمز كامل');
        $before = OrganizationUnit::where('is_active', true)->count();

        // اللوحة في الشرقية تكتب: وحدة جديدة بلا أب (← تحت الفرع)، وتُسقط «المرافق والصيانة»
        $out = $sync->fromDocument([
            ['id' => 'dmm', 'name' => 'فرع الشرقية', 'parent' => '', 'place' => 'HZ-06'],
            ['id' => 'dmm-hr', 'name' => 'الموارد البشرية — الشرقية', 'parent' => '', 'place' => 'HZ-06'],
        ], $this->b->id);
        $hr = OrganizationUnit::where('code', 'dmm-hr')->first();
        $this->assertSame($this->branch->id, $hr->parent_id, 'الوحدة الجديدة لم تُعلَّق تحت الفرع');
        $this->assertSame($this->bp('HZ-06')->id, $hr->place_id, 'مكانها ليس مكاتب الشرقية');
        $this->assertNull(OrganizationUnit::where('code', 'dmm-fm')->first(), 'الوحدة المُسقَطة من وثيقة فرعها لم تُحذف');
        $this->assertSame($before, OrganizationUnit::where('is_active', true)->count());
        $seeded = array_column(OrganizationUnitsSeeder::UNITS, 0);
        $this->assertSame(count($seeded), OrganizationUnit::whereIn('code', $seeded)->where('is_active', true)->count(), 'الهيكل خارج الفرع مُسّ');
        $this->assertSame(['dmm', 'dmm-hr'], array_column($out, 'id'));
    }

    public function test_occupant_reports_document_is_per_building_with_category_codes(): void
    {
        Incident::create(['code' => 'ش-0001', 'title' => 'بلاط', 'description' => 'بلاط مكسور', 'incident_type' => 'normal', 'status' => 'forwarded', 'place_id' => $this->bp('HZ-01')->id]);
        Incident::create(['code' => 'ش-0002', 'title' => 'طفاية', 'description' => 'طفاية مفقودة', 'incident_type' => 'normal', 'status' => 'forwarded', 'place_id' => Place::idByCode('HZ-01')]);
        $occ = app(OccSync::class);
        $db = $occ->toDocument($this->b->id);
        $this->assertSame(['ش-0001'], array_column($db['reports'], 'id'));
        $this->assertSame('HZ-01', $db['reports'][0]['hz'], 'رمز المكان في وثيقة الفني يجب أن يكون الصنف');
        $this->assertSame(['ش-0002'], array_column($occ->toDocument($this->main->id)['reports'], 'id'));
        $occ->refresh();
        $this->assertSame(2, InstituteDocument::where('key', 'ipa-occ')->count(), 'وثيقة لكل مبنى');
    }

    public function test_inspection_inbox_watch_and_rounds_are_per_building(): void
    {
        $report = fn (string $id, string $item) => ['id' => $id, 'row' => 's1-i-0', 'item' => $item, 'sent' => '٢٠٢٦/١٠/٠٨ — ١٠:٠٠', 'when' => '٢٠٢٦/١٠/٠٨ — ١٠:٠٠', 'due' => '٢٤ ساعة', 'levels' => []];
        $this->doc('ipa-park-form-v10', $this->main->id, ['defs' => ['s1' => ['name' => 'الإنارة']], 'reports' => [$report('ف-1', 'مصباح الملز')]]);
        $this->doc('ipa-park-form-v10', $this->b->id, ['defs' => ['s1' => ['name' => 'الإنارة']], 'reports' => [$report('ف-2', 'مصباح الشرقية')]]);
        $fani = $this->user('fani.b', 'tech_electrical', $this->b, $this->bp('HZ-01'));
        $salama = $this->user('salama', 'system_admin');

        $mine = app(InspectionReportTasks::class)->tasksFor($fani)->map(fn ($t) => $t->question)->implode(' | ');
        $this->assertStringContainsString('مصباح الشرقية', $mine);
        $this->assertStringNotContainsString('مصباح الملز', $mine, 'فني الشرقية يرى بلاغ الملز');
        $all = app(InspectionReportTasks::class)->tasksFor($salama);
        $this->assertCount(2, $all);
        $this->assertStringContainsString('فرع الشرقية', $all->map(fn ($t) => $t->place)->implode(' | '), 'بطاقة مسؤول السلامة لا تسمّي المبنى');
        $urls = $all->map(fn ($t) => $t->primary['url'])->implode(' | ');
        $this->assertStringContainsString('?b='.$this->b->id.'#open=', $urls, 'رابط نموذج الشرقية بلا مبناه');

        // الجولة في سجل السلامة بمبناها
        $new = ['fieldIds' => ['dtRound', 'tStart', 'insN'], 'fields' => ['2026-10-08', '09:00', 'فني الشرقية'], 'defs' => ['s1' => ['name' => 'الإنارة', 'items' => []]], 'marks' => [], 'vals' => [], 'reports' => []];
        app(InspectionWatch::class)->afterSave('ipa-park-form-v10', null, json_encode($new, JSON_UNESCAPED_UNICODE), $this->b->id);
        $row = DB::table('inspection_rounds')->where('form_key', 'ipa-park-form-v10')->first();
        $this->assertNotNull($row);
        $this->assertSame($this->b->id, (int) $row->building_id, 'الجولة بلا مبنى');
        $this->actingAs($fani);
        $this->assertCount(1, $this->getJson('/api/inspection-rounds?key=ipa-park-form-v10')->assertOk()->json('rounds'));
        $this->actingAs($this->user('fani.m', 'tech_electrical', $this->main, Place::find(Place::idByCode('HZ-01'))));
        $this->assertCount(0, $this->getJson('/api/inspection-rounds?key=ipa-park-form-v10')->assertOk()->json('rounds'), 'فني الملز يرى جولة الشرقية');
    }

    public function test_place_file_of_a_branch_place_reads_its_own_building_whatever_the_session_building(): void
    {
        $report = fn (string $id, string $item) => ['id' => $id, 'row' => 's1-i-0', 'item' => $item, 'sent' => '٢٠٢٦/١٠/٠٨ — ١٠:٠٠', 'when' => '٢٠٢٦/١٠/٠٨ — ١٠:٠٠', 'due' => '٢٤ ساعة', 'levels' => []];
        $this->doc('ipa-park-form-v10', $this->main->id, ['defs' => ['s1' => ['name' => 'الإنارة']], 'reports' => [$report('ف-1', 'مصباح الملز')]]);
        $this->doc('ipa-park-form-v10', $this->b->id, ['defs' => ['s1' => ['name' => 'الإنارة']], 'reports' => [$report('ف-2', 'مصباح الشرقية')]]);
        $this->actingAs($this->user('salama', 'system_admin')); // جلسته الملز
        $this->get(route('app.places.units.file', $this->bp('HZ-01')))->assertOk()->assertSee('مصباح الشرقية')->assertDontSee('مصباح الملز');
        $this->get(route('app.places.units.file', Place::find(Place::idByCode('HZ-01'))))->assertOk()->assertSee('مصباح الملز')->assertDontSee('مصباح الشرقية');
        $this->get(route('app.places.units.system', [$this->bp('HZ-01'), 'ipa-park-form-v10', 's1']))->assertOk()->assertSee('مصباح الشرقية');
    }
}
