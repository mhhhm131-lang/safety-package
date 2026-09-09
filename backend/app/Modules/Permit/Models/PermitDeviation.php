<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * انحراف ميداني: ما وُجد في الموقع ويختلف عمّا خُطّط في التصريح.
 * لا يُغلق التصريح وفيه انحراف مفتوح (حارس في PermitService)، وتغذّي الانحرافات مراجعة بنود التحكم.
 */
class PermitDeviation extends Model
{
    public $timestamps = false;

    public const SEVERITY_LOW    = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH   = 'high';

    public const STATUS_OPEN     = 'open';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_ACCEPTED = 'accepted';

    public const SEVERITY_LABELS = ['low' => 'منخفضة', 'medium' => 'متوسطة', 'high' => 'عالية'];
    public const STATUS_LABELS   = ['open' => 'مفتوح', 'resolved' => 'محلول', 'accepted' => 'مقبول بمبرر'];

    protected $fillable = [
        'permit_id', 'permit_requirement_id', 'type', 'description', 'severity',
        'corrective_action_taken', 'status', 'recorded_by_id', 'recorded_at',
        'resolved_by_id', 'resolved_at', 'signature_ip',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    protected $attributes = [
        'severity' => self::SEVERITY_MEDIUM,
        'status'   => self::STATUS_OPEN,
        'type'     => 'other',
    ];

    public function permit(): BelongsTo      { return $this->belongsTo(Permit::class); }
    public function requirement(): BelongsTo { return $this->belongsTo(PermitRequirement::class, 'permit_requirement_id'); }
    public function recordedBy(): BelongsTo  { return $this->belongsTo(User::class, 'recorded_by_id'); }
    public function resolvedBy(): BelongsTo  { return $this->belongsTo(User::class, 'resolved_by_id'); }

    public function isOpen(): bool { return $this->status === self::STATUS_OPEN; }

    public function getSeverityLabel(): string { return self::SEVERITY_LABELS[$this->severity] ?? $this->severity; }
    public function getStatusLabel(): string   { return self::STATUS_LABELS[$this->status] ?? $this->status; }
}
