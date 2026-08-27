<?php

namespace Tests\Feature\Banking;

use App\Exceptions\ApiException;
use App\Models\BankAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Banking\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class LedgerCoreTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private StaffUser $admin;

    private User $firstCustomer;

    private User $secondCustomer;

    private BankAccount $firstAccount;

    private BankAccount $secondAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
        $this->admin = StaffUser::factory()->admin()->create();
        $this->firstCustomer = User::factory()->create();
        $this->secondCustomer = User::factory()->create();
        $this->firstAccount = $this->ledger->openCustomerAccount($this->firstCustomer, $this->admin);
        $this->secondAccount = $this->ledger->openCustomerAccount($this->secondCustomer, $this->admin);
    }

    public function test_deposit_creates_exactly_balanced_immutable_entries_and_a_derived_balance(): void
    {
        $transaction = $this->ledger->deposit($this->firstAccount, 120_000, 'deposit-ledger-test-0001', $this->admin);

        $this->assertSame(LedgerTransaction::STATUS_POSTED, $transaction->status);
        $this->assertCount(2, $transaction->entries);
        $this->assertSame(120_000, (int) $transaction->entries->where('direction', 'debit')->sum('amount_millimes'));
        $this->assertSame(120_000, (int) $transaction->entries->where('direction', 'credit')->sum('amount_millimes'));
        $this->assertSame(120_000, $this->ledger->balanceMillimes($this->firstAccount));

        $transaction->description = 'attempted mutation';
        $this->expectException(LogicException::class);
        $transaction->save();
    }

    public function test_transfer_is_idempotent_and_preserves_double_entry_and_balances(): void
    {
        $this->ledger->deposit($this->firstAccount, 120_000, 'deposit-ledger-test-0002', $this->admin);
        $first = $this->ledger->transfer($this->firstAccount, $this->secondAccount, 45_000, 'transfer-ledger-test-0001', $this->admin);
        $second = $this->ledger->transfer($this->firstAccount, $this->secondAccount, 45_000, 'transfer-ledger-test-0001', $this->admin);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LedgerTransaction::query()->where('transaction_type', 'transfer')->count());
        $this->assertSame(75_000, $this->ledger->balanceMillimes($this->firstAccount));
        $this->assertSame(45_000, $this->ledger->balanceMillimes($this->secondAccount));
        $this->assertSame(0, LedgerEntry::query()->count() % 2);

        $statement = $this->ledger->statement($this->firstAccount);
        $this->assertSame(75_000, $statement[array_key_last($statement)]['balance_after_millimes']);
    }

    public function test_same_idempotency_key_with_different_payload_is_refused(): void
    {
        $this->ledger->deposit($this->firstAccount, 100_000, 'deposit-ledger-test-0003', $this->admin);
        $this->ledger->transfer($this->firstAccount, $this->secondAccount, 10_000, 'transfer-ledger-test-0002', $this->admin);

        try {
            $this->ledger->transfer($this->firstAccount, $this->secondAccount, 20_000, 'transfer-ledger-test-0002', $this->admin);
            $this->fail('Expected idempotency conflict.');
        } catch (ApiException $exception) {
            $this->assertSame('BANKING_IDEMPOTENCY_CONFLICT', $exception->errorCode);
        }
    }

    public function test_insufficient_funds_never_posts_a_partial_transaction(): void
    {
        $this->ledger->deposit($this->firstAccount, 10_000, 'deposit-ledger-test-0004', $this->admin);
        $before = LedgerTransaction::query()->count();

        try {
            $this->ledger->transfer($this->firstAccount, $this->secondAccount, 10_001, 'transfer-ledger-test-0003', $this->admin);
            $this->fail('Expected insufficient funds.');
        } catch (ApiException $exception) {
            $this->assertSame('BANKING_INSUFFICIENT_FUNDS', $exception->errorCode);
        }

        $this->assertSame($before, LedgerTransaction::query()->count());
        $this->assertSame(10_000, $this->ledger->balanceMillimes($this->firstAccount));
        $this->assertSame(0, $this->ledger->balanceMillimes($this->secondAccount));
    }

    public function test_customer_can_read_only_their_own_accounts_and_statements(): void
    {
        $this->ledger->deposit($this->firstAccount, 30_000, 'deposit-ledger-test-0005', $this->admin);
        Sanctum::actingAs($this->firstCustomer, ['*']);

        $this->getJson('/api/v1/banking/accounts')
            ->assertOk()
            ->assertJsonCount(1, 'data.accounts')
            ->assertJsonPath('data.accounts.0.id', $this->firstAccount->id)
            ->assertJsonPath('data.accounts.0.available_balance_millimes', 30_000);

        $this->getJson("/api/v1/banking/accounts/{$this->secondAccount->id}/statement")
            ->assertForbidden();
    }

    public function test_only_an_admin_can_open_and_fund_a_banking_account_through_the_versioned_api(): void
    {
        $staff = StaffUser::factory()->create();
        Sanctum::actingAs($staff, ['*']);
        $this->postJson('/api/v1/banking/accounts', ['user_id' => $this->firstCustomer->id])
            ->assertForbidden();

        Sanctum::actingAs($this->admin, ['*']);
        $account = $this->postJson('/api/v1/banking/accounts', ['user_id' => $this->firstCustomer->id])
            ->assertCreated()
            ->json('data.account');

        $this->withHeader('Idempotency-Key', 'deposit-ledger-api-test-0001')
            ->postJson('/api/v1/banking/deposits', [
                'destination_account_id' => $account['id'],
                'amount_millimes' => 80_000,
            ])
            ->assertCreated()
            ->assertJsonPath('data.transaction.status', LedgerTransaction::STATUS_POSTED);

        Sanctum::actingAs($this->firstCustomer, ['*']);
        $this->getJson('/api/v1/banking/accounts')
            ->assertOk()
            ->assertJsonPath('data.accounts.0.available_balance_millimes', 80_000);
    }
}
