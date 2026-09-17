# PROJECT_HANDOFF

## Project

AGAP (Automated Grievance Assistance Platform) is a PHP/MySQL web application for Barangay Tumana's Lupong Tagapamayapa. Its intended workflow is complaint filing, case docketing, mediation, Pangkat/conciliation, arbitration or settlement, Certification to File Action (CFA) issuance, and archiving.

The reference requirements are in `Module Development - Latest.pdf`.

---

## Development Priority

Prioritize missing features required by the Module Development specification over extensive hardening or refactoring of existing features.

For each increment:

- Implement one missing feature end-to-end.
- Add only the validation, authorization, audit logging, and error handling directly required by that feature.
- Avoid broad project-wide hardening unless it is necessary for the new feature to function safely.
- Preserve unrelated and uncommitted changes.

---

## Core Workflow Redesign (September 2026)

The following redesign is implemented as the current UX direction:

- **Complaint review gate:** New complaints are `Filed`; reviewers can set
  `Under Review`, `Needs Information`, `Accepted`, or `Rejected` with review
  notes. Only `Accepted` complaints may be docketed, and docketing changes the
  complaint to `Docketed` in the same transaction.
- **Complaint workspace:** The complaint details page consolidates review,
  parties, dedicated picture/video/document evidence uploads
  (JPG/PNG/PDF/MP4/WebM, up to 25 MB), additional details, and the incident
  location form. This replaces a fragmented complaint flow and no longer
  requires normal users to open the separate GPS location page.

### Complaint incident fields

The `complaints` table now includes optional `incident_time`,
`incident_location`, and `incident_landmark` fields in addition to the
existing date, narrative, and additional details. The details page stores the
full location description and optional coordinates in `incident_locations`.
- **Case workspace:** Opening a case now leads to `case-details.php`, which
  presents the case overview, team, hearings, generated documents, and proof
  of service together. The case list remains the cross-case monitoring screen.
- **Atomic case team:** Case Assignments now saves Head, Secretary,
  and Member together. All three must be distinct active users with the role
  name `Lupon Member`. The write is transactional and synchronizes existing
  Pangkat tables only to keep the existing KP Form 12 data source compatible.
  The separate Pangkat page redirects to Case Assignments and is no longer an
  operational feature.
- **Document-linked service:** Proof-of-service records require a generated
  document belonging to the selected case. Saving proof marks the document
  `Served`; document service states are `Generated`, `For Service`, `Served`,
  and `Service Failed`.

### Database change

`database/schema.sql` is the fresh-install source of truth. Apply
`database/migrations/20260914_core_case_workflow_redesign.sql` and then
`database/migrations/20260916_complaint_incident_details.sql` and
`database/migrations/20260917_case_team_roles.sql` once to an
existing populated database before using the redesigned fields and relations.
Alternatively, a database that predates all three changes can run the single
`database/migrations/20260917_consolidated_workflow_upgrade.sql` script instead.
Never run both the consolidated and individual migration paths.
Resolve any duplicate case/assignment-role data before adding the unique
assignment constraint.

### Remaining follow-up

- Add a controlled UI action for the `For Service` and `Service Failed`
  document states; the current flow sets `Generated` on generation and `Served`
  when proof is recorded.
- Keep future KP forms inside the case workspace/document center and require a
  stated regeneration reason when a form is generated again.

### Hearing scheduling refinement

- The Hearings page provides a calendar view backed by the authenticated
  `backend/api/hearings/calendar.php` endpoint.
- Scheduling from the Case Workspace preselects that case.
- Client and server validate future dates; Initial Hearings retain the
  five-calendar-day docketing limit.
- The scheduler must review the selected case, hearing type, date/time, venue,
  and remarks in a confirmation dialog before the API creates or updates it.
- Administrators and Lupon Clerks can click an eligible calendar day to start a
  new schedule with that date prefilled, or click an existing calendar entry to
  edit it. Lupon Members remain read-only and open entries for viewing.

