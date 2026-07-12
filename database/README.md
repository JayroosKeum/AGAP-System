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

`preflight_integrity.sql` is read-only and identifies the duplicates and
orphaned rows that must be resolved before that migration.

## Relationship rules

- A complaint can be docketed into only one case.
- A resident can be attached to a complaint once per party type.
- Any active user with the `Lupon Member` role is eligible for assignment.
- A Lupon member can have an assignment only once for the same case and role.
- A case has at most one Pangkat group, settlement, arbitration record, CFA,
  and incident location.
- Pangkat and case assignments reference the eligible user's account directly.

The schema keeps historical audit, log, document, and service records when a
user is removed by setting their actor reference to `NULL`. Core parent
records that would orphan legal case data are protected from deletion.
