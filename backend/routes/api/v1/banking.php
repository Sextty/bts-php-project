<?php

use App\Http\Controllers\Banking\BankAccountController;
use App\Http\Controllers\Banking\LedgerTransactionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/banking')->group(function () {
    Route::middleware(['auth:sanctum', 'customer', 'permission:banking.accounts.view'])->group(function () {
        Route::get('accounts', [BankAccountController::class, 'index']);
        Route::get('accounts/{account}/statement', [BankAccountController::class, 'statement']);
    });

    Route::middleware(['auth:sanctum', 'staff', 'staff.role:admin', 'permission:banking.accounts.manage'])->group(function () {
        Route::post('accounts', [BankAccountController::class, 'open'])
            ->middleware('throttle:bts:10');
        Route::post('deposits', [LedgerTransactionController::class, 'deposit'])
            ->middleware('throttle:bts:10');
    });

    // A transfer request never posts accounting entries. A different admin must approve it.
    Route::middleware(['auth:sanctum', 'staff', 'permission:banking.transfers.propose'])->group(function () {
        Route::post('transfers', [LedgerTransactionController::class, 'transfer'])
            ->middleware('throttle:bts:10');
    });

    Route::middleware(['auth:sanctum', 'staff', 'staff.role:admin', 'permission:banking.transfers.approve'])->group(function () {
        Route::post('transfer-requests/{transferRequest}/approve', [LedgerTransactionController::class, 'approveTransfer'])
            ->middleware('throttle:bts:10');
        Route::post('transfer-requests/{transferRequest}/reject', [LedgerTransactionController::class, 'rejectTransfer'])
            ->middleware('throttle:bts:10');
    });
});
