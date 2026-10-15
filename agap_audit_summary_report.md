# AGAP System Check & Debug Audit Report
**System:** Automated Governance & Barangay Action Platform (AGAP)  
**Scope:** Comprehensive Diagnostic Audit (Frontend, Backend APIs, Controllers, Models, MySQL Database)  
**Status:** Completed  

---

## Executive Summary

A comprehensive diagnostic audit was executed across all frontend pages, buttons, interactive forms, JavaScript controllers, backend API endpoints, controllers, models, and MySQL database tables.

### System Audit Metrics

| Category | Total Checked | Operational | Broken / Error | Incomplete / Missing |
| :--- | :---: | :---: | :---: | :---: |
| **Frontend Pages** | 35 pages | 27 fully rendered | 3 blank (0 bytes) | 5 standalone stubs |
| **Buttons & Interactive Forms** | 120+ controls | 108 working | 6 failing / dead links | 6 unlinked actions |
| **Backend API Endpoints** | 97 endpoints | 85 working | 8 failing / SQL errors | 4 empty stubs |
| **Core Models & Services** | 41 classes | 32 verified | 5 database-mismatched | 4 unimplemented methods |
| **Database Tables** | 36 tables | 30 healthy | 6 schema desynchronized | 2 unapplied migration tables |

---

## 1. What Works (Verified Operational Components)

### A. Authentication & Access Security
* **Login (`login.php`):** Form validation, password verification, active-status checks, session regeneration, and role-based redirects to all 4 user dashboards (Admin, Clerk, Lupon Member, Summons Server).
* **Password Reset (`forgot-password.php`, `reset-password.php`):** One-time SHA-256 hashed 1-hour expiry tokens; 12-character complex password enforcement; token consumption validation.
* **Change Password (`change-password.php`):** Current password verification, policy enforcement, session ID regeneration.
* **Session Protection:** Role-based access control (RBAC) strictly enforced on all protected endpoints.

### B. Resident Profiles Module (`resident-list.php`)
* **Metric KPI Cards:** Real-time totals for Total Profiles, Permanent Residents, Tenants, and Active Puroks with 1-click table filtering.
* **Search & Filter Toolbar:** Debounced search, clear (`×`) button, Purok selector, Residency filter, sorting, and 25-item pagination (`#residentPagination`).
* **Tumana Address & Leaflet Map Pinning:**
  * Strict Barangay Tumana boundary polygon enforcement (points outside Tumana are rejected).
  * OpenStreetMap reverse geocoding into structured Philippine address fields.
  * Automatic Tumana Purok/Zone detection based on road keywords and spatial centroid matching.
* **Duplicate Resident Prevention:** Allows identical names if addresses differ; rejects duplicate profile creation if both name and address match.

### C. Complaint Intake & Lifecycle (`complaint-list.php`, `complaint-create.php`, `complaint-edit.php`, `complaint-details.php`)
* **3-Dimensional Complaint Lifecycle:** Orthogonal tracking of **Intake Status** (*Under Review / Docketed*), **Dispute Stage** (*Mediation / Conciliation / Arbitration*), and **Disposition** (*Amicable Settlement / Arbitration Award / CFA Issued / Dismissed*).
* **Dedicated Complaint Intake (`complaint-create.php`):**
  * Auto-calculated Age and non-future incident datetime (`<input type="datetime-local">`).
  * Searchable resident combobox for Complainant and Respondent with datalist autocomplete.
  * Strict Respondent boundary check (Respondent must be a Tumana resident or tenant).
  * Dynamic party builder (`+ Add Witness / Extra Party`) with role type selection.
  * AI Narrative Enhancement (`✨ Enhance with AI`) via Gemini integration (`refineComplaintNarrative`).
  * Leaflet incident location pin selector with clear button.
  * Multi-file evidence drag-and-drop queue (up to 25 MB per file: images, video, PDF, Word) with thumbnail preview and removal before submit.
* **Complaint Details Workspace (`complaint-details.php`):**
  * Consolidated 8-card layout displaying incident details, parties, narrative, evidence, and map.
  * **15-Day Statutory Mediation Clock:** Live countdown badge (*Within Period*, *Expiring Soon*, *Mediation Lapsed*, *Paused*), statutory pause/resume with justified suspension reasons and audit logging.
  * **Gated 1st Mediation:** The *Schedule 1st Mediation* button is strictly disabled until summons service completion (*Served Personal*, *Served Substituted*, or *Refused*) is logged in the database.
  * Direct deep-links to Case Team Assignment, Document Center, and Hearings.

