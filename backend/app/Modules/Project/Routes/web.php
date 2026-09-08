<?php

use App\Modules\Project\Controllers\ContractorHomeController;
use App\Modules\Project\Controllers\ContractorPortalController;
use App\Modules\Project\Controllers\ContractorProfileController;
use App\Modules\Project\Controllers\ExternalPartyController;
use App\Modules\Project\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;

/*
 * المرحلة ٦ — المشاريع والمقاولون (من OHSMS: نفس الأسماء، تحت /app، بلا plan.limits). الصلاحيات من جدول ٤-٣-ب:
 *   project.list/create/edit — external_party.list/create/edit/evaluate — integration.manage لإعدادات القنوات.
 * حساب المقاول (contractor/contractor_supervisor/consultant_office) يملك list فيرى طرفه ومشاريعه فقط (AppliesOrgUnitScope::scopeToExternalParty).
 */
Route::middleware(['web', 'auth'])->prefix('app')->group(function () {
    Route::prefix('projects')->name('projects.')->middleware('permission:project.list')->group(function () {
        Route::get('/', [ProjectController::class, 'index'])->name('index');
        Route::get('/create', [ProjectController::class, 'create'])->name('create')->middleware('permission:project.create');
        Route::post('/', [ProjectController::class, 'store'])->name('store')->middleware('permission:project.create');
        Route::get('/{project}', [ProjectController::class, 'show'])->name('show')->whereNumber('project');
        Route::get('/{project}/edit', [ProjectController::class, 'edit'])->name('edit')->middleware('permission:project.edit');
        Route::put('/{project}', [ProjectController::class, 'update'])->name('update')->middleware('permission:project.edit');
        Route::get('/{project}/dashboard', [ProjectController::class, 'dashboard'])->name('dashboard');
        Route::get('/{project}/risks', [ProjectController::class, 'risks'])->name('risks');
        Route::get('/{project}/contractors', [ProjectController::class, 'contractors'])->name('contractors');
        Route::get('/{project}/contractors/assign', [ProjectController::class, 'assignContractor'])->name('contractors.assign')->middleware('permission:project.edit');
        Route::post('/{project}/contractors/assign', [ProjectController::class, 'storeContractor'])->name('contractors.store')->middleware('permission:project.edit');
        Route::post('/{project}/contractors/quick-party', [ProjectController::class, 'quickCreateParty'])->name('contractors.quick-party')->middleware('permission:external_party.create');
        Route::get('/{project}/contractors/new', [ProjectController::class, 'createNewContractor'])->name('contractors.new')->middleware('permission:external_party.create');
        Route::post('/{project}/contractors/new', [ProjectController::class, 'storeNewContractor'])->name('contractors.store-new')->middleware('permission:external_party.create');
        Route::post('/{project}/contractors/{contractor}/transition', [ProjectController::class, 'transitionContractor'])->name('contractors.transition');
        Route::get('/{project}/comparison', [ProjectController::class, 'comparison'])->name('comparison');
        Route::get('/{project}/manhours', [ProjectController::class, 'manhours'])->name('manhours');
        Route::post('/{project}/manhours', [ProjectController::class, 'manhourStore'])->name('manhours.store')->middleware('permission:project.edit');
    });

    Route::prefix('external-parties')->name('external-parties.')->middleware('permission:external_party.list')->group(function () {
        Route::get('/', [ExternalPartyController::class, 'index'])->name('index');
        Route::get('/create', [ExternalPartyController::class, 'create'])->name('create')->middleware('permission:external_party.create');
        Route::post('/', [ExternalPartyController::class, 'store'])->name('store')->middleware('permission:external_party.create');
        Route::get('/{externalParty}', [ExternalPartyController::class, 'show'])->name('show')->whereNumber('externalParty');
        Route::get('/{externalParty}/edit', [ExternalPartyController::class, 'edit'])->name('edit')->middleware('permission:external_party.edit');
        Route::put('/{externalParty}', [ExternalPartyController::class, 'update'])->name('update')->middleware('permission:external_party.edit');
        Route::get('/{externalParty}/risks', [ExternalPartyController::class, 'risks'])->name('risks');
        Route::post('/{externalParty}/risks', [ExternalPartyController::class, 'addRisk'])->name('risks.store')->middleware('permission:external_party.edit');
        Route::get('/{externalParty}/documents', [ExternalPartyController::class, 'documents'])->name('documents');
        Route::post('/{externalParty}/documents', [ExternalPartyController::class, 'addDocument'])->name('documents.store'); // المقاول يرفع مستنداته أيضاً
        Route::get('/{externalParty}/documents/{document}', [ExternalPartyController::class, 'downloadDocument'])->name('documents.download');
        Route::post('/{externalParty}/documents/{document}/verify', [ExternalPartyController::class, 'verifyDocument'])->name('documents.verify')->middleware('permission:external_party.edit');
        Route::get('/{externalParty}/evaluation/create', [ExternalPartyController::class, 'evaluationCreate'])->name('evaluation.create')->middleware('permission:external_party.evaluate');
        Route::post('/{externalParty}/evaluation', [ExternalPartyController::class, 'evaluationStore'])->name('evaluation.store')->middleware('permission:external_party.evaluate');
        Route::get('/{externalParty}/profile', [ContractorProfileController::class, 'show'])->name('profile');
        Route::put('/{externalParty}/profile', [ContractorProfileController::class, 'update'])->name('profile.update')->middleware('permission:external_party.edit');
        Route::post('/{externalParty}/enrich', [ContractorProfileController::class, 'enrich'])->name('enrich')->middleware('permission:external_party.edit');
        Route::post('/{externalParty}/portal-link', [ContractorProfileController::class, 'generatePortalLink'])->name('portal-link')->middleware('permission:external_party.edit');
    });

    Route::prefix('settings/contractor-channels')->middleware('permission:integration.manage')->group(function () {
        Route::get('/', [ContractorProfileController::class, 'channelSettings'])->name('settings.contractor-channels');
        Route::post('/', [ContractorProfileController::class, 'saveChannelSettings'])->name('settings.contractor-channels.save');
    });

    // بوابة المقاول بحساب: الملف والمشاريع والعمال والمستندات والتأهيل (التصاريح في ٦-ب)
    Route::get('contractor', [ContractorHomeController::class, 'index'])->name('contractor.home')->middleware('contractor');
});

// رابط التعبئة بلا حساب (رمز لمرة واحدة، ٧ أيام) — مرشح ١٠/دقيقة كما في OHSMS
Route::middleware(['web', 'throttle:10,1'])->prefix('contractor-portal')->name('contractor-portal.')->group(function () {
    Route::get('/{token}', [ContractorPortalController::class, 'show'])->name('show');
    Route::post('/{token}/submit', [ContractorPortalController::class, 'submit'])->name('submit');
    Route::get('/{token}/done', [ContractorPortalController::class, 'done'])->name('done');
});
