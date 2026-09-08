<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DrillParticipant extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'drill_id',
        'user_id',
        'visitor_name',
        'visitor_phone',
        'role',
        'status',
        'evacuated_at',
        'floor_id',
        'evacuation_time_sec',
        'reached_point_id',
        'feedback',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'evacuated_at' => 'datetime',
    ];

    protected $attributes = [
        'role' => 'participant',
    ];

    const ROLE_PARTICIPANT = 'participant';
    const ROLE_OBSERVER = 'observer';
    const ROLE_EVALUATOR = 'evaluator';
    const ROLE_TEAM_MEMBER = 'team_member';

    // Relationships
    public function drill(): BelongsTo
    {
        return $this->belongsTo(EvacuationDrill::class, 'drill_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(BuildingFloor::class, 'floor_id');
    }

    public function reachedPoint(): BelongsTo
    {
        return $this->belongsTo(AssemblyPoint::class, 'reached_point_id');
    }

    // Helpers
    public function getRoleLabel(): string
    {
        return match($this->role) {
            'participant' => 'مشارك',
            'observer' => 'مراقب',
            'evaluator' => 'مقيّم',
            'team_member' => 'عضو فريق',
            default => $this->role,
        };
    }
}
