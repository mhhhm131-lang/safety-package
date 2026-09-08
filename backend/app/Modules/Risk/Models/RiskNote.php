<?php

namespace App\Modules\Risk\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskNote extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'risk_id',
        'note',
        'created_by_id',
    ];

    // Relationships

    public function risk(): BelongsTo
    {
        return $this->belongsTo(Risk::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