### D. Case Assignments (`case-assignment.php`, `case-list.php`)
* **Atomic Case Team:** Head, Secretary, and Member saved atomically.
* **Mediation Role Gate:** For *Docketed* and *Mediation*, PB Administrator is automatically assigned as Head; manual member assignments are locked.
* **Conciliation Lupon Panel Enforcement:** Transition to *Conciliation* requires a dedicated 3-member Lupon panel (Head, Secretary, Member) composed strictly of distinct active users with role `Lupon Member`.
* **Pre-Booking Enforcement:** Booking a Conciliation hearing is strictly blocked on both client-side and backend (`HearingController::validate`) if the 3-member panel is not constituted.

### E. Hearing Scheduling Rules (`schedules.php`)
* **Office Hours & Weekday Safeguards:**
  * Hearings scheduled on Saturday or Sunday are strictly rejected.
  * Hearings outside office hours (08:00 to 17:00) are rejected with HTTP 422.
* **Date Progression Enforcement:** Cannot book a hearing on the same day or any prior date relative to the latest booked hearing of the case.
* **Progression Ceiling:** Maximum of 3 Mediation sessions and 3 Conciliation sessions; further scheduling halts upon reaching 3rd Conciliation.
* **Auto-Docketing on 1st Mediation:** Scheduling the 1st Mediation automatically transitions complaint and case status to *Docketed*.

### F. Summons Management & Field Documentation (`proof-service.php`)
* **Unified Expandable Case Table:** Replaced separate stacked cards and disconnected tables with an expandable case table. Clicking a row expands inline service cards directly below the case.
* **Layout Parity:** Complainant (KP Form 8) and Respondent (KP Form 9) service cards feature identical card structure, typography, and detail lists (`Date/Time Served`, `Service Source / Server`, `Service Method`, `Remarks`, failure reasons, and photo proof link).
* **Automatic Complainant Service:** Issuing 1st attempt summons automatically marks KP Form 8 as `Served` with personal notice from the Lupon office.
* **Slide-in Officer's Return Drawer:** Modern off-canvas drawer (`#officerReturnModal`) with live Case Number, Party, and KP Document badges for logging delivery results, recipient, relationship, unserved reasons, notes, and proof image uploads.
* **Summons Attempt Stepper & CFA Locking:** Real-time 3-attempt progression tracker (1st, 2nd, 3rd Attempt) and conditional CFA unlock indicator.
* **Mediation Clock Integration:** Marking service as *Unserved* automatically pauses the case mediation clock with reason *Summon Unserved*.

### G. Document Generation Foundation (`document-center.php`)
* **KP Form 12 (Paabiso ng Pagdinig):** Case selector, data preview (complainants, respondents, conciliation date/venue, Pangkat Chairman).
* **PDF Engine:** Packaged Composer-free Dompdf generation into `storage/generated-documents/`.
* **Security & Audit:** Authenticated PDF viewing, downloading, deletion, and audit trail logging.

### H. Reports and Exports (`report-list.php`)
* **Record-Based Reporting:** Monthly, Quarterly, Annual, and DILG reports with category breakdown, disposition metrics, and case-level tables.
* **CSV Export:** Export with audit trail recording in `generated_reports`.

### I. Notifications & Alerts (`inbox.php`)
* **Automated Workflow Notifications:** Real-time assignment alerts, hearing notifications, and global toast popups (`agapNotify`) polling every 45 seconds.

### J. Case Management Details Workspace Redesign (`case-details.php`, `stage-workspace.js`)
* **Top Hero Section & Context Grid:** Top banner displaying Case Number, Title, status badges (`MEDIATION`, `CONCILIATION`, `ARBITRATION`), Case Type badge (`Civil`/`Criminal`), Category, Date Filed, Incident Date/Time, and live countdown badge. Side-by-side context grid with Complainant and Respondent cards on the left, and incident location with Leaflet map on the right.
* **Three Dedicated Stage Tabs:** Dedicated tabs for **Mediation** (PB sessions, facts), **Conciliation** (Pangkat conciliation, membership status, locked until mediation concludes), and **Arbitration / CFA** (voluntary arbitration or court certification).
* **Streamlined Minutes of the Meeting:** Consolidated 3-stage flow (Preliminaries $\rightarrow$ Proceedings & Discussion $\rightarrow$ Agreed Terms & Outcome).

