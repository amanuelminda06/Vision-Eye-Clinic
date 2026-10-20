<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentExportController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\DailySummaryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ErrorController;
use App\Http\Controllers\FollowUpController;
use App\Http\Controllers\LeaveCalendarController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LeaveDecisionController;
use App\Http\Controllers\LeaveExportController;
use App\Http\Controllers\MessageApiController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MessageLibraryController;
use App\Http\Controllers\MessageReportController;
use App\Http\Controllers\MessageTemplateController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientExportController;
use App\Http\Controllers\PatientImportController;
use App\Http\Controllers\PatientSickLeaveController;
use App\Http\Controllers\PublicSiteController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleExportController;
use App\Http\Controllers\SaleVoidController;
use App\Http\Controllers\ScheduleController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShiftTemplateController;
use App\Http\Controllers\SiteContentController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UserController;
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

/*
|--------------------------------------------------------------------------
| Public website (slice 7) — no session, no patient data, no prices
|--------------------------------------------------------------------------
|
| The public set is exactly `/`, `/about`, `/services`, `/doctors`, `/book`,
| `/contact`, `/faq` and `/privacy` (plus the sitemap and robots files).
|
*/
Route::get('/', [PublicSiteController::class, 'home'])->name('site.home');
Route::get('/about', [PublicSiteController::class, 'about'])->name('site.about');
Route::get('/services', [PublicSiteController::class, 'services'])->name('site.services');
Route::get('/doctors', [PublicSiteController::class, 'doctors'])->name('site.doctors');
Route::get('/book', [PublicSiteController::class, 'book'])->name('site.book');
Route::get('/contact', [PublicSiteController::class, 'contact'])->name('site.contact');
Route::get('/faq', [PublicSiteController::class, 'faq'])->name('site.faq');
Route::get('/privacy', [PublicSiteController::class, 'privacy'])->name('site.privacy');
Route::get('/sitemap.xml', [PublicSiteController::class, 'sitemap'])->name('site.sitemap');
Route::get('/robots.txt', [PublicSiteController::class, 'robots'])->name('site.robots');

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

/*
|--------------------------------------------------------------------------
| Sales — OWNER-only surfaces (revenue, void inbox, service catalogue)
|--------------------------------------------------------------------------
|
| This group is registered BEFORE the OWNER+RECEPTION group so that
| `/sales/voids` is not shadowed by `/sales/{id}`. RECEPTION and STAFF hitting
| these routes are redirected to /403 (wrong role on a GET).
|
*/
Route::middleware(['auth', 'password.changed', 'role:OWNER'])->group(function () {
    Route::get('/revenue', [RevenueController::class, 'index'])->name('revenue.index');

    Route::get('/sales/voids', [SaleVoidController::class, 'index'])->name('sales.voids');
    Route::post('/sales/{id}/void', [SaleVoidController::class, 'void'])->name('sales.void');
    Route::post('/sales/{id}/void-approve', [SaleVoidController::class, 'approve'])->name('sales.void-approve');
    Route::post('/sales/{id}/void-reject', [SaleVoidController::class, 'reject'])->name('sales.void-reject');

    // Service catalogue (prices) is OWNER-only.
    Route::get('/dashboard/services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/dashboard/services/new', [ServiceController::class, 'create'])->name('services.create');
    Route::post('/dashboard/services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('/dashboard/services/{id}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::post('/dashboard/services/{id}', [ServiceController::class, 'update'])->name('services.update');
    Route::post('/dashboard/services/{id}/status', [ServiceController::class, 'setActive'])->name('services.status');
});

/*
|--------------------------------------------------------------------------
| Appointments + follow-ups (slice 5) — OWNER + RECEPTION only
|--------------------------------------------------------------------------
|
| The board is a normal POST form (no client-side framework): the server
| recomputes every warning, the status graph and the receipt of the request.
| The public booking intake lives in routes/api.php (no session required).
|
*/
Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION'])->group(function () {
    Route::get('/appointments/export', AppointmentExportController::class)->name('appointments.export');

    Route::get('/appointments', [AppointmentController::class, 'index'])->name('appointments.index');
    Route::get('/appointments/new', [AppointmentController::class, 'create'])->name('appointments.create');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    Route::get('/appointments/{id}/edit', [AppointmentController::class, 'edit'])->name('appointments.edit');
    Route::post('/appointments/{id}', [AppointmentController::class, 'update'])->name('appointments.update');
    Route::post('/appointments/{id}/status', [AppointmentController::class, 'setStatus'])->name('appointments.status');

    Route::get('/follow-ups', [FollowUpController::class, 'index'])->name('followups.index');
    Route::get('/follow-ups/new', [FollowUpController::class, 'create'])->name('followups.create');
    Route::post('/follow-ups', [FollowUpController::class, 'store'])->name('followups.store');
    Route::post('/follow-ups/{id}/status', [FollowUpController::class, 'setStatus'])->name('followups.status');
});

