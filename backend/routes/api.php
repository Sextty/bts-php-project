<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TelegramLinkController;
use App\Http\Controllers\CreditApplication\ClientController;
use App\Http\Controllers\CreditApplication\CreditApplicationController;
use App\Http\Controllers\CreditApplication\CreditRequestController;
use App\Http\Controllers\CreditApplication\DocumentController;
use App\Http\Controllers\CreditApplication\ProjectController;
use App\Http\Controllers\CreditApplication\ValidationController;
use App\Http\Controllers\Staff\ApplicationController as StaffApplicationController;
use App\Http\Controllers\Staff\AuthController as StaffAuthController;
use App\Http\Resources\UserResource;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('register', [RegisterController::class, 'register'])
        ->middleware('throttle:6,1');
    Route::post('verify-registration-otp', [RegisterController::class, 'verifyOtp'])
        ->middleware('throttle:10,1');

    Route::post('login', [LoginController::class, 'login'])
        ->middleware('throttle:6,1');
    Route::post('login/verify-otp', [LoginController::class, 'verifyOtp'])
        ->middleware('throttle:10,1');

    Route::post('google', [GoogleAuthController::class, 'authenticate'])
        ->middleware('throttle:10,1');
    Route::post('google/set-phone', [GoogleAuthController::class, 'setPhone'])
        ->middleware('throttle:10,1');
    Route::post('google/verify-otp', [GoogleAuthController::class, 'verifyOtp'])
        ->middleware('throttle:10,1');

    // Polled by the "Connect Telegram" screen — allowance is generous because the client polls
    // this every couple of seconds while the user completes the handshake in another app.
    Route::post('telegram/link-status', [TelegramLinkController::class, 'status'])
        ->middleware('throttle:60,1');

    Route::post('logout', [LogoutController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::post('password/forgot', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:6,1');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1');
});

Route::middleware('auth:sanctum')->get('user', function () {
    return response()->json(['success' => true, 'data' => ['user' => new UserResource(request()->user())]]);
});

Route::middleware('auth:sanctum')->prefix('applications')->group(function () {
    Route::get('/', [CreditApplicationController::class, 'index']);
    Route::post('/', [CreditApplicationController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::get('{application}', [CreditApplicationController::class, 'show']);

    Route::put('{application}/client', [ClientController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::put('{application}/credit', [CreditRequestController::class, 'update'])
        ->middleware('throttle:30,1');
    Route::put('{application}/project', [ProjectController::class, 'update'])
        ->middleware('throttle:30,1');

    Route::post('{application}/documents', [DocumentController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::delete('{application}/documents/{document}', [DocumentController::class, 'destroy'])
        ->middleware('throttle:20,1');

    Route::post('{application}/validation-1', [ValidationController::class, 'validationOne'])
        ->middleware('throttle:20,1');
    Route::post('{application}/validation-2', [ValidationController::class, 'validationTwo'])
        ->middleware('throttle:20,1');

    Route::post('{application}/submit', [CreditApplicationController::class, 'submit'])
        ->middleware('throttle:10,1');
});

Route::prefix('staff')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])
        ->middleware('throttle:6,1');

    Route::middleware(['auth:sanctum', 'staff'])->group(function () {
        Route::post('logout', [StaffAuthController::class, 'logout']);

        Route::get('applications', [StaffApplicationController::class, 'index']);
        Route::get('applications/{application}', [StaffApplicationController::class, 'show']);
        Route::post('applications/{application}/approve', [StaffApplicationController::class, 'approve'])
            ->middleware('throttle:20,1');
        Route::post('applications/{application}/reject', [StaffApplicationController::class, 'reject'])
            ->middleware('throttle:20,1');

        // Admin-only final decision — staff.role:admin runs after staff, so $request->user() is
        // already confirmed to be a StaffUser by the time it checks the role column.
        Route::middleware('staff.role:admin')->group(function () {
            Route::post('applications/{application}/admin-approve', [StaffApplicationController::class, 'adminApprove'])
                ->middleware('throttle:20,1');
            Route::post('applications/{application}/admin-reject', [StaffApplicationController::class, 'adminReject'])
                ->middleware('throttle:20,1');
        });
    });
});
