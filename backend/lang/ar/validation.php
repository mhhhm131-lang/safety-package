<?php

/**
 * رسائل التحقق بالعربية.
 *
 * أُضيف في المرحلة ٧ بعدما ظهرت مفاتيح خام («validation.accepted») للمستخدم في شاشة تعبئة
 * النموذج. يفيد كل الوحدات: أي قاعدة تحقق بلا رسالة مخصصة تظهر بعربية مفهومة.
 * `:attribute` يُستبدل باسم الحقل المعرَّب الذي يمرره المتحكم في الوسيط الثالث لـ`validate()`.
 */
return [
    'accepted'      => 'يجب الموافقة على :attribute.',
    'after'         => 'يجب أن يكون :attribute بعد :date.',
    'after_or_equal' => 'يجب أن يكون :attribute في :date أو بعده.',
    'array'         => 'يجب أن يكون :attribute قائمة.',
    'before'        => 'يجب أن يكون :attribute قبل :date.',
    'boolean'       => 'حقل :attribute يقبل نعم أو لا فقط.',
    'confirmed'     => 'تأكيد :attribute غير مطابق.',
    'date'          => 'حقل :attribute ليس تاريخاً صحيحاً.',
    'different'     => 'يجب أن يختلف :attribute عن :other.',
    'digits'        => 'يجب أن يكون :attribute :digits رقماً.',
    'email'         => 'صيغة البريد في :attribute غير صحيحة.',
    'exists'        => 'القيمة المختارة في :attribute غير موجودة.',
    'file'          => 'يجب أن يكون :attribute ملفاً.',
    'image'         => 'يجب أن يكون :attribute صورة.',
    'in'            => 'القيمة المختارة في :attribute غير مسموحة.',
    'integer'       => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'max'           => [
        'array'   => 'لا يقبل :attribute أكثر من :max عنصراً.',
        'file'    => 'يجب ألا يزيد حجم :attribute على :max كيلوبايت.',
        'numeric' => 'يجب ألا يزيد :attribute على :max.',
        'string'  => 'يجب ألا يزيد :attribute على :max حرفاً.',
    ],
    'mimes'         => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'min'           => [
        'array'   => 'يجب أن يحتوي :attribute على :min عنصراً على الأقل.',
        'file'    => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string'  => 'يجب ألا يقل :attribute عن :min حرفاً.',
    ],
    'numeric'       => 'يجب أن يكون :attribute رقماً.',
    'regex'         => 'صيغة :attribute غير صحيحة.',
    'required'      => 'حقل :attribute مطلوب.',
    'required_if'   => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_with' => 'حقل :attribute مطلوب مع :values.',
    'same'          => 'يجب تطابق :attribute مع :other.',
    'string'        => 'يجب أن يكون :attribute نصاً.',
    'unique'        => 'قيمة :attribute مستعملة من قبل.',
    'uploaded'      => 'تعذّر رفع :attribute — تجاوز الحجم المسموح أو انقطع الرفع.',
    'url'           => 'صيغة الرابط في :attribute غير صحيحة.',

    'custom'     => [],
    'attributes' => [],
];
