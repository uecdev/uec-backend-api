# UEC Backend — Installation, Configuration & Deployment

**Audience:** the engineer installing and operating the platform.

## 1. Topology

| Environment | API | Admin dashboard |
|---|---|---|
| Production | `https://api.domainname.org` | `https://app.domainname.org` |
| Staging | `https://api-staging.domainname.org` | `https://app-staging.domainname.org` |
| Development | `http://localhost:8000` (UEC_SURFACE=all serves both) | same host, `/login` |

Both surfaces run the **same repository** with different `.env` files and share one PostgreSQL database, one Redis and one S3 bucket. The API host also runs the queue workers and the scheduler. They can be one server with two Nginx vhosts, or two servers.

## 2. Server requirements
- Ubuntu 22.04/24.04 LTS (or equivalent), Nginx, PHP-FPM **8.4+** with extensions: `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `gd`, `bcmath`, `zip`, `sodium`, `curl`, `openssl`, `fileinfo`, `exif` (+ `redis` optional).
- Composer 2, Node.js 22 (admin build only), Git.
- PostgreSQL **15+** (managed service recommended); the `pg_trgm` extension must be available (the migration creates it).
- Redis **7** with AOF persistence.
- Supervisor; cron.
- Sizing to start: API 2 vCPU / 4 GB, admin 1 vCPU / 2 GB, PostgreSQL 2 vCPU / 4 GB.

## 3. DNS & TLS
| Record | Type | Value |
|---|---|---|
| `api` | A / AAAA (or CNAME to the load balancer) | API server IP |
| `app` | A / AAAA | Admin server IP |
| `api-staging`, `app-staging` | A / AAAA | Staging server IPs |

TLS uses Let's Encrypt: `sudo certbot --nginx -d api.domainname.org` and `-d app.domainname.org` (auto-renewal via the certbot timer). Behind a load balancer or Cloudflare, terminate TLS there and set `TRUSTED_PROXIES`.

Mail DNS for ZeptoMail: add the SPF, DKIM and DMARC records ZeptoMail shows for the sending domain.

## 4. Environment configuration
Start from `.env.example`. The table lists the values that **must differ** per surface; everything else is shared (same DB, Redis, `APP_KEY`, integration keys).

| Variable | API (`api.`) | Admin (`app.`) |
|---|---|---|
| `UEC_SURFACE` | `api` | `admin` |
| `APP_URL` | `https://api.domainname.org` | `https://app.domainname.org` |
| `UEC_API_URL` | `https://api.domainname.org` | `https://api.domainname.org` |
| `UEC_ADMIN_URL` | `https://app.domainname.org` | `https://app.domainname.org` |
| `SESSION_DOMAIN` | (unused) | `app.domainname.org` |
| `UEC_CORS_ALLOWED_ORIGINS` | `https://app.domainname.org` (+ any approved web clients) | (unused) |

**Mandatory for production:** `APP_ENV=production`, `APP_DEBUG=false` (`deploy.sh` refuses otherwise), `APP_KEY` identical on both, `LOG_LEVEL=warning`, `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.

Full variable reference: `.env.example` (grouped and commented) and [INTEGRATIONS.md](INTEGRATIONS.md) for provider keys.

## 5. First-time installation
```bash
# On each host, as the deploy user
sudo mkdir -p /var/www/uec-api/shared /var/www/uec-admin/shared /var/log/uec
sudo chown -R deploy:www-data /var/www/uec-api /var/www/uec-admin /var/log/uec

# Database (once)
sudo -u postgres psql -c "CREATE ROLE uec_api LOGIN PASSWORD '…';"
sudo -u postgres psql -c "CREATE DATABASE uec_api OWNER uec_api;"
sudo -u postgres psql -d uec_api -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"   # if the app role cannot create extensions

# Environment
cp .env.example /var/www/uec-api/shared/.env      # edit: UEC_SURFACE=api …
cp .env.example /var/www/uec-admin/shared/.env    # edit: UEC_SURFACE=admin …
php artisan key:generate --show                    # paste the SAME key into both .env files

# First release (runs migrations + seeds roles/plans/categories on the API surface)
bash deploy/deploy.sh api   v1.0.0
bash deploy/deploy.sh admin v1.0.0

# First administrator
cd /var/www/uec-api/current && php artisan uec:admin:create --email=you@uec.org.ng --name="Your Name"

