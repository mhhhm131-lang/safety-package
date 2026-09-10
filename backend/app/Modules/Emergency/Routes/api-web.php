<?php

use App\Modules\Emergency\Controllers\AfterActionReportController;
use App\Modules\Emergency\Controllers\EmergencyApiController;
use App\Modules\Emergency\Controllers\EmergencyMessageController;
use App\Modules\Emergency\Controllers\MedicalProfileController;
use App\Modules\Emergency\Controllers\MessageTemplateController;
use App\Modules\Emergency\Controllers\PanicAlertController;
use App\Modules\Emergency\Controllers\VisitorController;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use Illuminate\Support\Facades\Route;

/*
 * الواجهة البرمجية للطوارئ — بجلسة المتصفح نفسها (كما /api/store) لا برموز Sanctum: لا تطبيق جوال بعد.
 * يُحمَّل من web.php (لو سُمّي api.php لحمّله ModuleServiceProvider تحت /api/api/…).
 * ما استُبعد من OHSMS: إنترنت الأشياء والبروتوكولات وWebhooks (المرحلة ٥)، المواقع الداخلية والسياج والكاميرات والأساور (المرحلة ٥)،
 * لمّ الشمل والأسرة والمحادثة (لا تُنقل — قرار ٢٠٢٦-٠٩-٠٧).
 */
