#!/usr/bin/env bash
# Run manually on Hostinger ONLY after approval, maintenance and pausing writers.
set -euo pipefail
set +x
umask 077

PROJECT="$HOME/domains/crmupgradecol.com/laravel"
FRONT="$HOME/domains/upgradecolombia.com/public_html"
API_PUBLIC="$HOME/domains/crmupgradecol.com/public_html"
BACKUP_ROOT="$HOME/upgrade79-private-backups"

test -f "$PROJECT/.env"
test -f "$PROJECT/artisan"
test -f "$PROJECT/storage/framework/down"
test -f "$FRONT/laravel-api.php"
test -f "$FRONT/.htaccess"
test -d "$API_PUBLIC"
# Do not silently omit storage moved outside the archived backend tree.
test "$(realpath "$PROJECT/storage")" = "$PROJECT/storage"
test "$(realpath "$PROJECT/storage/app/public")" = "$PROJECT/storage/app/public"

read -r -p 'Host MySQL productivo verificado: ' RELEASE_DB_HOST
read -r -p 'Puerto MySQL productivo verificado (normalmente 3306): ' RELEASE_DB_PORT
read -r -p 'Base productiva verificada: ' RELEASE_DB_NAME
read -r -p 'Usuario MySQL productivo (no contraseña): ' RELEASE_DB_USER
[[ "$RELEASE_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]
[[ "$RELEASE_DB_PORT" =~ ^[0-9]+$ ]]
test "$RELEASE_DB_NAME" != upgrade
test "$RELEASE_DB_NAME" != upgrade_test
test -n "$RELEASE_DB_HOST"
test -n "$RELEASE_DB_USER"

mkdir -p "$BACKUP_ROOT"
chmod 700 "$BACKUP_ROOT"
BACKUP=$(mktemp -d "$BACKUP_ROOT/release-$(date -u +%Y%m%dT%H%M%SZ)-XXXXXX")
chmod 700 "$BACKUP"
printf 'Respaldo privado: %s\n' "$BACKUP"

# -p prompts privately. Never put a password in argv, an environment variable or log.
mysqldump --host="$RELEASE_DB_HOST" --port="$RELEASE_DB_PORT" --user="$RELEASE_DB_USER" -p \
  --single-transaction --quick --routines --triggers --events --hex-blob \
  --no-tablespaces -- "$RELEASE_DB_NAME" | gzip > "$BACKUP/database.sql.gz"

tar -czf "$BACKUP/backend.tar.gz" -C "$(dirname "$PROJECT")" "$(basename "$PROJECT")"
tar -czf "$BACKUP/frontend.tar.gz" -C "$(dirname "$FRONT")" "$(basename "$FRONT")"
tar -czf "$BACKUP/api-public.tar.gz" -C "$(dirname "$API_PUBLIC")" "$(basename "$API_PUBLIC")"
cp -p "$PROJECT/.env" "$BACKUP/backend.env"
chmod 600 "$BACKUP/backend.env"
git -C "$PROJECT" rev-parse HEAD > "$BACKUP/backend-head.txt"
sha256sum "$FRONT/index.html" > "$BACKUP/frontend-index-before.txt"

for archive in backend frontend api-public; do
  gzip -t "$BACKUP/$archive.tar.gz"
  tar -tzf "$BACKUP/$archive.tar.gz" > /dev/null
done
gzip -t "$BACKUP/database.sql.gz"
test -s "$BACKUP/database.sql.gz"
tar -tzf "$BACKUP/backend.tar.gz" | awk '$0 == "laravel/.env" { found=1 } END { exit !found }'
tar -tzf "$BACKUP/frontend.tar.gz" | awk '$0 == "public_html/laravel-api.php" { found=1 } END { exit !found }'
tar -tzf "$BACKUP/frontend.tar.gz" | awk '$0 == "public_html/.htaccess" { found=1 } END { exit !found }'

cd "$BACKUP"
sha256sum database.sql.gz backend.tar.gz frontend.tar.gz api-public.tar.gz \
  backend.env backend-head.txt frontend-index-before.txt > SHA256SUMS
sha256sum --check --quiet SHA256SUMS
printf 'Integridad PASS. Falta prueba privada de restauración; NO se creó RESTORE_VERIFIED.\n'