/*
|--------------------------------------------------------------------------
| Messaging (slice 6) — manual send only
|--------------------------------------------------------------------------
|
| The app never transmits anything: it prepares `sms:` links the operator
| opens on the clinic phone. The badge count and the export live in the
| session-free API group (the badge needs a session, the export needs the
| message role).
|
*/
Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION'])->group(function () {
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::post('/messages/{id}/open', [MessageController::class, 'open'])->name('messages.open');
    Route::post('/messages/{id}/sent', [MessageController::class, 'sent'])->name('messages.sent');
    Route::post('/messages/{id}/postpone', [MessageController::class, 'postpone'])->name('messages.postpone');
    Route::post('/messages/{id}/skip', [MessageController::class, 'skip'])->name('messages.skip');
    Route::post('/messages/{id}/edit', [MessageController::class, 'edit'])->name('messages.edit');

    Route::get('/messages/drafts', [MessageLibraryController::class, 'drafts'])->name('messages.drafts');
    Route::post('/messages/drafts', [MessageLibraryController::class, 'storeDraft'])->name('messages.drafts.store');
    Route::post('/messages/drafts/{id}/delete', [MessageLibraryController::class, 'destroyDraft'])->name('messages.drafts.destroy');

    Route::get('/messages/library', [MessageLibraryController::class, 'index'])->name('messages.library');
    Route::post('/messages/library', [MessageLibraryController::class, 'store'])->name('messages.library.store');
    Route::post('/messages/library/{id}/toggle', [MessageLibraryController::class, 'toggle'])->name('messages.library.toggle');

    Route::get('/messages/campaigns', [CampaignController::class, 'index'])->name('messages.campaigns');
    Route::get('/messages/campaigns/new', [CampaignController::class, 'create'])->name('messages.campaigns.new');
    Route::post('/messages/campaigns', [CampaignController::class, 'store'])->name('messages.campaigns.store');
    Route::get('/messages/campaigns/{id}', [CampaignController::class, 'show'])->name('messages.campaigns.show');
    Route::post('/messages/campaigns/{id}/batches/{batchId}', [CampaignController::class, 'batch'])->name('messages.campaigns.batch');
});

// Template editing, reports and the export: OWNER only.
Route::middleware(['auth', 'password.changed', 'role:OWNER'])->group(function () {
    Route::get('/messages/templates', [MessageTemplateController::class, 'index'])->name('messages.templates');
    Route::post('/messages/templates', [MessageTemplateController::class, 'store'])->name('messages.templates.store');
    Route::get('/messages/reports', [MessageReportController::class, 'index'])->name('messages.reports');
    Route::get('/messages/export', [MessageApiController::class, 'export'])->name('messages.export');

    // The website CMS + the feedback moderation.
    Route::get('/dashboard/content', [SiteContentController::class, 'index'])->name('site.content');
    Route::post('/dashboard/content', [SiteContentController::class, 'store'])->name('site.content.store');
    Route::get('/dashboard/feedback', [SiteContentController::class, 'feedback'])->name('site.feedback');
    Route::post('/dashboard/feedback/{id}/toggle', [SiteContentController::class, 'toggleFeedback'])->name('site.feedback.toggle');
    Route::post('/dashboard/feedback/{id}/delete', [SiteContentController::class, 'destroyFeedback'])->name('site.feedback.destroy');

    // Users, staff, settings, audit and the daily summary.
    Route::get('/users', [UserController::class, 'index'])->name('users.index');
    Route::get('/users/new', [UserController::class, 'create'])->name('users.create');
    Route::post('/users', [UserController::class, 'store'])->name('users.store');
    Route::get('/users/{id}', [UserController::class, 'edit'])->name('users.edit');
    Route::post('/users/{id}', [UserController::class, 'update'])->name('users.update');
    Route::post('/users/{id}/status', [UserController::class, 'setStatus'])->name('users.status');
    Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset');
    Route::post('/users/{id}/set-password', [UserController::class, 'setPassword'])->name('users.set-password');

    Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
    Route::get('/staff/new', [StaffController::class, 'create'])->name('staff.create');
    Route::post('/staff', [StaffController::class, 'store'])->name('staff.store');
    Route::get('/staff/{id}', [StaffController::class, 'edit'])->name('staff.edit');
    Route::post('/staff/{id}', [StaffController::class, 'update'])->name('staff.update');
    Route::post('/staff/{id}/status', [StaffController::class, 'setStatus'])->name('staff.status');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingsController::class, 'store'])->name('settings.store');

    Route::get('/audit', [AuditController::class, 'index'])->name('audit.index');
    Route::get('/audit/export', [AuditController::class, 'export'])->name('audit.export');

    Route::get('/dashboard/daily', [DailySummaryController::class, 'index'])->name('dashboard.daily');
});

