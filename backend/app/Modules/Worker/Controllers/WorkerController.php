<?php

namespace App\Modules\Worker\Controllers;

use App\Core\Traits\AppliesOrgUnitScope;
use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use App\Modules\Worker\Models\Trade;
use App\Modules\Worker\Models\Worker;
use App\Modules\Worker\Models\WorkerDocument;
use App\Modules\Worker\Services\WorkerService;
use Illuminate\Http\Request;

/**
 * عمال المقاولين (من OHSMS بلا tenant). إضافة المعهد: place_id بدل area؛ مشرف المقاول يرى عمال طرفه فقط ويقدّمهم،
 * والاعتماد والتقدم في الدورة لمسؤول السلامة والمناوب والمنسق (worker.approve/manage).
 */
class WorkerController extends Controller
{
    use AppliesOrgUnitScope;

    public function __construct(protected WorkerService $service) {}

    public function index(Request $request)
    {
        $query = Worker::with(['externalParty', 'trade', 'place']);
        $this->scopeToUserOrgUnit($query);
        $this->scopeToExternalParty($query);
        if ($request->filled('search')) {
            $query->where(fn ($q) => $q->where('full_name', 'like', '%'.$request->search.'%')->orWhere('national_id', 'like', '%'.$request->search.'%'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('external_party_id')) {
            $query->where('external_party_id', $request->external_party_id);
        }
        if ($request->filled('place')) {
            $query->where('place_id', Place::idByCode($request->place));
        }
        $workers = $query->latest()->paginate(20)->withQueryString();
        $externalParties = $this->contractorPartyId() ? ExternalParty::whereKey($this->contractorPartyId())->get() : ExternalParty::orderBy('name')->get();
        return view('modules.workers.index', compact('workers', 'externalParties'));
    }

    public function create()
    {
        return view('modules.workers.create', $this->formData());
    }

    public function store(Request $request)
    {
        $validated = $this->validatePayload($request);
        $worker = $this->service->create((int) auth()->id(), $validated);
        return redirect()->route('workers.show', $worker)->with('success', 'سُجّل العامل (مسودة) — قدّمه للاعتماد عند اكتمال بياناته.');
    }

    public function show(Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        $worker->load(['externalParty', 'trade', 'place', 'project', 'documents', 'trainingRecords.trainingTopic', 'statusEvents.actor']);
        $allowed = WorkerService::TRANSITIONS[$worker->status] ?? [];
        $user = auth()->user();
        $canApprove = $user->can_('worker.approve') || $user->can_('worker.manage');
        $transitions = array_values(array_filter($allowed, fn ($t) => $canApprove || $t === 'submitted'));
        return view('modules.workers.detail', compact('worker', 'transitions'));
    }

    public function edit(Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        return view('modules.workers.edit', ['worker' => $worker] + $this->formData());
    }

    public function update(Request $request, Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        $validated = $this->validatePayload($request, $worker);
        $worker->update($validated);
        return redirect()->route('workers.show', $worker)->with('success', 'حُدّث العامل.');
    }

    public function transition(Request $request, Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        $validated = $request->validate(['status' => 'required|string', 'note' => 'nullable|string|max:1000']);
        $user = auth()->user();
        if (in_array($validated['status'], WorkerService::APPROVAL_TRANSITIONS, true) && !$user->can_('worker.approve') && !$user->can_('worker.manage')) {
            abort(403, 'اعتماد العامل لمسؤول السلامة والمناوب والمنسق.');
        }
        try {
            $this->service->transition($worker, (int) auth()->id(), $validated['status'], $validated['note'] ?? null);
            return redirect()->back()->with('success', 'صارت حالة العامل: '.$worker->fresh()->getStatusLabel());
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function approvalQueue()
    {
        $queue = $this->service->getApprovalQueue();
        return view('modules.workers.approval_queue', compact('queue'));
    }

    public function documents(Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        $documents = $worker->documents()->latest('created_at')->paginate(20);
        return view('modules.workers.documents', compact('worker', 'documents'));
    }

    public function addDocument(Request $request, Worker $worker)
    {
        $this->assertPartyAccess($worker->external_party_id);
        $validated = $request->validate([
            'name' => 'required|string|max:200', 'document_type' => 'required|string|max:50',
            'file' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120', 'expiry_date' => 'nullable|date', 'notes' => 'nullable|string',
        ]);
        $doc = new WorkerDocument([
            'worker_id' => $worker->id, 'name' => $validated['name'], 'document_type' => $validated['document_type'],
            'expiry_date' => $validated['expiry_date'] ?? null, 'notes' => $validated['notes'] ?? null, 'uploaded_by_id' => (int) auth()->id(), 'created_at' => now(),
        ]);
        if ($request->hasFile('file')) {
            $doc->attachUpload($request->file('file'));
        }
        $doc->save();
        return redirect()->back()->with('success', 'رُفع المستند.');
    }

    public function downloadDocument(Worker $worker, WorkerDocument $document)
    {
        abort_unless($document->worker_id === $worker->id, 404);
        $this->assertPartyAccess($worker->external_party_id);
        abort_unless($document->file_data, 404, 'لا ملف مرفق');
        return response(base64_decode($document->file_data), 200, [
            'Content-Type' => $document->file_mime ?: 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="'.rawurlencode($document->file ?: 'document').'"',
        ]);
    }

    /** سجل تدريب للعامل على موضوع (تُغذّي مصفوفة الكفاءات). */
    public function addTraining(Request $request, Worker $worker)
    {
        $v = $request->validate([
            'training_topic_id' => 'required|exists:training_topics,id', 'status' => 'required|in:pending,completed,expired,waived',
            'completed_at' => 'nullable|date', 'expires_at' => 'nullable|date', 'certificate_number' => 'nullable|string|max:100', 'notes' => 'nullable|string',
        ]);
        \App\Modules\Worker\Models\WorkerTrainingRecord::updateOrCreate(
            ['worker_id' => $worker->id, 'training_topic_id' => $v['training_topic_id']],
            $v + ['recorded_by_id' => auth()->id()]
        );
        return back()->with('success', 'سُجّل التدريب.');
    }

    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->string('q'));
        $query = Worker::orderBy('full_name')->select(['id', 'full_name', 'national_id', 'trade_id', 'external_party_id']);
        $this->scopeToExternalParty($query);
        if ($q !== '') {
            $query->where(fn ($qb) => $qb->where('full_name', 'like', '%'.$q.'%')->orWhere('national_id', 'like', '%'.$q.'%'));
        }
        return response()->json($query->limit(50)->get()->map(fn ($w) => ['id' => $w->id, 'label' => $w->full_name.($w->national_id ? ' ('.$w->national_id.')' : '')]));
    }

    private function formData(): array
    {
        $pid = $this->contractorPartyId();
        return [
            'trades' => Trade::where('is_active', true)->orderBy('code')->get(),
            'externalParties' => $pid ? ExternalParty::whereKey($pid)->get() : ExternalParty::orderBy('name')->get(),
            'projects' => $pid ? Project::whereHas('projectContractors', fn ($q) => $q->where('external_party_id', $pid))->orderBy('name')->get() : Project::orderBy('name')->get(),
            'places' => Place::orderBy('sort')->get(),
            'topics' => \App\Modules\Worker\Models\TrainingTopic::where('is_active', true)->orderBy('code')->get(),
        ];
    }

    private function validatePayload(Request $request, ?Worker $existing = null): array
    {
        $nationalIdRule = 'required|string|max:50|unique:workers,national_id'.($existing ? ','.$existing->id : '');
        $v = $request->validate([
            'full_name' => 'required|string|max:200', 'full_name_en' => 'nullable|string|max:200', 'national_id' => $nationalIdRule,
            'phone' => 'nullable|string|max:20', 'trade_id' => 'required|exists:trades,id',
            'external_party_id' => 'required|exists:external_parties,id', 'project_id' => 'nullable|exists:projects,id',
            'place_id' => 'nullable|exists:places,id', 'medical_expiry' => 'nullable|date', 'iqama_expiry' => 'nullable|date', 'joined_date' => 'nullable|date',
        ]);
        // مشرف المقاول لا يسجّل عاملاً لطرف آخر
        if ($pid = $this->contractorPartyId()) {
            $v['external_party_id'] = $pid;
        }
        return $v;
    }
}
