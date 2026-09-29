# Persistent browser QA database

Operational browser QA uses `upgrade_ux_qa`. PHPUnit remains isolated on
`upgrade_test` through `phpunit.xml` and `Tests\\TestCase`.

## One-time database creation

With the local MySQL service running and the normal local `.env` credentials
available, create only the dedicated database:

```bash
php artisan tinker --execute="DB::statement('CREATE DATABASE IF NOT EXISTS upgrade_ux_qa CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');"
```

The command creates a database but does not migrate, truncate or otherwise
modify `upgrade` or `upgrade_test`.

## Build or refresh the deterministic base dataset

Provide the two existing QA password variables in the shell without writing
their values to this repository, then run:

```bash
./scripts/setup-browser-qa.sh
```

The setup script refuses to continue unless both Laravel configuration and
`SELECT DATABASE()` resolve to `upgrade_ux_qa`. It applies current migrations
and runs the idempotent `UXOperationalQaSeeder`.

## Start the browser QA backend

```bash
./scripts/serve-browser-qa.sh
```

Defaults: `127.0.0.1:8011`. Override only the listener with `UX_QA_HOST` or
`UX_QA_PORT`. The database remains fixed to `upgrade_ux_qa`.

For the frontend QA process, point its existing API-base environment variable
to `http://127.0.0.1:8011`; no frontend configuration file needs to change.

## Safety model

- PHPUnit: `APP_ENV=testing`, `upgrade_test`, and the existing test guardrail.
- Browser QA: `APP_ENV=local`, `upgrade_ux_qa`, plus `UX_QA_BROWSER=true`.
- The operational seeder rejects every other environment/database pairing.
- Never run `migrate:fresh` for browser QA; the dataset is persistent.
