# AGAP AI Development Guide

> **Authoritative Engineering & Katarungang Pambarangay (KP) Process Guide**  
> Use this file alongside `PROJECT_HANDOFF.md` when onboarding an AI assistant or software engineer to AGAP. It preserves architecture integrity, statutory legal compliance, database contracts, and engineering patterns.

---

## 1. Core Principles & Non-Negotiable Directives

1. **Do Not Rebuild Existing Processes from Scratch**:
   - Always trace existing UI, JavaScript, API, controllers, models, and database columns before modifying code.
   - Refactor or extend existing classes (`Summons`, `HearingAttendance`, `CaseModel`, `Settlement`, `ShowCauseService`, `SummonDeliveryService`, `SummonServiceGate`) instead of creating parallel architectures.
2. **Strict Database Schema & Type Alignment**:
   - Never insert records without required non-null columns (e.g., `case_id`, `hearing_id`).
   - All migrations must be idempotent (`IF NOT EXISTS`, conditional column/index checks via `INFORMATION_SCHEMA`).
   - Match all `ENUM` and `VARCHAR` constraints in SQL queries to prevent MySQL strict mode (`1364`, `1265`) truncation/default errors.
3. **No Redundant Entity Tables**:
   - Do **NOT** introduce a separate `lupon_members` table. Active users with `role_name = 'Lupon Member'` are eligible for unified case assignments (`case_assignments`) and Pangkat (`pangkat_constitutions`, `pangkat_members`).
   - Verified citizen identities live in `residents`; login accounts live in `users`. Never merge or confuse these entities.
4. **Transaction Integrity & Audit Logging**:
   - All multi-table mutations (complaint docketing, summons issuance, attendance recording, settlement execution) must run within a PDO transaction (`beginTransaction`, `commit`, `rollBack`).
   - Log all state transitions through `AuditService::log($userId, $action, $module, $recordId)`.
5. **Security, Session & Role Protection (RBAC)**:
   - Every API endpoint must invoke `session_start()`, check authentication (`$_SESSION['user_id']`), verify role authorization (`$_SESSION['role_id']`), and set `Content-Type: application/json`.
   - Never output plain HTML or unhandled exceptions from API endpoints; always return standard JSON with proper HTTP response codes.

---

## 2. Directory & Architecture Map

```text
AGAP/
|-- backend/
|   |-- api/                       # Lightweight HTTP endpoints (Auth, Validate, Dispatch)
|   |   |-- auth/                  # Login, logout, password reset
|   |   |-- complaints/            # Intake, edit, review, docket
|   |   |-- cases/                 # Case workspace, assignments, deadlines, archive
|   |   |-- summons/               # Issue summons, preview (preview.php), service gate, deliveries
|   |   |-- hearings/              # Schedule, attendance, deliveries, minutes
|   |   |-- pangkat/               # Constitution, constitution verification, officers
|   |   |-- settlements/           # KP 16/17, execution, repudiation, installments
|   |   `-- documents/             # KP Form generation, templates, service tracking, download.php
|   |-- config/                    # Database PDO connection (Database.php)
|   |-- controllers/               # Workflow coordination and audit log triggering
|   |-- middleware/                # Session and role-based access control
|   |-- models/                    # Data-access objects and prepared PDO statements
|   |   |-- Complaint.php          # Intake, validation, status progression
|   |   |-- CaseModel.php          # Case docketing, case numbering, lifecycle
|   |   |-- Summons.php            # Dual notice issuance (KP 8 & 9), 3-attempt limit
|   |   |-- SummonDelivery.php     # Multi-attempt summon delivery data-access
|   |   |-- HearingAttendance.php  # Attendance recording, monitoring KPI
|   |   |-- Pangkat.php            # Pangkat formation, 3-member quorum
|   |   `-- Settlement.php         # Amicable settlement, arbitration, repudiation
|   `-- services/                  # Business utilities & integrations
|       |-- AuditService.php       # System-wide immutable audit trail
|       |-- DeadlineService.php    # Consolidated deadline dispatcher
|       |-- MediationDeadlineService.php # 15 working-day calculation & legal PH holidays
|       |-- MediationCommencementService.php # First actual meeting tracking
|       |-- OfficeLogisticsService.php   # Venue conflicts, capacity, business hours
|       |-- PDFService.php         # KP Form PDF rendering engine & renderNoticeHtml
|       |-- ShowCauseService.php   # Non-appearance escalation (KP 18/19/21)
|       |-- SummonDeliveryService.php # Service matrix, attempt logging
|       |-- SummonDeliveryStatus.php  # Canonical delivery status enums & predicates
|       |-- SummonDeliveryValidator.php # Server-side delivery payload validation
|       `-- SummonServiceGate.php  # Gatekeeper for Mediation attendance unlocking
|-- database/
|   |-- schema.sql                 # Canonical fresh installation schema
|   `-- migrations/                # Additive, idempotent upgrade scripts
|-- frontend/
|   |-- assets/css/                # Vanilla CSS design system & tokens
|   |-- assets/js/                 # Client-side module controllers (complaints.js, summons-preview.js)
|   |-- layouts/                   # Shared header, navbar, sidebar, footer, summons-preview-modal.php
|   `-- pages/                     # PHP page views (complaints, cases, hearings, documents)
|-- storage/
|   |-- generated-documents/       # Output PDFs organized by year/case
|   `-- uploads/                   # Verified evidence attachments
`-- logs/                          # System runtime logs
```

