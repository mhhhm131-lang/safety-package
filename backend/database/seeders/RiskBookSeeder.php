<?php

namespace Database\Seeders;

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * كتاب المخاطر من OHSMS: التصنيف بثلاث درجات (٩ رئيسية، ٦٤ فرعية، ٥٤ نوعاً) و٢٦٤ خطراً
 * (database/data/*.json — تصدير قاعدة OHSMS في ٢٠٢٦-٠٥-٠٢)، مع الإجراءات المفصّلة للمخاطر التي
 * كتبها OHSMS في MasterRiskBookSeeder (تُوضع في المرحلة الاستباقية).
 *
 * قرار المستخدم (BACKEND.md ٥-٥): الكتاب كاملاً بتصنيفاته هو السجل العام للمعهد، فيُنسخ كله
 * إلى السجل العام (reference) عند البذر. يُبذر مرة واحدة ولا يكتب فوق تعديلات المستخدم.
 */
class RiskBookSeeder extends Seeder
{
    public function run(): void
    {
        // قرار ٢١: طبقة واحدة — يُبذر السجل العام مباشرة بلا نسخة master
        if (Risk::where('risk_type', 'reference')->exists()) {
            return;
        }
        $dir = database_path('data');
        $cats = json_decode(file_get_contents("$dir/risk_categories.json"), true);
        $subs = json_decode(file_get_contents("$dir/risk_sub_categories.json"), true);
        $causes = json_decode(file_get_contents("$dir/risk_causes.json"), true);
        $risks = json_decode(file_get_contents("$dir/risk_book.json"), true);
        $details = self::detailedActions();

        DB::transaction(function () use ($cats, $subs, $causes, $risks, $details) {
            $catMap = [];
            foreach ($cats as $c) {
                $m = RiskCategory::create(['name' => $c['name'], 'name_en' => $c['name_en'], 'abbreviation' => $c['abbreviation'] ?: null,
                    'description' => $c['description'], 'is_active' => (bool) $c['is_active'], 'created_at' => now()]);
                $catMap[$c['id']] = $m->id;
            }
            $subMap = [];
            foreach ($subs as $s) {
                if (!isset($catMap[$s['category_id']])) continue;
                $m = RiskSubCategory::create(['category_id' => $catMap[$s['category_id']], 'name' => $s['name'], 'name_en' => $s['name_en'],
                    'abbreviation' => $s['abbreviation'] ?: null, 'is_universal' => (bool) $s['is_universal'], 'description' => $s['description']]);
                $subMap[$s['id']] = $m->id;
            }
            foreach ($causes as $z) {
                RiskCause::create(['type_category_id' => $z['type_category_id'] ? ($subMap[$z['type_category_id']] ?? null) : null,
                    'name' => $z['name'], 'name_en' => $z['name_en'], 'description' => $z['description']]);
            }

            foreach ($risks as $r) {
                $risk = Risk::create([
                    'risk_type' => 'reference', 'code' => $r['code'], 'title' => $r['title'], 'description' => $r['description'],
                    'category_id' => $catMap[$r['category_id']] ?? null, 'sub_category_id' => $r['sub_category_id'] ? ($subMap[$r['sub_category_id']] ?? null) : null,
                    'severity' => $r['severity'], 'likelihood' => $r['likelihood'], 'benefit' => $r['benefit'],
                    'contact_channel' => $r['contact_channel'], 'scope_type' => 'general', 'status' => 'approved',
                    'legal_reference' => $r['legal_reference'],
                ]);
                foreach (RiskPhase::PHASES as $phase) {
                    $data = ['risk_id' => $risk->id, 'phase' => $phase];
                    if ($phase === RiskPhase::PHASE_PROACTIVE && isset($details[$r['code']])) {
                        $data += $details[$r['code']];
                    }
                    RiskPhase::create($data);
                }
            }
        });
    }

    /** الإجراءات المفصّلة من MasterRiskBookSeeder في OHSMS (بالكود). */
    public static function detailedActions(): array
    {
        return [
            'MR-FALL-001' => ['corrective_action' => 'توفير حبال أمان وحواجز مع التفتيش الدوري للسقالات', 'preventive_action' => 'تدريب العمال على العمل في الارتفاعات + استخدام معدات الحماية الشخصية'],
            'MR-FALL-002' => ['corrective_action' => 'تركيب شباك أمان وألواح حماية تحت السقالات', 'preventive_action' => 'استخدام صناديق الأدوات المثبتة + خوذات للعاملين أسفل السقالات'],
            'MR-WELD-001' => ['corrective_action' => 'إزالة المواد القابلة للاشتعال وتوفير طفايات الحريق + حارس حريق', 'preventive_action' => 'إصدار تصريح عمل ساخن قبل البدء + تفتيش المنطقة'],
            'MR-WELD-002' => ['corrective_action' => 'توفير تهوية موضعية وأقنعة تنفس مناسبة', 'preventive_action' => 'فحوصات طبية دورية + تدريب على المخاطر الكيميائية'],
            'MR-ELEC-001' => ['corrective_action' => 'فصل التيار قبل العمل (Lockout/Tagout) + استخدام أدوات معزولة', 'preventive_action' => 'تفتيش دوري للأسلاك + تدريب العمال على السلامة الكهربائية'],
            'MR-ELEC-002' => ['corrective_action' => 'تركيب قواطع تيار مناسبة + فحص الأحمال', 'preventive_action' => 'تفتيش دوري للوحات الكهربائية + التزام معايير التركيب'],
            'MR-CONF-001' => ['corrective_action' => 'فحص الجو قبل الدخول + تهوية إجبارية + مراقب خارجي', 'preventive_action' => 'تصريح دخول أماكن محصورة + تدريب متخصص + معدات إنقاذ'],
            'MR-LIFT-001' => ['corrective_action' => 'فحص دوري للسلاسل والكابلات + استبدال التالف', 'preventive_action' => 'تدريب مشغلي الرافعات + تحديد منطقة الرفع وإخلاء العمال'],
            'MR-LIFT-002' => ['corrective_action' => 'حدود سرعة + تحديد طاقة التحميل', 'preventive_action' => 'تدريب وترخيص مشغلي الرافعات + صيانة دورية'],
            'MR-CHEM-001' => ['corrective_action' => 'مواد امتصاص + معدات تنظيف + خطة استجابة طوارئ', 'preventive_action' => 'تخزين مناسب + تدريب على بطاقات السلامة (MSDS)'],
            'MR-CHEM-002' => ['corrective_action' => 'دش طوارئ ومحطات غسيل عيون قريبة', 'preventive_action' => 'قفازات وأقنعة وملابس واقية + تدريب على الاستخدام الآمن'],
            'MR-MACH-001' => ['corrective_action' => 'تركيب حواجز حماية على الأجزاء الدوارة', 'preventive_action' => 'لباس عمل ضيق + ربط الشعر + تدريب على السلامة'],
            'MR-NOISE-001' => ['corrective_action' => 'توفير سدادات أذن وواقيات سمع', 'preventive_action' => 'فحص سمع دوري + تقليل مصادر الضوضاء + تناوب العمل'],
            'MR-HEAT-001' => ['corrective_action' => 'توفير مياه باردة وفترات راحة في الظل', 'preventive_action' => 'جداول عمل مرنة + تجنب أوقات الذروة + تدريب على علامات الإجهاد'],
            'MR-ERGO-001' => ['corrective_action' => 'استخدام معدات رفع ميكانيكية + تقسيم الأحمال', 'preventive_action' => 'تدريب على تقنيات الرفع الصحيحة + تمارين تقوية'],
            'MR-SLIP-001' => ['corrective_action' => 'تنظيف فوري للانسكابات + لافتات تحذير', 'preventive_action' => 'أحذية مانعة للانزلاق + أرضيات خشنة + إضاءة جيدة'],
            'MR-VEH-001' => ['corrective_action' => 'فصل ممرات المشاة عن المركبات + لافتات + إنذارات', 'preventive_action' => 'سترات عاكسة + حدود سرعة + تدريب السائقين'],
            'MR-BIO-001' => ['corrective_action' => 'بروتوكولات عزل + إبر آمنة + معدات حماية شخصية كاملة', 'preventive_action' => 'تطعيمات + تدريب على مكافحة العدوى + فحوصات دورية'],
            'MR-PSY-001' => ['corrective_action' => 'إعادة توزيع المهام + جدولة إجازات منتظمة', 'preventive_action' => 'برامج صحة نفسية + تقدير الجهود + قنوات تواصل مفتوحة'],
            'MR-ADM-001' => ['corrective_action' => 'مراجعة الإجراءات + تدقيق ميداني دوري', 'preventive_action' => 'تدريب مستمر + ثقافة سلامة + محاسبة على المخالفات'],
            'MR-ADM-002' => ['corrective_action' => 'إلزام JSA قبل أي عمل عالي المخاطر', 'preventive_action' => 'تدريب المشرفين على تقييم المخاطر + قوالب جاهزة'],
        ];
    }
}
