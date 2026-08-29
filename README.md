<div align="center">
  # Digital Credit Application Platform

  **A secure, role-based loan-origination experience connecting customers, branch teams, bank
  administrators, and security operators through one consistent workflow.**

  [![Laravel](https://img.shields.io/badge/Laravel_12-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com/)
  [![Next.js](https://img.shields.io/badge/Next.js_16-000000?style=for-the-badge&logo=nextdotjs&logoColor=white)](https://nextjs.org/)
  [![TypeScript](https://img.shields.io/badge/TypeScript-3178C6?style=for-the-badge&logo=typescript&logoColor=white)](https://www.typescriptlang.org/)
  [![Tailwind CSS](https://img.shields.io/badge/Tailwind_CSS-06B6D4?style=for-the-badge&logo=tailwindcss&logoColor=white)](https://tailwindcss.com/)
  [![MariaDB](https://img.shields.io/badge/MariaDB-003545?style=for-the-badge&logo=mariadb&logoColor=white)](https://mariadb.org/)

  `4 portals` · `1 Laravel API` · `Bearer authentication` · `Realtime events` · `Human-controlled AI`
</div>

---

## About the project

BTS Bank is a full-stack loan-origination MVP designed to digitize the journey from a customer's
first credit request to the bank's final decision. Instead of placing every role in one large
interface, it provides four focused portals backed by a shared Laravel API and a controlled
application state machine.

The platform demonstrates customer onboarding and OTP login, structured dossier creation, secure
document handling, advisory AI analysis, dual staff/admin approval, appointments, realtime chat,
audit trails, operational analytics, and security monitoring. Important decisions remain governed
by backend authorization and human review—the AI assists document analysis but never grants credit.

> [!IMPORTANT]
> This repository is a demonstration/MVP, not a certified core-banking system. Use synthetic data
> only. Production use requires banking governance, legal/compliance approval, independent
> security assessment, managed infrastructure, and approved integrations.

## Applications

Each role receives a dedicated interface while sharing the same workflow, data rules, and audit
trail.

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

| Area | What the platform demonstrates |
| --- | --- |
| Identity | Customer registration, OTP verification, login, internal staff accounts and isolated sessions |
| Dossier workflow | Multi-step application, server-side state transitions, final locking and status history |
| Documents | Validated uploads, controlled downloads, advisory AI extraction and mandatory human fallback |
| Bank decisions | Branch-scoped staff review followed by administrator approval or rejection |
| Customer service | Automatic appointment proposal, controlled rescheduling and realtime dossier discussion |
| Security | Explicit CORS/CSP/Reverb origins, authorization policies, throttling, audit logs and session monitoring |
| Operations | Dashboards, workflow analytics, activity views and isolated end-to-end testing |

## Technology stack

| Layer | Technologies |
| --- | --- |
| Frontend | Next.js 16, React 19, TypeScript, Tailwind CSS, Laravel Echo |
| Backend | PHP 8.2+, Laravel 12, Sanctum Bearer tokens, policies, queues and Reverb |
| Data | MariaDB/MySQL, Eloquent ORM, private filesystem document storage |
| AI | Provider abstraction with local-only, OpenRouter and Gemini adapters; strict backend parsing |
| Quality | PHPUnit feature/unit tests, ESLint, TypeScript checks, production builds and Playwright E2E |

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

## Security notes

- Do not commit `.env`, `.env.local`, API keys, passwords, OTPs or real customer documents.
- Keep CORS, CSP and Reverb origins explicit; do not replace them with `*`.
- Use synthetic identities and documents with free external AI providers.
- Forced AI continuation means mandatory human review, not automatic validation.
- A successful demonstration does not replace a penetration test, compliance review, backup
  validation or production-readiness assessment.
