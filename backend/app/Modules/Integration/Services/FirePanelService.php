<?php

namespace App\Modules\Integration\Services;

use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Integration\Models\IotEvent;
use App\Modules\Integration\Services\Protocols\BACnetProtocol;
use App\Modules\Integration\Services\Protocols\ModbusTcpProtocol;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * لوحة إنذار الحريق (من OHSMS). الإصلاح المقرر ٥-٤: BACnet وModbus يُستدعيان فعلاً بدل المحاكاة الثابتة؛
 * `fire_zones` عمود حقيقي؛ حالة MQTT من آخر رسالة مستلمة في iot_events.
 *
 * عناوين القراءة في config الجهاز (تُحدَّد بعد جواب المرافق عن اللوحة الفعلية):
 *   bacnet: {"panel": {"object_type": 3, "object_instance": 1}, "zones": {"1": {"object_type": 3, "object_instance": 101}, …}}
 *   modbus: {"panel": {"type": "coil", "address": 0}, "zones": {"1": {"type": "coil", "address": 1}, …}}
 *   قيمة ≠ 0 = إنذار.
 */
class FirePanelService extends DeviceService
{
    const STATE_NORMAL = 'normal';
    const STATE_ALARM = 'alarm';
    const STATE_TROUBLE = 'trouble';
    const STATE_SUPERVISORY = 'supervisory';
    const STATE_DISABLED = 'disabled';

    protected function kind(): string { return 'fire_panel'; }

    public function getPanelStatus(int $buildingId): array
    {
        $building = EmergencyBuilding::find($buildingId);
        if (!$building || !$this->isEnabled()) {
            return $this->getOfflineStatus();
        }
        return Cache::remember("fire_panel_status:{$buildingId}", 15, function () use ($building) {
            $status = match ($this->device->protocol) {
                'bacnet' => $this->getBacnetStatus($building),
                'modbus' => $this->getModbusStatus($building),
                'mqtt', 'webhook' => $this->getEventStatus($building),
                default => $this->getRestStatus($building),
            };
            $this->device->touchSeen($status);
            return $status;
        });
    }

    public function getZoneStatuses(int $buildingId): array
    {
        $building = EmergencyBuilding::find($buildingId);
        if (!$building) return [];
        $count = (int) ($building->fire_zones ?? 0);
        $zones = [];
        for ($i = 1; $i <= $count; $i++) {
            $zones[] = $this->getZoneStatus($buildingId, $i);
        }
        return $zones;
    }

    public function getZoneStatus(int $buildingId, int $zoneId): array
    {
        return Cache::remember("fire_zone_status:{$buildingId}:{$zoneId}", 15, function () use ($buildingId, $zoneId) {
            $placeCode = $this->device?->config['zones'][(string) $zoneId] ?? null;
            $name = 'المنطقة '.$zoneId.($placeCode ? ' — '.$placeCode : '');
            if (!$this->isEnabled()) {
                return $this->getZoneOfflineStatus($zoneId, $name);
            }
            try {
                $state = match ($this->device->protocol) {
                    'bacnet' => $this->readBacnetZone($zoneId),
                    'modbus' => $this->readModbusZone($zoneId),
                    'mqtt', 'webhook' => $this->lastZoneState($zoneId),
                    default => $this->readRestZone($buildingId, $zoneId),
                };
                return ['zone_id' => $zoneId, 'name' => $name, 'place' => $placeCode, 'state' => $state['state'] ?? self::STATE_NORMAL,
                    'devices' => $state['devices'] ?? [], 'last_event' => $state['last_event'] ?? null, 'last_updated' => now()->toISOString()];
            } catch (\Throwable $e) {
                Log::warning('[FirePanel] zone status error', ['zone' => $zoneId, 'error' => $e->getMessage()]);
                return $this->getZoneOfflineStatus($zoneId, $name);
            }
        });
    }

