<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\BuildingComplianceItem;
use App\Modules\Emergency\Models\EmergencyBuilding;
use Illuminate\Support\Facades\DB;

/**
 * زرع جدول المقارنة لكل مبنى من وثيقة المعهد `comparison.html` (المرحلة ٣١-١، قرار ٩٠) على مثال القرّاء الثلاثة:
 * الوثيقة تُقرأ مرة؛ البصمة تمنع إعادة القراءة؛ وإن تغيّرت تبع غيرُ المعدَّل واحتفظ المعدَّل بكلامه وحُدّث أصله. لا كتابة عكسية.
 *
 * الوثيقة بأنماط سطرية ثابتة: رأس المادة «المادة N» ثم عنوانها (font-size:17px)، وثلاث خانات بعناوين «◆ ما تتطلبه اللائحة»
 * و«● الوضع الراهن» (قد تكون «—») و«✓ ما أعدّه مسؤول السلامة»؛ وتحت بعض المواد «تفصيل البنود» ثلاثيات خلايا (البند، الوضع الحالي، ما أعدّه).
 */
class ComplianceSync
{
    public const FILE = 'comparison.html';

    /** @return array<string, array{status:string, rows:int, edited:int}> بمفتاح رمز المبنى */
    public function sync(?string $from = null, bool $force = false): array
    {
        $result = [];
        foreach (EmergencyBuilding::orderBy('id')->get() as $b) $result[$b->code ?: ('#'.$b->id)] = $this->syncBuilding($b, $from, $force);
        return $result;
    }

    public function syncBuilding(EmergencyBuilding $b, ?string $from = null, bool $force = false): array
    {
        $path = $this->resolvePath($from);
        if (!$path) return ['status' => 'missing', 'rows' => 0, 'edited' => 0];
        $html = file_get_contents($path);
        $fingerprint = sha1($html);
        $existing = BuildingComplianceItem::where('building_id', $b->id)->get()->keyBy('key');
        $first = $existing->first();
        if ($first && !$force && $first->fingerprint === $fingerprint && $first->source_path === $path && $existing->every(fn ($r) => $r->fingerprint === $fingerprint)) {
            return ['status' => 'unchanged', 'rows' => $existing->count(), 'edited' => $existing->filter(fn ($r) => $r->isEdited())->count()];
        }
        $rows = $this->parse($html);
        DB::transaction(function () use ($b, $rows, $existing, $path, $fingerprint) {
            $seen = [];
            foreach ($rows as $r) {
                $seen[$r['key']] = true;
                $source = ['status' => BuildingComplianceItem::UNRATED, 'current_state' => $r['current_state'], 'action' => null, 'holder_name' => null, 'due_date' => null, 'notes' => null];
                $shape = ['article_no' => $r['article_no'], 'item_no' => $r['item_no'], 'sort' => $r['sort'], 'title' => $r['title'],
                    'requirement' => $r['requirement'], 'prepared' => $r['prepared'], 'source_path' => $path, 'fingerprint' => $fingerprint, 'synced_at' => now()];
                if ($row = $existing->get($r['key'])) {
                    $row->fill($shape);
                    $row->source = $source;
                    if (!$row->isEdited()) $row->fill($source); // غير المعدَّل يتبع الوثيقة؛ المعدَّل يحتفظ بكلامه
                    $row->save();
                } else {
                    BuildingComplianceItem::create($shape + $source + ['building_id' => $b->id, 'key' => $r['key'], 'source' => $source]);
                }
            }
            foreach ($existing as $k => $row) if (!isset($seen[$k]) && !$row->isEdited()) $row->delete();
        });
        BuildingComplianceItem::flush();
        $all = BuildingComplianceItem::where('building_id', $b->id)->get();
        return ['status' => 'synced', 'rows' => $all->count(), 'edited' => $all->filter(fn ($r) => $r->isEdited())->count()];
    }

