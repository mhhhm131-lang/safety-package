<?php

namespace Tests\Feature;

use App\Core\Permissions\PermissionRegistry;
use App\Core\Trial\TrialFill;
use App\Core\Trial\TrialMode;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyTeam;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\PlaceUnit;
use App\Modules\Governance\Models\Setting;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Services\IncidentService;
use App\Modules\Risk\Models\Risk;
use App\Modules\Store\Models\InstituteDocument;
use Database\Seeders\AffectedGroupsSeeder;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Database\Seeders\RiskBookSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * المرحلة ٢١-٢ (قرار ٥٤): «وضع التجربة» — يُعبَّأ النظام كما يجب أن يعمل، وكل ما يُنشأ أثناءه يُحذف عند إنهائه،
 * وما كان قبله لا يُمس.
 */
class TrialModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class, EmergencySeeder::class, AffectedGroupsSeeder::class, RiskBookSeeder::class]);
    }

    private function counts(): array
    {
        $out = [];
        foreach (Schema::getTables() as $t) {
            if (in_array($t['name'], ['sqlite_sequence', 'cache', 'cache_locks', 'sessions'], true)) continue;
            $out[$t['name']] = DB::table($t['name'])->count();
        }
        ksort($out);
        return $out;
    }

    public function test_fill_builds_the_intended_system_and_stop_removes_only_what_the_trial_created(): void
    {
        // قبل التجربة: حساب حقيقي، وحدة مكان أدخلها صاحبها، ملف مكان فيه فعالية، ومهلة مضبوطة
        $real = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => 'secret-real']);
        UserProfile::create(['user_id' => $real->id, 'role' => 'system_admin', 'is_active' => true]);
        PlaceUnit::create(['place_id' => Place::idByCode('HZ-07'), 'type' => 'training_hall', 'name' => '1001', 'is_active' => true]);
        $docData = json_encode(['HZ-07' => ['events' => [['name' => 'حفل التخرج']]]], JSON_UNESCAPED_UNICODE);
        InstituteDocument::create(['key' => 'ipa-place', 'data' => $docData, 'version' => 3]);
        Setting::set('incident.deadline_hours.urgent', 2);
        $before = $this->counts();

        $this->artisan('ipa:trial-start', ['--password' => 'trial-secret-21'])->assertSuccessful();
        $this->assertTrue(TrialMode::isOn());

        // النظام كما يجب أن يكون
        foreach (OrganizationUnit::where('is_active', true)->get() as $u) {
            $this->assertTrue(UserProfile::where('organization_unit_id', $u->id)->whereIn('role', ['department_manager', 'top_management'])->where('is_active', true)->exists(), "وحدة بلا مدير: {$u->name}");
            $this->assertTrue(UserProfile::where('organization_unit_id', $u->id)->where('role', 'safety_coordinator')->where('is_active', true)->exists(), "وحدة بلا منسق سلامة: {$u->name}");
            $this->assertTrue(Risk::where('risk_type', 'active')->where('organization_unit_id', $u->id)->exists(), "وحدة بلا سجل فعلي: {$u->name}");
        }
        $this->assertSame(0, Risk::where('risk_type', 'active')->where(fn ($q) => $q->whereNull('assigned_coordinator_id')->orWhereNull('assigned_field_team_id'))->count(), 'خطر فعلي بلا منسق أو معالج');
        foreach (array_keys(PermissionRegistry::assignableRoles()) as $role) {
            $this->assertTrue(UserProfile::where('role', $role)->where('is_active', true)->exists(), "دور بلا حساب: $role");
        }
        foreach (Place::all() as $place) {
            $team = EmergencyTeam::where('place_id', $place->id)->where('is_active', true)->withCount(['members as linked' => fn ($q) => $q->whereNotNull('user_id')])->first();
            $this->assertNotNull($team, "مكان بلا فريق أولي: {$place->code}");
            $this->assertSame(4, $team->linked, "فريق {$place->code} ليس أربعة بحساباتهم");
        }
        $bully = Risk::where('risk_type', 'active')->where('code', 'like', 'OR-03-02/%')->whereHas('organizationUnit', fn ($q) => $q->where('code', 'fin'))->first();
        $this->assertNotNull($bully, 'خطر التنمر غير مفعَّل لإدارة المالية');
        $this->assertSame('department_manager', UserProfile::where('user_id', $bully->assigned_field_team_id)->value('role')); // إداري مختص لا فني
        $this->assertTrue(PlaceUnit::where('name', '312')->exists());
        $this->assertTrue(User::where('username', TrialFill::PREFIX.'hr.c')->exists());

        // ما يُدخل باليد أثناء التجربة تجريبي أيضاً
        app(IncidentService::class)->createIncident('normal', User::where('username', TrialFill::PREFIX.'fin.e1')->value('id'),
            ['description' => 'بلاغ أُدخل أثناء التجربة', 'place_id' => Place::idByCode('HZ-06')]);

        $this->artisan('ipa:trial-stop', ['--force' => true])->assertSuccessful();

        $this->assertFalse(TrialMode::isOn());
        $this->assertSame($before, $this->counts(), 'أعداد الصفوف بعد الإنهاء ليست كما قبل التجربة');
        $this->assertTrue(User::where('username', 'salama')->exists());
        $this->assertFalse(User::where('username', 'like', TrialFill::PREFIX.'%')->exists());
        $this->assertTrue(PlaceUnit::where('name', '1001')->exists());
        $this->assertSame($docData, InstituteDocument::where('key', 'ipa-place')->value('data'));
        $this->assertSame(3, (int) InstituteDocument::where('key', 'ipa-place')->value('version'));
        $this->assertSame(2.0, Setting::deadlineHours('urgent'));
        $this->assertNull(Setting::deadlineHours('normal'));
    }

    public function test_fill_refuses_without_trial_mode_and_start_refuses_twice(): void
    {
        try {
            app(TrialFill::class)->run('x');
            $this->fail('التعبئة عملت بلا وضع التجربة');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame(0, User::where('username', 'like', TrialFill::PREFIX.'%')->count());

        $this->artisan('ipa:trial-start', ['--no-fill' => true])->assertSuccessful();
        $this->artisan('ipa:trial-start', ['--no-fill' => true])->assertFailed();
        $this->artisan('ipa:trial-stop', ['--force' => true])->assertSuccessful();
        $this->assertFalse(TrialMode::isOn());
    }

    public function test_banner_tells_every_signed_in_user_that_trial_mode_is_on(): void
    {
        $u = User::create(['username' => 'emp', 'name' => 'موظف', 'password' => 'secret-real']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'employee', 'is_active' => true]);
        $this->actingAs($u)->get('/app')->assertOk()->assertDontSee('وضع التجربة');
        app(TrialMode::class)->start();
        $this->actingAs($u)->get('/app')->assertOk()->assertSee('وضع التجربة')->assertSee('يُحذف عند إنهائها');
    }
}
