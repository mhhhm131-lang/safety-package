<?php

use App\Modules\Worker\Controllers\CompetencyController;
use App\Modules\Worker\Controllers\WorkerController;
use Illuminate\Support\Facades\Route;

/*
 * المرحلة ٦ — عمال المقاولين والكفاءات (من OHSMS، تحت /app، بلا plan.limits).
 *   worker.list — worker.create/edit (مشرف المقاول أيضاً، لعمال طرفه) — worker.approve/manage (مسؤول السلامة والمناوب والمنسق)
 *   competency.view/manage
 */
Route::middleware(['web', 'auth'])->prefix('app')->group(function () {
    Route::prefix('workers')->name('workers.')->middleware('permission:worker.list')->group(function () {
        Route::get('/search', [WorkerController::class, 'search'])->name('search');
        Route::get('/', [WorkerController::class, 'index'])->name('index');
        Route::get('/approval-queue', [WorkerController::class, 'approvalQueue'])->name('approval-queue')->middleware('permission:worker.approve');
        Route::get('/create', [WorkerController::class, 'create'])->name('create')->middleware('permission:worker.create');
        Route::post('/', [WorkerController::class, 'store'])->name('store')->middleware('permission:worker.create');
        Route::get('/{worker}', [WorkerController::class, 'show'])->name('show')->whereNumber('worker');
        Route::get('/{worker}/edit', [WorkerController::class, 'edit'])->name('edit')->middleware('permission:worker.edit');
        Route::put('/{worker}', [WorkerController::class, 'update'])->name('update')->middleware('permission:worker.edit');
        Route::post('/{worker}/transition', [WorkerController::class, 'transition'])->name('transition')->middleware('permission:worker.edit,worker.manage');
        Route::get('/{worker}/documents', [WorkerController::class, 'documents'])->name('documents');
        Route::post('/{worker}/documents', [WorkerController::class, 'addDocument'])->name('documents.store')->middleware('permission:worker.edit');
        Route::get('/{worker}/documents/{document}', [WorkerController::class, 'downloadDocument'])->name('documents.download');
        Route::post('/{worker}/training', [WorkerController::class, 'addTraining'])->name('training.store')->middleware('permission:worker.manage');
    });

    Route::prefix('competency')->name('competency.')->middleware('permission:competency.view')->group(function () {
        Route::get('/matrix', [CompetencyController::class, 'matrix'])->name('matrix');
        Route::get('/trades', [CompetencyController::class, 'trades'])->name('trades');
        Route::post('/trades', [CompetencyController::class, 'storeTrade'])->name('trades.store')->middleware('permission:competency.manage');
        Route::post('/trades/{trade}/toggle', [CompetencyController::class, 'toggleTrade'])->name('trades.toggle')->middleware('permission:competency.manage');
        Route::post('/toggle-requirement', [CompetencyController::class, 'toggleRequirement'])->name('toggle-requirement')->middleware('permission:competency.manage');
        Route::get('/worker/{worker}', [CompetencyController::class, 'workerCompliance'])->name('worker');
        Route::get('/contractor/{externalParty}', [CompetencyController::class, 'contractorCompliance'])->name('contractor');
    });
});