### Global workflow popups

- `frontend/assets/js/app.js` provides the shared `window.agapNotify()` toast
  component, styled in `frontend/assets/css/app.css`.
- Existing in-page status and alert regions display as dismissible popups in
  addition to their normal inline message.
- While authenticated, AGAP checks the existing notification inbox every
  45 seconds and shows newly received unread workflow notifications as popups.
  This covers assignments, hearings, documents, and future notifications that
  use `NotificationService`, without creating separate popup logic per module.

---

## Current Stack and Database

- PHP, HTML/CSS/JavaScript, MySQL, and Laragon.
- The canonical fresh-database schema is `database/schema.sql`.
- The database name is `agap_db`.
- The database connection is in `backend/config/database.php`.
- Active users whose role name is `Lupon Member` are directly eligible for the Head, Secretary, and Member case team.
- Do not introduce a separate `lupon_members` table.
- Complaint parties are stored in `complaint_parties`.
- Complaint attachments are stored in `complaint_attachments`.
- Do not introduce parallel complaint-member, complaint-file, or document tables.

---

## Implemented or Substantially Present

- Login/logout, user CRUD, role records, session-based redirects, and basic audit logging.
- Resident CRUD.
- Complaint CRUD.
- Complaint party management using `complaint_parties`.
- Complaint attachment management using `complaint_attachments`.
- Complaint details workflow with integrated party and attachment management.
- Secure complaint attachment upload, download, deletion, and linkage.
- Complaint categories, case docketing, case update, archive, and searchable records.
- Dashboard count cards for total, settled, CFA-issued, and archived cases.
- Case Assignment page/API with an atomic three-member team; the former Pangkat page redirects to it.
- Active users with role name `Lupon Member` are directly assignable.
- Hearing creation, update, viewing, calendar display, and attendance storage.
- Settlement, arbitration, CFA, incident-location, proof-of-service, AI chatbot, and narrative-generation endpoints/models exist.
- GPS incident-location and proof-of-service workflows are available.
- Secure password change and password-reset workflows are available.
- Complaint and case numbering use database-generated IDs and transactions.
- The schema contains foreign keys, key uniqueness rules, one case per complaint, and one resolution record per case where applicable.

---

## Reports and Export Increment

- Record-based monthly, quarterly, annual, and DILG reports are available to Administrators and Lupon Clerks.
- Reports include totals, case-status/category breakdowns, hearings, resolution/CFA/archive details, and case-level rows.
- CSV export uses the existing `generated_reports` table and audit logging; no schema change was required.

---

## Notification Inbox and Workflow Increment

The existing `notifications` table is now used for an authenticated inbox and automatic workflow updates. No schema change or duplicate notification table was introduced.

### Implemented

- Notification Inbox page for Administrator, Lupon Clerk, Lupon Member, and Summons Server roles.
- Per-user list with unread count, unread-first ordering, and owner-only mark-as-read behavior.
- JSON list/read APIs with method, session, and role checks, plus audit logging of read actions.
- Case assignment notification to the assigned Lupon Member.
- Hearing creation/update notifications to assigned case and Pangkat members.
- Pangkat formation notification to active Administrators/Lupon Clerks and appointment notification to the appointed Lupon Member.
- KP Form 12 generation notification to assigned case/Pangkat members.
- The workflow actor is excluded from their own event notification.
- Pangkat create/member APIs now have Administrator/Lupon Clerk authorization, JSON responses, and input validation.

### Files Added or Updated

```text
backend/models/Notification.php
backend/services/NotificationService.php
backend/controllers/NotificationController.php
backend/controllers/AssignmentController.php
backend/controllers/HearingController.php
backend/controllers/PangkatController.php
backend/controllers/DocumentController.php
backend/api/notifications/list.php
backend/api/notifications/read.php
backend/api/pangkat/create.php
backend/api/pangkat/update.php
frontend/pages/notifications/inbox.php
frontend/assets/js/notifications.js
frontend/assets/css/notifications.css
frontend/layouts/sidebar.php
```

