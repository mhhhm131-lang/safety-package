<?php

namespace App\Modules\Worker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeTask extends Model
{
    protected $fillable = [
        'trade_id',
        'seq',
        'description',
    ];

    public function trade(): BelongsTo
    {
        return $this->belongsTo(Trade::class);
    }
}
