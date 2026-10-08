<?php

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مرحلة الفروع — الخطوة ١ (قرار ٧٨، معتمدة بكلمته «تماماً ابدأ» ٢٠٢٦-١٠-٠٨، `backend/docs/plan-branches-2026-10-08.html`):
 * الفرع ← المبنى ← المكان ← الصنف (٨+١).
 *   - المبنى يشير إلى وحدة الفرع في الهيكل (`branch_unit_id`)؛ النص القديم `branch` يبقى للعرض.
 *     الملز يُربط بـ«المركز الرئيسي» إن وُجد رأسٌ بهذا الاسم، وإلا يبقى بلا ربط حتى يُختار من الشاشة (الخطوة ٢).
 *   - المكان يحمل صنفه (`category` = HZ-00…HZ-08) خانةً مستقلة؛ الأماكن التسعة: صنفها رمزها. قيد: صنف واحد لكل مبنى.
 *     الرمز يتسع للصيغة `HZ-06/DMM` (على نمط قرار ٢٩). رموز الملز لا تتغير.
 *   - الوثيقة التشغيلية تحمل مبناها؛ الموجود كله للملز. المفتاح فريد داخل المبنى لا في الجدول كله.
 * لا شاشة في هذه الخطوة. التراجع لا يحذف صفاً.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_buildings', function (Blueprint $table) {
            $table->foreignId('branch_unit_id')->nullable()->after('branch')->constrained('organization_units')->nullOnDelete();
        });

        Schema::table('places', function (Blueprint $table) {
            $table->string('code', 16)->change();
            $table->string('category', 5)->nullable()->after('code'); // HZ-00 … HZ-08
        });
        DB::table('places')->whereNull('category')->update(['category' => DB::raw("substr(code, 1, 5)")]);
        Schema::table('places', function (Blueprint $table) {
            $table->unique(['building_id', 'category']);
        });

        Schema::table('institute_documents', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('key')->constrained('emergency_buildings')->restrictOnDelete();
        });

        $main = EmergencyBuilding::mainOrCreate();
        DB::table('institute_documents')->whereNull('building_id')->update(['building_id' => $main->id]);
        Schema::table('institute_documents', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->unique(['key', 'building_id']);
        });

        $hq = DB::table('organization_units')->whereNull('parent_id')->where('name', 'المركز الرئيسي')->where('is_active', true)->orderBy('id')->value('id');
        if ($hq) {
            DB::table('emergency_buildings')->where('id', $main->id)->whereNull('branch_unit_id')->update(['branch_unit_id' => $hq]);
        }
    }

    public function down(): void
    {
        Schema::table('institute_documents', function (Blueprint $table) {
            $table->dropUnique(['key', 'building_id']);
        });
        Schema::table('institute_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
        });
        Schema::table('institute_documents', function (Blueprint $table) {
            $table->unique('key');
        });

        Schema::table('places', function (Blueprint $table) {
            $table->dropUnique(['building_id', 'category']);
        });
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('category');
        });

        Schema::table('emergency_buildings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_unit_id');
        });
    }
};
