<?php

namespace App\Modules\Incident\StateMachines;

use App\Core\StateMachine\StateMachine;

/**
 * آلة حالة بلاغ الشاغل — من OHSMS كما هي بأدوارنا (جدول BACKEND.md ٥-٢-ب ب).
 *
 * مسار التوجيه (new → field_received) يؤديه النظام (system_bot) فور الإرسال ما دام المنسق والفني معروفين؛
 * أول نقرة بشرية: الفني يبدأ المعالجة. الإغلاق بمسارين: موافقة المبلّغ (عادي/عاجل بمبلّغ معروف)
 * أو تحقق شخص غير المنفّذ (سري أو بلا مبلّغ). التصعيد: للمنسق ثم للجنة السلامة (وحتى تشكيلها مسؤول السلامة).
 *
 * إضافة معهدية واحدة: «وصل المركز → مغلق بملاحظة (لا يحتاج فنياً)» لمسؤول السلامة والمناوب (جدول ٥-٢-ب).
 */
class IncidentStateMachine extends StateMachine
{
    public function __construct()
    {
        parent::__construct([
            'new' => [
                'received' => ['system_admin', 'system_staff', 'system_bot'],
            ],
            'received' => [
                'referred' => ['system_admin', 'system_staff', 'system_bot'],
                'closed' => ['system_admin', 'system_staff'], // المعهد: إغلاق بملاحظة من المركز
            ],
            'referred' => [
                'ref_received' => ['safety_coordinator', 'system_bot'],
            ],
            'ref_received' => [
                'forwarded' => ['safety_coordinator', 'system_bot'],
            ],
            'forwarded' => [
                'field_received' => ['field_worker', 'system_bot'],
            ],
            'field_received' => [
                'in_progress' => ['field_worker'],
                'escalated_to_coord' => ['field_worker'],
            ],
            'in_progress' => [
                // المعهد: من تولّى المعالجة بعد التصعيد (منسق/لجنة/مسؤول السلامة) يعلّم «عولج» — الطبقة الثالثة تضمن أنه المعيَّن
                'resolved' => ['field_worker', 'safety_coordinator', 'safety_committee', 'system_admin'],
                'escalated_to_coord' => ['field_worker'],
            ],
            'resolved' => [
                'closed' => ['system_admin', 'system_staff'],
                'in_progress' => ['safety_coordinator', 'system_admin', 'system_staff'], // رفض الإغلاق
            ],
            'escalated_to_coord' => [
                'in_progress' => ['safety_coordinator'],
                'escalated_to_manager' => ['safety_coordinator'],
            ],
            'escalated_to_manager' => [
                'in_progress' => ['safety_committee', 'system_admin'],
                'closed' => ['safety_committee', 'system_admin'],
            ],
            'any' => [
                'out_of_scope' => ['system_admin', 'system_staff'],
            ],
        ]);
    }
}
