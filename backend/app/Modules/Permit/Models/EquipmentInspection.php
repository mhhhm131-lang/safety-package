<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** فحص دوري لمعدة — نتيجته تحكم صلاحية تصريح تشغيلها. */
class EquipmentInspection extends Model
{
    public const UPDATED_AT = null;

    public const RESULT_LABELS = ['pass' => 'مطابق', 'fail' => 'غير مطابق', 'conditional' => 'مطابق بشرط'];

    protected $fillable = ['equipment_id', 'inspection_date', 'inspector_id', 'result', 'findings', 'next_inspection', 'created_at'];

    protected $casts = [
        'inspection_date' => 'date',
        'next_inspection' => 'date',
        'created_at'      => 'datetime',
    ];

    public function equipment(): BelongsTo { return $this->belongsTo(Equipment::class); }
    public function inspector(): BelongsTo { return $this->belongsTo(User::class, 'inspector_id'); }

    public function getResultLabel(): string { return self::RESULT_LABELS[$this->result] ?? $this->result; }
}
