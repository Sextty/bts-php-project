<?php

namespace App\Http\Controllers\Banking;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\BankAccount;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Banking\LedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BankAccountController extends Controller
{
    public function __construct(private readonly LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $accounts = BankAccount::query()->where('user_id', $user->id)->orderBy('id')->get();

        return ApiResponse::ok([
            'accounts' => $accounts->map(fn (BankAccount $account): array => $this->accountPayload($account))->values(),
        ]);
    }

    public function statement(Request $request, BankAccount $account): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($account->user_id !== $user->id) {
            throw new ApiException(ApiErrorCode::Forbidden);
        }

        return ApiResponse::ok([
            'account' => $this->accountPayload($account),
            'entries' => $this->ledger->statement($account),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'currency' => ['nullable', 'string', 'size:3', 'in:TND'],
        ]);
        /** @var StaffUser $actor */
        $actor = $request->user();
        $customer = User::query()->findOrFail($data['user_id']);
        $account = $this->ledger->openCustomerAccount(
            user: $customer,
            actor: $actor,
            branchId: $data['branch_id'] ?? null,
            currency: $data['currency'] ?? 'TND',
        );

        return ApiResponse::created(['account' => $this->accountPayload($account)]);
    }

    /** @return array<string,mixed> */
    private function accountPayload(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'account_number' => $account->account_number,
            'product_code' => $account->product_code,
            'currency' => $account->currency,
            'status' => $account->status,
            'available_balance_millimes' => $this->ledger->balanceMillimes($account),
            'opened_at' => $account->created_at?->toIso8601String(),
        ];
    }
}