Route::middleware(['web', 'auth'])->prefix('api/emergency')->name('api.emergency.')->group(function () {

    Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/status', [EmergencyApiController::class, 'activeEmergencies'])->name('status');
        Route::get('/active', [EmergencyApiController::class, 'activeEmergencies'])->name('active');
        Route::get('/incidents/{incident}', [EmergencyApiController::class, 'incidentDetails'])->name('incidents.show');
        Route::get('/incidents/{incident}/stats', [EmergencyApiController::class, 'liveStats'])->name('incidents.stats');
        Route::get('/incidents/{incident}/missing', [EmergencyApiController::class, 'missingPeople'])->name('incidents.missing');
        Route::get('/incidents/{incident}/need-help', [EmergencyApiController::class, 'needHelp'])->name('incidents.need-help');
        Route::get('/incidents/{incident}/events', [EmergencyApiController::class, 'events'])->name('incidents.events');
        Route::get('/incidents/{incident}/steps', [EmergencyApiController::class, 'steps'])->name('incidents.steps');
        Route::get('/buildings/{building}/assembly-points', [EmergencyApiController::class, 'assemblyPoints'])->name('buildings.assembly-points');
        Route::get('/buildings/{building}/map-data', [EmergencyApiController::class, 'mapData'])->name('buildings.map-data');
        Route::get('/buildings/{building}/lockdown', [EmergencyApiController::class, 'lockdownStatus'])->name('buildings.lockdown');
    });

    // كل حساب: حالتي، رمزي، تعليماتي، تسجيل وصولي، طلب مساعدة
    Route::get('/my-status', [EmergencyApiController::class, 'myStatus'])->name('my-status');
    Route::get('/my-qr', [EmergencyApiController::class, 'myQr'])->name('my-qr');
    Route::get('/nearest-exit', [EmergencyApiController::class, 'nearestExit'])->name('nearest-exit');
    Route::get('/instructions', [EmergencyApiController::class, 'instructions'])->name('instructions');
    Route::post('/check-in', [EmergencyApiController::class, 'selfCheckIn'])->name('check-in');
    Route::post('/request-help', [EmergencyApiController::class, 'requestHelp'])->name('request-help');

    // الاستجابة: مسح رموز الآخرين، الإبلاغ عن مفقود، العثور
    Route::middleware('permission:emergency.respond,emergency.trigger')->group(function () {
        Route::post('/scan-qr', [EmergencyApiController::class, 'verifyQr'])->name('scan-qr');
        Route::post('/verify-qr', [EmergencyApiController::class, 'verifyQr'])->name('verify-qr');
        Route::post('/report-missing', [EmergencyApiController::class, 'reportMissing'])->name('report-missing');
        Route::post('/check-ins/{checkIn}/found', [EmergencyApiController::class, 'markFound'])->name('check-ins.found');
    });

    // قيادة الحادث (ICS) — للتفعيل
    Route::middleware('permission:emergency.trigger')->prefix('incidents/{incident}/ics')->name('ics.')->group(function () {
        Route::post('/establish', [EmergencyApiController::class, 'icsEstablish'])->name('establish');
        Route::post('/assign', [EmergencyApiController::class, 'icsAssign'])->name('assign');
        Route::post('/objectives', [EmergencyApiController::class, 'icsObjectives'])->name('objectives');
        Route::post('/resources', [EmergencyApiController::class, 'icsResource'])->name('resources');
        Route::post('/transfer', [EmergencyApiController::class, 'icsTransfer'])->name('transfer');
        Route::get('/forms/{form}', [EmergencyApiController::class, 'icsForm'])->whereIn('form', ['201', '202', '209', '214'])->name('form');
    });

    // تنبيهات الذعر: التفعيل لكل حساب، والمعالجة للمستجيبين
    Route::prefix('panic')->name('panic.')->group(function () {
        Route::post('/trigger', [PanicAlertController::class, 'trigger'])->name('trigger');
        Route::middleware('permission:emergency.respond,emergency.trigger')->group(function () {
            Route::get('/active', [PanicAlertController::class, 'active'])->name('active');
            Route::get('/recent', [PanicAlertController::class, 'recent'])->name('recent');
            Route::get('/stats', [PanicAlertController::class, 'stats'])->name('stats');
            Route::get('/{alert}', [PanicAlertController::class, 'show'])->name('show');
            Route::post('/{alert}/acknowledge', [PanicAlertController::class, 'acknowledge'])->name('acknowledge');
            Route::post('/{alert}/respond', [PanicAlertController::class, 'respond'])->name('respond');
            Route::post('/{alert}/resolve', [PanicAlertController::class, 'resolve'])->name('resolve');
            Route::get('/{alert}/recording', [PanicAlertController::class, 'recording'])->name('recording');
            Route::get('/{alert}/photo', [PanicAlertController::class, 'photo'])->name('photo');
        });
        Route::middleware('permission:emergency.trigger')->post('/{alert}/escalate', [PanicAlertController::class, 'escalate'])->name('escalate');
    });

    // الرسائل الجماعية والقوالب
    Route::prefix('messages')->name('messages.')->group(function () {
        Route::middleware('permission:emergency.trigger')->post('/send', [EmergencyMessageController::class, 'send'])->name('send');
        Route::middleware('permission:emergency.trigger')->post('/{message}/follow-up', [EmergencyMessageController::class, 'sendFollowUp'])->name('follow-up');
        Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
            Route::get('/recent', [EmergencyMessageController::class, 'recent'])->name('recent');
            Route::get('/{message}/stats', [EmergencyMessageController::class, 'stats'])->name('stats');
        });
        Route::get('/pending', [EmergencyMessageController::class, 'pending'])->name('pending');
        Route::get('/{message}', [EmergencyMessageController::class, 'show'])->name('show');
        Route::post('/{message}/respond', [EmergencyMessageController::class, 'respond'])->name('respond');
    });
    Route::middleware('permission:emergency.view,emergency.respond')->get('/incidents/{incident}/messages', [EmergencyMessageController::class, 'incidentMessages'])->name('incidents.messages');

    Route::prefix('templates')->name('templates.')->middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/', [MessageTemplateController::class, 'index'])->name('index');
        Route::get('/quick-send', [MessageTemplateController::class, 'quickSend'])->name('quick-send');
        Route::get('/category/{category}', [MessageTemplateController::class, 'byCategory'])->name('by-category');
        Route::get('/{template}', [MessageTemplateController::class, 'show'])->name('show');
        Route::post('/{template}/render', [MessageTemplateController::class, 'render'])->name('render');
        Route::middleware('permission:emergency.manage')->group(function () {
            Route::post('/', [MessageTemplateController::class, 'store'])->name('store');
            Route::put('/{template}', [MessageTemplateController::class, 'update'])->name('update');
            Route::delete('/{template}', [MessageTemplateController::class, 'destroy'])->name('destroy');
        });
    });

    // الزوار
    Route::prefix('visitors')->name('visitors.')->middleware('permission:emergency.respond,emergency.trigger')->group(function () {
        Route::post('/check-in', [VisitorController::class, 'checkIn'])->name('check-in');
        Route::post('/check-out-qr', [VisitorController::class, 'checkOutByQr'])->name('check-out-qr');
        Route::get('/search', [VisitorController::class, 'search'])->name('search');
        Route::get('/stats', [VisitorController::class, 'stats'])->name('stats');
        Route::get('/{visitor}', [VisitorController::class, 'show'])->name('show');
        Route::post('/{visitor}/check-out', [VisitorController::class, 'checkOut'])->name('check-out');
        Route::post('/{visitor}/mark-safe', [VisitorController::class, 'markSafe'])->name('mark-safe');
        Route::post('/{visitor}/mark-need-help', [VisitorController::class, 'markNeedHelp'])->name('mark-need-help');
        Route::get('/{visitor}/qr-code', [VisitorController::class, 'qrCode'])->name('qr-code');
        Route::get('/{visitor}/photo', [VisitorController::class, 'photo'])->name('photo');
    });
    Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/buildings/{building}/visitors', [VisitorController::class, 'inBuilding'])->name('buildings.visitors');
        Route::get('/buildings/{building}/visitors/today', [VisitorController::class, 'todayVisitors'])->name('buildings.visitors.today');
        Route::get('/buildings/{building}/visitors/evacuation-stats', [VisitorController::class, 'evacuationStats'])->name('buildings.visitors.evacuation-stats');
    });

    // الملفات الطبية: ملفي لكل حساب؛ الاطلاع للمستجيبين؛ التحقق للإدارة
    Route::prefix('medical')->name('medical.')->group(function () {
        Route::get('/my-profile', [MedicalProfileController::class, 'myProfile'])->name('my-profile');
        Route::put('/my-profile', [MedicalProfileController::class, 'updateMyProfile'])->name('my-profile.update');
        Route::get('/emergency-card', [MedicalProfileController::class, 'emergencyCard'])->name('emergency-card');
        Route::middleware('permission:emergency.respond,emergency.trigger')->group(function () {
            Route::get('/stats', [MedicalProfileController::class, 'stats'])->name('stats');
            Route::get('/needs-assistance', [MedicalProfileController::class, 'needsAssistance'])->name('needs-assistance');
            Route::get('/critical-info', [MedicalProfileController::class, 'criticalInfo'])->name('critical-info');
            Route::get('/needs-review', [MedicalProfileController::class, 'needsReview'])->name('needs-review');
            Route::get('/blood-type/{bloodType}', [MedicalProfileController::class, 'byBloodType'])->name('blood-type');
            Route::get('/users/{userId}', [MedicalProfileController::class, 'show'])->name('users.show');
            Route::get('/users/{userId}/for-responders', [MedicalProfileController::class, 'forResponders'])->name('users.for-responders');
        });
        Route::middleware('permission:emergency.manage')->group(function () {
            Route::post('/{profile}/verify', [MedicalProfileController::class, 'verify'])->name('verify');
            Route::post('/{profile}/reviewed', [MedicalProfileController::class, 'markReviewed'])->name('reviewed');
        });
    });

    // تقارير ما بعد الحادث
    Route::prefix('aar')->name('aar.')->group(function () {
        Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
            Route::get('/', [AfterActionReportController::class, 'index'])->name('index');
            Route::get('/stats', [AfterActionReportController::class, 'stats'])->name('stats');
            Route::get('/trends', [AfterActionReportController::class, 'trends'])->name('trends');
            Route::get('/actions/open', [AfterActionReportController::class, 'openActions'])->name('actions.open');
            Route::get('/actions/overdue', [AfterActionReportController::class, 'overdueActions'])->name('actions.overdue');
            Route::get('/{report}', [AfterActionReportController::class, 'show'])->name('show');
        });
        Route::middleware('permission:emergency.manage')->group(function () {
            Route::post('/incidents/{incident}', [AfterActionReportController::class, 'createFromIncident'])->name('create-from-incident');
            Route::put('/{report}', [AfterActionReportController::class, 'update'])->name('update');
            Route::post('/{report}/submit', [AfterActionReportController::class, 'submitForReview'])->name('submit');
            Route::post('/{report}/approve', [AfterActionReportController::class, 'approve'])->name('approve');
            Route::post('/{report}/publish', [AfterActionReportController::class, 'publish'])->name('publish');
            Route::post('/{report}/actions', [AfterActionReportController::class, 'addCorrectiveAction'])->name('actions.add');
            Route::put('/actions/{action}', [AfterActionReportController::class, 'updateCorrectiveAction'])->name('actions.update');
            Route::post('/actions/{action}/complete', [AfterActionReportController::class, 'completeAction'])->name('actions.complete');
        });
    });
});

// معلومات رمز الشخص بلا دخول (يظهر عند مسح الرمز بأي هاتف) — بلا هوية زائدة
Route::middleware(['web'])->prefix('api/emergency')->group(function () {
    Route::get('/qr/{token}', function (string $token) {
        $checkIn = EvacuationCheckIn::where('qr_token', $token)->first();
        if (!$checkIn) {
            return response()->json(['success' => false, 'message' => 'رمز غير صالح'], 404);
        }
        return response()->json(['success' => true, 'data' => [
            'person_name' => $checkIn->getPersonName(), 'person_type' => $checkIn->getPersonTypeLabel(),
            'status' => $checkIn->status, 'status_label' => $checkIn->getStatusLabel(), 'incident_id' => $checkIn->incident_id,
        ]]);
    })->name('api.emergency.qr.info');
    Route::get('/visitors/qr/{token}', [VisitorController::class, 'getByQr'])->name('api.emergency.visitors.qr');
});
