<?php

namespace App\Modules\Form\Services;

use App\Modules\Form\Models\FormAssignment;
use App\Modules\Form\Models\FormRound;
use App\Modules\Form\Models\FormSubmission;
use App\Modules\Form\Models\FormTemplate;
use App\Modules\Form\Support\NonMaterialPackage;
use App\Modules\Form\Support\RegisterFacts;
use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Incident\Models\Incident;

/**
 * ٢٩-٦ (قرار ٨٨ §٢ و§٣): أرقام الجولة — ما قالته الإدارة وما قاله موظفوها سرياً، صحيحاً ودقيقاً.
 *   السُّلّم التراكمي: درجة الموظف في البند = عدد «نعم» المتصلة من الأسفل (وصلتني ← أُعلن لي ← أفهمه ← أطبّقه)؛ من قال «أطبّقه» ولم تصله وثيقة يقف عند درجة انقطاعه.
 *   عدد الموظفين في المرحلة = من درجته ≥ رتبتها؛ النسب على من أجاب البند، و«لا ينطبق على عملي» خارج الحساب؛ من لم يجب لا يُعدّ لا ولا نعم.
 *   رقم البند متوسط درجات موظفيه من أربعة؛ رقم الإدارة متوسط بنودها التي أُجيبت؛ ما ادّعته الإدارة عدد «نعم» في مراحل البند؛ الفجوة الفرق.
 *   نوع الفجوة = أكبر هبوط بين مرحلتين متتاليتين (وعند التساوي الأعلى سُلّماً): «مفهوم وغير مطبَّق» الأخطر، ثم «معلن وغير مفهوم»، ثم «موثّق وغير معلن»، ثم «لم تصلهم الوثيقة».
 *   الترتيب: الأخطر أولاً ثم الأكبر فجوة، وما قالت فيه الإدارة «لا» في كل مراحله آخراً. تحت خمسة مجيبين لا أرقام. وقائع النظام بجانب الرقم لا داخله. لا بطاقات أبعاد.
 *   حدّ الاستجابة المنخفضة وحدّ الفجوة التي تصير اقتراح خطر: رقمان له لم يُعطيا — لا وسم ولا اقتراح حتى يعطيهما.
 */
final class RoundNumbersService
{
    public const KINDS = [
        'app'  => 'مفهوم وغير مطبَّق',
        'und'  => 'معلن وغير مفهوم',
        'ann'  => 'موثّق وغير معلن',
        'doc'  => 'لم تصلهم الوثيقة',
        'ok'    => 'لا فجوة',
        'empty' => 'لا مجيب على البند',
        'none'  => 'الإدارة لم تدّعِ شيئاً',
    ];

    private const ORDER = ['app' => 0, 'und' => 1, 'ann' => 2, 'doc' => 3, 'ok' => 4, 'empty' => 5, 'none' => 6];

    /**
     * @param bool $withTexts نصوص «ما الواقع» لمن يرى الكل فقط؛ المدير والمنسق يريان النسب بلا نصوص الموظفين
     * @return array{unit: string, round_no: int, state: string, ack: ?FormTemplate, assigned: int, answered: int, named: int, hidden: bool, min: int, level: ?float, top_gap: ?array, items: array, with_texts: bool}
     */
    public function compute(FormRound $round, bool $withTexts): array
    {
        $unit = $round->organizationUnit;
        $stages = array_column(NonMaterialPackage::STAGES, 'key');
        $ack = FormTemplate::where('package_code', NonMaterialPackage::ackCode($round->id))->first();
        $claims = $this->cardClaims($round);

        $assigned = $ack ? FormAssignment::withoutGlobalScope('building')->where('form_id', $ack->id)->count() : 0;
        $subs = $ack ? FormSubmission::withoutGlobalScope('building')->where('form_id', $ack->id)->with('answers.field')->get() : collect();
        $answered = $subs->count();
        $named = $subs->whereNotNull('submitted_by_id')->count();
        $hidden = $answered < FormTemplate::ANONYMOUS_MIN_RESPONSES;

        $items = [];
        foreach (NonMaterialPackage::ITEMS as $i => $item) {
            $c = $claims[$item['key']] ?? [];
            $claimed = 0;
            foreach ($stages as $s) {
                if (($c[$s]['claim'] ?? '') === 'yes') $claimed++;
            }
            $row = [
                'key'           => $item['key'],
                'title'         => $item['title'],
                'order'         => $i + 1,
                'claims'        => array_combine($stages, array_map(fn ($s) => $c[$s] ?? ['claim' => '', 'evidence' => ''], $stages)),
                'from_register' => $item['key'] === NonMaterialPackage::FROM_REGISTER['item'],
                'claimed'       => $claimed,
                'answered'      => 0,
                'na'            => 0,
                'counts'        => array_fill_keys($stages, 0),
                'shares'        => array_fill_keys($stages, null),
                'score'         => null,
                'gap'           => null,
                'kind'          => 'none',
                'notes'         => [],
                'notes_count'   => 0,
                'fact'          => $this->fact($item['key'], $unit),
            ];

            if (!$hidden && $claimed > 0) {
                $scores = [];
                $notes = [];
                foreach ($subs as $s) {
                    foreach ($s->answers as $a) {
                        $f = $a->field;
                        if (!$f || !$f->isLadder() || $f->ladderDef()['item'] !== $item['key']) continue;
                        $lv = $a->ladderValue();
                        if (!$lv) continue;
                        if ($lv['na']) {
                            $row['na']++;
                            continue;
                        }
                        $scores[] = self::score($lv);
                        if ($lv['note'] !== '') $notes[] = $lv['note'];
                    }
                }
                $row['answered'] = count($scores);
                $row['kind'] = 'empty'; // الإدارة ادّعت ولم يجب أحد على البند (كلهم «لا ينطبق» أو لم يصل بعد)
                if ($row['answered'] > 0) {
                    foreach ($stages as $k => $s) {
                        $n = count(array_filter($scores, fn ($x) => $x >= $k + 1));
                        $row['counts'][$s] = $n;
                        $row['shares'][$s] = (int) round($n / $row['answered'] * 100);
                    }
                    $row['score'] = array_sum($scores) / count($scores);
                    $row['gap'] = $claimed - $row['score'];
                    $row['kind'] = self::kind($row['counts'], $row['answered']);
                }
                $row['notes_count'] = count($notes);
                $row['notes'] = $withTexts ? $notes : [];
            }
            $items[] = $row;
        }

        usort($items, fn ($a, $b) => [self::ORDER[$a['kind']], -($a['gap'] ?? 0), $a['order']] <=> [self::ORDER[$b['kind']], -($b['gap'] ?? 0), $b['order']]);

        $scored = array_filter($items, fn ($r) => $r['score'] !== null);
        $level = $scored ? array_sum(array_column($scored, 'score')) / count($scored) : null;
        $top = null;
        foreach ($items as $r) {
            if (in_array($r['kind'], ['app', 'und', 'ann', 'doc'], true)) {
                $top = ['item' => $r['key'], 'title' => $r['title'], 'kind' => $r['kind'], 'label' => self::KINDS[$r['kind']]];
                break;
            }
        }

        return [
            'unit'       => $unit?->name ?? '—',
            'round_no'   => (int) $round->round_no,
            'state'      => $round->state,
            'ack'        => $ack,
            'assigned'   => $assigned,
            'answered'   => $answered,
            'named'      => $named,
            'hidden'     => $hidden,
            'min'        => FormTemplate::ANONYMOUS_MIN_RESPONSES,
            'level'      => $level,
            'top_gap'    => $top,
            'items'      => $items,
            'with_texts' => $withTexts,
        ];
    }

