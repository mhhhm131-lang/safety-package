<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * كُشف على المنشور بحساب منسق فرع الشرقية (٢٠٢٦-١٠-٠٨ ليلاً): وحدات الفروع الأربعة أُنشئت (الترحيل 100007) بمكان «المكاتب الإدارية»
 * في الملز، فظهرت مكاتب الملز في نطاق منسق الفرع. التصحيح: مكان وحدة الفرع = مكاتب مبناه (HZ-06 في المبنى الذي يشير إليها)،
 * وبلا مبنى بعد: فارغ. يعمل مرة واحدة ولا يمسّ غيرها.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mainHub = DB::table('places')->where('category', 'HZ-06')->orderBy('id')->value('id');
        foreach (DB::table('organization_units')->where('unit_type', 'region')->get(['id', 'place_id']) as $u) {
            $building = DB::table('emergency_buildings')->where('branch_unit_id', $u->id)->orderBy('id')->value('id');
            $place = $building ? DB::table('places')->where('building_id', $building)->where('category', 'HZ-06')->value('id') : null;
            // لا نكتب فوق مكان اختاره المستخدم بيده: نصحّح فقط ما كان على مكاتب الملز أو فارغاً
            if ($u->place_id !== null && (int) $u->place_id !== (int) $mainHub) continue;
            DB::table('organization_units')->where('id', $u->id)->update(['place_id' => $place, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        // لا تراجع في بيانات الهيكل
    }
};
