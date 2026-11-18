#!/usr/bin/env bash
# Zero-downtime release for one surface of the UEC platform.
#
#   deploy.sh <api|admin> <git-ref> [app-root]
#
# Layout (per surface):
#   <root>/releases/<timestamp>/   immutable release
#   <root>/shared/.env             surface-specific environment (never in git)
#   <root>/shared/storage/         logs, local uploads, framework cache (persists across releases)
#   <root>/current -> releases/…   atomic symlink switch
#
# Migrations run ONLY on the api surface so the two deployments never race.
set -euo pipefail

SURFACE="${1:?usage: deploy.sh <api|admin> <git-ref> [root]}"
REF="${2:?git ref (tag or commit) required}"
ROOT="${3:-/var/www/uec-${SURFACE}}"
REPO="${UEC_REPO:-git@github.com:uec/uec-backend-api.git}"
KEEP="${UEC_KEEP_RELEASES:-5}"
RELEASE="${ROOT}/releases/$(date -u +%Y%m%d%H%M%S)"

log() { printf '\033[1;32m==>\033[0m %s\n' "$*"; }

[[ "$SURFACE" == "api" || "$SURFACE" == "admin" ]] || { echo "surface must be api or admin" >&2; exit 1; }
[[ -f "${ROOT}/shared/.env" ]] || { echo "missing ${ROOT}/shared/.env" >&2; exit 1; }
grep -q "^UEC_SURFACE=${SURFACE}$" "${ROOT}/shared/.env" || { echo "shared/.env must set UEC_SURFACE=${SURFACE}" >&2; exit 1; }
grep -q '^APP_DEBUG=false$' "${ROOT}/shared/.env" || { echo "refusing to deploy with APP_DEBUG enabled" >&2; exit 1; }

log "Fetching ${REF} into ${RELEASE}"
mkdir -p "${ROOT}/releases" "${ROOT}/shared/storage"
git clone --quiet --depth 1 --branch "${REF}" "${REPO}" "${RELEASE}" 2>/dev/null \
  || { git clone --quiet "${REPO}" "${RELEASE}" && git -C "${RELEASE}" checkout --quiet "${REF}"; }

log "Linking shared state"
rm -rf "${RELEASE}/storage"
ln -s "${ROOT}/shared/storage" "${RELEASE}/storage"
ln -s "${ROOT}/shared/.env" "${RELEASE}/.env"
mkdir -p "${ROOT}/shared/storage"/{app/public,app/private,framework/{cache,sessions,views},logs}

cd "${RELEASE}"
log "Installing PHP dependencies"
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --classmap-authoritative

if [[ "$SURFACE" == "admin" ]]; then
  log "Building admin assets"
  npm ci --no-audit --no-fund
  npm run typecheck
  npm run build
  rm -rf node_modules
fi

log "Validating configuration"
php artisan about --only=environment >/dev/null

if [[ "$SURFACE" == "api" ]]; then
  log "Running migrations"
  php artisan migrate --force --no-interaction
  php artisan db:seed --class=Database\\Seeders\\RolesAndPermissionsSeeder --force
  php artisan db:seed --class=Database\\Seeders\\ReferenceDataSeeder --force
fi

log "Caching config, routes, views and events"
php artisan storage:link >/dev/null 2>&1 || true
php artisan optimize

log "Switching current -> ${RELEASE}"
ln -sfn "${RELEASE}" "${ROOT}/current.tmp"
mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"

log "Reloading PHP-FPM and workers"
sudo systemctl reload php8.4-fpm || true
if [[ "$SURFACE" == "api" ]]; then
  php artisan queue:restart
fi

log "Health check"
sleep 2
curl -fsS --max-time 10 "$(grep '^APP_URL=' "${ROOT}/shared/.env" | cut -d= -f2-)/up" >/dev/null \
  || { echo "health check failed — run deploy/rollback.sh ${SURFACE}" >&2; exit 1; }

log "Pruning old releases (keeping ${KEEP})"
ls -1dt "${ROOT}"/releases/* | tail -n +$((KEEP + 1)) | xargs -r rm -rf

log "Deployed ${REF} to ${SURFACE}"
