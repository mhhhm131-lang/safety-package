<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Models\EmergencyIncidentStep;
use App\Modules\Governance\Models\Place;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * المرحلة ١٠-٤ (قرار ٣٠، المكوّن ز): «الالتزام بالخطة» — لكل خطوة: المستهدف، الفعلي، الفارق، من، ملاحظة؛
 * ومؤشر لكل مكان: زمن الخطوات الثلاث الأولى (الفريق الأولي، استدعاء الطبيب، وصوله) اتجاهاً عبر الأشهر والتمارين.
 * كل الأرقام من الخطوات المسجَّلة فعلاً؛ ما لم يُعلَّم يُعرض «لم تُعلَّم» ولا يُحسب زمناً.
 */
class PlanComplianceService
{
    /** الخطوات الثلاث الأولى المقيسة لكل مكان (المسار الطبي في الوثائق الثماني): ١ التدخل الأولي · ٢ استدعاء الطبيب · ٣ وصول الطبيب. */
    public const FIRST_STEPS = ['١' => 'التدخل الأولي', '٢' => 'استدعاء الطبيب', '٣' => 'وصول الطبيب'];

    /** @return array<int, array> صف لكل خطوة بترتيب الوثيقة */
    public function rows(EmergencyIncident $incident): array
    {
        $t0 = $incident->triggered_at;
        return $incident->planSteps()->orderBy('sort')->get()->map(function (EmergencyIncidentStep $s) use ($t0) {
            $elapsed = $s->done_at && $t0 ? (int) abs($s->done_at->diffInSeconds($t0)) : null;
            $state = match (true) {
                $s->status === 'done' && $s->delta_sec !== null && $s->delta_sec > 0 => 'late',
                $s->status === 'done' && $s->window_to_sec === null => 'done_conditional', // شرطية تمت: تُذكر ولا تُقاس زمناً
                $s->status === 'done' => 'on_time',
                $s->status === 'skipped' => 'skipped',
                $s->is_conditional => 'conditional',      // معلّقة شرطية: لا تُحاسَب
                default => 'missed',                      // معلّقة لها نافذة ولم تُعلَّم
            };
            $card = $s->primaryCard();
            return [
                'id' => $s->id, 'label' => $s->label, 'path' => $s->path_title, 'title' => $s->title,
                'target' => $s->when_text, 'window_to_sec' => $s->window_to_sec, 'conditional' => $s->is_conditional,
                'elapsed_sec' => $elapsed, 'done_at' => $s->done_at?->format('H:i:s'), 'delta_sec' => $s->delta_sec,
                'delta_label' => $s->status === 'done' ? $s->deltaLabel() : null,
                'status' => $s->status, 'state' => $state,
                'owner' => $card ? $card['no'].' '.$card['name'] : ($s->who_text ?? '—'),
                'by' => $s->done_by_name, 'auto' => $s->auto_source ? (EmergencyIncidentStep::AUTO_LABELS[$s->auto_source] ?? $s->auto_source) : null,
                'note' => $s->note,
            ];
        })->all();
    }

    /** ملخص الالتزام: تمت ضمن النافذة، تأخرت، لم تُعلَّم، تُخطّيت، شرطية لم تتحقق. */
    public function summary(EmergencyIncident $incident): array
    {
        $rows = $this->rows($incident);
        $c = ['total' => count($rows), 'on_time' => 0, 'late' => 0, 'missed' => 0, 'skipped' => 0, 'conditional' => 0, 'done_conditional' => 0];
        foreach ($rows as $r) $c[$r['state']]++;
        $measured = $c['on_time'] + $c['late'] + $c['missed'];
        $c['measured'] = $measured;
        $c['ratio'] = $measured ? (int) round($c['on_time'] / $measured * 100) : null; // null = لا خطوات مقيسة
        $c['label'] = $c['total'] === 0 ? 'لا خطة' : ($measured === 0 ? 'لم تُقس' : $c['on_time'].'/'.$measured.' ضمن النافذة');
        return $c;
    }

