<?php

namespace App\Modules\Permit\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\Place;
use App\Modules\Permit\Models\GateLog;
use App\Modules\Permit\Services\GateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * شاشة جاهزية العامل: يُدخل الاسم أو رقم الهوية فتظهر الفحوص الستة والقرار.
 * لا قارئ بطاقات — بحث نصي يكفي في المعهد.
 */
class GateController extends Controller
{
    public function __construct(private readonly GateService $gate) {}

    public function screen()
    {
        return view('modules.permits.gate', [
            'stats'  => $this->gate->statsToday(),
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }

    public function check(Request $request)
    {
        $data = $request->validate([
            'worker_identifier' => ['required', 'string', 'max:100'],
            'place_id'          => ['nullable', 'integer', 'exists:places,id'],
            'gate_name'         => ['nullable', 'string', 'max:100'],
        ], [], ['worker_identifier' => 'رقم الهوية أو رقم العامل']);

        $result = $this->gate->checkWorker(
            trim($data['worker_identifier']),
            $data['place_id'] ?? null,
            $data['gate_name'] ?? 'main',
            Auth::id(),
        );

        $worker = $result['worker'];

        return response()->json([
            'result'         => $result['result'],
            'allowed'        => $result['result'] === 'allowed',
            'denial_reasons' => array_map(fn ($r) => ['code' => $r, 'label' => GateLog::denialLabel($r)], $result['denial_reasons']),
            'checks'         => $result['checks'],
            'permit_code'    => $result['permit_code'],
            'auto_permit'    => $result['auto_permit'],
            'worker'         => $worker ? [
                'id'             => $worker->id,
                'name'           => $worker->full_name,
                'national_id'    => $worker->national_id,
                'status'         => $worker->getStatusLabel(),
                'trade'          => $worker->trade?->name ?? '—',
                'external_party' => $worker->externalParty?->name ?? '—',
            ] : null,
            'stats' => $this->gate->statsToday(),
        ]);
    }

    public function logs(Request $request)
    {
        $query = GateLog::with(['worker', 'place', 'permit'])->latest('created_at');

        if ($request->filled('result')) {
            $query->where('result', $request->query('result'));
        }
        if ($request->filled('place_id')) {
            $query->where('place_id', (int) $request->query('place_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        return view('modules.permits.gate_logs', [
            'logs'   => $query->paginate(50)->withQueryString(),
            'places' => Place::orderBy('sort')->get(['id', 'code', 'name']),
        ]);
    }
}
