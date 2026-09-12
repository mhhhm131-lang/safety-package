<?php

use App\Modules\Risk\Controllers\RiskController;
use App\Modules\Risk\Controllers\RiskMasterController;
use App\Modules\Risk\Controllers\RiskTaxonomyController;
use App\Modules\Risk\Controllers\RiskTreeController;
use Illuminate\Support\Facades\Route;

/*
 * المخاطر تحت /app/risk — أسماء المسارات risk.* كما في OHSMS حتى تعمل الشاشات المنقولة.
 * الصلاحيات: risk.list للعرض والشجرة، risk.create للكتاب والسجل العام، risk.activate لتفعيل خطر في سجل إدارة،
 * risk.approve للاعتماد (BACKEND.md ٤-٣-ب).
 */
// المرحلة ١٢-٢ (قرار ٣٥): كتاب المعهد للتوعية — عام بلا دخول
Route::middleware(['web'])->get('/hazards', [\App\Modules\Risk\Controllers\HazardsController::class, 'index'])->name('hazards.index');

Route::middleware(['web', 'auth'])->prefix('app/risk')->name('risk.')->group(function () {

    Route::middleware('permission:risk.list')->group(function () {
        Route::get('/', [RiskController::class, 'index'])->name('index');
        Route::get('/export', [RiskController::class, 'export'])->name('export');
        Route::get('/active', [RiskController::class, 'activeIndex'])->name('active.index');
        Route::get('/reference', [RiskController::class, 'referenceIndex'])->name('reference.index');
        Route::get('/master', [RiskMasterController::class, 'index'])->name('master.index');
        Route::get('/{risk}/detail', [RiskController::class, 'show'])->name('show')->whereNumber('risk');
        Route::get('/{risk}/details', [RiskController::class, 'details'])->name('details')->whereNumber('risk');
        Route::post('/{risk}/notes', [RiskController::class, 'addNote'])->name('notes.store')->whereNumber('risk');

        // AJAX
        Route::get('/ajax/subcategories', [RiskController::class, 'ajaxSubcategories'])->name('ajax.subcategories');
        Route::get('/ajax/causes', [RiskController::class, 'ajaxCauses'])->name('ajax.causes');
        Route::get('/api/category-tree', [RiskController::class, 'ajaxCategoryTree'])->name('ajax.categoryTree');
        Route::get('/api/risks-by-registry', [RiskController::class, 'ajaxRisksByRegistry'])->name('ajax.risksByRegistry');

        // شجرة الكتاب
        Route::prefix('book/tree')->name('book.tree.')->group(function () {
            Route::get('/categories', [RiskTreeController::class, 'bookCategories'])->name('categories');
            Route::get('/sub-categories/{categoryId}', [RiskTreeController::class, 'bookSubCategories'])->name('subCategories');
            Route::get('/risks-by-sub-category/{subCatId}', [RiskTreeController::class, 'bookRisksBySubCategory'])->name('risksBySubCategory');
            Route::get('/risk/{risk}', [RiskTreeController::class, 'bookRiskDetail'])->name('riskDetail');
        });
        // شجرة السجلين
        Route::prefix('registry/tree/{type}')->where(['type' => 'reference|active'])->name('registry.tree.')->group(function () {
            Route::get('/categories', [RiskTreeController::class, 'registryCategories'])->name('categories');
            Route::get('/sub-categories/{categoryId}', [RiskTreeController::class, 'registrySubCategories'])->name('subCategories');
            Route::get('/risks-by-sub-category/{subCatId}', [RiskTreeController::class, 'registryRisksBySubCategory'])->name('risksBySubCategory');
            Route::get('/risk/{riskId}', [RiskTreeController::class, 'registryRiskDetail'])->name('riskDetail');
        });
    });

    // الكتاب والسجل العام: إنشاء وتعديل (مسؤول السلامة، المناوب، منسق السلامة)
    Route::middleware('permission:risk.create')->group(function () {
        Route::get('/master/template', [RiskMasterController::class, 'template'])->name('master.template');
        Route::get('/master/create', [RiskMasterController::class, 'create'])->name('master.create');
        Route::post('/master/create', [RiskMasterController::class, 'store'])->name('master.store');
        Route::get('/master/{risk}/edit', [RiskMasterController::class, 'edit'])->name('master.edit');
        Route::post('/master/{risk}/edit', [RiskMasterController::class, 'update'])->name('master.update');
        Route::post('/master/{risk}/delete', [RiskMasterController::class, 'destroy'])->name('master.destroy');
        Route::post('/master/import', [RiskMasterController::class, 'import'])->name('master.import');
        Route::post('/copy-from-master/{risk}', [RiskController::class, 'copyFromMaster'])->name('copyFromMaster');
        Route::post('/bulk-copy-from-master', [RiskController::class, 'bulkCopyFromMaster'])->name('bulkCopyFromMaster');

        Route::get('/reference/create', [RiskController::class, 'referenceCreate'])->name('reference.create');
        Route::post('/reference/create', [RiskController::class, 'referenceStore'])->name('reference.store');
        Route::get('/reference/{risk}/edit', [RiskController::class, 'referenceEdit'])->name('reference.edit');
        Route::post('/reference/{risk}/edit', [RiskController::class, 'referenceUpdate'])->name('reference.update');

        Route::get('/create', [RiskController::class, 'create'])->name('create');
        Route::post('/create', [RiskController::class, 'store'])->name('store');
        Route::post('/{risk}/delete', [RiskController::class, 'destroy'])->name('destroy');
        Route::post('/{risk}/submit', [RiskController::class, 'submit'])->name('submit');
        Route::post('/{risk}/change-status', [RiskController::class, 'changeStatus'])->name('changeStatus');

        Route::post('/taxonomy/subcategory', [RiskTaxonomyController::class, 'storeSubcategory'])->name('taxonomy.subcategory.store');
        Route::post('/taxonomy/cause', [RiskTaxonomyController::class, 'storeCause'])->name('taxonomy.cause.store');
    });

    // سجل الإدارة: التفعيل والتعديل (مدير الإدارة لإدارته)
    Route::middleware('permission:risk.activate')->group(function () {
        Route::get('/active/create', [RiskController::class, 'activeCreate'])->name('active.create');
        Route::post('/active/create', [RiskController::class, 'activeStore'])->name('active.store');
        Route::get('/active/{risk}/edit', [RiskController::class, 'activeEdit'])->name('active.edit');
        Route::post('/active/{risk}/edit', [RiskController::class, 'activeUpdate'])->name('active.update');
        Route::get('/{risk}/update', [RiskController::class, 'edit'])->name('edit')->whereNumber('risk');
        Route::post('/{risk}/update', [RiskController::class, 'update'])->name('update')->whereNumber('risk');
        Route::get('/{risk}/activate', [RiskController::class, 'activateForm'])->name('activate.form')->whereNumber('risk');
        Route::post('/{risk}/activate', [RiskController::class, 'activate'])->name('activate')->whereNumber('risk');
    });

    Route::middleware('permission:risk.approve')->group(function () {
        Route::get('/approval/queue', [RiskController::class, 'approvalQueue'])->name('approval.queue');
        Route::post('/{risk}/approve', [RiskController::class, 'approve'])->name('approve');
        Route::post('/{risk}/reject', [RiskController::class, 'reject'])->name('reject');
        Route::post('/{risk}/request-modification', [RiskController::class, 'requestModification'])->name('requestModification');
    });
});
