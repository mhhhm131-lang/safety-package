<?php

namespace App\Modules\Worker\Models;

use App\Modules\Risk\Models\RiskCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * المهنة (شجرة التصنيف المهني بخمس طبقات). بذرة OHSMS ٩ صفوف؛ مهن المعهد تُضاف من شاشة المهن (§٨ س٩).
 */
class Trade extends Model
{
    public const LEVELS = ['major' => 'قسم رئيسي', 'sub_major' => 'قسم فرعي', 'minor' => 'مجموعة', 'unit' => 'وحدة', 'occupation' => 'مهنة'];

    use HasFactory;

    protected static function newFactory()
    {
        return \Database\Factories\TradeFactory::new();
    }

    protected $fillable = [
        'code',
        'name',
        'name_en',
        'description',
        'qualification_level',
        'level',
        'parent_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    // Relationships

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function riskCategories(): BelongsToMany
    {
        return $this->belongsToMany(RiskCategory::class, 'trade_risk_categories');
    }


    public function workers(): HasMany
    {
        return $this->hasMany(Worker::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(TradeTask::class)->orderBy('seq');
    }

    public function competencies(): HasMany
    {
        return $this->hasMany(TradeCompetency::class)->orderBy('type')->orderBy('seq');
    }

    public function educationFields(): HasMany
    {
        return $this->hasMany(TradeCompetency::class)
            ->where('type', TradeCompetency::TYPE_EDUCATION)
            ->orderBy('seq');
    }

    public function behavioralCompetencies(): HasMany
    {
        return $this->hasMany(TradeCompetency::class)
            ->where('type', TradeCompetency::TYPE_BEHAVIORAL)
            ->orderBy('seq');
    }

    public function technicalCompetencies(): HasMany
    {
        return $this->hasMany(TradeCompetency::class)
            ->where('type', TradeCompetency::TYPE_TECHNICAL)
            ->orderBy('seq');
    }
}
