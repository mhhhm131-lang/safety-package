<?php

namespace App\Modules\Permit\StateMachines;

use App\Core\StateMachine\StateMachine;
use App\Modules\Permit\Models\Permit;

/**
 * آلة حالة التصريح — تغطي الفئات الخمس كلها.
 *
 * المسار المعتاد (مرحلة واحدة):
 *   مسودة → مقدَّم → قيد المراجعة → معتمد → نشط → مكتمل
 *
 * المسار بمرحلتين (نوع عليه `two_stage_approval` أو خطر درجته ≥ ١٥):
 *   … قيد المراجعة → معتمد من السلامة → معتمد → نشط → مكتمل
 *
 * الأدوار بجدول BACKEND.md ٤-٣-ب:
 *   المراجعة واعتماد السلامة: مسؤول السلامة، المناوب، المنسق.
 *   **الاعتماد النهائي: مسؤول السلامة والمناوب فقط** (قرار ٢٠٢٦-٠٩-٠٧: قرار فني لا إداري).
 *   التفعيل: مسؤول السلامة والمناوب والمنسق. الإغلاق: هؤلاء + مدير المرافق (منفّذ العمل ميدانياً).
 *   الإلغاء والإيقاف: هؤلاء + مشرف المقاول (يلغي طلبه) والمكتب الاستشاري.
 * (في OHSMS كانت أسماء غير مسجَّلة: safety_manager/site_manager/site_supervisor — استُبدلت بجدول ٤-٣.)
 *
 * الحرّاس المعتمدة على بيانات أخرى (اكتمال البنود، التعارض، ثغرات العمال، الانحرافات المفتوحة)
 * في PermitService::transition لأنها لا تُعبَّر عنها بالحالة وحدها.
 */
class PermitStateMachine extends StateMachine
{
    /** مراجعة واعتماد السلامة. */
    public const REVIEWERS = ['system_admin', 'system_staff', 'safety_coordinator'];

    /** الاعتماد النهائي — قرار فني عند مسؤول السلامة والمناوب. */
    public const FINAL_APPROVERS = ['system_admin', 'system_staff'];

    public function __construct()
    {
        parent::__construct([
            Permit::STATUS_DRAFT => [
                Permit::STATUS_SUBMITTED => ['any'], // الحارس صلاحية permit.create/edit في السياسة
            ],
            Permit::STATUS_SUBMITTED => [
                Permit::STATUS_UNDER_REVIEW => self::REVIEWERS,
                Permit::STATUS_DRAFT        => ['any'], // إعادة للمسودة للتعديل
            ],
            Permit::STATUS_UNDER_REVIEW => [
                Permit::STATUS_APPROVED        => self::FINAL_APPROVERS, // مسار مرحلة واحدة
                Permit::STATUS_SAFETY_APPROVED => self::REVIEWERS,       // أول مرحلتي الاعتماد
                Permit::STATUS_CONDITIONAL     => self::REVIEWERS,
                Permit::STATUS_REJECTED        => self::REVIEWERS,
                Permit::STATUS_SUBMITTED       => self::REVIEWERS,
            ],
            Permit::STATUS_SAFETY_APPROVED => [
                Permit::STATUS_APPROVED => self::FINAL_APPROVERS,
                Permit::STATUS_REJECTED => self::FINAL_APPROVERS,
            ],
            Permit::STATUS_CONDITIONAL => [
                Permit::STATUS_APPROVED => self::FINAL_APPROVERS,
                Permit::STATUS_REJECTED => self::REVIEWERS,
            ],
            Permit::STATUS_APPROVED => [
                Permit::STATUS_ACTIVE => self::REVIEWERS,
            ],
            Permit::STATUS_ACTIVE => [
                Permit::STATUS_COMPLETED => [...self::REVIEWERS, 'facilities_manager'],
                Permit::STATUS_EXPIRED   => ['system'], // المجدول وحده
                Permit::STATUS_SUSPENDED => self::REVIEWERS,
            ],
            Permit::STATUS_SUSPENDED => [
                Permit::STATUS_ACTIVE => self::REVIEWERS,
            ],
            'any' => [
                Permit::STATUS_CANCELLED => [...self::REVIEWERS, 'contractor_supervisor', 'consultant_office'],
            ],
        ], Permit::STATUS_LABELS);
    }
}
