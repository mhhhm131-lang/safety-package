<?php

namespace App\Modules\Permit\Services;

use App\Core\Services\NotificationService;
use App\Models\User;
use App\Modules\Permit\Models\Permit;

/**
 * إشعارات التصاريح بقناتي §٦ فقط (داخل النظام + بريد؛ لا واتساب ولا رسائل نصية).
 *
 * من يُنبَّه:
 *   - عند التقديم: مسؤول السلامة والمناوب والمنسق (طابور المراجعة).
 *   - عند اعتماد السلامة: مسؤول السلامة والمناوب (الاعتماد النهائي بيدهما).
 *   - عند كل قرار يمسّ مقدّم الطلب: مقدّم الطلب، وحساب المقاول المرتبط بالطرف إن وُجد.
 */
class PermitNotifier
{
    public function __construct(private readonly NotificationService $inbox) {}

    public function statusChanged(Permit $permit, string $from, string $to, ?int $actorId, ?string $notes): void
    {
        $url = "/app/permits/{$permit->id}";

        if ($to === Permit::STATUS_SUBMITTED) {
            $this->inbox->notifyRoles(
                ['system_admin', 'system_staff', 'safety_coordinator'],
                'permit.submitted',
                "تصريح للمراجعة: {$permit->code}",
                "{$permit->title} — يحتاج مراجعة واعتماداً.",
                $url,
            );
        }

        if ($to === Permit::STATUS_SAFETY_APPROVED) {
            $this->inbox->notifyRoles(
                ['system_admin', 'system_staff'],
                'permit.safety_approved',
                "اعتماد السلامة تمّ: {$permit->code}",
                "{$permit->title} — بانتظار الاعتماد النهائي.",
                $url,
            );
        }

        $decisions = [
            Permit::STATUS_APPROVED  => 'اعتُمد التصريح',
            Permit::STATUS_REJECTED  => 'رُفض التصريح',
            Permit::STATUS_ACTIVE    => 'فُعّل التصريح — يمكن بدء العمل',
            Permit::STATUS_COMPLETED => 'أُغلق التصريح',
            Permit::STATUS_SUSPENDED => 'أُوقف التصريح مؤقتاً',
            Permit::STATUS_CANCELLED => 'أُلغي التصريح',
            Permit::STATUS_EXPIRED   => 'انتهت صلاحية التصريح',
        ];

        if (!isset($decisions[$to])) {
            return;
        }

        $message = $decisions[$to].($notes ? ": {$notes}" : '');
        foreach ($this->interestedUserIds($permit, $actorId) as $userId) {
            $this->inbox->create($userId, 'permit.decision', "تحديث التصريح {$permit->code}", $message, $url);
        }
    }

    public function deviationRecorded(Permit $permit, string $severity, ?int $actorId): void
    {
        if ($severity !== 'high') {
            return;
        }
        $this->inbox->notifyRoles(
            ['system_admin', 'system_staff', 'safety_coordinator'],
            'permit.deviation',
            "انحراف عالي الخطورة: {$permit->code}",
            "{$permit->title} — سُجّل انحراف عالي أثناء العمل.",
            "/app/permits/{$permit->id}",
        );
    }

    /**
     * مقدّم الطلب + حسابات الطرف الخارجي المرتبطة، بلا صاحب القرار نفسه.
     *
     * @return array<int, int>
     */
    private function interestedUserIds(Permit $permit, ?int $actorId): array
    {
        $ids = [];
        if ($permit->requested_by_id) {
            $ids[] = $permit->requested_by_id;
        }
        if ($permit->external_party_id) {
            $ids = array_merge($ids, User::where('external_party_id', $permit->external_party_id)->pluck('id')->all());
        }

        return array_values(array_diff(array_unique($ids), array_filter([$actorId])));
    }
}
