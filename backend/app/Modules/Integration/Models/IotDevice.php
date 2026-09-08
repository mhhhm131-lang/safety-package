<?php

namespace App\Modules\Integration\Models;

use App\Core\Traits\HasAuditLog;
use App\Models\User;
use App\Modules\Emergency\Models\EmergencyBuilding;
use App\Modules\Governance\Models\Place;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * جهاز/نظام مبنى موصول (بدل أعمدة tenant_settings في OHSMS). لكل جهاز مفتاح توقيع HMAC خاص (مشفّر في القاعدة)
 * وعنوان مسموح به للبروتوكولات. config: خريطة المناطق ← الأماكن (`zones: {"1":"HZ-06"}`)، وعناوين القراءة
 * (`bacnet.objects`, `modbus.registers`)، ومواضيع MQTT (`mqtt.topics`).
 */
class IotDevice extends Model
{
    use HasAuditLog;

    protected $fillable = [
        'kind', 'name', 'building_id', 'place_id', 'protocol', 'host', 'port', 'base_path', 'scheme', 'unit_id',
        'username', 'password', 'webhook_secret', 'config', 'is_enabled', 'last_seen_at', 'last_status', 'created_by_id',
    ];

    protected $casts = [
        'config' => 'array', 'last_status' => 'array', 'is_enabled' => 'boolean', 'last_seen_at' => 'datetime',
        'password' => 'encrypted', 'webhook_secret' => 'encrypted',
    ];

    protected $hidden = ['password', 'webhook_secret'];

    public const KINDS = [
        'fire_panel' => 'لوحة إنذار الحريق', 'access_control' => 'التحكم بالأبواب', 'elevator' => 'المصاعد',
        'hvac' => 'التكييف والتهوية', 'signage' => 'شاشات العرض', 'mqtt_broker' => 'وسيط MQTT', 'other' => 'أخرى',
    ];

    public const PROTOCOLS = ['rest' => 'REST/HTTP', 'bacnet' => 'BACnet/IP', 'modbus' => 'Modbus TCP', 'mqtt' => 'MQTT', 'webhook' => 'Webhook فقط'];

    public const DEFAULT_PORTS = ['rest' => 8080, 'bacnet' => 47808, 'modbus' => 502, 'mqtt' => 1883, 'webhook' => null];

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            if (empty($d->webhook_secret)) $d->webhook_secret = Str::random(48);
        });
    }

    public function auditLabel(): string
    {
        return $this->getKindLabel().' '.$this->name;
    }

    public function building(): BelongsTo { return $this->belongsTo(EmergencyBuilding::class, 'building_id'); }
    public function place(): BelongsTo { return $this->belongsTo(Place::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_id'); }
    public function events(): HasMany { return $this->hasMany(IotEvent::class, 'device_id')->orderByDesc('received_at'); }

    public function scopeEnabled($query) { return $query->where('is_enabled', true); }
    public function scopeOfKind($query, string $kind) { return $query->where('kind', $kind); }

    /** الجهاز المفعّل من نوع لمبنى (أو أي مبنى). */
    public static function forBuilding(string $kind, ?int $buildingId): ?self
    {
        return static::enabled()->ofKind($kind)
            ->when($buildingId, fn ($q) => $q->where(fn ($w) => $w->where('building_id', $buildingId)->orWhereNull('building_id')))
            ->orderByRaw('building_id is null')->first();
    }

    public function getKindLabel(): string { return self::KINDS[$this->kind] ?? $this->kind; }
    public function getProtocolLabel(): string { return self::PROTOCOLS[$this->protocol] ?? $this->protocol; }

    public function endpoint(string $path = ''): string
    {
        $port = $this->port ?: (self::DEFAULT_PORTS[$this->protocol] ?? 80);
        $base = rtrim((string) $this->base_path, '/');
        return "{$this->scheme}://{$this->host}:{$port}{$base}".($path !== '' ? '/'.ltrim($path, '/') : '');
    }

    /** المكان المرتبط بمنطقة إنذار (config.zones) وإلا مكان الجهاز. */
    public function placeIdForZone(int|string|null $zone): ?int
    {
        $zones = $this->config['zones'] ?? [];
        $code = $zone !== null ? ($zones[(string) $zone] ?? null) : null;
        if ($code) {
            return Place::idByCode($code);
        }
        return $this->place_id;
    }

    /** توقيع الجسم بمفتاح الجهاز — ما يُقارن به رأس X-IPA-Signature. */
    public function sign(string $body): string
    {
        return 'sha256='.hash_hmac('sha256', $body, (string) $this->webhook_secret);
    }

    public function verifySignature(?string $header, string $body): bool
    {
        if (!$header || !$this->webhook_secret) return false;
        return hash_equals($this->sign($body), trim($header));
    }

    public function touchSeen(?array $status = null): void
    {
        $this->forceFill(['last_seen_at' => now(), 'last_status' => $status ?? $this->last_status])->saveQuietly();
    }
}
