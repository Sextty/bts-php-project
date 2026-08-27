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
use App\Http\Controllers\LoadTestStatusController;
use App\Http\Controllers\Security\SecurityCenterController;
use App\Http\Controllers\Staff\ActivityController as StaffActivityController;
use App\Http\Controllers\Staff\AnalyticsController;
use App\Http\Controllers\Staff\ApplicationController as StaffApplicationController;
use App\Http\Controllers\Staff\AppointmentManagementController;
use App\Http\Controllers\Staff\AuthController as StaffAuthController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use App\Http\Controllers\Staff\NotificationController as StaffNotificationController;
use App\Http\Controllers\Staff\OsqueryAuditController;
use App\Http\Controllers\Staff\ReportController as StaffReportController;
use App\Http\Controllers\Staff\StaffDocumentController;
use App\Http\Controllers\Staff\UserBanController;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Route;

require __DIR__.'/api/v1/banking.php';

// Public liveness/readiness probes for load balancers and uptime monitors.
Route::get('health/live', [HealthController::class, 'live']);
Route::get('health', HealthController::class);
Route::get('load-test/status', LoadTestStatusController::class);

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

Route::middleware(['auth:sanctum', 'customer'])->get('user', function () {
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
    Route::post('admin/login', [StaffAuthController::class, 'adminLogin'])
        ->middleware('throttle:bts:6');

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout']);

        Route::get('applications', [StaffApplicationController::class, 'index'])
            ->middleware('permission:application.review');
        Route::get('applications/{application}', [StaffApplicationController::class, 'show'])
            ->middleware('permission:application.review');
        Route::get('applications/{application}/documents/{document}', [StaffDocumentController::class, 'download'])
            ->middleware('permission:application.review');
        Route::post('applications/{application}/approve', [StaffApplicationController::class, 'approve'])
            ->middleware(['permission:application.approve', 'throttle:bts:20']);
        Route::post('applications/{application}/reject', [StaffApplicationController::class, 'reject'])
            ->middleware(['permission:application.reject', 'throttle:bts:20']);
        Route::post('applications/{application}/cancel', [StaffApplicationController::class, 'staffCancel'])
            ->middleware(['permission:application.approve', 'throttle:bts:20']);

        // Activity log for admin / staff (staff only if they hold audit.view)
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

        Route::get('appointments', [AppointmentManagementController::class, 'index'])
            ->middleware('permission:reports.view');
        Route::get('branches/overview', [AppointmentManagementController::class, 'branchesOverview'])
            ->middleware('permission:reports.view');

        Route::prefix('analytics')->middleware('permission:analytics.view')->group(function () {
            Route::get('overview', [AnalyticsController::class, 'overview']);
            Route::get('export', [AnalyticsController::class, 'export'])
                ->middleware(['permission:analytics.export', 'throttle:bts:10']);
        });

        Route::get('banned-users', [UserBanController::class, 'index'])
            ->middleware('permission:reports.view');
        Route::post('reports/{application}/ban-client', [UserBanController::class, 'banFromApplication'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);
        Route::post('users/{user}/unban', [UserBanController::class, 'unban'])
            ->middleware(['permission:reports.view', 'throttle:bts:30']);

        Route::get('notifications', [StaffNotificationController::class, 'index']);
        Route::post('notifications/mark-read', [StaffNotificationController::class, 'markAsRead'])
            ->middleware('throttle:bts:20');

        // Admin-only routes
        Route::middleware('staff.role:admin')->group(function () {
            Route::get('dashboard', [StaffDashboardController::class, 'index']);
            Route::get('analytics/data-quality', [AnalyticsController::class, 'dataQuality'])
                ->middleware('permission:analytics.view');

            Route::post('applications/{application}/admin-approve', [StaffApplicationController::class, 'adminApprove'])
                ->middleware(['permission:application.final_approve', 'throttle:bts:20']);
            Route::post('applications/{application}/admin-reject', [StaffApplicationController::class, 'adminReject'])
                ->middleware(['permission:application.reject', 'throttle:bts:20']);
            Route::post('applications/{application}/admin-cancel', [StaffApplicationController::class, 'adminCancel'])
                ->middleware(['permission:application.reject', 'throttle:bts:20']);

            // Osquery live telemetry and system inspection (Admin audit journal)
            Route::prefix('insights/osquery')->group(function () {
                Route::get('status', [OsqueryAuditController::class, 'status'])
                    ->middleware('permission:audit.view');
                Route::post('query', [OsqueryAuditController::class, 'query'])
                    ->middleware(['permission:audit.view', 'throttle:bts:30']);
                Route::get('presets', [OsqueryAuditController::class, 'presets'])
                    ->middleware('permission:audit.view');
                Route::get('quick-audit', [OsqueryAuditController::class, 'quickAudit'])
                    ->middleware(['permission:audit.view', 'throttle:bts:10']);
            });
        });
    });
});

