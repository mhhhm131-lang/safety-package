<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;

class AffectedGroup extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'name_en',
        'affected_count',
        'vulnerability_level',
        'special_considerations',
    ];

    /**
     * دوائر العواقب والأضرار الخمس (قرار ٢٥): تُعرض الفئات التسع مجمَّعة تحتها في لوحات الإدخال
     * وتفتح كل دائرة بالنقر — الناس، الممتلكات، القدرات، السمعة، الخسائر المالية.
     */
    public const CIRCLES = [
        'الناس'           => ['الموظفون', 'المتدربون والزوار', 'المقاولون وعمالهم', 'الفريق الأولي للاستجابة', 'ذوو الإعاقة والحالات الخاصة'],
        'الممتلكات'       => ['الممتلكات والأنظمة'],
        'القدرات'         => ['استمرارية الأعمال'],
        'السمعة'          => ['السمعة'],
        'الخسائر المالية' => ['الخسائر المالية والقانونية'],
    ];

    /** الفئات التي لا تنتمي لدائرة معروفة (مضافة يدوياً) تُعرض تحت «أخرى». */
    public static function circleOf(string $name): string
    {
        foreach (self::CIRCLES as $circle => $names) {
            if (in_array($name, $names, true)) return $circle;
        }
        return 'أخرى';
    }

    protected $casts = [
        'affected_count' => 'integer',
    ];

    protected $attributes = [
        'affected_count' => 0,
        'vulnerability_level' => 'medium',
    ];
}
