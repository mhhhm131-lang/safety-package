<?php

namespace App\Modules\Form\Services;

use App\Models\User;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Governance\Models\OrganizationUnit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ٢٩-٠ (قرار ٨٧، وثيقة التصميم §٤-٦): من يرى أي تكليف وتعبئة في وحدة النماذج — قاعدة واحدة تُبنى مرة
 * وتُقرأ في القائمة والنتائج والمتابعة والتصدير معاً (على مثال `IncidentVisibilityService::rule`).
 *
 *   الكل     مسؤول السلامة، المناوب (بمبناه)، الإدارة العليا، اللجنة الدائمة، وخانة «يرى كل الفروع»؛ ومنسق سلامة بلا وحدة ولا مكان (قاعدة قائمة).
 *   الفرع    مدير الفرع ومنسق سلامة الفرع (قرار ٨١): وحدات فرعه وما تحتها.
 *   الوحدة   مدير الإدارة/القسم والمديرون الثلاثة الخاصون ومنسق الإدارة: وحدته وما تحتها، لا غير.
 *   الذات    الموظف وغيره: تكليفاته هو (في «نماذجي»)، ولا يرى نتيجة ولا متابعة.
 *
 * المفتاح الوحدة في الهيكل لا المكان (بكلمته)؛ والمبنى يقيّده `BelongsToBuilding` على الصفوف نفسها قبل هذه القاعدة (قرار ٨٤).
 * القوالب عامة لكل المباني (هي الحزمة نفسها)؛ ما يُعزل هو ما يُعبَّأ.
 */
final class FormVisibilityService
{
    private const ALL  = ['system_admin', 'system_staff', 'top_management', 'safety_committee'];
    private const UNIT = ['branch_manager', 'department_manager', 'section_manager', 'security_safety_head', 'admin_eng_manager', 'facilities_manager', 'safety_coordinator'];

    /** @return array{all: bool, units: int[]}|null null = بلا حساب فعّال */
    public function rule(?User $user): ?array
    {
        $p = $user?->profile;
        if (!$p || !$p->is_active) return null;
        $role = $p->role;
        if (in_array($role, self::ALL, true) || $p->sees_all_buildings) return ['all' => true, 'units' => []];
        if ($role === 'safety_coordinator' && !$p->organization_unit_id && !$p->place_id) return ['all' => true, 'units' => []];
        if (in_array($role, self::UNIT, true) && $p->organization_unit_id) {
            return ['all' => false, 'units' => array_map('intval', OrganizationUnit::descendantIdsOf($p->organization_unit_id))];
        }
        return ['all' => false, 'units' => []];
    }

    /** يقيّد استعلاماً على التكليفات أو التعبئات (كلاهما يحمل `organization_unit_id`) بما يراه الحساب */
    public function apply(Builder|Relation $q, ?User $user): Builder|Relation
    {
        $rule = $this->rule($user);
        if ($rule === null) return $q->whereRaw('1 = 0');
        if ($rule['all']) return $q;
        if ($rule['units'] === []) return $q->whereRaw('1 = 0');
        $col = $q->getModel()->getTable().'.organization_unit_id';
        return $q->whereIn($col, $rule['units']);
    }

    public function canSeeUnit(?User $user, ?int $unitId): bool
    {
        $rule = $this->rule($user);
        if ($rule === null) return false;
        if ($rule['all']) return true;
        return $unitId !== null && in_array((int) $unitId, $rule['units'], true);
    }

    /** تعبئة واحدة: صاحبها، أو من يرى وحدتها */
    public function canSeeSubmission(?User $user, FormSubmission $s): bool
    {
        if ($user && $s->submitted_by_id !== null && (int) $s->submitted_by_id === (int) $user->id) return true;
        return $this->canSeeUnit($user, $s->organization_unit_id === null ? null : (int) $s->organization_unit_id);
    }
}
