<?php

namespace App\Modules\Store\Services;

use App\Modules\Store\Inbox\InspectionReportTasks;
use App\Modules\Store\Models\InstituteDocument;

/**
 * المرحلة ١٩-١ (قرار ٤٨): قارئ واحد لوثائق نماذج الفحص — منطق اللوحة حرفياً (dashboard.html:440-514, 636-658).
 * كان منسوخاً في InspectionReportTasks وفي dashboard.html؛ الآن مصدر واحد في الخلفية يستعمله «ما ينتظرك» وملف المكان.
 */
class InspectionDocReader
{
    /** المهل بالساعات كما في النماذج — قيم النماذج لا قيم مخترعة */
    public const DUE_H = ['فوري' => 1, '٢٤ ساعة' => 24, '٧٢ ساعة' => 72];

    /** أيام الدورية كما في اللوحة (dashboard.html:637) */
    public const FREQ_DAYS = ['يومي' => 1, 'أسبوعي' => 7, 'نصف شهري' => 15, 'شهري' => 30, 'ربع سنوي' => 91, 'فصلي' => 91, 'نصف سنوي' => 182, 'سنوي' => 365];

    public const STATE_LABELS = ['ok' => 'فُحص في موعده وسليم', 'late' => 'فحص متأخر', 'fault' => 'في آخر فحص ✗', 'none' => 'لم يُفحص بعد'];

    public static function isClosed(array $r): bool
    {
        foreach ((array) ($r['levels'] ?? []) as $l) {
            if (is_array($l) && empty($l['up']) && empty($l['back'])) return true;
        }
        return false;
    }

    public static function lastLvl(array $r): int
    {
        $lv = array_map('intval', array_keys((array) ($r['levels'] ?? [])));
        return $lv ? max($lv) : 0;
    }

    public static function holder(array $r): int
    {
        if (self::isClosed($r)) return 0;
        $last = self::lastLvl($r);
        $backFrom = (!empty($r['backFrom']) && empty($r['levels'][1])) ? (int) $r['backFrom'] : 0;
        if (!empty($r['reopen']) || $backFrom || !$last) return 1;
        return min($last + 1, 4);
    }

    /** الساعات فوق المهلة (موجب = متأخر)؛ null بلا مهلة أو بلا طابع زمني */
    public static function overdueHours(array $r): ?float
    {
        if (self::isClosed($r)) return null;
        $h = self::DUE_H[$r['due'] ?? ''] ?? null;
        if (!$h) return null;
        $d = self::parseStamp($r['cycleAt'] ?? '') ?? self::parseStamp($r['when'] ?? '') ?? self::parseStamp($r['sent'] ?? '');
        if (!$d) return null;
        return (now()->getTimestamp() - $d->getTimestamp()) / 3600 - $h;
    }

    /** «٢٠٢٦/٠٩/١٢ — ١٤:٠٥» بأرقام عربية أو لاتينية → وقت */
    public static function parseStamp(?string $s): ?\DateTimeImmutable
    {
        if (!$s) return null;
        $en = strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
        if (!preg_match('/(\d{4})\/(\d{2})\/(\d{2})\D+(\d{2}):(\d{2})/', $en, $m)) return null;
        return new \DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:00", new \DateTimeZone(config('app.timezone', 'UTC')));
    }

    /** نماذج المكان (مركز السلامة له نموذجان) */
    public static function formsOf(string $hz): array
    {
        return array_values(array_filter(InspectionReportTasks::FORMS, fn ($f) => $f['hz'] === $hz));
    }

    /**
     * وثائق نماذج المكان مفكوكة: [key => ['form' => f, 'data' => array]]
     */
    public static function docsOf(string $hz): array
    {
        $forms = self::formsOf($hz);
        $docs = InstituteDocument::whereIn('key', array_column($forms, 'key'))->get()->keyBy('key');
        $out = [];
        foreach ($forms as $f) {
            $d = $docs->get($f['key']);
            $j = $d ? json_decode($d->data, true) : null;
            $out[$f['key']] = ['form' => $f, 'data' => is_array($j) ? $j : []];
        }
        return $out;
    }

    /** أقصر دورية في جدول النظام بالأيام واسمها (dashboard.html:641 freqDays) */
    public static function shortestFreq(array $sched): array
    {
        $days = null; $name = null;
        foreach ($sched as $row) {
            $n = self::FREQ_DAYS[trim((string) ($row[0] ?? ''))] ?? null;
            if ($n && ($days === null || $n < $days)) { $days = $n; $name = trim((string) $row[0]); }
        }
        return [$days, $name];
    }

