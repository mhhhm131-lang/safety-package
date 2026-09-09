<?php

namespace App\Policies;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Permit\Models\Permit;

/**
 * صلاحيات التصاريح بجدول BACKEND.md ٤-٣-ب (`permit.*`).
 * (في OHSMS كانت `PermitPolicy` تفحص صلاحيات النظام القديم `permit_request.*` — أُصلح.)
 *
 * الحالة تُفحص هنا أيضاً حتى لا تُعرض أزرار لا تعمل: آلة الحالة هي الحكم النهائي في الخدمة.
 * حساب الطرف الخارجي (مقاول/مشرف/مكتب استشاري) يرى ويعدّل تصاريح طرفه فقط.
 */
class PermitPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'permit.list');
    }

    public function view(User $user, Permit $permit): bool
    {
        return $this->can($user, 'permit.list') && $this->sameParty($user, $permit);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'permit.create');
    }

    public function update(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_DRAFT
            && $this->can($user, 'permit.edit')
            && $this->sameParty($user, $permit);
    }

    /** مسودة ← مقدَّم: من يملك الإنشاء أو التعديل (المقاول يقدّم تصريحه). */
    public function submit(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_DRAFT
            && ($this->can($user, 'permit.create') || $this->can($user, 'permit.edit'))
            && $this->sameParty($user, $permit);
    }

    /** مقدَّم ← قيد المراجعة. */
    public function review(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_SUBMITTED && $this->can($user, 'permit.review');
    }

    /** قيد المراجعة ← معتمد من السلامة. */
    public function safetyApprove(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_UNDER_REVIEW && $this->can($user, 'permit.safety_approve');
    }

    /** الاعتماد النهائي — مسؤول السلامة والمناوب. */
    public function approve(User $user, Permit $permit): bool
    {
        return in_array($permit->status, [
                Permit::STATUS_UNDER_REVIEW, Permit::STATUS_SAFETY_APPROVED, Permit::STATUS_CONDITIONAL,
            ], true)
            && $this->can($user, 'permit.final_approve');
    }

    public function reject(User $user, Permit $permit): bool
    {
        return in_array($permit->status, [
                Permit::STATUS_UNDER_REVIEW, Permit::STATUS_SAFETY_APPROVED, Permit::STATUS_CONDITIONAL,
            ], true)
            && ($this->can($user, 'permit.review') || $this->can($user, 'permit.final_approve'));
    }

    /** اعتماد مشروط (يُعتمد بانتظار إصلاح يسير). */
    public function conditional(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_UNDER_REVIEW && $this->can($user, 'permit.review');
    }

    public function activate(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_APPROVED && $this->can($user, 'permit.activate');
    }

    public function complete(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_ACTIVE && $this->can($user, 'permit.activate');
    }

    public function suspend(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_ACTIVE && $this->can($user, 'permit.cancel');
    }

    public function resume(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_SUSPENDED && $this->can($user, 'permit.activate');
    }

    public function cancel(User $user, Permit $permit): bool
    {
        return !$permit->isTerminal()
            && $this->can($user, 'permit.cancel')
            && $this->sameParty($user, $permit);
    }

    /** تسجيل انحراف: على تصريح نشط، لمن يعمل عليه أو يشرف. */
    public function recordDeviation(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_ACTIVE
            && ($this->can($user, 'permit.edit') || $this->can($user, 'permit.activate'))
            && $this->sameParty($user, $permit);
    }

    /** إتمام بند (رفع دليل): المقاول يرفع أدلة تصريحه، والفريق يوثّق البنود. */
    public function completeRequirement(User $user, Permit $permit): bool
    {
        return !$permit->isTerminal()
            && ($this->can($user, 'permit.edit') || $this->can($user, 'permit.activate'))
            && $this->sameParty($user, $permit);
    }

    /** التقييم البعدي على التصاريح المكتملة. */
    public function evaluate(User $user, Permit $permit): bool
    {
        return $permit->status === Permit::STATUS_COMPLETED && $this->can($user, 'permit.review');
    }

    // ── مساعدات ──

    private function can(User $user, string $permission): bool
    {
        $role = $user->profile?->role;

        return $role !== null && PermissionRegistry::hasPermission($role, $permission);
    }

    /** حساب الطرف الخارجي لا يتجاوز تصاريح طرفه. */
    private function sameParty(User $user, Permit $permit): bool
    {
        if (!$user->isContractorRole()) {
            return true;
        }

        return $user->external_party_id !== null
            && (int) $permit->external_party_id === (int) $user->external_party_id;
    }
}
