<?php

namespace App\Modules\Emergency\Support;

/**
 * سجل بطاقات الأدوار الـ٢١ (SOURCE.md §٣ وBACKEND.md ٩-و-٢) — ثابت في الكود (المرحلة ١٠-١، المكوّن ب).
 *
 * لكل بطاقة: الرقم، الاسم، الصفة، الفئة، ملف البطاقة، ومقابلها في النظام:
 *   role  = دور في PermissionRegistry (له حساب)
 *   team  = عضو الفريق الأولي بالمكان (emergency_team_members.role_key من ملف المكان في اللوحة)
 *   none  = بلا حساب (الشاغلون)
 * المطابقة بالأشخاص (من يشغل البطاقة ٤ أو ١٤…) عمل المستخدم من شاشة المستخدمين — لا تُخترع هنا.
 *
 * قاعدة «من» في خطوات الاستجابة (٩-و-٢ بند ٢): الفريق الأولي ٨–١١ ← المناوب ٢١ ← الطبيب ٤ ← رئيس الأمن ٣ وأفراده ٥
 * ← مدير المرافق ٢ وفنيوه ١٤–١٩ ← قائد الطوارئ ١ ← مراقب الحريق ١٣ ← مسؤول السلامة ٢٠.
 */
class RoleCards
{
    public const CATEGORIES = [
        'leadership' => 'القيادة',
        'support' => 'الإسناد',
        'response-team' => 'الاستجابة الأولية',
        'occupants' => 'الشاغلون',
    ];

    public const TECH = [14, 15, 16, 17, 18, 19];      // فنيو مدير المرافق (فريق التحكم بالأنظمة الحرجة / الفريق الفني المناوب)
    public const INITIAL_TEAM = [8, 9, 10, 11];        // الفريق الأولي بالمكان

    public const CARDS = [
        1 => ['name' => 'المشرف العام للسلامة وقائد فريق الطوارئ', 'desc' => 'مدير الشؤون الإدارية والهندسية', 'category' => 'leadership', 'role' => 'admin_eng_manager'],
        2 => ['name' => 'مدير المرافق والصيانة', 'desc' => 'القيادة الفنية', 'category' => 'leadership', 'role' => 'facilities_manager'],
        3 => ['name' => 'رئيس قسم الأمن والسلامة', 'desc' => 'القيادة الميدانية', 'category' => 'leadership', 'role' => 'security_safety_head'],
        4 => ['name' => 'طبيب المعهد', 'desc' => 'الإسناد الطبي', 'category' => 'support', 'role' => 'support_team'],
        5 => ['name' => 'أفراد الأمن المكلفون', 'desc' => 'التدفق والمحيط + منقذ/إطفائي القاعات', 'category' => 'support', 'role' => 'support_team'],
        6 => ['name' => 'المحاضر', 'desc' => 'منسق + مسعف لقاعته', 'category' => 'response-team', 'team' => ['coordinator', 'medic']],
        7 => ['name' => 'المتدرب', 'desc' => 'الفئة الأخطر — لا يعرف المبنى', 'category' => 'occupants', 'none' => true],
        8 => ['name' => 'المنسق', 'desc' => 'لكل إدارة أو قسم أو فريق', 'category' => 'response-team', 'team' => ['coordinator']],
        9 => ['name' => 'المسعف', 'desc' => 'لكل إدارة أو قسم أو فريق', 'category' => 'response-team', 'team' => ['medic']],
        10 => ['name' => 'المنقذ', 'desc' => 'لكل إدارة أو قسم أو فريق', 'category' => 'response-team', 'team' => ['rescuer']],
        11 => ['name' => 'الإطفائي', 'desc' => 'لكل إدارة أو قسم أو فريق', 'category' => 'response-team', 'team' => ['firefighter']],
        12 => ['name' => 'الموظف', 'desc' => 'عضو في خلية الـ20', 'category' => 'occupants', 'role' => 'employee'],
        13 => ['name' => 'مراقب الحريق', 'desc' => 'مراقبة عدم تجدد الحريق', 'category' => 'support', 'role' => 'support_team'],
        14 => ['name' => 'فني مضخة الحريق', 'desc' => 'غرفة المضخة', 'category' => 'support', 'role' => 'tech_fire_pump'], // ٢٠-٣
        15 => ['name' => 'فني المولد الاحتياطي', 'desc' => 'غرفة المولد', 'category' => 'support', 'role' => 'tech_generator'],
        16 => ['name' => 'فني لوحة الإنذار والحريق', 'desc' => 'غرفة لوحة الإنذار', 'category' => 'support', 'role' => 'tech_fire_alarm'],
        17 => ['name' => 'فني التكييف ونظام الدخان', 'desc' => 'غرفة التحكم بالتكييف', 'category' => 'support', 'role' => 'tech_hvac'],
        18 => ['name' => 'فني المصاعد', 'desc' => 'غرفة ماكينة المصاعد', 'category' => 'support', 'role' => 'tech_elevator'],
        19 => ['name' => 'فني الكهرباء', 'desc' => 'اللوحات والتمديدات', 'category' => 'support', 'role' => 'tech_electrical'],
        20 => ['name' => 'مسؤول السلامة بالمعهد', 'desc' => 'أخصائي السلامة', 'category' => 'leadership', 'role' => 'system_admin'],
        21 => ['name' => 'مناوب مركز السلامة', 'desc' => 'المحرك التشغيلي للمركز', 'category' => 'leadership', 'role' => 'system_staff'],
    ];