/*
|--------------------------------------------------------------------------
| HR (slice 4) — schedule, leave requests, inbox, calendar, export
|--------------------------------------------------------------------------
|
| /schedule and /leave are open to every role (STAFF sees only its own rows
| on the schedule). The team calendar is OWNER + RECEPTION, the inbox and every
| shift/template mutation is OWNER-only.
|
*/
Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION,STAFF'])->group(function () {
    Route::get('/schedule', [ScheduleController::class, 'index'])->name('schedule.index');
    Route::get('/leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('/leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('/leave/{id}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
    Route::get('/leave/export', LeaveExportController::class)->name('leave.export');
});

// The team leave calendar: OWNER + RECEPTION.
Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION'])->group(function () {
    Route::get('/leave/calendar', [LeaveCalendarController::class, 'index'])->name('leave.calendar');
});

// The approval inbox and every shift/template mutation: OWNER only.
Route::middleware(['auth', 'password.changed', 'role:OWNER'])->group(function () {
    Route::get('/leave/inbox', [LeaveDecisionController::class, 'index'])->name('leave.inbox');
    Route::post('/leave/{id}/approve', [LeaveDecisionController::class, 'approve'])->name('leave.approve');
    Route::post('/leave/{id}/reject', [LeaveDecisionController::class, 'reject'])->name('leave.reject');

    Route::post('/schedule/shifts', [ScheduleController::class, 'store'])->name('schedule.shifts.store');
    Route::post('/schedule/shifts/{id}', [ScheduleController::class, 'update'])->name('schedule.shifts.update');
    Route::post('/schedule/shifts/{id}/delete', [ScheduleController::class, 'destroy'])->name('schedule.shifts.destroy');

    Route::post('/schedule/templates', [ShiftTemplateController::class, 'store'])->name('schedule.templates.store');
    Route::post('/schedule/templates/{id}/delete', [ShiftTemplateController::class, 'destroy'])->name('schedule.templates.destroy');
    Route::post('/schedule/templates/preview', [ShiftTemplateController::class, 'preview'])->name('schedule.templates.preview');
    Route::post('/schedule/templates/apply', [ShiftTemplateController::class, 'apply'])->name('schedule.templates.apply');
    Route::post('/schedule/templates/copy-last-week', [ShiftTemplateController::class, 'copyLastWeek'])->name('schedule.templates.copy');
});

/*
|--------------------------------------------------------------------------
| Sales (slice 3) — OWNER + RECEPTION only, server-side gated
|--------------------------------------------------------------------------
|
| The front desk creates and confirms sales; confirmed sales are immutable and
| the only transition is the void flow (slice 3's SaleVoidController). STAFF
| never reaches any money surface.
|
*/
Route::middleware(['auth', 'password.changed', 'role:OWNER,RECEPTION'])->group(function () {
    // Export (before /sales/{id} so it wins).
    Route::get('/sales/export', SaleExportController::class)->name('sales.export');

    Route::get('/sales', [SaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/new', [SaleController::class, 'create'])->name('sales.create');
    Route::post('/sales', [SaleController::class, 'store'])->name('sales.store');

    // Filing a void request is allowed for RECEPTION too (the OWNER reviews it).
    Route::post('/sales/{id}/void-request', [SaleVoidController::class, 'request'])->name('sales.void-request');

    Route::get('/sales/{id}', [SaleController::class, 'show'])->name('sales.show');
});