    /**
     * لكل مكان: عدد الحالات ذات خطوات، ومتوسط ثواني الخطوات الثلاث الأولى (المسار الطبي ١، ٢، ٣) لما تمّ منها، ونسبة الالتزام.
     * التمارين تدخل (تُقاس بالمسطرة نفسها) وتُعدّ على حدة.
     */
    public function placeStats(Carbon $from, Carbon $to, ?int $placeId = null): array
    {
        $incidents = EmergencyIncident::whereBetween('triggered_at', [$from, $to])
            ->when($placeId, fn ($q) => $q->where('place_id', $placeId))
            ->whereHas('planSteps')->with('planSteps')->get();
        $byPlace = $incidents->groupBy('place_id');

        return Place::orderBy('sort')->get(['id', 'code', 'name'])
            ->when($placeId, fn ($p) => $p->where('id', $placeId))
            ->map(function (Place $p) use ($byPlace) {
                $list = $byPlace->get($p->id, collect());
                $row = ['code' => $p->code, 'name' => $p->name, 'incidents' => $list->where('is_drill', false)->count(), 'drills' => $list->where('is_drill', true)->count(), 'steps' => []];
                $onTime = 0; $measured = 0;
                foreach ($list as $i) {
                    foreach ($i->planSteps as $s) {
                        if ($s->window_to_sec === null) continue;
                        if ($s->status === 'done') { $measured++; if (($s->delta_sec ?? 0) <= 0) $onTime++; }
                        elseif ($s->status === 'pending' && !$i->isOpen()) { $measured++; }
                    }
                }
                foreach (self::FIRST_STEPS as $label => $name) {
                    $steps = $list->flatMap->planSteps->filter(fn ($s) => $s->path_key === 'medical' && $s->label === $label);
                    $done = $steps->where('status', 'done')->filter(fn ($s) => $s->done_at !== null);
                    $secs = $done->map(fn ($s) => (int) abs($s->done_at->diffInSeconds($s->incident->triggered_at)));
                    $row['steps'][$label] = [
                        'name' => $name, 'target_sec' => $steps->first()?->window_to_sec,
                        'count' => $done->count(), 'avg_sec' => $done->count() ? (int) round($secs->avg()) : null, 'max_sec' => $done->count() ? (int) $secs->max() : null,
                        'not_marked' => $steps->count() - $done->count(),
                    ];
                }
                $row['measured'] = $measured; $row['on_time'] = $onTime;
                $row['ratio'] = $measured ? (int) round($onTime / $measured * 100) : null;
                return $row;
            })->values()->all();
    }

    /** الاتجاه شهراً بشهر: متوسط ثواني «التدخل الأولي» (الخطوة ١ الطبية) ونسبة الالتزام. */
    public function monthlyTrend(Carbon $from, Carbon $to, ?int $placeId = null): array
    {
        $steps = EmergencyIncidentStep::query()
            ->join('emergency_incidents as ei', 'ei.id', '=', 'emergency_incident_steps.incident_id')
            ->whereBetween('ei.triggered_at', [$from, $to])
            ->when($placeId, fn ($q) => $q->where('ei.place_id', $placeId))
            ->whereNotNull('emergency_incident_steps.window_to_sec')
            ->get(['emergency_incident_steps.*', 'ei.triggered_at as t0', 'ei.is_drill as drill']);
        $out = [];
        foreach ($steps->groupBy(fn ($s) => Carbon::parse($s->t0)->format('Y-m')) as $month => $list) {
            $done = $list->where('status', 'done');
            $first = $done->filter(fn ($s) => $s->path_key === 'medical' && $s->label === '١');
            $out[] = [
                'month' => $month,
                'incidents' => $list->pluck('incident_id')->unique()->count(),
                'first_step_avg_sec' => $first->count() ? (int) round($first->avg(fn ($s) => abs(Carbon::parse($s->done_at)->diffInSeconds(Carbon::parse($s->t0))))) : null,
                'ratio' => $done->count() ? (int) round($done->filter(fn ($s) => ($s->delta_sec ?? 0) <= 0)->count() / $done->count() * 100) : null,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['month'], $b['month']));
        return $out;
    }

    public static function secs(?int $s): string
    {
        return $s === null ? '—' : EmergencyIncidentStep::secs($s);
    }
}
