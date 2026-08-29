<?php

namespace App\Services\Banking;

use App\Enums\ApiErrorCode;
use App\Exceptions\ApiException;
use App\Models\BankAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only application service allowed to post financial entries. It validates
 * debit/credit equality, uses one database transaction per posting, locks the
 * affected accounts in a stable order, and never persists a mutable balance.
 */
final class LedgerService
{
    public function __construct(private readonly AuditLogService $auditLog) {}

    public function openCustomerAccount(User $user, StaffUser $actor, ?int $branchId = null, string $currency = 'TND'): BankAccount
    {
        $account = BankAccount::query()->firstOrCreate(
            ['user_id' => $user->id, 'product_code' => 'CURRENT', 'currency' => $currency],
            [
                'branch_id' => $branchId,
                'account_number' => $this->accountNumber($currency),
                'account_kind' => BankAccount::KIND_CUSTOMER,
                'normal_side' => 'credit',
                'status' => BankAccount::STATUS_ACTIVE,
            ],
        );

        if ($account->wasRecentlyCreated) {
            $this->auditLog->log(
                action: 'banking.account.opened',
                user: $user,
                newState: ['bank_account_id' => $account->id, 'account_number' => $account->account_number, 'currency' => $currency],
                staffUser: $actor,
            );
        }

        return $account;
    }

    public function deposit(BankAccount $destination, int $amountMillimes, string $idempotencyKey, StaffUser $actor): LedgerTransaction
    {
        $this->assertPositiveAmount($amountMillimes);

        return $this->postIdempotently(
            type: 'deposit',
            idempotencyKey: $idempotencyKey,
            payload: ['destination_account_id' => $destination->id, 'amount_millimes' => $amountMillimes, 'currency' => $destination->currency],
            actor: $actor,
            callback: function () use ($destination, $amountMillimes, $idempotencyKey, $actor): LedgerTransaction {
                $cash = $this->cashAccount($destination->currency);
                $accounts = $this->lockAccounts([$cash->id, $destination->id]);
                $cash = $accounts[$cash->id];
                $destination = $accounts[$destination->id];
                $this->assertActiveCustomerAccount($destination);

                return $this->postEntries(
                    type: 'deposit',
                    idempotencyKey: $idempotencyKey,
                    requestHash: $this->requestHash(['destination_account_id' => $destination->id, 'amount_millimes' => $amountMillimes, 'currency' => $destination->currency]),
                    currency: $destination->currency,
                    description: 'Dépôt sur compte client',
                    actor: $actor,
                    entries: [
                        ['account' => $cash, 'direction' => 'debit', 'amount_millimes' => $amountMillimes],
                        ['account' => $destination, 'direction' => 'credit', 'amount_millimes' => $amountMillimes],
                    ],
                );
            },
        );
    }

    public function transfer(BankAccount $source, BankAccount $destination, int $amountMillimes, string $idempotencyKey, StaffUser $actor): LedgerTransaction
    {
        $this->assertPositiveAmount($amountMillimes);
        if ($source->id === $destination->id) {
            throw new ApiException(ApiErrorCode::BankingAccountUnavailable, 'The source and destination accounts must be different.');
        }

        $payload = [
            'source_account_id' => $source->id,
            'destination_account_id' => $destination->id,
            'amount_millimes' => $amountMillimes,
            'currency' => $source->currency,
        ];

        return $this->postIdempotently(
            type: 'transfer',
            idempotencyKey: $idempotencyKey,
            payload: $payload,
            actor: $actor,
            callback: function () use ($source, $destination, $amountMillimes, $idempotencyKey, $actor, $payload): LedgerTransaction {
                $accounts = $this->lockAccounts([$source->id, $destination->id]);
                $source = $accounts[$source->id];
                $destination = $accounts[$destination->id];
                $this->assertActiveCustomerAccount($source);
                $this->assertActiveCustomerAccount($destination);
                if ($source->currency !== $destination->currency) {
                    throw new ApiException(ApiErrorCode::BankingCurrencyMismatch);
                }
                if ($this->balanceMillimes($source) < $amountMillimes) {
                    throw new ApiException(ApiErrorCode::BankingInsufficientFunds);
                }

                return $this->postEntries(
                    type: 'transfer',
                    idempotencyKey: $idempotencyKey,
                    requestHash: $this->requestHash($payload),
                    currency: $source->currency,
                    description: 'Virement interne',
                    actor: $actor,
                    entries: [
                        ['account' => $source, 'direction' => 'debit', 'amount_millimes' => $amountMillimes],
                        ['account' => $destination, 'direction' => 'credit', 'amount_millimes' => $amountMillimes],
                    ],
                );
            },
        );
    }

