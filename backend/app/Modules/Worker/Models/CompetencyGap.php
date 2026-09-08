<?php

namespace App\Modules\Worker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetencyGap extends Model
{

    protected $fillable = ['trade_id', 'trade_competency_id'];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }

    public function competency(): BelongsTo
    {
        return $this->belongsTo(TradeCompetency::class, 'trade_competency_id');
    }
}
