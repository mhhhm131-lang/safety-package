<?php

namespace App\Modules\Worker\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Services\CompetencyService;
use Illuminate\Http\Request;

/** مصفوفة الكفاءات (مهنة × موضوع تدريب) والمهن (من OHSMS بلا tenant) + شاشة إضافة مهنة (§٨ س٩). */
class CompetencyController extends Controller
{
    use AppliesOrgUnitScope;

    public function __construct(protected CompetencyService $service) {}

    public function matrix()
    {
        $data = $this->service->getMatrixData();
        return view('modules.competency.matrix', ['trades' => $data['trades'], 'topics' => $data['topics'], 'matrix' => $data['matrix']]);
    }

    public function trades()
    {
        $trades = Trade::with('parent')->withCount('workers')->orderBy('code')->paginate(50);
        return view('modules.competency.trades', ['trades' => $trades, 'levels' => Trade::LEVELS, 'parents' => Trade::orderBy('code')->get(['id', 'code', 'name', 'level'])]);
    }

    /** إضافة مهنة (المعهد: بذرة OHSMS ٩ صفوف لا تكفي؛ مسؤول السلامة يضيف مهن مقاوليه بلا قائمة مخترعة). */
    public function storeTrade(Request $request)
    {
        $v = $request->validate([
            'code' => 'required|string|max:20|unique:trades,code', 'name' => 'required|string|max:200', 'name_en' => 'nullable|string|max:200',
            'level' => 'required|in:'.implode(',', array_keys(Trade::LEVELS)), 'parent_id' => 'nullable|exists:trades,id',
            'description' => 'nullable|string', 'qualification_level' => 'nullable|string|max:150',
        ]);
        $trade = Trade::create($v + ['is_active' => true]);
        return redirect()->route('competency.trades')->with('success', 'أُضيفت المهنة «'.$trade->name.'».');
    }

    public function toggleTrade(Trade $trade)
    {
        $trade->update(['is_active' => !$trade->is_active]);
        return back()->with('success', $trade->is_active ? 'فُعّلت المهنة' : 'أُوقفت المهنة');
    }

    public function toggleRequirement(Request $request)
    {
        $validated = $request->validate(['trade_id' => 'required|exists:trades,id', 'training_topic_id' => 'required|exists:training_topics,id']);
        return response()->json($this->service->toggleRequirement($validated['trade_id'], $validated['training_topic_id']));
    }

    public function workerCompliance(Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        $compliance = $this->service->getWorkerCompliance($worker);
        return view('modules.competency.worker_compliance', compact('worker', 'compliance'));
    }

    public function contractorCompliance(ExternalParty $externalParty)
    {
        $this->assertPartyAccess($externalParty->id);
        $compliance = $this->service->getContractorCompliance($externalParty);
        return view('modules.competency.contractor_compliance', compact('externalParty', 'compliance'));
    }
}
