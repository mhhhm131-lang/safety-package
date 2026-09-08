<?php

namespace Tests\Feature\Risk;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskSubCategory;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Support\Str;

/**
 * مساعدات مشتركة لاختبارات المخاطر المنقولة من OHSMS — بلا factories للمخاطر في المعهد،
 * فالإنشاء مباشر كما في tests/Feature/RiskTest.php (User::create + UserProfile::create).
 */
trait RiskFixtures
{
    /** مستخدم بدور معهدي، واختيارياً وحدة تنظيمية من بذرة الهيكل (تُبذر عند الحاجة). */
    protected function makeUser(string $role, ?string $unitCode = null, ?string $name = null): User
    {
        $unitId = null;
        if ($unitCode) {
            $unitId = $this->orgUnit($unitCode)->id;
        }
        $username = $role.'_'.Str::lower(Str::random(8));
        $user = User::create(['username' => $username, 'name' => $name ?? "اسم {$username}", 'password' => '1234']);
        UserProfile::create(['user_id' => $user->id, 'role' => $role, 'is_active' => true, 'organization_unit_id' => $unitId]);
        return $user;
    }

    protected function actingAsRole(string $role, ?string $unitCode = null, ?string $name = null): User
    {
        $user = $this->makeUser($role, $unitCode, $name);
        $this->actingAs($user);
        return $user;
    }

    /** وحدة تنظيمية بكودها من OrganizationUnitsSeeder (hr, it, ...). */
    protected function orgUnit(string $code): OrganizationUnit
    {
        if (OrganizationUnit::count() === 0) {
            $this->seed(PlacesSeeder::class);
            $this->seed(OrganizationUnitsSeeder::class);
        }
        return OrganizationUnit::where('code', $code)->firstOrFail();
    }

    protected function makeCategory(?string $name = null): RiskCategory
    {
        return RiskCategory::create(['name' => $name ?? 'فئة '.Str::random(4), 'is_active' => true, 'created_at' => now()]);
    }

    protected function makeSubCategory(RiskCategory $category, ?string $name = null): RiskSubCategory
    {
        return RiskSubCategory::create(['category_id' => $category->id, 'name' => $name ?? 'فئة فرعية '.Str::random(4)]);
    }

    /** خطر مباشر (بلا مراحل) — يقابل Risk::factory()->create() في OHSMS. */
    protected function makeRisk(array $overrides = []): Risk
    {
        return Risk::create(array_merge([
            'title' => 'خطر اختبار', 'description' => 'وصف خطر اختبار',
            'risk_type' => 'active', 'status' => 'draft', 'severity' => 3, 'likelihood' => 3,
        ], $overrides));
    }
}
