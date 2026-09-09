<?php

namespace App\Modules\Emergency\StateMachines;

use App\Core\Permissions\PermissionRegistry;
use App\Core\StateMachine\StateMachine;

/**
 * آلة حالة الحالة الطارئة — لم تكن في OHSMS (خلل مؤكد ٥-٣؛ الحالة كانت تُعدَّل مباشرة).
 *
 * active → contained (تمت السيطرة) → ended (انتهى الخطر)؛ active → ended مباشرة؛ any → cancelled (إنذار كاذب/تفعيل خطأ).
 * الأدوار من سجل الصلاحيات (مصدر واحد): من يملك «emergency.trigger» يسيطر ويُنهي (مسؤول السلامة والمناوب والمنسق
 * ومديرو الإدارات ومدير الشؤون الإدارية والهندسية ومدير المرافق ورئيس الأمن والسلامة — خطط الاستجابة: قائد الطوارئ + المرافق + الأمن)،
 * والإلغاء لمن يملك «emergency.manage» (مسؤول السلامة والمناوب والمنسق).
 */
class EmergencyStateMachine extends StateMachine
{
    public function __construct()
    {
        $control = PermissionRegistry::PERMISSIONS['emergency.trigger'];
        $manage = PermissionRegistry::PERMISSIONS['emergency.manage'];

        parent::__construct([
            'active' => [
                'contained' => $control,
                'ended' => $control,
            ],
            'contained' => [
                'ended' => $control,
                'active' => $control, // عادت الحالة (السيطرة لم تكتمل)
            ],
            'any' => [
                'cancelled' => $manage,
            ],
        ], \App\Modules\Emergency\Models\EmergencyIncident::STATUS_LABELS);
    }
}
