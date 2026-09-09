<?php

namespace App\Modules\Permit\Services;

use App\Modules\Permit\Models\Permit;
use App\Modules\Permit\Models\PermitRequirement;
use App\Modules\Permit\Models\PermitType;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskControl;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الجسر بين بنود التحكم (`risk_controls`) وبنود التصريح (`permit_requirements`) — «تحليل سلامة العمل».
 *
 * مساران:
 *   (أ) **بنوع التصريح**: بنود التحكم المبذورة بـ `permit_type_code` المطابق (أعمال ساخنة، أماكن محصورة…).
 *       لا يُصفَّى بالمهنة: بنود النوع إلزامية لكل من يعمل عليه.
 *   (ب) **بفئة الخطر** (احتياطي للأنواع العامة بلا بنود خاصة): فئات مخاطر التصريح المرتبطة.
 *
 * يكتب البنود فقط، ولا ينشئ تصاريح ولا ينقل حالات. يُستدعى بعد ربط المخاطر.
 * غير تكراري: بند التحكم المضاف مسبقاً يُتخطّى، فيمكن إعادة الاستدعاء عند التعديل.
 */
class SmartJsaService
{
    /**
     * توليد البنود من بنود التحكم.
     *
     * @param array<int> $tradeIds سياق المهن (يضيّق المسار الاحتياطي)
     * @return array{created: int, skipped: int}
     */
    public function generate(Permit $permit, array $tradeIds = []): array
    {
        // التأهيل له كتالوجه، و«عامل» أهليته فحص شخصي عند البوابة.
        if (in_array($permit->permit_category, [PermitType::CATEGORY_QUALIFICATION, PermitType::CATEGORY_WORKER], true)) {
            return ['created' => 0, 'skipped' => 0];
        }

        $controls = $this->resolveControls($permit, $tradeIds);
        if ($controls->isEmpty()) {
            return ['created' => 0, 'skipped' => 0];
        }

        $existing = PermitRequirement::where('permit_id', $permit->id)
            ->whereNotNull('risk_control_id')
            ->pluck('risk_control_id')
            ->flip();

        $created = 0;
        $skipped = 0;

        foreach ($controls as $control) {
            if ($existing->has($control->id)) {
                $skipped++;
                continue;
            }
            PermitRequirement::create([
                'permit_id'        => $permit->id,
                'category'         => PermitRequirement::CATEGORY_RISK_CONTROL,
                'requirement_code' => "rc:{$control->id}",
                'reference_id'     => $control->id,
                'risk_control_id'  => $control->id,
                'description_ar'   => $control->description_ar,
                'phase'            => $control->phase,
                'evidence_type'    => $control->evidence_type,
                'responsible_role' => $control->responsible_role,
                'frequency'        => $control->frequency,
                'severity'         => $control->is_mandatory
                    ? PermitRequirement::SEVERITY_MANDATORY
                    : PermitRequirement::SEVERITY_RECOMMENDED,
                'status'           => PermitRequirement::STATUS_REQUIRED,
            ]);
            $created++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * معاينة ما سيُولَّد بلا كتابة — الخطوة الثانية في معالج الإنشاء.
     *
     * @return array{preventive: array, operational: array, response: array, total: int}
     */
    public function preview(Permit $permit, array $tradeIds = []): array
    {
        if (in_array($permit->permit_category, [PermitType::CATEGORY_QUALIFICATION, PermitType::CATEGORY_WORKER], true)) {
            return ['preventive' => [], 'operational' => [], 'response' => [], 'total' => 0];
        }

        $controls = $this->resolveControls($permit, $tradeIds, orderByPhase: true);

        $grouped = ['preventive' => [], 'operational' => [], 'response' => []];
        foreach ($controls as $c) {
            if (!isset($grouped[$c->phase])) {
                continue;
            }
            $grouped[$c->phase][] = [
                'id'                    => $c->id,
                'description_ar'        => $c->description_ar,
                'evidence_type'         => $c->evidence_type,
                'measurement_unit'      => $c->measurement_unit,
                'measurement_threshold' => $c->measurement_threshold,
                'responsible_role'      => $c->responsible_role,
                'frequency'             => $c->frequency,
                'is_mandatory'          => (bool) $c->is_mandatory,
                'standard_reference'    => $c->standard_reference,
            ];
        }

        return $grouped + ['total' => $controls->count()];
    }

    /**
     * أنواع التصاريح التي تستلزمها مهن التصريح (لعرضها كتصاريح مسبقة مقترحة).
     *
     * @param array<int> $tradeIds
     * @return Collection<int, array{permit_type_id: int, code: string, name: string, is_mandatory: bool}>
     */
    public function requiredPermitTypesForTrades(array $tradeIds, int $riskScore = 0): Collection
    {
        if ($tradeIds === []) {
            return collect();
        }

        $conditions = ['always'];
        if ($riskScore >= 3) {
            $conditions[] = 'severity_ge_3';
        }
        if ($riskScore >= 4) {
            $conditions[] = 'severity_ge_4';
        }

        return DB::table('permit_type_trades as ptt')
            ->join('permit_types as pt', 'pt.id', '=', 'ptt.permit_type_id')
            ->whereIn('ptt.trade_id', $tradeIds)
            ->whereIn('ptt.triggering_condition', $conditions)
            ->where('pt.is_active', true)
            ->distinct()
            ->get(['pt.id as permit_type_id', 'pt.code', 'pt.name', 'ptt.is_mandatory'])
            ->map(fn ($r) => [
                'permit_type_id' => (int) $r->permit_type_id,
                'code'           => $r->code,
                'name'           => $r->name,
                'is_mandatory'   => (bool) $r->is_mandatory,
            ]);
    }

    /**
     * بنود التصريح مجمَّعة بالطور لشاشة العرض.
     *
     * @return array{preventive: EloquentCollection, operational: EloquentCollection, response: EloquentCollection, other: EloquentCollection}
     */
    public function groupedRequirements(Permit $permit): array
    {
        $all = PermitRequirement::where('permit_id', $permit->id)
            ->with(['riskControl', 'verifiedBy', 'completedBy'])
            ->orderByRaw("CASE phase WHEN 'preventive' THEN 1 WHEN 'operational' THEN 2 WHEN 'response' THEN 3 ELSE 4 END")
            ->orderBy('id')
            ->get();

        return [
            'preventive'  => $all->where('phase', 'preventive')->values(),
            'operational' => $all->where('phase', 'operational')->values(),
            'response'    => $all->where('phase', 'response')->values(),
            'other'       => $all->whereNull('phase')->values(),
        ];
    }

    /** @return array{mandatory_total: int, mandatory_done: int, pct: int} */
    public function completionStats(Permit $permit): array
    {
        $reqs = PermitRequirement::where('permit_id', $permit->id)
            ->where('severity', PermitRequirement::SEVERITY_MANDATORY)
            ->get(['status']);

        $total = $reqs->count();
        $done  = $reqs->whereIn('status', [PermitRequirement::STATUS_COMPLETED, PermitRequirement::STATUS_WAIVED])->count();

        return [
            'mandatory_total' => $total,
            'mandatory_done'  => $done,
            'pct'             => $total > 0 ? (int) round($done / $total * 100) : 0,
        ];
    }

    // ── داخلي ──

    /** المسار (أ) ثم (ب) عند الخلو. */
    private function resolveControls(Permit $permit, array $tradeIds, bool $orderByPhase = false): EloquentCollection
    {
        $typeCode = $permit->type?->code ?? $permit->type()->value('code');

        if ($typeCode) {
            $direct = $this->query(orderByPhase: $orderByPhase)
                ->where('permit_type_code', $typeCode)
                ->get();
            if ($direct->isNotEmpty()) {
                return $direct;
            }
        }

        $categoryIds = $this->riskCategoryIds($permit);
        if ($categoryIds === []) {
            return new EloquentCollection();
        }

        return $this->query(orderByPhase: $orderByPhase)
            ->whereIn('risk_category_id', $categoryIds)
            ->when($tradeIds !== [], fn ($q) => $q->where(fn ($inner) => $inner
                ->whereNotExists(fn ($sub) => $sub->from('trade_risk_categories')
                    ->whereColumn('trade_risk_categories.risk_category_id', 'risk_controls.risk_category_id'))
                ->orWhereExists(fn ($sub) => $sub->from('trade_risk_categories')
                    ->whereColumn('trade_risk_categories.risk_category_id', 'risk_controls.risk_category_id')
                    ->whereIn('trade_risk_categories.trade_id', $tradeIds))))
            ->get();
    }

    private function query(bool $orderByPhase): \Illuminate\Database\Eloquent\Builder
    {
        $q = RiskControl::query();

        return $orderByPhase
            ? $q->orderByRaw("CASE phase WHEN 'preventive' THEN 1 WHEN 'operational' THEN 2 ELSE 3 END")->orderBy('sort_order')
            : $q->orderBy('sort_order');
    }

    /** @return array<int, int> */
    private function riskCategoryIds(Permit $permit): array
    {
        $riskIds = DB::table('permit_risks')->where('permit_id', $permit->id)->pluck('risk_id')->all();
        if ($riskIds === []) {
            return [];
        }

        return Risk::whereIn('id', $riskIds)
            ->whereNotNull('category_id')
            ->distinct()
            ->pluck('category_id')
            ->all();
    }
}
