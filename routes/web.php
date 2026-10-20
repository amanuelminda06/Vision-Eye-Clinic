<?php

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ErrorController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes (slice 1: foundation + auth)
|--------------------------------------------------------------------------
|
| `/` is the PUBLIC marketing site (never the ERP). The only login page is
| `/login/<LOGIN_SECRET_PATH>` — every other /login path is a real 404.
|
*/

Route::get('/', [HomeController::class, 'index'])->name('home');

// Secret-path login. Wrong secret → real 404 (scanners learn nothing).
Route::get('/login/{secret}', [LoginController::class, 'show'])->name('login');
Route::post('/login/{secret}', [LoginController::class, 'attempt'])->name('login.attempt');

Route::post('/logout', [LogoutController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/change-password', [ChangePasswordController::class, 'show'])->name('password.change');
    Route::post('/change-password', [ChangePasswordController::class, 'update'])->name('password.change.update');
});

// Friendly forbidden page (reached by redirect; no session required).
Route::get('/403', [ErrorController::class, 'forbidden'])->name('forbidden');

// App group: session first, then forced password change, then role gates.
Route::middleware(['auth', 'password.changed'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
});
