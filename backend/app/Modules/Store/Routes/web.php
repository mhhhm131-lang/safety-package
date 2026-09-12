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
