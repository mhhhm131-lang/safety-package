<?php

namespace App\Modules\Permit\Models;

use App\Models\User;
use App\Modules\Governance\Models\Place;
use App\Modules\Project\Models\ExternalParty;
use App\Modules\Project\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * معدة — موضوع تصاريح تشغيل المعدات والرافعات وعزل الطاقة (من `Modules/EPC` في OHSMS).
 * نُقلت إلى وحدة التصاريح لأن هذا استعمالها الوحيد عندنا (بقية EPC خارج النطاق §٢).
 */
class Equipment extends Model
{
    protected $table = 'equipment';

    public const STATUS_LABELS = [
        'active'         => 'صالحة',
        'maintenance'    => 'في الصيانة',
        'out_of_service' => 'خارج الخدمة',
        'retired'        => 'مستبعدة',
    ];

    public const TYPE_LABELS = [
        'heavy'      => 'معدات ثقيلة',
        'light'      => 'معدات خفيفة',
        'electrical' => 'كهربائية',
        'safety'     => 'معدات سلامة',
        'lifting'    => 'معدات رفع',
        'other'      => 'أخرى',
    ];

    protected $fillable = [
        'project_id', 'place_id', 'external_party_id', 'name', 'code', 'equipment_type',
        'serial_number', 'manufacturer', 'model_number', 'status', 'inspection_frequency_days',
        'last_inspection_date', 'next_inspection_date', 'location', 'assigned_to_id', 'notes', 'created_by_id',
    ];

    protected $casts = [
        'last_inspection_date' => 'date',
        'next_inspection_date' => 'date',
    ];

    protected $attributes = ['status' => 'active'];

    public function project(): BelongsTo       { return $this->belongsTo(Project::class); }
    public function place(): BelongsTo         { return $this->belongsTo(Place::class); }
    public function externalParty(): BelongsTo { return $this->belongsTo(ExternalParty::class); }
    public function assignedTo(): BelongsTo    { return $this->belongsTo(User::class, 'assigned_to_id'); }
    public function inspections(): HasMany     { return $this->hasMany(EquipmentInspection::class)->latest('inspection_date'); }

    public function getStatusLabel(): string { return self::STATUS_LABELS[$this->status] ?? $this->status; }
    public function getTypeLabel(): string   { return self::TYPE_LABELS[$this->equipment_type] ?? ($this->equipment_type ?? '—'); }

    public function isInspectionOverdue(): bool
    {
        return $this->next_inspection_date !== null && $this->next_inspection_date->isPast();
    }
}
