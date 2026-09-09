<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Worker\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** سجل فحص جاهزية عامل قبل السماح بالعمل (من WorkPermit في OHSMS — «جاهزية البوابة»). */
class GateLog extends Model
{
    public const UPDATED_AT = null;

    public const DENIAL_LABELS = [
        'worker_not_found'            => 'العامل غير مسجَّل',
        'status_not_authorized'       => 'حالة العامل لا تسمح بالعمل',
        'training_incomplete'         => 'تدريب إلزامي ناقص',
        'certificates_expired'        => 'شهادات منتهية',
        'medical_expired'             => 'الفحص الطبي منتهٍ',
        'no_active_permit'            => 'لا تصريح نشط يغطيه',
        'competency_gaps_outstanding' => 'كفاءات ناقصة في مهنته',
        'place_mismatch'              => 'التصريح لمكان آخر',
    ];

    protected $fillable = [
        'worker_id', 'place_id', 'permit_id', 'gate_name', 'result', 'denial_reason', 'checks', 'scanned_by_id', 'created_at',
    ];

    protected $casts = ['checks' => 'array', 'created_at' => 'datetime'];

    public function worker(): BelongsTo    { return $this->belongsTo(Worker::class); }
    public function place(): BelongsTo     { return $this->belongsTo(Place::class); }
    public function permit(): BelongsTo    { return $this->belongsTo(Permit::class); }
    public function scannedBy(): BelongsTo { return $this->belongsTo(User::class, 'scanned_by_id'); }

    public static function denialLabel(string $reason): string
    {
        return self::DENIAL_LABELS[$reason] ?? $reason;
    }
}
