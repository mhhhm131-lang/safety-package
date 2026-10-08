<?php

namespace App\Modules\Incident\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Services\BuildingContext;
use App\Modules\Incident\Models\Incident;
use App\Modules\Store\Models\InstituteDocument;

/**
 * الطبقة صفر لبلاغ الشاغل: وثيقة `ipa-occ` التي يقرؤها شريط «بلاغات شاغلين لهذا المكان» في نماذج الفحص العشرة
 * تُشتق من جدول البلاغات (كما ipa-depts من الهيكل). النماذج لا تُمس: تقرأ وتكتب localStorage كما اليوم.
 *
 * الاتجاه الأول (قراءة): البلاغات المحوَّلة إلى فني ولم يُفتح عليها بلاغ فحص بعد → صفوف بصيغة النموذج:
 *   {id, at, ts, hz, place, loc, desc, note, status:'assigned'}  — hz صنف المكان (HZ-xx) داخل مبنى الوثيقة (٢٨-٣)
 * الاتجاه الثاني (كتابة): الفني ضغط «اربطه» ← status:'linked' و link:{key,row} → inspection_ref + انتقال «جارٍ».
 */
class OccSync
{
    public const KEY = 'ipa-occ';

    /** البلاغات التي ينتظرها فني في نموذج مكانه — لمبنى بعينه. */
    public function toDocument(?int $buildingId = null): array
    {
        $buildingId ??= BuildingContext::id();
        $rows = Incident::with('place')
            ->whereIn('status', ['forwarded', 'field_received', 'in_progress'])
            ->whereNull('inspection_ref')
            ->whereNotNull('place_id')
            ->whereHas('place', fn ($q) => $q->where('building_id', $buildingId))
            ->orderBy('id')->get()
            ->map(fn (Incident $i) => [
                'id' => $i->code,
                'at' => $this->stamp($i->created_at),
                'ts' => $i->created_at?->getTimestampMs(),
                'hz' => $i->place?->category,
                'place' => $i->place?->name,
                'loc' => $i->location_text ?? '',
                'desc' => ($i->isSecret() ? '' : '').$i->description,
                'note' => $i->latestCenterNote(),
                'status' => 'assigned',
                'type' => $i->incident_type,
                'srv' => $i->id,
            ])->values()->all();
        return ['seq' => Incident::max('id') ?? 0, 'reports' => $rows];
    }

    /** كتابة النموذج: ما صار linked يُسجَّل ربطاً عكسياً. يعيد الوثيقة المعاد توليدها لمبناها. */
    public function fromDocument(array $doc, ?int $userId, IncidentService $service, ?int $buildingId = null): array
    {
        foreach ((array) ($doc['reports'] ?? []) as $r) {
            if (!is_array($r) || ($r['status'] ?? '') !== 'linked' || empty($r['link']) || !is_array($r['link'])) continue;
            $incident = !empty($r['srv']) ? Incident::find((int) $r['srv']) : Incident::where('code', (string) ($r['id'] ?? ''))->first();
            if (!$incident || $incident->inspection_ref) continue;
            $service->linkInspection($incident, $userId, $r['link']);
        }
        return $this->toDocument($buildingId);
    }

    /** تحديث الوثيقة المخزنة ورفع نسختها (يُستدعى بعد أي تغيير يمس ما يراه الفني) — لمبنى، أو لكل المباني. */
    public function refresh(?int $userId = null, ?int $buildingId = null): void
    {
        $ids = $buildingId !== null ? [$buildingId] : EmergencyBuilding::query()->pluck('id')->all();
        foreach ($ids as $b) {
            $doc = InstituteDocument::firstOrNew(['key' => self::KEY, 'building_id' => (int) $b]);
            $doc->data = json_encode($this->toDocument((int) $b), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $doc->version = ($doc->exists ? $doc->version : 0) + 1;
            $doc->updated_by = $userId;
            $doc->save();
        }
    }

    private function stamp($dt): string
    {
        if (!$dt) return '';
        $s = $dt->format('Y/m/d — H:i');
        return strtr($s, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    }
}
