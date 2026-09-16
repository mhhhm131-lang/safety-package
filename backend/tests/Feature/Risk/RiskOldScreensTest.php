<?php

namespace Tests\Feature\Risk;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * المرحلة ١٦ الدفعة ٨ (كلمة المستخدم ٢٠٢٦-٠٩-١٦): «الكتاب» وملفات «السجل الفعلي» القديمة تُحذف.
 * يبقى: السجل العام والفعلي وطابور الاعتماد، والروابط القديمة المحيلة إليها، والجداول (قرار ٢١).
 */
class RiskOldScreensTest extends TestCase
{
    use RefreshDatabase, RiskFixtures;

    public function test_master_book_is_gone(): void
    {
        foreach (['master.index', 'master.create', 'master.store', 'master.edit', 'master.update', 'master.destroy', 'master.import', 'master.template',
                  'copyFromMaster', 'bulkCopyFromMaster',
                  'book.tree.categories', 'book.tree.subCategories', 'book.tree.risksBySubCategory', 'book.tree.riskDetail'] as $r) {
            $this->assertFalse(Route::has("risk.$r"), "مسار الكتاب باقٍ: risk.$r");
        }
        $this->assertFalse(class_exists(\App\Modules\Risk\Controllers\RiskMasterController::class), 'متحكم الكتاب باقٍ');
        foreach (['master', 'master_create', 'master_edit', 'index', 'create', 'edit'] as $v) {
            $this->assertFalse(view()->exists("modules.risks.$v"), "شاشة قديمة باقية: $v");
        }
        $this->assertStringNotContainsString("'master'", file_get_contents(resource_path('views/modules/risks/partials/_risk_table.blade.php')), 'فرع الكتاب باقٍ في الجدول');

        $this->actingAsRole('system_admin');
        $this->get('/app/risk/master')->assertNotFound();
    }

    public function test_live_registers_and_old_links_still_work(): void
    {
        $this->actingAsRole('system_admin');
        foreach (['/app/risk', '/app/risk/reference', '/app/risk/active', '/app/risk/approval/queue', '/app/risk/reference/create', '/app/risk/active/create', '/app/risk/create'] as $p) {
            $this->get($p)->assertOk();
        }
        foreach (['reference.index', 'reference.edit', 'active.index', 'active.edit', 'activate.form', 'submit', 'show', 'index', 'details'] as $r) {
            $this->assertTrue(Route::has("risk.$r"), "حُذف ما هو حيّ: risk.$r");
        }
    }
}
