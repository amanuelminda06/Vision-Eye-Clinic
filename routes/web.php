<?php

use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ErrorController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientExportController;
use App\Http\Controllers\PatientImportController;
use App\Http\Controllers\PatientSickLeaveController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\UploadController;
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

/*
|--------------------------------------------------------------------------
| Patients (slice 2) — OWNER + RECEPTION only, server-side gated
|--------------------------------------------------------------------------
|
| Every route that exposes patient data is behind role:OWNER,RECEPTION; STAFF
| redirects to /403 (GET) or gets a hard 403 (mutations). The patient detail
| page and sick-leave tab read `tab` / `edit` query params.
|
*/
Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION'])->group(function () {
    // Uploaded blobs (patient ID photos, staff photos) — signed-in users only.
    Route::get('/uploads/{key}', [UploadController::class, 'show'])
        ->where('key', '[A-Za-z0-9][A-Za-z0-9._-]*')->name('uploads.show');

    // Global type-ahead (patient branch for now; sales/appointments in later slices).
    Route::get('/api/search', SearchController::class)->name('search');

    // CSV/XLSX export + import template (before /patients/{id} so they win).
    Route::get('/patients/export', [PatientExportController::class, 'export'])->name('patients.export');
    Route::get('/patients/template', [PatientExportController::class, 'template'])->name('patients.template');

    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients/new', [PatientController::class, 'create'])->name('patients.create');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');

    Route::get('/patients/import', [PatientImportController::class, 'show'])->name('patients.import');
    Route::post('/patients/import', [PatientImportController::class, 'store'])->name('patients.import.store');

    Route::get('/patients/{id}', [PatientController::class, 'show'])->name('patients.show');
    Route::get('/patients/{id}/edit', [PatientController::class, 'edit'])->name('patients.edit');
    Route::post('/patients/{id}', [PatientController::class, 'update'])->name('patients.update');

    Route::post('/patients/{id}/tags', [PatientController::class, 'setTags'])->name('patients.tags');
    Route::post('/patients/{id}/status', [PatientController::class, 'setActive'])->name('patients.status');
    Route::post('/patients/{id}/do-not-message', [PatientController::class, 'setDoNotMessage'])->name('patients.dnm');
    Route::post('/patients/{id}/photo', [PatientController::class, 'capturePhoto'])->name('patients.photo');

    Route::post('/patients/{id}/sick-leave', [PatientSickLeaveController::class, 'store'])->name('patients.sick-leave.store');
    Route::post('/patients/{id}/sick-leave/{sickId}', [PatientSickLeaveController::class, 'update'])->name('patients.sick-leave.update');
    Route::post('/patients/{id}/sick-leave/{sickId}/delete', [PatientSickLeaveController::class, 'destroy'])->name('patients.sick-leave.destroy');
});
