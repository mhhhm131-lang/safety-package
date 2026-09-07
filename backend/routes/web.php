<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

/*
 * الجذر: صفحات المعهد (HTML كما هي) تُقدَّم من public/ مباشرة.
 * الشاشة الرئيسية عند المستخدم هي vision.html (SOURCE.md §٥).
 */
Route::redirect('/', '/vision.html');

Route::get('/login', [LoginController::class, 'show'])->name('login');
/* حد المسار ٦٠/دقيقة لكل عنوان؛ والحد الفعلي ضد التخمين في المتحكم: ٥ محاولات فاشلة لكل اسم وعنوان */
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:60,1');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

/* الوحدات */
require app_path('Modules/Store/Routes/web.php');
