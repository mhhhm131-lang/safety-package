<?php

use App\Modules\Incident\Controllers\IncidentController;
use App\Modules\Incident\Controllers\IncidentSettingsController;
use Illuminate\Support\Facades\Route;

// الصفحات العامة (بلا دخول) — تحل محل report.html. مرشح المعدل ١٠/ساعة لكل عنوان (OHSMS).
Route::get('/incident', [IncidentController::class, 'landing'])->name('incident.landing');
Route::get('/incident/track', [IncidentController::class, 'track'])->name('incident.track');
Route::post('/incident/track', [IncidentController::class, 'track'])->middleware('throttle:incident-public')->name('incident.track.post');
Route::get('/incident/success', [IncidentController::class, 'success'])->name('incident.success');
Route::get('/incident/api/sub-categories', [IncidentController::class, 'apiSubCategories'])->name('incident.api.sub-categories');
Route::get('/incident/api/risks', [IncidentController::class, 'apiRisks'])->name('incident.api.risks');
Route::get('/incident/{type}', [IncidentController::class, 'form'])->whereIn('type', ['normal', 'urgent', 'secret'])->name('incident.form');
Route::post('/incident/{type}', [IncidentController::class, 'store'])->whereIn('type', ['normal', 'urgent', 'secret'])->middleware('throttle:incident-public')->name('incident.store');

// شاشات مركز السلامة والمعالجة
Route::middleware(['auth'])->prefix('app/incidents')->name('incidents.')->group(function () {
    Route::middleware('permission:incident.list')->group(function () {
        Route::get('/', [IncidentController::class, 'index'])->name('index');
        Route::get('/export', [IncidentController::class, 'export'])->name('export');
        Route::post('/{incident}/add-note', [IncidentController::class, 'addNote'])->name('addNote');
    });
    Route::middleware('permission:system.settings')->group(function () {
        Route::get('/settings', [IncidentSettingsController::class, 'edit'])->name('settings');
        Route::post('/settings', [IncidentSettingsController::class, 'update'])->name('settings.update');
    });
    // بلا صلاحية قائمة: المبلّغ بحساب (موظف مثلاً) يرى بلاغه ويوافق على إغلاقه — الرؤية تحكمها VisibilityService
    Route::get('/{incident}', [IncidentController::class, 'show'])->name('show')->whereNumber('incident');
    Route::get('/{incident}/attachments/{attachment}', [IncidentController::class, 'attachment'])->name('attachment')->whereNumber('incident');
    Route::post('/{incident}/approve-closure', [IncidentController::class, 'approveClosure'])->name('approveClosure');

    Route::middleware('permission:incident.manage')->group(function () {
        Route::post('/{incident}/refer', [IncidentController::class, 'refer'])->name('refer');
        Route::post('/{incident}/close-with-note', [IncidentController::class, 'closeWithNote'])->name('closeWithNote');
        Route::post('/{incident}/field-receive', [IncidentController::class, 'fieldReceive'])->name('fieldReceive');
        Route::post('/{incident}/begin-work', [IncidentController::class, 'beginWork'])->name('beginWork');
        Route::post('/{incident}/upload', [IncidentController::class, 'upload'])->name('upload');
        Route::post('/{incident}/resolve', [IncidentController::class, 'resolve'])->name('resolve');
        Route::post('/{incident}/close', [IncidentController::class, 'close'])->name('close');
        Route::post('/{incident}/reject-closure', [IncidentController::class, 'rejectClosure'])->name('rejectClosure');
        Route::post('/{incident}/escalate-to-coord', [IncidentController::class, 'escalateToCoordinator'])->name('escalateToCoordinator');
        Route::post('/{incident}/escalate-to-manager', [IncidentController::class, 'escalateToManager'])->name('escalateToManager');
        Route::post('/{incident}/resolve-escalation', [IncidentController::class, 'resolveEscalation'])->name('resolveEscalation');
        Route::post('/{incident}/verify', [IncidentController::class, 'verifyByCoordinator'])->name('verify');
        Route::post('/{incident}/out-of-scope', [IncidentController::class, 'outOfScope'])->name('outOfScope');
        Route::post('/{incident}/link-risk', [IncidentController::class, 'linkRisk'])->name('linkRisk');
        Route::put('/{incident}/actions', [IncidentController::class, 'updateActions'])->name('updateActions');
    });
});
