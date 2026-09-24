# AGAP AI Development Guide

Use this file with `PROJECT_HANDOFF.md` when asking another AI or developer to
continue AGAP without sharing the whole project.

## Minimal context to share

Share these files first:

1. `AI_DEVELOPMENT_GUIDE.md` and the current `PROJECT_HANDOFF` file.
2. `database/schema.sql`.
3. The relevant Module Development PDF pages or a short description of the
   target module.
4. Every existing file in the target module: its page, JavaScript, API files,
   controller, model, and related service.
5. `backend/config/database.php`, the relevant middleware, and layout files if
   the work affects authentication or UI.

Never ask an AI to replace the schema or rewrite unrelated modules unless that
is the specific task.

## Folder structure

```text
AGAP/
|-- backend/
|   |-- api/<module>/          # Small HTTP entry points
|   |-- config/                # Database and external-service configuration
|   |-- controllers/           # Request/workflow coordination and audit calls
|   |-- middleware/            # Session authentication and role checks
|   |-- models/                # PDO queries and database write rules
|   `-- services/              # Reusable integrations and business utilities
|-- database/
|   |-- schema.sql             # Canonical schema for a fresh agap_db
|   |-- migrations/            # One-time scripts for populated databases
|   |-- seeds/                 # Local-only optional test data
|   |-- preflight_integrity.sql# Read-only checks before a legacy migration
|   `-- README.md              # Database setup notes
|-- frontend/
|   |-- assets/css/            # Module styles
|   |-- assets/js/             # Module client-side logic
|   |-- layouts/               # Shared header, navbar, sidebar, footer
|   `-- pages/<module>/        # PHP pages and forms
|-- storage/                   # Uploaded/generated files; do not commit secrets
|-- logs/                      # Runtime logs
|-- public/                    # Publicly served assets when applicable
|-- PROJECT_HANDOFF.md         # Current implementation and outstanding work
`-- AI_DEVELOPMENT_GUIDE.md    # This guide
```

## Existing module names

Use the same module name across `frontend/pages`, `frontend/assets/js`, and
`backend/api` where possible: `auth`, `users`, `residents`, `complaints`,
`cases`, `assignments`, `pangkat`, `hearings`, `documents`, `gps`, `ai`,
`notifications`, `reports`, `search`, and `dashboard`.

## Database contract

- Database name: `agap_db`; connection settings are in
  `backend/config/database.php`.
- `database/schema.sql` is the source of truth for a fresh database.
- IDs use `<entity>_id` (for example, `case_id`, `complaint_id`, `user_id`).
- Use `created_at` and `updated_at` where a record changes over time.
- Use foreign keys, explicit `NOT NULL` rules, unique indexes, and named
  constraints for new relationships.
- Do not use a separate `lupon_members` table. An active user with the role
  name `Lupon Member` is eligible for the unified case-team assignment.
- Do not use `COUNT(*) + 1` to generate business numbers. Use an inserted ID
  inside a database transaction, as the Complaint and Case models do.
- For a one-record-per-case feature, add a unique `case_id` constraint and use
  an upsert only when editing the same record is the intended behavior.
- For password resets, store only a hashed, single-use token with an expiry;
  never store a raw reset token or password. Document a populated-database
  migration whenever a reset-token table is added.
- `database/schema.sql` is the complete, single SQL script for a new database.
  For an older populated database that predates the workflow redesign, use
  `database/migrations/20260917_consolidated_workflow_upgrade.sql` instead of
  the three individual workflow migrations. Do not run both options.
- Existing databases that predate the complaint case-type field also need
  `database/migrations/20260924_add_complaint_case_type.sql` once. It adds
  `complaints.case_type` and backfills docketed complaints from their linked
  cases; do not run it when the field already exists. Fresh databases receive
  the field from `schema.sql`.
- Local development test users are seeded by `schema.sql` and are also
  available for existing local databases in `database/seeds/local_test_users.sql`.
  They include an Administrator, Lupon Clerk, Summons Server, and three Lupon
  Members for the required case team. Never retain the shared `password`
  credentials outside a local/test environment.

## Backend conventions

Flow: `frontend page/JS -> backend/api -> controller -> model/service -> PDO`.

- API files should validate request method and session/role access, set JSON
  content type, return a suitable HTTP status, and return JSON only.
- Controllers coordinate models, audit logging, and workflow decisions. They
  should not contain raw SQL.
- Models own prepared PDO statements. Never concatenate user input into SQL.
- Services contain reusable integrations such as audit, notifications, PDF,
  deadlines, and Gemini.
- Log successful create/update/archive actions through `AuditService`.
- For workflow notifications, reuse `notifications`, `Notification`, and `NotificationService`; notifications must be retrieved and marked read only by their owning authenticated user.
- Validate all server-side inputs even if the page already validates them.
- Authentication changes must regenerate the session ID after login or a
  password change, enforce active-account status, and audit successful
  password changes and reset requests/completions.
