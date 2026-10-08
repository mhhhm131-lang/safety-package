<?php

use App\Modules\Risk\Controllers\RiskController;
use App\Modules\Risk\Controllers\RiskTaxonomyController;
use App\Modules\Risk\Controllers\RiskTreeController;
use Illuminate\Support\Facades\Route;

/*
 * المخاطر تحت /app/risk — أسماء المسارات risk.* كما في OHSMS حتى تعمل الشاشات المنقولة.
 * الصلاحيات: risk.list للعرض والشجرة، risk.create للسجل العام، risk.activate لتفعيل خطر في سجل إدارة،
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
        Route::get('/{risk}/detail', [RiskController::class, 'show'])->name('show')->whereNumber('risk');
        Route::get('/{risk}/details', [RiskController::class, 'details'])->name('details')->whereNumber('risk');
        Route::post('/{risk}/notes', [RiskController::class, 'addNote'])->name('notes.store')->whereNumber('risk');
        // قرار ٦٩: الرفع وإعادة المرفوض للمسودة — لمن يملك الإنشاء، أو لصاحب الخطر ممن يملك سجل إدارته (الحارس في المتحكم)
        Route::post('/{risk}/submit', [RiskController::class, 'submit'])->name('submit');
        Route::post('/{risk}/change-status', [RiskController::class, 'changeStatus'])->name('changeStatus');

        // AJAX
        Route::get('/ajax/subcategories', [RiskController::class, 'ajaxSubcategories'])->name('ajax.subcategories');
        Route::get('/ajax/causes', [RiskController::class, 'ajaxCauses'])->name('ajax.causes');
        Route::get('/api/category-tree', [RiskController::class, 'ajaxCategoryTree'])->name('ajax.categoryTree');
        Route::get('/api/risks-by-registry', [RiskController::class, 'ajaxRisksByRegistry'])->name('ajax.risksByRegistry');

        // شجرة السجلين
        Route::prefix('registry/tree/{type}')->where(['type' => 'reference|active'])->name('registry.tree.')->group(function () {
            Route::get('/categories', [RiskTreeController::class, 'registryCategories'])->name('categories');
            Route::get('/sub-categories/{categoryId}', [RiskTreeController::class, 'registrySubCategories'])->name('subCategories');
            Route::get('/risks-by-sub-category/{subCatId}', [RiskTreeController::class, 'registryRisksBySubCategory'])->name('risksBySubCategory');
            Route::get('/risk/{riskId}', [RiskTreeController::class, 'registryRiskDetail'])->name('riskDetail');
        });
    });

    // السجل العام: إنشاء وتعديل (مسؤول السلامة، المناوب، منسق السلامة)
    Route::middleware('permission:risk.create')->group(function () {
        Route::get('/reference/create', [RiskController::class, 'referenceCreate'])->name('reference.create');
        Route::post('/reference/create', [RiskController::class, 'referenceStore'])->name('reference.store');
        Route::get('/reference/{risk}/edit', [RiskController::class, 'referenceEdit'])->name('reference.edit');
        Route::post('/reference/{risk}/edit', [RiskController::class, 'referenceUpdate'])->name('reference.update');

        Route::get('/create', [RiskController::class, 'create'])->name('create');
        Route::post('/create', [RiskController::class, 'store'])->name('store');
        Route::post('/{risk}/delete', [RiskController::class, 'destroy'])->name('destroy');

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
        // قرار ٧١: تفعيل المحدَّد من السجل العام دفعةً
        Route::post('/reference/activate-bulk', [RiskController::class, 'activateBulk'])->name('activate.bulk');
    });

    Route::middleware('permission:risk.approve')->group(function () {
        // خطة المعالج — الخطوة ١: مدير الإدارة المعالجة يسمّي المعالج من «إدارتي» (الحارس في المتحكم: مدير وحدة مربوط بوحدة)
        Route::get('/handlers', [\App\Modules\Risk\Controllers\HandlersController::class, 'index'])->name('handlers.index');
        Route::post('/handlers/{risk}', [\App\Modules\Risk\Controllers\HandlersController::class, 'set'])->name('handlers.set')->whereNumber('risk');
        Route::get('/approval/queue', [RiskController::class, 'approvalQueue'])->name('approval.queue');
        Route::post('/{risk}/approve', [RiskController::class, 'approve'])->name('approve');
        // قرار ٧١: «اعتمدها كلها» — ما يملك اعتماده مما ينتظر
        Route::post('/approve-bulk', [RiskController::class, 'approveBulk'])->name('approve.bulk');
        // المرحلة ١٨-٢: خطر فعلي جديد ← السجل العام بقرار مسؤول السلامة
        Route::post('/{risk}/to-reference', [RiskController::class, 'toReference'])->name('toReference')->whereNumber('risk');
        Route::post('/{risk}/reject', [RiskController::class, 'reject'])->name('reject');
        Route::post('/{risk}/request-modification', [RiskController::class, 'requestModification'])->name('requestModification');
    });
});

// قرار ٨٠: بديل الإدارة المعالجة في نسخة الفرع — يقترحه منسق الفرع (لا يملك risk.approve) ويعتمده مديره؛ الصلاحية داخل المتحكم
Route::middleware(array_merge(['web', 'auth'], ['permission:risk.list']))->prefix('app/risk')->name('risk.')->group(function () {
    Route::post('/{risk}/handling-override', [\App\Modules\Risk\Controllers\HandlingOverrideController::class, 'set'])->name('handling.override')->whereNumber('risk');
    Route::post('/{risk}/handling-override/approve', [\App\Modules\Risk\Controllers\HandlingOverrideController::class, 'approve'])->name('handling.override.approve')->whereNumber('risk');
});
