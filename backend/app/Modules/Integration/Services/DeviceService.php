<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Models\IotDevice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * أساس خدمات الأنظمة الخمس (بدل `setTenant`/`TenantSettings` في OHSMS): الجهاز المفعّل للمبنى هو مصدر العنوان والاعتماد.
 * بلا جهاز مفعّل → كل الأفعال تعيد «غير مفعّل» بلا محاولة اتصال.
 */
abstract class DeviceService
{
    protected ?IotDevice $device = null;

    abstract protected function kind(): string;

    public function forBuilding(?int $buildingId): static
    {
        $this->device = IotDevice::forBuilding($this->kind(), $buildingId);
        return $this;
    }

    public function forDevice(IotDevice $device): static
    {
        $this->device = $device;
        return $this;
    }

    public function device(): ?IotDevice
    {
        return $this->device;
    }

    /** مفعّل = جهاز مسجّل مفعّل، وله عنوان إن كان بروتوكوله يتصل به (webhook يستقبل فقط بلا عنوان). */
    public function isEnabled(): bool
    {
        return $this->device !== null && $this->device->is_enabled && ($this->device->protocol === 'webhook' || (bool) $this->device->host);
    }

    /** يمكن الاتصال الصادر بالجهاز (ليس webhook فقط). */
    public function canReach(): bool
    {
        return $this->isEnabled() && $this->device->protocol !== 'webhook';
    }

    protected function getEndpoint(string $path): string
    {
        return $this->device?->endpoint($path) ?? 'http://localhost/'.$path;
    }

    protected function http(int $timeout = 10): PendingRequest
    {
        $req = Http::timeout($timeout)->acceptJson();
        if ($this->device?->username) {
            $req = $req->withBasicAuth($this->device->username, (string) $this->device->password);
        }
        return $req;
    }

    protected function notEnabled(string $what = 'system'): array
    {
        return ['success' => false, 'enabled' => false, 'error' => 'لا جهاز مفعّل لهذا النظام', 'timestamp' => now()->toISOString()];
    }
}
