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
    protected function plansInSystem(): void
    {
        foreach (Place::where('code', '!=', 'HZ-00')->get() as $place) {
            ResponsePlan::firstOrCreate(['place_id' => $place->id],
                ['title' => $place->name, 'source_path' => 'tests', 'fingerprint' => str_repeat('0', 40), 'synced_at' => now()]);
        }
    }
}
