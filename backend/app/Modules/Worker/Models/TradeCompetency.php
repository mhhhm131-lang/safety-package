<?php

namespace App\Modules\Worker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeCompetency extends Model
{
    public const TYPE_EDUCATION  = 'education';
    public const TYPE_BEHAVIORAL = 'behavioral';
    public const TYPE_TECHNICAL  = 'technical';

    protected $fillable = [
        'trade_id',
        'type',
        'seq',
        'name',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function scopeEducation($q)  { return $q->where('type', self::TYPE_EDUCATION); }
    public function scopeBehavioral($q) { return $q->where('type', self::TYPE_BEHAVIORAL); }
    public function scopeTechnical($q)  { return $q->where('type', self::TYPE_TECHNICAL); }
}