    /**
     * أنظمة المكان من وثائق نماذجه (dashboard.html:643-658 systemsOf) — الحالة: ok / late / fault / none.
     * @return array<int, array{k:string,form:array,name:string,code:string,last:?array,rounds:array,days:?int,freq:?string,next:?string,st:string,open:int,reports:array}>
     */
    public static function systemsOf(string $hz): array
    {
        $out = []; $today = now()->startOfDay();
        foreach (self::docsOf($hz) as $key => $doc) {
            $d = $doc['data'];
            foreach ((array) ($d['defs'] ?? []) as $k => $def) {
                if (!is_array($def)) continue;
                $rounds = array_values(array_filter((array) (($d['rounds'] ?? [])[$k] ?? []), 'is_array'));
                usort($rounds, fn ($a, $b) => strcmp(($b['d'] ?? '').($b['s'] ?? ''), ($a['d'] ?? '').($a['s'] ?? '')));
                $last = $rounds[0] ?? null;
                [$days, $freq] = self::shortestFreq((array) ($def['sched'] ?? []));
                $next = null;
                if ($last && $days && !empty($last['d']) && ($t = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $last['d'], 0, 10)))) {
                    $next = $t->modify("+{$days} days")->format('Y-m-d');
                }
                $st = !$last ? 'none' : (((int) ($last['no'] ?? 0)) > 0 ? 'fault' : (($next && $next < $today->toDateString()) ? 'late' : 'ok'));
                $reps = array_values(array_filter((array) ($d['reports'] ?? []), fn ($r) => is_array($r) && explode('-', (string) ($r['row'] ?? ''))[0] === (string) $k));
                $out[] = ['k' => (string) $k, 'form' => $doc['form'], 'name' => (string) ($def['name'] ?? $k), 'code' => (string) ($def['code'] ?? ''), 'named' => !empty($def['name']),
                    'last' => $last, 'rounds' => $rounds, 'days' => $days, 'freq' => $freq, 'next' => $next, 'st' => $st,
                    'open' => count(array_filter($reps, fn ($r) => !self::isClosed($r))), 'reports' => $reps];
            }
        }
        return $out;
    }

    /**
     * المرحلة ١٩-٢: نظام واحد بتفاصيله (dashboard.html:715-740 renderSystem) — البنود بعلاماتها، القراءات بمرجعيتها والمقاسة،
     * الجدول الدوري، والبلاغات المفتوح أولاً ثم الأشد تأخراً. null إن لم يوجد النظام في نموذج هذا المكان.
     */
    public static function systemOf(string $hz, string $formKey, string $k): ?array
    {
        $sys = null;
        foreach (self::systemsOf($hz) as $s) if ($s['form']['key'] === $formKey && $s['k'] === $k) { $sys = $s; break; }
        if (!$sys) return null;
        $d = self::docsOf($hz)[$formKey]['data'] ?? [];
        $def = (array) (($d['defs'] ?? [])[$k] ?? []);
        $marks = (array) ($d['marks'] ?? []); $vals = (array) ($d['vals'] ?? []);
        $items = [];
        foreach (array_values((array) ($def['items'] ?? [])) as $i => $it) {
            $items[] = ['i' => $i, 'text' => (string) (is_array($it) ? ($it[0] ?? '') : $it), 'ref' => (string) (is_array($it) ? ($it[1] ?? '') : ''), 'mark' => (string) ($marks["$k-i-$i"] ?? '')];
        }
        $reads = [];
        foreach (array_values((array) ($def['reads'] ?? [])) as $i => $t) {
            $b = "$k-r-$i";
            $reads[] = ['i' => $i, 'text' => (string) $t, 'ref' => (string) ($vals["$b|ref"] ?? ''), 'act' => (string) ($vals["$b|act"] ?? ''), 'mark' => (string) ($marks[$b] ?? '')];
        }
        $reps = $sys['reports'];
        usort($reps, fn ($a, $b) => (self::isClosed($a) <=> self::isClosed($b)) ?: ((self::overdueHours($b) ?? -INF) <=> (self::overdueHours($a) ?? -INF)));
        return $sys + ['items' => $items, 'reads' => $reads, 'sched' => array_values(array_filter((array) ($def['sched'] ?? []), 'is_array')), 'reports_sorted' => $reps];
    }

    /** أيام العطل: من الاكتشاف إلى الإغلاق (أو إلى الآن إن كان مفتوحاً) — dashboard.html:709-714 */
    public static function faultDays(array $r): ?int
    {
        $a = self::parseStamp($r['when'] ?? '') ?? self::parseStamp($r['sent'] ?? '');
        if (!$a) return null;
        $b = new \DateTimeImmutable('now', $a->getTimezone());
        foreach ((array) ($r['levels'] ?? []) as $l) {
            if (is_array($l) && empty($l['up']) && empty($l['back'])) { $b = self::parseStamp($l['date'] ?? '') ?? $b; break; }
        }
        return max(0, (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400));
    }

    /** آخر جولة في نموذج (أحدث تاريخ عبر أنظمته) وعدد جولاته — لشاشة «نماذج الفحص» */
    public static function roundsSummary(array $data): array
    {
        $n = 0; $last = null;
        foreach ((array) ($data['rounds'] ?? []) as $list) {
            foreach ((array) $list as $r) {
                if (!is_array($r) || empty($r['d'])) continue;
                $n++; if ($last === null || (string) $r['d'] > $last) $last = (string) $r['d'];
            }
        }
        return ['count' => $n, 'last' => $last];
    }

    /** كل بلاغات فحص المكان مع نموذجها */
    public static function reportsOf(string $hz): array
    {
        $out = [];
        foreach (self::docsOf($hz) as $doc) {
            foreach ((array) ($doc['data']['reports'] ?? []) as $r) {
                if (is_array($r)) $out[] = $r + ['_form' => $doc['form']];
            }
        }
        return $out;
    }
}
