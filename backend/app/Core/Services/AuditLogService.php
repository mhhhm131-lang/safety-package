<?php

namespace App\Core\Services;

use App\Modules\Governance\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

/** سجل التدقيق (منقول من OHSMS بلا tenant). مسجَّل كـ singleton باسم audit.logger. */
class AuditLogService
{
    public function log(
        ?object $request,
        string $action,
        string $modelName,
        ?int $objectId = null,
        ?string $description = null,
        ?int $userId = null
    ): AuditLog {
        $ip = null;
        if ($request && method_exists($request, 'ip')) {
            $ip = $request->ip();
        }
        if ($userId === null) {
            $userId = Auth::id();
        }

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'model_name' => $modelName,
            'object_id' => $objectId,
            'description' => $description,
            'ip_address' => $ip,
            'created_at' => now(),
        ]);
    }
}
