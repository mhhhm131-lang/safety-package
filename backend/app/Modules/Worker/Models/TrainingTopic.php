<?php

namespace App\Modules\Worker\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * موضوع تدريب مرجعي لكفاءة عمال المقاولين (من جدول training_topics في وحدة التدريب بـ OHSMS).
 * وحدة التدريب لموظفي المعهد خارج النطاق (§٢-٤)؛ يُنقل الجدول المرجعي فقط لأن مصفوفة الكفاءات وسجلات تدريب العمال تعتمد عليه.
 */
class TrainingTopic extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['code', 'name', 'name_en', 'category', 'description', 'duration_hours', 'validity_months', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'duration_hours' => 'integer', 'validity_months' => 'integer'];

    public const CATEGORIES = [
        'induction' => 'تعريفي', 'permit_control' => 'التحكم بالتصاريح', 'high_risk' => 'عالي الخطورة', 'emergency' => 'طوارئ',
        'leadership' => 'قيادة', 'ssw' => 'أساليب العمل الآمن', 'environmental' => 'بيئي',
    ];

    public function requirements(): HasMany { return $this->hasMany(CompetencyRequirement::class); }

    public function getCategoryLabel(): string { return self::CATEGORIES[$this->category] ?? $this->category; }
}
