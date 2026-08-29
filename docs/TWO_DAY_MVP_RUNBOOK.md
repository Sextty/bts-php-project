# BTS Bank two-day MVP runbook

## Deliverable and safety boundary

This repository is a tested **BTS Bank loan-origination prototype**. It demonstrates customer
onboarding, credit-application processing, document verification, staff and admin decisions,
appointments, realtime discussion, audit/security monitoring, analytics, and a small internal
double-entry ledger.

It is not a certified core-banking deployment. Do not connect real customer identities,
documents, accounts, or money until BTS governance, legal/compliance, independent security audit,
production infrastructure, disaster recovery, and approved external-provider contracts are in
place.

Use synthetic data only for the demonstration. Keep `DOCUMENT_VERIFICATION_PROVIDER=local` unless
an explicitly approved cloud-AI test uses synthetic documents. Never send a real CIN or financial
document to a free AI endpoint.

The development cloud option uses `OPENROUTER_MODEL=openrouter/free` with reasoning disabled for
fast JSON extraction. This is OpenRouter's zero-cost router, so the concrete model may change per
request and availability/rate limits are not production guarantees. AI output is advisory and is
strictly parsed; rejection or provider failure must go to human review, never an automatic credit
decision.

## Local environment

Each frontend must contain the following value in `.env.local`:

```dotenv
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000
```

Laravel must allow only the exact local portal origins in `CORS_ALLOWED_ORIGINS`. Reverb reuses
those hosts by default; `REVERB_ALLOWED_ORIGINS=localhost,127.0.0.1` may be set explicitly.

Do not commit `.env` or `.env.local`. Copy the supplied `.env.example` files and inject secrets
locally. Configure unique local seed passwords with `CLIENT_SEED_PASSWORD`, `STAFF_SEED_PASSWORD`,
`ADMIN_SEED_PASSWORD`, and `SECURITY_SEED_PASSWORD` before running `php artisan db:seed`.

## Start the platform

Install dependencies and migrate once:

```powershell
cd C:\Users\wassi\Desktop\bts-php-project\backend
composer install
php artisan migrate
php artisan db:seed
```

Open separate terminals and run:

```powershell
# Laravel API
cd C:\Users\wassi\Desktop\bts-php-project\backend
php artisan serve --host=127.0.0.1 --port=8000

# Queue worker
cd C:\Users\wassi\Desktop\bts-php-project\backend
php artisan queue:work --sleep=1 --tries=5 --backoff=2 --timeout=60

# Realtime server
cd C:\Users\wassi\Desktop\bts-php-project\backend
php artisan reverb:start --host=127.0.0.1 --port=6001

# Client portal
cd C:\Users\wassi\Desktop\bts-php-project\client
npm run dev

# Staff portal
cd C:\Users\wassi\Desktop\bts-php-project\staff
npm run dev

# Admin portal
cd C:\Users\wassi\Desktop\bts-php-project\admin
npm run dev

# Security Center
cd C:\Users\wassi\Desktop\bts-php-project\sc
npm run dev
```

Portal addresses:

- Client: `http://localhost:3000`
- Staff: `http://localhost:3001`
- Admin: `http://localhost:3002`
- Security Center: `http://localhost:3003`
- API health: `http://127.0.0.1:8000/api/health`

## Demonstration acceptance flow

1. Register a synthetic customer and verify the OTP.
2. Log out and log in again with a fresh OTP.
3. Create an unfinished dossier, delete it, and confirm it leaves the list.
4. Create a new dossier, complete all three information steps, and upload the synthetic CIN fixture.
5. Run compliance validation. When an AI rejection is intentionally simulated, force only the
   human-review path; missing required sections must remain blocking.
6. Finalize the dossier and verify that its delete control is absent and the API rejects deletion.
7. Log in as branch staff, inspect the document, and approve or reject the dossier.
8. Log in as admin, make the final decision, and verify appointment creation.
9. Exercise appointment acceptance/change limits and customer/staff realtime discussion.
10. Log in to Security Center and inspect the resulting audit activity without exposing document
    contents or authentication secrets.

## Verification commands

```powershell
# Backend
cd C:\Users\wassi\Desktop\bts-php-project\backend
php artisan route:list
php artisan test

# Run in each of client, staff, admin, and sc
npm run lint
npx tsc --noEmit
npm run build

# Security Center also has unit tests
cd C:\Users\wassi\Desktop\bts-php-project\sc
npm test

# Full isolated browser workflow; the local XAMPP MariaDB currently listens on 13306
cd C:\Users\wassi\Desktop\bts-php-project
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-e2e-isolated.ps1 -DatabasePort 13306
```

The isolated browser runner creates only a uniquely named `bts_e2e_<pid>` database, uses synthetic
fixtures and dedicated ports, stops its child processes, and drops that validated database after
the run.

## Go-live blockers outside the two-day MVP

- Formal BTS product ownership and BCT/legal/compliance approval.
- INPDP data-processing assessment and approved retention policy.
- CTAF-compatible KYC/AML, PEP, sanctions, suspicious-activity, and fraud workflows.
- Bank-managed SSO/MFA, HSM/KMS, secrets vault, production TLS/WAF/network segmentation and SIEM.
- Contracted private AI or on-premises inference with data-retention guarantees and mandatory
  human decisions.
- Core-banking, accounting, credit bureau, identity, signature, archive, and settlement integrations.
- Independently certified security audit and penetration test.
- Redundant production database/storage, monitored backups, proven restoration, failover, RPO/RTO,
  incident response, and 24/7 operational ownership.
