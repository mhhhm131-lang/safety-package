<?php

namespace Tests\Feature\Incident;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Incident\Models\Incident;
use App\Modules\Risk\Models\Risk;
use Database\Seeders\PlacesSeeder;
use Illuminate\Support\Str;

/**
 * مساعدات مشتركة لاختبارات بلاغ الشاغل المنقولة من OHSMS — بلا tenant ولا factories:
 * الإنشاء مباشر كما في tests/Feature/IncidentTest.php (User::create + UserProfile::create، Risk::create، Incident::create).
 */
trait IncidentFixtures
{
    /** صورة PNG بحجم ١×١ (المرفقات تُحفظ base64 في الجدول). */
    protected static string $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** مستخدم بدور معهدي، واختيارياً مكان (رمز من PlacesSeeder) واسم ووحدة تنظيمية. */
    protected function makeUser(string $role, ?string $placeCode = null, ?string $name = null, ?int $unitId = null): User
    {
        $username = $role.'_'.Str::lower(Str::random(8));
        $user = User::create(['username' => $username, 'name' => $name ?? "اسم {$username}", 'password' => '1234', 'email' => "$username@example.test"]);
        UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true,
            'organization_unit_id' => $unitId, 'place_id' => $placeCode ? $this->placeId($placeCode) : null]);
        return $user;
    }

    protected function actingAsRole(string $role, ?string $placeCode = null, ?int $unitId = null): User
    {
        $user = $this->makeUser($role, $placeCode, null, $unitId);
        $this->actingAs($user);
        return $user;
    }

    protected function placeId(string $code): int
    {
        if (Place::count() === 0) {
            $this->seed(PlacesSeeder::class);
        }
        return Place::idByCode($code);
    }

    protected function makeUnit(string $code, string $name = 'قسم اختبار'): OrganizationUnit
    {
        return OrganizationUnit::create(['code' => $code, 'name' => $name, 'unit_type' => 'section', 'is_active' => true, 'order' => 1]);
    }

    /** خطر مباشر (بلا مراحل) — يقابل Risk::factory()->create() في OHSMS. */
    protected function makeRisk(array $overrides = []): Risk
    {
        return Risk::create(array_merge([
            'title' => 'خطر اختبار', 'description' => 'وصف خطر اختبار',
            'risk_type' => 'active', 'status' => 'approved', 'severity' => 3, 'likelihood' => 3,
        ], $overrides));
    }

    /** بلاغ مباشر بلا خدمة — يقابل Incident::factory()->create(). المراقب يفرض risk_id لغير السري. */
    protected function makeIncident(array $overrides = []): Incident
    {
        if (!array_key_exists('risk_id', $overrides) && ($overrides['incident_type'] ?? 'normal') !== 'secret') {
            $overrides['risk_id'] = $this->makeRisk()->id;
        }
        return Incident::create(array_merge([
            'title' => 'بلاغ اختبار', 'description' => 'وصف بلاغ اختبار', 'incident_type' => 'normal', 'status' => 'new',
        ], $overrides));
    }
}
