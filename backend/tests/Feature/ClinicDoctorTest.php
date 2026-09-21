<?php

namespace Tests\Feature;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\EmergencySeeder;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * المرحلة ٢٢-٦ب (د) — قرار ٦٠ بكلمة المستخدم: «ملف طبي داخل النظام لا يطّلع عليه إلا الطبيب».
 *
 * الحال قبلها (جرد ٢٢-١ وتشغيلها): الملف الطبي مبنيّ كاملاً (أمراض مزمنة، حساسية، أدوية، فصيلة دم،
 * منظّم ضربات، جهتا اتصال الأسرة) ولا يُقرأ في أي خطوة؛ و«ملفي الطبي» بلا رابط في صفحة الموظف؛
 * و`critical-info` مفتوحة لكل من يملك `emergency.respond` (المسعف والفني ومديرو الإدارات…).
 *
 * البوابة: الطبيب وحده يفتح ملفات الناس ويُسجَّل اطّلاعه؛ والموظف يرى ملفه هو ويعبّئه؛ ولا أحد غيرهما.
 */
class ClinicDoctorTest extends TestCase
{
    use RefreshDatabase;

    private User $doctor;
    private User $employee;
    private User $medic;
    private User $salama;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
        $this->seed(OrganizationUnitsSeeder::class);
        $this->seed(EmergencySeeder::class);

        $this->doctor = $this->user('tabib', 'clinic_doctor');
        $this->employee = $this->user('emp', 'employee', 'HZ-06');
        $this->medic = $this->user('medic', 'medic', 'HZ-06');
        $this->salama = $this->user('salama', 'system_admin');
    }

    private function user(string $username, string $role, ?string $placeCode = null): User
    {
        $u = User::create(['username' => $username, 'name' => "اسم $username", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $u->id, 'role' => $role, 'is_active' => true, 'place_id' => Place::idByCode($placeCode)]);
        return $u;
    }

    public function test_the_clinic_doctor_is_a_role_in_the_system(): void
    {
        $this->assertArrayHasKey('clinic_doctor', PermissionRegistry::ROLES);
        $this->assertSame('طبيب العيادة', PermissionRegistry::ROLES['clinic_doctor']);
        $this->assertTrue(PermissionRegistry::hasPermission('clinic_doctor', 'medical.read'));
    }

    public function test_nobody_but_the_doctor_holds_the_medical_permission(): void
    {
        foreach (array_keys(PermissionRegistry::ROLES) as $role) {
            if ($role === 'clinic_doctor') continue;
            $this->assertFalse(
                PermissionRegistry::hasPermission($role, 'medical.read'),
                "الدور «{$role}» يملك الاطلاع على الملفات الطبية وهو ليس الطبيب"
            );
        }
    }

    public function test_doctor_opens_the_medical_files_and_his_view_is_logged(): void
    {
        $this->actingAs($this->doctor)->get('/app/emergency/medical')->assertOk();

        $this->actingAs($this->doctor)->get("/app/emergency/medical/users/{$this->employee->id}")
            ->assertOk()
            ->assertSee('اسم emp', false);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->doctor->id,
            'action' => 'medical.view',
        ]);
    }

    public function test_responders_and_the_centre_are_shut_out(): void
    {
        foreach ([$this->medic, $this->salama, $this->employee] as $u) {
            $this->actingAs($u)->get('/app/emergency/medical')->assertForbidden();
            $this->actingAs($u)->get("/app/emergency/medical/users/{$this->employee->id}")->assertForbidden();
            $this->actingAs($u)->getJson('/api/emergency/medical/critical-info')->assertForbidden();
            $this->actingAs($u)->getJson("/api/emergency/medical/users/{$this->employee->id}/for-responders")->assertForbidden();
        }
    }

    public function test_every_employee_reaches_and_fills_his_own_file(): void
    {
        // الرابط في صفحته الأولى — كان الملف بلا مدخل (عيب ٨ في جولة ٢٢-١)
        $this->actingAs($this->employee)->get('/app')->assertOk()->assertSee('ملفي الطبي', false);

        $this->actingAs($this->employee)->get('/app/emergency/medical/my-profile')->assertOk();
        $this->actingAs($this->employee)
            ->putJson('/api/emergency/medical/my-profile', ['blood_type' => 'O+', 'chronic_conditions' => ['سكري']])
            ->assertOk();

        $this->assertDatabaseHas('emergency_medical_profiles', [
            'user_id' => $this->employee->id, 'blood_type' => 'O+',
        ]);
    }

    public function test_nobody_reads_another_persons_file_through_the_api(): void
    {
        $this->actingAs($this->employee)
            ->getJson("/api/emergency/medical/users/{$this->medic->id}")
            ->assertForbidden();
    }

    /** تفعيل حالة طبية يُنبّه الطبيب، وإلا كان الملف خزانة مقفلة. */
    public function test_a_medical_emergency_alerts_the_doctor(): void
    {
        app(EmergencyService::class)->triggerAlarm(
            EmergencyBuilding::main(), 'medical', $this->salama, 'high', false, 'إغماء', Place::idByCode('HZ-06')
        );

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $this->doctor->id,
            'type' => 'emergency.medical',
        ]);
    }
}