- Password-reset responses must not reveal whether a username or email exists.
  Use `AGAP_APP_URL` for the deployed reset-link base URL when it is available.

### API file template

```php
<?php
session_start();
header('Content-Type: application/json');

require_once '../../controllers/ExampleController.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$result = (new ExampleController())->store($_POST);
http_response_code($result['success'] ? 201 : 422);
echo json_encode($result);
```

### Controller and model shape

```php
// backend/controllers/ExampleController.php
class ExampleController
{
    private $example;
    private $audit;

    public function __construct()
    {
        $this->example = new Example();
        $this->audit = new AuditService();
    }

    public function store(array $data): array
    {
        $result = $this->example->create($data);
        if ($result['success']) {
            $this->audit->log($_SESSION['user_id'], 'Created example', 'Examples', $result['id']);
        }
        return $result;
    }
}

// backend/models/Example.php
class Example
{
    private $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
    }

    public function create(array $data): array
    {
        // Validate expected values, then use a prepared INSERT/UPDATE.
        // Return ['success' => true, 'id' => (int) $this->conn->lastInsertId()].
    }
}
```

## Frontend conventions

- A module page belongs in `frontend/pages/<module>/` and its logic in
  `frontend/assets/js/<module>.js`.
- Reuse `header.php`, `sidebar.php`, `navbar.php`, and `footer.php`.
- Do not insert untrusted values using `innerHTML`; use an `escapeHtml` helper
  when constructing table rows or option labels.
- Use `fetch`, show a readable success/error message, and refresh the affected
  list after a successful write.

```javascript
const api = (url, options) => fetch(url, options).then(async (response) => {
    const data = await response.json();
    if (!response.ok) throw new Error(data.message || 'Request failed.');
    return data;
});
```

## Required quality checks for every module

1. Confirm schema, foreign keys, unique rules, and indexes before coding.
2. Add or update the model, controller, API, page, and JavaScript together.
3. Test allowed and denied roles, invalid input, duplicate submissions, and
   missing parent records.
4. For account-security work, test expired and reused tokens, inactive users,
   invalid current passwords, and password-policy failures.
5. Run `php -l` on each changed PHP file and check `git diff --check`.
6. Do not claim a feature is complete just because a page, route, or table
   exists. Verify the full UI-to-database flow.
7. Preserve existing uncommitted work unless it directly conflicts with the
   requested module; report conflicts before overwriting it.

## Core workflow and UX rules

- A complaint begins as `Filed`. A permitted reviewer moves it to `Under Review`,
  `Needs Information`, `Accepted`, or `Rejected`, with optional review notes.
  Only an `Accepted` complaint can be docketed; docketing changes it to
  `Docketed` in the same transaction as case creation.
- The Complaints page (`complaint-list.php`) is the primary record-search screen.
  It features a modern, compact dashboard layout: 4 KPI metric cards (Total, Under
  Review, In Progress, Settled) with 1-click filtering, quick status tabs with live
  counts, a compact toolbar with debounced search, clear button, Case Type dropdown
  (`All`, `Civil`, `Criminal`), Category dropdown, and a collapsible filter drawer for
  incident date ranges and exact status. Results appear in a dedicated 7-column table:
  `Complaint`, `Case No.`, `Category`, `Parties`, `Incident Date`, `Status`, and
  `Actions` (View Details link, Edit Page link, and Delete modal trigger).
  The standalone Records Search page remains available by direct URL for compatibility,
  while its sidebar navigation item is removed.
- Complaint intake and editing use dedicated full-page forms (`complaint-create.php`
  and `complaint-edit.php`) rather than popup modals:
  - Both pages share a modern, compact two-column card architecture.
  - **Classification & Case Type**: Category dropdown, narrative description, prayer for
    relief, and a mandatory **Case Type** dropdown (`Civil` or `Criminal`), stored in
    `complaints.case_type` and propagated to `cases.case_type` upon docketing.
  - **Integrated Parties**: Complainant and Respondent textboxes are embedded directly
    in the form (replacing the previous "Add Party" modal popup button), featuring live
    resident datalist autocomplete (`#residentOptions`) and automatic database
    synchronization into `complaint_parties`.
  - **Merged Incident Datetime**: Uses a single `<input type="datetime-local" name="incident_datetime">`
    on intake and edit, parsed server-side into `incident_date` (DATE) and `incident_time` (TIME).
  - **Incident Location & Map**: Requires specific location text, optional landmark, and
    an optional interactive Leaflet/OpenStreetMap pin selector. Coordinates are persisted
    in `incident_locations` in the same transaction as the complaint write.
  - **Edit Synchronization**: `complaint-edit.php` preloads all stored facts, parties,
    merged datetime, case type, and coordinates. Edits atomically update `complaints`,
    sync `case_type` to any linked `cases`, update `complaint_parties`, and update/clear
    coordinates in `incident_locations`. Edit buttons on `complaint-details.php` and
    `complaint-list.php` route directly to `complaint-edit.php?id=<id>`.