    /** مسار الوثيقة: المجلد المُمرَّر ← مجلد المستودع الأعلى ← public/. */
    public function resolvePath(?string $from = null): ?string
    {
        $candidates = $from !== null
            ? [rtrim($from, '/\\').DIRECTORY_SEPARATOR.self::FILE]
            : [dirname(base_path()).DIRECTORY_SEPARATOR.self::FILE, public_path(self::FILE)];
        foreach ($candidates as $p) if (is_file($p)) return $p;
        return null;
    }

    /** @return array<int, array{key:string, article_no:int, item_no:?int, sort:int, title:string, requirement:?string, prepared:?string, current_state:?string}> */
    public function parse(string $html): array
    {
        $start = strpos($html, 'id="doc"');
        $doc = $start === false ? $html : substr($html, $start);
        preg_match_all('/<span style="font-size:12px;color:#8A8578;font-weight:500;">المادة ([٠-٩0-9]+)<\/span>\s*<span style="[^"]*font-size:17px;color:#F6F3EC;">([^<]+)<\/span>/u', $doc, $heads, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        if (count($heads) !== 16) throw new \RuntimeException('وثيقة المقارنة ليست بست عشرة مادة: '.count($heads));
        $rows = [];
        $sort = 0;
        foreach ($heads as $i => $h) {
            $from = $h[0][1];
            $to = isset($heads[$i + 1]) ? $heads[$i + 1][0][1] : strlen($doc);
            $section = substr($doc, $from, $to - $from);
            $no = $this->int($h[1][0]);
            $rows[] = ['key' => (string) $no, 'article_no' => $no, 'item_no' => null, 'sort' => ++$sort, 'title' => $this->text($h[2][0]),
                'requirement' => $this->cellAfter($section, '◆ ما تتطلبه اللائحة'), 'current_state' => $this->cellAfter($section, '● الوضع الراهن'),
                'prepared' => $this->cellAfter($section, '✓ ما أعدّه مسؤول السلامة')];
            $p = strpos($section, 'تفصيل البنود');
            if ($p === false) continue;
            preg_match_all('/<div style="background:#FFFFFF;padding:11px 14px;[^"]*">(.*?)<\/div>\s*<div style="background:#F5F5F5;padding:11px 14px;[^"]*">(.*?)<\/div>\s*<div style="background:#F1F6F3;padding:11px 14px;[^"]*">(.*?)<\/div>/su', substr($section, $p), $items, PREG_SET_ORDER);
            foreach ($items as $it) {
                $raw = $this->text($it[1]);
                [$numText, $title] = array_pad(array_map('trim', explode('—', $raw, 2)), 2, '');
                $itemNo = $this->int($numText);
                if ($itemNo < 1 || $title === '') throw new \RuntimeException("بند بلا رقم أو عنوان تحت المادة $no: «$raw»");
                $rows[] = ['key' => $no.'.'.$itemNo, 'article_no' => $no, 'item_no' => $itemNo, 'sort' => ++$sort, 'title' => $title,
                    'requirement' => null, 'current_state' => $this->nullDash($this->text($it[2])), 'prepared' => $this->nullDash($this->text($it[3]))];
            }
        }
        return $rows;
    }

    /** نص الخلية التي تلي عنوانها (مثل «● الوضع الراهن»)؛ «—» = لا نص. */
    private function cellAfter(string $section, string $label): ?string
    {
        $p = strpos($section, $label);
        if ($p === false) return null;
        if (!preg_match('/<\/div>\s*<div style="font-size:(?:13|20)px[^"]*">(.*?)<\/div>/su', substr($section, $p), $m)) return null;
        return $this->nullDash($this->text($m[1]));
    }

    private function nullDash(string $t): ?string { return ($t === '' || $t === '—') ? null : $t; }

    private function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/iu', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }

    private function int(string $s): int
    {
        return (int) strtr($s, ['٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }
}
