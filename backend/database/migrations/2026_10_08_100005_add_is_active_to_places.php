<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرحلة الفروع — الخطوة ٢ (قرار ٧٨): صنف لا يوجد في مبنى يُعطَّل ولا يُحذف (`is_active`)؛ أماكن الملز ثابتة ولا تُعطَّل.
 * ورمز المكان يتسع لرمز المبنى كما أُدخل في شاشته (`HZ-06/<رمز المبنى>`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('name');
            $table->string('code', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
