<?php

namespace App\Policies;

use App\Core\Permissions\PermissionRegistry;
use App\Models\User;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormTemplate;

/**
 * صلاحيات النماذج بجدول BACKEND.md ٤-٣-ب (`form.*`).
 * **إصلاح ٥-٨:** التعبئة في OHSMS كانت بلا صلاحية ولا تحقق من التكليف — أي مستخدم مسجَّل
 * يعبّئ أي نموذج. هنا: التعبئة لمن كُلّف بها وحده، مرة واحدة.
 */
class FormPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->can($user, 'form.list');
    }

    public function view(User $user, FormTemplate $form): bool
    {
        return $this->can($user, 'form.list') || $this->isAssigned($user, $form);
    }

    public function create(User $user): bool
    {
        return $this->can($user, 'form.create');
    }

    public function update(User $user, FormTemplate $form): bool
    {
        return $this->can($user, 'form.edit');
    }

    /** لا تُعدَّل حقول نموذج بدأت تعبئته: يفسد مقارنة النتائج. */
    public function editFields(User $user, FormTemplate $form): bool
    {
        return $this->can($user, 'form.edit') && $form->submissions()->doesntExist();
    }

    public function send(User $user, FormTemplate $form): bool
    {
        return $this->can($user, 'form.send') && $form->is_active && $form->fields()->exists();
    }

    public function track(User $user, FormTemplate $form): bool
    {
        return $this->can($user, 'form.track');
    }

    public function results(User $user, FormTemplate $form): bool
    {
        return $this->can($user, 'form.results');
    }

    /** التعبئة: مكلَّف، والنموذج مفعَّل، ولم يعبّئه من قبل. */
    public function fill(User $user, FormTemplate $form): bool
    {
        return $form->is_active
            && $this->isAssigned($user, $form)
            && $form->submissions()->where('submitted_by_id', $user->id)->doesntExist();
    }

    private function can(User $user, string $permission): bool
    {
        $role = $user->profile?->role;

        return $role !== null && PermissionRegistry::hasPermission($role, $permission);
    }

    private function isAssigned(User $user, FormTemplate $form): bool
    {
        return FormAssignment::where('form_id', $form->id)->where('assigned_to_id', $user->id)->exists();
    }
}
