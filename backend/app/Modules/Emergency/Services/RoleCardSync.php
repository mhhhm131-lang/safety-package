<?php

namespace App\Modules\Emergency\Services;

use App\Modules\Emergency\Models\BuildingRoleCard;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Support\RoleCards;

/**
 * زرع بطاقات الأدوار الـ٢١ لكل مبنى من ملفات المعهد `role-cards/{الفئة}/role-NN.html` (المرحلة ٣٠-٣، قرار ٨٩)
 * على مثال `SafetyPlanSync` و`ResponsePlanSync`: الملف للصنف ويُقرأ مرة؛ البصمة تمنع إعادة القراءة؛ وإن تغيّر الملف
 * تبع غيرُ المعدَّل، واحتفظ المعدَّل بكلامه وحُدّث أصله فقط. لا كتابة عكسية أبداً.
 *
 * الملف بأنماط سطرية ثابتة: الاسم (font-size:18px)، الوصف تحته (font-size:13px)، «التبعية: …» و«السند: …»،
 * وثلاث قوائم <ul> بعد عناوين «◆ استباقية» و«● تشغيلية» و«▲ استجابة».
 */
class RoleCardSync
{
    /** @return array<string, array{status:string, cards:int, edited:int, missing:int[]}> بمفتاح رمز المبنى */
    public function sync(?string $from = null, bool $force = false): array
    {
        $result = [];
        foreach (EmergencyBuilding::orderBy('id')->get() as $b) {
            $result[$b->code ?: ('#'.$b->id)] = $this->syncBuilding($b, $from, $force);
        }
        return $result;
    }

    /** مبنى واحد — رخيصة (sha1 لـ٢١ ملفاً صغيراً) فتُستدعى عند فتح شاشة البطاقات. */
    public function syncBuilding(EmergencyBuilding $b, ?string $from = null, bool $force = false): array
    {
        $existing = BuildingRoleCard::where('building_id', $b->id)->get()->keyBy('card_no');
        $n = 0; $changed = 0; $missing = [];
        foreach (RoleCards::CARDS as $no => $c) {
            $path = $this->resolvePath($no, $from);
            if (!$path) { $missing[] = $no; continue; }
            $html = file_get_contents($path);
            $fingerprint = sha1($html);
            $row = $existing->get($no);
            if ($row && !$force && $row->fingerprint === $fingerprint && $row->source_path === $path) { $n++; continue; }
            $source = $this->parse($html, $no) + ['holder_name' => null, 'holder_phone' => null];
            if ($row) {
                $row->source = $source;
                $row->fill(['category' => $c['category'], 'source_path' => $path, 'fingerprint' => $fingerprint, 'synced_at' => now()]);
                if (!$row->isEdited()) $row->fill($source); // غير المعدَّل يتبع الملف؛ المعدَّل يحتفظ بكلامه
                $row->save();
            } else {
                BuildingRoleCard::create($source + ['building_id' => $b->id, 'card_no' => $no, 'category' => $c['category'],
                    'source' => $source, 'source_path' => $path, 'fingerprint' => $fingerprint, 'synced_at' => now()]);
            }
            $n++; $changed++;
        }
        BuildingRoleCard::flush();
        return [
            'status' => $missing ? 'missing' : ($changed ? 'synced' : 'unchanged'), 'cards' => $n,
            'edited' => BuildingRoleCard::where('building_id', $b->id)->whereNotNull('edited_at')->count(), 'missing' => $missing,
        ];
    }

    /** مسار ملف البطاقة: المجلد المُمرَّر ← مجلد المستودع الأعلى ← public/. */
    public function resolvePath(int $no, ?string $from = null): ?string
    {
        $rel = str_replace('/', DIRECTORY_SEPARATOR, ltrim(RoleCards::url($no), '/'));
        $candidates = $from !== null
            ? [rtrim($from, '/\\').DIRECTORY_SEPARATOR.$rel]
            : [dirname(base_path()).DIRECTORY_SEPARATOR.$rel, public_path($rel)];
        foreach ($candidates as $p) if (is_file($p)) return $p;
        return null;
    }

    /** @return array{name:string, description:?string, reports_to:?string, basis:?string, tasks_proactive:?string, tasks_operational:?string, tasks_response:?string} */
    public function parse(string $html, int $no): array
    {
        $start = strpos($html, 'id="doc"');
        $doc = $start === false ? $html : substr($html, $start);
        $name = preg_match('/font-weight:800;font-size:18px;">([^<]+)<\/div>/u', $doc, $m) ? $this->text($m[1]) : '';
        if ($name === '') throw new \RuntimeException("ملف البطاقة $no بلا اسم.");
        $description = preg_match('/font-size:13px;color:#B7B2A6;margin-top:2px;">([^<]*)<\/div>/u', $doc, $m) ? $this->text($m[1]) : '';
        $reports = preg_match('/التبعية:\s*([^<]*)</u', $doc, $m) ? $this->text($m[1]) : '';
        $basis = preg_match('/السند:\s*([^<]*)</u', $doc, $m) ? $this->text($m[1]) : '';
        $tasks = [];
        foreach (['tasks_proactive' => '◆ استباقية', 'tasks_operational' => '● تشغيلية', 'tasks_response' => '▲ استجابة'] as $field => $marker) {
            $p = strpos($doc, $marker);
            $lines = [];
            if ($p !== false && preg_match('/<ul[^>]*>(.*?)<\/ul>/su', substr($doc, $p), $um)) {
                preg_match_all('/<li[^>]*>(.*?)<\/li>/su', $um[1], $lis);
                foreach ($lis[1] as $li) { $t = $this->text($li); if ($t !== '') $lines[] = $t; }
            }
            $tasks[$field] = $lines ? implode("\n", $lines) : null;
        }
        return ['name' => $name, 'description' => $description ?: null, 'reports_to' => $reports ?: null, 'basis' => $basis ?: null] + $tasks;
    }

    private function text(string $html): string
    {
        $t = html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>/iu', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/\s+/u', ' ', $t));
    }
}
