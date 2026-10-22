# Database setup

`schema.sql` is the canonical schema for a new AGAP database. It includes the
current migration-defined tables, columns, indexes, and reference data used by
the PHP models; a fresh installation must import this file only, not each
historical migration afterward.

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

## Hearing attendance monitoring upgrade

For an existing populated database whose `hearing_attendance` table does not yet
contain justification tracking and user audit fields, apply
`database/migrations/20260929_hearing_attendance_monitoring.sql` once. Fresh
databases receive these fields and constraints directly from `schema.sql`.

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
  - **Preliminary Paanyaya Dialogue Stages**:
    - `Paanyaya 1` (First Preliminary Invitation, up to 6 delivery attempts).
    - `Paanyaya 2` (Second Preliminary Invitation, up to 6 delivery attempts, unlocked after Paanyaya 1 review/escalation).
    - Cases in this stage hold the `Pre-Docketed (Paanyaya)` state; formal summons and mediation scheduling remain locked until service or administrative escalation.
  - **Formal Mediation Sessions**:
    - Up to 3 Mediation hearings (`1st Mediation`, `2nd Mediation`, `3rd Mediation`).
  - **Pangkat Conciliation Proceedings**:
    - Followed by up to 3 Conciliation hearings (`1st Conciliation`, `2nd Conciliation`, `3rd Conciliation`).
  - Scheduling halts permanently once the 3rd Conciliation hearing is reached.
- **Automatic Docketing**:
  - Completing Paanyaya delivery requirements unlocks formal summons issuance. Issuing `1st Mediation` summons automatically transitions `cases.case_status` to `'Docketed'`
    and synchronizes `complaints.status` to `'Docketed'` in the same database transaction.

The schema keeps historical audit, log, document, and service records when a
user is removed by setting their actor reference to `NULL`. Core parent
records that would orphan legal case data are protected from deletion.

## Hearing service and explanation workflow

Fresh databases receive hearing-party service history, explanations, legal-action review, and linked rescheduled hearing events from database/schema.sql. Existing databases must apply database/migrations/20260930_hearing_service_explanation_workflow.sql once after the hearing, generated-document, party, and user tables exist. This migration adds workflow tables and rescheduling linkage; it does not migrate legacy nonappearance decisions into verified-service records.

## Summon deliveries repair and mediation commencement (October 2026)

For existing databases updating to the integrated summons preview and mediation workflow, apply:
- `database/migrations/20261013_001_repair_summon_deliveries.sql`: Verifies and repairs the `summon_deliveries` table columns (`case_id`, `document_id`, `party_id`, `resident_id`, `party_type`, `recipient_name`, `delivery_status`, `attempt_count`, `service_method`, `served_by_user_id`, `served_at`).
- `database/migrations/20261013_002_mediation_commencement.sql`: Adds first mediation commencement tracking columns (`first_mediation_commenced_at`, `mediation_commenced_by`, `mediation_commencement_notes`) to the `cases` table.
- `database/migrations/20261013_003_summon_three_attempts.sql`: Upgrades `summon_deliveries` to support the multi-attempt summons lifecycle (up to 3 documented delivery attempts per party/hearing). Replaces the legacy unique index `uq_hearing_party_form` with `uq_hearing_party_form_attempt (hearing_id, party_type, form_type, attempt_number)` and converts `delivery_status` and `unserved_reason` to `VARCHAR(50)`.

## Pangkat conciliation and hearing workspace compatibility (October 2026)

For existing databases updating to the modernized hearing workspace, apply:
- `database/migrations/20261014_001_pangkat_stage_enhancements.sql`: Adds Pangkat constitution tracking, officer role selection (`pangkat_officers`), challenge resolution records (`pangkat_challenges`), and statutory 15-day extension requests (`pangkat_extensions`).
- `database/migrations/20261014_002_hearing_workspace_compatibility.sql`: Adds hearing workspace compatibility fields to the `hearings` table:
  - `substitute_presider_id`: Foreign key referencing `users.user_id` when the Punong Barangay is unavailable.
  - `substitute_reason`: Free-text justification for presiding officer substitution.
  - `parties_consent_to_substitute`: Boolean indicator confirming mutual party consent.
  - `complainant_attendance`, `respondent_attendance`: Independent hearing-level appearance states (`Present`, `Absent`, `Excused`).
  - `attendance_recorded_at`, `attendance_notes`: Timestamp and audit summary of the hearing's recorded preliminaries.

## Second Mediation Summons Delivery Rules

- Under Katarungang Pambarangay rules (RA 7160 Sec. 410), summons issuance applies to the initial mediation hearing.
- Subsequent mediation hearings (`2nd Mediation`, `3rd Mediation`) do not insert new pending records into `summon_deliveries` and do not require re-issuance of summonses. Prior notice is recognized, and attendance tracking is unlocked.
- If a party is recorded absent in the 2nd Mediation, the non-appearance escalation path issues KP Form 18 (for absent complainant) or KP Form 19 (for absent respondent).

## File Complaint & KP Form 7 Enhancements (October 2026)

