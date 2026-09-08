<?php

namespace App\Console\Commands;

use App\Modules\Integration\Models\IotDevice;
use App\Modules\Integration\Services\IoTEventService;
use App\Modules\Integration\Services\Protocols\MqttProtocol;
use Illuminate\Console\Command;

/**
 * مستمع MQTT: يشترك في مواضيع الأجهزة من نوع mqtt (config.mqtt.topics) ويمرّر كل رسالة JSON إلى IoTEventService
 * كما لو كانت Webhook موقّعاً (الوسيط نفسه هو المصادقة: اسم مستخدم/كلمة مرور الجهاز).
 * لا يعمل تلقائياً: يُضاف إلى supervisord بعد وجود وسيط فعلي (جواب المرافق). محلياً: php artisan iot:mqtt-listen --seconds=60
 */
class IotMqttListen extends Command
{
    protected $signature = 'iot:mqtt-listen {--device= : معرّف الجهاز (وإلا كل أجهزة MQTT المفعّلة)} {--seconds=0 : مدة الاستماع (٠ = بلا نهاية)}';

    protected $description = 'الاستماع إلى رسائل MQTT من الأجهزة المفعّلة ومعالجتها كأحداث';

    public function handle(IoTEventService $events): int
    {
        if (!function_exists('socket_create')) {
            $this->error('امتداد sockets غير مثبت في PHP');
            return self::FAILURE;
        }
        $devices = IotDevice::enabled()->where('protocol', 'mqtt')->when($this->option('device'), fn ($q, $id) => $q->where('id', $id))->get();
        if ($devices->isEmpty()) {
            $this->warn('لا أجهزة MQTT مفعّلة');
            return self::SUCCESS;
        }
        $clients = [];
        foreach ($devices as $device) {
            $mqtt = new MqttProtocol($device->host, $device->port ?: 1883, 'ipa-'.$device->id.'-'.uniqid(), $device->username, $device->password);
            if (!$mqtt->connect()) {
                $this->error("[{$device->name}] فشل الاتصال: ".$mqtt->getLastError());
                continue;
            }
            foreach ((array) ($device->config['mqtt']['topics'] ?? []) as $topic) {
                $mqtt->subscribe($topic, function (string $topic, string $message) use ($device, $events) {
                    $payload = json_decode($message, true);
                    if (!is_array($payload)) $payload = ['event_type' => 'status', 'raw' => $message];
                    $payload['topic'] = $topic;
                    $e = $events->handle($device, $payload, 'mqtt', null, true);
                    $this->line(now()->format('H:i:s')." [{$device->name}] {$topic} → {$e->event_type} ({$e->action_taken})");
                });
                $this->info("[{$device->name}] مشترك في {$topic}");
            }
            $clients[] = $mqtt;
        }
        $start = time();
        $limit = (int) $this->option('seconds');
        while ($clients && ($limit === 0 || time() - $start < $limit)) {
            foreach ($clients as $c) {
                $c->loop(200);
            }
        }
        foreach ($clients as $c) $c->disconnect();
        return self::SUCCESS;
    }
}
