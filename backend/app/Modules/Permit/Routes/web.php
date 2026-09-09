<?php

use App\Modules\Permit\Controllers\EquipmentController;
use App\Modules\Permit\Controllers\GateController;
use App\Modules\Permit\Controllers\PermitController;
use App\Modules\Permit\Controllers\PermitDashboardController;
use Illuminate\Support\Facades\Route;

/*
 * المرحلة ٦-ب — التصاريح (BACKEND.md ٥-٦). كلها تحت /app بجلسة المتصفح.
 * الصلاحيات من جدول ٤-٣-ب: permit.list/create/review/safety_approve/final_approve/activate/edit/cancel/zones
 * و epc.manage للمعدات. (إصلاح خطأ OHSMS: مسارات Hub كانت بلا `permission:` — auth وحده.)
 *
 * حساب الطرف الخارجي (مقاول/مشرف/مكتب استشاري) يرى تصاريح طرفه فقط عبر
 * AppliesOrgUnitScope::scopeToExternalParty في المتحكم، وPermitPolicy تمنع فتح تصريح طرف آخر.
 */
Route::middleware(['web', 'auth'])->prefix('app')->group(function () {

    Route::prefix('permits')->name('permits.')->middleware('permission:permit.list')->group(function () {
        // اللوحة والتقرير والإعدادات — قبل /{permit} حتى لا تُلتقط كمعرّف
        Route::get('/dashboard', [PermitDashboardController::class, 'index'])->name('dashboard');
        Route::get('/report', [PermitDashboardController::class, 'monthlyReport'])->name('report');
        Route::get('/queue', [PermitController::class, 'queue'])->name('queue');

        Route::middleware('permission:permit.zones')->group(function () {
            Route::get('/settings', [PermitDashboardController::class, 'settings'])->name('settings');
            Route::put('/settings/places/{place}', [PermitDashboardController::class, 'updateCapacity'])->name('settings.capacity');
            Route::post('/settings/conflict-rules', [PermitDashboardController::class, 'storeConflictRule'])->name('settings.rules.store');
            Route::post('/settings/conflict-rules/{rule}/toggle', [PermitDashboardController::class, 'toggleConflictRule'])->name('settings.rules.toggle');
        });

        // شاشة جاهزية العامل وسجلّها
        Route::get('/gate', [GateController::class, 'screen'])->name('gate');
        Route::post('/gate/check', [GateController::class, 'check'])->name('gate.check');
        Route::get('/gate/logs', [GateController::class, 'logs'])->name('gate.logs');

        // المعالج
        Route::get('/create', [PermitController::class, 'create'])->name('create')->middleware('permission:permit.create');
        Route::post('/', [PermitController::class, 'store'])->name('store')->middleware('permission:permit.create');

        Route::get('/', [PermitController::class, 'index'])->name('index');

        // تصريح واحد
        Route::get('/{permit}', [PermitController::class, 'show'])->name('show')->whereNumber('permit');
        Route::get('/{permit}/review', [PermitController::class, 'review'])->name('review')->whereNumber('permit');
        Route::get('/{permit}/edit', [PermitController::class, 'edit'])->name('edit')->whereNumber('permit')->middleware('permission:permit.edit');
        Route::put('/{permit}', [PermitController::class, 'update'])->name('update')->whereNumber('permit')->middleware('permission:permit.edit');
        Route::get('/{permit}/activate', [PermitController::class, 'activate'])->name('activate')->whereNumber('permit')->middleware('permission:permit.activate');
        Route::post('/{permit}/transition', [PermitController::class, 'transition'])->name('transition')->whereNumber('permit');

        // البنود وأدلتها
        Route::post('/{permit}/requirements/{requirement}/complete', [PermitController::class, 'completeRequirement'])->name('requirements.complete');
        Route::post('/{permit}/requirements/{requirement}/toggle', [PermitController::class, 'toggleRequirement'])->name('requirements.toggle');
        Route::post('/{permit}/requirements/{requirement}/waive', [PermitController::class, 'waiveRequirement'])->name('requirements.waive');
        Route::get('/{permit}/requirements/{requirement}/evidence', [PermitController::class, 'downloadEvidence'])->name('requirements.evidence');

        // المخاطر والعمال والمرفقات
        Route::post('/{permit}/risks', [PermitController::class, 'attachRisk'])->name('risks.attach');
        Route::post('/{permit}/workers', [PermitController::class, 'assignWorker'])->name('workers.assign');
        Route::delete('/{permit}/workers/{worker}', [PermitController::class, 'removeWorker'])->name('workers.remove');
        Route::post('/{permit}/attachments', [PermitController::class, 'uploadAttachment'])->name('attachments.store');
        Route::get('/{permit}/attachments/{attachment}', [PermitController::class, 'downloadAttachment'])->name('attachments.download');
        Route::delete('/{permit}/attachments/{attachment}', [PermitController::class, 'deleteAttachment'])->name('attachments.delete');

        // الانحرافات والتقييم البعدي
        Route::post('/{permit}/deviations', [PermitController::class, 'recordDeviation'])->name('deviations.store');
        Route::post('/{permit}/deviations/{deviation}/resolve', [PermitController::class, 'resolveDeviation'])->name('deviations.resolve');
        Route::get('/{permit}/evaluate', [PermitController::class, 'evaluate'])->name('evaluate')->whereNumber('permit');
        Route::post('/{permit}/evaluate', [PermitController::class, 'saveEvaluation'])->name('evaluate.save')->whereNumber('permit');
    });

    // المعدات (من EPC) — صلاحية epc.manage للعرض والتعديل
    Route::prefix('equipment')->name('equipment.')->middleware('permission:epc.manage')->group(function () {
        Route::get('/', [EquipmentController::class, 'index'])->name('index');
        Route::get('/create', [EquipmentController::class, 'create'])->name('create');
        Route::post('/', [EquipmentController::class, 'store'])->name('store');
        Route::get('/{equipment}', [EquipmentController::class, 'show'])->name('show')->whereNumber('equipment');
        Route::get('/{equipment}/edit', [EquipmentController::class, 'edit'])->name('edit')->whereNumber('equipment');
        Route::put('/{equipment}', [EquipmentController::class, 'update'])->name('update')->whereNumber('equipment');
        Route::post('/{equipment}/inspections', [EquipmentController::class, 'storeInspection'])->name('inspections.store');
    });
});
