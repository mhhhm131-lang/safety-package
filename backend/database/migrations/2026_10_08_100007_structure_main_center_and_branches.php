<?php

use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * مرحلة الفروع (قرار ٧٨) — بكلمته «ابدأ» (٢٠٢٦-١٠-٠٨): الملز هو «المركز الرئيسي»، والفروع الأربعة تحته.
 *   - رأس واحد «المركز الرئيسي» يُنشأ إن لم يوجد، وتُنقل تحته رؤوس الهيكل القائمة كلها (المدير العام والنواب والإدارات).
 *   - تحته أربعة فروع فارغة: الرياض، الشرقية، مكة، عسير — إن لم توجد بأسمائها.
 *   - مبنى الملز يشير إلى «المركز الرئيسي» إن لم يكن مربوطاً.
 * يعمل مرة واحدة ولا يكتب فوق ما أُضيف من الشاشة؛ ما أضافه المستخدم يبقى. التراجع لا يحذف شيئاً.
 */
return new class extends Migration
{
    private const HQ = 'المركز الرئيسي';
    private const BRANCHES = [['br-ryd', 'فرع الرياض'], ['br-dmm', 'فرع الشرقية'], ['br-mka', 'فرع مكة'], ['br-asr', 'فرع عسير']];

    public function up(): void
    {
        if (DB::table('organization_units')->count() === 0) return; // قاعدة فارغة (اختبار بلا بذر): لا هيكل يُرتَّب

        $now = now();
        $hub = DB::table('places')->where('category', 'HZ-06')->orderBy('id')->value('id');
        $hq = DB::table('organization_units')->where('name', self::HQ)->whereNull('parent_id')->orderBy('id')->first();
        if (!$hq) {
            $id = DB::table('organization_units')->insertGetId([
                'code' => 'hq', 'name' => self::HQ, 'unit_type' => 'company', 'parent_id' => null, 'place_id' => $hub,
                'order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $hq = DB::table('organization_units')->find($id);
        }

        // رؤوس الهيكل القائمة ← تحت المركز الرئيسي
        DB::table('organization_units')->whereNull('parent_id')->where('id', '!=', $hq->id)->update(['parent_id' => $hq->id, 'updated_at' => $now]);

        // الفروع الأربعة تحته
        $order = (int) DB::table('organization_units')->max('order') + 1;
        foreach (self::BRANCHES as [$code, $name]) {
            $exists = DB::table('organization_units')->where('name', $name)->exists() || DB::table('organization_units')->where('code', $code)->exists();
            if ($exists) continue;
            DB::table('organization_units')->insert([
                'code' => $code, 'name' => $name, 'unit_type' => 'region', 'parent_id' => $hq->id, 'place_id' => $hub, // region = «فرع» في الشاشة
                'order' => $order++, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // الملز ← المركز الرئيسي
        $main = EmergencyBuilding::main();
        if ($main && !$main->branch_unit_id) {
            DB::table('emergency_buildings')->where('id', $main->id)->update(['branch_unit_id' => $hq->id]);
        }
    }

    public function down(): void
    {
        // بيانات الهيكل لا تُحذف بالتراجع
    }
};