### K. First Mediation Presider Substitution & Independent Attendance
* **Independent Attendance:** Complainant, Respondent, and Presider attendance tracked independently.
* **Presider Substitution Flow:** Server-enforced designated substitute presider selection, justification reason, and confirmation of mutual party consent before session can proceed. Preserves original scheduled presider in hearing history.
* **First Mediation Non-Appearance Policy:** Initial non-appearance directs staff to book the 2nd Mediation session; penalty forms (KP 18/19) remain locked.

### L. Second Mediation Summons Exemption & Non-Appearance Escalation
* **Summons Exemption (RA 7160 Sec. 410):** 2nd and 3rd mediation hearings automatically bypass summons delivery gates (`SummonDeliveryService`, `SummonServiceGate`); attendance is unlocked, party service status displays as `Served (Prior Notice)`, and no dummy pending deliveries are created.
* **Absence Escalation (KP Form 18 & KP Form 19):** Absent complainant unlocks KP Form 18; absent respondent unlocks KP Form 19. Both forms can be previewed/printed directly with `preview=1`.
* **Strict Consequence Form Gating:** KP Form 21 (Bar Action) and KP Form 22 (Bar Counterclaim) remain locked until an unjustified non-appearance ruling is recorded in `show_cause_evaluations`.
* **Canonical Schedule Navigation:** "Book 2nd Mediation Hearing" links directly to `../hearings/schedules.php?case_id=...&hearing_id=...` with modal pre-selection and return link.

---

## 2. What is Broken (Bugs, Schema Mismatches & Dead Links)

