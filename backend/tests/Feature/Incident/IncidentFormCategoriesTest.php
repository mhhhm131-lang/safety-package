<?php

namespace Tests\Feature\Incident;

use App\Modules\Risk\Models\RiskCategory;
use Database\Seeders\PlacesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * النماذج العامة تعرض فئات المخاطر للضيف وللمستخدم المسجَّل على السواء.
 * في OHSMS كان الاختبار لمصيدة BelongsToTenant (الفئات العامة tenant_id=NULL)؛ في المعهد لا tenant،
 * فالمعنى الباقي: الفئات النشطة تظهر في النموذج أياً كان الزائر.
 */
class IncidentFormCategoriesTest extends TestCase
{
    use RefreshDatabase, IncidentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlacesSeeder::class);
    }

    public function test_normal_form_shows_global_categories_for_guest(): void
    {
        RiskCategory::create(['name' => 'فئة عامة للاختبار', 'is_active' => true, 'created_at' => now()]);

        $this->get('/incident/normal')
            ->assertOk()
            ->assertSee('فئة عامة للاختبار', false);
    }

    public function test_normal_form_shows_global_categories_for_authenticated_tenant_user(): void
    {
        RiskCategory::create(['name' => 'فئة عامة للاختبار', 'is_active' => true, 'created_at' => now()]);

        // موظف مسجَّل الدخول: النموذج العام يعمل ويُرسل باسمه
        $user = $this->actingAsRole('employee');

        $this->get('/incident/normal')
            ->assertOk()
            ->assertSee('فئة عامة للاختبار', false)
            ->assertSee($user->name);
    }

    public function test_urgent_form_shows_global_categories_for_authenticated_user(): void
    {
        RiskCategory::create(['name' => 'فئة عامة عاجلة', 'is_active' => true, 'created_at' => now()]);

        $this->actingAsRole('employee');

        $this->get('/incident/urgent')
            ->assertOk()
            ->assertSee('فئة عامة عاجلة', false);
    }
}
