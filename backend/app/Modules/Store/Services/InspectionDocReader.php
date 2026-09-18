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
                    'last' => $last, 'rounds' => $rounds, 'days' => $days, 'freq' => $freq, 'next' => $next, 'st' => $st, 'sched_rows' => array_values(array_filter((array) ($def['sched'] ?? []), 'is_array')),
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

    // ── المرحلة ١٩-٣: مهامي، أين تقف البلاغات، بلاغاتي (dashboard.html:459-474, 588-600, 741-781) ──

    /** من يخصه سطر الجدول الدوري: كلمات عمود «مَن» لكل دور واجهة (dashboard.html:742 WHO_ROLE) */
    public const WHO_ROLE = ['tech' => ['الفني'], 'fm' => ['المرافق والصيانة'], 'safety' => ['مسؤول السلامة', 'المناوب', 'المشرف'], 'cons' => ['المكتب']];

    /** مستوى القرار لكل دور واجهة (dashboard.html:468 RLVL) */
    public const ROLE_LEVEL = ['tech' => 1, 'fm' => 2, 'adm' => 3, 'exec' => 4];

    public const LEVEL_NAMES = [1 => 'الفني', 2 => 'مدير المرافق', 3 => 'مدير الشؤون الإدارية', 4 => 'الإدارة العليا'];

    public static function allPlaces(): array
    {
        return array_values(array_unique(array_column(InspectionReportTasks::FORMS, 'hz')));
    }

    /**
     * الجولات المستحقة على دور الواجهة (myTasks): لكل مكان × نظام × سطر جدول دوري دوريته معروفة ويخص الدور.
     * left = الأيام الباقية (سالب = متأخرة، null = لم تُنفَّذ). المستحق: null أو ≤ ٧.
     * @return array<int, array{hz:string,form:array,k:string,system:string,freq:string,task:string,who:string,last:?array,next:?string,left:?int}>
     */
    public static function dueRounds(string $ui, bool $onlyDue = true): array
    {
        $mine = self::WHO_ROLE[$ui] ?? null;
        if (!$mine) return [];
        $today = new \DateTimeImmutable(now()->toDateString());
        $out = [];
        foreach (self::allPlaces() as $hz) {
            foreach (self::systemsOf($hz) as $s) {
                foreach ($s['sched_rows'] as $sc) { // من systemsOf — بلا قراءة ثانية للوثيقة
                    $freq = trim((string) ($sc[0] ?? '')); $days = self::FREQ_DAYS[$freq] ?? null;
                    if (!$days) continue;
                    $who = (string) ($sc[2] ?? '');
                    $owns = false; foreach ($mine as $w) if ($w !== '' && mb_strpos($who, $w) !== false) { $owns = true; break; }
                    if (!$owns) continue;
                    $withF = array_values(array_filter($s['rounds'], fn ($r) => ($r['f'] ?? '') === $freq));
                    $last = $withF[0] ?? (array_values(array_filter($s['rounds'], fn ($r) => empty($r['f'])))[0] ?? null);
                    $next = null; $left = null;
                    if ($last && !empty($last['d']) && ($t = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $last['d'], 0, 10)))) {
                        $n = $t->modify("+{$days} days"); $next = $n->format('Y-m-d');
                        $left = (int) round(($n->getTimestamp() - $today->getTimestamp()) / 86400);
                    }
                    if ($onlyDue && $left !== null && $left > 7) continue;
                    $out[] = ['hz' => $hz, 'form' => $s['form'], 'k' => $s['k'], 'system' => $s['name'], 'freq' => $freq, 'task' => (string) ($sc[1] ?? ''), 'who' => $who, 'last' => $last, 'next' => $next, 'left' => $left];
                }
            }
        }
        return $out;
    }

    /** كل بلاغات الفحص في الأماكن كلها مع نموذجها */
    public static function allReports(): array
    {
        $out = [];
        foreach (self::allPlaces() as $hz) foreach (self::reportsOf($hz) as $r) $out[] = $r;
        return $out;
    }

    /** سكة «أين تقف البلاغات»: لكل مستوى عدد المفتوح والمتأخر (dashboard.html:588-593) */
    public static function rail(): array
    {
        $cnt = [1 => 0, 2 => 0, 3 => 0, 4 => 0]; $od = [1 => 0, 2 => 0, 3 => 0, 4 => 0]; $open = 0;
        foreach (self::allReports() as $r) {
            if (self::isClosed($r)) continue;
            $open++; $h = self::holder($r);
            if ($h) { $cnt[$h]++; if ((self::overdueHours($r) ?? -1) >= 0) $od[$h]++; }
        }
        return ['open' => $open, 'cnt' => $cnt, 'od' => $od];
    }

    /** هل ينتظر قرار هذا الدور الآن (mine — كما في InspectionReportTasks) */
    public static function waitsFor(array $r, string $ui): bool
    {
        $last = self::lastLvl($r); $closed = self::isClosed($r); $L = (array) ($r['levels'] ?? []);
        $up = fn (int $n) => !empty($L[$n]['up']);
        return match ($ui) {
            'tech' => !$closed && ($last === 0 || !empty($r['backFrom'])),
            'fm' => !$closed && $last === 1 && $up(1),
            'adm' => !$closed && $last === 2 && $up(2),
            'exec' => !$closed && (($last === 3 && $up(3)) || (!empty($r['path']) && $r['path'] !== 'إداري' && $last > 0 && $last < 4 && $up($last))),
            'safety' => !$closed,
            default => false,
        };
    }

    /** «بلاغاتي»: قررتُ فيها ولم تُغلق ولا تنتظرني الآن (mineDone:469-474) */
    public static function decidedByRole(string $ui): array
    {
        $lv = self::ROLE_LEVEL[$ui] ?? null;
        if (!$lv) return [];
        $reached = function ($L) use ($lv): bool {
            $L = (array) $L;
            return !empty($L[$lv]) || (!empty($L[$lv - 1]) && !empty($L[$lv - 1]['up']));
        };
        $out = [];
        foreach (self::allReports() as $r) {
            if (self::isClosed($r) || self::waitsFor($r, $ui)) continue;
            if ($ui === 'tech') { if (!empty($r['sent'])) $out[] = $r; continue; }
            $hit = $reached($r['levels'] ?? []) || ($lv === 4 && !empty($r['path']) && $r['path'] !== 'إداري');
            if (!$hit) foreach ((array) ($r['hist'] ?? []) as $H) { if ($reached(is_array($H) ? ($H['levels'] ?? $H) : [])) { $hit = true; break; } }
            if ($hit) $out[] = $r;
        }
        usort($out, fn ($a, $b) => (self::overdueHours($b) ?? -INF) <=> (self::overdueHours($a) ?? -INF));
        return $out;
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
