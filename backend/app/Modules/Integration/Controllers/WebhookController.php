<?php

namespace App\Modules\Integration\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\IotDevice;
use App\Modules\Integration\Services\IoTEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhooks الأجهزة — بلا جلسة، بتوقيع HMAC-SHA256 لكل جهاز (الإصلاح المقرر ٥-٤؛ في OHSMS كانت بلا مصادقة وtenant_id من الجسم).
 *   POST /api/iot/webhooks/{device}   رأس X-IPA-Signature: sha256=hex(HMAC(body, secret))
 *   اختياري: X-IPA-Timestamp (unix) — يُرفض ما يزيد عمره على ٥ دقائق (إعادة إرسال).
 * الجسم JSON: {"event_type":"alarm","zone_id":"3","severity":"critical","location":"…"}
 */
class WebhookController extends Controller
{
    public function __construct(protected IoTEventService $events) {}

    public function handle(Request $request, IotDevice $device): JsonResponse
    {
        $body = $request->getContent();
        $valid = $device->is_enabled && $device->verifySignature($request->header('X-IPA-Signature'), $body);
        $ts = $request->header('X-IPA-Timestamp');
        if ($valid && $ts !== null && abs(now()->timestamp - (int) $ts) > 300) {
            $valid = false;
        }
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $payload = $request->all();
        }
        $event = $this->events->handle($device, $payload, 'webhook', $request->ip(), $valid);
        if (!$valid) {
            return response()->json(['success' => false, 'message' => 'توقيع غير صحيح أو جهاز غير مفعّل'], 401);
        }
        return response()->json([
            'success' => true, 'event_id' => $event->id, 'action' => $event->action_taken,
            'incident_id' => $event->incident_id, 'incident_code' => $event->incident?->incident_code,
            // زمن المعالجة في الخادم من وصول الطلب حتى الرد (البوابة: «خلال ثانية» — بلا زمن الشبكة)
            'processing_ms' => (int) round((microtime(true) - (float) $request->server('REQUEST_TIME_FLOAT', microtime(true))) * 1000),
        ]);
    }
}