For existing databases updating to the enhanced File Complaint module and versioned KP Form 7 handwritten scans, apply:
- `database/migrations/20261016_001_enhance_file_complaint_kp7.sql`:
  - **`complaints` Table**:
    - Expands `status` ENUM to support `'Draft'` filing mode.
    - Adds `document_status` ENUM (`'No Scan Uploaded'`, `'Scan Uploaded'`, `'Updated Scan Uploaded'`) to track physical document intake state.
    - Adds `requested_resolution` (TEXT) for prayer for relief, settlement assistance, or restitution requests.
    - Adds `is_time_approximate` (TINYINT 1) and `is_time_unknown` (TINYINT 1) to support flexible incident time recording.
    - Adds `incident_location_details` (TEXT) and `is_draft` (TINYINT 1).
  - **`complaint_parties` Table**:
    - Adds minor complainant/respondent protection and legal guardian representation: `is_minor`, `guardian_name`, `guardian_relationship`, `guardian_contact`, `guardian_address`.
    - Adds detailed demographic and verification fields: `preferred_contact` (`SMS`, `Call`, `Email`, `In-Person`), `suffix`, `occupation`, `age`, `birth_date`, `gender`, `civil_status`.
    - Adds unavailable information flags: `contact_unknown`, `middle_name_unknown`, `birthdate_unknown`, `address_requires_verification`, `residency_verified`.
  - **`residents` Table**:
    - Adds `suffix`, `occupation`, `preferred_contact`, and `residency_verified`.
  - **`complaint_scanned_documents` Table**:
    - Dedicated versioned repository for pre-signed and handwritten physical KP Form 7 uploads with metadata (`doc_id`, `complaint_id`, `case_id`, `file_name`, `file_path`, `file_type`, `file_size`, `version_number`, `is_current`, `replacement_reason`, `uploaded_by`, `uploaded_at`).
  - **`document_templates` Table**:
    - Ensures official entry for `KP Form 7` (Sumbong / Complaint) is registered.

## Intake Screening, Scheduling Conflicts & Mediation Improvements (October 2026)

For existing databases updating to the intake preliminary screening and enhanced scheduling conflict features, apply:
- `database/migrations/20261017_001_intake_screening_and_duplicate_acknowledgement.sql`:
  - **`complaint_intake_screenings` Table**:
    - Stores preliminary Katarungang Pambarangay eligibility criteria (government/public officer party, LGU jurisdiction checks, detention/habeas corpus flags, statutory penalty thresholds, duplicate dispute acknowledgement).
- `database/migrations/20261017_002_scheduling_conflict_and_mediation_improvements.sql`:
  - **`system_settings` Table**:
    - Updates/ensures `daily_hearing_capacity` default limit is set to 8.
  - **`hearings` Table**:
    - Adds `mediation_attempt` (TINYINT UNSIGNED) and `attempt_exception_reason` (TEXT) to track mediation attempt numbering (1 to 3).
    - Adds `cancellation_category` (VARCHAR 100) to classify hearing cancellations.
    - Expands `complainant_attendance` and `respondent_attendance` ENUMs to include `'Pending'`, `'Present'`, `'Absent'`, `'Late'`, and `'Excused'`.
    - Backfills `mediation_attempt` for existing mediation hearings.
  - **`scheduling_holidays` Table**:
    - Stores statutory and declared non-working holidays to prevent invalid hearing bookings.
  - **`venue_unavailabilities` Table**:
    - Stores facility/venue blackout windows and maintenance schedules to avoid room booking conflicts.

## Preliminary Paanyaya Stages & Officer's Return Tracking (October 2026)

For existing databases updating to the mandatory preliminary Paanyaya process before mediation and summons issuance, apply:
- `database/migrations/20261020_001_create_paanyaya_stages_and_returns.sql`:
  - **`paanyaya_stages` Table**:
    - Tracks stage metadata and status for `Paanyaya 1` and `Paanyaya 2` (`Not Started`, `In Progress`, `Successfully Delivered`, `Attempts Exhausted – For Review`, `Completed`, `Cancelled`).
    - Stores invitation schedule details (`invitation_date`, `invitation_time`, `venue`), salaysay narrative, electronic signature flag, and generated PDF link.
    - Captures administrative review decisions when attempts are exhausted (`review_status`, `review_notes`, `reviewed_by`, `reviewed_at`).
    - Enforces uniqueness per complaint and stage via `uq_complaint_stage (complaint_id, stage_type)`.
  - **`paanyaya_officer_returns` Table**:
    - Dedicated audit repository for up to six (6) independent delivery attempts per Paanyaya stage.
    - Records service attempt number (1 to 6), service timestamp (`service_datetime`), serving officer (`serving_officer_id`), respondent/intended recipient name, and service address visited.
    - Stores granular delivery outcomes (`Successfully delivered`, `Recipient unavailable`, `Recipient refused to receive`, `Respondent not found at the address`, `Incorrect or incomplete address`, `Other reason`).
    - Stores failure reason (`unsuccessful_reason`), serving notes/witness info (`remarks`), proof attachments (`proof_file_path`), GPS coordinates (`latitude`, `longitude`, `service_location`), and audit actors (`recorded_by`, `submitted_at`).
    - Enforces sequential attempt numbering and duplicate prevention via unique constraint `uq_stage_attempt (paanyaya_stage_id, attempt_number)`.



