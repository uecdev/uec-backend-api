#!/usr/bin/env bash
# Roll a surface back to its previous release (code only).
#
#   rollback.sh <api|admin> [root]
#
# Database migrations are NOT reversed automatically: every migration in this
# project is additive, so the previous release runs against the newer schema.
# If a release ever ships a destructive migration, restore from the pre-deploy
# database snapshot instead (see docs/DEPLOYMENT.md §Backups).
set -euo pipefail

SURFACE="${1:?usage: rollback.sh <api|admin> [root]}"
ROOT="${2:-/var/www/uec-${SURFACE}}"

CURRENT="$(readlink -f "${ROOT}/current")"
PREVIOUS="$(ls -1dt "${ROOT}"/releases/* | grep -v "^${CURRENT}$" | head -n 1 || true)"
[[ -n "${PREVIOUS}" ]] || { echo "no previous release to roll back to" >&2; exit 1; }

echo "Rolling ${SURFACE} back: $(basename "${CURRENT}") -> $(basename "${PREVIOUS}")"
ln -sfn "${PREVIOUS}" "${ROOT}/current.tmp"
mv -Tf "${ROOT}/current.tmp" "${ROOT}/current"

cd "${ROOT}/current"
php artisan optimize
sudo systemctl reload php8.4-fpm || true
[[ "$SURFACE" == "api" ]] && php artisan queue:restart

echo "Rolled back. Investigate ${CURRENT} before redeploying."
