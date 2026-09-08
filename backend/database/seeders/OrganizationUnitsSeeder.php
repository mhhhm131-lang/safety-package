<?php

namespace Database\Seeders;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\Place;
use Illuminate\Database\Seeder;

/**
 * الهيكل التنظيمي: ٣٢ وحدة من الهيكل المنشور للمعهد (بذرة DEPT_SEED في dashboard.html).
 * المكان الافتراضي: المكاتب الإدارية HZ-06. يُبذر مرة واحدة ولا يكتب فوق تعديلات المستخدم.
 */
class OrganizationUnitsSeeder extends Seeder
{
    public const UNITS = [
        ['gm', 'مكتب المدير العام', '', 'company'],
        ['comm', 'الإدارة العامة للاتصال المؤسسي', '', 'department'],
        ['audit', 'إدارة المراجعة الداخلية', '', 'department'],
        ['legal', 'الإدارة العامة للشؤون القانونية', '', 'department'],
        ['biz', 'مركز الأعمال', '', 'department'],
        ['eng', 'مركز اللغة الإنجليزية', 'biz', 'section'],
        ['v-shared', 'نائب المدير العام للخدمات المشتركة', '', 'branch'],
        ['adm-eng', 'الإدارة العامة للشؤون الإدارية والهندسية', 'v-shared', 'department'],
        ['fin', 'الإدارة العامة للشؤون المالية', 'v-shared', 'department'],
        ['it', 'الإدارة العامة لتقنية المعلومات', 'v-shared', 'department'],
        ['hr', 'الإدارة العامة للموارد البشرية', 'v-shared', 'department'],
        ['proc', 'الإدارة العامة للمشتريات والعقود', 'v-shared', 'department'],
        ['v-cons', 'نائب المدير العام للاستشارات والدراسات', '', 'branch'],
        ['cons', 'الإدارة العامة للاستشارات', 'v-cons', 'department'],
        ['res', 'مركز البحوث والدراسات', 'v-cons', 'department'],
        ['pub', 'الإدارة العامة للنشر والخدمات اللغوية', 'v-cons', 'department'],
        ['doc', 'الإدارة العامة للوثائق والمعلومات', 'v-cons', 'department'],
        ['cs', 'مركز دعم الاستشارات الإدارية', 'v-cons', 'department'],
        ['v-train', 'نائب المدير العام للتدريب', '', 'branch'],
        ['tr', 'الإدارة العامة للتدريب', 'v-train', 'department'],
        ['trops', 'الإدارة العامة لعمليات التدريب', 'v-train', 'department'],
        ['trstd', 'الإدارة العامة لمعايير التدريب وقياس الأثر', 'v-train', 'department'],
        ['v-lead', 'نائب المدير العام لتطوير القيادات والشراكات', '', 'branch'],
        ['acad', 'أكاديمية تطوير القيادات الإدارية', 'v-lead', 'department'],
        ['coop', 'الإدارة العامة للتعاون والشراكات', 'v-lead', 'department'],
        ['cust', 'الإدارة العامة لخدمة العملاء', 'v-lead', 'department'],
        ['innov', 'الإدارة العامة للتدريب المبتكر', 'v-lead', 'department'],
        ['v-plan', 'نائب المدير العام للتخطيط والتحول الاستراتيجي', '', 'branch'],
        ['plan', 'الإدارة العامة للتخطيط والجودة والتميز المؤسسي', 'v-plan', 'department'],
        ['dx', 'مكتب التحول الرقمي', 'v-plan', 'department'],
        ['chg', 'مكتب إدارة التحول', 'v-plan', 'department'],
        ['data', 'مكتب إدارة البيانات', 'v-plan', 'department'],
    ];

    public function run(): void
    {
        if (OrganizationUnit::count() > 0) {
            return; // بُذر من قبل — لا نكتب فوق تعديلات المستخدم
        }
        $hub = Place::idByCode('HZ-06');
        $ids = [];
        foreach (self::UNITS as $i => [$code, $name, $parent, $type]) {
            $u = OrganizationUnit::create([
                'code' => $code, 'name' => $name, 'unit_type' => $type,
                'parent_id' => $parent ? ($ids[$parent] ?? null) : null,
                'place_id' => $hub, 'order' => $i,
            ]);
            $ids[$code] = $u->id;
        }
    }
}
