<?php

namespace Database\Seeders;

use App\Modules\Permit\Models\PermitType;
use App\Modules\Permit\Models\PermitTypeConflictRule;
use Illuminate\Database\Seeder;

/**
 * قواعد التعارض العامة (تسري في كل مكان ما لم يُقيَّدها المستخدم بمكان من شاشة الإعدادات).
 * منقولة من OHSMS كما هي — أساسها ممارسات السلامة المهنية المعروفة:
 *
 *   أعمال ساخنة × نقل مواد خطرة   → مانع (مصدر إشعال مع مواد قابلة للاشتعال)
 *   أعمال ساخنة × مكان محصور      → مانع (استهلاك أكسجين وخطر انفجار)
 *   أعمال كهربائية × حفريات       → مانع (كابلات مدفونة)
 *   حفريات × عمل على ارتفاع       → تنبيه (منطقة سقوط — يُفضَّل الفصل الزمني)
 *   أعمال ساخنة × عمل على ارتفاع  → تنبيه (شرر يسقط على ما تحته)
 *
 * غير تكرارية: مفتاحها (النوعان + المكان).
 */
class PermitConflictRulesSeeder extends Seeder
{
    public function run(): void
    {
        $types = PermitType::pluck('id', 'code');

        $rules = [
            ['hot_work', 'hazmat_transport', PermitTypeConflictRule::SEVERITY_BLOCK,
                'لا يُسمح بعمل ساخن أثناء نقل أو تخزين مواد قابلة للاشتعال في المكان نفسه.'],
            ['hot_work', 'confined_space', PermitTypeConflictRule::SEVERITY_BLOCK,
                'العمل الساخن في مكان محصور يستهلك الأكسجين ويرفع خطر الانفجار.'],
            ['electrical_work', 'excavation', PermitTypeConflictRule::SEVERITY_BLOCK,
                'الحفر قرب أعمال كهربائية نشطة يعرّض الكابلات المدفونة.'],
            ['excavation', 'work_at_height', PermitTypeConflictRule::SEVERITY_WARN,
                'الحفر تحت عمل على ارتفاع يجعل المنطقة منطقة سقوط — يُفضَّل الفصل الزمني.'],
            ['hot_work', 'work_at_height', PermitTypeConflictRule::SEVERITY_WARN,
                'شرر اللحام يسقط على ما تحته وقد يشعل مواد في الطابق الأسفل.'],
        ];

        foreach ($rules as [$a, $b, $severity, $reason]) {
            if (!isset($types[$a], $types[$b])) {
                continue;
            }
            PermitTypeConflictRule::updateOrCreate(
                ['permit_type_a_id' => $types[$a], 'permit_type_b_id' => $types[$b], 'place_id' => null],
                ['severity' => $severity, 'reason' => $reason, 'is_active' => true],
            );
        }
    }
}
