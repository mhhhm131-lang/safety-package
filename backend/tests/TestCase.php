<?php

namespace Tests;

use App\Modules\Emergency\Models\ResponsePlan;
use App\Modules\Governance\Models\Place;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * خطط الاستجابة الثماني في النظام كما على المنشور (`ipa:sync-plans` يعمل عند كل تشغيل للخادم).
     * للاختبارات التي تعدّ بطاقات المركز: بلا خطط تصله بطاقة «أماكن بلا خطة استجابة» (٢٧-ب) فيزيد العدّ واحداً.
     * يُستدعى بعد بذر الأماكن.
     */
    /**
     * قرار ٧٢ (٢٠٢٦-١٠-٠٤): النموذج صار لا يرسل بلاغاً بلا خطر، والعادي بحساب. ما أُرسل قبله — بلا خطر أو بلا حساب —
     * قائم ويكمل طريقه (تصنيف المركز، الإحالة، رمز التتبع). الاختبارات التي تحرس ذلك تبنيه من الخدمة كما كان النموذج يبنيه.
     */
    protected function legacyReport(array $data, string $type = 'normal', ?int $userId = null): \App\Modules\Incident\Models\Incident
    {
        $service = app(\App\Modules\Incident\Services\IncidentService::class);
        $incident = $type === 'secret' ? $service->createSecretIncident($data)['incident'] : $service->createIncident($type, $userId, $data);
        app(\App\Modules\Incident\Services\OccSync::class)->refresh($userId);
        return $incident;
    }

    protected function plansInSystem(): void
    {
        foreach (Place::where('code', '!=', 'HZ-00')->get() as $place) {
            ResponsePlan::firstOrCreate(['place_id' => $place->id],
                ['title' => $place->name, 'source_path' => 'tests', 'fingerprint' => str_repeat('0', 40), 'synced_at' => now()]);
        }
    }
}
