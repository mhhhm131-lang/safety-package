<?php

namespace App\Modules\Integration\Services;

use App\Core\Services\NotificationService;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Emergency\Models\EmergencyEventLog;
use App\Modules\Emergency\Models\EmergencyIncident;
use App\Modules\Emergency\Services\EmergencyService;
use App\Modules\Integration\Models\IotDevice;
use App\Modules\Integration\Models\IotEvent;
use Illuminate\Support\Facades\Log;

/**
 * ما يحدث حين يصل حدث من جهاز (Webhook موقّع أو رسالة MQTT) — الإصلاح المقرر ٥-٤:
 * في OHSMS كانت Webhooks بلا مصادقة وتكتفي بالتسجيل؛ هنا:
 *  - لوحة الحريق `alarm` → حالة طارئة (حريق) في مكان المنطقة فوراً بمسار المرحلة ٤ كاملاً (تنبيه الفريق والقيادة والحصر).
 *    إن كانت حالة مفتوحة في المبنى تُسجَّل المنطقة فيها بلا حالة ثانية. `restore`/`trouble`/`supervisory` تُسجَّل وتُنبّه المركز.
 *  - التكييف `smoke_detected`/`gas_detected`/`co_detected`/`high_temp` → حالة (حريق/تسرب غاز) بالمنطق نفسه.
 *  - الأبواب (باب مكسور، محتجز، عبث) والمصاعد (احتجاز، عطل) → تنبيه المركز ورئيس الأمن، وتسجيل في الحالة المفتوحة إن وُجدت.
 * كل حدث يُسجَّل في iot_events بما فُعل به.
 */
class IoTEventService
{
    public const INCIDENT_EVENTS = [
        'fire_panel' => ['alarm' => 'fire', 'fire' => 'fire'],
        'hvac' => ['smoke_detected' => 'fire', 'gas_detected' => 'gas_leak', 'co_detected' => 'gas_leak', 'high_temp' => 'fire'],
    ];

    public const NOTIFY_EVENTS = [
        'fire_panel' => ['trouble', 'supervisory', 'restore', 'silenced', 'reset'],
        'access_control' => ['forced_door', 'door_held', 'invalid_access', 'tamper'],
        'elevator' => ['entrapment', 'fault', 'door_malfunction', 'emergency_stop'],
        'hvac' => ['fault'],
        'signage' => ['offline'],
    ];

    public function __construct(protected EmergencyService $emergency, protected NotificationService $inbox) {}

    /** يعيد سجل الحدث بعد المعالجة. */
    public function handle(IotDevice $device, array $payload, string $source = 'webhook', ?string $ip = null, bool $signatureValid = true): IotEvent
    {
        $type = strtolower((string) ($payload['event_type'] ?? $payload['event'] ?? $payload['type'] ?? 'unknown'));
        $event = IotEvent::create([
            'device_id' => $device->id, 'kind' => $device->kind, 'event_type' => $type, 'source' => $source, 'payload' => $payload,
            'signature_valid' => $signatureValid, 'source_ip' => $ip, 'received_at' => now(),
        ]);
        if (!$signatureValid) {
            $event->update(['action_taken' => 'rejected', 'note' => 'توقيع غير صحيح']);
            Log::warning('[IoT] rejected event (bad signature)', ['device' => $device->id, 'type' => $type, 'ip' => $ip]);
            return $event;
        }
        $device->touchSeen(['last_event' => $type, 'at' => now()->toISOString()]);
        if ($device->kind === 'fire_panel' && $device->building_id) {
            // حالة اللوحة والمناطق تُقرأ من آخر إشارة — يُمسح الكاش (١٥ ثانية) حتى تظهر فوراً
            app(FirePanelService::class)->forDevice($device)->clearZoneCache($device->building_id);
        }

        try {
            $incidentType = self::INCIDENT_EVENTS[$device->kind][$type] ?? null;
            if ($incidentType) {
                $this->raiseIncident($device, $event, $incidentType, $payload);
            } elseif (in_array($type, self::NOTIFY_EVENTS[$device->kind] ?? [], true)) {
                $this->notify($device, $event, $payload);
            } else {
                $event->update(['action_taken' => 'status']);
            }
        } catch (\Throwable $e) {
            report($e);
            $event->update(['action_taken' => 'ignored', 'note' => 'خطأ في المعالجة: '.mb_substr($e->getMessage(), 0, 200)]);
        }
        return $event;
    }

