<?php

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ٣٢-٢ (قرار ٩١) — بكلمته «اضفها في الشاشة وأنا أضيف الأماكن لاحقاً» (٢٠٢٦-١٠-١٧): مبنى لفرع الرياض.
 * «فرع الرياض» موجود في الهيكل (ترحيل ١٠٠٠٠٧) بلا مبنى؛ مباني الفروع الثلاثة أُضيفت من الشاشة بيده، وهذا يُضاف هنا لأن لا حساب لي على المنشور.
 * يُنشأ مرة واحدة بلا أماكن (أماكنه التسعة بزر «أماكن المبنى» في ملفه). لا يفعل شيئاً إن وُجد مبنى للفرع من قبل (مربوط بوحدته أو برمزه أو باسمه).
 * التراجع لا يحذف شيئاً.
 */
return new class extends Migration
{
    public const CODE = 'RYD';
    public const NAME = 'معهد الادارة العامة فرع الرياض';

    public function up(): void
    {
        if (DB::table('organization_units')->count() === 0) return; // قاعدة فارغة (اختبار بلا بذر): لا هيكل
        $unit = DB::table('organization_units')->where('code', 'br-ryd')->orWhere('name', 'فرع الرياض')->orderBy('id')->first();
        if (!$unit) return; // لا فرع في الهيكل: لا مبنى يُربط
        $exists = DB::table('emergency_buildings')->where('branch_unit_id', $unit->id)->orWhere('code', self::CODE)->orWhere('name', 'like', '%فرع الرياض%')->exists();
        if ($exists) return;
        EmergencyBuilding::create(['code' => self::CODE, 'name' => self::NAME, 'branch' => $unit->name, 'branch_unit_id' => $unit->id]);
    }

    public function down(): void
    {
        // بيانات المباني لا تُحذف بالتراجع
    }
};
