<?php

namespace App\Modules\Risk\Models;

use App\Modules\Risk\Services\RiskCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskSubCategory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'category_id',
        'name',
        'name_en',
        'abbreviation',
        'description',
        'is_universal',
    ];

    protected static function booted(): void
    {
        static::creating(function (RiskSubCategory $sub) {
            if (empty($sub->abbreviation)) {
                $sub->abbreviation = app(RiskCodeService::class)
                    ->generateSubCategoryAbbreviation($sub->name);
            }
        });
    }

    protected $casts = [
        'is_universal' => 'boolean',
    ];

    // Relationships

    public function category(): BelongsTo
    {
        return $this->belongsTo(RiskCategory::class, 'category_id');
    }

    public function causes(): HasMany
    {
        return $this->hasMany(RiskCause::class, 'type_category_id');
    }
}
