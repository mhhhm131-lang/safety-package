<?php

use App\Modules\Store\Controllers\StoreController;
use Illuminate\Support\Facades\Route;

/*
 * مسارات المخزن المركزي. تعمل بجلسة المتصفح نفسها (cookie + XSRF)،
 * فتعمل صفحات المعهد المقدَّمة من public/ بلا رموز API.
 */
// قرار المستخدم ٢٠٢٦-٠٩-١٣: «نماذج الفحص» — النماذج العشرة بضغطة من «أريد أن…»
Route::middleware(['web', 'auth'])->get('/app/inspections', [\App\Modules\Store\Controllers\InspectionsController::class, 'index'])->name('app.inspections');

Route::middleware(['web', 'auth'])->prefix('api/store')->name('store.')->group(function () {
    Route::get('/', [StoreController::class, 'index'])->name('index');
    Route::get('/{key}', [StoreController::class, 'show'])->name('show');
    Route::put('/{key}', [StoreController::class, 'put'])->name('put');
    Route::delete('/{key}', [StoreController::class, 'destroy'])->name('destroy');
});

// المرحلة ١٥-٤: اقتراح الحسابات في ترشيح الفريق الأولي باللوحة — لمن يرشّح أو يعتمد
Route::middleware(['web', 'auth'])->get('/api/team-accounts', [\App\Modules\Store\Controllers\TeamAccountsController::class, 'index'])->name('team-accounts');

// المرحلة ١٨-٣ (د): وحدات المكان لنماذج الفحص (اختيار القاعة/الغرفة في المحطة الأولى)
Route::middleware(['web', 'auth'])->get('/api/place-units', [\App\Modules\Store\Controllers\PlaceUnitsApiController::class, 'index'])->name('place-units');

// المرحلة ١٤: «الجولات السابقة» في النموذج — سجل السلامة للقراءة فقط
Route::middleware(['web', 'auth'])->prefix('api/inspection-rounds')->name('inspection-rounds.')->group(function () {
    Route::get('/', [\App\Modules\Store\Controllers\InspectionRoundsController::class, 'index'])->name('index');
    Route::get('/{id}', [\App\Modules\Store\Controllers\InspectionRoundsController::class, 'show'])->whereNumber('id')->name('show');
});
