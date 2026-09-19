<?php

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ٢٠-١ (قرار ٥١): المبنى في البيانات — الفرع ← المبنى ← المكان ← الوحدة.
 * المبنى وحدة الجاهزية؛ الملز (IPA-MAIN) هو المبنى الرئيسي وفرعه الرياض. كل مكان وكل حساب يتبع مبناه.
 * البيانات القائمة كلها تُلحق بالملز. لا يُحذف شيء عند التراجع إلا العمودان.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('emergency_buildings', function (Blueprint $table) {
            $table->string('branch', 100)->nullable()->after('name_en'); // الفرع: الرياض، الدمام، عسير، مكة
        });
        Schema::table('places', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('id')->constrained('emergency_buildings')->restrictOnDelete();
        });
        Schema::table('user_profiles', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('place_id')->constrained('emergency_buildings')->nullOnDelete();
        });

        $main = EmergencyBuilding::mainOrCreate();
        DB::table('emergency_buildings')->where('id', $main->id)->whereNull('branch')->update(['branch' => EmergencyBuilding::MAIN_BRANCH]);
        DB::table('places')->whereNull('building_id')->update(['building_id' => $main->id]);
        DB::table('user_profiles')->whereNull('building_id')->update(['building_id' => $main->id]);
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) { $table->dropConstrainedForeignId('building_id'); });
        Schema::table('places', function (Blueprint $table) { $table->dropConstrainedForeignId('building_id'); });
        Schema::table('emergency_buildings', function (Blueprint $table) { $table->dropColumn('branch'); });
    }
};
