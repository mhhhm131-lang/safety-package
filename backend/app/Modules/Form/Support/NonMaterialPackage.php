<?php

namespace App\Modules\Form\Support;

/**
 * قرار ٨٨ (٢٠٢٦-١٠-١٥): حزمة الطبقة الثانية = نموذج واحد «بطاقة الإدارة».
 * سبعة بنود × أربع مراحل. البنود بكلماته (one-form-design-2026-10-15.md §١)، وأسماء المراحل وتلميحاتها
 * وعبارات الموظف الأربع بنصها من الواجهة الأصل (`database/data/non-material-forms.json`: MSTAGE وMQ).
 * تقرؤها البذرة (القالب)، وتوليد الإقرار (٢٩-٥)، وشاشة الأرقام (٢٩-٦).
 */
final class NonMaterialPackage
{
    public const CODE  = 'card';
    public const TITLE = 'بطاقة الإدارة';
    public const DESCRIPTION = 'سبعة بنود تسأل الإدارة: هل عندكم هذا؟ موثّق، معلن، مفهوم، مطبَّق؟ نعم تعني أرونا، ولا جواب مقبول. ثم يصدّق الموظفون سرياً. النظام يسأل ولا يبني ولا يحاسب.';

    /** @var array<int, array{key: string, title: string, why: string}> */
    public const ITEMS = [
        ['key' => 'goals',    'title' => 'أهداف الإدارة وارتباطها بأهداف المعهد ورؤيته ورؤية ٢٠٣٠',           'why' => 'الإدارة تعرف إلى أين، ولماذا يهم عملها'],
        ['key' => 'role',     'title' => 'دور كل موظف: وصفه ومهامه وصلاحياته ومسؤولياته وحقوقه وواجباته', 'why' => 'كل فرد يعرف ما له وما عليه — أصل العدالة'],
        ['key' => 'rules',    'title' => 'ما يحكم العمل: السياسات والإجراءات وتعليمات العمل',               'why' => 'طريقة واحدة معروفة، لا اجتهاد ولا تعارض'],
        ['key' => 'risks',    'title' => 'المخاطر والفرص على مستوى الإدارة والأفراد',                       'why' => 'الحادث غير المرئي يُرى قبل وقوعه'],
        ['key' => 'bias',     'title' => 'التوعية بالانحيازات الذاتية',                                     'why' => 'قرارات وعلاقات موضوعية'],
        ['key' => 'channels', 'title' => 'القنوات: إبلاغ واقتراح ومبادرة، بمتابعة وردّ',                    'why' => 'لا شيء يبقى في صدر الموظف'],
        ['key' => 'learning', 'title' => 'التعلم والتطوير وربطهما بالتقييم السنوي',                         'why' => 'النمو المعرفي ومسار واضح'],
    ];

    /** المراحل الأربع: الاسم [نص]، سؤال الإدارة، تلميح «أرني» [نص]، وهل تُرفق وثيقة. @var array<int, array{key: string, name: string, question: string, hint: string, attach: bool}> */
    public const STAGES = [
        ['key' => 'doc', 'name' => 'موثّق',  'question' => 'هل يوجد مكتوباً؟',          'hint' => 'اسم الوثيقة ورقمها وتاريخها — أو ارفعها، أو الصق نصها أو رابطها', 'attach' => true],
        ['key' => 'ann', 'name' => 'معلن',   'question' => 'هل أُعلن للموظفين؟',        'hint' => 'وسيلة الإعلان وتاريخها — بريد · اجتماع · لوحة · منصة',          'attach' => false],
        ['key' => 'und', 'name' => 'مفهوم',  'question' => 'هل تضمن أنهم يفهمونه؟',     'hint' => 'ما الذي يضمن الفهم؟ تعريف · ورشة · اختبار · تدريب',             'attach' => false],
        ['key' => 'app', 'name' => 'مطبَّق', 'question' => 'هل يُطبَّق فعلاً؟',          'hint' => 'ما الذي يضمن التطبيق؟ متابعة · مؤشر · مراجعة دورية',            'attach' => false],
    ];

    /** عبارات الموظف بالمتكلم [نص من الواجهة الأصل] بمفتاح مرحلتها. */
    public const EMPLOYEE_STATEMENTS = [
        'doc' => 'وصلتني وثيقة مكتوبة بهذا',
        'ann' => 'أُعلن لي عنه بوضوح',
        'und' => 'أفهمه فهماً كافياً لأعمل به',
        'app' => 'أطبّقه فعلاً في عملي اليومي',
    ];

    /** البند الذي يقرأ النظام مرحلته «موثّق» من سجل الإدارة (بكلمته «موافق»). */
    public const FROM_REGISTER = ['item' => 'risks', 'stage' => 'doc'];

    /** ٢٩-٥: الإقرار السري المولَّد لكل جولة — رمزه `ack` + رقم الجولة (ثماني خانات، فريد)؛ عنوانه ومقدمته [نص الصفحة التجريبية] وخيار الموظف. */
    public const ACK_PREFIX    = 'ack';
    public const ACK_TITLE     = 'إقرارك السري';
    public const ACK_INTRO     = 'لا يُنسب جوابك إليك. إدارتك قالت «نعم» في هذه البنود وأرَتنا ما عندها. قل ما وصلك أنت.';
    public const NA_LABEL      = 'لا ينطبق على عملي';
    public const REALITY_LABEL = 'ما الواقع؟';

    public static function ackCode(int $roundId): string
    {
        return sprintf('%s%05d', self::ACK_PREFIX, $roundId);
    }

    public static function isAckCode(?string $code): bool
    {
        return $code !== null && str_starts_with($code, self::ACK_PREFIX);
    }

    public static function roundIdOfAck(?string $code): ?int
    {
        return self::isAckCode($code) ? (int) substr($code, strlen(self::ACK_PREFIX)) : null;
    }

    /** @return array{key: string, title: string, why: string}|null */
    public static function item(string $key): ?array
    {
        foreach (self::ITEMS as $i) if ($i['key'] === $key) return $i;
        return null;
    }

    /** @return array{key: string, name: string, question: string, hint: string, attach: bool}|null */
    public static function stage(string $key): ?array
    {
        foreach (self::STAGES as $s) if ($s['key'] === $key) return $s;
        return null;
    }
}
