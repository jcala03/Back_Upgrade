#!/usr/bin/env bash

set -euo pipefail

export APP_ENV=local
export DB_CONNECTION=mysql
export DB_DATABASE=upgrade_ux_qa
export UX_QA_BROWSER=true

php artisan tinker --execute='if (config("database.connections.mysql.database") !== "upgrade_ux_qa" || DB::selectOne("SELECT DATABASE() AS name")->name !== "upgrade_ux_qa") { throw new RuntimeException("Browser QA database guard failed."); } echo "Browser QA database verified: upgrade_ux_qa";'

exec php artisan serve --host="${UX_QA_HOST:-127.0.0.1}" --port="${UX_QA_PORT:-8011}"
