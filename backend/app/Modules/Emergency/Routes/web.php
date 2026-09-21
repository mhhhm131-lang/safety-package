<?php

use App\Modules\Emergency\Controllers\EmergencyController;
use App\Modules\Emergency\Models\EvacuationCheckIn;
use Illuminate\Support\Facades\Route;

/*
 * شاشات الطوارئ — تحت /app/emergency بجلسة المتصفح. permission: على كل مجموعة (لم تكن في OHSMS — خلل مؤكد ٥-٣):
 *   emergency.view    الاطلاع (الإدارة العليا، اللجنة، المديرون، القيادة، الإسناد…)
 *   emergency.respond الاستجابة الميدانية (يشمل الفني)
 *   emergency.trigger التفعيل والإنهاء والإغلاق الأمني
 *   emergency.manage  الإدارة (المبنى، جهات الاتصال، الإعدادات) — مسؤول السلامة والمناوب والمنسق
 *   emergency.drill / emergency.equipment / emergency.teams
 */
Route::middleware(['web', 'auth'])->prefix('app/emergency')->name('emergency.')->group(function () {

    // ٢٢-٢: شاشة الشخص وقت الحالة — لكل حساب بلا صلاحية طوارئ (عيب ع١: الموظف لا يرى الحالة إطلاقاً).
    // خارج كل `permission:` عمداً: ما فيها يخص صاحبها وحده (حالته هو، وصوله هو، طلبه هو).
    Route::get('/me', [EmergencyController::class, 'myEmergency'])->name('me');
    Route::post('/me/check-in', [EmergencyController::class, 'myCheckIn'])->name('me.check-in');
    Route::post('/me/help', [EmergencyController::class, 'myHelp'])->name('me.help');

    // ٢٢-٣: الاستغاثة لكل حساب بضغطة واحدة (كانت مبنية ولا تُرى إلا داخل لوحة المركز).
    Route::get('/sos', [EmergencyController::class, 'sos'])->name('sos');
    Route::post('/sos', [EmergencyController::class, 'sosTrigger'])->name('sos.trigger')->middleware('throttle:10,1');

    // ٢٢-٤: المستجيب يردّ بزر — «وصلتُ» من شاشته (عضو فريق في حالة مفتوحة؛ السياسة في المتحكّم)
    Route::post('/me/arrived', [EmergencyController::class, 'myArrived'])->name('me.arrived');

    // الاطلاع والاستجابة: من يملك view أو respond (الفني يصل ليسجّل وصوله ووصول فريقه)
    Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/', [EmergencyController::class, 'dashboard'])->name('dashboard');
        Route::get('/incidents', [EmergencyController::class, 'incidentsIndex'])->name('incidents.index');
        Route::get('/incidents/{incident}/live', [EmergencyController::class, 'incidentLive'])->name('incidents.live');
        Route::get('/incidents/{incident}/report', [EmergencyController::class, 'incidentReport'])->name('incidents.report');
        // خطط الاستجابة المشتقة من الوثائق (المرحلة ١٠-١) — اطلاع
        Route::get('/plans', [EmergencyController::class, 'plansIndex'])->name('plans.index');
        Route::get('/plans/{place}', [EmergencyController::class, 'plansShow'])->name('plans.show');
        Route::get('/buildings', [EmergencyController::class, 'buildingsIndex'])->name('buildings.index');
        Route::get('/buildings/{building}', [EmergencyController::class, 'buildingsShow'])->name('buildings.show')->whereNumber('building');
        Route::get('/buildings/{building}/control', [EmergencyController::class, 'buildingControl'])->name('buildings.control');
        Route::get('/teams', [EmergencyController::class, 'teamsIndex'])->name('teams.index');
        Route::get('/teams/{team}', [EmergencyController::class, 'teamsShow'])->name('teams.show')->whereNumber('team');
        Route::get('/contacts', [EmergencyController::class, 'contactsIndex'])->name('contacts.index');
        Route::get('/drills', [EmergencyController::class, 'drillsIndex'])->name('drills.index');
        Route::get('/equipment', [EmergencyController::class, 'equipmentIndex'])->name('equipment.index');
        Route::get('/analytics', [EmergencyController::class, 'analytics'])->name('analytics.index');
        Route::get('/analytics/export', [EmergencyController::class, 'analyticsExport'])->name('analytics.export');
        Route::get('/panic', [EmergencyController::class, 'panicDashboard'])->name('panic.dashboard');
        Route::get('/panic/{alert}', [EmergencyController::class, 'panicDetails'])->name('panic.show')->whereNumber('alert');
        Route::get('/visitors', [EmergencyController::class, 'visitorsDashboard'])->name('visitors.dashboard');
        Route::get('/visitors/kiosk', [EmergencyController::class, 'visitorsKiosk'])->name('visitors.kiosk');
        Route::get('/visitors/{building}', [EmergencyController::class, 'visitorsBuilding'])->name('visitors.building')->whereNumber('building');
    });

    // الاستجابة الميدانية أثناء الحالة (السياسة تتحقق من الكائن)
    Route::middleware('permission:emergency.respond,emergency.trigger')->prefix('incidents/{incident}')->name('incidents.')->group(function () {
        Route::post('/acknowledge', [EmergencyController::class, 'acknowledge'])->name('acknowledge');
        Route::post('/note', [EmergencyController::class, 'addNote'])->name('note');
        Route::post('/check-in', [EmergencyController::class, 'checkInManual'])->name('checkin');
        Route::post('/mark-missing', [EmergencyController::class, 'markMissing'])->name('markMissing');
        Route::post('/report-missing', [EmergencyController::class, 'reportMissingPerson'])->name('reportMissing');
        // خطوات خطة الاستجابة (المرحلة ١٠-٢): «تم» بيد المناوب أو صاحب الدور، أو تخطٍّ بسبب
        // ٢٢-٦: «عولج» يغلق طلب المساعدة فلا يبقى معلّقاً ويُعرف من عالجه
        Route::post('/help/{checkIn}/done', [EmergencyController::class, 'helpDone'])->name('help.done')->whereNumber('checkIn');
        Route::post('/steps/{step}/done', [EmergencyController::class, 'stepDone'])->name('steps.done')->whereNumber('step');
        Route::post('/steps/{step}/skip', [EmergencyController::class, 'stepSkip'])->name('steps.skip')->whereNumber('step');
    });

    // التفعيل والسيطرة والإنهاء والإلغاء والإغلاق الأمني
    Route::middleware('permission:emergency.trigger')->group(function () {
        Route::post('/buildings/{building}/trigger', [EmergencyController::class, 'triggerAlarm'])->name('buildings.trigger');
        Route::post('/incidents/{incident}/contain', [EmergencyController::class, 'contain'])->name('incidents.contain');
        Route::post('/incidents/{incident}/reactivate', [EmergencyController::class, 'reactivate'])->name('incidents.reactivate');
        Route::post('/incidents/{incident}/end', [EmergencyController::class, 'endIncident'])->name('incidents.end');
        Route::post('/buildings/{building}/lockdown', [EmergencyController::class, 'lockdownInitiate'])->name('buildings.lockdown');
        Route::post('/lockdowns/{lockdown}/lift', [EmergencyController::class, 'lockdownLift'])->name('lockdowns.lift');
        Route::post('/drills/{drill}/start', [EmergencyController::class, 'drillsStart'])->name('drills.start');
        Route::post('/drills/{drill}/end', [EmergencyController::class, 'drillsEnd'])->name('drills.end');
    });
    Route::middleware('permission:emergency.manage')->post('/incidents/{incident}/cancel', [EmergencyController::class, 'cancelIncident'])->name('incidents.cancel');

    // ٢٢-٤ (ع٤): ردّ المستجيب على تنبيه الذعر بزر — كانت الشاشة تعرض جدول المستجيبين بلا زر يجعل أحداً مستجيباً
    Route::middleware('permission:emergency.respond,emergency.trigger')
        ->post('/panic/{alert}/respond', [EmergencyController::class, 'panicRespond'])->name('panic.respond');

    // ٢٢-٤ (ع٥): «نوديَ» يغلق النداء الهاتفي — للمركز وحده (هو من ينادي)
    Route::middleware('permission:emergency.manage')
        ->post('/calls/{notification}/done', [EmergencyController::class, 'callDone'])->name('calls.done');

    // ٢٢-٧ (ع٧): تقرير ما بعد الحادث — أربع عشرة وظيفة كانت بلا شاشة
    Route::middleware('permission:emergency.view,emergency.respond')->group(function () {
        Route::get('/aar', [EmergencyController::class, 'aarIndex'])->name('aar.index');
    });
    // صاحب الإجراء التصحيحي قد يكون موظفاً بلا صلاحية طوارئ — يفتح تقريره ويغلق مهمته وحدها.
    // (كانا خلف `emergency.view` فتصله البطاقة ويُردّ عن زرّها — عيب كُشف ٢٠٢٦-٠٩-٢٢.)
    Route::get('/aar/{report}', [EmergencyController::class, 'aarShow'])->name('aar.show')->whereNumber('report');
    Route::post('/aar/actions/{action}/done', [EmergencyController::class, 'aarActionDone'])->name('aar.actions.done')->whereNumber('action');
    Route::middleware('permission:emergency.manage')->group(function () {
        Route::post('/incidents/{incident}/aar', [EmergencyController::class, 'aarCreate'])->name('incidents.aar');
        Route::post('/aar/{report}', [EmergencyController::class, 'aarUpdate'])->whereNumber('report');
        Route::post('/aar/{report}/submit', [EmergencyController::class, 'aarSubmit'])->name('aar.submit')->whereNumber('report');
        Route::post('/aar/{report}/approve', [EmergencyController::class, 'aarApprove'])->name('aar.approve')->whereNumber('report');
        Route::post('/aar/{report}/publish', [EmergencyController::class, 'aarPublish'])->name('aar.publish')->whereNumber('report');
        Route::post('/aar/{report}/actions', [EmergencyController::class, 'aarActionAdd'])->name('aar.actions.add')->whereNumber('report');
    });

    // ٢٢-٥ (ع٦): زر إرسال الرسالة الجماعية من شاشة الحالة — كان الردّ بزر والإرسال بلا زر
    Route::middleware('permission:emergency.trigger')->group(function () {
        Route::post('/incidents/{incident}/message', [EmergencyController::class, 'sendMessage'])->name('incidents.message');
        Route::post('/messages/{message}/follow-up', [EmergencyController::class, 'messageFollowUp'])->name('messages.follow-up');
    });

    // الإدارة: المبنى وطوابقه ومخارجه ونقاط تجمعه، جهات الاتصال، المهل
    Route::middleware('permission:emergency.manage')->group(function () {
        Route::get('/buildings/create', [EmergencyController::class, 'buildingsCreate'])->name('buildings.create');
        Route::post('/buildings', [EmergencyController::class, 'buildingsStore'])->name('buildings.store');
        Route::get('/buildings/{building}/edit', [EmergencyController::class, 'buildingsEdit'])->name('buildings.edit');
        Route::put('/buildings/{building}', [EmergencyController::class, 'buildingsUpdate'])->name('buildings.update');
        Route::post('/buildings/{building}/floors', [EmergencyController::class, 'floorsStore'])->name('buildings.floors.store');
        Route::put('/buildings/{building}/floors/{floor}', [EmergencyController::class, 'floorsUpdate'])->name('buildings.floors.update');
        Route::delete('/buildings/{building}/floors/{floor}', [EmergencyController::class, 'floorsDestroy'])->name('buildings.floors.destroy');
        Route::post('/buildings/{building}/exits', [EmergencyController::class, 'exitsStore'])->name('buildings.exits.store');
        Route::put('/buildings/{building}/exits/{exit}', [EmergencyController::class, 'exitsUpdate'])->name('buildings.exits.update');
        Route::delete('/buildings/{building}/exits/{exit}', [EmergencyController::class, 'exitsDestroy'])->name('buildings.exits.destroy');
        Route::post('/buildings/{building}/assembly-points', [EmergencyController::class, 'assemblyPointsStore'])->name('buildings.assembly-points.store');
        Route::put('/buildings/{building}/assembly-points/{point}', [EmergencyController::class, 'assemblyPointsUpdate'])->name('buildings.assembly-points.update');
        Route::delete('/buildings/{building}/assembly-points/{point}', [EmergencyController::class, 'assemblyPointsDestroy'])->name('buildings.assembly-points.destroy');

        Route::get('/contacts/create', [EmergencyController::class, 'contactsCreate'])->name('contacts.create');
        Route::post('/contacts', [EmergencyController::class, 'contactsStore'])->name('contacts.store');
        Route::get('/contacts/{contact}/edit', [EmergencyController::class, 'contactsEdit'])->name('contacts.edit');
        Route::put('/contacts/{contact}', [EmergencyController::class, 'contactsUpdate'])->name('contacts.update');
        Route::delete('/contacts/{contact}', [EmergencyController::class, 'contactsDestroy'])->name('contacts.destroy');

        Route::get('/settings', [EmergencyController::class, 'settingsEdit'])->name('settings');
        Route::post('/settings', [EmergencyController::class, 'settingsUpdate'])->name('settings.update');
        Route::post('/teams/sync', [EmergencyController::class, 'teamsSync'])->name('teams.sync');
        Route::post('/plans/sync', [EmergencyController::class, 'plansSync'])->name('plans.sync');
    });

    Route::middleware('permission:emergency.drill')->group(function () {
        Route::get('/drills/create', [EmergencyController::class, 'drillsCreate'])->name('drills.create');
        Route::post('/drills', [EmergencyController::class, 'drillsStore'])->name('drills.store');
        Route::post('/drills/{drill}/cancel', [EmergencyController::class, 'drillsCancel'])->name('drills.cancel');
    });

    Route::middleware('permission:emergency.equipment')->group(function () {
        Route::get('/equipment/create', [EmergencyController::class, 'equipmentCreate'])->name('equipment.create');
        Route::post('/equipment', [EmergencyController::class, 'equipmentStore'])->name('equipment.store');
        Route::post('/equipment/{equipment}/inspect', [EmergencyController::class, 'equipmentInspect'])->name('equipment.inspect');
    });

    Route::middleware('permission:emergency.teams')->group(function () {
        Route::get('/teams/create', [EmergencyController::class, 'teamsCreate'])->name('teams.create');
        Route::post('/teams', [EmergencyController::class, 'teamsStore'])->name('teams.store');
        Route::get('/teams/{team}/edit', [EmergencyController::class, 'teamsEdit'])->name('teams.edit');
        Route::put('/teams/{team}', [EmergencyController::class, 'teamsUpdate'])->name('teams.update');
        Route::delete('/teams/{team}', [EmergencyController::class, 'teamsDestroy'])->name('teams.destroy');
        Route::post('/teams/{team}/members', [EmergencyController::class, 'teamMembersStore'])->name('teams.members.store');
        Route::delete('/teams/{team}/members/{member}', [EmergencyController::class, 'teamMembersDestroy'])->name('teams.members.destroy');
    });

    // ٢٢-٦ب (قرار ٦٠): الملف الطبي الشخصي لكل حساب — يملؤه صاحبه اختياراً
    Route::get('/medical/my-profile', [EmergencyController::class, 'myMedicalProfile'])->name('medical.my-profile');

    // وملفات الناس للطبيب وحده، وكل اطّلاع يُسجَّل
    Route::middleware('permission:medical.read')->group(function () {
        Route::get('/medical', [EmergencyController::class, 'medicalDashboard'])->name('medical.dashboard');
        Route::get('/medical/users/{userId}', [EmergencyController::class, 'medicalShow'])->name('medical.show')->whereNumber('userId');
    });

    // مسح رمز الشخص عند نقطة التجمع (يفتح على جوال المنسق بعد الدخول) — يعرض هوية الرمز ويحيل إلى التسجيل
    Route::get('/checkin/{token}', function (string $token) {
        $checkIn = EvacuationCheckIn::where('qr_token', $token)->with('incident')->firstOrFail();
        return redirect()->route('emergency.incidents.live', $checkIn->incident)->with('scan_token', $token);
    })->name('checkin.verify');
});

// الواجهة البرمجية بجلسة المتصفح (كما /api/store) — ملف مستقل لا يحمّله ModuleServiceProvider تحت بادئة api/ الثانية
require __DIR__.'/api-web.php';