---

## Hearing Scheduling and Deadline Increment

The hearing workflow was extended across the schema, model, controller, API, page, and JavaScript.

### Implemented

- Server-side validation for hearing creation and update.
- Hearing types: `Initial Hearing`, `Mediation`, `Conciliation`, and `Arbitration`.
- Validation for missing/archived cases, invalid or past dates, invalid hearing types, blank venues, and oversized input.
- Initial Hearing scheduling safeguard within five calendar days of docketing.
- Conflict detection for:
  - the same case at the same date/time; and
  - the same venue at the same date/time.
- Transaction-based hearing writes.
- Automatic deadline creation/update for Initial Hearing, Mediation, and Conciliation.
- Deadline-list JSON endpoint and deadline table.
- Derived `Pending`, `Completed`, or `Overdue` deadline display.
- Audit logging for successful creation and update.
- Administrator and Lupon Clerk can create/update hearing schedules.
- Administrator, Lupon Clerk, and Lupon Member can view schedules and deadlines.

### Lupon Member Read-Only Hearing UI

- Lupon Members can view hearing schedules, details, and deadlines.
- Lupon Members do not see the Schedule Hearing button.
- Lupon Members do not receive add/edit hearing modals.
- Lupon Members do not see Edit buttons.
- Server-side create/update authorization remains enforced.

Important: `const canManageHearings` must be declared only once in `frontend/assets/js/hearings.js`. A duplicate declaration prevents the JavaScript file from running and makes the hearing/deadline tables appear empty.

### Hearing Database Change

`database/schema.sql` includes:

```sql
UNIQUE KEY uq_case_deadlines_case_type (case_id, deadline_type)
```

For an existing populated database, duplicate `(case_id, deadline_type)` rows must be resolved before applying this unique key.

---

## Document Generation Foundation and KP Form 12

The shared document-generation foundation has been started, and the first form implemented is:

```text
KP Form 12 - Paabiso ng Pagdinig
Notice of Hearing for Conciliation Proceedings
```

The order of KP form implementation does not need to follow the official form-number chronology. Additional official forms may be uploaded and implemented individually later.

### KP Form 12 Implemented Components

- Document Center page.
- Active case selection.
- KP Form 12 case-data preview.
- Automatic retrieval of:
  - case number;
  - complaint title;
  - complainants;
  - respondents;
  - Conciliation hearing date/time;
  - hearing venue; and
  - Pangkat Chairman.
- Actual PDF generation through Dompdf.
- PDF storage under `storage/generated-documents/<year>/<case-number>/`.
- Document template creation/reuse through `document_templates`.
- Generated-document metadata through `generated_documents`.
- Generated-document listing.
- Authenticated PDF viewing/downloading.
- Generated-document deletion for allowed roles.
- Audit logging for document generation and deletion.
- Administrator and Lupon Clerk can generate/delete documents.
- Lupon Members can view generated documents but cannot generate or delete them.

### Composer-Free Dompdf Installation

Composer is not available on the current development device. Dompdf must therefore use the packaged release installed at:

```text
backend/libs/dompdf/
```

The required autoloader is:

```text
backend/libs/dompdf/autoload.inc.php
```

`PDFService.php` must load the packaged library through that path instead of `AGAP/vendor/autoload.php`.

Expected structure:

```text
backend/libs/dompdf/
├── autoload.inc.php
├── lib/
├── src/
└── vendor/
```

The `vendor` directory inside `backend/libs/dompdf/` belongs to the packaged Dompdf release and must not be removed.

### Existing Database Tables Reused

The KP Form 12 increment did not change `database/schema.sql`.

It uses the existing tables:

```text
document_templates
generated_documents
cases
complaints
complaint_parties
residents
hearings
pangkat_groups
pangkat_members
users
audit_trails
```

No new document table or migration was introduced.

### KP Form 12 Data Requirements

