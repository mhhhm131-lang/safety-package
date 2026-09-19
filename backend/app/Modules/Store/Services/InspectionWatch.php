<?php

namespace App\Modules\Store\Services;

use App\Core\Services\NotificationService;
use App\Modules\Store\Inbox\InspectionReportTasks;
use App\Modules\Store\Models\InstituteDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * المرحلة ١٤ — إشعارات الفحص وسجل السلامة (قرار ٤٢، التصميم ١٤-٢ المعتمد ٢٠٢٦-٠٩-١٤).
 *
 * يعمل في الخادم لا في المتصفح: بعد حفظ وثيقة نموذج فحص (`afterSave` من StoreController)، وبأمر مجدول (`checkOverdue`).
 * المستلمون بكلمة المستخدم: مسؤول السلامة، مناوب مركز السلامة، مدير المرافق والصيانة.
 * كل حدث يُسجَّل مرة في inspection_notices (insertOrIgnore) فلا يتكرر الإشعار بتعدد الحفظ أو الأجهزة.
 * النموذج نفسه لا يُمس هنا: القراءة من الوثيقة كما يكتبها snapshot() في النماذج العشرة.
 */
class InspectionWatch
{
    public const RECIPIENTS = ['system_admin', 'system_staff', 'facilities_manager'];

    /** أيام كل دورية — كما في اللوحة (dashboard.html:658 FREQ_D)؛ الدوريات بلا مدة («قبل كل فعالية»…) لا تُحسب */
    public const FREQ_DAYS = ['يومي' => 1, 'أسبوعي' => 7, 'نصف شهري' => 15, 'شهري' => 30, 'ربع سنوي' => 91, 'فصلي' => 91, 'نصف سنوي' => 182, 'سنوي' => 365];

    public function __construct(private NotificationService $notify) {}

    public static function form(string $key): ?array
    {
        foreach (InspectionReportTasks::FORMS as $f) if ($f['key'] === $key) return $f;
        return null;
    }

    /** بعد حفظ وثيقة نموذج: إشعار البلاغات المرسلة، ولقطة الجولة، وإشعار الجولة المنتهية. */
    public function afterSave(string $key, ?string $oldRaw, string $newRaw): void
    {
        $f = self::form($key);
        if (!$f) return;
        $new = json_decode($newRaw, true);
        if (!is_array($new)) return;
        $old = $oldRaw ? json_decode($oldRaw, true) : null;
        if (!is_array($old)) $old = [];

        $this->reports($f, $old, $new);
        $this->rounds($f, $old, $new);
    }

    // ── بلاغ فحص: عولج موقعياً أو صُعِّد ──

    private function reports(array $f, array $old, array $new): void
    {
        $before = [];
        foreach ((array) ($old['reports'] ?? []) as $r) {
            if (is_array($r)) $before[$this->reportId($r)] = $this->decision($r);
        }
        foreach ((array) ($new['reports'] ?? []) as $r) {
            if (!is_array($r)) continue;
            $ld = $this->decision($r);
            if ($ld === null) continue;                                   // لم يُرسل بعد
            $id = $this->reportId($r);
            if (($before[$id] ?? null) === $ld) continue;                 // لم يتغير منذ الحفظ السابق
            $row = (string) ($r['row'] ?? '');
            if (!$this->claim('report:'.$f['key'].':'.$id.':'.$ld, 'inspection_report', $f['key'])) continue;

            $sys = explode('-', $row)[0];
            $sysName = (string) ($new['defs'][$sys]['name'] ?? '');
            $title = 'بلاغ فحص '.($r['id'] ?? $row).' · '.$f['name'].($sysName !== '' ? ' · '.$sysName : '');
            $state = $ld === 'up'
                ? 'صُعِّد لمدير المرافق والصيانة'.(!empty($r['sev']) ? ' ('.$r['sev'].(!empty($r['due']) && $r['due'] !== '—' ? ' · '.$r['due'] : '').')' : '')
                : 'عولج موقعياً';
            $message = mb_substr((string) ($r['item'] ?? ''), 0, 90).' — '.$state
                .(!empty($r['desc']) ? "\n".mb_substr((string) $r['desc'], 0, 200) : '');
            $this->notify->notifyRoles(self::RECIPIENTS, 'inspection_report', $title, $message, '/'.$f['file'].'#open='.rawurlencode($row));
        }
    }

