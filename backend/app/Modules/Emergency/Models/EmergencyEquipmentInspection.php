<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmergencyEquipmentInspection extends Model
{
    public $timestamps = false;

    protected $table = 'emergency_equipment_inspections';

    protected $fillable = [
        'equipment_id',
        'inspected_at',
        'inspected_by_id',
        'result',
        'checklist',
        'issues_found',
        'corrective_action',
        'next_inspection_date',
        'photos',
        'notes',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
        'next_inspection_date' => 'date',
        'checklist' => 'array',
        'photos' => 'array',
        'created_at' => 'datetime',
    ];

    const RESULT_PASS = 'pass';
    const RESULT_FAIL = 'fail';
    const RESULT_NEEDS_ATTENTION = 'needs_attention';

    // Relationships
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(EmergencyEquipment::class, 'equipment_id');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by_id');
    }

    // Helpers
    public function isPassed(): bool
    {
        return $this->result === self::RESULT_PASS;
    }

    public function getResultLabel(): string
    {
        return match($this->result) {
            'pass' => 'ناجح',
            'fail' => 'فاشل',
            'needs_attention' => 'يحتاج انتباه',
            default => $this->result,
        };
    }
}
