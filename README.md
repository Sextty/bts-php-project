# BTS Bank — Loan Origination MVP

BTS Bank is a multi-portal loan-origination prototype built with Laravel and Next.js. It covers
customer onboarding, credit applications, document verification, staff and admin decisions,
appointments, realtime discussion, audit monitoring, and security analytics.

> This repository is a demonstration/MVP, not a certified core-banking system. Use synthetic data
> only. Production use requires banking governance, legal/compliance approval, independent
> security assessment, managed infrastructure, and approved integrations.

## Applications

| Application | Directory | Local URL | Purpose |
| --- | --- | --- | --- |
| Client portal | `client/` | <http://localhost:3000> | Registration, OTP login, applications and appointments |
| Staff portal | `staff/` | <http://localhost:3001> | Branch review, document control and first decision |
| Admin portal | `admin/` | <http://localhost:3002> | Global review, final decision and administration |
| Security Center | `sc/` | <http://localhost:3003> | Security events, sessions and audit monitoring |
| Laravel API | `backend/` | <http://127.0.0.1:8000> | Authentication, workflow, persistence, queue and realtime API |

All browser applications use Bearer-token authentication and the same API base URL:

```dotenv
NEXT_PUBLIC_API_URL=http://127.0.0.1:8000
```

## Main capabilities

- Customer registration and OTP-based authentication.
- Multi-step credit application workflow with server-side state enforcement.
- Secure document upload and advisory AI-assisted verification.
- Optional OpenRouter free-model routing with strict JSON validation and human-review fallback.
- Customer deletion of unfinished dossiers; validated dossiers are retained and auditable.
- Staff first-level review and administrator final approval or rejection.
- Appointment scheduling, controlled rescheduling and realtime customer/staff discussion.
- Role and branch isolation, audit logs, session monitoring and Security Center dashboards.
- Exact local CORS/CSP/Reverb origins without unrestricted wildcards.

## Requirements

- PHP 8.2 or newer
- Composer
- Node.js and npm
- MySQL or MariaDB

## Installation

Clone the repository, then configure the backend:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
```

Before seeding, set unique local values for `CLIENT_SEED_PASSWORD`, `STAFF_SEED_PASSWORD`,
`ADMIN_SEED_PASSWORD`, and `SECURITY_SEED_PASSWORD` in `backend/.env`. Never commit that file.

Install every frontend:

```powershell
cd ..\client
npm install
Copy-Item .env.example .env.local

cd ..\staff
npm install
Copy-Item .env.example .env.local

cd ..\admin
npm install
Copy-Item .env.example .env.local

cd ..\sc
npm install
Copy-Item .env.example .env.local
```

## Start locally

Open a separate terminal for each command:

```powershell
# API
cd backend
php artisan serve --host=127.0.0.1 --port=8000

# Queue worker
cd backend
php artisan queue:work --sleep=1 --tries=5 --backoff=2 --timeout=60

# Realtime server
cd backend
php artisan reverb:start --host=127.0.0.1 --port=6001

# Port 3000
cd client
npm run dev

# Port 3001
cd staff
npm run dev

# Port 3002
cd admin
npm run dev

# Port 3003
cd sc
npm run dev
```

Check the API at <http://127.0.0.1:8000/api/health>.

## AI document verification

Document AI is backend-only. API keys must stay in `backend/.env` and must never use a
`NEXT_PUBLIC_` variable. Local-only verification is the safe default:

```dotenv
DOCUMENT_VERIFICATION_PROVIDER=local
```

For a synthetic development test through OpenRouter:

```dotenv
DOCUMENT_VERIFICATION_PROVIDER=openrouter
OPENROUTER_API_KEY=your_backend_only_key
OPENROUTER_MODEL=openrouter/free
OPENROUTER_REASONING_ENABLED=false
```

The free router may select a different compatible model for each request and can have variable
latency or rate limits. AI output never makes the final credit decision. Malformed output,
provider failures and rejected documents are retained for human review.

## Verification

```powershell
cd backend
php artisan route:list
php artisan test
```

Run these commands in each of `client`, `staff`, `admin`, and `sc`:

```powershell
npm run lint
npx tsc --noEmit
npm run build
```

Security Center unit tests:

```powershell
cd sc
npm test
```

Full isolated browser workflow:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/run-e2e-isolated.ps1
```

If the local database listens on a non-default port, pass it explicitly, for example
`-DatabasePort 13306`.

## Documentation

- [Detailed startup guide](GUIDE.md)
- [Two-day MVP runbook](docs/TWO_DAY_MVP_RUNBOOK.md)
- [API specification](backend/docs/openapi.yaml)
- [Architecture diagram](architecture.puml)

## Security notes

- Do not commit `.env`, `.env.local`, API keys, passwords, OTPs or real customer documents.
- Keep CORS, CSP and Reverb origins explicit; do not replace them with `*`.
- Use synthetic identities and documents with free external AI providers.
- Forced AI continuation means mandatory human review, not automatic validation.
- A successful demonstration does not replace a penetration test, compliance review, backup
  validation or production-readiness assessment.
