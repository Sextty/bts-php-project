<?php

namespace Tests\Feature\Banking;

use App\Exceptions\ApiException;
use App\Models\BankAccount;
use App\Models\BankingTransferRequest;
use App\Models\LedgerTransaction;
use App\Models\StaffUser;
use App\Models\User;
use App\Services\Banking\LedgerService;
use App\Services\Banking\TransferRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class TransferRequestTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    private TransferRequestService $transferRequests;

    private StaffUser $firstAdmin;

    private StaffUser $secondAdmin;

    private BankAccount $source;

    private BankAccount $destination;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('banking.transfers.max_per_transfer_millimes', 50_000);
        config()->set('banking.transfers.daily_limit_per_source_millimes', 75_000);
        $this->ledger = app(LedgerService::class);
        $this->transferRequests = app(TransferRequestService::class);
        $this->firstAdmin = StaffUser::factory()->admin()->create();
        $this->secondAdmin = StaffUser::factory()->admin()->create();
        $firstCustomer = User::factory()->create();
        $secondCustomer = User::factory()->create();
        $this->source = $this->ledger->openCustomerAccount($firstCustomer, $this->firstAdmin);
        $this->destination = $this->ledger->openCustomerAccount($secondCustomer, $this->firstAdmin);
        $this->ledger->deposit($this->source, 100_000, 'transfer-request-deposit-0001', $this->firstAdmin);
    }

    public function test_transfer_request_does_not_post_until_a_different_admin_approves_it(): void
    {
        Sanctum::actingAs($this->firstAdmin, ['*']);
        $this->withHeader('Idempotency-Key', 'transfer-request-api-test-0001')
            ->postJson('/api/v1/banking/transfers', [
                'source_account_id' => $this->source->id,
                'destination_account_id' => $this->destination->id,
                'amount_millimes' => 30_000,
            ])
            ->assertAccepted()
            ->assertJsonPath('data.transfer_request.status', BankingTransferRequest::STATUS_PENDING);

        $transferRequest = BankingTransferRequest::query()->sole();
        $this->assertSame(0, LedgerTransaction::query()->where('transaction_type', 'transfer')->count());
        $this->assertSame(100_000, $this->ledger->balanceMillimes($this->source));

        $this->postJson("/api/v1/banking/transfer-requests/{$transferRequest->id}/approve")
            ->assertConflict()
            ->assertJsonPath('error.code', 'BANKING_SELF_APPROVAL');

        Sanctum::actingAs($this->secondAdmin, ['*']);
        $this->postJson("/api/v1/banking/transfer-requests/{$transferRequest->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.transfer_request.status', BankingTransferRequest::STATUS_APPROVED)
            ->assertJsonPath('data.transfer_request.transaction.status', LedgerTransaction::STATUS_POSTED);

        $this->assertSame(70_000, $this->ledger->balanceMillimes($this->source));
        $this->assertSame(30_000, $this->ledger->balanceMillimes($this->destination));
        $this->assertDatabaseHas('banking_transfer_requests', [
            'id' => $transferRequest->id,
            'requested_by_staff_user_id' => $this->firstAdmin->id,
            'checked_by_staff_user_id' => $this->secondAdmin->id,
            'status' => BankingTransferRequest::STATUS_APPROVED,
        ]);
    }

    public function test_only_authorized_staff_may_propose_or_approve_transfers(): void
    {
        $staff = StaffUser::factory()->create();
        Sanctum::actingAs($staff, ['*']);
        $this->withHeader('Idempotency-Key', 'transfer-request-api-test-0002')
            ->postJson('/api/v1/banking/transfers', [
                'source_account_id' => $this->source->id,
                'destination_account_id' => $this->destination->id,
                'amount_millimes' => 10_000,
            ])
            ->assertForbidden();

        $maker = StaffUser::factory()->create(['role' => 'senior_staff']);
        Sanctum::actingAs($maker, ['*']);
        $this->withHeader('Idempotency-Key', 'transfer-request-api-test-0003')
            ->postJson('/api/v1/banking/transfers', [
                'source_account_id' => $this->source->id,
                'destination_account_id' => $this->destination->id,
                'amount_millimes' => 10_000,
            ])
            ->assertAccepted();

        $transferRequest = BankingTransferRequest::query()->sole();
        $this->postJson("/api/v1/banking/transfer-requests/{$transferRequest->id}/approve")
            ->assertForbidden();
    }

    public function test_pending_requests_reserve_the_configured_daily_limit_and_are_idempotent(): void
    {
        $first = $this->transferRequests->propose(
            $this->source,
            $this->destination,
            50_000,
            'transfer-request-service-test-001',
            $this->firstAdmin,
        );
        $same = $this->transferRequests->propose(
            $this->source,
            $this->destination,
            50_000,
            'transfer-request-service-test-001',
            $this->firstAdmin,
        );

        $this->assertSame($first->id, $same->id);
        try {
            $this->transferRequests->propose(
                $this->source,
                $this->destination,
                30_000,
                'transfer-request-service-test-002',
                $this->firstAdmin,
            );
            $this->fail('Expected daily transfer limit to be enforced.');
        } catch (ApiException $exception) {
            $this->assertSame('BANKING_TRANSFER_LIMIT_EXCEEDED', $exception->errorCode);
        }

        $this->assertSame(1, BankingTransferRequest::query()->count());
    }

    public function test_rejected_requests_are_final_and_cannot_be_mutated(): void
    {
        $transferRequest = $this->transferRequests->propose(
            $this->source,
            $this->destination,
            10_000,
            'transfer-request-service-test-003',
            $this->firstAdmin,
        );
        $rejected = $this->transferRequests->reject($transferRequest, 'Montant à confirmer avec le client.', $this->secondAdmin);

        $this->assertSame(BankingTransferRequest::STATUS_REJECTED, $rejected->status);
        $this->expectException(LogicException::class);
        $rejected->rejection_reason = 'Tentative de modification';
        $rejected->save();
    }
}
