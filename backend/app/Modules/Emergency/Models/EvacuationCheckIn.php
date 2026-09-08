<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تسجيل وصول شخص إلى نقطة التجمع (من OHSMS بلا tenant). المعهد: قد يكون عضو فريق بلا حساب (team_member_id).
 * رمز QR يُرسم في المتصفح (qrcodejs كما رموز الأماكن) بدل حزمة simple-qrcode غير المثبتة.
 */
class EvacuationCheckIn extends Model
{
    protected $fillable = [
        'incident_id', 'user_id', 'team_member_id', 'visitor_name', 'visitor_phone', 'visitor_company', 'person_type',
        'qr_token', 'qr_generated_at', 'status', 'response_status', 'response_at', 'response_message', 'floor_id',
        'last_known_location', 'checked_in_at', 'assembly_point_id', 'checked_by_id', 'check_in_method',
        'check_in_lat', 'check_in_lng', 'needs_assistance', 'assistance_type', 'notes',
    ];

    protected $casts = [
        'qr_generated_at' => 'datetime', 'checked_in_at' => 'datetime', 'response_at' => 'datetime',
        'needs_assistance' => 'boolean', 'check_in_lat' => 'decimal:8', 'check_in_lng' => 'decimal:8',
    ];

    protected $attributes = ['status' => 'evacuating', 'person_type' => 'employee', 'needs_assistance' => false, 'response_status' => 'pending'];

    const STATUS_EVACUATING = 'evacuating';
    const STATUS_SAFE = 'safe';
    const STATUS_MISSING = 'missing';
    const STATUS_INJURED = 'injured';
    const STATUS_ASSISTED = 'assisted';
    const STATUS_DECEASED = 'deceased';

    const TYPE_EMPLOYEE = 'employee';
    const TYPE_CONTRACTOR = 'contractor';
    const TYPE_VISITOR = 'visitor';
    const TYPE_TEAM = 'team';

    const METHOD_QR_SCAN = 'qr_scan';
    const METHOD_SELF = 'self';
    const METHOD_MANUAL = 'manual';
    const METHOD_AUTO = 'auto';

    public function incident(): BelongsTo { return $this->belongsTo(EmergencyIncident::class, 'incident_id'); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function teamMember(): BelongsTo { return $this->belongsTo(EmergencyTeamMember::class, 'team_member_id'); }
    public function floor(): BelongsTo { return $this->belongsTo(BuildingFloor::class, 'floor_id'); }
    public function assemblyPoint(): BelongsTo { return $this->belongsTo(AssemblyPoint::class, 'assembly_point_id'); }
    public function checkedBy(): BelongsTo { return $this->belongsTo(User::class, 'checked_by_id'); }

    public function scopeSafe($query) { return $query->where('status', self::STATUS_SAFE); }
    public function scopeEvacuating($query) { return $query->where('status', self::STATUS_EVACUATING); }
    public function scopeMissing($query) { return $query->where('status', self::STATUS_MISSING); }
    public function scopeNeedsHelp($query) { return $query->where('needs_assistance', true); }

    public function getPersonName(): string
    {
        if ($this->user_id) return $this->user->name ?? 'غير معروف';
        if ($this->team_member_id) return $this->teamMember?->displayName() ?? 'عضو فريق';
        return $this->visitor_name ?? 'زائر';
    }

    public function getPersonPhone(): ?string
    {
        if ($this->team_member_id) return $this->teamMember?->getDisplayPhone();
        return $this->visitor_phone;
    }

    public function isSafe(): bool { return $this->status === self::STATUS_SAFE; }
    public function isEmployee(): bool { return $this->person_type === self::TYPE_EMPLOYEE; }

    public function getStatusLabel(): string
    {
        return match ($this->status) {
            'evacuating' => 'قيد الإخلاء', 'safe' => 'آمن', 'missing' => 'مفقود', 'injured' => 'مصاب',
            'assisted' => 'يحتاج مساعدة', 'deceased' => 'متوفى', default => $this->status,
        };
    }

    public function getPersonTypeLabel(): string
    {
        return match ($this->person_type) {
            'employee' => 'موظف', 'contractor' => 'مقاول', 'visitor' => 'زائر', 'team' => 'عضو فريق', default => $this->person_type,
        };
    }

    public function getAssistanceTypeLabel(): string
    {
        return match ($this->assistance_type) {
            'mobility' => 'صعوبة حركة', 'medical' => 'حالة طبية', 'injured' => 'إصابة', 'trapped' => 'محاصر',
            'panic' => 'هلع', 'child' => 'طفل', 'elderly' => 'كبير سن', default => $this->assistance_type ?? 'غير محدد',
        };
    }

    /** ما يُرمَّز في QR الشخص: يُمسح عند نقطة التجمع (verify-qr). */
    public function getQrCodeUrl(): string
    {
        return url('/app/emergency/checkin/'.$this->qr_token);
    }

    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    protected static function booted(): void
    {
        static::creating(function (self $checkIn) {
            if (empty($checkIn->qr_token)) $checkIn->qr_token = self::generateToken();
            if (empty($checkIn->qr_generated_at)) $checkIn->qr_generated_at = now();
        });
    }
}