    /**
     * قواعد مطابقة نص «من» بأرقام البطاقات. تُطبَّق بالترتيب، وكل مطابقة تُمحى من النص كي لا تُعاد قراءتها
     * (مثل «مناوب الأمن في مركز السلامة» = ٢١ لا ٥، و«الفريق الفني المناوب» = ١٤–١٩ لا ٢١).
     * النتيجة بترتيب الورود في النص؛ الأولى هي صاحبة الخطوة.
     */
    private const RULES = [
        ['/فريق التدخل الأولي|فريق السلامة|الفريق الموجود|فريق المكتب|فريق المطعم|فريق القبو|العاملون بالمستودع|العاملون|فريق العمل|من رصد الحالة/u', [8, 9, 10, 11]],
        ['/الفريق الفني المناوب|الفريق الفني|فريق التحكم(?: بالأنظمة الحرجة)?/u', self::TECH],
        ['/مناوب الأمن في مركز السلامة|مناوب المركز|مناوب الأمن|مركز السلامة|المكتشف المركز|المركز ي|^المركز$/u', [21]],
        ['/رئيس (?:قسم )?الأمن(?: والسلامة)?/u', [3]],
        ['/فريق الأمن|أفراد الأمن|المراقبين|الأمن/u', [5]],
        ['/قائد(?: فريق)? الطوارئ(?: والإخلاء)?/u', [1]],
        ['/فريق الطوارئ/u', [1]],
        ['/مدير المرافق(?: والصيانة)?/u', [2]],
        ['/الطبيب/u', [4]],
        ['/المحاضر|المنظّم/u', [6]],
        ['/المنسق|منسق/u', [8]],
        ['/المسعف|مسعف/u', [9]],
        ['/المنقذ|منقذ/u', [10]],
        ['/الإطفائي|إطفائي/u', [11]],
        ['/مراقب الحريق/u', [13]],
        ['/مسؤول السلامة/u', [20]],
        ['/متدرب|الحضور/u', [7]],
        ['/موظف/u', [12]],
    ];

    public static function all(): array { return self::CARDS; }

    public static function get(int $no): ?array
    {
        if (!isset(self::CARDS[$no])) return null;
        return self::CARDS[$no] + ['no' => $no, 'url' => self::url($no)];
    }

    public static function url(int $no): string
    {
        return '/role-cards/'.self::CARDS[$no]['category'].'/role-'.str_pad((string) $no, 2, '0', STR_PAD_LEFT).'.html';
    }

