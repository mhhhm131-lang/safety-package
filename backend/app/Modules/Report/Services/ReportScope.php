<?php

namespace App\Modules\Report\Services;

use App\Modules\Governance\Models\OrganizationUnit;
use App\Modules\Governance\Models\UserProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * نطاق التقرير: المدة والمكان والوحدات المسموح للمستخدم برؤيتها.
 *
 * **تصحيح لسلوك OHSMS:** هناك كان النطاق `tenant_id` وحده، والذاكرة المؤقتة مفتاحها المستأجر
 * لا المستخدم — فلو رآها مديران من إدارتين لرأى الثاني أرقام الأول. عندنا لا مستأجرين، والرؤية
 * بالوحدة التنظيمية كبقية الوحدات (`AppliesOrgUnitScope`): أدوار الإشراف العام ترى الكل،
 * ومدير الإدارة يرى وحدته وما تحتها.
 */
class ReportScope
{
    /** الأدوار التي ترى كل الوحدات (مطابقة لـAppliesOrgUnitScope). */
    private const GLOBAL_ROLES = ['system_admin', 'system_staff', 'top_management', 'safety_committee', 'safety_coordinator'];

    /** @param array<int>|null $unitIds null = بلا تقييد بالوحدة */
    private function __construct(
        public readonly Carbon $from,
        public readonly Carbon $to,
        public readonly ?int $placeId,
        public readonly ?array $unitIds,
        public readonly bool $global,
    ) {}

    /**
     * المدة الافتراضية **الشهر الجاري** — من تقويم الشهر لا رقماً مخترعاً،
     * والمستخدم يغيّرها من شريط المرشّحات.
     */
    public static function fromRequest(?string $from, ?string $to, ?int $placeId, ?int $userId): self
    {
        $start = $from ? Carbon::parse($from)->startOfDay() : Carbon::now()->startOfMonth();
        $end   = $to ? Carbon::parse($to)->endOfDay() : Carbon::now()->endOfDay();
        if ($end->lt($start)) {
            [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
        }

        $profile = $userId ? UserProfile::where('user_id', $userId)->first() : null;
        $global  = $profile && in_array($profile->role, self::GLOBAL_ROLES, true);

        $unitIds = null;
        if (!$global) {
            $unitIds = $profile?->organization_unit_id
                ? OrganizationUnit::descendantIdsOf($profile->organization_unit_id)
                : [];
        }

        return new self($start, $end, $placeId ?: null, $unitIds, (bool) $global);
    }

    /** نطاق مفتوح بلا مستخدم — للأوامر والاختبارات. */
    public static function all(?Carbon $from = null, ?Carbon $to = null): self
    {
        return new self(
            $from ?? Carbon::now()->startOfMonth(),
            $to ?? Carbon::now()->endOfDay(),
            null,
            null,
            true,
        );
    }

    /** يقيّد الاستعلام بالمدة والمكان والوحدة. أي عمود غير موجود يُمرَّر null فيُتخطّى. */
    public function apply(
        Builder $query,
        string $dateColumn = 'created_at',
        ?string $placeColumn = 'place_id',
        ?string $unitColumn = 'organization_unit_id',
    ): Builder {
        $query->whereBetween($dateColumn, [$this->from, $this->to]);

        if ($this->placeId && $placeColumn) {
            $query->where($placeColumn, $this->placeId);
        }

        if ($this->unitIds !== null && $unitColumn) {
            $ids = $this->unitIds;
            // السجلات بلا وحدة تبقى مرئية (كما في AppliesOrgUnitScope).
            $query->where(fn (Builder $q) => $q->whereNull($unitColumn)->orWhereIn($unitColumn, $ids));
        }

        return $query;
    }

    /** المدة بالأيام — تُعرض في رأس التقرير حتى يعرف القارئ ما يقارن. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    public function label(): string
    {
        return $this->from->format('Y-m-d').' — '.$this->to->format('Y-m-d');
    }
}
