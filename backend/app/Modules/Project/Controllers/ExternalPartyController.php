<?php

namespace App\Modules\Project\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\ExternalPartyDocument;
use App\Modules\Project\Models\ExternalPartyEvaluation;
use App\Modules\Risk\Models\Risk;
use Illuminate\Http\Request;

/**
 * الأطراف الخارجية (من OHSMS بلا tenant/أنشطة اقتصادية). إرسال النموذج الرقمي (sendForm) يُنقل في المرحلة ٧ مع وحدة النماذج.
 * المستندات base64 في القاعدة (قرص Render مؤقت). حساب المقاول يرى طرفه فقط.
 */
class ExternalPartyController extends Controller
{
    use AppliesOrgUnitScope;

    public function index(Request $request)
    {
        $query = ExternalParty::with('profile')->withCount('workers')->latest();
        $this->scopeToExternalParty($query, 'id');
        if ($type = $request->query('type')) {
            $query->where('party_type', $type);
        }
        $parties = $query->paginate(20)->withQueryString();
        $activeType = $request->query('type');
        return view('modules.external_parties.index', compact('parties', 'activeType'));
    }

    public function create()
    {
        return view('modules.external_parties.create', ['types' => ExternalParty::TYPES]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $party = ExternalParty::create(array_merge($validated, ['created_by_id' => (int) auth()->id()]));
        return redirect()->route('external-parties.show', $party)->with('success', 'أُنشئ الطرف الخارجي «'.$party->name.'».');
    }

    public function show(ExternalParty $externalParty)
    {
        $this->assertPartyAccess($externalParty->id);
        $externalParty->load(['documents', 'evaluations', 'workers.trade', 'profile', 'projectAssignments.project', 'users']);
        return view('modules.external_parties.detail', ['party' => $externalParty, 'externalParty' => $externalParty]);
    }

    public function edit(ExternalParty $externalParty)
    {
        return view('modules.external_parties.edit', ['externalParty' => $externalParty, 'types' => ExternalParty::TYPES, 'statuses' => ExternalParty::STATUSES]);
    }

    public function update(Request $request, ExternalParty $externalParty)
    {
        $validated = $this->validatePayload($request, true);
        $externalParty->update($validated);
        return redirect()->route('external-parties.show', $externalParty)->with('success', 'حُدّث الطرف الخارجي.');
    }

    public function risks(ExternalParty $externalParty)
    {
        $this->assertPartyAccess($externalParty->id);
        $risks = $externalParty->risks()->with(['category', 'subCategory'])->latest('risks.created_at')->paginate(20);
        $registry = Risk::where('risk_type', 'reference')->orderBy('code')->get(['id', 'code', 'title']);
        return view('modules.external_parties.risks', compact('externalParty', 'risks', 'registry'));
    }

    public function addRisk(Request $request, ExternalParty $externalParty)
    {
        $validated = $request->validate(['risk_id' => 'required|exists:risks,id']);
        $externalParty->risks()->syncWithoutDetaching([$validated['risk_id']]);
        return redirect()->back()->with('success', 'رُبط الخطر بالطرف.');
    }

    public function documents(ExternalParty $externalParty)
    {
        $this->assertPartyAccess($externalParty->id);
        $documents = $externalParty->documents()->latest('created_at')->paginate(20);
        return view('modules.external_parties.documents', compact('externalParty', 'documents'));
    }

    public function addDocument(Request $request, ExternalParty $externalParty)
    {
        $this->assertPartyAccess($externalParty->id);
        $validated = $request->validate([
            'name' => 'required|string|max:200',
            'document_type' => 'required|in:cr,license,insurance,safety_cert,iso_cert,other',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'expiry_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);
        $doc = new ExternalPartyDocument([
            'external_party_id' => $externalParty->id, 'name' => $validated['name'], 'document_type' => $validated['document_type'],
            'expiry_date' => $validated['expiry_date'] ?? null, 'notes' => $validated['notes'] ?? null, 'uploaded_by_id' => (int) auth()->id(),
            'source_channel' => auth()->user()->isContractorRole() ? 'portal_link' : 'manual', 'created_at' => now(),
        ]);
        if ($request->hasFile('file')) {
            $doc->attachUpload($request->file('file'));
        }
        $doc->save();
        return redirect()->back()->with('success', 'رُفع المستند.');
    }

    /** تحقق مسؤول السلامة من المستند (يغذّي قناة PDF في التأهيل). */
    public function verifyDocument(ExternalParty $externalParty, ExternalPartyDocument $document)
    {
        abort_unless($document->external_party_id === $externalParty->id, 404);
        $document->update(['is_verified' => true, 'verified_at' => now(), 'verified_by_id' => auth()->id()]);
        return back()->with('success', 'عُلّم المستند موثّقاً.');
    }

    public function downloadDocument(ExternalParty $externalParty, ExternalPartyDocument $document)
    {
        abort_unless($document->external_party_id === $externalParty->id, 404);
        $this->assertPartyAccess($externalParty->id);
        abort_unless($document->file_data, 404, 'لا ملف مرفق');
        return response(base64_decode($document->file_data), 200, [
            'Content-Type' => $document->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->file ?: 'document').'"',
        ]);
    }

    public function evaluationCreate(ExternalParty $externalParty)
    {
        $projects = $externalParty->projects()->orderBy('name')->get(['projects.id', 'name']);
        return view('modules.external_parties.evaluation_create', compact('externalParty', 'projects'));
    }

    public function evaluationStore(Request $request, ExternalParty $externalParty)
    {
        $validated = $request->validate([
            'period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from',
            'safety_score' => 'required|integer|min:0|max:100', 'compliance_score' => 'required|integer|min:0|max:100', 'quality_score' => 'required|integer|min:0|max:100',
            'project_id' => 'nullable|exists:projects,id', 'notes' => 'nullable|string',
        ]);
        $overall = round(($validated['safety_score'] + $validated['compliance_score'] + $validated['quality_score']) / 3, 1);
        ExternalPartyEvaluation::create([
            'external_party_id' => $externalParty->id, 'project_id' => $validated['project_id'] ?? null,
            'period_from' => $validated['period_from'], 'period_to' => $validated['period_to'],
            'safety_score' => $validated['safety_score'], 'compliance_score' => $validated['compliance_score'], 'quality_score' => $validated['quality_score'],
            'overall_score' => $overall, 'notes' => $validated['notes'] ?? null, 'evaluated_by_id' => (int) auth()->id(), 'created_at' => now(),
        ]);
        return redirect()->route('external-parties.show', $externalParty)->with('success', 'سُجّل التقييم.');
    }

    private function validatePayload(Request $request, bool $withStatus = false): array
    {
        $rules = [
            'name' => 'required|string|max:200', 'name_en' => 'nullable|string|max:200',
            'party_type' => 'required|in:'.implode(',', array_keys(ExternalParty::TYPES)),
            'contact_person' => 'nullable|string|max:200', 'email' => 'nullable|email|max:255', 'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string', 'cr_number' => 'nullable|string|max:50', 'notes' => 'nullable|string',
            'website_url' => 'nullable|url|max:500', 'registration_url' => 'nullable|url|max:500',
        ];
        if ($withStatus) {
            $rules['status'] = 'nullable|in:'.implode(',', array_keys(ExternalParty::STATUSES));
        }
        return $request->validate($rules);
    }
}
