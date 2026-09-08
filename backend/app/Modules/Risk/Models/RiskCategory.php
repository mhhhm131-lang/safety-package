<?php

namespace App\Modules\Risk\Models;

use App\Modules\Risk\Services\RiskCodeService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskCategory extends Model
{

    const UPDATED_AT = null;

    protected $fillable = [
        'name',
        'name_en',
        'abbreviation',
        'description',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::creating(function (RiskCategory $category) {
            if (empty($category->abbreviation)) {
                $category->abbreviation = app(RiskCodeService::class)
                    ->generateCategoryAbbreviation($category->name);
            }
        });
    }

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    // Relationships

    public function subCategories(): HasMany
    {
        return $this->hasMany(RiskSubCategory::class, 'category_id');
    }
}
