<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Worker\Models\Worker;

/**
 * توليد تصريح دخول فردي آلياً عند فحص الجاهزية.
 *
 * الشرط: اجتاز العامل كل الفحوص، والمانع الوحيد غياب تصريح يغطيه،
 * ولمقاوله **تصريح عمل نشط** (اعتُمد تنظيمياً أصلاً). عندها يُنشأ تصريح لليوم
 * ويُفعَّل مباشرة: لا معالج ولا مراجعة، لأن الاعتماد تمّ على التصريح الأب.
 */
class IndividualPermitFactory
{
    public function __construct(private readonly PermitService $permits) {}

    public function tryCreate(Worker $worker, ?int $placeId = null, ?int $actorId = null): ?Permit
    {
        $parent = $this->parentWorkPermit($worker, $placeId);
        if (!$parent) {
            return null;
        }

        $type = PermitType::where('code', 'worker_site_access')->where('is_active', true)->first();
        if (!$type) {
            return null;
        }

        $permit = $this->permits->create($type, [
            'scope'             => Permit::SCOPE_INDIVIDUAL,
            'parent_permit_id'  => $parent->id,
            'title'             => 'دخول للعمل — '.$worker->full_name,
            'subject_type'      => Permit::SUBJECT_WORKER,
            'subject_id'        => $worker->id,
            'project_id'        => $parent->project_id,
            'external_party_id' => $parent->external_party_id,
            'place_id'          => $placeId ?? $parent->place_id,
            'starts_at'         => now()->startOfDay(),
            'expires_at'        => now()->endOfDay(),
            'requested_by_id'   => $actorId,
        ]);

        $this->permits->transition($permit, Permit::STATUS_SUBMITTED, $actorId, 'أُنشئ آلياً عند فحص الجاهزية');
        $this->permits->transition($permit, Permit::STATUS_UNDER_REVIEW, $actorId);
        $this->permits->transition($permit, Permit::STATUS_APPROVED, $actorId, 'مغطّى بتصريح المقاول '.$parent->code);
        $this->permits->transition($permit, Permit::STATUS_ACTIVE, $actorId);

        return $permit->refresh();
    }

    private function parentWorkPermit(Worker $worker, ?int $placeId): ?Permit
    {
        if (!$worker->external_party_id) {
            return null;
        }
        $now = now();

        return Permit::query()
            ->where('permit_category', PermitType::CATEGORY_WORK)
            ->where('status', Permit::STATUS_ACTIVE)
            ->where('external_party_id', $worker->external_party_id)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>=', $now))
            ->when($placeId, fn ($q) => $q->where(fn ($inner) => $inner
                ->whereNull('place_id')->orWhere('place_id', $placeId)))
            ->latest('id')
            ->first();
    }
}
