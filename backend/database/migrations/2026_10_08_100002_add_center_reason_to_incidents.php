<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * خطة المعالج — الخطوة ٣ (٢٠٢٦-١٠-٠٨): حين يقف البلاغ عند المركز بلا معالج، تُحفظ علّته بجملة قصيرة
 * («هذا الخطر بلا إدارة معالجة في السجل العام»، «الإدارة المعالجة بلا مدير بحساب») لتقولها بطاقة المركز، لا «لا فني للمكان» للجميع.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->string('center_reason', 200)->nullable()->after('incident_field_team_id');
        });
    }

    public function down(): void
    {
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('center_reason');
        });
    }
};