---

## 3. Katarungang Pambarangay (KP) Statutory Process Roadmap

The Katarungang Pambarangay Law (RA 7160, Local Government Code Sections 399–422) mandates a strict, sequential dispute-resolution workflow:

```
[Complaint Intake] ──> [Administrative Review] ──> [Docketing (Case No. MM-SS-YYYY)]
                                                              │
                                                              ▼
                                                   [Issue 1st Summons]
                                            (KP 8 to Complainant + KP 9 to Respondent)
                                                              │
                                                              ▼
                                                   [Summons Service & Proof]
                                          (Served Personal / Substituted / Failed)
                                                              │
               ┌──────────────────────────────────────────────┴───────────────────────────────┐
               ▼                                                                              ▼
     [Service Confirmed]                                                            [Service Failed]
               │                                                                              │
               ▼                                                                              ▼
     [Mediation Hearing #1, #2, or #3]                                            [Issue 2nd / 3rd Summons]
               │                                                                   (Capped at 3 summons max)
               ├────────────────────────┬────────────────────────┐
               ▼                        ▼                        ▼
       [Both Present]           [Complainant Absent]     [Respondent Absent]
               │                        │                        │
               ▼                        ▼                        ▼
     [Mediation Meeting]          [KP Form 18]             [KP Form 19]
  (Opens 15 working-day clock)  (Show Cause Hearing)     (Show Cause Hearing)
               │                        │                        │
       ┌───────┴───────┐                ▼                        ▼
       ▼               ▼          [Unjustified?]           [Unjustified?]
  [Settlement]    [Failed]              │                        │
   (KP 16/17)          │          ┌─────┴─────┐            ┌─────┴─────┐
       │               ▼          ▼           ▼            ▼           ▼
       │         [Pangkat   [KP Form 21  [Reschedule  [Respondent  [Reschedule
       │         Referral]   (Bar Action)] Hearing]    Default /    Hearing]
       │               │                               CFA Eligible]
       │               ▼
       │       [Pangkat Constitution]
       │     (Chair, Sec, Member - Lupon)
       │               │
       │               ▼
       │     [Conciliation Hearing]
       │    (15 calendar days clock)
       │               │
       │       ┌───────┴───────┐
       │       ▼               ▼
       │  [Settlement]    [Failed]
       │   (KP 16/17)          │
       │       │               ▼
       │       │         [CFA Issuance]
       │       │      (KP 20 / 22 to Court)
       │       ▼
       └─> [Repudiation Window: 10 Days]
               │
       ┌───────┴───────┐
       ▼               ▼
  [Repudiated]    [Final & Executory]
   (Nullified)    (Execution by Lupon)
```

---

## 4. Subsystem Specifications & Rules

### 4.1. Complaint Intake & Case Docketing
- **Intake**: A complaint begins as `Filed`. Allowed reviewers change it to `Under Review`, `Needs Information`, `Accepted`, or `Rejected`.
- **Docketing**: Only `Accepted` complaints can be docketed. Docketing generates an official case number in the strict **`MM-SS-YYYY`** format (e.g., `10-01-2026` for October, 1st case, year 2026) using `CaseModel::generateCaseNumber()`.
- **Atomic Creation**: Docketing updates `complaints.status = 'Docketed'` and creates a record in `cases` inside a single transaction.

### 4.2. Summons Issuance & Service Gate
- **Dual Form Issuance**: Issuing the 1st Summons (`Issue Summon 1st Attempt`) generates **both**:
  1. **KP Form 8**: Notice of Hearing for the Complainant.
  2. **KP Form 9**: Summons for the Respondent.
- **Button Renaming & Progression**: Renamed from `Issue 1st Summon` to `Issue Summon 1st Attempt`. Progresses dynamically to `Issue Summon 2nd Attempt` and `Issue Summon 3rd Attempt` as delivery failures are recorded.
- **KP Form 8 Auto-Served on Print**:
  - During First Mediation stage, when KP Form 8 is printed, the system automatically marks its service status as `Served` via the print workflow (`SummonDeliveryService::markKp8Printed`).
  - Distinctly logged in the audit trail without creating false physical delivery receipts.
  - Strictly isolated: does **not** auto-serve KP Form 9 or alter the respondent's summons status.
  - Idempotent: repeated print requests do not create duplicate service records.