A case must have:

- at least one complainant;
- at least one respondent;
- a Conciliation hearing;
- a hearing venue;
- a Pangkat group; and
- a Pangkat Chairman.

The preview loads even when some values are missing and shows values such as `Missing`, `Not scheduled`, or `Not assigned`. PDF generation is rejected until required fields exist.

### KP Form 12 Files Added or Updated

```text
backend/models/Document.php
backend/services/PDFService.php
backend/controllers/DocumentController.php
backend/api/documents/generate.php
backend/api/documents/cases.php
backend/api/documents/kp12-data.php
backend/api/documents/list.php
backend/api/documents/download.php
backend/api/documents/delete.php
frontend/pages/documents/document-center.php
frontend/assets/js/documents.js
frontend/assets/css/documents.css
```

The packaged third-party Dompdf files reside under:

```text
backend/libs/dompdf/
```

### KP Form 12 Current Testing Status

Confirmed:

- The Document Center loads.
- Active cases appear in the dropdown.
- `kp12-data.php` returns data when called with a valid `case_id`.
- The case preview displays stored party, hearing, venue, and Pangkat information.
- Missing respondent and missing Pangkat Chairman conditions are visible in the preview.

Still to verify:

- Missing-data generation rejection.
- Successful generation after adding a respondent and Pangkat Chairman.
- PDF storage and database record insertion.
- Generated PDF visual layout against the uploaded reference.
- View/download behavior.
- Delete behavior.
- Audit records.
- Administrator, Lupon Clerk, and Lupon Member role flows.
- PHP lint in Laragon.

### Deferred Document Work

Do not continue adding KP forms in the next increment unless specifically requested. KP Form 12 should remain available for completion and visual verification later.

Future forms should reuse the same Document model, controller, storage, listing, download, deletion, audit, and permission foundation rather than creating separate document systems.

---

## GPS Incident Location and Proof-of-Service Increment

The existing `incident_locations` and `proof_of_service` tables are now used
for authenticated field documentation. No duplicate GPS or proof tables were
introduced.

### Implemented

- Incident-location map with complaint selection, coordinate/address
  validation, and saved-location markers.
- Administrator, Lupon Clerk, and Summons Server location-write access; Lupon
  Members have read-only location-map access.
- Proof-of-service recording for active cases, with service details, optional
  proof image, service history, duplicate-entry protection, and case linkage.
- Secure JPG, PNG, and WebP uploads limited to 5 MB, stored under
  `storage/uploads/proof-of-service/` using random filenames.
- Authenticated proof-image retrieval constrained to the storage directory.
- JSON APIs with method/session/role checks, server-side validation, and
  audit logging for location and proof mutations.

### Testing Status

- PHP lint, JavaScript syntax check, and `git diff --check` passed.
- Browser/MySQL acceptance testing remains required in Laragon, including
  actual image upload, saved markers, and authorized/denied role flows.
- Leaflet/OpenStreetMap map tiles require browser network access.

---

## Account Security Increment

Password security now has both an authenticated change-password path and a
one-time reset flow. `database/schema.sql` includes the canonical
`password_reset_tokens` table.

### Implemented

- Change-password page/API available to every authenticated application role.
- Current-password verification and password policy: at least 12 characters
  with uppercase, lowercase, and a number.
- Session ID regeneration after successful login and password changes.
- Inactive users are rejected at login and cannot receive/reset passwords.
- Password-reset request, token reset page, and JSON APIs.
- One-hour, single-use, SHA-256-hashed reset tokens; raw tokens are only sent
  in the reset link and are never stored in the database.
- Generic reset-request results to avoid revealing whether an account exists.
- Audit entries for reset requests, resets, and password changes.
- A reset-link entry on login and a change-password entry in the authenticated
  navbar.

### Existing Database Step

For a populated database, apply once:

```text
database/migrations/20260813_add_password_reset_tokens.sql
```

For a new database, `database/schema.sql` already creates the table.

