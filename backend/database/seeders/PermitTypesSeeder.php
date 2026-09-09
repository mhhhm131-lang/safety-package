<?php

namespace Database\Seeders;

use App\Modules\Permit\Models\PermitType;
use Illuminate\Database\Seeder;

/**
 * كتالوج أنواع التصاريح — منقول من OHSMS كاملاً (قرار §٥-٧: تبقى كلها مفعّلة،
 * ومسؤول السلامة يوقف ما لا يلزم المعهد من شاشة الإعدادات بدل حذفه).
 *
 * ما تغيّر عن OHSMS: `requires_zone` → `requires_place` (المكان)، وحُذف `requires_activity`
 * لأن الأنشطة الاقتصادية خارج النطاق. الاعتماد بمرحلتين كما هو (يُفعّل مسار «معتمد من السلامة»).
 * غير تكراري: `updateOrCreate` بالرمز.
 */
class PermitTypesSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->types() as $type) {
            PermitType::updateOrCreate(['code' => $type['code']], $type);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function types(): array
    {
        return [
            // ── تأهيل ──
            [
                'code' => 'contractor_pre_qualification',
                'name' => 'تأهيل المقاول قبل التعاقد',
                'name_en' => 'Contractor Pre-Contract Qualification',
                'category' => PermitType::CATEGORY_QUALIFICATION,
                'description' => 'وثائق المنشأة: السجل التجاري، التأمين، سياسة السلامة، سجل الحوادث. قبل توقيع أي عقد.',
                'default_validity_days' => 365,
                'requires_project' => true, 'requires_contractor' => true,
            ],
            [
                'code' => 'contractor_post_qualification',
                'name' => 'تأهيل المقاول قبل بدء العمل',
                'name_en' => 'Contractor Post-Contract Qualification',
                'category' => PermitType::CATEGORY_QUALIFICATION,
                'description' => 'جاهزية العمال: الشهادات، التدريب، الفحص الطبي، معدات الوقاية. يفتح تصاريح العمل التشغيلية.',
                'default_validity_days' => 180,
                'requires_project' => true, 'requires_contractor' => true,
            ],
            [
                'code' => 'internal_unit_qualification',
                'name' => 'تأهيل وحدة داخلية',
                'name_en' => 'Internal Unit Qualification',
                'category' => PermitType::CATEGORY_QUALIFICATION,
                'description' => 'تأهيل إدارة أو قسم من المعهد لتنفيذ عمل ذي خطر بنفسه.',
                'default_validity_days' => 365,
                'requires_project' => false,
            ],

            // ── عمل ──
            [
                'code' => 'work_permit',
                'name' => 'تصريح عمل عام',
                'name_en' => 'General Work Permit',
                'category' => PermitType::CATEGORY_WORK,
                'description' => 'عمل لا ينطبق عليه نوع متخصص. يحدد المكان والمدة والاحتياطات.',
                'default_validity_days' => 30,
                'requires_place' => true,
            ],
            [
                'code' => 'contractor_operational_permit',
                'name' => 'تصريح عمل المقاول التشغيلي',
                'name_en' => 'Contractor Operational Work Permit',
                'category' => PermitType::CATEGORY_WORK,
                'description' => 'يُصدر بعد اعتماد تأهيل ما بعد التعاقد. يسمح لطاقم المقاول بالعمل، وتُشتق منه تصاريح دخول أفراده.',
                'default_validity_days' => 90,
                'requires_project' => true, 'requires_contractor' => true, 'requires_place' => true,
                'two_stage_approval' => true,
            ],
            [
                'code' => 'work_start_clearance',
                'name' => 'إذن بدء العمل',
                'name_en' => 'Work Start Clearance',
                'category' => PermitType::CATEGORY_WORK,
                'description' => 'إذن يومي يؤكد اكتمال الاشتراطات قبل أول أداة: التحليل، الحديث التعريفي، معدات الوقاية، تأمين المنطقة.',
                'default_validity_days' => 1,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'night_work',
                'name' => 'تصريح العمل الليلي',
                'name_en' => 'Night Work Permit',
                'category' => PermitType::CATEGORY_WORK,
                'description' => 'عمل خارج ساعات الدوام: خطة إضاءة، وجود مشرف، وقائمة اتصال للطوارئ.',
                'default_validity_days' => 7,
                'requires_place' => true,
            ],

            // ── خاص (عالي الخطورة) ──
            [
                'code' => 'hot_work',
                'name' => 'تصريح الأعمال الساخنة',
                'name_en' => 'Hot Work Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'لحام أو قطع أو جلخ أو لهب مكشوف — كل ما يولّد شرراً أو حرارة قرب مواد قابلة للاشتعال.',
                'default_validity_days' => 7,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'confined_space',
                'name' => 'تصريح دخول الأماكن المحصورة',
                'name_en' => 'Confined Space Entry Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'دخول خزانات أو غرف أو حفر رديئة التهوية. قياس الجو ومراقب خارجي إلزاميان.',
                'default_validity_days' => 1,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'work_at_height',
                'name' => 'تصريح العمل على ارتفاع',
                'name_en' => 'Work at Height Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'عمل يزيد على مترين عن سطح الأرض. نظام منع سقوط وتطويق وخطة إنقاذ.',
                'default_validity_days' => 7,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'excavation',
                'name' => 'تصريح الحفريات',
                'name_en' => 'Excavation Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'كسر الأرض: مسح المرافق المدفونة، تصنيف التربة، خطة إسناد، وحماية الحواف.',
                'default_validity_days' => 30,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'electrical_work',
                'name' => 'تصريح الأعمال الكهربائية',
                'name_en' => 'Electrical Work Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'عمل على دوائر حية أو معزولة ولوحات التوزيع. فني مؤهل والتحقق من العزل.',
                'default_validity_days' => 7,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'loto',
                'name' => 'تصريح عزل الطاقة (LOTO)',
                'name_en' => 'Lockout / Tagout Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'عزل كل مصادر الطاقة (كهربائية، هيدروليكية، هوائية، حرارية) قبل الصيانة.',
                'default_validity_days' => 3,
                'requires_equipment' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'hazmat_handling',
                'name' => 'تصريح التعامل مع المواد الخطرة',
                'name_en' => 'Hazardous Materials Handling Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'تخزين أو استعمال مواد كيميائية خاضعة للتنظيم: مراجعة بطاقة السلامة، عدة احتواء، ومناوِل مدرَّب.',
                'default_validity_days' => 30,
                'requires_place' => true,
            ],
            [
                'code' => 'hazmat_transport',
                'name' => 'تصريح نقل المواد الخطرة',
                'name_en' => 'Hazardous Materials Transport Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'نقل مواد خطرة داخل المبنى أو إليه: لوحات تعريف، مسار معتمد، وسائق مؤهل.',
                'default_validity_days' => 7,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'pressure_testing',
                'name' => 'تصريح اختبار الضغط',
                'name_en' => 'Pressure Testing Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'اختبار ضغط للأنابيب أو الأوعية: خطة اختبار، منطقة عزل، والتحقق من صمام التنفيس.',
                'default_validity_days' => 3,
                'requires_place' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'radiation_work',
                'name' => 'تصريح الأعمال الإشعاعية',
                'name_en' => 'Radiation Work Permit',
                'category' => PermitType::CATEGORY_SPECIAL,
                'description' => 'استعمال مصادر إشعاع مؤيّن: منطقة استبعاد، قياس جرعات، ومشغّل مرخَّص.',
                'default_validity_days' => 1,
                'requires_place' => true, 'two_stage_approval' => true,
            ],

            // ── عامل ──
            [
                'code' => 'worker_site_access',
                'name' => 'تصريح دخول العامل للعمل',
                'name_en' => 'Worker Site Access Permit',
                'category' => PermitType::CATEGORY_WORKER,
                'description' => 'إذن دخول لعامل بعينه. يُولَّد آلياً عند فحص الجاهزية إن اجتاز الفحوص وكان لمقاوله تصريح تشغيلي نشط.',
                'default_validity_days' => 30,
                'requires_worker' => true,
            ],
            [
                'code' => 'individual_task_permit',
                'name' => 'تصريح مهمة محددة',
                'name_en' => 'Individual Task Authorization',
                'category' => PermitType::CATEGORY_WORKER,
                'description' => 'إذن لعامل بعينه لأداء مهمة تتطلب كفاءة موثقة (تشغيل سقالة، إسعاف، تشغيل آلة).',
                'default_validity_days' => 90,
                'requires_worker' => true,
            ],
            [
                'code' => 'worker_role_authorization',
                'name' => 'تفويض دور خاص',
                'name_en' => 'Worker Role Authorization',
                'category' => PermitType::CATEGORY_WORKER,
                'description' => 'تفويض بدور عالي الخطورة: مراقب أماكن محصورة، مرافق حريق، عضو فريق إنقاذ.',
                'default_validity_days' => 90,
                'requires_worker' => true,
            ],
            [
                'code' => 'visitor_pass',
                'name' => 'تصريح زائر',
                'name_en' => 'Visitor Pass',
                'category' => PermitType::CATEGORY_WORKER,
                'description' => 'إذن دخول قصير لزائر (ليس عاملاً): تعريف بالسلامة ومرافق.',
                'default_validity_days' => 1,
                'requires_place' => true,
            ],
            [
                'code' => 'vehicle_entry',
                'name' => 'تصريح دخول مركبة',
                'name_en' => 'Vehicle Entry Permit',
                'category' => PermitType::CATEGORY_WORKER,
                'description' => 'دخول مركبة (شاحنة، رافعة) إلى المبنى ومواقفه: فحص المركبة ورخصة السائق.',
                'default_validity_days' => 30,
                'requires_place' => true,
            ],

            // ── معدات ──
            [
                'code' => 'equipment_operation',
                'name' => 'تصريح تشغيل معدة',
                'name_en' => 'Equipment Operation Permit',
                'category' => PermitType::CATEGORY_EQUIPMENT,
                'description' => 'إذن تشغيل معدة بعينها: شهادة المشغّل وفحص ما قبل الاستعمال.',
                'default_validity_days' => 90,
                'requires_equipment' => true,
            ],
            [
                'code' => 'crane_operations',
                'name' => 'تصريح أعمال الرفع بالرافعات',
                'name_en' => 'Crane Operations Permit',
                'category' => PermitType::CATEGORY_EQUIPMENT,
                'description' => 'أعمال الرافعات والونشات: تصنيف الرفع الحرج، مراجعة خطة التحزيم، وحدود سرعة الرياح.',
                'default_validity_days' => 30,
                'requires_place' => true, 'requires_equipment' => true, 'two_stage_approval' => true,
            ],
            [
                'code' => 'equipment_inspection',
                'name' => 'شهادة فحص دوري لمعدة',
                'name_en' => 'Equipment Inspection Certificate',
                'category' => PermitType::CATEGORY_EQUIPMENT,
                'description' => 'شهادة فحص دورية (يومي، شهري، سنوي بطرف ثالث). لا تُشغَّل المعدة بشهادة منتهية.',
                'default_validity_days' => 30,
                'requires_equipment' => true,
            ],
        ];
    }
}