    /** قرار المستوى ١ (up = صُعِّد، close = عولج) أو null إن لم يُرسل */
    private function decision(array $r): ?string
    {
        $l1 = $r['levels'][1] ?? $r['levels']['1'] ?? null;
        return is_array($l1) && !empty($l1['ld']) ? (string) $l1['ld'] : null;
    }

    /** البلاغ بدورته: إعادة الفتح (cycleAt جديد) حدث جديد */
    private function reportId(array $r): string
    {
        return ($r['row'] ?? '').'|'.($r['id'] ?? '').'|'.($r['cycleAt'] ?? '');
    }

    // ── سجل السلامة: الجولة كاملة، وإشعار «جولة نُفِّذت» ──

    private function rounds(array $f, array $old, array $new): void
    {
        $nf = $this->fields($new);
        $of = $this->fields($old);
        [$nd, $ns] = [trim((string) ($nf['dtRound'] ?? '')), trim((string) ($nf['tStart'] ?? ''))];
        [$od, $os] = [trim((string) ($of['dtRound'] ?? '')), trim((string) ($of['tStart'] ?? ''))];

        // الجولة السابقة لم تعد القائمة («جولة جديدة» يمسح تاريخها) ← تُثبَّت ويُنبَّه عنها إن لم يُنبَّه
        if ($od !== '' && ($od !== $nd || $os !== $ns)) {
            DB::table('inspection_rounds')->where(['form_key' => $f['key'], 'round_date' => $od, 'started_at' => $os])
                ->whereNull('closed_at')->update(['closed_at' => now(), 'updated_at' => now()]);
            $this->roundDone($f, $new, $od, $os);
        }

        // الجولة القائمة ← لقطتها الكاملة (ما لم تُثبَّت)
        if ($nd !== '') {
            $where = ['form_key' => $f['key'], 'round_date' => $nd, 'started_at' => $ns];
            $row = DB::table('inspection_rounds')->where($where)->first();
            $values = [
                'inspector' => $nf['insN'] ?? null, 'qualifier' => $nf['insQ'] ?? null, 'freq' => $nf['freq'] ?? null,
                'snapshot' => json_encode($this->snapshot($new, $nf), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ];
            if (!$row) DB::table('inspection_rounds')->insert($where + $values + ['created_at' => now()]);
            elseif (!$row->closed_at) DB::table('inspection_rounds')->where('id', $row->id)->update($values);
        }

        // «إنهاء الجولة وإرسالها» في المحطة ٥ ← إشعار الآن
        $done = $new['roundDone'] ?? null;
        if (is_array($done) && !empty($done['d'])) {
            $d = (string) $done['d']; $s = (string) ($done['s'] ?? '');
            DB::table('inspection_rounds')->where(['form_key' => $f['key'], 'round_date' => $d, 'started_at' => $s])
                ->whereNull('finished_at')->update(['finished_at' => now(), 'updated_at' => now()]);
            $this->roundDone($f, $new, $d, $s);
        }
    }

    private function roundDone(array $f, array $doc, string $d, string $s): void
    {
        if (!$this->claim('round:'.$f['key'].':'.$d.':'.$s, 'inspection_round', $f['key'])) return;
        $ok = $no = $na = $tot = 0; $n = ''; $sysList = [];
        foreach ((array) ($doc['rounds'] ?? []) as $sys => $rows) {
            foreach ((array) $rows as $e) {
                if (!is_array($e) || ($e['d'] ?? '') !== $d || (string) ($e['s'] ?? '') !== $s) continue;
                $ok += (int) ($e['ok'] ?? 0); $no += (int) ($e['no'] ?? 0); $na += (int) ($e['na'] ?? 0); $tot += (int) ($e['tot'] ?? 0);
                $n = $n ?: (string) ($e['n'] ?? ''); $sysList[] = (string) $sys;
            }
        }
        $title = 'جولة نُفِّذت · '.$f['name'].' · '.$d;
        $message = count($sysList).' نظام · '.$tot.' بنداً: '.$ok.' مطابق · '.$no.' غير مطابق · '.$na.' لا ينطبق'.($n !== '' ? ' · الفاحص: '.$n : '');
        $this->notify->notifyRoles(self::RECIPIENTS, 'inspection_round', $title, $message, '/'.$f['file'].($sysList ? '#sys='.$sysList[0] : ''));
    }

    /** الحقول الثابتة بالمعرّف كما يحفظها snapshot() */
    private function fields(array $doc): array
    {
        $out = [];
        foreach ((array) ($doc['fieldIds'] ?? []) as $i => $id) {
            if ($id !== '' && $id !== null) $out[$id] = $doc['fields'][$i] ?? null;
        }
        return $out;
    }

    private function snapshot(array $doc, array $fields): array
    {
        $systems = [];
        foreach ((array) ($doc['defs'] ?? []) as $k => $def) {
            if (is_array($def)) $systems[$k] = ['name' => $def['name'] ?? '', 'code' => $def['code'] ?? '', 'items' => $def['items'] ?? [], 'reads' => $def['reads'] ?? []];
        }
        return [
            'marks' => (object) ($doc['marks'] ?? []),
            'vals' => (object) ($doc['vals'] ?? []),
            'fields' => (object) $fields,
            'reports' => array_values(array_map(fn ($r) => is_array($r) ? (string) ($r['id'] ?? '') : '', array_filter((array) ($doc['reports'] ?? []), 'is_array'))),
            'systems' => (object) $systems,
        ];
    }

    // ── الفحوص التي فات موعدها (أمر مجدول) ──

    /** يعيد [عدد إشعارات «فات موعده» الجديدة، عدد المهام التي لم تُنفَّذ قط] */
    public function checkOverdue(): array
    {
        $today = now()->startOfDay();
        $docs = InstituteDocument::whereIn('key', array_column(InspectionReportTasks::FORMS, 'key'))->get()->keyBy('key');
        $sent = 0; $never = 0;
        foreach (InspectionReportTasks::FORMS as $f) {
            $doc = $docs->get($f['key']);
            $data = $doc ? json_decode($doc->data, true) : null;
            if (!is_array($data)) continue;
            foreach ((array) ($data['defs'] ?? []) as $sys => $def) {
                if (!is_array($def)) continue;
                $rounds = array_values(array_filter((array) ($data['rounds'][$sys] ?? []), 'is_array'));
                usort($rounds, fn ($a, $b) => strcmp(($b['d'] ?? '').($b['s'] ?? ''), ($a['d'] ?? '').($a['s'] ?? '')));
                foreach ((array) ($def['sched'] ?? []) as $sc) {
                    $freq = trim((string) ($sc[0] ?? ''));
                    $days = self::FREQ_DAYS[$freq] ?? null;
                    if (!$days) continue;
                    // كما في «مهامي»: آخر جولة بهذه الدورية، وإلا آخر جولة بلا دورية
                    $withF = array_values(array_filter($rounds, fn ($r) => ($r['f'] ?? '') === $freq));
                    $last = $withF[0] ?? (array_values(array_filter($rounds, fn ($r) => empty($r['f'])))[0] ?? null);
                    if (!$last || empty($last['d'])) { $never++; continue; }
                    try { $due = Carbon::parse($last['d'], config('app.timezone'))->startOfDay()->addDays($days); } catch (\Throwable) { continue; }
                    if (!$due->lt($today)) continue;
                    if (!$this->claim('overdue:'.$f['key'].':'.$sys.':'.$freq.':'.$due->toDateString(), 'inspection_overdue', $f['key'])) continue;
                    $this->notify->notifyRoles(self::RECIPIENTS, 'inspection_overdue',
                        'فات موعد: فحص '.$freq.' · '.$f['name'].' · '.($def['name'] ?? $sys),
                        mb_substr((string) ($sc[1] ?? ''), 0, 90).' — آخر تنفيذ '.$last['d'].' · كان مستحقاً '.$due->toDateString().(!empty($sc[2]) ? ' (مسؤوله: '.$sc[2].')' : ''),
                        '/'.$f['file'].'#sys='.$sys);
                    $sent++;
                }
            }
        }
        if ($never > 0 && $this->claim('never:'.$today->toDateString(), 'inspection_never', null)) {
            $this->notify->notifyRoles(self::RECIPIENTS, 'inspection_never',
                'مهام فحص دورية لم تُنفَّذ بعد: '.$never,
                'لا جولة مسجّلة لها في سجل الجولات — التفصيل في «ما ينتظرك»', '/app');
        }
        return [$sent, $never];
    }

    /** يسجّل الحدث مرة؛ false إن سُجّل من قبل. insertOrIgnore لا يرمي خطأ فلا يُفسد معاملة Postgres */
    private function claim(string $key, string $type, ?string $formKey): bool
    {
        return DB::table('inspection_notices')->insertOrIgnore([
            'notice_key' => mb_substr($key, 0, 191), 'type' => $type, 'form_key' => $formKey, 'created_at' => now(),
        ]) === 1;
    }
}
