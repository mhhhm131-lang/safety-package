<?php

namespace App\Modules\Risk\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Modules\Risk\Models\Risk;
use App\Modules\Risk\Models\RiskCategory;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskSubCategory;
use Illuminate\Http\JsonResponse;

/**
 * واجهات JSON لشجرة التصفح (فئة رئيسية ← فرعية ← مخاطر ← تفاصيل) في الكتاب والسجلين.
 * منقول من RiskBookTreeController في OHSMS بلا المهن والقطاعات والأنشطة الاقتصادية.
 */
class RiskTreeController extends Controller
{
    use AppliesOrgUnitScope;

    // ── كتاب المخاطر (master) ──

    public function bookCategories(): JsonResponse
    {
        return response()->json(RiskCategory::where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']));
    }

    public function bookSubCategories(int $categoryId): JsonResponse
    {
        return response()->json(RiskSubCategory::where('category_id', $categoryId)->orderBy('name')->get(['id', 'name', 'is_universal']));
    }

    public function bookRisksBySubCategory(int $subCatId): JsonResponse
    {
        return response()->json(Risk::where('risk_type', 'master')->where('sub_category_id', $subCatId)
            ->orderByDesc('risk_score')->get(['id', 'code', 'title', 'severity', 'likelihood', 'risk_score']));
    }

    public function bookRiskDetail(int $risk): JsonResponse
    {
        $risk = Risk::with(['category', 'subCategory', 'assignedCoordinator', 'assignedFieldTeam',
            'phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails.affectedGroup'])->findOrFail($risk);
        return response()->json($this->pack($risk));
    }

    // ── السجل العام (reference) وسجل الإدارة (active) ──

    public function registryCategories(string $type): JsonResponse
    {
        $q = Risk::where('risk_type', $type)->whereNotNull('category_id');
        if ($type === 'active') { $this->scopeToUserOrgUnit($q); $this->scopeToPlace($q); }
        $catIds = $q->distinct()->pluck('category_id');
        return response()->json(RiskCategory::whereIn('id', $catIds)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'name_en']));
    }

    public function registrySubCategories(string $type, $categoryId): JsonResponse
    {
        $q = Risk::where('risk_type', $type)->where('category_id', (int) $categoryId)->whereNotNull('sub_category_id');
        if ($type === 'active') { $this->scopeToUserOrgUnit($q); $this->scopeToPlace($q); }
        $subIds = $q->distinct()->pluck('sub_category_id');
        return response()->json(RiskSubCategory::whereIn('id', $subIds)->orderBy('name')->get(['id', 'name', 'is_universal']));
    }

    public function registryRisksBySubCategory(string $type, $subCatId): JsonResponse
    {
        $q = Risk::where('risk_type', $type)->where('sub_category_id', (int) $subCatId);
        if ($type === 'active') { $this->scopeToUserOrgUnit($q); $this->scopeToPlace($q); }
        return response()->json($q->orderByDesc('risk_score')->limit(200)
            ->get(['id', 'code', 'title', 'severity', 'likelihood', 'risk_score', 'status', 'scope_type', 'organization_unit_id', 'place_id']));
    }

    public function registryRiskDetail(string $type, $riskId): JsonResponse
    {
        $q = Risk::with(['category', 'subCategory', 'organizationUnit', 'place', 'assignedCoordinator', 'assignedFieldTeam',
            'phases.causes', 'phases.affectedGroups', 'phases.affectedGroupDetails.affectedGroup'])->where('risk_type', $type);
        if ($type === 'active') $this->scopeToUserOrgUnit($q);
        $risk = $q->findOrFail((int) $riskId);
        return response()->json($this->pack($risk) + [
            'status' => $risk->status, 'status_label' => $risk->status_label, 'scope_type' => $risk->scope_type,
            'organization_unit' => $risk->organizationUnit?->name, 'place' => $risk->place?->name,
        ]);
    }

    /** المعهد: ?place=HZ-xx يحصر سجل الإدارة بمكان واحد (رابط «مخاطر المكان» من اللوحة). */
    private function scopeToPlace($q): void
    {
        if ($code = request()->query('place')) {
            $q->whereHas('place', fn ($p) => $p->where('code', $code));
        }
    }

    private function pack(Risk $risk): array
    {
        $phasesByKey = $risk->phases->keyBy('phase');
        $phases = [];
        foreach (RiskPhase::PHASES as $key) {
            $p = $phasesByKey->get($key);
            if (!$p) continue;
            $detailsByGroup = $p->affectedGroupDetails->keyBy('affected_group_id');
            $phases[] = [
                'phase' => $p->phase, 'phase_label' => $p->phase_label,
                'preventive_action' => $p->preventive_action, 'corrective_action' => $p->corrective_action,
                'residual_assessment' => $p->residual_assessment,
                'responsible_org_unit' => $p->responsible_org_unit_display, 'responsible_user' => $p->responsible_user_display,
                'causes' => $p->causes->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
                'affected_groups' => $p->affectedGroups->map(fn ($g) => [
                    'id' => $g->id, 'name' => $g->name,
                    'impact' => optional($detailsByGroup->get($g->id))->impact, 'rep_scope' => optional($detailsByGroup->get($g->id))->rep_scope,
                ])->values(),
            ];
        }
        return [
            'id' => $risk->id, 'code' => $risk->code, 'title' => $risk->title, 'description' => $risk->description,
            'category' => $risk->category?->name, 'sub_category' => $risk->subCategory?->name,
            'severity' => $risk->severity, 'likelihood' => $risk->likelihood, 'risk_score' => $risk->risk_score,
            'benefit' => $risk->benefit, 'legal_reference' => $risk->legal_reference, 'contact_channel' => $risk->contact_channel,
            'assigned_coordinator' => $risk->assignedCoordinator?->name, 'assigned_field_team' => $risk->assignedFieldTeam?->name,
            'phases' => $phases,
        ];
    }
}
