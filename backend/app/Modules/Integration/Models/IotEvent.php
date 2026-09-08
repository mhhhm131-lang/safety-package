<?php

namespace App\Modules\Integration\Models;

use App\Modules\Emergency\Models\EmergencyIncident;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ما وصل من جهاز (Webhook أو MQTT أو استطلاع) وما فُعل به. المرفوض توقيعاً يُسجَّل أيضاً (signature_valid=false). */
class IotEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['device_id', 'kind', 'event_type', 'source', 'payload', 'signature_valid', 'source_ip', 'incident_id', 'action_taken', 'note', 'received_at'];

    protected $casts = ['payload' => 'array', 'signature_valid' => 'boolean', 'received_at' => 'datetime'];

    public const ACTIONS = [
        'incident_created' => 'أُنشئت حالة طارئة', 'logged_to_incident' => 'سُجّل في الحالة المفتوحة', 'notified' => 'نُبّه المركز',
        'ignored' => 'تُجوهل', 'rejected' => 'رُفض (توقيع)', 'status' => 'تحديث حالة',
    ];

    public function device(): BelongsTo { return $this->belongsTo(IotDevice::class, 'device_id'); }
    public function incident(): BelongsTo { return $this->belongsTo(EmergencyIncident::class, 'incident_id'); }

    public function getActionLabel(): string { return self::ACTIONS[$this->action_taken] ?? ($this->action_taken ?? '—'); }

    public function getActionBadge(): string
    {
        $cls = match ($this->action_taken) {
            'incident_created' => 'bg-danger', 'logged_to_incident' => 'bg-warning text-dark', 'notified' => 'bg-info text-dark',
            'rejected' => 'bg-dark', 'ignored' => 'bg-secondary', default => 'bg-light text-dark border',
        };
        return '<span class="badge '.$cls.'">'.e($this->getActionLabel()).'</span>';
    }
}
