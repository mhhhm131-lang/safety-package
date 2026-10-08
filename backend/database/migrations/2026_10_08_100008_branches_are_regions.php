<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * بكلمته (٢٠٢٦-١٠-٠٨): شاشة الهيكل تعرض المركز الرئيسي والفروع الأربعة رؤوساً مطوية.
 * الفرع نوعه «فرع» (region) ليتميّز عن «نائب» (branch) داخل المركز — الفروع الأربعة التي أنشأها الترحيل السابق تُصحَّح.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('organization_units')->whereIn('code', ['br-ryd', 'br-dmm', 'br-mka', 'br-asr'])->where('unit_type', 'branch')
            ->update(['unit_type' => 'region', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // لا تراجع في بيانات الهيكل
    }
};
