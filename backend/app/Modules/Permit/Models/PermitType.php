<?php

namespace App\Modules\Permit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * كتالوج أنواع التصاريح — بيانات مرجعية يبذرها النظام ولا تُنشأ من الشاشات.
 * أعلام `requires_*` يفحصها PermitService::create قبل الإنشاء (المطلوب حسب النوع).
 * (من OHSMS: `requires_zone` صار `requires_place` لأن مناطق العمل عندنا هي الأماكن التسعة، و`requires_activity` حُذف.)
 */
class PermitType extends Model
{
    public const CATEGORY_QUALIFICATION = 'qualification';
    public const CATEGORY_WORK          = 'work';
    public const CATEGORY_WORKER        = 'worker';
    public const CATEGORY_EQUIPMENT     = 'equipment';
    public const CATEGORY_SPECIAL       = 'special';

    public const CATEGORIES = [
        self::CATEGORY_QUALIFICATION => 'تأهيل',
        self::CATEGORY_WORK          => 'عمل',
        self::CATEGORY_WORKER        => 'عامل',
        self::CATEGORY_EQUIPMENT     => 'معدة',
        self::CATEGORY_SPECIAL       => 'خاص',
    ];

    protected $fillable = [
        'code', 'name', 'name_en', 'category', 'description', 'default_validity_days',
        'requires_project', 'requires_contractor', 'requires_place', 'requires_worker', 'requires_equipment',
        'two_stage_approval', 'is_active',
    ];

    protected $casts = [
        'requires_project'      => 'boolean',
        'requires_contractor'   => 'boolean',
        'requires_place'        => 'boolean',
        'requires_worker'       => 'boolean',
        'requires_equipment'    => 'boolean',
        'two_stage_approval'    => 'boolean',
        'is_active'             => 'boolean',
        'default_validity_days' => 'integer',
    ];

    public function permits(): HasMany
    {
        return $this->hasMany(Permit::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(QualificationChecklistItem::class);
    }

    public function getCategoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
