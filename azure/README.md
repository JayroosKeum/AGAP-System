# Azure deployment

This repository deploys to a **Linux Azure App Service running PHP 8.3** with
the [GitHub Actions workflow](../.github/workflows/azure-webapp.yml).

## 1. Create Azure resources

Create an Azure App Service (Linux, PHP 8.3) and an Azure Database for
MySQL Flexible Server. Create the `agap_db` database, then import
`database/schema.sql` into it. Configure the MySQL firewall/network rules so
the App Service can reach the server (and ensure TLS/SSL is enabled; AGAP
automatically uses `/etc/ssl/certs/ca-certificates.crt` on Azure App Service
when `DB_SSL_CA` is left blank).

For production, create an Azure Files share and mount it in the App Service at
`/home/site/wwwroot/storage`. AGAP saves evidence, documents, seals, reports,
and service photos below `storage/`; a deployment must not be allowed to
replace these user-created files. The GitHub Actions package deliberately
excludes local `storage/` content, including the development PDFs currently in
the repository.

## 2. Configure App Service environment variables

In **App Service > Settings > Environment variables** (Application settings),
add the values in [`.env.azure.example`](../.env.azure.example):

| Setting | Required | Purpose |
| --- | --- | --- |
| `DB_HOST` | Yes | Azure MySQL fully-qualified host name |
| `DB_PORT` | Yes | Usually `3306` |
| `DB_NAME` | Yes | Database name, normally `agap_db` |
| `DB_USER` | Yes | MySQL login name |
| `DB_PASS` | Yes | MySQL password |
| `AGAP_APP_URL` | Yes | Public HTTPS address used in verification and password-reset links (no trailing slash) |
| `GMAIL_SMTP_USERNAME` | Recommended | Gmail sender address for staff verification and password-reset emails |
| `GMAIL_SMTP_APP_PASSWORD` | Recommended | 16-character Google App Password for `GMAIL_SMTP_USERNAME` |
| `GEMINI_API_KEY` | No | Enables AI features (server-side only) |
| `GEMINI_API_BASE_URL` | Conditional | Private relay URL in a Gemini-supported region (e.g. Cloud Run in `asia-southeast1` if App Service runs in `East Asia`) |
| `GEMINI_PROXY_SECRET` | Conditional | Shared secret sent as `x-agap-proxy-secret` to `GEMINI_API_BASE_URL` |
| `GOOGLE_RESIDENT_CLIENT_ID` | No | OAuth Web client ID to require Google confirmation for new residents and enable their Google sign-in |
| `APP_PII_KEY` | Recommended | AES-256-GCM key (`base64:...`) for encrypting sensitive resident/party PII |
| `DB_SSL_CA` | Conditional | Custom CA file path; defaults to `/etc/ssl/certs/ca-certificates.crt` on Azure Linux |

Do not enter these values as GitHub variables or place them in the repository.
They are server-side App Service settings.

Before setting `GOOGLE_RESIDENT_CLIENT_ID`, add the App Service's HTTPS origin
(for example `https://your-app-name.azurewebsites.net`) to the OAuth client's
**Authorized JavaScript origins** in Google Cloud Console. AGAP uses the
existing password registration flow while the setting is empty. Password sign-in
remains available to existing residents when Google sign-in is enabled.

## 3. Configure Startup Command (Nginx security & storage directories)

Linux Azure App Service for PHP 8.3 uses Nginx (which ignores `.htaccess`).
To enforce `.htaccess`-equivalent path blocks (`/backend/` internals,
`/database/`, `.env`, `composer.json`, `*.sql`, `*.md`, `*.log`), raise upload
limits to `32M`, and ensure `storage/` subdirectories exist on boot:

1. In Azure Portal, open **App Service > Settings > Configuration > General settings**.
2. Set **Startup Command** to:
   ```text
   bash /home/site/wwwroot/azure/startup.sh
   ```
3. Save and restart the App Service.

## 4. Configure GitHub Actions

The workflow runs on pushes to `main` and can also be run manually from the
Actions tab. It deploys to the `AGAP` App Service using Azure OIDC credentials
stored as GitHub Actions secrets:
`AZUREAPPSERVICE_CLIENTID_AFFD284B6AD849CA9C04C89B66296DB0`,
`AZUREAPPSERVICE_TENANTID_3236F735AB824ACBBE842420C6BDA10E`, and
`AZUREAPPSERVICE_SUBSCRIPTIONID_6C0A42D6437542A2A49C03DE6EDDE208`.

The release package deliberately excludes `storage/`, logs, debugging output,
and local scratch files. This keeps releases small and prevents a deployment
from overwriting the mounted Azure Files share that contains user uploads and
generated documents.

Before production deployment, apply any required migrations separately after
verifying a current MySQL backup. The workflow uses the existing Azure OIDC
identity to perform a read-only schema check in the running App Service
container and read the result through Kudu. It deploys the code after the
schema check passes and then checks the live login page.

## 5. Production checks

Change the seeded development passwords (`PassTumana2026!`) immediately after
importing `database/schema.sql`. Verify that the App Service has write access
to the mounted `/home/site/wwwroot/storage` path and test staff login,
email verification/password reset, complaint intake, document generation, and
the public complaint tracker.
