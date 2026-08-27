<?php

namespace App\Services\Banking;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\BankAccount;
use App\Models\BankingTransferRequest;
use App\Models\StaffUser;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Controls the operational lifecycle of an internal transfer. A request is not
 * an accounting event: only its approval asks LedgerService to post entries.
 */
final class TransferRequestService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly LedgerService $ledger,
    ) {}

    public function propose(
        BankAccount $source,
        BankAccount $destination,
        int $amountMillimes,
        string $idempotencyKey,
        StaffUser $maker,
    ): BankingTransferRequest {
        $this->assertPositiveAmount($amountMillimes);
        $this->assertIdempotencyKey($idempotencyKey);
        if ($source->id === $destination->id) {
            throw new ApiException(ApiErrorCode::BankingAccountUnavailable, 'The source and destination accounts must be different.');
        }

        $payload = [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_millimes' => $amountMillimes,
            'currency' => $source->currency,
        ];
        $requestHash = $this->requestHash($payload);

        try {
            return DB::transaction(function () use ($source, $destination, $amountMillimes, $idempotencyKey, $maker, $requestHash): BankingTransferRequest {
                $existing = BankingTransferRequest::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $this->matchingExistingRequest($existing, $requestHash);
                }

                $accounts = $this->lockAccounts([$source->id, $destination->id]);
                $source = $accounts[$source->id];
                $destination = $accounts[$destination->id];
                $this->assertActiveCustomerAccount($source);
                $this->assertActiveCustomerAccount($destination);
                if ($source->currency !== $destination->currency) {
                    throw new ApiException(ApiErrorCode::BankingCurrencyMismatch);
                }
                if ($maker->isBranchRestricted() && $source->branch_id !== $maker->branch_id) {
                    throw new ApiException(ApiErrorCode::Forbidden, 'You may only propose transfers from accounts in your assigned branch.');
                }

                $this->assertTransferLimits($source, $amountMillimes);
                $transferRequest = BankingTransferRequest::create([
                    'reference' => 'TRQ-'.strtoupper((string) Str::ulid()),
                    'idempotency_key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'source_account_id' => $source->id,
                    'destination_account_id' => $destination->id,
                    'amount_millimes' => $amountMillimes,
                    'currency' => $source->currency,
                    'status' => BankingTransferRequest::STATUS_PENDING,
                    'requested_by_staff_user_id' => $maker->id,
                ]);

                $this->auditLog->log(
                    action: 'banking.transfer_requested',
                    newState: [
                        'banking_transfer_request_id' => $transferRequest->id,
                        'reference' => $transferRequest->reference,
                        'source_account_id' => $source->id,
                        'destination_account_id' => $destination->id,
                        'amount_millimes' => $amountMillimes,
                        'currency' => $source->currency,
                    ],
                    staffUser: $maker,
                );

                return $transferRequest;
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = BankingTransferRequest::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->matchingExistingRequest($existing, $requestHash);
            }

            throw $exception;
        }
    }

    public function approve(BankingTransferRequest $transferRequest, StaffUser $checker): BankingTransferRequest
    {
        return DB::transaction(function () use ($transferRequest, $checker): BankingTransferRequest {
            $transferRequest = BankingTransferRequest::query()->lockForUpdate()->findOrFail($transferRequest->id);
            if ($transferRequest->status === BankingTransferRequest::STATUS_APPROVED) {
                return $transferRequest->load('ledgerTransaction.entries.account');
            }
            $this->assertPendingAndDifferentChecker($transferRequest, $checker);

            $transaction = $this->ledger->transfer(
                source: BankAccount::query()->findOrFail($transferRequest->source_account_id),
                destination: BankAccount::query()->findOrFail($transferRequest->destination_account_id),
                amountMillimes: $transferRequest->amount_millimes,
                idempotencyKey: 'transfer-request-'.$transferRequest->id,
                actor: $checker,
            );

            $transferRequest->update([
                'status' => BankingTransferRequest::STATUS_APPROVED,
                'checked_by_staff_user_id' => $checker->id,
                'ledger_transaction_id' => $transaction->id,
                'approved_at' => now(),
            ]);
            $this->auditLog->log(
                action: 'banking.transfer_approved',
                newState: [
                    'banking_transfer_request_id' => $transferRequest->id,
                    'reference' => $transferRequest->reference,
                    'ledger_transaction_id' => $transaction->id,
                ],
                staffUser: $checker,
            );

            return $transferRequest->fresh()->load('ledgerTransaction.entries.account');
        }, attempts: 3);
    }

    public function reject(BankingTransferRequest $transferRequest, string $reason, StaffUser $checker): BankingTransferRequest
    {
        return DB::transaction(function () use ($transferRequest, $reason, $checker): BankingTransferRequest {
            $transferRequest = BankingTransferRequest::query()->lockForUpdate()->findOrFail($transferRequest->id);
            $this->assertPendingAndDifferentChecker($transferRequest, $checker);

            $transferRequest->update([
                'status' => BankingTransferRequest::STATUS_REJECTED,
                'checked_by_staff_user_id' => $checker->id,
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);
            $this->auditLog->log(
                action: 'banking.transfer_rejected',
                newState: [
                    'banking_transfer_request_id' => $transferRequest->id,
                    'reference' => $transferRequest->reference,
                    'reason' => $reason,
                ],
                staffUser: $checker,
            );

            return $transferRequest->fresh();
        }, attempts: 3);
    }

    /** @param list<int> $accountIds @return array<int,BankAccount> */
    private function lockAccounts(array $accountIds): array
    {
        $accountIds = array_values(array_unique($accountIds));
        $accounts = BankAccount::query()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        if (count($accounts) !== count($accountIds)) {
            throw new ApiException(ApiErrorCode::BankingAccountUnavailable, 'One or more bank accounts do not exist.');
        }

        return $accounts;
    }

    private function assertTransferLimits(BankAccount $source, int $amountMillimes): void
    {
        $perTransferLimit = max(1, (int) config('banking.transfers.max_per_transfer_millimes'));
        $dailyLimit = max($perTransferLimit, (int) config('banking.transfers.daily_limit_per_source_millimes'));
        if ($amountMillimes > $perTransferLimit) {
            throw new ApiException(ApiErrorCode::BankingTransferLimitExceeded, 'This transfer exceeds the configured per-transfer limit.');
        }

        $today = now(config('banking.transfers.daily_limit_timezone'))->startOfDay()->setTimezone(config('app.timezone'));
        $reservedToday = BankingTransferRequest::query()
            ->where('source_account_id', $source->id)
            ->whereIn('status', [BankingTransferRequest::STATUS_PENDING, BankingTransferRequest::STATUS_APPROVED])
            ->where('created_at', '>=', $today)
            ->lockForUpdate()
            ->get(['amount_millimes'])
            ->sum('amount_millimes');

        if ($reservedToday + $amountMillimes > $dailyLimit) {
            throw new ApiException(ApiErrorCode::BankingTransferLimitExceeded, 'This transfer exceeds the configured daily limit for the source account.');
        }
    }

    private function assertPendingAndDifferentChecker(BankingTransferRequest $transferRequest, StaffUser $checker): void
    {
        if ($transferRequest->status !== BankingTransferRequest::STATUS_PENDING) {
            throw new ApiException(ApiErrorCode::BankingTransferRequestUnavailable, 'This transfer request is no longer pending.');
        }
        if ($transferRequest->requested_by_staff_user_id === $checker->id) {
            throw new ApiException(ApiErrorCode::BankingSelfApproval, 'The staff member who requested a transfer cannot approve or reject it.');
        }
    }

    private function assertActiveCustomerAccount(BankAccount $account): void
    {
        if ($account->account_kind !== BankAccount::KIND_CUSTOMER || $account->status !== BankAccount::STATUS_ACTIVE) {
            throw new ApiException(ApiErrorCode::BankingAccountUnavailable);
        }
    }

    private function assertPositiveAmount(int $amountMillimes): void
    {
        if ($amountMillimes < 1) {
            throw new \InvalidArgumentException('Transfer amount must be a positive integer number of millimes.');
        }
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if (! preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $idempotencyKey)) {
            throw new \InvalidArgumentException('Idempotency-Key must contain 16-128 safe characters.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function requestHash(array $payload): string
    {
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function matchingExistingRequest(BankingTransferRequest $transferRequest, string $requestHash): BankingTransferRequest
    {
        if (! hash_equals($transferRequest->request_hash, $requestHash)) {
            throw new ApiException(ApiErrorCode::BankingIdempotencyConflict);
        }

        return $transferRequest->load('ledgerTransaction.entries.account');
    }
}
