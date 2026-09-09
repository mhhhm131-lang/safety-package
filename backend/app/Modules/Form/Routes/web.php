<?php

use App\Modules\Form\Controllers\FormController;
use Illuminate\Support\Facades\Route;

/*
 * المرحلة ٧ — النماذج الرقمية (BACKEND.md ٥-٨). كلها تحت /app بجلسة المتصفح.
 * الصلاحيات من جدول ٤-٣-ب: form.list/create/edit/results/send/track.
 *
 * **التعبئة ليست خلف `permission:`** لأن المكلَّف قد يكون موظفاً بلا صلاحيات إدارية —
 * حارسها `FormPolicy::fill` (مكلَّف بالنموذج، والنموذج مفعَّل، ولم يعبّئه من قبل).
 * (في OHSMS كانت التعبئة مفتوحة لأي مستخدم مسجَّل بلا تحقق من التكليف.)
 */
Route::middleware(['web', 'auth'])->prefix('app')->group(function () {

    Route::prefix('forms')->name('forms.')->group(function () {

        // «نماذجي» والتعبئة — لكل من له حساب، والحارس في السياسة
        Route::get('/mine', [FormController::class, 'mine'])->name('mine');
        Route::get('/{form}/fill', [FormController::class, 'fill'])->name('fill')->whereNumber('form');
        Route::post('/{form}/fill', [FormController::class, 'submit'])->name('submit')->whereNumber('form');
        Route::get('/{form}/submitted', [FormController::class, 'submitted'])->name('submitted')->whereNumber('form');
        Route::get('/{form}/answers/{answer}/file', [FormController::class, 'answerFile'])->name('answers.file');

        // إدارة النماذج
        Route::middleware('permission:form.list')->group(function () {
            Route::get('/', [FormController::class, 'index'])->name('index');

            Route::middleware('permission:form.create')->group(function () {
                Route::get('/create', [FormController::class, 'create'])->name('create');
                Route::post('/', [FormController::class, 'store'])->name('store');
                Route::get('/generate', [FormController::class, 'generateForm'])->name('generate');
                Route::post('/generate', [FormController::class, 'generate'])->name('generate.store');
            });

            Route::get('/{form}', [FormController::class, 'show'])->name('show')->whereNumber('form');

            Route::middleware('permission:form.edit')->group(function () {
                Route::get('/{form}/edit', [FormController::class, 'edit'])->name('edit')->whereNumber('form');
                Route::put('/{form}', [FormController::class, 'update'])->name('update')->whereNumber('form');
                Route::post('/{form}/toggle', [FormController::class, 'toggleActive'])->name('toggle')->whereNumber('form');
                Route::post('/{form}/fields', [FormController::class, 'storeField'])->name('fields.store');
                Route::put('/{form}/fields/{field}', [FormController::class, 'updateField'])->name('fields.update');
                Route::delete('/{form}/fields/{field}', [FormController::class, 'destroyField'])->name('fields.destroy');
                Route::post('/{form}/fields/reorder', [FormController::class, 'reorderFields'])->name('fields.reorder');
            });

            Route::middleware('permission:form.send')->group(function () {
                Route::get('/{form}/send', [FormController::class, 'sendForm'])->name('send')->whereNumber('form');
                Route::post('/{form}/send', [FormController::class, 'send'])->name('send.store')->whereNumber('form');
                Route::post('/{form}/assignments/{assignment}/remind', [FormController::class, 'remind'])->name('remind');
                Route::post('/{form}/remind-all', [FormController::class, 'remindAll'])->name('remind-all');
            });

            Route::get('/{form}/tracking', [FormController::class, 'tracking'])->name('tracking')
                ->whereNumber('form')->middleware('permission:form.track');

            Route::middleware('permission:form.results')->group(function () {
                Route::get('/{form}/results', [FormController::class, 'results'])->name('results')->whereNumber('form');
                Route::get('/{form}/export', [FormController::class, 'export'])->name('export')->whereNumber('form');
            });
        });
    });
});
