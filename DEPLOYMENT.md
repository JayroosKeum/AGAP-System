# Production deployment checklist

## Required server configuration

1. Serve only the `public/` directory as the web root. Do not expose the project root, `backend/`, `database/`, or `storage/` directly.
2. Use PHP 8.2+ with the PDO MySQL, cURL, mbstring, fileinfo, OpenSSL, and ZIP extensions enabled.
3. Set `APP_ENV=production`, disable `display_errors`, and enable server-side error logging.
4. Configure HTTPS and redirect all HTTP traffic to HTTPS. Mark PHP session cookies `Secure`, `HttpOnly`, and `SameSite=Lax` (or stricter where compatible).
5. Apply `database/schema.sql` for a new installation. For an existing installation, back up the database and use the [database guide](database/README.md) to select migrations for the installed schema. **Production migration prerequisite**: ensure `20261115_002_mediation_pause_decisions.sql`, `20261115_003_preserve_show_cause_and_cfa_decisions.sql`, and `20261115_004_order4_settlement_arbitration_transmittal.sql` are applied through the authorized production migration procedure before deploying Order 4/5 code.
6. Give the web-service account write permission only to the necessary `storage/` subdirectories; keep application source read-only.

## Secrets

Set `DB_*` and `GEMINI_API_KEY` in the hosting platform's secret manager or the Apache/PHP-FPM environment. Use `.env.example` only as a template. Do not put production credentials in Git, JavaScript, or the `system_settings` table.

For Apache, configure the secrets outside the document root, then restart Apache:

```apache
SetEnv GEMINI_API_KEY "replace-with-a-rotated-server-secret"
SetEnv DB_HOST "your-database-host"
SetEnv DB_PORT "3306"
SetEnv DB_NAME "agap_db"
SetEnv DB_USER "agap_app"
SetEnv DB_PASS "replace-with-a-server-secret"
```

Restrict the Gemini key to the production server where Google supports it, set quota/billing alerts, and rotate any key that has appeared in a local configuration or log.

Gemini must have an enabled project with available API quota. For production workloads, enable billing or use an appropriately provisioned paid project; a valid key alone cannot bypass a provider `429` quota response.

## Gemini readiness check

1. In **Settings → External services**, choose a supported Gemini model and run **Test Gemini API** while signed in as an administrator or Lupon Clerk.
2. Confirm that PHP cURL can make outbound HTTPS requests to `generativelanguage.googleapis.com:443`.
3. Use a test prompt with no resident or complaint data. The application calls Gemini only from the server; users must explicitly consent before case data is sent to an AI feature.

The application prioritizes `GEMINI_API_KEY` from the server environment. The database setting remains only as a legacy fallback.

## Release verification & Azure CI gate

```powershell
php -l backend/config/database.php
php -l backend/services/GeminiService.php
npm test
php tests/verify_release_package.php <release-dir>
```

The GitHub Actions workflow (`.github/workflows/azure-webapp.yml`) gates Azure App Service deployment (`deploy` depends on `test-and-package`) on:
1. Installing production PHP dependencies (`composer install --no-dev --optimize-autoloader`) and running PHP/JS syntax checks.
2. Importing `database/schema.sql` into disposable MySQL 8.0 (`agap_test`) and verifying upgrade migrations through `20261115_004_order4_settlement_arbitration_transmittal.sql`.
3. Running `npm test` on PHP 8.3 against disposable MySQL (including `tests/run_tests.js` preflight checks for `SELECT DATABASE()` and Order 4 schema columns).
4. Assembling `release/` with runtime code, `vendor/`, `backend/libs/dompdf/`, `frontend/assets/images/` document assets, and `database/schema.sql` + `database/migrations/`, while excluding `storage/` (preserving the Azure Files mount at `/home/site/wwwroot/storage`), `database/backups/`, `database/seeds/`, `database/seeders/`, `.env` / `.env.local` / `.env.*.local`, `logs/`, `output/`, `scratch/`, `tests/`, `node_modules/`, `*.pdf`, `*.xlsx`, `*.log`, `*.bak`, `*.tmp`, and `*.gz`.
5. Running `php tests/verify_release_package.php release`, which fails the workflow before deployment if any required runtime file is missing or any prohibited file is present.

Run the integration suite against an isolated local database using the [automated test setup](README.md#automated-tests).

Before go-live, replace all development passwords, remove test accounts, confirm backup/restore works, and conduct an authenticated smoke test of login, dashboard, complaint intake, document generation, and one consented AI request.