- **Three KP Form No. 9 Delivery Attempts Tracking (`SummonDeliveryService`)**:
  - **Attempt 1**: Initiated upon summons issuance (`Issue Summon 1st Attempt`), creating a delivery record (`Pending`) and generating KP Form 9 PDF.
  - **Attempt 2**: Enabled only after Attempt 1 is completed and recorded as a qualifying delivery failure (`Unserved`).
  - **Attempt 3**: Enabled only after both Attempts 1 and 2 are recorded as qualifying delivery failures.
  - **Attempt 4 Ceiling**: A 4th attempt is strictly blocked per statutory service cycle rules.
  - **Successful Service**: If any attempt is successfully served (`Served Personal`, `Served Substituted`, `Served Refused`), failed delivery progression stops immediately.
  - **History Preservation**: Independent records are maintained for each attempt with attempt number, server, timestamp, failure reasons, and photo proof attachments without overwriting previous attempts.
- **Conditional Certificate to File Action (CFA) Unlocking (`SummonDeliveryService::getCfaEligibility`)**:
  - For the failed-summons path, the CFA document (KP Form 20) unlocks for printing **only** when all three (3) summons delivery attempts have completed with qualifying delivery failures and no attempt succeeded.
  - Shows real-time locked states (`CFA Locked — X of 3 summons attempts resulted in delivery failure`).
  - CFA issuance generates **KP Form 20** PDF via `PDFService::generateKp20()` and transitions case status to `CFA Issued`.
- **Service Gate (`SummonServiceGate`)**:
  - Mediation attendance **cannot** be recorded until proof of summons service is confirmed in `summon_deliveries`.
  - Successful statuses: `'Served'`, `'Served Personal'`, `'Served Substituted'`.
  - Unsuccessful / review statuses: `'Refused'`, `'Served Refused'`, `'Unlocatable'`, `'Wrong Address'`, `'Unserved'`.

### 4.3. Mediation & Conciliation Deadlines
- **Mediation Clock**:
  - Maximum **15 working days** under Article 410(b) of RA 7160.
  - Calculated by `MediationDeadlineService::calculateDeadline()` taking into account weekends and official Philippine legal holidays.
  - The statutory clock formally starts upon the **first actual appearance of both parties**, tracked via `mediation_commencements`.
- **Conciliation Clock**:
  - Maximum **15 calendar days** from the date the Pangkat is constituted, extendable by another 15 days only for clearly justifiable causes.
- **Testing & Development Bypass**:
  - In `OfficeLogisticsService.php` and `Summons.php`, strict delivery window (8 AM – 6 PM), weekend blocks, and holiday restrictions may be toggled for dev/testing environments, but the core business calculation logic must remain intact.

### 4.4. Attendance & Non-Appearance Escalation
- Attendance statuses: `Present`, `Late`, `Absent`, `Excused`.
- **Complainant Non-Appearance**:
  - Generates **KP Form 18** (Notice of Hearing to Complainant to explain non-appearance).
  - Unjustified non-appearance results in case dismissal and issuance of **KP Form 21 (Certificate to Bar Action)** (`DISMISSED_BARRED`).
- **Respondent Non-Appearance**:
  - Generates **KP Form 19** (Notice of Hearing to Respondent to explain non-appearance).
  - Unjustified non-appearance bars counterclaims (`RESPONDENT_DEFAULT`), enables issuance of **KP Form 20 (CFA)**, and allows contempt petition before the court.

### 4.5. Pangkat ng Tagapagkasundo (Conciliation)
- Constituted upon mediation failure.
- Composed of **3 active Lupon Members** chosen by parties or drawn by lot:
  1. Pangkat Chairperson (`Head`)
  2. Pangkat Secretary (`Secretary`)
  3. Pangkat Member (`Member`)
- All 3 must be distinct active users with role `Lupon Member`.
- Saved atomically into `case_assignments` and synced to `pangkat_constitutions`.

### 4.6. Settlements, Repudiation & Execution
- **Amicable Settlement (KP Form 16)** or **Arbitration Award (KP Form 17)**:
  - Updates `cases.case_status = 'Settled'` and `complaints.status = 'Settled'`.
- **10-Day Repudiation Window**:
  - Any party may repudiate the settlement within **10 calendar days** from the date of settlement by filing a sworn statement with the Lupon Chairperson citing fraud, violence, or intimidation.
  - If repudiated, updates `cases.case_status = 'Repudiated'` and logs into `case_history`.
