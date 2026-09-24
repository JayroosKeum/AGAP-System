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

## Complaint case-type upgrade

An existing database whose `complaints` table does not yet contain `case_type`
must apply `database/migrations/20260924_add_complaint_case_type.sql` once.
Back up the database first. The migration adds the column using the canonical
`Civil` default for existing complaints, then copies the stored type from each
linked case so docketed complaints retain their known type. Existing
undocketed complaints have no stored case type to recover and therefore keep
the canonical `Civil` default. Do not run this migration if `complaints.case_type`
already exists; fresh databases get it from `schema.sql`.

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
them before deploying to a real environment. They are ordinary active accounts
whose passwords are checked through the normal login flow; AGAP has no login
bypass or role-selection shortcut.

## User contact-number upgrade

For an existing populated database, apply
`database/migrations/20260919_add_user_contact_number.sql` once before using
the contact-number field in User Management. Fresh databases receive the field
from `schema.sql`.

`preflight_integrity.sql` is read-only and identifies the duplicates and
orphaned rows that must be resolved before that migration.

## Relationship rules

- A complaint can be docketed into only one case.
- A resident can be attached to a complaint once per party type (Complainant or Respondent)
  recorded in `complaint_parties`.
- Incident map coordinates (`latitude`, `longitude`) and verified address are stored in
  `incident_locations`, linked via `complaint_id` (and synced to `case_id` upon docketing).
- Complaint case classification is recorded in `complaints.case_type` (`Civil` or `Criminal`)
  and synchronized to `cases.case_type`.
- Any active user with the `Lupon Member` role is eligible for the unified
  Head, Secretary, and Member case team.
- A case has one assignment per case-team role; the application saves the
  three distinct roles as one transaction.
- A case has at most one Pangkat group, settlement, arbitration record, CFA,
  and incident location.
- Pangkat and case assignments reference the eligible user's account directly.

## Hearing Progression and Docketing Lifecycle

- Hearing schedules are tracked in `hearings` and associated deadlines in `case_deadlines`.
- **Progression Sequence**:
  - Up to 3 Mediation hearings (`1st Mediation`, `2nd Mediation`, `3rd Mediation`).
  - Followed by up to 3 Conciliation hearings (`1st Conciliation`, `2nd Conciliation`, `3rd Conciliation`).
  - Scheduling halts permanently once the 3rd Conciliation hearing is reached.
- **Automatic Docketing**:
  - Scheduling the `1st Mediation` hearing automatically transitions `cases.case_status` to `'Docketed'`
    and synchronizes `complaints.status` to `'Docketed'` in the same database transaction.

The schema keeps historical audit, log, document, and service records when a
user is removed by setting their actor reference to `NULL`. Core parent
records that would orphan legal case data are protected from deletion.
