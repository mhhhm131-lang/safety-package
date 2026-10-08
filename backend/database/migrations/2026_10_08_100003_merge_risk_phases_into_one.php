<?php

use App\Modules\Risk\Models\AffectedGroup;
use App\Modules\Risk\Models\RiskCause;
use App\Modules\Risk\Models\RiskPhase;
use App\Modules\Risk\Models\RiskPhaseAffectedGroupDetail;
use App\Modules\Risk\Support\PhaseMerger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * خطة المعالج — الخطوة ٦ (بكلمته ٢٠٢٦-١٠-٠٧): الأطوار الثلاثة تُلغى ويُدمج محتواها في صف واحد لكل خطر (`single`) بلا عناوين.
 * لكل خطر له أكثر من صف أو صف قديم: يُدمج (PhaseMerger) في الصف الأول، يُسمّى `single`، وتُحذف البقية (وتتبعها الأسباب والمتأثرون).
 * لا يُحذف نص: كل ما في الأطوار يبقى في الصف الواحد. يعمل مرة واحدة ولا يعيد ما دُمج (idempotent).
 */
return new class extends Migration
{
    public function up(): void
    {
        $riskIds = DB::table('risk_phases')->where('phase', '!=', RiskPhase::PHASE_SINGLE)->distinct()->pluck('risk_id');
        foreach ($riskIds->chunk(100) as $chunk) {
            DB::transaction(function () use ($chunk) {
                foreach ($chunk as $riskId) {
                    $rows = RiskPhase::where('risk_id', $riskId)->with(['causes', 'affectedGroupDetails.affectedGroup', 'affectedGroups'])->get()
                        ->sortBy(fn (RiskPhase $p) => array_search($p->phase, RiskPhase::LEGACY_PHASES, true) === false ? 9 : array_search($p->phase, RiskPhase::LEGACY_PHASES, true))
                        ->values();
                    if ($rows->isEmpty()) continue;
                    $merged = PhaseMerger::merge($rows->map(fn (RiskPhase $p) => [
                        'preventive_action' => $p->preventive_action, 'corrective_action' => $p->corrective_action, 'residual_assessment' => $p->residual_assessment,
                        'responsible_org_unit_id' => $p->responsible_org_unit_id, 'responsible_org_unit_text' => $p->responsible_org_unit_text,
                        'responsible_user_id' => $p->responsible_user_id, 'responsible_user_text' => $p->responsible_user_text, 'notes' => $p->notes,
                        'causes' => $p->causes->pluck('name')->all(),
                        'affected' => $p->affectedGroups->map(function ($g) use ($p) {
                            $d = $p->affectedGroupDetails->firstWhere('affected_group_id', $g->id);
                            return ['name' => $g->name, 'impact' => $d?->impact, 'rep_scope' => $d?->rep_scope, 'detail' => $d?->impact_description];
                        })->all(),
                    ])->all());
                    $keep = $rows->first();
                    $others = $rows->slice(1);
                    foreach ($others as $o) $o->delete(); // تتبعها جداول الأسباب والمتأثرين (cascade)
                    $keep->update([
                        'phase' => RiskPhase::PHASE_SINGLE,
                        'preventive_action' => $merged['preventive_action'], 'corrective_action' => $merged['corrective_action'],
                        'residual_assessment' => $merged['residual_assessment'],
                        'responsible_org_unit_id' => $merged['responsible_org_unit_id'], 'responsible_org_unit_text' => $merged['responsible_org_unit_text'],
                        'responsible_user_id' => $merged['responsible_user_id'], 'responsible_user_text' => $merged['responsible_user_text'],
                        'notes' => $merged['notes'],
                    ]);
                    $keep->causes()->sync(collect($merged['causes'])->map(fn ($c) => RiskCause::firstOrCreate(['name' => mb_substr($c, 0, 200)])->id)->all());
                    $ids = [];
                    foreach ($merged['affected'] as $g) {
                        $group = AffectedGroup::where('name', $g['name'])->first() ?? AffectedGroup::create(['name' => $g['name']]);
                        $ids[] = $group->id;
                        RiskPhaseAffectedGroupDetail::updateOrCreate(['risk_phase_id' => $keep->id, 'affected_group_id' => $group->id],
                            ['impact' => $g['impact'] ?? '3', 'rep_scope' => $g['rep_scope'], 'impact_description' => $g['detail']]);
                    }
                    $keep->affectedGroups()->sync($ids);
                    RiskPhaseAffectedGroupDetail::where('risk_phase_id', $keep->id)->whereNotIn('affected_group_id', $ids ?: [0])->delete();
                }
            });
        }
    }

    public function down(): void
    {
        // لا رجوع: الدمج لا يُفكّ. ملفات الكتاب ما زالت بأطوارها الثلاثة إن لزم.
    }
};