- Treat `frontend/pages/complaints/complaint-details.php` as the complaint
  workspace for review, parties, and picture/video/document evidence. Create
  and edit incident details, including the optional exact map pin, from the
  Add/Edit Complaint forms; do not create a separate
  Incident Locations page or location-only save route.
- Complaint intake requires the incident date and specific location, and records
  optional time, landmark, narrative, supporting details, and an optional exact
  map pin. Store an exact pin in `incident_locations` as part of the same
  complaint create/update transaction. On edit, preserve a saved pin unless the
  user moves or clears it. Evidence uploads validate MIME
  type and size server-side: JPG/PNG pictures, MP4/WebM videos, and PDFs are
  allowed up to 25 MB.
- Treat `frontend/pages/cases/case-details.php` as the case workspace: it is
  the record-level overview for case team, hearings, generated documents, and
  proof of service. Keep cross-case monitoring pages for lists and calendars.
- The Cases list omits the top-right Docket Case button, places Case Team
  Assignment above the table, and loads 25 cases per page from the existing
  database ordering. Pagination is server-side; keep the assignment case
  selector sourced from the full case list rather than only the visible page.
- Do not require a separate Pangkat workflow. Save the Head, Secretary,
  and Member together from Case Assignments, require three distinct active
  users whose role name is `Lupon Member`, and perform the replacement in one
  transaction. Existing `pangkat_groups` and `pangkat_members` may be synced
  internally for legacy KP-document compatibility; they are not a separate
  user journey.
- Case-team assignment depends on the case stage. In this implementation,
  `Docketed` and `Mediation` use the active `Administrator` as the automatic
  Barangay Captain/Head; Secretary and Member are unavailable and manual team
  saves must be rejected by the server. A `Conciliation` team may be initially
  saved once through the assignment form. After that, keep the assigned values
  read-only there and allow changes through the authorized case Edit operation
  only. Validate all three as distinct active `Lupon Member` users on the
  server and save case/status/team changes atomically without duplicate
  assignment rows. No schema change is needed for these rules.
- Proof of service must reference a generated document for the selected case.
  Generated-document service states are `Generated`, `For Service`, `Served`,
  and `Service Failed`; recording proof marks that document `Served`.
- Required form controls need a visible asterisk, an HTML `required` rule, and
  server-side validation. Provide useful character limits and file format/size
  guidance beside relevant inputs.
- Reuse `backend/services/ValidationService.php` for shared name, email, date,
  phone, address, and text checks. Current validation trims required values,
  rejects control characters and impossible dates, checks Philippine telephone
  number formats, validates existing dropdown choices, and checks upload MIME
  type/extension/size where those upload flows exist. Keep critical checks on
  the server; client checks are for immediate feedback. Do not normalize or
  rewrite existing stored records as part of adding validation.
- Hearing scheduling is case-based: link from the case workspace into the
  scheduling page with `case_id`, validate future dates and the Initial Hearing
  five-day docketing limit on both client and server, show calendar data from
  the hearing calendar API, allow staff to begin scheduling from an eligible
  calendar date and edit an existing calendar entry, and require review and
  confirmation before the final write.
- **Hearing Progression and Automatic Docketing Rules**:
  - Hearing sessions follow a statutory progression: up to 3 Mediation hearings
    (`1st Mediation`, `2nd Mediation`, `3rd Mediation`), followed by up to 3 Conciliation
    hearings (`1st Conciliation`, `2nd Conciliation`, `3rd Conciliation`).
  - Scheduling permanently halts once the `3rd Conciliation` hearing is scheduled.
  - Scheduling the `1st Mediation` hearing automatically triggers case docketing
    (`cases.case_status = 'Docketed'`) and updates complaint status (`complaints.status = 'Docketed'`)
    in the same database transaction.
- Use the shared `window.agapNotify(message, type, title)` toast layer for
  user-facing workflow feedback. It converts module status/alert messages into
  dismissible popups and polls the authenticated notification inbox for newly
  received unread workflow notifications. Do not create page-specific popup
  implementations for individual modules.

## Copy-ready prompt for another AI

```text
You are continuing the AGAP PHP/MySQL project. Read the attached
AI_DEVELOPMENT_GUIDE.md and PROJECT_HANDOFF.md first. The requirements are
based on the attached Module Development excerpt.

Task: [describe one module or bug only]

Attached project context: database/schema.sql plus all existing files for the
target module (frontend page, JavaScript, API endpoints, controller, model,
and related services).

Rules:
- Keep agap_db and database/schema.sql as the schema source of truth.
- Active users with role name "Lupon Member" must automatically be eligible
  for the Head, Secretary, and Member case-team roles; do not introduce a
  lupon_members table.
- Use prepared PDO statements, server-side validation, JSON API responses,
  role checks, and audit logging for mutations.
- Do not rewrite unrelated modules or overwrite uncommitted changes.
- Implement the feature end-to-end, then lint changed PHP files and report
  the changed files, testing performed, and remaining limitations.
```
