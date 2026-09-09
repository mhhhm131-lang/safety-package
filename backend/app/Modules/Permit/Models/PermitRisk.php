<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use App\Modules\Risk\Models\Risk;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ربط الخطر بالتصريح — مقترح آلياً (من مخاطر المكان أو من التصريح الأب) أو مضاف يدوياً. */
class PermitRisk extends Model
{
    public $timestamps = false;

    protected $fillable = ['permit_id', 'risk_id', 'auto_suggested', 'added_at', 'added_by_id', 'notes'];

    protected $casts = [
        'auto_suggested' => 'boolean',
        'added_at'       => 'datetime',
    ];

    public function permit(): BelongsTo  { return $this->belongsTo(Permit::class); }
    public function risk(): BelongsTo    { return $this->belongsTo(Risk::class); }
    public function addedBy(): BelongsTo { return $this->belongsTo(User::class, 'added_by_id'); }
}
