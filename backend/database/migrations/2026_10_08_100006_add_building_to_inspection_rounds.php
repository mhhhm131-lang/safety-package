<?php

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ٢٨-٣ (قرار ٧٨): سجل الجولات (سجل السلامة) بمبناه — المفتاح نفسه لنموذج الصنف في كل مبنى. الموجود كله للملز. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_rounds', function (Blueprint $table) {
            $table->foreignId('building_id')->nullable()->after('id')->constrained('emergency_buildings')->restrictOnDelete();
        });
        DB::table('inspection_rounds')->whereNull('building_id')->update(['building_id' => EmergencyBuilding::mainOrCreate()->id]);
    }

    public function down(): void
    {
        Schema::table('inspection_rounds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('building_id');
        });
    }
};
