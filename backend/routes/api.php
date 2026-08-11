<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
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

    Route::post('logout', [LogoutController::class, 'logout'])
        ->middleware('auth:sanctum');

    Route::post('password/forgot', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:6,1');
    Route::post('password/reset', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1');
});

Route::middleware('auth:sanctum')->get('user', function () {
    return request()->user();
});
