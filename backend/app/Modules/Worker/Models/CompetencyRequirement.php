<?php

namespace App\Modules\Worker\Models;


use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyRequirement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'trade_id',
        'training_topic_id',
        'is_mandatory',
        'source',
    ];

    protected $casts = [
        'is_mandatory' => 'boolean',
    ];

    protected $attributes = [
        'is_mandatory' => true,
    ];

    // Relationships

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function trainingTopic(): BelongsTo
    {
        return $this->belongsTo(TrainingTopic::class);
    }
}
