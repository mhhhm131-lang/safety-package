<?php

namespace Database\Seeders;

use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskControl;
use Illuminate\Database\Seeder;

/**
 * ضوابط المخاطر لعشر فئات أعمال عالية الخطورة × ٣ أطوار (من OHSMS كما هي؛ تُستخدم في التصاريح لاحقاً).
 *
 * Each control maps to a risk_category (category-level scope, tenant-agnostic).
 * SmartJsaService pulls these when building permit_requirements.
 *
 * Sources: OSHA 1910, HSE UK guidance, NFPA 51B, IRATA, IEC 60900.
 * Idempotent — safe to re-run.
 */
class RiskControlsSeeder extends Seeder
{
    // Maps seeder keys → Arabic category names in the database
    private array $categoryMap = [
        'hot_work'         => 'مخاطر الحريق',
        'confined_space'   => 'المخاطر الفيزيائية',
        'work_at_height'   => 'المخاطر الفيزيائية',
        'excavation'       => 'المخاطر الميكانيكية',
        'electrical_work'  => 'المخاطر الكهربائية',
        'loto'             => 'المخاطر الكهربائية',
        'hazmat_handling'  => 'المخاطر الكيميائية',
        'radiation_work'   => 'المخاطر الفيزيائية',
        'pressure_testing' => 'المخاطر الميكانيكية',
        'blasting'         => 'المخاطر الفيزيائية',
        'crane_operations' => 'المخاطر الميكانيكية',
    ];

