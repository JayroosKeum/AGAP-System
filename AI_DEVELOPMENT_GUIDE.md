# AGAP AI Development Guide

Use this file with `PROJECT_HANDOFF.md` when asking another AI or developer to
continue AGAP without sharing the whole project.

## Minimal context to share

Share these files first:

1. `AI_DEVELOPMENT_GUIDE.md` and `PROJECT_HANDOFF.md`.
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
  name `Lupon Member` is assignable to cases and Pangkat.
- Do not use `COUNT(*) + 1` to generate business numbers. Use an inserted ID
  inside a database transaction, as the Complaint and Case models do.
- For a one-record-per-case feature, add a unique `case_id` constraint and use
  an upsert only when editing the same record is the intended behavior.

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
- Validate all server-side inputs even if the page already validates them.

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
4. Run `php -l` on each changed PHP file and check `git diff --check`.
5. Do not claim a feature is complete just because a page, route, or table
   exists. Verify the full UI-to-database flow.
6. Preserve existing uncommitted work unless it directly conflicts with the
   requested module; report conflicts before overwriting it.

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
  for Case Assignment and Pangkat; do not introduce a lupon_members table.
- Use prepared PDO statements, server-side validation, JSON API responses,
  role checks, and audit logging for mutations.
- Do not rewrite unrelated modules or overwrite uncommitted changes.
- Implement the feature end-to-end, then lint changed PHP files and report
  the changed files, testing performed, and remaining limitations.
```
