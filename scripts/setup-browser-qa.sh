#!/usr/bin/env bash

set -euo pipefail

export APP_ENV=local
export DB_CONNECTION=mysql
export DB_DATABASE=upgrade_ux_qa
export UX_QA_BROWSER=true

if [[ -z "${UX_QA_ADMIN_PASSWORD:-}" || -z "${UX_QA_COLLABORATOR_PASSWORD:-}" ]]; then
    echo "UX_QA_ADMIN_PASSWORD and UX_QA_COLLABORATOR_PASSWORD are required." >&2
    exit 1
fi

php artisan tinker --execute='if (config("database.connections.mysql.database") !== "upgrade_ux_qa" || DB::selectOne("SELECT DATABASE() AS name")->name !== "upgrade_ux_qa") { throw new RuntimeException("Browser QA database guard failed."); } echo "Browser QA database verified: upgrade_ux_qa";'
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\UXOperationalQaSeeder --force
