<?php

use App\Modules\Governance\Controllers\AuditLogsController;
use App\Modules\Governance\Controllers\CloseoutController;
use App\Modules\Governance\Controllers\HomeController;
use App\Modules\Governance\Controllers\MailController;
use App\Modules\Governance\Controllers\NotificationsController;
use App\Modules\Governance\Controllers\OrgUnitsController;
use App\Modules\Governance\Controllers\PlacesController;
use App\Modules\Governance\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * شاشات الوحدات تحت /app/… (BACKEND.md ٤-١). صفحات المعهد في الجذر كما هي.
 */
// ١٩-٧ (قرار ٤٨): «العمل اليومي» مخفية — الرابط القديم يُحوَّل إلى مقابله في الخلفية (الوسم #place= لا يصل الخادم فتحوّله الصفحة)
Route::middleware(['web', 'auth'])->get('/dashboard.html', \App\Modules\Governance\Controllers\DashboardMovedController::class)->name('dashboard.moved');
Route::middleware(['web', 'auth'])->prefix('app')->name('app.')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/inbox/count', [\App\Modules\Governance\Controllers\InboxController::class, 'count'])->name('inbox.count'); // ١١-٢: شارة «ما ينتظرك»
    Route::get('/inbox/open', [\App\Modules\Governance\Controllers\InboxController::class, 'open'])->name('inbox.open'); // ١١-٥: فتح مهمة يعلّم إشعارها مقروءاً
    Route::get('/search', [\App\Modules\Governance\Controllers\SearchController::class, 'index'])->name('search'); // ١١-٤: الباب الثاني
    Route::middleware('permission:system.settings')->get('/settings', [\App\Modules\Governance\Controllers\SettingsController::class, 'index'])->name('settings'); // ١١-٤: الباب الثالث

    Route::middleware('permission:system.users')->prefix('users')->name('users.')->group(function () {
        Route::get('/', [UsersController::class, 'index'])->name('index');
        Route::get('/create', [UsersController::class, 'create'])->name('create');
        Route::post('/', [UsersController::class, 'store'])->name('store');
        Route::get('/{user}/edit', [UsersController::class, 'edit'])->name('edit');
        Route::put('/{user}', [UsersController::class, 'update'])->name('update');
        Route::post('/{user}/toggle', [UsersController::class, 'toggle'])->name('toggle');
        Route::post('/{user}/reset-password', [UsersController::class, 'resetPassword'])->name('reset');
    });

    Route::middleware('permission:system.org')->prefix('org')->name('org.')->group(function () {
        Route::get('/', [OrgUnitsController::class, 'index'])->name('index');
        Route::get('/create', [OrgUnitsController::class, 'create'])->name('create');
        Route::post('/', [OrgUnitsController::class, 'store'])->name('store');
        Route::get('/{unit}/edit', [OrgUnitsController::class, 'edit'])->name('edit');
        Route::put('/{unit}', [OrgUnitsController::class, 'update'])->name('update');
        Route::delete('/{unit}', [OrgUnitsController::class, 'destroy'])->name('destroy');
    });

    // المرحلة ١٨-٣ (قرار ٤٧): وحدات الأماكن — القراءة لكل حساب، والتعديل بحسب صاحب المكان (PlaceUnit::canManage)
    Route::prefix('places')->name('places.units.')->group(function () {
        Route::get('/units', [\App\Modules\Governance\Controllers\PlaceUnitsController::class, 'hub'])->name('hub');
        // المرحلة ١٩-١ (قرار ٤٨): ملف المكان في الخلفية (ما كان في dashboard.html#place=)
        Route::get('/{place}/file', [\App\Modules\Governance\Controllers\PlaceFileController::class, 'show'])->name('file')->whereNumber('place');
        // المرحلة ١٩-٢: ملف النظام (ما كان في dashboard.html#place=…&sys=…)
        Route::get('/{place}/systems/{form}/{sys}', [\App\Modules\Governance\Controllers\PlaceFileController::class, 'system'])->name('system')->whereNumber('place');
        Route::get('/{place}/units', [\App\Modules\Governance\Controllers\PlaceUnitsController::class, 'index'])->name('index');
        Route::post('/{place}/units', [\App\Modules\Governance\Controllers\PlaceUnitsController::class, 'store'])->name('store');
        Route::post('/{place}/units/paste', [\App\Modules\Governance\Controllers\PlaceUnitsController::class, 'paste'])->name('paste');
        Route::put('/{place}/units/{unit}', [\App\Modules\Governance\Controllers\PlaceUnitsController::class, 'update'])->name('update');
        Route::delete('/{place}/units/{unit}', [\App\Modules\Governance\Controllers\PlaceUnitsController::class, 'destroy'])->name('destroy');
    });
    // المرحلة ١٩-٥ (قرار ٤٨): الفريق الأولي والخطتان وفرق الفعاليات من ملف المكان — الصلاحية في PlaceProfile (كما كانت في اللوحة)
    Route::prefix('places/{place}')->name('places.team.')->whereNumber('place')->group(function () {
        $c = \App\Modules\Governance\Controllers\PlaceTeamController::class;
        Route::post('/plans', [$c, 'plans'])->name('plans');
        Route::post('/team/{uid}/staff', [$c, 'staff'])->name('staff');
        Route::get('/team/{uid}/{k}', [$c, 'edit'])->name('edit')->whereNumber('k');
        Route::post('/team/{uid}/{k}', [$c, 'save'])->name('save')->whereNumber('k');
        Route::post('/team/{uid}/{k}/approve', [$c, 'approve'])->name('approve')->whereNumber('k');
        Route::post('/team/{uid}/{k}/refer', [$c, 'refer'])->name('refer')->whereNumber('k');
        Route::get('/events/{i}', [$c, 'eventEdit'])->name('event.edit');
        Route::post('/events/{i}', [$c, 'eventSave'])->name('event.save');
        Route::post('/events/{i}/approve', [$c, 'eventApprove'])->name('event.approve');
        Route::delete('/events/{i}', [$c, 'eventDelete'])->name('event.delete');
    });
    Route::middleware('permission:system.settings')->prefix('places')->name('places.')->group(function () {
        Route::get('/', [PlacesController::class, 'index'])->name('index');
        Route::get('/qr', [PlacesController::class, 'qr'])->name('qr');
        Route::get('/{code}/qr', [PlacesController::class, 'qr'])->name('qr.one');
        Route::put('/{place}', [PlacesController::class, 'update'])->name('update');
    });

    Route::middleware('permission:system.audit')->get('/audit', [AuditLogsController::class, 'index'])->name('audit');

    // البريد (المرحلة ٨-٢): حالة القناة الثانية ورسالة اختبار — يتحقق منها مسؤول السلامة بنفسه.
    Route::middleware('permission:system.settings')->prefix('mail')->name('mail.')->group(function () {
        Route::get('/', [MailController::class, 'index'])->name('index');
        Route::post('/test', [MailController::class, 'test'])->name('test');
    });

    // الإغلاق (المرحلة ٨-١): بديل سطر الأوامر الغائب على Render — الجرد ثم الحذف بكلمة تأكيد.
    Route::middleware('permission:system.settings')->prefix('closeout')->name('closeout.')->group(function () {
        Route::get('/', [CloseoutController::class, 'index'])->name('index');
        Route::post('/purge', [CloseoutController::class, 'purge'])->name('purge');
        Route::post('/demo-off', [CloseoutController::class, 'disableDemo'])->name('demo-off');
        Route::post('/book-replace', [CloseoutController::class, 'replaceBook'])->name('book-replace');
        Route::get('/backup', [CloseoutController::class, 'backupDownload'])->name('backup');
    });

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationsController::class, 'index'])->name('index');
        Route::get('/count', [NotificationsController::class, 'count'])->name('count');
        Route::post('/{notification}/read', [NotificationsController::class, 'markRead'])->name('read');
        Route::post('/read-all', [NotificationsController::class, 'markAllRead'])->name('read-all');
    });
});
