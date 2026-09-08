<?php

namespace App\Modules\Worker\Models;

use App\Models\User;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkerTrainingRecord extends Model
{

    protected $fillable = [
        'worker_id',
        'training_topic_id',
        'status',
        'completed_at',
        'expires_at',
        'certificate_number',
        'notes',
        'recorded_by_id',
    ];

    protected $casts = [
        'completed_at' => 'date',
        'expires_at' => 'date',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    // Relationships

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function trainingTopic(): BelongsTo
    {
        return $this->belongsTo(TrainingTopic::class);
    }


    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