    public function balanceMillimes(BankAccount $account): int
    {
        $normalSide = $account->normal_side;
        $balance = LedgerEntry::query()
            ->join('ledger_transactions', 'ledger_transactions.id', '=', 'ledger_entries.ledger_transaction_id')
            ->where('ledger_entries.bank_account_id', $account->id)
            ->where('ledger_transactions.status', LedgerTransaction::STATUS_POSTED)
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN ledger_entries.direction = ? THEN CAST(ledger_entries.amount_millimes AS SIGNED) ELSE -CAST(ledger_entries.amount_millimes AS SIGNED) END), 0) AS balance_millimes',
                [$normalSide],
            )
            ->value('balance_millimes');

        return (int) $balance;
    }

    /** @return list<array<string,mixed>> */
    public function statement(BankAccount $account): array
    {
        $balance = 0;

        return LedgerEntry::query()
            ->with('transaction:id,reference,transaction_type,description,posted_at')
            ->where('bank_account_id', $account->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (LedgerEntry $entry) use (&$balance, $account): array {
                $delta = $entry->direction === $account->normal_side
                    ? $entry->amount_millimes
                    : -$entry->amount_millimes;
                $balance += $delta;

                return [
                    'reference' => $entry->transaction->reference,
                    'transaction_type' => $entry->transaction->transaction_type,
                    'description' => $entry->transaction->description,
                    'direction' => $entry->direction,
                    'amount_millimes' => $entry->amount_millimes,
                    'balance_after_millimes' => $balance,
                    'posted_at' => $entry->transaction->posted_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $payload @param callable():LedgerTransaction $callback */
    private function postIdempotently(string $type, string $idempotencyKey, array $payload, StaffUser $actor, callable $callback): LedgerTransaction
    {
        $this->assertIdempotencyKey($idempotencyKey);
        $hash = $this->requestHash($payload);

        try {
            return DB::transaction(function () use ($type, $idempotencyKey, $hash, $callback): LedgerTransaction {
                $existing = LedgerTransaction::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $this->matchingExistingTransaction($existing, $type, $hash);
                }

                return $callback();
            }, attempts: 3);
        } catch (QueryException $exception) {
            $existing = LedgerTransaction::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $this->matchingExistingTransaction($existing, $type, $hash);
            }

            throw $exception;
        }
    }

    /** @param list<int> $accountIds @return array<int,BankAccount> */
    private function lockAccounts(array $accountIds): array
    {
        $accounts = BankAccount::query()->whereIn('id', array_values(array_unique($accountIds)))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        if (count($accounts) !== count(array_unique($accountIds))) {
            throw new ApiException(ApiErrorCode::BankingAccountUnavailable, 'One or more bank accounts do not exist.');
        }

        return $accounts;
    }

    /** @param list<array{account:BankAccount,direction:string,amount_millimes:int}> $entries */
    private function postEntries(
        string $type,
        string $idempotencyKey,
        string $requestHash,
        string $currency,
        string $description,
        StaffUser $actor,
        array $entries,
    ): LedgerTransaction {
        $debits = 0;
        $credits = 0;
        foreach ($entries as $entry) {
            $this->assertPositiveAmount($entry['amount_millimes']);
            if ($entry['account']->currency !== $currency) {
                throw new ApiException(ApiErrorCode::BankingCurrencyMismatch);
            }
            if ($entry['direction'] === 'debit') {
                $debits += $entry['amount_millimes'];
            } elseif ($entry['direction'] === 'credit') {
                $credits += $entry['amount_millimes'];
            } else {
                throw new \LogicException('Ledger entry direction must be debit or credit.');
            }
        }
        if (count($entries) < 2 || $debits !== $credits) {
            throw new \LogicException('A ledger transaction must contain balanced debit and credit entries.');
        }

        $transaction = LedgerTransaction::create([
            'reference' => 'LTX-'.strtoupper((string) Str::ulid()),
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'transaction_type' => $type,
            'status' => LedgerTransaction::STATUS_POSTED,
            'currency' => $currency,
            'description' => $description,
            'initiated_by_staff_user_id' => $actor->id,
            'metadata' => ['synthetic' => false],
            'posted_at' => now(),
        ]);

        $timestamp = now();
        LedgerEntry::insert(array_map(
            fn (array $entry, int $index): array => [
                'ledger_transaction_id' => $transaction->id,
                'bank_account_id' => $entry['account']->id,
                'sequence' => $index + 1,
                'direction' => $entry['direction'],
                'amount_millimes' => $entry['amount_millimes'],
                'currency' => $currency,
                'created_at' => $timestamp,
            ],
            $entries,
            array_keys($entries),
        ));

        $this->auditLog->log(
            action: 'banking.ledger_transaction.posted',
            newState: [
                'ledger_transaction_id' => $transaction->id,
                'reference' => $transaction->reference,
                'type' => $type,
                'amount_millimes' => $debits,
                'currency' => $currency,
            ],
            staffUser: $actor,
        );

        return $transaction->load('entries.account');
    }

    private function cashAccount(string $currency): BankAccount
    {
        return BankAccount::query()->firstOrCreate(
            ['account_number' => 'SYS-CASH-'.$currency],
            [
                'product_code' => 'CASH_SETTLEMENT',
                'currency' => $currency,
                'account_kind' => BankAccount::KIND_SYSTEM,
                'normal_side' => 'debit',
                'status' => BankAccount::STATUS_ACTIVE,
            ],
        );
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
            throw new \InvalidArgumentException('Ledger amount must be a positive integer number of millimes.');
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

    private function matchingExistingTransaction(LedgerTransaction $transaction, string $type, string $requestHash): LedgerTransaction
    {
        if ($transaction->transaction_type !== $type || ! hash_equals($transaction->request_hash, $requestHash)) {
            throw new ApiException(ApiErrorCode::BankingIdempotencyConflict);
        }

        return $transaction->load('entries.account');
    }

    private function accountNumber(string $currency): string
    {
        return 'TNB-'.$currency.'-'.strtoupper(Str::random(20));
    }
}
