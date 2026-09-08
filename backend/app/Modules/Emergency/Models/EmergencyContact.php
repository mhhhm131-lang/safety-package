<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\HasAuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** جهة اتصال للطوارئ (من OHSMS بلا tenant). الخارجية تُبذر من خطط الاستجابة (الدفاع المدني، الهلال الأحمر، الشرطة). */
class EmergencyContact extends Model
{
    use HasAuditLog;

    public $timestamps = false;

    protected $fillable = [
        'building_id', 'contact_type', 'name', 'role', 'organization', 'phone', 'phone_alt', 'email', 'priority',
        'auto_notify', 'is_active', 'notes',
    ];

    protected $casts = ['auto_notify' => 'boolean', 'is_active' => 'boolean', 'created_at' => 'datetime'];

    protected $attributes = ['priority' => 1, 'auto_notify' => false, 'is_active' => true];

    const TYPE_INTERNAL = 'internal';
    const TYPE_EXTERNAL = 'external';

    protected static function booted(): void
    {
        static::creating(function (self $c) {
            if (empty($c->created_at)) $c->created_at = now();
        });
    }

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }

    public function scopeActive($query) { return $query->where('is_active', true); }
    public function scopeInternal($query) { return $query->where('contact_type', self::TYPE_INTERNAL); }
    public function scopeExternal($query) { return $query->where('contact_type', self::TYPE_EXTERNAL); }
    public function scopeAutoNotify($query) { return $query->where('auto_notify', true); }
    public function scopeByPriority($query) { return $query->orderBy('priority'); }

    public function getTypeLabel(): string
    {
        return match ($this->contact_type) { 'internal' => 'داخلي', 'external' => 'خارجي', default => $this->contact_type };
    }
}
