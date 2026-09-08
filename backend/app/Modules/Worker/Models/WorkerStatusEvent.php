<?php

namespace App\Modules\Worker\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerStatusEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'worker_id',
        'action',
        'from_status',
        'to_status',
        'note',
        'actor_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // Relationships

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
