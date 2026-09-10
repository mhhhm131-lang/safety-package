<?php

namespace Database\Seeders;

use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * كتاب المعهد (المرحلة ٩، قرارات ٢١–٢٣): الشجرة المعتمدة ٨ أصناف ← ٤٩ فرعاً ← ١٧٧ خطراً
 * من `database/data/institute_risk_book.json` (مُولَّد من `database/data/book/*.md`).
 *
 * طبقة واحدة: يُبذر في السجل العام (`reference`) مباشرة بلا نسخة `master`.
 * الجداول (٢٥ خلية لكل خطر) تُملأ لاحقاً في الخطوة ٥؛ حتى ذلك الحين الدرجة ١×١ والطبقات فارغة.
 * يُبذر مرة واحدة ولا يكتب فوق تعديلات المستخدم. الاستبدال الكامل: `CloseoutService::replaceBook()`.
 *
 * تصدير OHSMS العام (٢٦٤ خطراً) بقي ملفاً مرجعياً `risk_book.json` وبذرته في `OhsmsRiskBookSeeder` — لا يُبذر.
 */
class RiskBookSeeder extends Seeder
{
    public const FILE = 'institute_risk_book.json';

    public function run(): void
    {
        if (Risk::where('risk_type', 'reference')->exists()) {
            return;
        }
        $cats = json_decode(file_get_contents(database_path('data/'.self::FILE)), true);

        DB::transaction(function () use ($cats) {
            foreach ($cats as $c) {
                $cat = RiskCategory::create([
                    'name' => $c['name'], 'name_en' => $c['name_en'], 'abbreviation' => $c['abbreviation'],
                    'description' => $c['description'], 'is_active' => true, 'created_at' => now(),
                ]);
                foreach ($c['sub_categories'] as $s) {
                    $sub = RiskSubCategory::create([
                        'category_id' => $cat->id, 'name' => $s['name'], 'abbreviation' => null,
                        'is_universal' => true, 'description' => $s['description'],
                    ]);
                    foreach ($s['risks'] as $r) {
                        $risk = Risk::create([
                            'risk_type' => 'reference', 'code' => $r['code'], 'title' => $r['title'],
                            'description' => null, 'notes' => $r['note'] ?? null,
                            'category_id' => $cat->id, 'sub_category_id' => $sub->id,
                            'severity' => 1, 'likelihood' => 1, 'scope_type' => 'general', 'status' => 'approved',
                        ]);
                        foreach (RiskPhase::PHASES as $phase) {
                            RiskPhase::create(['risk_id' => $risk->id, 'phase' => $phase]);
                        }
                    }
                }
            }
        });
    }
}
