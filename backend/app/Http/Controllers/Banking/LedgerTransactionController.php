<?php

namespace App\Http\Controllers\Banking;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BankAccount;
use App\Models\BankingTransferRequest;
use App\Models\LedgerTransaction;
use App\Models\StaffUser;
use App\Services\Banking\LedgerService;
use App\Services\Banking\TransferRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LedgerTransactionController extends Controller
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TransferRequestService $transferRequests,
    ) {}

    public function deposit(Request $request): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate([
            'destination_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'amount_millimes' => ['required', 'integer', 'min:1', 'max:9000000000000000'],
            'idempotency_key' => ['required', 'string', 'regex:/\\A[A-Za-z0-9._:-]{16,128}\\z/'],
        ]);
        /** @var StaffUser $actor */
        $actor = $request->user();
        $transaction = $this->ledger->deposit(
            destination: BankAccount::query()->findOrFail($data['destination_account_id']),
            amountMillimes: $data['amount_millimes'],
            idempotencyKey: $data['idempotency_key'],
            actor: $actor,
        );

        return ApiResponse::created(['transaction' => $this->transactionPayload($transaction)]);
    }

    public function transfer(Request $request): JsonResponse
    {
        $request->merge(['idempotency_key' => $request->header('Idempotency-Key')]);
        $data = $request->validate([
            'source_account_id' => ['required', 'integer', 'different:destination_account_id', 'exists:bank_accounts,id'],
            'destination_account_id' => ['required', 'integer', 'exists:bank_accounts,id'],
            'amount_millimes' => ['required', 'integer', 'min:1', 'max:9000000000000000'],
            'idempotency_key' => ['required', 'string', 'regex:/\\A[A-Za-z0-9._:-]{16,128}\\z/'],
        ]);
        /** @var StaffUser $actor */
        $actor = $request->user();
        $transferRequest = $this->transferRequests->propose(
            source: BankAccount::query()->findOrFail($data['source_account_id']),
            destination: BankAccount::query()->findOrFail($data['destination_account_id']),
            amountMillimes: $data['amount_millimes'],
            idempotencyKey: $data['idempotency_key'],
            maker: $actor,
        );

        return ApiResponse::accepted(['transfer_request' => $this->transferRequestPayload($transferRequest)]);
    }

    public function approveTransfer(BankingTransferRequest $transferRequest, Request $request): JsonResponse
    {
        /** @var StaffUser $checker */
        $checker = $request->user();
        $transferRequest = $this->transferRequests->approve($transferRequest, $checker);

        return ApiResponse::ok(['transfer_request' => $this->transferRequestPayload($transferRequest)]);
    }

    public function rejectTransfer(BankingTransferRequest $transferRequest, Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        /** @var StaffUser $checker */
        $checker = $request->user();
        $transferRequest = $this->transferRequests->reject($transferRequest, $data['reason'], $checker);

        return ApiResponse::ok(['transfer_request' => $this->transferRequestPayload($transferRequest)]);
    }

    /** @return array<string,mixed> */
    private function transactionPayload(LedgerTransaction $transaction): array
    {
        return [
            'reference' => $transaction->reference,
            'type' => $transaction->transaction_type,
            'status' => $transaction->status,
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'posted_at' => $transaction->posted_at?->toIso8601String(),
            'entries' => $transaction->entries->map(fn ($entry): array => [
                'account_id' => $entry->bank_account_id,
                'account_number' => $entry->account->account_number,
                'direction' => $entry->direction,
                'amount_millimes' => $entry->amount_millimes,
            ])->values(),
        ];
    }

    /** @return array<string,mixed> */
    private function transferRequestPayload(BankingTransferRequest $transferRequest): array
    {
        return [
            'reference' => $transferRequest->reference,
            'status' => $transferRequest->status,
            'source_account_id' => $transferRequest->source_account_id,
            'destination_account_id' => $transferRequest->destination_account_id,
            'amount_millimes' => $transferRequest->amount_millimes,
            'currency' => $transferRequest->currency,
            'requested_by_staff_user_id' => $transferRequest->requested_by_staff_user_id,
            'checked_by_staff_user_id' => $transferRequest->checked_by_staff_user_id,
            'approved_at' => $transferRequest->approved_at?->toIso8601String(),
            'rejected_at' => $transferRequest->rejected_at?->toIso8601String(),
            'rejection_reason' => $transferRequest->rejection_reason,
            'transaction' => $transferRequest->ledgerTransaction === null
                ? null
                : $this->transactionPayload($transferRequest->ledgerTransaction),
        ];
    }
}
