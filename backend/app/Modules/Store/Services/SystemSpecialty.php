<?php

namespace App\Modules\Store\Services;

/**
 * المرحلة ٢٠-٥ (قرار ٥١): جدول «النظام ← التخصص» المعتمد بكلمة المستخدم «موافق» (٢٠٢٦-٠٩-٢٠).
 * مفاتيح الأنظمة كما في النماذج العشرة (e = الكهرباء، a = التكييف، s = الحريق، d = مركز البيانات، p = القبو، o = المكاتب،
 * f = المطاعم، c = مركز السلامة). ما ليس هنا بلا تخصص: يصله من يذكره عمود «من» في النموذج، وإن كان «الفني المختص» فأي فني يغطي المكان.
 * ثابت واحد في الكود؛ تغييره قرار المستخدم ثم مطوّر.
 */
final class SystemSpecialty
{
    public const MAP = [
        // فني الكهرباء (١٠)
        'e01' => 'tech_electrical', 'e02' => 'tech_electrical', 'e03' => 'tech_electrical', 'e05' => 'tech_electrical', 'e06' => 'tech_electrical',
        'e07' => 'tech_electrical', 'e08' => 'tech_electrical', 'o02' => 'tech_electrical', 'd03' => 'tech_electrical', 'p04' => 'tech_electrical',
        // فني المولد الاحتياطي (٢)
        'e04' => 'tech_generator', 's09' => 'tech_generator',
        // فني لوحة الإنذار والحريق (١٢)
        's01' => 'tech_fire_alarm', 's02' => 'tech_fire_alarm', 's05' => 'tech_fire_alarm', 's06' => 'tech_fire_alarm', 's07' => 'tech_fire_alarm',
        's12' => 'tech_fire_alarm', 's10' => 'tech_fire_alarm', 'd01' => 'tech_fire_alarm', 'd02' => 'tech_fire_alarm', 'p02' => 'tech_fire_alarm',
        'f02' => 'tech_fire_alarm', 'c01' => 'tech_fire_alarm',
        // فني مضخة الحريق (٢)
        's03' => 'tech_fire_pump', 's04' => 'tech_fire_pump',
        // فني التكييف ونظام الدخان (١٠)
        'a01' => 'tech_hvac', 'a02' => 'tech_hvac', 'a03' => 'tech_hvac', 'a04' => 'tech_hvac', 'a05' => 'tech_hvac', 'a06' => 'tech_hvac', 'a07' => 'tech_hvac',
        's08' => 'tech_hvac', 'd04' => 'tech_hvac', 'p01' => 'tech_hvac',
        // فني المصاعد (١)
        's11' => 'tech_elevator',
    ];

    /** تخصص النظام، أو null بلا تخصص. يقبل مفتاح النظام أو صف البلاغ (e01-i-0). */
    public static function for(string $keyOrRow): ?string
    {
        $k = explode('-', $keyOrRow)[0];
        return self::MAP[$k] ?? null;
    }

    /** هل يصلح هذا الدور لهذا النظام؟ الدور القديم (الفني المنفّذ) يصلح لكل نظام حتى يُنقل؛ والنظام بلا تخصص لأي فني. */
    public static function fits(string $role, string $keyOrRow): bool
    {
        if (!\App\Core\Permissions\PermissionRegistry::isTech($role)) return false;
        if ($role === 'field_worker') return true;
        $need = self::for($keyOrRow);
        return $need === null || $need === $role;
    }
}