    /**
     * ١٩-٦ (قرار ٤٩): بطاقة الشخص — القيادة ببطاقة دورها؛ وعضو الفريق الأولي ببطاقة دوره في الفريق؛
     * ثم الدور الذي له بطاقة واحدة (الموظف ١٢)؛ ومن له أكثر من بطاقة (الفنيون والإسناد) أو لا بطاقة = null فيبقى الفهرس.
     */
    public static function forUser(\App\Models\User $user): ?int
    {
        $role = $user->role();
        // ٢٠-٦ (قرار ٥١): الدور ذو البطاقات المتعددة (فريق الإسناد) يحمل حسابُه بطاقته بالاسم
        $named = $user->profile?->role_card_no;
        if ($named && in_array((int) $named, self::cardsOfRole($role), true)) return (int) $named;
        $mine = array_keys(array_filter(self::CARDS, fn ($c) => ($c['role'] ?? null) === $role));
        if (count($mine) === 1 && self::CARDS[$mine[0]]['category'] === 'leadership') return $mine[0];
        $teamKey = ['evac_coordinator' => 'coordinator', 'medic' => 'medic', 'rescuer' => 'rescuer', 'firefighter' => 'firefighter'][$role]
            ?? \App\Modules\Emergency\Models\EmergencyTeamMember::where('user_id', $user->id)->whereNotNull('role_key')
                ->whereHas('team', fn ($q) => $q->where('is_active', true))->value('role_key');
        if ($teamKey) {
            foreach (self::INITIAL_TEAM as $no) if ((self::CARDS[$no]['team'] ?? []) === [$teamKey]) return $no;
        }
        return count($mine) === 1 ? $mine[0] : null;
    }

    /** ٢٠-٦: أرقام البطاقات التي يحملها دور (بالاسم أو بالعضوية) */
    public static function cardsOfRole(string $role): array
    {
        $teamKey = ['evac_coordinator' => 'coordinator', 'medic' => 'medic', 'rescuer' => 'rescuer', 'firefighter' => 'firefighter'][$role] ?? null;
        $out = [];
        foreach (self::CARDS as $no => $c) {
            if (($c['role'] ?? null) === $role) $out[] = $no;
            elseif ($teamKey && ($c['team'] ?? []) === [$teamKey]) $out[] = $no;
        }
        return $out;
    }

    /**
     * ٢٠-٦ (قرار ٥١): لكل بطاقة حامل معروف — kind: role (دور واحد) · named (دور بأكثر من بطاقة: يُحدَّد الحساب بالاسم) ·
     * team (عضو الفريق الأولي بالمكان) · none (شاغل بلا حساب).
     */
    public static function holders(): array
    {
        $out = [];
        foreach (self::CARDS as $no => $c) {
            $row = $c + ['no' => $no, 'url' => self::url($no), 'role' => $c['role'] ?? null];
            if (!empty($c['none'])) {
                $row += ['kind' => 'none', 'holder' => $no === 7 ? 'كل متدرب — بلا حساب؛ يبلغ من الصفحة العامة ويقرأ البطاقة' : 'بلا حساب'];
            } elseif (!empty($c['team'])) {
                $keys = array_map(fn ($k) => \App\Modules\Emergency\Models\EmergencyTeamMember::ROLE_KEYS[$k] ?? $k, $c['team']);
                $row += ['kind' => $no === 6 ? 'none' : 'team', 'holder' => $no === 6
                    ? 'المحاضر في قاعته — كل محاضر، بلا حساب؛ يقوم بمهمة '.implode(' و', $keys).' (قاعدة القاعات)'
                    : 'عضو الفريق الأولي في مكانه: '.implode(' + ', $keys).' — بالاسم في ملف المكان، وبحسابه إن رُبط'];
            } else {
                $role = $c['role']; $label = \App\Core\Permissions\PermissionRegistry::ROLES[$role] ?? $role;
                $multi = count(array_filter(self::CARDS, fn ($x) => ($x['role'] ?? null) === $role)) > 1;
                $row += ['kind' => $multi ? 'named' : 'role', 'holder' => $multi ? 'دور «'.$label.'» — من حُدِّدت له هذه البطاقة' : 'دور «'.$label.'»'];
            }
            $out[$no] = $row;
        }
        return $out;
    }