### Configuration and Testing Limitations

- PHP mail must be configured for actual email delivery.
- Set `AGAP_APP_URL` to the deployed application base URL so reset links use
  the correct host; the local fallback is `http://localhost/AGAP`.
- PHP lint, JavaScript syntax check, and `git diff --check` passed.
- Still verify mail delivery plus expired/reused token, password-policy,
  inactive-account, and browser role/session flows in Laragon.

---

## Present but Incomplete or Requiring Verification

- Project-wide RBAC remains inconsistent, but broad RBAC hardening is not the immediate priority.
- Complaint party and attachment workflows require full Laragon/MySQL verification.
- Hearing attendance storage exists, but complete attendance and missed-hearing workflows remain incomplete.
- GPS/proof workflows need Laragon acceptance testing, including actual image
  upload and authorized/denied role scenarios.
- AI calls Gemini, but production-grade monitoring and rate limiting are incomplete.
- Case history and deadline structures exist, but all automatic status-transition rules are not connected.
- KP Form 12 is implemented but needs successful PDF generation and visual acceptance testing with complete case data.

---

## Missing Module Development Features

The following are still missing or not complete enough to be considered implemented:

- Resident self-service complaint filing and status tracking.
- Complete summons workflow and delivery linkage.
- Remaining official KP forms and generated-document templates.
- Complete attendance, missed-hearing, deadline-completion, and case-status workflow.
- Automated tests, user acceptance testing, deployment, and backup procedures.

---

## Next Development Step

Move temporarily away from adding KP forms and implement another missing Module Development feature.

### Recommended Next Module: Resident Self-Service Complaint Filing and Status Tracking

Implement a resident-facing complaint filing and status-tracking workflow.
First confirm the resident account/authentication approach in the Module
Development requirements and existing schema before adding new tables or roles.

### Scope Control for the Next Increment

Do not perform broad unrelated hardening. Only add security and validation
directly required by the resident self-service feature.

Do not:

- rewrite the hearing or account-security workflows;
- add more KP forms;
- restructure unrelated models;
- replace the canonical schema;
- introduce duplicate report or document tables; or
- overwrite uncommitted changes.

The next missing-feature priority is the GPS/proof-of-service workflow.

---

## Feature-Focused Development Order

1. Complete resident self-service complaint filing and status tracking.
2. Complete summons and service/delivery workflow.
3. Return to remaining official KP forms one form at a time.
4. Complete attendance, missed-hearing, and advanced status/deadline automation as needed.
5. Add broader testing, security review, deployment, and backup procedures.

This order prioritizes missing Module Development features. It does not mean existing defects should be ignored when a defect blocks the current feature.

---

## Instructions for the Next Developer or AI

- Read this handoff and `AI_DEVELOPMENT_GUIDE.md` first.
- Inspect the latest project archive and run `git status` before editing.
- Preserve `database/schema.sql` as the source of truth for a fresh `agap_db`.
- Document populated-database migrations separately if a schema change is truly required.
- Preserve active `Lupon Member` user eligibility for Case Assignment and Pangkat.
- Do not create a `lupon_members` table.
- Extend `complaint_parties`, `complaint_attachments`, `document_templates`, and `generated_documents` rather than replacing them.
- Preserve the existing KP Form 12 implementation while working on another module.
- Prioritize implementing missing Module Development features over broad hardening.
- Use frontend page/JavaScript -> API -> controller -> model/service -> PDO.
- Use prepared PDO statements.
- Add server-side validation and role checks directly required by the feature.
- Return JSON from APIs with suitable HTTP status codes.
- Audit successful mutations and exports when applicable.
- Do not rewrite unrelated modules.
- Do not overwrite uncommitted changes.
- Implement one feature end-to-end before moving to the next one.
- Run `php -l` on changed PHP files in Laragon.
- Run a JavaScript syntax check and `git diff --check` where available.
- Report changed files, database steps, testing performed, and remaining limitations.
