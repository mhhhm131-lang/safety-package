<?php

namespace App\Modules\Risk\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rich per-group impact data for a single risk phase — mirrors
 * RiskAffectedGroupDetail but scoped to the phase, so the same affected
 * group can carry different impact/scope/description across proactive,
 * operational, and response phases of the same risk.
 */
class RiskPhaseAffectedGroupDetail extends Model
{
    public $timestamps = false;

    protected $table = 'risk_phase_affected_group_details';

    protected $fillable = [
        'risk_phase_id',
        'affected_group_id',
        'impact',
        'rep_scope',
        'impact_description',
        'details',
        'cascading_effects',
    ];

    public function phase(): BelongsTo
    {
        return $this->belongsTo(RiskPhase::class, 'risk_phase_id');
    }

    public function affectedGroup(): BelongsTo
    {
        return $this->belongsTo(AffectedGroup::class);
    }

    /**
     * قرار ٢٧: أثر الفئة بمقياس الخطورة نفسه ١–٥ (طفيف، بسيط، متوسط، كبير، كارثي).
     * القيم القديمة من OHSMS (low/medium/high/critical) تُقرأ وتُحوَّل: ٢/٣/٤/٥.
     */
    public const LEVELS = ['1' => 'طفيف', '2' => 'بسيط', '3' => 'متوسط', '4' => 'كبير', '5' => 'كارثي'];
    public const LEGACY = ['low' => '2', 'medium' => '3', 'high' => '4', 'critical' => '5'];

    public static function normalizeImpact($value): string
    {
        $v = (string) $value;
        if (isset(self::LEGACY[$v])) return self::LEGACY[$v];
        return isset(self::LEVELS[$v]) ? $v : '3';
    }

    public function getImpactLevelAttribute(): int
    {
        return (int) self::normalizeImpact($this->impact);
    }

    public function getImpactLabelAttribute(): string
    {
        return self::LEVELS[self::normalizeImpact($this->impact)];
    }

    public function getImpactColorAttribute(): string
    {
        return match($this->impact_level) {
            5 => 'danger',
            4 => 'warning',
            3 => 'info',
            default => 'success',
        };
    }
}
