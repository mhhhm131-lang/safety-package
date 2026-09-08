<?php

namespace App\Modules\Project\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManhourLog extends Model
{

    const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'external_party_id',
        'date',
        'workers_count',
        'hours_worked',
        'total_manhours',
        'incidents_count',
        'lost_time_incidents',
        'notes',
        'recorded_by_id',
    ];

    protected $casts = [
        'date' => 'date',
        'workers_count' => 'integer',
        'hours_worked' => 'decimal:2',
        'total_manhours' => 'decimal:2',
        'incidents_count' => 'integer',
        'lost_time_incidents' => 'integer',
    ];

    protected $attributes = [
        'incidents_count' => 0,
        'lost_time_incidents' => 0,
    ];

    protected static function booted(): void
    {
        static::creating(function (ManhourLog $log) {
            $log->total_manhours = round($log->workers_count * $log->hours_worked, 2);
        });

        static::updating(function (ManhourLog $log) {
            $log->total_manhours = round($log->workers_count * $log->hours_worked, 2);
        });
    }

    // Relationships

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function externalParty(): BelongsTo
    {
        return $this->belongsTo(ExternalParty::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }
}
