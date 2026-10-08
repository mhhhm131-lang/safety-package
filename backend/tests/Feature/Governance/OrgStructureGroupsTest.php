<?php

namespace Tests\Feature\Governance;

use App\Models\User;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use Database\Seeders\OrganizationUnitsSeeder;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * بكلمته (٢٠٢٦-١٠-٠٨): «اجعل الإدارات في المركز الرئيسي تنطوي بحيث يظهر المركز الرئيسي والفروع الأربعة،
 * وعند النقر على أي واحد تظهر إداراته وأقسامه».
 */
class OrgStructureGroupsTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATIONS = [
        'database/migrations/2026_10_08_100007_structure_main_center_and_branches.php',
        'database/migrations/2026_10_08_100008_branches_are_regions.php',
    ];

    public function test_structure_screen_shows_only_the_main_center_and_the_branches_collapsed(): void
    {
        $this->seed([PlacesSeeder::class, OrganizationUnitsSeeder::class]);
        $this->artisan('migrate:refresh', ['--path' => self::MIGRATIONS])->assertSuccessful();
        $dmm = OrganizationUnit::where('code', 'br-dmm')->firstOrFail();
        $hq = OrganizationUnit::where('code', 'hq')->firstOrFail();
        OrganizationUnit::create(['code' => 'dmm-fm', 'name' => 'المرافق والصيانة — الشرقية', 'unit_type' => 'department', 'parent_id' => $dmm->id]);

        $u = User::create(['username' => 'salama', 'name' => 'مسؤول السلامة', 'password' => '1234']);
        UserProfile::create(['user_id' => $u->id, 'role' => 'system_admin', 'is_active' => true]);
        $h = $this->actingAs($u)->get(route('app.org.index'))->assertOk()->getContent();

        // خمسة رؤوس فقط، كلها مطوية
        $this->assertSame(5, substr_count($h, 'data-group="'), 'الرؤوس ليست خمسة');
        $this->assertSame(5, substr_count($h, 'class="collapse" id="grp-'), 'رأس غير مطوي');
        foreach (['المركز الرئيسي', 'فرع الرياض', 'فرع الشرقية', 'فرع مكة', 'فرع عسير'] as $name) $this->assertStringContainsString($name, $h);
        $this->assertStringContainsString('>فرع<', $h, 'نوع الفرع لا يُعرض «فرع»');

        // إدارات المركز داخل مجموعته، وإدارة الشرقية داخل مجموعتها لا في المركز
        $hqBody = $this->group($h, $hq->id);
        $dmmBody = $this->group($h, $dmm->id);
        $this->assertStringContainsString('الإدارة العامة للموارد البشرية', $hqBody);
        $this->assertStringContainsString('نائب المدير العام للتدريب', $hqBody);
        $this->assertStringNotContainsString('المرافق والصيانة — الشرقية', $hqBody, 'إدارة الشرقية ظهرت داخل المركز الرئيسي');
        $this->assertStringNotContainsString('data-group="br-dmm"', $hqBody, 'الفرع ظهر داخل المركز الرئيسي');
        $this->assertStringContainsString('المرافق والصيانة — الشرقية', $dmmBody);
        $this->assertStringNotContainsString('الموارد البشرية', $dmmBody);
        // فرع بلا إدارات يقول ذلك
        $this->assertStringContainsString('لا إدارات بعد', $this->group($h, OrganizationUnit::where('code', 'br-mka')->value('id')));
        // عدّاد المركز في رأسه: الوحدات المبذورة ٣٢ كلها تحته (الفروع الأربعة لا تُعدّ فيه)
        $this->assertStringContainsString('32 وحدة', substr($h, 0, strpos($h, 'id="grp-'.$hq->id.'"')));
    }

    /** محتوى مجموعة رأس من فتح div.collapse إلى الرأس التالي أو نهاية الصفحة */
    private function group(string $html, int $id): string
    {
        $start = strpos($html, 'id="grp-'.$id.'"');
        $this->assertNotFalse($start, "لا مجموعة للرأس $id");
        $next = strpos($html, 'data-group="', $start);
        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }
}
