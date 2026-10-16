<?php

namespace App\Modules\Form\Services;

use App\Core\Services\NotificationService;
use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormTemplate;

/**
 * إشعارات النماذج بقناتي §٦ فقط: داخل النظام + بريد.
 * **إصلاح ٥-٨:** التذكير في OHSMS كان يختم `reminded_at` ولا يُرسل شيئاً.
 */
class FormNotifier
{
    public function __construct(private readonly NotificationService $inbox) {}

    public function assigned(FormAssignment $assignment): void
    {
        $form = $assignment->form ?: $assignment->form()->first();
        if (!$form) {
            return;
        }

        $due = $assignment->due_date ? ' المهلة: '.$assignment->due_date->format('Y-m-d').'.' : '';
        $this->inbox->create(
            $assignment->assigned_to_id,
            'form.assigned',
            'نموذج بانتظارك: '.$form->title,
            $form->getTypeLabel().'.'.$due,
            "/app/forms/{$form->id}/fill",
        );
    }

    public function reminded(FormAssignment $assignment): void
    {
        $form = $assignment->form ?: $assignment->form()->first();
        if (!$form) {
            return;
        }

        $late = $assignment->status === FormAssignment::STATUS_OVERDUE ? ' — تجاوز المهلة' : '';
        $this->inbox->create(
            $assignment->assigned_to_id,
            'form.reminder',
            'تذكير بنموذج: '.$form->title.$late,
            'لم يُسجَّل تعبئتك بعد.'
                .($assignment->due_date ? ' المهلة: '.$assignment->due_date->format('Y-m-d').'.' : ''),
            "/app/forms/{$form->id}/fill",
        );
    }

    /** عند تجاوز المهلة: يُنبَّه المكلَّف ومن كلّفه. */
    public function overdue(FormAssignment $assignment): void
    {
        $form = $assignment->form ?: $assignment->form()->first();
        if (!$form) {
            return;
        }

        $this->inbox->create(
            $assignment->assigned_to_id,
            'form.overdue',
            'تأخر نموذج: '.$form->title,
            'تجاوزت مهلة التعبئة ('.$assignment->due_date?->format('Y-m-d').').',
            "/app/forms/{$form->id}/fill",
        );

        if ($assignment->assigned_by_id && $assignment->assigned_by_id !== $assignment->assigned_to_id) {
            // ٢٩-٢: في القالب السري يُعرف العدد لا الاسم — حتى من لم يعبّئ (الاسم ضغط، والضغط يفسد الصدق)
            $who = $form->allows_anonymous ? 'مكلَّف' : ($assignment->assignedTo?->name ?? 'مكلَّف');
            $this->inbox->create(
                $assignment->assigned_by_id,
                'form.overdue',
                'تأخر مكلَّف عن نموذج: '.$form->title,
                $who.' تجاوز المهلة.',
                "/app/forms/{$form->id}/tracking",
            );
        }
    }

    /** ٢٩-٤: اعتمد المدير بطاقة إدارته — يُعلَم مسؤول السلامة والمناوب (٢٩-٥: ليرسلا الإقرارات). */
    public function roundApproved(\App\Modules\Form\Models\FormRound $round, \App\Models\User $by): void
    {
        $unit = $round->organizationUnit?->name ?? '—';
        $this->inbox->notifyRoles(
            ['system_admin', 'system_staff'],
            'form.round',
            'عادت بطاقة إدارة «'.$unit.'» — الجولة '.$round->round_no,
            'اعتمدها مديرها '.$by->name.': «البيانات صحيحة وأعتمدها».',
            "/app/forms/rounds/{$round->id}",
        );
    }

    /** عند توليد نموذج من خطر: يُعلَم مسؤول السلامة والمناوب ليكلّفا به. */
    public function generatedFromRisks(FormTemplate $form, int $riskCount): void
    {
        $this->inbox->notifyRoles(
            ['system_admin', 'system_staff'],
            'form.generated',
            'نموذج جديد من المخاطر: '.$form->title,
            "وُلّد من {$riskCount} خطراً بنوع «{$form->getTypeLabel()}». يحتاج تكليفاً.",
            "/app/forms/{$form->id}",
        );
    }
}
