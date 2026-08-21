<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\CreditApplication\AppointmentController;
use App\Http\Controllers\CreditApplication\ClientController;
use App\Http\Controllers\CreditApplication\CreditApplicationController;
use App\Http\Controllers\CreditApplication\CreditRequestController;
use App\Http\Controllers\CreditApplication\DocumentController;
use App\Http\Controllers\CreditApplication\NotificationController;
use App\Http\Controllers\CreditApplication\ProjectController;
use App\Http\Controllers\CreditApplication\ReportController;
use App\Http\Controllers\CreditApplication\ValidationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\Staff\ActivityController as StaffActivityController;
use App\Http\Controllers\Staff\ApplicationController as StaffApplicationController;
use App\Http\Controllers\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\NotificationController as StaffNotificationController;
use App\Http\Controllers\Staff\ReportController as StaffReportController;
use App\Http\Controllers\Staff\StaffDocumentController;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Route;

// Public readiness probe for load balancers and uptime monitors — no auth, no
// secrets, see App\Http\Controllers\HealthController.
Route::get('health', HealthController::class);

Route::prefix('auth')->group(function () {
    Route::post('register', [RegisterController::class, 'register'])
        ->middleware('throttle:bts:6');
    Route::post('verify-registration-otp', [RegisterController::class, 'verifyOtp'])
        ->middleware('throttle:bts:10');

    Route::post('login', [LoginController::class, 'login'])
        ->middleware('throttle:bts:6');
    Route::post('login/verify-otp', [LoginController::class, 'verifyOtp'])
        ->middleware('throttle:bts:10');

    Route::post('google', [GoogleAuthController::class, 'authenticate'])
        ->middleware('throttle:bts:10');
    Route::post('google/set-phone', [GoogleAuthController::class, 'setPhone'])
        ->middleware('throttle:bts:10');
    Route::post('google/verify-otp', [GoogleAuthController::class, 'verifyOtp'])
        ->middleware('throttle:bts:10');

    Route::post('logout', [LogoutController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::post('password/forgot', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:bts:6');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:bts:6');
});

Route::middleware('auth:sanctum')->get('user', function () {
    return response()->json(['success' => true, 'data' => ['user' => new UserResource(request()->user())]]);
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('applications')->group(function () {
    Route::get('/', [CreditApplicationController::class, 'index'])
        ->middleware('permission:application.view');
    Route::post('/', [CreditApplicationController::class, 'store'])
        ->middleware(['permission:application.create', 'throttle:bts:10']);
    Route::get('{application}', [CreditApplicationController::class, 'show'])
        ->middleware('permission:application.view');

    Route::put('{application}/client', [ClientController::class, 'update'])
        ->middleware(['permission:application.update', 'throttle:bts:30']);
    Route::put('{application}/credit', [CreditRequestController::class, 'update'])
        ->middleware(['permission:application.update', 'throttle:bts:30']);
    Route::put('{application}/project', [ProjectController::class, 'update'])
        ->middleware(['permission:application.update', 'throttle:bts:30']);

    Route::post('{application}/documents', [DocumentController::class, 'store'])
        ->middleware(['permission:application.update', 'throttle:bts:20']);
    Route::delete('{application}/documents/{document}', [DocumentController::class, 'destroy'])
        ->middleware(['permission:application.update', 'throttle:bts:20']);
    // Customer download: ownership policy + document/application match in the controller;
        // file streams from the private documents disk — no storage URL is ever exposed.
    Route::get('{application}/documents/{document}', [DocumentController::class, 'download'])
        ->middleware('permission:application.view');

    Route::post('{application}/validation-1', [ValidationController::class, 'validationOne'])
        ->middleware(['permission:application.update', 'throttle:bts:20']);
    Route::post('{application}/validation-2', [ValidationController::class, 'validationTwo'])
        ->middleware(['permission:application.update', 'throttle:bts:20']);

    Route::post('{application}/submit', [CreditApplicationController::class, 'submit'])
        ->middleware(['permission:application.update', 'throttle:bts:10']);
    Route::post('{application}/cancel', [CreditApplicationController::class, 'cancel'])
        ->middleware(['permission:application.update', 'throttle:bts:10']);

    Route::get('{application}/appointment', [AppointmentController::class, 'show'])
        ->middleware('permission:appointment.manage');
    Route::post('{application}/appointment/accept', [AppointmentController::class, 'accept'])
        ->middleware(['permission:appointment.manage', 'throttle:bts:20']);
    Route::post('{application}/appointment/reject', [AppointmentController::class, 'reject'])
        ->middleware(['permission:appointment.manage', 'throttle:bts:20']);

    Route::get('{application}/report/messages', [ReportController::class, 'index'])
        ->middleware('permission:reports.view');
    Route::post('{application}/report/messages', [ReportController::class, 'store'])
        ->middleware(['permission:reports.view', 'throttle:bts:30']);
    Route::get('{application}/report/messages/{message}/attachment', [ReportController::class, 'downloadAttachment'])
        ->middleware('permission:reports.view');
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index']);
    Route::post('mark-read', [NotificationController::class, 'markAsRead'])
        ->middleware('throttle:bts:20');
});

Route::prefix('staff')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])
        ->middleware('throttle:bts:6');
    // Separate entrance for administrators only — staff accounts get 403 here (and vice versa
    // on the plain login above), so each portal only hands out tokens for its own role.
    Route::post('admin/login', [StaffAuthController::class, 'adminLogin'])
        ->middleware('throttle:bts:6');

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout']);

        Route::get('applications', [StaffApplicationController::class, 'index'])
            ->middleware('permission:application.review');
        Route::get('applications/{application}', [StaffApplicationController::class, 'show'])
            ->middleware('permission:application.review');
        // Staff/admin document download: branch scoping + under-review check in the
        // controller; file streams from the private documents disk, never a public URL.
        Route::get('applications/{application}/documents/{document}', [StaffDocumentController::class, 'download'])
            ->middleware('permission:application.review');
        Route::post('applications/{application}/approve', [StaffApplicationController::class, 'approve'])
            ->middleware(['permission:application.approve', 'throttle:bts:20']);
        Route::post('applications/{application}/reject', [StaffApplicationController::class, 'reject'])
            ->middleware(['permission:application.reject', 'throttle:bts:20']);
        Route::post('applications/{application}/cancel', [StaffApplicationController::class, 'staffCancel'])
            ->middleware(['permission:application.approve', 'throttle:bts:20']);

        // Logs & traffic — gated on audit.view for both roles, but ActivityLogService narrows
        // rows and columns by the authenticated staff user's role/branch, so "staff can enter"
        // never means "staff sees customer IPs and auth events".
        Route::get('activity', [StaffActivityController::class, 'index'])
            ->middleware('permission:audit.view');
        Route::get('activity/traffic', [StaffActivityController::class, 'traffic'])
            ->middleware('permission:audit.view');

        Route::get('branches', [StaffReportController::class, 'branches'])
            ->middleware('permission:reports.view');
        Route::get('reports', [StaffReportController::class, 'index'])
            ->middleware('permission:reports.view');
        Route::get('reports/{application}/messages', [StaffReportController::class, 'show'])
            ->middleware('permission:reports.view');
        Route::post('reports/{application}/messages', [StaffReportController::class, 'store'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);
        Route::get('reports/{application}/messages/{message}/attachment', [StaffReportController::class, 'downloadAttachment'])
            ->middleware('permission:reports.view');
        Route::post('reports/{application}/close', [StaffReportController::class, 'closeThread'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);
        Route::post('reports/{application}/reopen', [StaffReportController::class, 'reopenThread'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);
        Route::post('reports/{application}/appointment', [StaffReportController::class, 'scheduleAppointment'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);

        Route::get('appointments', [\App\Http\Controllers\Staff\AppointmentManagementController::class, 'index'])
            ->middleware('permission:reports.view');
        Route::get('branches/overview', [\App\Http\Controllers\Staff\AppointmentManagementController::class, 'branchesOverview'])
            ->middleware('permission:reports.view');

        Route::get('banned-users', [\App\Http\Controllers\Staff\UserBanController::class, 'index'])
            ->middleware('permission:reports.view');
        Route::post('reports/{application}/ban-client', [\App\Http\Controllers\Staff\UserBanController::class, 'banFromApplication'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);
        Route::post('users/{user}/unban', [\App\Http\Controllers\Staff\UserBanController::class, 'unban'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);

        Route::get('notifications', [StaffNotificationController::class, 'index']);
        Route::post('notifications/mark-read', [StaffNotificationController::class, 'markAsRead'])
            ->middleware('throttle:bts:20');

        // Admin-only final decision: the role gate (staff.role:admin) keeps this portal-level
        // exclusive, the permission gates express the capability (final_approve is granted to no
        // one below admin in PermissionRegistry). Belt and suspenders on purpose.
        Route::middleware('staff.role:admin')->group(function () {
            Route::get('dashboard', [StaffDashboardController::class, 'index']);

            Route::post('applications/{application}/admin-approve', [StaffApplicationController::class, 'adminApprove'])
                ->middleware(['permission:application.final_approve', 'throttle:bts:20']);
            Route::post('applications/{application}/admin-reject', [StaffApplicationController::class, 'adminReject'])
                ->middleware(['permission:application.reject', 'throttle:bts:20']);
            Route::post('applications/{application}/admin-cancel', [StaffApplicationController::class, 'adminCancel'])
                ->middleware(['permission:application.reject', 'throttle:bts:20']);
        });
    });
});
