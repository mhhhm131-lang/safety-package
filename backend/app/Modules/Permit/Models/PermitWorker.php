<?php

namespace App\Modules\Permit\Models;

use App\Modules\Worker\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * عامل معيَّن على تصريح (من WorkPermit في OHSMS، مربوط هنا بالتصريح الموحّد مباشرة).
 * حالة التأهيل لقطة وقت الإسناد؛ الفحص الحيّ في GateReadinessService.
 */
class PermitWorker extends Model
{
    public $timestamps = false;

    public const STATUS_LABELS = [
        'qualified'     => 'مؤهَّل',
        'warning'       => 'تحذير',
        'not_qualified' => 'غير مؤهَّل',
    ];

    protected $fillable = ['permit_id', 'worker_id', 'qualification_status', 'qualification_notes', 'checked_at'];

    protected $casts = ['checked_at' => 'datetime'];

    protected $attributes = ['qualification_status' => 'qualified'];

    protected static function booted(): void
    {
        static::creating(function (PermitWorker $m) {
            $m->checked_at ??= now();
        });
    }

    public function permit(): BelongsTo { return $this->belongsTo(Permit::class); }
    public function worker(): BelongsTo { return $this->belongsTo(Worker::class); }

    public function getStatusLabel(): string
    {
        return self::STATUS_LABELS[$this->qualification_status] ?? $this->qualification_status;
    }
}
