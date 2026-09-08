<?php

namespace App\Modules\Emergency\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PanicAlertResponder extends Model
{
    protected $fillable = [
        'panic_alert_id',
        'user_id',
        'notified_at',
        'seen_at',
        'responded_at',
        'response_type',
    ];

    protected $casts = [
        'notified_at' => 'datetime',
        'seen_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    // Response type constants
    const RESPONSE_ACKNOWLEDGED = 'acknowledged';
    const RESPONSE_EN_ROUTE = 'en_route';
    const RESPONSE_ARRIVED = 'arrived';
    const RESPONSE_UNAVAILABLE = 'unavailable';

    // Relationships
    public function alert(): BelongsTo
    {
        return $this->belongsTo(PanicAlert::class, 'panic_alert_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Methods
    public function markSeen(): void
    {
        if (!$this->seen_at) {
            $this->update(['seen_at' => now()]);
        }
    }

    public function respond(string $responseType): void
    {
        $this->update([
            'response_type' => $responseType,
            'responded_at' => now(),
        ]);
    }

    public function hasResponded(): bool
    {
        return $this->response_type !== null;
    }

    public function getResponseLabel(): ?string
    {
        if (!$this->response_type) {
            return null;
        }

        return match($this->response_type) {
            'acknowledged' => 'تم الاستلام',
            'en_route' => 'في الطريق',
            'arrived' => 'وصل',
            'unavailable' => 'غير متاح',
            default => $this->response_type,
        };
    }

    public function getResponseTimeSeconds(): ?int
    {
        if (!$this->responded_at || !$this->notified_at) {
            return null;
        }
        return $this->responded_at->diffInSeconds($this->notified_at);
    }
}
