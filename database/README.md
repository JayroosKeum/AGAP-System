# Database setup

`schema.sql` is the canonical schema for a new AGAP database. It defines all
relationships, indexes, uniqueness rules, and deletion behaviour used by the
current PHP models.

For a fresh local environment, import it with:

```powershell
mysql -u root < database/schema.sql
```

Do not import it over an existing populated database as a migration. The
strengthened constraints can expose duplicate or orphaned records that must be
resolved first. Back up the database, validate existing records, then apply a
reviewed migration in a maintenance window.

For an existing database that predates the current complaint workflow,
document-service, and unified case-team changes, run the single consolidated
upgrade query instead of the individual workflow migrations:

```powershell
mysql -u root agap_db < database/migrations/20260917_consolidated_workflow_upgrade.sql
```

Do not run the consolidated query if any of its individual migrations have
already been applied; its `ADD COLUMN`, index, and constraint operations are
intentionally one-time changes.

## Local test accounts

`schema.sql` seeds local-only test accounts. Existing local databases can add
the same accounts with `database/seeds/local_test_users.sql`. Every account
uses the password `password`:

- `admin` — Administrator
- `clerk` — Lupon Clerk
- `luponhead`, `luponsecretary`, `luponmember` — three Lupon Members for the
  Head, Secretary, and Member case-team test
- `summonsserver` — Summons Server

These known credentials are intentionally for debugging only. Remove or change
them before deploying to a real environment.

`preflight_integrity.sql` is read-only and identifies the duplicates and
orphaned rows that must be resolved before that migration.

## Relationship rules

- A complaint can be docketed into only one case.
- A resident can be attached to a complaint once per party type.
- Any active user with the `Lupon Member` role is eligible for the unified
  Head, Secretary, and Member case team.
- A case has one assignment per case-team role; the application saves the
  three distinct roles as one transaction.
- A case has at most one Pangkat group, settlement, arbitration record, CFA,
  and incident location.
- Pangkat and case assignments reference the eligible user's account directly.

The schema keeps historical audit, log, document, and service records when a
user is removed by setting their actor reference to `NULL`. Core parent
records that would orphan legal case data are protected from deletion.
