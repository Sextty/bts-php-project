# Core Ledger (v1)

## Scope

This is the first additive core-banking module. It does not replace the existing credit-application workflow and it does not model cards, external clearing, interest, repayments, or regulatory accounting yet.

The implementation provides a safe internal foundation:

- customer current accounts in TND;
- one internal cash-settlement asset account per currency;
- immutable ledger transactions and entries;
- balanced debit/credit postings only;
- integer amounts in millimes, never floats;
- idempotent deposits and internal transfers;
- maker-checker approval for every API transfer;
- configurable per-transfer and per-source daily transfer limits;
- database transactions and stable `FOR UPDATE` account locking;
- derived balances and chronological statements;
- audit events for account opening and ledger posting.

## Data model

```text
users ──< bank_accounts ──< ledger_entries >── ledger_transactions
  │                                      │
  └──< banking_transfer_requests ─────────┴── one debit total = one credit total
```

`bank_accounts` does not store a mutable balance. For a customer liability account, the available balance is the sum of credits minus debits. For the system cash asset account, it is the sum of debits minus credits.

`ledger_transactions.idempotency_key` is unique. Repeating an identical request returns the original posting; repeating a key with different financial data returns `BANKING_IDEMPOTENCY_CONFLICT`.

`banking_transfer_requests` is deliberately separate from the ledger. It records a pending operational instruction and can transition exactly once to `approved` or `rejected`. Approval creates the immutable ledger transaction; a rejected or approved request cannot be edited or deleted.

## API

All new endpoints are versioned under `/api/v1/banking`.

| Endpoint | Actor | Purpose |
| --- | --- | --- |
| `GET /accounts` | account owner | List own accounts and derived balances. |
| `GET /accounts/{account}/statement` | account owner | Read own chronological statement. |
| `POST /accounts` | admin | Open a current account for a customer. |
| `POST /deposits` | admin | Post a cash deposit. Requires `Idempotency-Key`. |
| `POST /transfers` | senior staff, branch manager, admin | Create a pending internal transfer request. Requires `Idempotency-Key`; returns `202`. |
| `POST /transfer-requests/{request}/approve` | a different admin | Approve and post the request atomically. |
| `POST /transfer-requests/{request}/reject` | a different admin | Reject the request. Requires a reason. |

Customers have no write endpoint. The maker and checker must be different `staff_users`, even when both are administrators. A branch-restricted maker may only request a transfer from an account in their assigned branch. Approval is reserved for administrators and is globally scoped.

## Transfer limits

Amounts are in millimes. Defaults are deliberately conservative and must be approved by the bank's risk owners before production:

| Setting | Default | Meaning |
| --- | ---: | --- |
| `BANKING_TRANSFER_MAX_PER_TRANSFER_MILLIMES` | `50_000_000` | 50,000 TND maximum per request. |
| `BANKING_TRANSFER_DAILY_LIMIT_PER_SOURCE_MILLIMES` | `100_000_000` | 100,000 TND maximum per source account per calendar day. |
| `BANKING_TRANSFER_LIMIT_TIMEZONE` | `Africa/Tunis` | Time zone that defines the daily-limit boundary. |

Pending and approved requests both reserve the daily amount. This prevents creating many pending requests to circumvent the daily limit. The actual values live in `backend/config/banking.php`; set the environment variables per approved risk policy.

## Posting rules

1. Amounts are positive integers in millimes (`80_000` = `80.000 TND`).
2. Every posting has at least two entries and total debits equal total credits.
3. Account rows are locked in ascending ID order during a transfer.
4. A request checks account state, currency, branch scope, and configured limits before it can be created.
5. Only a different administrator can approve or reject a pending request; approval rechecks the derived source balance while account locks are held.
6. Posted transactions and entries cannot be updated or deleted through Eloquent.
7. A failed operation rolls back every request, ledger, and audit write together.

## Before production

Complete these next modules before treating this as a payment product:

- reversals as compensating entries, never edits;
- interest, repayment schedules, fees, reconciliation, and external payment rails;
- encrypted backups, restore drills, replication, and a supported MariaDB LTS release;
- retention/KYC/AML controls and an independent security/compliance review.
