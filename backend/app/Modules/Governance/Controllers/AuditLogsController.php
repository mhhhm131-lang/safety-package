<?php

namespace App\Modules\Governance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Governance\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** سجل التدقيق — لمسؤول السلامة. قراءة فقط. */
class AuditLogsController extends Controller
{
    public function index(Request $request): View
    {
        $q = AuditLog::with('user')->latest('id');
        if ($m = $request->query('model')) $q->where('model_name', $m);
        if ($a = $request->query('action')) $q->where('action', $a);
        if ($u = $request->query('user')) $q->where('user_id', $u);
        $logs = $q->paginate(50)->withQueryString();
        $models = AuditLog::query()->distinct()->orderBy('model_name')->pluck('model_name');
        $actions = AuditLog::query()->distinct()->orderBy('action')->pluck('action');
        return view('governance.audit.index', compact('logs', 'models', 'actions'));
    }
}
