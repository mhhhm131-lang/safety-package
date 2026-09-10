<?php

namespace Database\Seeders;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
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
        if (!Risk::where('risk_type', 'reference')->exists()) {
            $this->seedTree();
        }
        $this->applyTables();
    }

    /**
     * الجداول المعتمدة (الخطوة ٥): كل ملف `data/book/tables/<كود>.json` يملأ خلايا خطره الـ٢٥ في السجل العام
     * بالكود. يعمل في كل نشر (idempotent) فيصل الجدول المعتمد الجديد إلى المنشور بلا استبدال الكتاب.
     * لا يمس نسخ الإدارات المفعَّلة (active) — تلك ملك أصحابها.
     */
    public function applyTables(): int
    {
        $n = 0;
        foreach (glob(database_path('data/book/tables/*.json')) ?: [] as $file) {
            $t = json_decode(file_get_contents($file), true);
            $risk = Risk::where('risk_type', 'reference')->where('code', $t['code'] ?? '')->first();
            if (!$risk) continue;
            DB::transaction(function () use ($risk, $t) {
                $risk->update([
                    'title' => $t['title'] ?: $risk->title, 'description' => $t['description'] ?? null,
                    'severity' => (int) $t['severity'], 'likelihood' => (int) $t['likelihood'],
                    'risk_score' => (int) $t['severity'] * (int) $t['likelihood'],
                    'legal_reference' => $t['legal_reference'] ?? null, 'benefit' => $t['benefit'] ?? null,
                    'contact_channel' => $t['contact_channel'] ?? null,
                ]);
                foreach ($t['phases'] as $key => $p) {
                    $phase = RiskPhase::firstOrCreate(['risk_id' => $risk->id, 'phase' => $key]);
                    $resp = (string) ($p['responsible'] ?? '');
                    $phase->update([
                        'preventive_action' => $p['preventive_action'] ?? null, 'corrective_action' => $p['corrective_action'] ?? null,
                        'residual_assessment' => $p['residual_assessment'] ?? null,
                        'responsible_org_unit_text' => mb_substr($resp, 0, 200) ?: null,
                        'notes' => mb_strlen($resp) > 200 ? 'الجهة والشخص كاملاً: '.$resp : null,
                    ]);
                    $phase->causes()->sync(collect($p['causes'] ?? [])->map(fn ($c) => RiskCause::firstOrCreate(['name' => mb_substr($c, 0, 200)])->id)->all());
                    $ids = [];
                    foreach ($p['affected'] ?? [] as $g) {
                        $group = AffectedGroup::where('name', $g['name'])->first();
                        if (!$group) continue;
                        $ids[] = $group->id;
                        RiskPhaseAffectedGroupDetail::updateOrCreate(['risk_phase_id' => $phase->id, 'affected_group_id' => $group->id],
                            ['impact' => (string) $g['impact'], 'rep_scope' => $g['rep_scope'] ?? null, 'impact_description' => $g['detail'] ?? null]);
                    }
                    $phase->affectedGroups()->sync($ids);
                }
            });
            $n++;
        }
        return $n;
    }

    private function seedTree(): void
    {
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
