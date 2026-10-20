<?php

use App\Http\Controllers\MessageApiController;
use App\Http\Controllers\PublicBookingController;
use App\Http\Controllers\PublicDoctorsController;
use App\Http\Controllers\PublicFeedbackController;
use App\Http\Controllers\PublicImageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes (session-free)
|--------------------------------------------------------------------------
|
| These routes live in the `api` middleware group: no session, no CSRF, no
| authentication. Only genuinely public data belongs here — the doctors feed
| returns a strict allow-list projection (never patient data) and the booking
| intake creates an appointment for a visitor who never signed in.
|
*/

Route::get('/public/doctors', PublicDoctorsController::class)->name('public.doctors');

Route::post('/public/requests', PublicBookingController::class)->name('public.requests');

Route::post('/public/feedback', PublicFeedbackController::class)->name('public.feedback');

// The two narrow public image routes (uploaded blobs are never exposed
// directly — only through these, gated by the publication flags).
Route::get('/public/doctors/{id}/photo', [PublicImageController::class, 'doctorPhoto'])
    ->name('public.doctors.photo');

Route::get('/public/services/{id}/image', [PublicImageController::class, 'serviceImage'])
    ->name('public.services.image');

// The cron entry for the message jobs (bearer CRON_SECRET).
Route::post('/jobs/messages', [MessageApiController::class, 'jobs'])->name('jobs.messages');