    public function getActiveAlarms(int $buildingId): array
    {
        if (!$this->isEnabled()) return [];
        if ($this->device->protocol !== 'rest') {
            return IotEvent::where('device_id', $this->device->id)->where('event_type', 'alarm')->where('received_at', '>=', now()->subDay())
                ->orderByDesc('received_at')->limit(50)->get()->map(fn ($e) => $e->payload + ['received_at' => $e->received_at->toISOString()])->all();
        }
        try {
            $r = $this->http(5)->get($this->getEndpoint('alarms'), ['building_id' => $buildingId, 'active' => true]);
            return $r->successful() ? ($r->json()['alarms'] ?? []) : [];
        } catch (\Throwable $e) {
            Log::error('[FirePanel] alarms failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function acknowledgeAlarm(int $buildingId, string $alarmId, int $userId): bool
    {
        return $this->post("alarms/{$alarmId}/acknowledge", ['building_id' => $buildingId, 'acknowledged_by' => $userId, 'timestamp' => now()->toISOString()], 10, fn () => $this->clearZoneCache($buildingId));
    }

    public function silenceAlarm(int $buildingId, int $userId): bool
    {
        return $this->post('silence', ['building_id' => $buildingId, 'silenced_by' => $userId], 10);
    }

    public function resetPanel(int $buildingId, int $userId): bool
    {
        return $this->post('reset', ['building_id' => $buildingId, 'reset_by' => $userId], 15, fn () => $this->clearZoneCache($buildingId));
    }

    public function getDeviceInventory(int $buildingId): array
    {
        if (!$this->isEnabled() || $this->device->protocol !== 'rest') return [];
        return Cache::remember("fire_panel_devices:{$buildingId}", 3600, function () use ($buildingId) {
            try {
                $r = $this->http(30)->get($this->getEndpoint('devices'), ['building_id' => $buildingId]);
                return $r->successful() ? ($r->json()['devices'] ?? []) : [];
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function testDevice(int $buildingId, string $deviceId): array
    {
        if (!$this->isEnabled() || $this->device->protocol !== 'rest') return $this->notEnabled();
        try {
            $r = $this->http(30)->post($this->getEndpoint("devices/{$deviceId}/test"), ['building_id' => $buildingId]);
            return $r->successful() ? ['success' => true, 'result' => $r->json()] : ['success' => false, 'error' => 'Test failed'];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getEventHistory(int $buildingId, int $limit = 100): array
    {
        if (!$this->isEnabled()) return [];
        return IotEvent::where('device_id', $this->device->id)->orderByDesc('received_at')->limit($limit)->get()
            ->map(fn ($e) => ['type' => $e->event_type, 'payload' => $e->payload, 'action' => $e->action_taken, 'received_at' => $e->received_at->toISOString()])->all();
    }

    /** اتصال فعلي بالبروتوكول (لزر «اختبار» في شاشة الأجهزة). */
    public function testConnection(): array
    {
        if (!$this->device) return $this->notEnabled();
        try {
            return match ($this->device->protocol) {
                'bacnet' => $this->withBacnet(fn ($b) => ['success' => true, 'protocol' => 'BACnet/IP']),
                'modbus' => $this->withModbus(fn ($m) => ['success' => true, 'protocol' => 'Modbus TCP']),
                'rest' => (function () {
                    $r = $this->http(8)->get($this->getEndpoint('status'));
                    return ['success' => $r->successful(), 'protocol' => 'REST', 'http' => $r->status()];
                })(),
                default => ['success' => true, 'protocol' => $this->device->protocol, 'note' => 'يستقبل فقط (Webhook/MQTT) — لا اتصال صادر'],
            };
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    // ── البروتوكولات ──

    protected function getRestStatus(EmergencyBuilding $building): array
    {
        try {
            $r = $this->http(10)->get($this->getEndpoint('status'), ['building_id' => $building->id]);
            if ($r->successful()) {
                $d = $r->json();
                return ['online' => true, 'protocol' => 'rest', 'state' => $d['state'] ?? self::STATE_NORMAL, 'zones_in_alarm' => $d['zones_in_alarm'] ?? 0,
                    'zones_in_trouble' => $d['zones_in_trouble'] ?? 0, 'last_event' => $d['last_event'] ?? null, 'battery_status' => $d['battery_status'] ?? 'unknown',
                    'ac_power' => $d['ac_power'] ?? true, 'last_updated' => now()->toISOString()];
            }
            return $this->getOfflineStatus();
        } catch (\Throwable $e) {
            Log::warning('[FirePanel] REST status failed', ['error' => $e->getMessage()]);
            return $this->getOfflineStatus();
        }
    }

    protected function getBacnetStatus(EmergencyBuilding $building): array
    {
        $panel = $this->device->config['bacnet']['panel'] ?? null;
        return $this->withBacnet(function (BACnetProtocol $b) use ($panel, $building) {
            $alarm = 0;
            if ($panel) {
                $res = $b->sendCommand('read_property', ['object_type' => (int) ($panel['object_type'] ?? 3), 'object_instance' => (int) ($panel['object_instance'] ?? 1), 'property_id' => (int) ($panel['property_id'] ?? 85)]);
                if (!($res['success'] ?? false)) {
                    return $this->getOfflineStatus($res['error'] ?? 'read failed');
                }
                $alarm = (int) ($res['value'] ?? 0);
            }
            $zonesInAlarm = 0;
            foreach (array_keys($this->device->config['bacnet']['zones'] ?? []) as $z) {
                if (($this->readBacnetZone((int) $z, $b)['state'] ?? '') === self::STATE_ALARM) $zonesInAlarm++;
            }
            return ['online' => true, 'protocol' => 'bacnet', 'state' => ($alarm || $zonesInAlarm) ? self::STATE_ALARM : self::STATE_NORMAL,
                'zones_in_alarm' => $zonesInAlarm, 'zones_in_trouble' => 0, 'last_updated' => now()->toISOString()];
        }) ?? $this->getOfflineStatus();
    }

    protected function getModbusStatus(EmergencyBuilding $building): array
    {
        $panel = $this->device->config['modbus']['panel'] ?? null;
        return $this->withModbus(function (ModbusTcpProtocol $m) use ($panel) {
            $alarm = 0;
            if ($panel) {
                $res = $m->sendCommand($this->modbusCommand($panel['type'] ?? 'coil'), ['address' => (int) ($panel['address'] ?? 0), 'count' => 1]);
                if (!($res['success'] ?? false)) {
                    return $this->getOfflineStatus($res['error'] ?? 'read failed');
                }
                $alarm = (int) ($res['values'][0] ?? 0);
            }
            $zonesInAlarm = 0;
            foreach (array_keys($this->device->config['modbus']['zones'] ?? []) as $z) {
                if (($this->readModbusZone((int) $z, $m)['state'] ?? '') === self::STATE_ALARM) $zonesInAlarm++;
            }
            return ['online' => true, 'protocol' => 'modbus', 'state' => ($alarm || $zonesInAlarm) ? self::STATE_ALARM : self::STATE_NORMAL,
                'zones_in_alarm' => $zonesInAlarm, 'zones_in_trouble' => 0, 'last_updated' => now()->toISOString()];
        }) ?? $this->getOfflineStatus();
    }

    /** MQTT/Webhook: الحالة من آخر حدث مستلم من الجهاز خلال الساعة. */
    protected function getEventStatus(EmergencyBuilding $building): array
    {
        $last = IotEvent::where('device_id', $this->device->id)->whereIn('event_type', ['alarm', 'restore', 'trouble', 'status', 'supervisory'])->orderByDesc('received_at')->first();
        if (!$last) {
            return ['online' => false, 'protocol' => $this->device->protocol, 'state' => 'unknown', 'error' => 'لم يصل حدث بعد', 'last_updated' => now()->toISOString()];
        }
        $state = match ($last->event_type) { 'alarm' => self::STATE_ALARM, 'trouble' => self::STATE_TROUBLE, 'supervisory' => self::STATE_SUPERVISORY, default => $last->payload['state'] ?? self::STATE_NORMAL };
        $active = IotEvent::where('device_id', $this->device->id)->where('event_type', 'alarm')->where('received_at', '>=', now()->subHours(6))->count();
        return ['online' => $last->received_at->gt(now()->subHour()), 'protocol' => $this->device->protocol, 'state' => $state, 'zones_in_alarm' => $active,
            'zones_in_trouble' => 0, 'last_event' => $last->event_type.' '.$last->received_at->format('H:i:s'), 'last_updated' => now()->toISOString()];
    }

    protected function readRestZone(int $buildingId, int $zoneId): array
    {
        $r = $this->http(5)->get($this->getEndpoint("zones/{$zoneId}"), ['building_id' => $buildingId]);
        if ($r->successful()) return $r->json();
        throw new \RuntimeException("Failed to read zone {$zoneId}");
    }

    protected function readBacnetZone(int $zoneId, ?BACnetProtocol $b = null): array
    {
        $cfg = $this->device->config['bacnet']['zones'][(string) $zoneId] ?? null;
        if (!$cfg) return ['state' => self::STATE_NORMAL];
        $read = fn (BACnetProtocol $b) => $b->sendCommand('read_property', ['object_type' => (int) ($cfg['object_type'] ?? 3), 'object_instance' => (int) ($cfg['object_instance'] ?? 0), 'property_id' => (int) ($cfg['property_id'] ?? 85)]);
        $res = $b ? $read($b) : $this->withBacnet($read);
        if (!($res['success'] ?? false)) return ['state' => 'offline'];
        return ['state' => ((int) ($res['value'] ?? 0)) ? self::STATE_ALARM : self::STATE_NORMAL];
    }

    protected function readModbusZone(int $zoneId, ?ModbusTcpProtocol $m = null): array
    {
        $cfg = $this->device->config['modbus']['zones'][(string) $zoneId] ?? null;
        if (!$cfg) return ['state' => self::STATE_NORMAL];
        $read = fn (ModbusTcpProtocol $m) => $m->sendCommand($this->modbusCommand($cfg['type'] ?? 'coil'), ['address' => (int) ($cfg['address'] ?? 0), 'count' => 1]);
        $res = $m ? $read($m) : $this->withModbus($read);
        if (!($res['success'] ?? false)) return ['state' => 'offline'];
        return ['state' => ((int) ($res['values'][0] ?? 0)) ? self::STATE_ALARM : self::STATE_NORMAL];
    }

    protected function lastZoneState(int $zoneId): array
    {
        $last = IotEvent::where('device_id', $this->device->id)->whereIn('event_type', ['alarm', 'restore', 'trouble'])
            ->where('payload->zone_id', (string) $zoneId)->orderByDesc('received_at')->first()
            ?? IotEvent::where('device_id', $this->device->id)->whereIn('event_type', ['alarm', 'restore', 'trouble'])->where('payload->zone_id', $zoneId)->orderByDesc('received_at')->first();
        if (!$last) return ['state' => self::STATE_NORMAL];
        return ['state' => match ($last->event_type) { 'alarm' => self::STATE_ALARM, 'trouble' => self::STATE_TROUBLE, default => self::STATE_NORMAL }, 'last_event' => $last->received_at->toISOString()];
    }

    protected function modbusCommand(string $type): string
    {
        return match ($type) { 'holding' => 'read_holding_registers', 'input' => 'read_input_registers', 'discrete' => 'read_discrete_inputs', default => 'read_coils' };
    }

    protected function withBacnet(callable $fn): ?array
    {
        if (!function_exists('socket_create')) return $this->getOfflineStatus('sockets extension missing');
        $b = new BACnetProtocol($this->device->host, $this->device->port ?: BACnetProtocol::BACNET_PORT, $this->device->unit_id ?: 1);
        if (!$b->connect()) return $this->getOfflineStatus($b->getLastError() ?? 'connect failed');
        try {
            return $fn($b);
        } finally {
            $b->disconnect();
        }
    }

    protected function withModbus(callable $fn): ?array
    {
        if (!function_exists('socket_create')) return $this->getOfflineStatus('sockets extension missing');
        $m = new ModbusTcpProtocol($this->device->host, $this->device->port ?: ModbusTcpProtocol::MODBUS_TCP_PORT, $this->device->unit_id ?: 1);
        if (!$m->connect()) return $this->getOfflineStatus($m->getLastError() ?? 'connect failed');
        try {
            return $fn($m);
        } finally {
            $m->disconnect();
        }
    }

    protected function post(string $path, array $body, int $timeout, ?callable $onSuccess = null): bool
    {
        if (!$this->isEnabled() || $this->device->protocol !== 'rest') return false;
        try {
            $r = $this->http($timeout)->post($this->getEndpoint($path), $body);
            if ($r->successful()) {
                if ($onSuccess) $onSuccess();
                return true;
            }
            return false;
        } catch (\Throwable $e) {
            Log::error('[FirePanel] '.$path.' failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function getOfflineStatus(?string $error = null): array
    {
        return ['online' => false, 'success' => false, 'state' => 'offline', 'error' => $error ?? 'لا اتصال باللوحة', 'last_updated' => now()->toISOString()];
    }

    protected function getZoneOfflineStatus(int $zoneId, ?string $name = null): array
    {
        return ['zone_id' => $zoneId, 'name' => $name ?? "المنطقة {$zoneId}", 'state' => 'offline', 'devices' => [], 'last_updated' => now()->toISOString()];
    }

    public function clearZoneCache(int $buildingId, ?int $zoneId = null): void
    {
        Cache::forget("fire_panel_status:{$buildingId}");
        $count = $zoneId ? [$zoneId] : range(1, (int) (EmergencyBuilding::find($buildingId)?->fire_zones ?? 0));
        foreach ($count as $i) {
            Cache::forget("fire_zone_status:{$buildingId}:{$i}");
        }
    }
}
