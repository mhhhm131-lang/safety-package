<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\QualificationChecklistItem;
use Illuminate\Support\Collection;

/**
 * بنود تصاريح فئة «التأهيل» تُولَّد من كتالوج ثابت لكل نوع
 * (تأهيل المقاول قبل التعاقد، تأهيل المقاول قبل بدء العمل، تأهيل الوحدة الداخلية).
 * الواجهة نفسها التي في SmartJsaService حتى يستدعي PermitService أحدهما بحسب الفئة.
 */
class QualificationChecklistService
{
    /** غير تكراري: البند المضاف مسبقاً (برمزه) يُتخطّى. @return Collection<int, PermitRequirement> */
    public function generate(Permit $permit): Collection
    {
        $items = $this->catalogItems($permit);
        if ($items->isEmpty()) {
            return collect();
        }

        $existing = PermitRequirement::where('permit_id', $permit->id)
            ->where('category', PermitRequirement::CATEGORY_QUALIFICATION)
            ->pluck('requirement_code')
            ->flip();

        $created = collect();
        foreach ($items as $item) {
            $code = "qual:{$item->id}";
            if ($existing->has($code)) {
                continue;
            }
            $created->push(PermitRequirement::create([
                'permit_id'        => $permit->id,
                'category'         => PermitRequirement::CATEGORY_QUALIFICATION,
                'requirement_code' => $code,
                'description_ar'   => $item->description_ar,
                'phase'            => $item->phase,
                'evidence_type'    => $item->evidence_type,
                'responsible_role' => 'issuer',
                'severity'         => $item->is_mandatory
                    ? PermitRequirement::SEVERITY_MANDATORY
                    : PermitRequirement::SEVERITY_RECOMMENDED,
                'status'           => PermitRequirement::STATUS_REQUIRED,
                'notes'            => $item->standard_reference,
            ]));
        }

        return $created;
    }

    /** معاينة بلا كتابة. @return array{preventive: array, operational: array, response: array, total: int} */
    public function preview(Permit $permit): array
    {
        $grouped = ['preventive' => [], 'operational' => [], 'response' => []];
        $items = $this->catalogItems($permit);

        foreach ($items as $item) {
            if (!isset($grouped[$item->phase])) {
                continue;
            }
            $grouped[$item->phase][] = [
                'id'                 => $item->id,
                'description_ar'     => $item->description_ar,
                'evidence_type'      => $item->evidence_type,
                'is_mandatory'       => $item->is_mandatory,
                'standard_reference' => $item->standard_reference,
            ];
        }

        return $grouped + ['total' => $items->count()];
    }

    private function catalogItems(Permit $permit): Collection
    {
        $typeId = $permit->permit_type_id ?? $permit->type?->id;
        if (!$typeId) {
            return collect();
        }

        return QualificationChecklistItem::where('permit_type_id', $typeId)
            ->orderByRaw("CASE phase WHEN 'preventive' THEN 1 WHEN 'operational' THEN 2 ELSE 3 END")
            ->orderBy('sort_order')
            ->get();
    }
}
