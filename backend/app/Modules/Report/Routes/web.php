<?php

use App\Modules\Report\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| مسارات التقارير (المرحلة ٧-ب)
|--------------------------------------------------------------------------
| صلاحية واحدة `report.view` بأدوار BACKEND.md ٤-٣: مسؤول السلامة والمناوب،
| المدير العام والنواب، لجنة السلامة، مديرو الفرع والإدارة والقسم، المنسق،
| ورؤساء الشؤون الإدارية والمرافق والأمن والسلامة.
*/

Route::middleware(['web', 'auth'])->prefix('app')->group(function () {
    Route::prefix('reports')->name('reports.')->middleware('permission:report.view')->group(function () {
        Route::get('/', [ReportController::class, 'dashboard'])->name('dashboard');
        Route::get('/incidents', [ReportController::class, 'incidents'])->name('incidents');
        Route::get('/risks', [ReportController::class, 'risks'])->name('risks');
        Route::get('/export', [ReportController::class, 'export'])->name('export');
    });
});
