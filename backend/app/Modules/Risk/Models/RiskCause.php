<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskCause extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'type_category_id',
        'name',
        'name_en',
        'description',
    ];

    // Relationships

    public function typeCategory(): BelongsTo
    {
        return $this->belongsTo(RiskSubCategory::class, 'type_category_id');
    }
}
