<?php

namespace App\Modules\Emergency\Models;

use App\Core\Traits\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EmergencyVisitor extends Model
{

    protected $fillable = [
        'building_id',
        'name',
        'phone',
        'email',
        'company',
        'id_number',
        'id_type',
        'place_id',
        'photo_mime',
        'photo_data',
        'host_user_id',
        'purpose',
        'badge_number',
        'vehicle_plate',
        'checked_in_at',
        'expected_checkout_at',
        'checked_out_at',
        'qr_token',
        'evacuation_status',
        'evacuation_checked_at',
        'evacuation_location',
        'evacuation_assembly_point_id',
        'needs_assistance',
        'assistance_type',
        'special_notes',
        'status',
    ];

    protected $hidden = ['photo_data'];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'expected_checkout_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'evacuation_checked_at' => 'datetime',
        'needs_assistance' => 'boolean',
    ];

    protected $attributes = [
        'evacuation_status' => 'unknown',
        'needs_assistance' => false,
        'status' => 'checked_in',
    ];

    // Status constants
    const STATUS_CHECKED_IN = 'checked_in';
    const STATUS_CHECKED_OUT = 'checked_out';
    const STATUS_DENIED = 'denied';
    const STATUS_BLACKLISTED = 'blacklisted';

    // Evacuation status constants
    const EVAC_UNKNOWN = 'unknown';
    const EVAC_SAFE = 'safe';
    const EVAC_EVACUATING = 'evacuating';
    const EVAC_NEED_HELP = 'need_help';
    const EVAC_MISSING = 'missing';

    // Relationships
    public function place(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Governance\Models\Place::class);
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(EmergencyBuilding::class, 'building_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function assemblyPoint(): BelongsTo
    {
        return $this->belongsTo(AssemblyPoint::class, 'evacuation_assembly_point_id');
    }

    // Scopes
    public function scopeCurrentlyIn($query)
    {
        return $query->where('status', self::STATUS_CHECKED_IN)
            ->whereNull('checked_out_at');
    }

    public function scopeInBuilding($query, int $buildingId)
    {
        return $query->where('building_id', $buildingId)->currentlyIn();
    }

    public function scopeNeedsEvacuationHelp($query)
    {
        return $query->currentlyIn()
            ->where(function ($q) {
                $q->where('needs_assistance', true)
                  ->orWhere('evacuation_status', self::EVAC_NEED_HELP);
            });
    }

    public function scopeMissing($query)
    {
        return $query->currentlyIn()
            ->whereIn('evacuation_status', [self::EVAC_UNKNOWN, self::EVAC_MISSING]);
    }

    public function scopeSafe($query)
    {
        return $query->where('evacuation_status', self::EVAC_SAFE);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('checked_in_at', today());
    }

    // Helpers
    public function isCurrentlyIn(): bool
    {
        return $this->status === self::STATUS_CHECKED_IN && $this->checked_out_at === null;
    }

    public function checkOut(): void
    {
        $this->update([
            'checked_out_at' => now(),
            'status' => self::STATUS_CHECKED_OUT,
        ]);
    }

    public function markSafe(?AssemblyPoint $assemblyPoint = null): void
    {
        $this->update([
            'evacuation_status' => self::EVAC_SAFE,
            'evacuation_checked_at' => now(),
            'evacuation_assembly_point_id' => $assemblyPoint?->id,
        ]);
    }

    public function markNeedHelp(string $location = null): void
    {
        $this->update([
            'evacuation_status' => self::EVAC_NEED_HELP,
            'evacuation_checked_at' => now(),
            'evacuation_location' => $location,
        ]);
    }

    public function markMissing(): void
    {
        $this->update([
            'evacuation_status' => self::EVAC_MISSING,
            'evacuation_checked_at' => now(),
        ]);
    }

    public function resetEvacuationStatus(): void
    {
        $this->update([
            'evacuation_status' => self::EVAC_UNKNOWN,
            'evacuation_checked_at' => null,
            'evacuation_location' => null,
            'evacuation_assembly_point_id' => null,
        ]);
    }

    public function getStatusLabel(): string
    {
        return match($this->status) {
            'checked_in' => 'داخل المبنى',
            'checked_out' => 'غادر',
            'denied' => 'مرفوض',
            'blacklisted' => 'محظور',
            default => $this->status,
        };
    }

    public function getStatusColor(): string
    {
        return match($this->status) {
            'checked_in' => 'success',
            'checked_out' => 'secondary',
            'denied' => 'warning',
            'blacklisted' => 'danger',
            default => 'secondary',
        };
    }

    public function getEvacuationStatusLabel(): string
    {
        return match($this->evacuation_status) {
            'unknown' => 'غير محدد',
            'safe' => 'آمن',
            'evacuating' => 'جاري الإخلاء',
            'need_help' => 'يحتاج مساعدة',
            'missing' => 'مفقود',
            default => $this->evacuation_status,
        };
    }

    public function getEvacuationStatusColor(): string
    {
        return match($this->evacuation_status) {
            'unknown' => 'secondary',
            'safe' => 'success',
            'evacuating' => 'warning',
            'need_help' => 'danger',
            'missing' => 'dark',
            default => 'secondary',
        };
    }

    public function getIdTypeLabel(): ?string
    {
        if (!$this->id_type) return null;

        return match($this->id_type) {
            'national_id' => 'هوية وطنية',
            'passport' => 'جواز سفر',
            'employee_id' => 'هوية موظف',
            'driver_license' => 'رخصة قيادة',
            default => $this->id_type,
        };
    }

    public function getAssistanceTypeLabel(): ?string
    {
        if (!$this->assistance_type) return null;

        return match($this->assistance_type) {
            'wheelchair' => 'كرسي متحرك',
            'visual' => 'ضعف بصري',
            'hearing' => 'ضعف سمعي',
            'mobility' => 'صعوبة حركة',
            'medical' => 'حالة طبية',
            'other' => 'أخرى',
            default => $this->assistance_type,
        };
    }

    public function getDurationMinutes(): int
    {
        $endTime = $this->checked_out_at ?? now();
        return $this->checked_in_at->diffInMinutes($endTime);
    }

    public function getDurationFormatted(): string
    {
        $minutes = $this->getDurationMinutes();
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours > 0) {
            return "{$hours} ساعة و {$mins} دقيقة";
        }
        return "{$mins} دقيقة";
    }

    public function generateQrData(): array
    {
        return [
            'type' => 'emergency_visitor',
            'token' => $this->qr_token,
            'visitor_id' => $this->id,
            'name' => $this->name,
            'building_id' => $this->building_id,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function ($visitor) {
            if (empty($visitor->qr_token)) {
                $visitor->qr_token = Str::random(64);
            }
            if (empty($visitor->checked_in_at)) {
                $visitor->checked_in_at = now();
            }
        });
    }
}
