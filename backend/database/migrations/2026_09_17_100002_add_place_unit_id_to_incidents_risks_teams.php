<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * المرحلة ١٨-٣ (ج) (قرار ٤٧): الوحدة داخل المكان تُحمل اختيارياً في بلاغ الشاغل والخطر الفعلي والفريق الأولي —
 * «القاعة ٣١٢ الدور ٣» بدل «القاعات»، فيذهب المستجيب إلى النقطة لا إلى المبنى.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['incidents', 'risks', 'emergency_teams'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('place_unit_id')->nullable()->after('place_id')->constrained('place_units')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['incidents', 'risks', 'emergency_teams'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->dropConstrainedForeignId('place_unit_id');
            });
        }
    }
};