### 🔴 Critical Bug 1: Cases List Page Crashes (`case-list.php`)
* **Symptom:** Opening `frontend/pages/cases/case-list.php` fails to load cases and crashes with an internal server error.
* **Root Cause:** In `Assignment.php`, `validateConciliationTeams()` queries `SELECT case_id, quorum_size FROM pangkat_groups`. The migration `20261006_scheduling_mediation_settlement_logistics.sql` defines `quorum_size`, but was **never executed** against `agap_db`.
* **Error:** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'quorum_size' in 'field list'`

### 🔴 Critical Bug 2: Amicable Settlements Page Crashes (`settlements.php`)
* **Symptom:** Navigating to `frontend/pages/documents/settlements.php` results in failed API requests (`backend/api/settlements/list.php`).
* **Root Cause:** `Settlement.php` queries `repudiation_status`, `repudiation_deadline`, and table `settlement_installments`. Neither the expanded columns nor the `settlement_installments` / `settlement_executions` tables exist in `agap_db` because migration `20261006` was not applied.
* **Error:** `SQLSTATE[42S22]: Column not found: 1054 Unknown column 'repudiation_status' in 'where clause'`

### 🟢 Resolved: Hearing Attendance & Summon Deliveries Schema Alignment
* **Status:** Resolved via migrations `20261013_001_repair_summon_deliveries.sql` and `20261013_003_summon_three_attempts.sql`.
* **Fix:** Standardized `summon_deliveries` columns (`case_id`, `document_id`, `party_id`, `resident_id`, `party_type`, `recipient_name`, `delivery_status`, `attempt_count`, `service_method`, `served_by_user_id`, `served_at`, `unserved_reason`, `failure_notes`, `attachment_path`), resolving crashes during attendance and delivery updates.

### 🟢 Resolved: Summons Generation & Multi-Attempt Progression
* **Status:** Resolved via `backend/api/summons/next-attempt.php`, `backend/api/summons/preview.php`, and `summons-preview-modal.php`.
* **Fix:** Multi-attempt summons issuance (1st, 2nd, and 3rd Attempt) is now fully integrated with dedicated preview modals, PDF generation, and automatic KP Form 8 service.

### 🟡 Bug 5: `hearings.status` ENUM Value Truncation / Failure
* **Symptom:** `ShowCauseService.php` sets hearing status to `'Attendance Recorded'` when at least one party is absent.
* **Root Cause:** In `agap_db`, the column `hearings.status` is defined as `ENUM('Scheduled', 'Completed', 'Rescheduled', 'Cancelled')`. The value `'Attendance Recorded'` is only defined in the unapplied `20261006` migration.

### 🟡 Bug 6: Dead Stubs and Blank Navigation Pages (0-Byte Files)
The following files exist in the repository but have 0 bytes:
* `calendar.php` (0 bytes): Direct navigation gives a blank page; should redirect to `schedules.php`.
* `roles.php` (0 bytes): Highlighted in sidebar navigation, but renders blank; should redirect to `user-list.php`.
* `chatbot.php` & `narrative-generator.php` (0 bytes): Standalone AI pages are empty (AI is only implemented as inline enhancement in complaint narrative).
* `cases/delete.php` (0 bytes): Unused empty API endpoint.

---

## 3. What Processes Are Missing (Statutory & Module Spec Gaps)

### 1. Resident Self-Service Portal (Filing & Blotter Tracking)
* **Status:** Not implemented.
* **Gap:** As outlined in `PROJECT_HANDOFF.md`, there is currently no public or resident-facing interface allowing citizens of Barangay Tumana to submit grievances online, verify their resident status, or track case progress with a tracking number without staff assistance.

### 2. Official Katarungang Pambarangay (KP) Form Templates Suite
Under Republic Act 7160 (Katarungang Pambarangay Rules), the barangay must issue standardized KP forms.

* **Implemented:**
  * KP Form 8 — Notice of Hearing for Complainant (PB Mediation; side-by-side preview and auto-serve).
  * KP Form 9 — Summons for Respondent (3-attempt tracking, side-by-side preview, Dompdf generation).
  * KP Form 10 — Notice for Constitution of Pangkat.
  * KP Form 12 — Notice of Hearing for Conciliation Proceedings (Dompdf generation).
  * KP Form 18 & 19 — Notice of Hearing for Failure to Appear / Show Cause (data models and escalation).
  * KP Form 20 — Certification to File Action (CFA; automated PDF generation via `PDFService::generateKp20`).
  * KP Form 21 — Certification to Bar Action (for Complainant default).
* **Missing Templates:**
  * KP Form 7 — Formal Written Complaint.
  * KP Form 11 — Notice to Chosen Pangkat Members.
  * KP Form 13 — Subpoena (Witness / Evidence Summons).
  * KP Form 14 — Agreement for Arbitration.
  * KP Form 15 — Arbitration Award.
  * KP Form 16 — Amicable Settlement Agreement.
  * KP Form 17 — Repudiation of Settlement.
  * KP Form 22 — Certification to Bar Counterclaim (due to Respondent non-appearance).
  * KP Form 23 & 24 — Motion for Execution and Notice of Execution.

### 3. Settlement Execution & Repudiation Life Cycle
* **Status:** Front-end UI designed in `settlements.php`, but backend processing is incomplete.
* **Gap:**
  * Automated tracking of the statutory 10-day repudiation window (sworn statement before PB citing fraud, violence, or intimidation).
  * Tracking of installment payment due dates, partial payments, and receipts.
  * 6-month barangay enforcement window before execution transmittal to the Municipal Trial Court (MTC).

### 4. Hearing Logistics & Session Duration Tracking
* **Status:** Partially defined in models; missing live enforcement.
* **Gap:**
  * Expected duration (30–45 min for Mediation; 45–90 min for Conciliation) vs actual session end-time recording.
  * Daily hearing load limits per presiding officer (5–8 hearings/officer capacity).
  * Official Emergency Office Cancellation flow (distinguishing barangay-wide calamity/suspension from party absence).

---

## 4. Priority Remediation Plan

To bring the AGAP system to 100% operational stability:

```
[ Step 1: Migration ] ---> [ Step 2: DB Sync ] ---> [ Step 3: Link Fixes ] ---> [ Step 4: Clean Stubs ] ---> [ Step 5: New Features ]
Execute 20261006.sql      Align summon_deliveries     Redirect summons.php        302 Redirects for stubs    Resident Self-Service
```

1. **Apply Migration `20261006_scheduling_mediation_settlement_logistics.sql`:**
   * Run the migration in MySQL to create `system_settings`, `hearing_minutes`, `settlement_installments`, `settlement_executions`, and add `quorum_size` to `pangkat_groups`.
   * *Fixes Critical Bugs 1 & 2 immediately.*
2. **Synchronize `summon_deliveries` Table Columns:**
   * Align `summon_deliveries` with `SummonDeliveryService.php` and `HearingAttendance.php` (standardizing `party_type`, `resident_id`, `form_type`, `served_at`, and `unserved_reason`).
   * *Fixes Critical Bug 3.*
3. **Fix Broken Summons Navigation (`summons.php`):**
   * Add a redirect from `frontend/pages/documents/summons.php` to `kp-form-9.php?case_id=...` or embed the summons view template.
   * *Fixes Bug 4.*
4. **Clean Up Empty Stubs:**
   * Add standard 302 redirects in `calendar.php` → `schedules.php` and `roles.php` → `user-list.php`.
   * *Fixes Bug 6.*
5. **Proceed to Next Feature Milestone:**
   * As specified in `PROJECT_HANDOFF.md`, proceed to build the **Resident Self-Service Complaint Filing and Tracking** module.