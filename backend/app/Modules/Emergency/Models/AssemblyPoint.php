<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use App\Modules\Governance\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** نقطة تجمع (من OHSMS بلا tenant). المعهد: place_id = المكان الذي تخدمه النقطة أساساً (اختياري). */
class AssemblyPoint extends Model
{
    use HasAuditLog;

    public $timestamps = false;

    protected $fillable = [
        'building_id', 'place_id', 'code', 'name', 'latitude', 'longitude', 'capacity', 'is_primary', 'is_accessible',
        'directions', 'responsible_id', 'status',
    ];

    protected $casts = [
        'is_primary' => 'boolean', 'is_accessible' => 'boolean', 'latitude' => 'decimal:8', 'longitude' => 'decimal:8',
        'created_at' => 'datetime',
    ];

    protected $attributes = ['status' => 'active', 'is_primary' => false, 'is_accessible' => true];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            if (empty($p->created_at)) $p->created_at = now();
        });
    }

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }
    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function responsible(): BelongsTo { return $this->belongsTo(User::class, 'responsible_id'); }
    public function exits(): HasMany { return $this->hasMany(BuildingExit::class, 'leads_to_point_id'); }
    public function checkIns(): HasMany { return $this->hasMany(EvacuationCheckIn::class, 'assembly_point_id'); }

    public function scopeActive($query) { return $query->where('status', 'active'); }
    public function scopePrimary($query) { return $query->where('is_primary', true); }

    public function getCheckInCount(EmergencyIncident $incident): int
    {
        return $this->checkIns()->where('incident_id', $incident->id)->where('status', 'safe')->count();
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
