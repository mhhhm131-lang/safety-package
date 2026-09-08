<?php

use App\Modules\Governance\Controllers\AuditLogsController;
use App\Modules\Governance\Controllers\HomeController;
use App\Modules\Governance\Controllers\NotificationsController;
use App\Modules\Governance\Controllers\OrgUnitsController;
use App\Modules\Governance\Controllers\PlacesController;
use App\Modules\Governance\Controllers\UsersController;
use Illuminate\Support\Facades\Route;

/*
 * شاشات الوحدات تحت /app/… (BACKEND.md ٤-١). صفحات المعهد في الجذر كما هي.
 */
Route::middleware(['web', 'auth'])->prefix('app')->name('app.')->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');

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

    Route::middleware('permission:system.settings')->prefix('places')->name('places.')->group(function () {
        Route::get('/', [PlacesController::class, 'index'])->name('index');
        Route::get('/qr', [PlacesController::class, 'qr'])->name('qr');
        Route::get('/{code}/qr', [PlacesController::class, 'qr'])->name('qr.one');
        Route::put('/{place}', [PlacesController::class, 'update'])->name('update');
    });

    Route::middleware('permission:system.audit')->get('/audit', [AuditLogsController::class, 'index'])->name('audit');

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationsController::class, 'index'])->name('index');
        Route::get('/count', [NotificationsController::class, 'count'])->name('count');
        Route::post('/{notification}/read', [NotificationsController::class, 'markRead'])->name('read');
        Route::post('/read-all', [NotificationsController::class, 'markAllRead'])->name('read-all');
    });
});
