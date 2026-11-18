# UEC Backend API & Administration Platform

Backend for the **United Evangelical Church (UEC)** mobile apps (Android & iOS, Ionic Vue + Capacitor) and the custom administration dashboard.

- **REST API** — `https://api.<domain>/api/v1` · Laravel 13 · PostgreSQL · Redis · Sanctum
- **Admin dashboard** — `https://app.<domain>` · Livewire 4 · Alpine.js · Tailwind CSS 4 · TypeScript (no Filament)
- One codebase, two deployments, selected by `UEC_SURFACE=api|admin|all`

## Quick start (local)
```bash
composer install && npm install
cp .env.example .env        # set UEC_SURFACE=all, APP_ENV=local, APP_DEBUG=true, MAIL_MAILER=log, DB_*, UEC_PRIVATE_DISK=local
php artisan key:generate
createdb uec_api && createdb uec_api_test
php artisan migrate --seed
php artisan uec:admin:create --email=admin@uec.local --name="Local Admin"
npm run build
php artisan serve           # API: http://localhost:8000/api/v1 · Dashboard: http://localhost:8000/login
php artisan queue:work redis --queue=otp,webhooks,notifications,default
```

## Quality gates
```bash
php artisan test            # 103 tests on PostgreSQL
vendor/bin/pint --test
npm run typecheck && npm run build
```

## Documentation
| Document | Purpose |
|---|---|
| [docs/backend-handoff.md](docs/backend-handoff.md) | API contract from the mobile team (source of truth) |
| [docs/FRONTEND_API_INTEGRATION_HANDOFF.md](docs/FRONTEND_API_INTEGRATION_HANDOFF.md) | How the mobile app connects to this API |
| [docs/api/openapi.yaml](docs/api/openapi.yaml) | OpenAPI 3.1 spec (also at `/api/v1/openapi.yaml` and `/api-docs` in the dashboard) |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | System design, modules, flows, decisions |
| [docs/DATABASE.md](docs/DATABASE.md) | Schema and ERD |
| [docs/SECURITY.md](docs/SECURITY.md) | Authentication, authorization, OWASP mapping, operations |
| [docs/INTEGRATIONS.md](docs/INTEGRATIONS.md) | Paystack, Monnify, ZeptoMail, WhatsApp, FCM, Google, Cloudinary, S3, Redis |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Environments, DNS/TLS, Nginx, workers, scheduler, CI/CD, rollback, backups |
| [docs/TESTING.md](docs/TESTING.md) | Test suite, contract checks, smoke checks |
| [docs/REQUIREMENTS_TRACEABILITY.md](docs/REQUIREMENTS_TRACEABILITY.md) | Requirement → implementation → test → status |
| [docs/BACKEND_COMPLETION_REPORT.md](docs/BACKEND_COMPLETION_REPORT.md) | Delivery status, pending credentials and decisions |

## License
See [LICENSE](LICENSE).