    protected function raiseIncident(IotDevice $device, IotEvent $event, string $incidentType, array $payload): void
    {
        $building = $device->building ?? EmergencyBuilding::main();
        if (!$building) {
            $event->update(['action_taken' => 'ignored', 'note' => 'لا مبنى']);
            return;
        }
        $zone = $payload['zone_id'] ?? $payload['zone'] ?? null;
        $placeId = $device->placeIdForZone($zone);
        $where = trim(($zone !== null ? 'المنطقة '.$zone : '').(!empty($payload['location']) ? ' — '.$payload['location'] : ''));
        $desc = $device->getKindLabel().' «'.$device->name.'»: '.$event->event_type.($where ? ' — '.$where : '');

        $open = $building->getActiveIncident();
        if ($open) {
            EmergencyEventLog::log($open, EmergencyEventLog::TYPE_ALARM_TRIGGERED, 'إشارة جهاز: '.$desc, ['device_id' => $device->id, 'payload' => $payload], 'critical', null);
            $event->update(['action_taken' => 'logged_to_incident', 'incident_id' => $open->id]);
            return;
        }

        $severity = in_array(($payload['severity'] ?? ''), ['low', 'medium', 'high', 'critical'], true) ? $payload['severity'] : 'critical';
        $incident = $this->emergency->triggerAlarm($building, $incidentType, null, $severity, false, $desc, $placeId, null, $device);
        $event->update(['action_taken' => 'incident_created', 'incident_id' => $incident->id]);
        Log::critical('[IoT] incident created from device', ['device' => $device->id, 'incident' => $incident->incident_code]);
    }

    protected function notify(IotDevice $device, IotEvent $event, array $payload): void
    {
        $title = $device->getKindLabel().' — '.$this->label($event->event_type);
        $body = $device->name.($payload['location'] ?? $payload['door_id'] ?? $payload['elevator_id'] ?? $payload['zone_id'] ?? '' ? ' — '.($payload['location'] ?? $payload['door_id'] ?? $payload['elevator_id'] ?? $payload['zone_id']) : '');
        $roles = match ($device->kind) {
            'access_control' => ['system_admin', 'system_staff', 'security_safety_head'],
            'elevator' => ['system_admin', 'system_staff', 'facilities_manager'],
            default => ['system_admin', 'system_staff'],
        };
        $this->inbox->notifyRoles($roles, 'iot.'.$device->kind, $title, $body, '/app/emergency/iot/devices/'.$device->id);
        $open = $device->building?->getActiveIncident() ?? EmergencyBuilding::main()?->getActiveIncident();
        if ($open) {
            EmergencyEventLog::log($open, EmergencyEventLog::TYPE_NOTE, 'إشارة جهاز: '.$title.' — '.$body, ['device_id' => $device->id], $event->event_type === 'restore' ? 'info' : 'warning', null);
            $event->update(['action_taken' => 'logged_to_incident', 'incident_id' => $open->id]);
            return;
        }
        $event->update(['action_taken' => 'notified']);
    }

    public function label(string $type): string
    {
        return match ($type) {
            'alarm' => 'إنذار', 'fire' => 'حريق', 'trouble' => 'عطل', 'supervisory' => 'إشراف', 'restore' => 'عودة للوضع الطبيعي', 'silenced' => 'إسكات', 'reset' => 'إعادة ضبط',
            'forced_door' => 'باب فُتح عنوة', 'door_held' => 'باب مفتوح طويلاً', 'invalid_access' => 'محاولة دخول غير مصرح', 'tamper' => 'عبث',
            'entrapment' => 'احتجاز في المصعد', 'fault' => 'عطل', 'door_malfunction' => 'عطل باب المصعد', 'emergency_stop' => 'توقف طارئ',
            'smoke_detected' => 'كشف دخان', 'gas_detected' => 'كشف غاز', 'co_detected' => 'كشف أول أكسيد الكربون', 'high_temp' => 'حرارة مرتفعة', 'offline' => 'خارج الاتصال',
            default => $type,
        };
    }
}