    /** درجة الموظف في البند على السُّلّم: «نعم» المتصلة من الأسفل. */
    public static function score(array $lv): int
    {
        $n = 0;
        foreach (array_keys(NonMaterialPackage::EMPLOYEE_STATEMENTS) as $s) {
            if (($lv[$s] ?? '') !== 'yes') break;
            $n++;
        }

        return $n;
    }

    /** نوع الفجوة: أكبر هبوط بين مرحلتين متتاليتين (من ١٠٠٪ قبل «وصلتني»)، وعند التساوي الأعلى سُلّماً. */
    private static function kind(array $counts, int $answered): string
    {
        $keys = array_keys(NonMaterialPackage::EMPLOYEE_STATEMENTS);
        $share = [1.0];
        foreach ($keys as $k) $share[] = $counts[$k] / $answered;
        $drops = [];
        for ($i = 1; $i <= 4; $i++) $drops[$i] = $share[$i - 1] - $share[$i];
        $max = max($drops);
        if ($max <= 0.0001) return 'ok';
        for ($i = 4; $i >= 1; $i--) {
            if (abs($drops[$i] - $max) < 0.0001) return $keys[$i - 1];
        }

        return 'ok';
    }

    /** ما قالته الإدارة في بطاقتها لهذه الجولة: [البند][المرحلة] => {claim, evidence} */
    private function cardClaims(FormRound $round): array
    {
        $card = $round->cardAssignment();
        $s = $card
            ? FormSubmission::withoutGlobalScope('building')->where('round_id', $round->id)->where('form_id', $card->form_id)->with('answers.field')->latest('id')->first()
            : null;
        if (!$s) return [];
        $out = [];
        foreach ($s->answers as $a) {
            $f = $a->field;
            if (!$f || !$f->isClaim()) continue;
            $d = $f->claimDef();
            $c = $a->claimValue();
            $out[$d['item']][$d['stage']] = ['claim' => (string) ($c['claim'] ?? ''), 'evidence' => (string) ($c['evidence'] ?? '')];
        }

        return $out;
    }

    /** وقائع النظام بجانب الرقم لا داخله: أخطار الإدارة المفعّلة بجانب ٤، وبلاغاتها بجانب ٦؛ لا سجل تدريب للموظفين في النظام بعد فلا واقعة بجانب ٧. */
    private function fact(string $item, ?OrganizationUnit $unit): ?string
    {
        if (!$unit) return null;

        return match ($item) {
            'risks'    => ($n = RegisterFacts::activeRiskCount($unit->id)) > 0 ? "سجل الإدارة: {$n} خطراً مفعّلاً" : 'سجل الإدارة: لا أخطار مفعّلة',
            'channels' => 'بلاغات مُحالة إلى الإدارة في نظام البلاغ: '.Incident::withoutGlobalScopes()->where('organization_unit_id', $unit->id)->count(),
            default    => null,
        };
    }
}
