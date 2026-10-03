<?php

namespace App\Core\Inbox;

use Illuminate\Support\Collection;

/**
 * ٢٦-١١ (قرار ٦٦): دمج البطاقات المتطابقة في «ما ينتظرك» — عرض لا منطق.
 * «المتطابقة» = السؤال نفسه عن المكان نفسه ولا يميّز بين البطاقات إلا الرقم (نوع واحد في `Task::batch`).
 * اثنتان فأكثر تصيران بطاقة واحدة بعددها، زرها يفتح بنودها تحتها، وكل بند بزره كما كان. لا يُحذف شيء،
 * والعدّادات تبقى عدد الأشياء (InboxService لا يتغيّر). ما له اسم يميّزه (خطر، عامل، حساب، تصريح، خطوة) لا يُدمج.
 */
final class Batch
{
    /** النوع ← [المفرد المجرور للعدّ، المثنى، الجمع (٣–١٠)، التمييز (١١+)، الحال المشترك] */
    public const KINDS = [
        'incident.refer'     => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'لا فني للمكان'],
        'incident.forward'   => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'عند المنسق بلا فني'],
        'incident.classify'  => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'بلا تصنيف للخطر'],
        'incident.ask'       => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'بانتظار طلب موافقة المبلّغ على الإغلاق'],
        'incident.verify'    => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'بانتظار التحقق الميداني قبل الإغلاق'],
        'incident.close'     => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'بانتظار الإغلاق'],
        'incident.coord'     => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'صُعّد إليك — بانتظار من يتولّى المعالجة'],
        'incident.committee' => ['بلاغ', 'بلاغان', 'بلاغات', 'بلاغاً', 'صُعّد للجنة — بانتظار من يتولّى المعالجة'],
        'emergency.aar'      => ['حالة', 'حالتان', 'حالات', 'حالة', 'لا تقرير بعد الانتهاء'],
        'equipment.inspect'  => ['معدة', 'معدتان', 'معدات', 'معدة', 'حان فحصها الدوري'], // ٢٧-ج
        'risk.approve'       => ['خطر', 'خطران', 'أخطار', 'خطراً', 'بانتظار اعتمادك'],     // قرار ٧١
    ];

    /**
     * قرار ٧١: دفعة لها فعل واحد على بنودها كلها — النوع ← [اسم المسار، نمط مفتاح البند لاستخراج رقمه، نص الزر للاثنين، نصه للأكثر].
     * الزر يرسل أرقام البنود؛ والمسار يفحص كل بند بصلاحيته كما يفحصه زره المفرد.
     */
    public const ALL = [
        'risk.approve' => ['risk.approve.bulk', '/^risk:(\d+):approve$/', 'اعتمدهما', 'اعتمدها كلها'],
    ];

    /**
     * يحوّل مهام مجموعة واحدة (بترتيبها) إلى بنود عرض: مهمة مفردة، أو دفعة في موضع أول أعضائها.
     *
     * @param Collection<int, Task> $tasks
     * @return array<int, array{task?:Task, batch?:string, tasks?:Task[], question?:string, late?:int, open?:string}>
     */
    public static function group(Collection $tasks): array
    {
        $sets = [];
        foreach ($tasks as $t) {
            if ($t->batch !== null && isset(self::KINDS[$t->batch])) $sets[self::keyOf($t)][] = $t;
        }
        $out = [];
        $done = [];
        foreach ($tasks as $t) {
            $k = $t->batch !== null && isset(self::KINDS[$t->batch]) ? self::keyOf($t) : null;
            if ($k === null || count($sets[$k]) < 2) { $out[] = ['task' => $t]; continue; }
            if (isset($done[$k])) continue;
            $done[$k] = true;
            $list = $sets[$k];
            $n = count($list);
            $out[] = [
                'batch' => $t->batch,
                'tasks' => $list,
                'question' => self::question($t->batch, $n, $t->place),
                'late' => count(array_filter($list, fn (Task $x) => $x->isOverdue)),
                'open' => $n === 2 ? 'اعرضهما' : 'اعرضها',
                'all' => self::all($t->batch, $list),
            ];
        }
        return $out;
    }

    /** «بلاغان في القبو: لا فني للمكان» · «14 بلاغاً في المكاتب الإدارية: لا فني للمكان» */
    public static function question(string $kind, int $n, ?string $place): string
    {
        [, $two, $few, $many, $what] = self::KINDS[$kind];
        $count = $n === 2 ? $two : ($n <= 10 ? $n.' '.$few : $n.' '.$many);
        return $count.($place ? ' في '.$place : '').': '.$what;
    }

    /**
     * @param Task[] $list
     * @return array{label:string,url:string,ids:int[]}|null
     */
    private static function all(string $kind, array $list): ?array
    {
        if (!isset(self::ALL[$kind])) return null;
        [$route, $pattern, $two, $many] = self::ALL[$kind];
        $ids = [];
        foreach ($list as $t) {
            if (preg_match($pattern, $t->key, $m)) $ids[] = (int) $m[1];
        }
        return count($ids) === count($list) ? ['label' => count($ids) === 2 ? $two : $many, 'url' => route($route), 'ids' => $ids] : null;
    }

    private static function keyOf(Task $t): string
    {
        return $t->module.'|'.$t->batch.'|'.($t->place ?? '');
    }
}