# Web server, workers, scheduler
sudo cp deploy/nginx/api.domainname.org.conf /etc/nginx/sites-available/ && sudo ln -s ../sites-available/api.domainname.org.conf /etc/nginx/sites-enabled/
sudo cp deploy/nginx/app.domainname.org.conf /etc/nginx/sites-available/ && sudo ln -s ../sites-available/app.domainname.org.conf /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo cp deploy/supervisor/uec-workers.conf /etc/supervisor/conf.d/ && sudo supervisorctl reread && sudo supervisorctl update
sudo cp deploy/cron/uec-scheduler /etc/cron.d/uec-scheduler
```
Then on the dashboard: **Mobile app** (donation URL, versions, store URLs, providers), **Plans** (sync Paystack plans), **Integrations** (run every live test), **Pages** (About, Anthem, Doctrine, Terms, Privacy).

PHP-FPM: `upload_max_filesize = 60M`, `post_max_size = 64M`, `memory_limit = 256M`, `expose_php = Off`, opcache enabled (`opcache.validate_timestamps=0` in production; `deploy.sh` reloads FPM).

## 6. Queue workers & scheduler
- Supervisor runs three general workers (`otp,webhooks,notifications,default`, in priority order) plus one dedicated push worker (`deploy/supervisor/uec-workers.conf`). `deploy.sh` calls `queue:restart` after each API release.
- cron calls `schedule:run` every minute (`deploy/cron/uec-scheduler`). Tasks: heartbeat, scheduled pushes and reminders, renewals (15 min), lifecycle (hourly), pruning (daily). All use `onOneServer()`.
- Health: **Dashboard → Operations** shows queue backlog, failed jobs (retry/delete), webhook events (reprocess) and the scheduler heartbeat.

## 7. CI/CD (GitHub Actions)
| Workflow | Trigger | What it does |
|---|---|---|
| `ci.yml` | PRs, pushes to `main`, and as a reusable gate | Composer install → Pint `--test` → secret scan → PHPUnit (parallel) on PostgreSQL 17 + Redis 7 services; `npm ci` → `tsc --noEmit` → `vite build` |
| `deploy.yml` | push to `main` → **staging**; tag `v*` → **production**; manual dispatch (environment, surface, deploy/rollback) | Runs CI first, then SSHes to each host and runs `deploy/deploy.sh` for **api** then **admin** (one at a time, API first because it owns migrations). Production uses the `production` GitHub environment, so add required reviewers there. |

Repository secrets (per environment): `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`, `DEPLOY_USER`, `API_HOST`, `ADMIN_HOST`, `DEPLOY_REPO`. Application secrets stay in each host's `shared/.env` and are never stored in GitHub.

`deploy/deploy.sh` (zero downtime):
1. Validates `shared/.env` (surface matches, `APP_DEBUG=false`).
2. Clones the ref into `releases/<timestamp>`.
3. Links the shared `storage/` and `.env`, so uploads and logs persist across releases.
4. Runs `composer install --no-dev -o`; on the admin surface also `npm ci && npm run typecheck && npm run build`.
5. On the API surface: `migrate --force` and the idempotent seeders.
6. `php artisan optimize` (config, routes, views, events).
7. Atomic symlink switch, then FPM reload and `queue:restart`.
8. `/up` health check, then prunes to 5 releases.

## 8. Rollback
- **Code:** `bash deploy/rollback.sh api` (or `admin`), or run the Deploy workflow with `action: rollback`. This switches `current` to the previous release and re-caches.
- **Database:** all migrations are additive, so older code runs against the newer schema. A release that needs a destructive change must ship as expand → migrate data → contract across two releases. Restore from the pre-deploy snapshot (§9) only as a last resort.

## 9. Backups & recovery
| Asset | Method | Frequency / retention |
|---|---|---|
| PostgreSQL | Managed automated backups with point-in-time recovery (or `pg_dump -Fc` to S3 via cron) + a snapshot before each production deploy | daily, 30 days; PITR 7 days |
| S3 private bucket | Versioning + cross-region replication (optional) | continuous |
| Redis | AOF persistence. Contents are reconstructible (cache) or short-lived (OTP, queue), so it isn't a backup target. | — |
| `shared/.env` | Secrets manager / encrypted vault copy | on change |

**Restore drill (quarterly):** restore the latest dump into a scratch database, point a staging API at it, run `php artisan migrate:status` and the smoke checks in [TESTING.md](TESTING.md#5-post-deploy-smoke-checks).

## 10. Logs & monitoring
- App logs: `shared/storage/logs/laravel-YYYY-MM-DD.log` (daily, 30 days); workers: `/var/log/uec/worker*.log`; Nginx per vhost.
- Point an uptime monitor at `https://api.<domain>/up` and `https://app.<domain>/up` (200 = database + cache OK, 503 otherwise).
- Error tracking (Sentry or Crashlytics) is **[CONFIRM]**. When chosen, add the SDK and a log channel and set the DSN in `.env`.

## 11. Local development
```bash
composer install && npm install
cp .env.example .env   # set UEC_SURFACE=all, APP_ENV=local, APP_DEBUG=true, MAIL_MAILER=log, DB_* , UEC_PRIVATE_DISK=local
php artisan key:generate
php artisan migrate --seed
php artisan uec:admin:create --email=admin@uec.local --name="Local Admin"
npm run dev                 # or npm run build
php artisan serve           # API at /api/v1, dashboard at /login
php artisan queue:work redis --queue=otp,webhooks,notifications,default
php artisan schedule:work
```
With `MAIL_MAILER=log`, OTP emails are written to `storage/logs`. WhatsApp OTPs are skipped (and logged) when WhatsApp is not configured in `local`.
