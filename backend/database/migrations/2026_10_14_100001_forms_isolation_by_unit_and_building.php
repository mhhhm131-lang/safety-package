<?php

use App\Modules\Form\Support\OwnerScopeBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٩ — الخطوة ٢٩-٠ «العزل أولاً» (قرار ٨٧، وثيقة التصميم §٤-٦):
 * ما يُعبَّأ في وحدة النماذج (التكليف والتعبئة) يحمل إدارته ومبناه، فيُقرأ بالمبنى في الموضع الواحد (قرار ٨٤)
 * وبالوحدة بقاعدة رؤية واحدة (`FormVisibilityService`). القوالب تبقى عامة: هي الحزمة نفسها لكل المباني.
 *
 * الصفوف القائمة تُملأ من حساب صاحبها (`OwnerScopeBackfill`): وحدته، ومبنى فرع وحدته في الهيكل، وإلا مبنى حسابه (أو مكانه)، وإلا المبنى الرئيسي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('form_assignments', function (Blueprint $t) {
            $t->foreignId('building_id')->nullable()->after('assigned_to_id')->constrained('emergency_buildings')->nullOnDelete();
            $t->foreignId('organization_unit_id')->nullable()->after('building_id')->constrained('organization_units')->nullOnDelete();
            $t->index(['form_id', 'organization_unit_id']);
        });
        Schema::table('form_submissions', function (Blueprint $t) {
            $t->foreignId('building_id')->nullable()->after('submitted_by_id')->constrained('emergency_buildings')->nullOnDelete();
            $t->foreignId('organization_unit_id')->nullable()->after('building_id')->constrained('organization_units')->nullOnDelete();
            $t->index(['form_id', 'organization_unit_id']);
        });

        OwnerScopeBackfill::run();
    }

    public function down(): void
    {
        foreach (['form_assignments', 'form_submissions'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex([$table.'_form_id_organization_unit_id_index']);
                $t->dropConstrainedForeignId('organization_unit_id');
                $t->dropConstrainedForeignId('building_id');
            });
        }
    }
};