// Dedicated Security Center (SC Team) Routes
Route::prefix('security')->group(function () {
    Route::post('auth/login', [StaffAuthController::class, 'securityLogin'])
        ->middleware('throttle:bts:6');

    Route::middleware(['auth:sanctum', 'staff', 'staff.role:security,admin'])->group(function () {
        Route::get('dashboard', [SecurityCenterController::class, 'dashboard'])
            ->middleware('permission:security.dashboard.view');

        Route::get('activity', [SecurityCenterController::class, 'activity'])
            ->middleware('permission:security.audit.view');
        Route::get('activity/{id}', [SecurityCenterController::class, 'activityDetail'])
            ->middleware('permission:security.audit.view');

        Route::get('users', [SecurityCenterController::class, 'users'])
            ->middleware('permission:security.users.view');
        Route::post('users/{id}/suspend', [SecurityCenterController::class, 'suspendUser'])
            ->middleware(['permission:security.users.suspend', 'throttle:bts:20']);
        Route::post('users/{id}/unsuspend', [SecurityCenterController::class, 'unsuspendUser'])
            ->middleware(['permission:security.users.unsuspend', 'throttle:bts:20']);
        Route::post('users/{id}/revoke-tokens', [SecurityCenterController::class, 'revokeTokens'])
            ->middleware(['permission:security.users.revoke_tokens', 'throttle:bts:20']);

        Route::get('data/applications', [SecurityCenterController::class, 'applications'])
            ->middleware('permission:security.applications.view');
        Route::get('data/documents', [SecurityCenterController::class, 'documents'])
            ->middleware('permission:security.documents.view');
        Route::get('data/appointments', [SecurityCenterController::class, 'appointments'])
            ->middleware('permission:security.appointments.view');

        Route::get('telemetry', [SecurityCenterController::class, 'telemetry'])
            ->middleware('permission:security.telemetry.view');

        Route::get('vulnerabilities', [SecurityCenterController::class, 'vulnerabilities'])
            ->middleware('permission:security.vulnerability.view');
        Route::post('vulnerabilities/scan', [SecurityCenterController::class, 'scanVulnerabilities'])
            ->middleware(['permission:security.vulnerability.scan', 'throttle:bts:10']);

        Route::post('export', [SecurityCenterController::class, 'export'])
            ->middleware(['permission:security.audit.export', 'throttle:bts:10']);

        // Osquery routes under security prefix
        Route::prefix('osquery')->group(function () {
            Route::get('status', [OsqueryAuditController::class, 'status'])
                ->middleware('permission:security.osquery.view');
            Route::post('query', [OsqueryAuditController::class, 'query'])
                ->middleware(['permission:security.osquery.execute', 'throttle:bts:30']);
            Route::get('presets', [OsqueryAuditController::class, 'presets'])
                ->middleware('permission:security.osquery.view');
            Route::get('quick-audit', [OsqueryAuditController::class, 'quickAudit'])
                ->middleware(['permission:security.osquery.view', 'throttle:bts:10']);
        });
    });
});
