<?php

namespace App\Modules\Governance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل التدقيق: من فعل ماذا ومتى (منقول من OHSMS بلا tenant). لا يُعدَّل ولا يُحذف. */
class AuditLog extends Model
{
    const UPDATED_AT = null;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
