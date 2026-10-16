<?php

namespace App\Policies;

use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Services\FormVisibilityService;
use App\Modules\Form\Services\RoundService;

/**
 * ٢٩-٤: صلاحيات الجولة.
 *   الصفحة: من يرى وحدتها بقاعدة الرؤية (٢٩-٠)، أو المكلَّف فيها (مدير الإدارة). المبنى يحرسه `BelongsToBuilding` قبلها (403).
 *   الفتح: `form.send` (مسؤول السلامة والمناوب والمنسق؛ اللجنة ترى ولا تضغط — قرار ٨٧-٨).
 *   الاعتماد: مدير هذه الوحدة بعينها (وثيقة التصميم §٤-٦ البند ٤).
 */
class FormRoundPolicy
{
    public function __construct(private readonly FormVisibilityService $visibility) {}

    /** ٢٩-٧: تبويب «الطبقة الثانية» لمن يرى الكل أو وحدةً (الإدارة العليا واللجنة ومسؤول السلامة والمناوب والمدراء والمنسقون) — لا الموظف */
    public function viewAny(User $user): bool
    {
        $rule = $this->visibility->rule($user);

        return $rule !== null && ($rule['all'] || $rule['units'] !== []);
    }

    public function view(User $user, FormRound $round): bool
    {
        if ($this->visibility->canSeeUnit($user, $round->organization_unit_id === null ? null : (int) $round->organization_unit_id)) {
            return true;
        }

        // ٢٩-٦: المكلَّف ببطاقة الإدارة (المدير) لا المكلَّف بالإقرار السري (الموظف لا يرى الجولة ولا أرقامها)
        return FormAssignment::withoutGlobalScope('building')->where('round_id', $round->id)->where('assigned_to_id', $user->id)
            ->whereHas('form', fn ($q) => $q->where('package_code', \App\Modules\Form\Support\NonMaterialPackage::CODE))
            ->exists();
    }

    public function open(User $user): bool
    {
        return $user->can_('form.send');
    }

    public function approve(User $user, FormRound $round): bool
    {
        return RoundService::isManagerOf($user, (int) $round->organization_unit_id);
    }

    /** ٢٩-٦: الإقفال لمن يفتح (`form.send`)؛ اللجنة ترى بلا أزرار */
    public function close(User $user, FormRound $round): bool
    {
        return $user->can_('form.send');
    }
}