- **Execution**:
  - After 10 days without repudiation, the settlement has the force and effect of a final court judgment.
  - The Punong Barangay / Lupon Chairperson executes the settlement through a writ of execution within 6 months.

---

## 5. Database Schema Standards & Reference

### 5.1. `summon_deliveries` Table Contract
To ensure backwards compatibility with existing UI forms and new service architectures, `summon_deliveries` must support the following column definitions:

| Column Name | Type | Constraints / Description |
|---|---|---|
| `delivery_id` | INT UNSIGNED | Auto Increment, Primary Key |
| `hearing_id` | INT UNSIGNED | NULLable, FK to `hearings` |
| `case_id` | INT UNSIGNED | NULLable, FK to `cases` |
| `party_type` | ENUM | `'Complainant'`, `'Respondent'` |
| `resident_id` | INT UNSIGNED | NULLable, FK to `residents` |
| `party_id` | INT | NULLable, alias for `resident_id` |
| `form_type` | ENUM | `'Notice of Hearing'`, `'Summon'`, `'KP Form 18'`, `'KP Form 19'` |
| `recipient_name` | VARCHAR(255) | Full name of receiving party |
| `delivery_status` | VARCHAR(50) | Default `'Pending'`. Supports `'Served Personal'`, `'Served Substituted'`, `'Served'`, `'Refused'`, `'Unserved'` |
| `unserved_reason` | VARCHAR(50) | NULLable. Supports `'Moved Out'`, `'Wrong Address'`, `'No One Home'`, `'Refused'`, `'Unlocatable'`, `'Other'` |
| `failure_notes` | TEXT | NULLable. Detailed explanation/neighbor statements for delivery failure |
| `attempt_count` | INT UNSIGNED | Default 1 |
| `attempt_number` | TINYINT UNSIGNED | Attempt order: 1, 2, or 3 (Unique index: `uq_hearing_party_form_attempt`) |
| `service_method` | VARCHAR(50) | e.g. `'Personal'`, `'Substituted'` |
| `served_by` | INT UNSIGNED | NULLable, user ID of server |
| `served_by_user_id` | INT | NULLable, user ID of server |
| `served_at` | DATETIME | Timestamp of actual delivery |
| `document_id` | INT | FK to `generated_documents` |
| `attachment_path` | VARCHAR(1024) | NULLable path to photo proof of service image |

### 5.2. `cases.case_status` and `case_history.status` ENUMs
All status writes must conform to:
```sql
ENUM(
    'Docketed',
    'Mediation',
    'Conciliation',
    'Arbitration',
    'Settled',
    'Dismissed',
    'CFA Issued',
    'Archived',
    'DISMISSED_BARRED',
    'RESPONDENT_DEFAULT',
    'Repudiated',
    'Breached'
)
```

### 5.3. Case Number Format
```text
Format: MM-SS-YYYY
Example: 10-01-2026 (Month: 10, Sequential Series: 01, Year: 2026)
Generator: CaseModel::generateCaseNumber($conn, $docketDate, $caseId)
```

---

## 6. Development Checklist for Any AI Modification

Before marking any task as complete:

- [ ] **Trace the full stack**: Page (`frontend/pages/`) ➔ JavaScript (`frontend/assets/js/`) ➔ API (`backend/api/`) ➔ Controller/Service (`backend/controllers/` or `backend/services/`) ➔ Model (`backend/models/`) ➔ MySQL Database (`agap_db`).
- [ ] **Verify column nullability & defaults**: Never execute an `INSERT` statement that omits a column defined as `NOT NULL` without a default value.
- [ ] **Preserve backward compatibility**: Support existing parameters while normalizing inputs.
- [ ] **Check PHP syntax**: Run `php -l <path-to-file>` on all edited PHP files.
- [ ] **Check PHP error log**: Review `C:\laragon\tmp\php_errors.log` to confirm no SQL or runtime exceptions occurred.
- [ ] **Audit all mutations**: Invoke `AuditService::log()` for any successful create, update, or status change.

---

## 7. Copy-Ready Prompt for Future AI Conversations

```text
You are pair-programming on the AGAP (Automated Barangay Katarungang Pambarangay Management System) PHP/MySQL project.
Read AI_DEVELOPMENT_GUIDE.md and PROJECT_HANDOFF.md before writing code.

Task: [Insert specific task or bugfix description]

Key Guidelines:
1. Do not rebuild working processes or create parallel workflows.
2. Maintain strict database alignment (agap_db) and check C:\laragon\tmp\php_errors.log for runtime errors.
3. Use prepared PDO statements with transactions for all state mutations.
4. Enforce Katarungang Pambarangay rules (KP Forms 7-28, 15-day mediation clock, 3-summons cap, service gate).
5. Run "php -l" on all modified PHP files to guarantee zero syntax errors.
```