    /** ٢٠-٦: موضع كل دور قابل للإسناد — بطاقاته، أو سبب غياب البطاقة (لا مهمة سلامة ميدانية له) */
    public static function rolesPlacement(): array
    {
        $why = [
            'top_management' => 'لا بطاقة: قرار واعتماد وصورة المبنى؛ المستوى ٤ في تصعيد بلاغ الفحص',
            'safety_committee' => 'لا بطاقة: تُشكَّل لاحقاً؛ اعتماد المخاطر',
            'branch_manager' => 'لا بطاقة: يدير فرعه ويرشّح فرقه',
            'department_manager' => 'لا بطاقة: ترشيح الفريق الأولي لإدارته وتفعيل أخطارها',
            'section_manager' => 'لا بطاقة: كمدير الإدارة لقسمه',
            'safety_coordinator' => 'لا بطاقة: عمل يومي — السجل الفعلي لوحدته وبلاغات الشاغلين (قرار ٥٣)؛ بطاقة ٨ لمنسق الإخلاء والطوارئ',
            'contractor_supervisor' => 'لا بطاقة: بوابة المقاول',
            'contractor' => 'لا بطاقة: بوابة المقاول',
            'employee' => '',
            'external' => 'لا بطاقة: اطلاع',
            'consultant_office' => 'لا بطاقة: يعتمد بنود الفحص وقيمها',
        ];
        $out = [];
        foreach (\App\Core\Permissions\PermissionRegistry::assignableRoles() as $key => $name) {
            $cards = self::cardsOfRole($key);
            $text = $cards ? (count($cards) > 1 ? 'أكثر من بطاقة: تُحدَّد للحساب بالاسم' : '') : ($why[$key] ?? 'لا بطاقة');
            $out[$key] = ['cards' => $cards, 'text' => $text !== '' ? $text : 'بطاقة واحدة'];
        }
        return $out;
    }

    public static function byCategory(): array
    {
        $out = [];
        foreach (self::CARDS as $no => $c) $out[$c['category']][$no] = $c + ['no' => $no, 'url' => self::url($no)];
        return $out;
    }

    /** وصف مقابل البطاقة في النظام للعرض. */
    public static function systemLabel(int $no): string
    {
        $c = self::CARDS[$no] ?? [];
        if (!empty($c['none'])) return 'بلا حساب (شاغل)';
        if (!empty($c['team'])) {
            $keys = array_map(fn ($k) => \App\Modules\Emergency\Models\EmergencyTeamMember::ROLE_KEYS[$k] ?? $k, $c['team']);
            return 'عضو الفريق الأولي بالمكان: '.implode(' + ', $keys);
        }
        $role = $c['role'] ?? null;
        $label = $role ? (\App\Core\Permissions\PermissionRegistry::ROLES[$role] ?? $role) : '—';
        return $role === 'support_team' ? 'دور «'.$label.'» — يُحدَّد الشخص بالاسم' : 'دور: '.$label;
    }

    /** @return int[] أرقام البطاقات المطابقة لنص «من» بترتيب ورودها؛ فارغة إن لم يُطابق شيء. */
    public static function match(?string $who): array
    {
        $text = trim((string) $who);
        if ($text === '') return [];
        $found = [];
        foreach (self::RULES as [$re, $cards]) {
            while (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                [$hit, $pos] = $m[0];
                foreach ($cards as $i => $n) $found[] = [$pos + $i / 100, $n];
                // محو المطابقة بطول مساوٍ (بالبايتات) حتى تبقى المواضع صحيحة
                $text = substr($text, 0, $pos).str_repeat(' ', strlen($hit)).substr($text, $pos + strlen($hit));
            }
        }
        usort($found, fn ($a, $b) => $a[0] <=> $b[0]);
        $out = [];
        foreach ($found as [, $n]) if (!in_array($n, $out, true)) $out[] = $n;
        return $out;
    }
}
