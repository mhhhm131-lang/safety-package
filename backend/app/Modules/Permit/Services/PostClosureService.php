<?php

namespace App\Modules\Permit\Services;

use App\Modules\Incident\Models\Incident;
use App\Modules\Permit\Models\Permit;
use App\Modules\Risk\Models\RiskControl;
use Illuminate\Support\Facades\DB;

/**
 * ما بعد إغلاق التصريح — حلقة التعلّم:
 *
 *   ١. **ربط البلاغات**: أي بلاغ شاغل وقع في مكان التصريح خلال مدته يُربط به،
 *      فيكون لتحليل السبب سياق كامل (أي عمل كان جارياً حينها).
 *   ٢. **تعليم بنود التحكم**: إن قال التقييم إن الخطر قُدِّر أقل من الواقع، أو إن الدرجة ضعيفة،
 *      أو ذُكر إخفاق — تُعلَّم بنود تحكم التصريح للمراجعة (`review_flag`) ويزيد عدّادها.
 *      عند بلوغ العدّاد ثلاثاً تصير مراجعتها لازمة على فريق السلامة.
 *
 * غير تكراري: يُعلَّم في `metadata.learning_processed` فلا يُعاد.
 */
class PostClosureService
{
    private const FLAG_THRESHOLD = 3;

    public function __construct(private readonly PermitService $permits) {}

    public function process(Permit $permit, ?int $actorId = null): void
    {
        if ($permit->status !== Permit::STATUS_COMPLETED) {
            return;
        }

        $linked = $this->linkIncidents($permit);

        $meta = $permit->metadata ?? [];
        $evaluation = $meta['evaluation'] ?? null;

        if ($evaluation && empty($meta['learning_processed'])) {
            $flagged = $this->indicatesWeakControls($evaluation) ? $this->flagControls($permit, $evaluation) : 0;

            $meta['learning_processed'] = now()->toIso8601String();
            $meta['flagged_controls']   = $flagged;
            $permit->update(['metadata' => $meta]);

            $this->permits->recordEvent($permit, 'evaluated', [
                'linked_incidents' => $linked,
                'flagged_controls' => $flagged,
            ], $actorId);
        }
    }

    /** بلاغات مكان التصريح خلال مدته التي لا تصريح لها. يعيد عددها. */
    private function linkIncidents(Permit $permit): int
    {
        if (!$permit->starts_at || !$permit->place_id) {
            return 0;
        }

        $end = $permit->updated_at ?? now();

        return Incident::query()
            ->whereNull('permit_id')
            ->where('place_id', $permit->place_id)
            ->where('created_at', '>=', $permit->starts_at)
            ->where('created_at', '<=', $end)
            ->update(['permit_id' => $permit->id]);
    }

    private function indicatesWeakControls(array $evaluation): bool
    {
        return ($evaluation['severity_match'] ?? '') === 'underestimated'
            || ((int) ($evaluation['overall_rating'] ?? 5)) <= 2
            || trim((string) ($evaluation['what_failed'] ?? '')) !== '';
    }

    /** يعيد عدد البنود المعلَّمة. */
    private function flagControls(Permit $permit, array $evaluation): int
    {
        $controlIds = $permit->requirements()
            ->whereNotNull('risk_control_id')
            ->pluck('risk_control_id')
            ->unique();

        if ($controlIds->isEmpty()) {
            return 0;
        }

        $note = $this->buildNote($permit, $evaluation);
        $count = 0;

        DB::transaction(function () use ($controlIds, $note, &$count) {
            foreach (RiskControl::whereIn('id', $controlIds)->get() as $control) {
                $control->update([
                    'flag_count'   => (int) $control->flag_count + 1,
                    'review_flag'  => true,
                    'review_notes' => $note,
                ]);
                $count++;
            }
        });

        return $count;
    }

    private function buildNote(Permit $permit, array $evaluation): string
    {
        $parts = ["عُلّم بعد تقييم إغلاق التصريح {$permit->code}"];
        foreach ([
            'what_failed'       => 'ما أخفق',
            'lessons_learned'   => 'الدروس',
            'recommend_changes' => 'المقترح',
        ] as $key => $label) {
            if (!empty($evaluation[$key])) {
                $parts[] = $label.': '.mb_substr((string) $evaluation[$key], 0, 200);
            }
        }

        return implode("\n", $parts);
    }

    /** بنود التحكم التي بلغت حد المراجعة — لشاشة لوحة التصاريح. */
    public function controlsNeedingReview(): \Illuminate\Database\Eloquent\Collection
    {
        return RiskControl::where('review_flag', true)
            ->where('flag_count', '>=', self::FLAG_THRESHOLD)
            ->orderByDesc('flag_count')
            ->limit(20)
            ->get();
    }
}
