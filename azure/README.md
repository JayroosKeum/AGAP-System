# Azure deployment

This repository deploys to a **Linux Azure App Service running PHP 8.3** with
the GitHub Actions workflow in `.github/workflows/main_agap.yml`.

## 1. Create Azure resources

Create an Azure App Service (Linux, PHP 8.2 or later) and an Azure Database for
MySQL Flexible Server. Create the `agap_db` database, then import
`database/schema.sql` into it. Configure the MySQL firewall/network rules so
the App Service can reach the server.

For production, create an Azure Files share and mount it in the App Service at
`/home/site/wwwroot/storage`. AGAP saves evidence, documents, seals, reports,
and service photos below `storage/`; a deployment must not be allowed to
replace these user-created files. The GitHub Actions package deliberately
excludes local `storage/` content, including the development PDFs currently in
the repository.

## 2. Configure App Service settings

In **App Service > Configuration > Application settings**, add the values in
`.env.azure.example`:

| Setting | Required | Purpose |
| --- | --- | --- |
| `DB_HOST` | Yes | Azure MySQL fully-qualified host name |
| `DB_PORT` | Yes | Usually `3306` |
| `DB_NAME` | Yes | Database name, normally `agap_db` |
| `DB_USER` | Yes | MySQL login name |
| `DB_PASS` | Yes | MySQL password |
| `AGAP_APP_URL` | Yes | Public HTTPS address used in password-reset links |
| `GEMINI_API_KEY` | No | Enables AI features |
| `DB_SSL_CA` | Conditional | CA file path when your MySQL TLS policy requires it |

Do not enter these values as GitHub variables or place them in the repository.
They are server-side App Service settings.

## 3. Configure GitHub Actions

The workflow runs on pushes to `main` and can also be run manually from the
Actions tab. It deploys to the `AGAP` App Service using Azure OIDC credentials
stored as GitHub Actions secrets:
`AZUREAPPSERVICE_CLIENTID_49F63E697029412686408871AA88CF9E`,
`AZUREAPPSERVICE_TENANTID_DFC2C15D65784E45A8ED28731128DA80`, and
`AZUREAPPSERVICE_SUBSCRIPTIONID_BFA18332F3314BB2B3975BC75386ABD2`.

The release package deliberately excludes `storage/`, logs, debugging output,
and local scratch files. This keeps releases small and prevents a deployment
from overwriting the mounted Azure Files share that contains user uploads and
generated documents.

## 4. Production checks

Change the seeded development passwords after importing the schema. Configure
an SMTP relay or supported mail provider for PHP's `mail()` function if
password-reset emails are needed; Azure App Service does not provide a general
outbound mail service by default. Verify that the App Service has write access
to the mounted `storage` path.