    public function run(): void
    {
        foreach ($this->catalog() as $permitTypeCode => $controls) {
            $categoryName = $this->categoryMap[$permitTypeCode] ?? $permitTypeCode;

            // Find category by name only — 'code' column may not exist in older DBs
            $category = RiskCategory::where('name', $categoryName)->first();

            if (! $category) {
                $this->command?->warn("Category not found: {$permitTypeCode} — skipping");
                continue;
            }

            foreach ($controls as $sort => $control) {
                RiskControl::updateOrCreate(
                    [
                        'risk_category_id' => $category->id,
                        'phase'            => $control['phase'],
                        'description_ar'   => $control['description_ar'],
                    ],
                    array_merge($control, [
                        'risk_category_id' => $category->id,
                        'permit_type_code' => $permitTypeCode,
                        'sort_order'       => $sort + 1,
                    ])
                );
            }
        }
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function catalog(): array
    {
        return [

            // ════════════════════════════════════════════════
            // HOT WORK — welding, cutting, grinding, open flame
            // Standard: NFPA 51B, OSHA 1910.252
            // ════════════════════════════════════════════════
            'hot_work' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'التحقق من خلو المنطقة (10م) من المواد القابلة للاشتعال', 'description_en' => 'Verify 10m radius clear of flammable/combustible materials', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'NFPA 51B §4.3'],
                ['phase' => 'preventive', 'description_ar' => 'قياس تركيز الغاز القابل للاشتعال (يجب < 10% LEL)', 'description_en' => 'Gas test: LEL < 10% before ignition', 'evidence_type' => 'measurement', 'measurement_unit' => '% LEL', 'measurement_threshold' => '< 10', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.252(a)'],
                ['phase' => 'preventive', 'description_ar' => 'تعيين حارس حريق مدرَّب مع طفاية 12 كجم على الأقل', 'description_en' => 'Assign trained fire watch with ≥ 12 kg extinguisher', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'NFPA 51B §5.6'],
                ['phase' => 'preventive', 'description_ar' => 'حماية الفتحات والقنوات المجاورة بالحواجز أو الغطاء المبلل', 'description_en' => 'Shield/cover adjacent drains, ducts, openings', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من صلاحية مطفأة الحريق وتاريخ الصيانة', 'description_en' => 'Confirm extinguisher inspection tag is current', 'evidence_type' => 'check', 'responsible_role' => 'requester', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'توثيق صورة لموقع العمل قبل البدء', 'description_en' => 'Photo of work area before ignition', 'evidence_type' => 'photo', 'responsible_role' => 'issuer', 'is_mandatory' => false],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'إعادة قياس تركيز الغاز كل 30 دقيقة أثناء العمل', 'description_en' => 'Re-test LEL every 30 minutes during work', 'evidence_type' => 'measurement', 'measurement_unit' => '% LEL', 'measurement_threshold' => '< 10', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'frequency' => 'every_30_min', 'standard_reference' => 'OSHA 1910.252(a)'],
                ['phase' => 'operational', 'description_ar' => 'حارس الحريق يراقب الشرر والمواد المشتعلة باستمرار', 'description_en' => 'Fire watch monitors sparks and hot slag continuously', 'evidence_type' => 'check', 'responsible_role' => 'fire_watch', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'إيقاف العمل فوراً عند تجاوز 10% LEL أو وجود دخان', 'description_en' => 'Stop work immediately if LEL ≥ 10% or smoke detected', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'الإبلاغ الفوري عن الحريق وتفعيل إنذار الطوارئ', 'description_en' => 'Immediately report fire and activate emergency alarm', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'إخلاء المنطقة وإغلاق إمدادات الغاز/الوقود', 'description_en' => 'Evacuate area and shut off gas/fuel supplies', 'evidence_type' => 'check', 'responsible_role' => 'fire_watch', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'حارس الحريق يواصل المراقبة 30 دقيقة بعد انتهاء العمل', 'description_en' => 'Fire watch continues monitoring 30 min after work ends', 'evidence_type' => 'check', 'responsible_role' => 'fire_watch', 'is_mandatory' => true, 'standard_reference' => 'NFPA 51B §5.6.2'],
            ],

            // ════════════════════════════════════════════════
            // CONFINED SPACE — tanks, vessels, manholes, pits
            // Standard: OSHA 1910.146, EN 13779
            // ════════════════════════════════════════════════
            'confined_space' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'قياس الأكسجين (يجب 19.5%-23.5%) قبل الدخول', 'description_en' => 'Test O₂ concentration: must be 19.5%–23.5%', 'evidence_type' => 'measurement', 'measurement_unit' => '% O₂', 'measurement_threshold' => '19.5–23.5', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.146(c)(5)'],
                ['phase' => 'preventive', 'description_ar' => 'قياس تركيز الغازات السامة (CO < 25 ppm, H₂S < 10 ppm)', 'description_en' => 'Test toxic gases: CO < 25 ppm, H₂S < 10 ppm', 'evidence_type' => 'measurement', 'measurement_unit' => 'ppm', 'measurement_threshold' => 'CO<25, H2S<10', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.146'],
                ['phase' => 'preventive', 'description_ar' => 'قياس تركيز الغاز القابل للاشتعال (LEL < 10%)', 'description_en' => 'Test LEL: must be < 10%', 'evidence_type' => 'measurement', 'measurement_unit' => '% LEL', 'measurement_threshold' => '< 10', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'عزل جميع مصادر الطاقة والأنابيب المتصلة (LOTO)', 'description_en' => 'Isolate all energy sources and connected pipes (LOTO)', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'تعيين حارس خارجي (attendant) طوال مدة العمل', 'description_en' => 'Assign attendant outside space for duration', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.146(i)'],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من جاهزية معدات الإنقاذ (طوق النجاة، حبل الإنقاذ)', 'description_en' => 'Verify rescue equipment: retrieval line, harness, tripod', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'تشغيل نظام التهوية القسرية 5 دقائق قبل الدخول', 'description_en' => 'Run forced ventilation 5 min before entry', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'مراقبة جودة الهواء بشكل مستمر بجهاز الغاز الشخصي', 'description_en' => 'Continuous air monitoring with personal gas detector', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'تواصل مستمر (لفظي أو بالإشارة) بين الداخل والحارس', 'description_en' => 'Continuous communication (verbal/signal) between entrant and attendant', 'evidence_type' => 'check', 'responsible_role' => 'attendant', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'إعادة قياس الغازات كل 30 دقيقة وتسجيل النتائج', 'description_en' => 'Re-test air quality every 30 min and log results', 'evidence_type' => 'measurement', 'measurement_unit' => 'multiple', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'frequency' => 'every_30_min'],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'الحارس يُنبّه الطوارئ ولا يدخل الفضاء المحصور للإنقاذ', 'description_en' => 'Attendant alerts emergency team — does NOT enter space to rescue', 'evidence_type' => 'check', 'responsible_role' => 'attendant', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.146(i)(6)'],
                ['phase' => 'response', 'description_ar' => 'استخدام معدات الاسترداد (حبل، رافعة ثلاثية) للإخلاء', 'description_en' => 'Use retrieval equipment (line, tripod winch) for non-entry rescue', 'evidence_type' => 'check', 'responsible_role' => 'attendant', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'تفعيل خطة الطوارئ والاتصال بالمساعدة الطبية', 'description_en' => 'Activate emergency plan and call medical assistance', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // WORK AT HEIGHT — > 2 m, fall risk
            // Standard: OSHA 1926 Subpart R, EN 363, IRATA
            // ════════════════════════════════════════════════
            'work_at_height' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'التحقق من فحص معدات الحماية من السقوط (تاريخ < 12 شهر)', 'description_en' => 'Verify fall arrest equipment inspection date < 12 months', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'EN 363'],
                ['phase' => 'preventive', 'description_ar' => 'فحص نقطة الربط (anchor) — تتحمل ≥ 15 كيلونيوتن', 'description_en' => 'Inspect anchor point: rated ≥ 15 kN', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.502(d)'],
                ['phase' => 'preventive', 'description_ar' => 'تأمين المنطقة السفلية بحواجز وتحذيرات (خطر السقوط)', 'description_en' => 'Barricade and warn area below — falling object hazard', 'evidence_type' => 'check', 'responsible_role' => 'requester', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من حالة السقالة: شهادة فحص سارية وطابق مستوٍ', 'description_en' => 'Scaffold inspection certificate current, level platform', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.451'],
                ['phase' => 'preventive', 'description_ar' => 'قياس سرعة الرياح — إيقاف العمل إذا > 45 كم/ساعة', 'description_en' => 'Measure wind speed: stop work if > 45 km/h', 'evidence_type' => 'measurement', 'measurement_unit' => 'km/h', 'measurement_threshold' => '< 45', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'توثيق صورة لتثبيت حزام الأمان على نقطة الربط', 'description_en' => 'Photo of harness connected to anchor point', 'evidence_type' => 'photo', 'responsible_role' => 'issuer', 'is_mandatory' => false],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'الالتزام بنظام نقطتي ربط عند التنقل (100% tie-off)', 'description_en' => 'Maintain 100% tie-off with double lanyard during movement', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'قياس سرعة الرياح كل ساعة وتسجيل القراءة', 'description_en' => 'Measure wind speed hourly and log', 'evidence_type' => 'measurement', 'measurement_unit' => 'km/h', 'measurement_threshold' => '< 45', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'frequency' => 'hourly'],
                ['phase' => 'operational', 'description_ar' => 'إيقاف العمل فوراً عند تدهور الأحوال الجوية', 'description_en' => 'Stop work immediately on weather deterioration', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'تفعيل خطة إنقاذ الارتفاع وطلب المساعدة على الفور', 'description_en' => 'Activate height rescue plan and call for help immediately', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'لا تحرّك العامل المصاب عند الاشتباه بإصابة العمود الفقري', 'description_en' => 'Do not move injured worker if spinal injury suspected', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'تأمين موقع السقوط ومنع الدخول ريثما تصل الطوارئ', 'description_en' => 'Secure fall site, prevent access until emergency services arrive', 'evidence_type' => 'check', 'responsible_role' => 'attendant', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // EXCAVATION — ground-breaking, trenching
            // Standard: OSHA 1926 Subpart P, BS 6031
            // ════════════════════════════════════════════════
            'excavation' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'إجراء مسح المرافق (كهرباء، ماء، غاز) قبل الحفر', 'description_en' => 'Complete utility scan before breaking ground', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.651(b)'],
                ['phase' => 'preventive', 'description_ar' => 'تصنيف نوع التربة من قِبل شخص مختص (A/B/C)', 'description_en' => 'Soil classification by competent person (Type A/B/C)', 'evidence_type' => 'document', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.652 App A'],
                ['phase' => 'preventive', 'description_ar' => 'تركيب نظام دعم الحفرة (قواطع أو ميل أو درجات) لعمق > 1.5م', 'description_en' => 'Install shoring/sloping/benching for depth > 1.5 m', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.652'],
                ['phase' => 'preventive', 'description_ar' => 'وضع الحواجز والأسيجة على بُعد ≥ 60 سم من حافة الحفرة', 'description_en' => 'Install barriers ≥ 60 cm from excavation edge', 'evidence_type' => 'check', 'responsible_role' => 'requester', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'توفير مخرج آمن (سلم أو درج) كل 7.5م على الأقل', 'description_en' => 'Provide safe egress (ladder/ramp) every 7.5 m max', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.651(c)'],
                ['phase' => 'preventive', 'description_ar' => 'قياس مستوى الأكسجين والغازات قبل الدخول لحفريات > 1.2م', 'description_en' => 'Test O₂ and gas levels before entering excavations > 1.2 m', 'evidence_type' => 'measurement', 'measurement_unit' => '% O₂', 'measurement_threshold' => '19.5–23.5', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'فحص جدران الحفرة يومياً وبعد كل هطول أمطار', 'description_en' => 'Inspect excavation walls daily and after rain', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'frequency' => 'daily'],
                ['phase' => 'operational', 'description_ar' => 'مراقبة علامات الانهيار (تشققات، بروز في الجدران)', 'description_en' => 'Monitor for collapse signs: cracks, wall bulging', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'عدم تخزين المواد على بُعد < 0.6م من حافة الحفرة', 'description_en' => 'No spoil/material storage within 0.6 m of trench edge', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'عند الانهيار: الإخلاء الفوري والاتصال بالطوارئ (لا تحفر بالأيدي)', 'description_en' => 'On collapse: evacuate immediately, call emergency — do not hand-dig', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'إغلاق المنطقة ومنع أي دخول حتى يصل فريق الإنقاذ', 'description_en' => 'Close area and prevent all access until rescue team arrives', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // ELECTRICAL WORK — live/de-energized, HV/LV
            // Standard: OSHA 1910.303, IEC 60900, NFPA 70E
            // ════════════════════════════════════════════════
            'electrical_work' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'التحقق من كفاءة الكهربائي (شهادة معتمدة سارية)', 'description_en' => 'Verify electrician competency certificate is current', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'NFPA 70E 110.2'],
                ['phase' => 'preventive', 'description_ar' => 'قياس الجهد للتحقق من قطع التيار (يجب = 0 فولت)', 'description_en' => 'Voltage test to confirm de-energization: must read 0 V', 'evidence_type' => 'measurement', 'measurement_unit' => 'V', 'measurement_threshold' => '= 0', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'NFPA 70E 120.5'],
                ['phase' => 'preventive', 'description_ar' => 'تطبيق إجراءات LOTO وتركيب قفل شخصي على لوحة التحكم', 'description_en' => 'Apply LOTO — personal lock on control panel', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147'],
                ['phase' => 'preventive', 'description_ar' => 'ارتداء معدات الحماية من قوس البرق (ARC flash PPE) المناسبة للفئة', 'description_en' => 'Wear rated ARC flash PPE for the incident energy category', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'NFPA 70E 130.5'],
                ['phase' => 'preventive', 'description_ar' => 'تأسيس الأرضي (Grounding) للأجزاء المعزولة قبل اللمس', 'description_en' => 'Ground isolated conductors/parts before contact', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'IEC 60900'],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'اشتراط وجود شخص ثانٍ مؤهل على بُعد الاستجابة', 'description_en' => 'Second qualified person must be within response distance', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'التحقق من عدم انقطاع حالة الجهد كل مرحلة عمل', 'description_en' => 'Re-verify de-energized state at each work phase', 'evidence_type' => 'measurement', 'measurement_unit' => 'V', 'measurement_threshold' => '= 0', 'responsible_role' => 'worker', 'is_mandatory' => true],
                ['phase' => 'operational', 'description_ar' => 'إيقاف العمل فوراً عند شم حرق أو سماع صوت غير طبيعي', 'description_en' => 'Stop work immediately on burning smell or abnormal noise', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'لا تلمس ضحية الصدمة الكهربائية — قطع التيار أولاً', 'description_en' => 'Do not touch electric shock victim — cut power first', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'تطبيق الإسعافات الأولية وطلب المساعدة الطبية فوراً', 'description_en' => 'Apply first aid and call medical help immediately', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'توثيق الحادثة كاملاً قبل استئناف أي عمل', 'description_en' => 'Fully document incident before any work resumes', 'evidence_type' => 'document', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // LOTO — lockout/tagout, energy isolation
            // Standard: OSHA 1910.147
            // ════════════════════════════════════════════════
            'loto' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'تحديد جميع مصادر الطاقة وتوثيقها (إجراء LOTO محدد للمعدة)', 'description_en' => 'Identify all energy sources per equipment-specific LOTO procedure', 'evidence_type' => 'document', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(c)(4)'],
                ['phase' => 'preventive', 'description_ar' => 'إشعار جميع المتأثرين بعزل الطاقة قبل تطبيق LOTO', 'description_en' => 'Notify all affected employees before applying LOTO', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(d)(2)'],
                ['phase' => 'preventive', 'description_ar' => 'قطع جميع مصادر الطاقة (كهرباء، هيدروليك، هواء، حراري)', 'description_en' => 'De-energize all sources: electrical, hydraulic, pneumatic, thermal', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(d)(3)'],
                ['phase' => 'preventive', 'description_ar' => 'تركيب قفل شخصي وبطاقة LOTO على كل نقطة عزل', 'description_en' => 'Apply personal lock and LOTO tag to each isolation point', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(d)(4)'],
                ['phase' => 'preventive', 'description_ar' => 'تفريغ الطاقة المخزنة (ضغط، زنبرك، جاذبية) والتحقق من ذلك', 'description_en' => 'Release/restrain stored energy (pressure, spring, gravity) and verify', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(d)(5)'],
                ['phase' => 'preventive', 'description_ar' => 'اختبار التحقق من عزل الطاقة (Try out)', 'description_en' => 'Verify isolation: attempt to start — machine must not respond', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(d)(6)'],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'كل عامل إضافي يُركّب قفله الشخصي (لا مشاركة الأقفال)', 'description_en' => 'Each additional worker applies their personal lock — no shared locks', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'مراجعة حالة LOTO عند تغيير الورديات', 'description_en' => 'Review LOTO status at each shift change', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'frequency' => 'shift_change'],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'إجراء رفع LOTO في حالات الطوارئ: فقط بإذن مشرف أعلى', 'description_en' => 'Emergency LOTO removal: only with senior supervisor authorization', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.147(e)'],
                ['phase' => 'response', 'description_ar' => 'إشعار المالك الأصلي للقفل عند الإزالة الطارئة وتوثيق ذلك', 'description_en' => 'Notify lock owner of emergency removal and document', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // HAZMAT HANDLING — chemicals, regulated substances
            // Standard: OSHA 1910.1200 (HazCom), GHS/SDS
            // ════════════════════════════════════════════════
            'hazmat_handling' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'مراجعة بطاقة بيانات السلامة (SDS) للمادة المستخدمة', 'description_en' => 'Review SDS for all chemicals before handling', 'evidence_type' => 'document', 'responsible_role' => 'requester', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.1200(g)'],
                ['phase' => 'preventive', 'description_ar' => 'ارتداء مهمات الوقاية الشخصية المحددة في SDS (قفازات، نظارات، بدلة)', 'description_en' => 'Wear PPE specified in SDS: gloves, goggles, suit as required', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1910.1200'],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من جاهزية معدات الإسعافات الأولية (غسيل العيون، دش الطوارئ)', 'description_en' => 'Verify eyewash and emergency shower are operational within 10 sec', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'ANSI Z358.1'],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من توافر طقم مكافحة الانسكاب وملاءمته للمادة', 'description_en' => 'Verify spill kit is available and compatible with the chemical', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من التهوية الكافية في منطقة التخزين/الاستخدام', 'description_en' => 'Verify adequate ventilation in storage/use area', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'مراقبة علامات التعرض (دوخة، إحراق، رائحة حادة) وإيقاف العمل فوراً', 'description_en' => 'Monitor exposure signs: dizziness, burning, sharp odor — stop immediately', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'التخلص من مخلفات المواد الخطرة وفق بروتوكول التخلص المعتمد', 'description_en' => 'Dispose hazmat waste per approved disposal protocol', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'عند الانسكاب: إخلاء غير المدربين وتفعيل فريق HAZMAT', 'description_en' => 'On spill: evacuate untrained personnel, activate HAZMAT team', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'عند التعرض الجلدي: شطف فوري بالماء 15 دقيقة + طلب مساعدة طبية', 'description_en' => 'On skin contact: flush with water 15 min immediately + medical aid', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true, 'standard_reference' => 'SDS Section 4'],
                ['phase' => 'response', 'description_ar' => 'إشعار السلطات البيئية عند انسكاب خارج حدود الموقع', 'description_en' => 'Notify environmental authorities for spills beyond site boundary', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // RADIATION WORK — ionizing radiation, NDT, gauges
            // Standard: IAEA, OSHA 1910.1096
            // ════════════════════════════════════════════════
            'radiation_work' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'التحقق من ترخيص المشغّل الإشعاعي (RSO) والجهاز', 'description_en' => 'Verify radiation operator (RSO) license and source permit', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'IAEA GSR Part 3'],
                ['phase' => 'preventive', 'description_ar' => 'تحديد منطقة الإقصاء وتحديد حدودها بوضوح (تحذير الإشعاع)', 'description_en' => 'Establish and mark exclusion zone with radiation warning signs', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من معايرة جهاز قياس الإشعاع (تاريخ المعايرة < سنة)', 'description_en' => 'Verify dosimeter/survey meter calibration date < 1 year', 'evidence_type' => 'document', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'توزيع أجهزة الجرعة الشخصية (TLD/dosimeter) على جميع العاملين', 'description_en' => 'Issue personal dosimeters (TLD/film badge) to all workers', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'IAEA GSR Part 3'],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'مراقبة معدل الجرعة على حدود منطقة الإقصاء باستمرار', 'description_en' => 'Monitor dose rate at exclusion zone boundary continuously', 'evidence_type' => 'measurement', 'measurement_unit' => 'µSv/h', 'measurement_threshold' => '< 2.5 µSv/h at boundary', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'frequency' => 'continuous'],
                ['phase' => 'operational', 'description_ar' => 'التحقق من عدم دخول أي شخص غير مخوّل لمنطقة الإقصاء', 'description_en' => 'Ensure no unauthorized person enters exclusion zone', 'evidence_type' => 'check', 'responsible_role' => 'attendant', 'is_mandatory' => true, 'frequency' => 'continuous'],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'عند حادث تعرض: إخلاء المنطقة وإشعار RSO والسلطة النووية', 'description_en' => 'On exposure incident: evacuate, notify RSO and nuclear authority', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'تسجيل جرعة الطوارئ من dosimeter المتأثر فوراً', 'description_en' => 'Record emergency dose from affected worker dosimeter immediately', 'evidence_type' => 'measurement', 'measurement_unit' => 'mSv', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // PRESSURE TESTING — hydrostatic/pneumatic
            // Standard: ASME B31.3, PED 2014/68/EU
            // ════════════════════════════════════════════════
            'pressure_testing' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'مراجعة إجراء الاختبار والحد الأقصى لضغط الاختبار مع المهندس', 'description_en' => 'Review test procedure and max test pressure with engineer', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'ASME B31.3 §345'],
                ['phase' => 'preventive', 'description_ar' => 'التحقق من معايرة مقياس الضغط (تاريخ < 6 أشهر)', 'description_en' => 'Verify pressure gauge calibration date < 6 months', 'evidence_type' => 'document', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'تركيب صمام تخفيف الضغط على الجانب الأقل وتحقق من ضبطه', 'description_en' => 'Install and verify PRV (pressure relief valve) setting', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'تحديد منطقة إقصاء (لا أفراد) أثناء رفع الضغط في الاختبار الهوائي', 'description_en' => 'Establish exclusion zone (no personnel) during pneumatic pressurization', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'ASME B31.3 §345.5'],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'رفع الضغط تدريجياً (10% في كل خطوة) مع فحص التسرب', 'description_en' => 'Increase pressure in 10% increments with leak checks at each step', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                ['phase' => 'operational', 'description_ar' => 'تسجيل الضغط والزمن في كل خطوة في سجل الاختبار', 'description_en' => 'Log pressure and time at each step in test record', 'evidence_type' => 'measurement', 'measurement_unit' => 'bar/psi', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'عند تسرب أو انفجار: تخفيف الضغط فوراً وإخلاء المنطقة', 'description_en' => 'On leak or rupture: vent pressure immediately and evacuate', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'لا يُعاد اختبار النظام الفاشل إلا بعد تحقيق مهندس من السبب', 'description_en' => 'Failed system must not be retested until engineer investigates cause', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true],
            ],

            // ════════════════════════════════════════════════
            // BLASTING / DEMOLITION — explosives, controlled demo
            // Standard: OSHA 1926 Subpart U, IBRACON
            // ════════════════════════════════════════════════
            'blasting' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'التحقق من ترخيص فني التفجير والموافقة الأمنية', 'description_en' => 'Verify blaster license and security clearance', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.900'],
                ['phase' => 'preventive', 'description_ar' => 'إجراء مسح اهتزاز الأرض ووضع حدود الإقصاء المحسوبة', 'description_en' => 'Ground vibration survey and calculated exclusion zone setup', 'evidence_type' => 'document', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.909'],
                ['phase' => 'preventive', 'description_ar' => 'إشعار الجهات الأمنية والسلطات المحلية قبل 24 ساعة', 'description_en' => 'Notify security forces and local authority 24h in advance', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'فحص المنطقة بحثاً عن مرافق تحت الأرض ومباني مجاورة', 'description_en' => 'Survey for underground utilities and adjacent structures', 'evidence_type' => 'document', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'اختبار الإشارة اللاسلكية لضمان عدم تفجير مبكر (Radio frequency)', 'description_en' => 'Test radio frequency environment to prevent premature detonation', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.900(k)'],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'إطلاق صفارات التحذير (3 مرات) قبل التفجير بـ 5 دقائق', 'description_en' => 'Sound warning sirens (3 blasts) 5 min before detonation', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.909'],
                ['phase' => 'operational', 'description_ar' => 'التحقق من إخلاء منطقة الإقصاء كاملاً قبل التفجير', 'description_en' => 'Confirm exclusion zone is fully clear before detonation', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'انتظار 30 دقيقة قبل دخول المنطقة بعد التفجير (غاز الدخان)', 'description_en' => 'Wait 30 min before entering after blast (fume dissipation)', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.911'],
                ['phase' => 'response', 'description_ar' => 'معالجة المتفجرات غير المنفجرة (Misfires) بواسطة فني مرخص فقط', 'description_en' => 'Handle misfires only by licensed blaster per procedure', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.911'],
                ['phase' => 'response', 'description_ar' => 'الإبلاغ الفوري عن أي إصابة لجهات السلامة والأمن', 'description_en' => 'Immediately report injuries to safety and security authorities', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
            ],

            'crane_operations' => [
                // PREVENTIVE
                ['phase' => 'preventive', 'description_ar' => 'التحقق من شهادة فحص الرافعة وصلاحيتها قبل التشغيل', 'description_en' => 'Verify crane inspection certificate validity before operation', 'evidence_type' => 'document', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'ASME B30.5'],
                ['phase' => 'preventive', 'description_ar' => 'مراجعة جداول الحمولة والتحقق من عدم تجاوز الحمل الأقصى', 'description_en' => 'Review load charts and verify load does not exceed rated capacity', 'evidence_type' => 'document', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'ASME B30.5-2.1'],
                ['phase' => 'preventive', 'description_ar' => 'فحص معدات الرفع (سلاسل، خطافات، حلقات الربط) للتحقق من سلامتها', 'description_en' => 'Inspect all rigging equipment (slings, hooks, shackles) for defects', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'ASME B30.9'],
                ['phase' => 'preventive', 'description_ar' => 'تثبيت أذرع الدعم (Outriggers) على أرض صلبة والتحقق من استواء الرافعة', 'description_en' => 'Deploy outriggers on firm ground and verify crane is level', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                ['phase' => 'preventive', 'description_ar' => 'تحديد منطقة الإقصاء (1.5× نصف قطر الرفع) وتأمينها بحواجز', 'description_en' => 'Establish exclusion zone (1.5× lift radius) with physical barriers', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.1408'],
                ['phase' => 'preventive', 'description_ar' => 'الكشف عن خطوط الكهرباء الهوائية واتخاذ مسافة أمان ≥ 3م', 'description_en' => 'Identify overhead power lines and maintain ≥3m safe clearance', 'evidence_type' => 'check', 'responsible_role' => 'safety_engineer', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.1408(a)(2)'],
                ['phase' => 'preventive', 'description_ar' => 'تعيين مرشد إشارة مؤهل وتحديد إشارات التواصل قبل الرفع', 'description_en' => 'Designate qualified signal person and agree on hand signals before lift', 'evidence_type' => 'check', 'responsible_role' => 'issuer', 'is_mandatory' => true, 'standard_reference' => 'ASME B30.5-3.1'],
                // OPERATIONAL
                ['phase' => 'operational', 'description_ar' => 'قياس سرعة الرياح وإيقاف العمليات عند تجاوز 48 كم/ساعة', 'description_en' => 'Monitor wind speed and suspend operations if exceeding 48 km/h', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                ['phase' => 'operational', 'description_ar' => 'التحقق من توازن الحمولة قبل الرفع الكامل عبر رفع تجريبي منخفض', 'description_en' => 'Verify load balance with a low trial lift before full raise', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                ['phase' => 'operational', 'description_ar' => 'إبقاء الحمولة مرئية طوال مدة الرفع وعدم تمريرها فوق العمال', 'description_en' => 'Keep load visible throughout lift and never swing over workers', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true, 'standard_reference' => 'OSHA 1926.1425'],
                ['phase' => 'operational', 'description_ar' => 'عدم ترك الحمولة معلقة دون مراقبة في أي وقت خلال العمليات', 'description_en' => 'Never leave a suspended load unattended during operations', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                // RESPONSE
                ['phase' => 'response', 'description_ar' => 'تأمين الذراع في الوضع المنخفض وتفريغ الحمولة عند إيقاف التشغيل', 'description_en' => 'Lower boom and offload before shutdown; secure all controls', 'evidence_type' => 'check', 'responsible_role' => 'worker', 'is_mandatory' => true],
                ['phase' => 'response', 'description_ar' => 'توثيق أي شذوذ أو حادثة وإبلاغ مشرف السلامة فوراً', 'description_en' => 'Document any anomaly or incident and notify safety supervisor immediately', 'evidence_type' => 'check', 'responsible_role' => 'any', 'is_mandatory' => true],
            ],

        ];
    }
}
