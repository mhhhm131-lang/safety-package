<?php

namespace App\Modules\Risk\Support;

use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;

/**
 * خطة المعالج — الخطوة ٦ (بكلمته ٢٠٢٦-١٠-٠٧): دمج الأطوار الثلاثة في صف واحد بلا عناوين.
 *   الأسباب: قائمة واحدة، المكرّر مرة · الوقائي والتصحيحي والتقييم المتبقي: نص واحد، المكرّر مرة، والترقيم «(١)…» متصل
 *   المتأثرون: مرة واحدة بأعلى أثر · «من يطبّق الضوابط» (نص الكتاب): جملة واحدة من الأدوار بلا تكرار
 * يُستعمل في البذر من ملفات الكتاب (بقيت بأطوارها) وفي ترحيل الصفوف القائمة. لا يحذف نصاً: كل ما في الأطوار يبقى في الصف الواحد.
 */
final class PhaseMerger
{
    public const TEXT_FIELDS = ['preventive_action', 'corrective_action', 'residual_assessment'];
    public const FULL_PREFIX = 'الجهة والشخص كاملاً: ';

    /**
     * @param array<int, array{preventive_action?:?string, corrective_action?:?string, residual_assessment?:?string,
     *     responsible_org_unit_id?:?int, responsible_org_unit_text?:?string, responsible_user_id?:?int, responsible_user_text?:?string,
     *     notes?:?string, causes?:array<int,string>, affected?:array<int, array{name:string, impact?:?string, rep_scope?:?string, detail?:?string}>}> $phases بترتيب الأطوار
     * @return array{preventive_action:?string, corrective_action:?string, residual_assessment:?string, responsible_org_unit_id:?int,
     *     responsible_org_unit_text:?string, responsible_user_id:?int, responsible_user_text:?string, notes:?string,
     *     causes:array<int,string>, affected:array<int, array{name:string, impact:?string, rep_scope:?string, detail:?string}>}
     */
    public static function merge(array $phases): array
    {
        $out = ['responsible_org_unit_id' => null, 'responsible_user_id' => null];
        foreach (self::TEXT_FIELDS as $f) {
            $out[$f] = self::renumber(self::joinDistinct(array_map(fn ($p) => $p[$f] ?? null, $phases), "\n"));
        }
        foreach (['responsible_org_unit_id', 'responsible_user_id'] as $f) {
            foreach ($phases as $p) { if (!empty($p[$f])) { $out[$f] = (int) $p[$f]; break; } }
        }
        // نص «من يطبّق الضوابط»: الكامل إن كان في الملاحظات (البذر يقصّ ٢٠٠ حرفاً في الخانة ويضع الكامل في الملاحظات)
        $who = [];
        $otherNotes = [];
        foreach ($phases as $p) {
            $n = trim((string) ($p['notes'] ?? ''));
            if ($n !== '' && str_starts_with($n, self::FULL_PREFIX)) {
                $who[] = mb_substr($n, mb_strlen(self::FULL_PREFIX));
            } else {
                if ($n !== '') $otherNotes[] = $n;
                $t = trim((string) ($p['responsible_org_unit_text'] ?? ''));
                if ($t !== '') $who[] = $t;
            }
        }
        $whoText = self::joinDistinct($who, ' · ');
        $out['responsible_org_unit_text'] = $whoText === null ? null : mb_substr($whoText, 0, 200);
        $out['responsible_user_text'] = self::joinDistinct(array_map(fn ($p) => $p['responsible_user_text'] ?? null, $phases), ' · ');
        if ($out['responsible_user_text'] !== null) $out['responsible_user_text'] = mb_substr($out['responsible_user_text'], 0, 200);
        $notes = $otherNotes;
        if ($whoText !== null && mb_strlen($whoText) > 200) array_unshift($notes, self::FULL_PREFIX.$whoText);
        $out['notes'] = self::joinDistinct($notes, "\n");

        // الأسباب: مرة واحدة بالاسم (بعد ضبط المسافات)
        $causes = [];
        foreach ($phases as $p) {
            foreach ($p['causes'] ?? [] as $c) {
                $c = self::norm((string) $c);
                if ($c !== '' && !in_array($c, $causes, true)) $causes[] = $c;
            }
        }
        $out['causes'] = $causes;

        // المتأثرون: مرة واحدة بأعلى أثر، والنطاق والتفصيل أول ما كُتب
        $affected = [];
        foreach ($phases as $p) {
            foreach ($p['affected'] ?? [] as $g) {
                $name = self::norm((string) ($g['name'] ?? ''));
                if ($name === '') continue;
                $impact = isset($g['impact']) && $g['impact'] !== null && $g['impact'] !== '' ? RiskPhaseAffectedGroupDetail::normalizeImpact((string) $g['impact']) : null;
                if (!isset($affected[$name])) {
                    $affected[$name] = ['name' => $name, 'impact' => $impact, 'rep_scope' => $g['rep_scope'] ?? null, 'detail' => self::nz($g['detail'] ?? null)];
                    continue;
                }
                $cur = &$affected[$name];
                if ($impact !== null && ($cur['impact'] === null || (int) $impact > (int) $cur['impact'])) $cur['impact'] = $impact;
                if ($cur['rep_scope'] === null && !empty($g['rep_scope'])) $cur['rep_scope'] = $g['rep_scope'];
                if ($cur['detail'] === null && self::nz($g['detail'] ?? null) !== null) $cur['detail'] = self::nz($g['detail']);
                unset($cur);
            }
        }
        $out['affected'] = array_values($affected);
        return $out;
    }

    /** نصوص متعددة ← نص واحد: المكرّر مرة، الفارغ يُهمل، والترتيب كما جاء */
    public static function joinDistinct(array $texts, string $glue): ?string
    {
        $seen = [];
        $out = [];
        foreach ($texts as $t) {
            $t = trim((string) $t);
            if ($t === '') continue;
            $k = self::norm($t);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $out[] = $t;
        }
        return $out ? implode($glue, $out) : null;
    }

    /** الترقيم «(١) … (٢) …» يبدأ من (١) في كل طور في الكتاب؛ في النص الواحد يُعاد متصلاً */
    public static function renumber(?string $text): ?string
    {
        if ($text === null) return null;
        $n = 0;
        $hits = preg_match_all('/\((?:[٠-٩]+|\d+)\)/u', $text);
        if ($hits < 2) return $text;
        return preg_replace_callback('/\((?:[٠-٩]+|\d+)\)/u', function () use (&$n) {
            $n++;
            return '('.self::arabicDigits((string) $n).')';
        }, $text);
    }

    public static function arabicDigits(string $n): string
    {
        return strtr($n, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }

    private static function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function nz(?string $s): ?string
    {
        $s = $s === null ? '' : trim($s);
        return $s === '' ? null : $s;
    }
}
