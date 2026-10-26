# AGAP System (Automated Grievance Assistance Platform)

[![System](https://img.shields.io/badge/Platform-Barangay%20Tumana-blue.svg)](https://github.com/JayroosKeum/AGAP-System)
[![PHP](https://img.shields.io/badge/PHP-8.x-purple.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MySQL-orange.svg)](https://www.mysql.com/)
[![Leaflet](https://img.shields.io/badge/Maps-Leaflet%20OpenStreetMap-green.svg)](https://leafletjs.com/)

The **Automated Grievance Assistance Platform (AGAP)** is a comprehensive web-based case management and dispute resolution platform tailored for the **Lupong Tagapamayapa of Barangay Tumana**. The platform digitizes and streamlines the statutory conciliation and mediation process under the **Katarungang Pambarangay (KP)** law (Republic Act No. 7160 / Local Government Code of 1991).

---

## Table of Contents

- [Key Features](#key-features)
  - [1. Complaint Intake, Management & Records Search](#1-complaint-intake-management--records-search)
  - [2. Preliminary Paanyaya Process & Officer's Return Tracking](#2-preliminary-paanyaya-process--officers-return-tracking)
  - [3. Mediation & Conciliation Progression Engine](#3-mediation--conciliation-progression-engine)
  - [4. Case Management & Unified Case Teams](#4-case-management--unified-case-teams)
  - [5. KP Document Generation & Proof of Service](#5-kp-document-generation--proof-of-service)
  - [6. Security, Audit Trails & Notifications](#6-security-audit-trails--notifications)
- [System Architecture](#system-architecture)
- [Directory Structure](#directory-structure)
- [Tech Stack](#tech-stack)
- [Installation & Local Setup](#installation--local-setup)
- [Local Test Credentials](#local-test-credentials)
- [Database & Migrations](#database--migrations)

---

## Key Features

#### 1. Complaint Intake, Management & Records Search

- **Dedicated File Complaint Page (`complaint-create.php`)**:
  - Full-page, compact two-column card intake layout replacing legacy modal popups.
  - **Dual Filing Architecture**:
    - **Digital Filing**:
      - Multiple parties support with `has_multiple_complainants` and `has_multiple_respondents` checkboxes.
      - **Demographic Modal (`#partyDetailsModal`)**: Capture comprehensive details without cluttering the main screen—minor status with mandatory legal guardian details (name, relationship, contact, address), demographics (birthdate, calculated age, gender, civil status, occupation, suffix), contact preferences (`SMS`, `Call`, `Email`, `In-Person`), and unknown info flags (`contact_unknown`, `middle_name_unknown`, `birthdate_unknown`).
      - **Streamlined Narrative**: Focused "Complaint Narrative" textarea without redundant instructional text or optional fields.
      - **Flexible Incident Date & Time**: "Exact Time Unknown" and "Approximate Time" toggles dynamically switching the input element between `date` and `datetime-local`.
      - **Relocated Primary Action**: Primary **Submit Complaint** button positioned directly beneath Section 5 (Evidence & Attachments), simplifying the header to **Cancel**.
      - **Pre-Submission Legal Preview**: Submitting triggers an interactive Legal-size modal preview of **Pormularyo ng KP Blg. 7 (Reklamo)** for review and printing before final database insertion.
    - **Pre-Signed Paper Form (Fast Intake Mode)**:
      - 2-panel split screen: Left panel (50%–60% width) in-browser document viewer for uploaded handwritten/scanned KP Form 7 with Zoom In/Out, 90° rotation, and fullscreen; Right panel fast indexing form (Category, Case Type, Incident Date & Time, and auto-filled narrative "See attached scanned KP Form 7").
      - Versioned scan storage in `storage/uploads/complaint-scans/` and `complaint_scanned_documents`.
- **Dedicated Edit Complaint Page (`complaint-edit.php`)**:
  - Full-page edit interface with complete structural parity with `complaint-create.php`.
  - Header actions featuring **Cancel** alongside top **Save Changes** button.
  - Preloads all existing facts, category, `case_type`, merged datetime (`YYYY-MM-DDTHH:MM`), parties, demographic details, and Leaflet pin coordinates.
  - Atomic server updates: synchronizes `complaints`, propagates `case_type` to linked `cases`, updates/inserts `complaint_parties`, and persists or clears coordinates in `incident_locations`.
- **Modern, Compact Complaints List (`complaint-list.php`)**:
  - **4 Top KPI Metric Cards**: "Total Complaints", "Under Review", "In Progress", and "Settled" with one-click filtering.
  - **8 Process-Based Status Navigation Tabs**: Horizontal quick-access tabs with live count pills for each stage and disposition: `All`, `Paanyaya`, `Pending`, `Under Review`, `Docketed`, `Mediation`, `Conciliation`, `CFA`, and `Resolution / Closed`.
  - **3-Dimensional Lifecycle Tracking**: Conforms to RA 7160 (Katarungang Pambarangay) by decoupling orthogonal attributes:
    1. **Intake Status**: `Under Review` (screening/pre-docketing) vs `Docketed` (assigned case number).
    2. **Progression / Dispute Stage**: `None / Pre-docketing`, `Mediation` (PB phase), `Conciliation` (Pangkat phase), or `Arbitration` (voluntary binding phase).
    3. **Case Disposition**: `Pending`, `Amicable Settlement` (mutual agreement), `Arbitration Award` (binding resolution), `Certificate to File Action (CFA)`, or `Dismissed / Dropped`.
  - **Interactive Column Header Sorting**: Clickable table headers on all 6 data columns (`Complaint`, `Case No.`, `Category`, `Parties`, `Incident Date`, `Status`) with toggleable ascending/descending sorting (`▲`/`▼`/`⇅`), zero-latency client-side instant sort, and backend database sort mapping.
  - **Compact Toolbar & Granular Drawer**: Debounced keyword search, clear button, Case Type dropdown (`All`, `Civil`, `Criminal`), Category filter dropdown, and a collapsible secondary drawer containing dedicated dropdowns for each lifecycle dimension (`#searchIntake`, `#searchStage`, `#searchDisposition`) alongside date range pickers and active filter chips with one-click removal.
  - **10 Items Per Page Pagination**: Clean, responsive pagination bar below the table displaying a dynamic range summary ("Showing 1–10 of 14 complaint records"), Previous/Next navigation controls, and direct numeric page selectors.
  - **7-Column Table Layout**:
    1. **Complaint** (Complaint Number, Title, Case Type badge)
    2. **Case No.** (Formatted Docket Case No. `MM-DD-YYYY` or "Undocketed" badge)
    3. **Category** (Badge)
    4. **Parties** (Complainant, Respondent, and Witness pills with role tags)
    5. **Incident Date** (Formatted date)
    6. **Status** (Clean color-coded badges for Intake, Dispute Stage, and Final Outcome without label prefixes; stage automatically expires and hides after 3 mediations and 3 conciliations)
    7. **Actions** (View Details, Edit Page, and Archive modal trigger)
- **Complaint Workspace (`complaint-details.php`)**:
  - Multi-stage review gate: `Filed` $\rightarrow$ `Under Review` / `Needs Information` $\rightarrow$ `Accepted` / `Rejected`.
  - Secure evidence upload vault supporting images (JPG, PNG), videos (MP4, WebM), and PDF documents up to 25 MB with MIME verification.
  - **Official KP Forms Table**: Houses **KP Form 7 (Reklamo)** alongside other KP forms, supporting high-fidelity Legal-size modal preview, zero-watermark printing, and versioned scan replacements.

### 2. Preliminary Paanyaya Process & Pre-Docket Tracking

- **Mandatory Preliminary Workflow Insertion**:
  - Seamlessly positioned between Complaint Details and formal Mediation:
    `Complaint Intake / Overview` $\rightarrow$ `Paanyaya 1` $\rightarrow$ `Paanyaya 2` $\rightarrow$ `Mediation` $\rightarrow$ `Conciliation` $\rightarrow$ `Arbitration / CFA`.
  - Cases in this preliminary phase hold the dedicated `Paanyaya` (`Pre-Docketed (Paanyaya)`) state until preliminary service requirements or administrative review escalations are satisfied.
  - **Status Isolation**: Records in Paanyaya display strictly the `Paanyaya` status badge and are never combined with other statuses (such as "Pending", "Draft", or "Under Review").
- **Distinct Pre-Docket Case Numbering (`P-month-number-year`)**:
  - While in the informal Paanyaya stage, disputes are **not docketed automatically** into the court cases table.
  - Assigned a distinct pre-docket tracking number format: `P-month-number-year` (e.g. `P-10-86-2026`).
  - Upon escalation to formal Mediation, the dispute is docketed into `cases` and the case number transitions to the official docketed format: `month-number-year` (e.g. `10-21-2026`).
- **Dedicated Paanyaya Tab (`complaint-details.php`)**:
  - Positioned directly after Overview & Workflow and before the Mediation tab.
  - Two distinct sections:
    - **Paanyaya 1 – First Preliminary Invitation**: Up to 6 recorded delivery attempts.
    - **Paanyaya 2 – Second Preliminary Invitation**: Up to 6 delivery attempts, unlocked only when Paanyaya 1 completes with Lupon administrative review or escalation approval.
  - Independent attempt counter (1 to 6) per stage enforced by database constraints and server-side model logic; attempt counters never reset on navigation or overlap between stages.
- **Official Paanyaya Invitation Letter Generation & Print Preview**:
  - Generates official Paanyaya invitation letters featuring Barangay Tumana and City of Marikina seals, hearing schedule (date, time, venue), salaysay facts excerpt, and Punong Barangay / Lupon signature block.
  - Streamlined post-filing modal preview (`#paanyayaPreviewModal`) displaying Legal-size print previews with direct zero-watermark printing and handover to Officer's Return recording.
- **Granular Officer's Return Delivery Tracking**:
  - Tracks detailed delivery data per attempt: attempt number (1–6), date/time visited, serving officer, recipient visited, address visited, delivery outcome, failure reason, remarks/witness notes, proof photo/document attachment, and GPS coordinates.
  - Non-equivalent outcome options: `Successfully delivered`, `Recipient unavailable`, `Recipient refused to receive`, `Respondent not found at the address`, `Incorrect or incomplete address`, and `Other reason`.
- **Lupon Administrative Review Workflow**:
  - When all 6 attempts are exhausted without successful delivery, the stage transitions to `Attempts Exhausted – For Review`.
  - Authorized Lupon officers review the history and record decisions: `Approved for Paanyaya 2`, `Approved for Summons`, or `Case Closed`.
- **Summons Prerequisite Gate**:
  - The `Issue Summon 1st Attempt` action remains locked until Paanyaya 1 is successfully delivered OR all attempts are exhausted and approved for formal court-annexed escalation via administrative review.

### 3. Mediation & Conciliation Progression Engine

- **Standardized Case Docketing Numbering (`MM-DD-YYYY`)**:
  - Format: `MM-DD-YYYY` (e.g., `10-17-2026`).
  - Standardized across all complaint and case screens, eliminating non-standard alphanumeric prefixes.
  - Thread-safe atomic generation via `CaseModel::generateCaseNumber()`.
- **Statutory Hearing Progression & 2nd Mediation Summons Exemption**:
  - **Mediation Stage**: 1st Mediation $\rightarrow$ 2nd Mediation $\rightarrow$ 3rd Mediation (up to 3 mediation sessions conducted by the Punong Barangay).
  - **Second Mediation Summons Exemption (RA 7160 Sec. 410)**: After the 1st Mediation, scheduling the 2nd Mediation does not require re-issuance of summonses. Prior notice is recognized, attendance gate is unlocked, and party service badges display `Served (Prior Notice)`.
  - **Presider Substitution Flow**: When the scheduled Punong Barangay presider is absent, selecting an active Lupon member as substitute presider, specifying the reason, and verifying mutual party consent is mandatory on the server before proceedings can proceed.
  - **Progression to Conciliation**: Unresolved cases advance to conciliation: 1st Conciliation $\rightarrow$ 2nd Conciliation $\rightarrow$ 3rd Conciliation (up to 3 conciliation sessions conducted by the Pangkat Tagapagkasundo). Progression is locked until the 3rd mediation is completed.
  - **Scheduling Ceiling**: Once the 3rd Conciliation hearing is scheduled, the scheduling engine permanently halts further hearing additions, enforcing KP statutory limits.
- **Dual Notice Auto-Generation & "Print summon" Side-by-Side Review**:
  - Triggering `Issue Summon 1st Attempt` atomically books the 1st Mediation session, updates complaint status, generates both **KP Form 8 (Notice of Hearing for Complainant)** and **KP Form 9 (Summons for Respondent)**, and immediately opens the dedicated **Print summon** preview modal.
  - Displays both official forms side by side in high-fidelity preview matching official Dompdf outputs with clean letterhead, docket number, parties, and Officer's Return block.
  - Provides isolated A4 printing (excluding AGAP sidebar/navbar chrome), standardized PDF downloading (`AGAP_[CASE]_KP-Form-[8/9]_[Notice/Summons].pdf`), and seamless handover to the Officer's Return proof-of-service interface.
  - **KP Form 8 Auto-Served on Print**: Printing KP Form 8 for the First Mediation stage automatically marks its service status as `Served` via the print workflow, recorded in the audit trail without requiring a manual Officer's Return.
  - **Strict 3-Attempt Summons Cycle & Progression**: Tracks up to three (3) documented delivery attempts for KP Form 9. Attempt 2 is enabled only after Attempt 1 is recorded as a delivery failure (`Unserved`); Attempt 3 is enabled only after Attempts 1 & 2 fail. Attempt 4 is strictly prevented. Successful service immediately halts failed progression.
  - **Conditional Certificate to File Action (CFA) Unlocking**: Unlocks **KP Form 20 (CFA)** for printing only when all three (3) summons delivery attempts have resulted in documented delivery failure, keeping CFA locked with real-time feedback (e.g., `CFA Locked — X of 3 summons attempts resulted in delivery failure`).
- **Attendance & Show-Cause Lifecycle (KP Forms 16, 18, 19, 21, 22)**:
  - Separate Complainant, Respondent, and Presider attendance tracking (`Present`, `Absent`, `Excused`).
  - **1st Mediation Absence Rule**: Non-appearance in 1st Mediation leads directly to booking the 2nd Mediation session; penalty notices are blocked with statutory guidance.
  - **2nd Mediation Absence Escalation**:
    - Unlocks **KP Form 18** (Notice of Hearing for Failure to Appear - Complainant) when complainant is absent.
    - Unlocks **KP Form 19** (Notice of Hearing for Failure to Appear - Respondent) when respondent is absent.
  - **Strict Consequence Gating**:
    - **KP Form 21 (Certificate to Bar Action)** is strictly locked until a show-cause evaluation ruling complainant non-appearance as unjustified (`is_justified = 0`) is recorded.
    - **KP Form 22 (Certificate to Bar Counterclaim)** is strictly locked until a show-cause evaluation ruling respondent non-appearance as unjustified (`is_justified = 0`) is recorded.
  - **KP Form 16 (Amicable Settlement)**: Generates Kasunduang Pag-aayos upon dispute resolution with automatic integration of agreed terms.
- **Office Logistics & Flexible Scheduling Constraints**:
  - Enforces working-day scheduling (**Monday to Friday, 8:00 AM to 5:00 PM**) within the statutory 15-working-day mediation limit.
  - Daily room capacity management (up to 8 mediation hearings per day) with Lupon Court Room overlap collision protection.
  - Rejects hearings scheduled on the same date/time as an existing hearing for the case or on any prior date.
  - Room collision and presider conflict detection powered by `OfficeLogisticsService` and `Summons::findMediationConflict()`.

### 4. Case Management & Unified Case Teams

- **Redesigned Case Workspace (`case-details.php`)**:
  - **Top Hero Section**: Case Number (`Case #MM-SS-YYYY`), Title, status badges (`MEDIATION`, `CONCILIATION`, `ARBITRATION`), Case Type badge (`Civil`/`Criminal`), Category, Date Filed, Incident Date/Time, and live statutory deadline countdown.
  - **Side-by-Side Context Grid**: Involved Parties Card (Complainant, Respondent) and Incident Location Card with interactive Leaflet map pin.
  - **Three Functional Tabs**:
    - **Mediation**: Punong Barangay Mediation hub, Full Narrative & Facts, mediator assignment, session metrics, and schedule.
    - **Conciliation**: Pangkat Tagapagkasundo conciliation hub, Pangkat constitution state (locked until mediation concludes), and conciliation hearings.
    - **Arbitration / CFA**: Voluntary binding arbitration proceedings or Court Referral / Certificate to File Action (CFA) issuance.
  - **Streamlined 3-Stage Minutes Flow**: Consolidated 3-step sequence:
    1. *Preliminaries* (Quorum, identity verification, presider/substitute confirmation, attendance check).
    2. *Proceedings & Discussion* (Complainant statement, respondent response, dispute issues, caucuses, proposals).
    3. *Agreed Terms & Outcome* (Session outcome, agreed settlement terms, and document generation).
- **Separated Hearing Session Minutes & Proceedings Cards (`stage-workspace.js`)**:
  - **Independent Multi-Card Architecture**: Replaced single-form in-place toggling with distinct, physically separated cards for each session in both Mediation (`1st Mediation` & `2nd Mediation`) and Conciliation (`1st`, `2nd`, and `3rd Conciliation`) workspaces.
  - **Simultaneous Cross-Session Review**: Clerks can review previous session notes, proposals, and agreements in Card 1 while taking live minutes in Card 2 without losing context or switching tabs.
  - **Session-Specific Outcome Logic**: Tailors available outcomes dynamically (1st Mediation offers `Unresolved / Book 2nd Mediation` with summons bypassed; 2nd Mediation unlocks `Failure of Mediation / Endorse to Pangkat (KP Form 10)`).
  - **Isolated Auto-Save Engine (`AgapDraftManager`)**: Client-side drafts are scoped strictly to `#inlineMinutesForm_${hId}` and keyed by `hearing_id`, completely preventing draft collisions or accidental overwrites across sessions.
  - **Quick Jump Navigation Bar**: Top navigation bar with status badges (`Draft`, `Finalized`, `Not Recorded`) that smooth-scrolls directly to the selected session card.
  - **Dynamic Next Session Booking Prompt**: Automatically displays a scheduling card beneath completed sessions allowing clerks to book the next hearing immediately under RA 7160 Sec. 410.
- **Consolidated Pangkat Formation & Team Assignment**:
  - Integrated 3-member Pangkat panel selection (Chairperson, Secretary, Member) directly into the **"Refer Case to Pangkat ng Tagapagkasundo"** modal (`#wsReferPangkatModal`) on 2nd Mediation, retiring the redundant standalone team assignment page.
  - Validates active Lupon membership, prevents duplicate selections, automatically transitions case status to `Conciliation`, sets the 3-day Pangkat convening deadline, and generates KP Forms 10, 11, and 12.
- **Dynamic Outcomes Tab Unlocking**:
  - The final disposition tab (`tab-arbitration`) is renamed **Outcomes**. Previewing or printing an Amicable Settlement (KP Form 16), CFA (KP Form 20), Arbitration Agreement (KP Form 14/15), or Transmittal Letter automatically unlocks the Outcomes tab immediately across sessions.
- **Court Transmittal Letter & 10-Business-Day Statutory Countdown Gating (RA 7160 Sec. 416/417)**:
  - **Official MTC Transmittal Letter (`transmittal-print.php`)**: Full-page printable transmittal with official Barangay Tumana letterhead, Metropolitan Trial Court (MTC) Marikina address, certified records checklist, and Secretary attestation.
  - **10-Business-Day Gating Engine (`backend/api/transmittals/status.php`)**: Calculates business working days (excluding weekends and statutory holidays) from settlement or CFA execution. Locks transmittal printing with a live countdown badge until 10 business days have passed, automatically triggering an alert notification once ready for court submission.
- **Printable Blank Ruled Minutes Sheet (`print-blank-minutes.php`)**:
  - Accessible across Mediation and Conciliation workspaces via `🖨️ Print Blank Minutes Sheet` and the Official KP Forms table.
  - Formatted with official Barangay Tumana header, case caption particulars, and 24 ruled writing lines for handwritten minutes during live hearings.

### 5. KP Document Generation & Master Document Center

- **Master Document Center & DOCX Template Engine (`document-center.php`)**:
  - **DOCX Master Template Upload (`DocxTemplateService.php`)**: Parses uploaded Microsoft Word (`.docx`) template files using OpenXML DOM parsing, preserving character formatting, tables, line heights, and paragraph alignment.
  - **Standardized Arial Typography**: Standardized font across all KP document bodies and previews to clean **Arial** (`font-family: Arial, Helvetica, sans-serif;`).
  - **Zero Browser Header/Footer Print Rules**: Print media styles (`@page { margin: 10mm; }`) suppress default browser print headers and footers (timestamps, page URLs, file paths).
  - **Automatic Paper Size Scaling**: Automatically adjusts and scales template layouts cleanly for Legal (`8.5in x 14in`) and A4 bond paper sizes.
  - **Customizable Official Letterheads**: Dynamic logo replacement for Barangay and City seals, with the obsolete Office Name Banner removed for a cleaner presentation.
  - **Standardized Modal Previews**: Clean modal titles displaying strictly the canonical form number (e.g. `KP FORM 7`, `KP FORM 8`, `KP FORM 20`) without clutter.
- **Tri-Panel Side-by-Side Pangkat Preview (`kp-form-preview-modal.php`)**:
  - **Tri-Panel Responsive Grid**: Displays **KP Form 10** (Notice for Constitution), **KP Form 11** (Notice to Chosen Members), and **KP Form 12** (Notice of Hearing) side-by-side in a wide 3-panel modal.
  - **Individual & Bulk Print Controls**: Individual `Print` and `Download PDF` actions per panel, plus `Print All 3 Forms` bulk printing with sequential iframe rendering.
  - **Officer's Return Service Tracking**: Direct integration under KP Form 12 linking to proof of service logging for both Complainant and Respondent (`proof-service.php`).
- **Dynamic Outcome-Driven KP Forms Unlocking**:
  - **Real-Time Table Unlocking**: The Official KP Forms card dynamically inspects recorded stage and hearing minutes outcomes to unlock corresponding forms:
    - *Amicably Settled* → **KP Form 16** (*Kasunduang Pag-aayos*) with immediate modal launcher `Open Amicable Settlement →`.
    - *Voluntary Arbitration* → **KP Form 14** (*Agreement for Arbitration*) & **KP Form 15** (*Arbitration Award*).
    - *Repudiated Settlement* → **KP Form 17** (*Pagtatwa*) & CFA (**KP Form 20 / 20-A / 20-B**).
    - *Endorse to Pangkat* → **KP Forms 10, 11, and 12** with Tri-Panel preview shortcuts.
- **Binding Arbitration Scheduling (RA 7160 Sec. 413)**:
  - Selecting Arbitration outcome dynamically renders an amber action banner prompting `📅 Schedule Arbitration Session →`.
  - Integrated with the standard scheduling modal (`openEmbeddedScheduleModal('Arbitration')`) and backend validation in `HearingController.php`.
- **Official Katarungang Pambarangay (KP) Forms**:
  - **KP Form 7**: Pormularyo ng KP Blg. 7 (Reklamo / Formal Complaint) — formatted strictly for standard **Legal Size Bondpaper** (`8.5in x 14in`) with Barangay Tumana Seal (Left) and City of Marikina Seal (Right), generous signature clearances (`48px`), zero-watermark browser printing, and interactive modal preview.
  - **KP Form 8**: Notice of Hearing (Complainant).
  - **KP Form 9**: Summons for Respondent.
  - **KP Form 10**: Notice for Constitution of Pangkat.
  - **KP Form 12**: Notice of Hearing (Conciliation Proceedings).
  - **KP Form 16**: Kasunduang Pag-aayos (Amicable Settlement Agreement).
  - **KP Form 18**: Notice of Hearing for Failure to Appear (Complainant).
  - **KP Form 19**: Notice of Hearing for Failure to Appear (Respondent).
  - **KP Form 20**: Certificate to File Action (CFA - issued upon 3 failed summons delivery attempts or conciliation/mediation failure).
  - **KP Form 21**: Certificate to Bar Action (for Complainant default).
  - **KP Form 22**: Certificate to Bar Counterclaim (for Respondent default).
- **Summons Management & Proof of Service Interface (`proof-service.php`)**:
  - **Unified Expandable Case Table**: Displays all cases requiring summons and preliminary invitation tracking, seamlessly integrating both formal hearings and Paanyaya cases. Rows expand/collapse inline to reveal service records.
  - **Paanyaya Adaptive Layout**: For Paanyaya preliminary cases, displays a full-width Respondent delivery card (Complainant notice card is hidden as Paanyaya is delivered exclusively to the respondent).
  - **Live Filter Tabs & Search**: Instant keyword search across Case No., parties, and titles; filter tabs for **All Cases**, **Pending Delivery**, and **Fully Served** with live counter badges.
  - **Top KPI Indicators**: Compact summary counters for Total Cases, Pending Delivery, CFA Eligible, and Served Today.
  - **Complainant & Respondent Visual Parity**: Matching two-column card structure, badges, typography, and detail lists (`Date/Time Served`, `Service Source / Server`, `Service Method`, `Remarks`, failure notes, proof links).
  - **Interactive Horizontal Steppers**:
    - **Paanyaya Cases**: 6 interactive attempt nodes (1st to 6th Attempt) for Respondent preliminary invitations.
    - **Formal Summons**: Progression nodes for Attempt 1, Attempt 2, Attempt 3, and CFA status with dynamic state badges (`Served`, `Pending`, `Failed`, `Locked`).
  - **Fully Editable Officer's Return Workflow**:
    - Stepper circles for all issued/recorded attempts have pointer cursors and open the return drawer on click.
    - Row-level `✏️ Edit Return` buttons allow authorized personnel to update existing returns (outcome, recipient name, address visited, remarks, failure reason).
    - Automatically operates in **Edit Mode** (`Edit Officer's Return`) when editing existing attempts and **Log Return Mode** when recording pending attempts.
    - Updates are saved to `paanyaya_officer_returns` and `summon_deliveries` with audit logging via `AuditService`, without causing attempt counter collisions.
  - **Auto-Served Complainant Notice**: Automatically sets KP Form 8 to `Served` upon issuance of the 1st Attempt summons from the complaint page.
  - **Slide-in Officer's Return Drawer**: Modern off-canvas drawer (`#officerReturnModal`) with distinct Case Number, Party, and Document Form badges for recording delivery outcomes, recipient relationship, unserved reasons, notes, and photo proof attachments.
  - **Clean Action Footers**: Streamlined card footers focused on **Log Officer's Return** / **Edit Officer's Return** alongside next-stage progression buttons, removing clutter from redundant print buttons.

### 6. Security, Audit Trails & Notifications

- **Granular Role-Based Access Control (RBAC)**: Administrator, Lupon Clerk, Lupon Member, Summons Server.
- **Account Security**: Session regeneration on authentication, 12-character complex password policies, and single-use SHA-256 hashed password reset tokens with 1-hour expiration.
- **Audit Trails**: Transaction-level logging via `AuditService` tracking user actions, entity mutations, and data exports.
- **Live Notifications & Toasts**: Shared `window.agapNotify()` toast notification system and authenticated background inbox polling every 45 seconds.

---

## System Architecture

AGAP follows a clean **Model-View-Controller (MVC)** architectural pattern:

```text
Browser UI (HTML5 / ES6 JavaScript)
       │
       ▼
RESTful API Endpoints (`backend/api/<module>/`)
       │
       ▼
Controllers (`backend/controllers/`)  ◄──►  Services (`AuditService`, `PDFService`, etc.)
       │
       ▼
Models (`backend/models/`)
       │
       ▼
Database (`MySQL PDO` via `backend/config/database.php`)
```

---

## Directory Structure

```text
AGAP-System/
├── backend/
│   ├── api/                   # Modular API endpoints (complaints, cases, hearings, etc.)
│   ├── config/                # Database and environment configurations
│   ├── controllers/           # Workflow controllers coordinating models and audits
│   ├── libs/                  # Third-party libraries (packaged Dompdf)
│   ├── middleware/            # Session, auth, and role verification
│   ├── models/                # PDO data models with prepared statements
│   └── services/              # Business logic (Audit, PDF, Validation, Notifications)
├── database/
│   ├── migrations/            # Incremental SQL migration scripts
│   ├── seeds/                 # Local development seed data
│   ├── schema.sql             # Canonical source-of-truth schema
│   └── README.md              # Database documentation and migration instructions
├── frontend/
│   ├── assets/
│   │   ├── css/               # Modular and utility stylesheets
│   │   └── js/                # Client-side JavaScript modules
│   ├── layouts/               # Shared layouts (header, navbar, sidebar, footer)
│   └── pages/                 # Full-page PHP views (complaints, cases, hearings, etc.)
├── storage/                   # Uploaded attachments, proof images, and generated PDFs
├── AI_DEVELOPMENT_GUIDE.md    # Development and AI pair-programming guide
├── PROJECT_HANDOFF.md         # Comprehensive project specification and handoff details
└── README.md                  # System overview and getting started guide
```

---

## Tech Stack

- **Server Environment**: PHP 8.1+ / Apache (optimized for Laragon on Windows)
- **Database**: MySQL 8.0+ / MariaDB 10.4+
- **Frontend**: Vanilla JavaScript (ES6+), Modern Responsive CSS, HTML5
- **Mapping & Geospatial**: Leaflet.js & OpenStreetMap
- **PDF Engine**: Dompdf (packaged release, composer-free)

---

## Installation & Local Setup

### 1. Clone the Repository
Clone the project into your web root (e.g., `C:\laragon\www\AGAP-System`):
```powershell
git clone https://github.com/JayroosKeum/AGAP-System.git
```

### 2. Setup Database
Create a database named `agap_db` in MySQL and import the canonical schema:
```powershell
mysql -u root -e "CREATE DATABASE IF NOT EXISTS agap_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root agap_db < database/schema.sql
```

### 3. Verify Configuration
Ensure `backend/config/database.php` matches your local database settings:
```php
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'agap_db');
```

To enable AI narrative enhancement, configure `GEMINI_API_KEY` as a PHP/Apache
environment variable and restart Apache. The key is read server-side only; do
not add it to JavaScript or `backend/config/gemini.php`.

### 4. Access the Application
Start Apache and MySQL in Laragon, then open:
```text
http://localhost/AGAP-System/
```

---

## Local Test Credentials

For development and testing, `database/schema.sql` seeds the following local accounts (all accounts use password: `password`):

| Username | Role | Description |
| :--- | :--- | :--- |
| `admin` | Administrator | Full administrative privileges |
| `clerk` | Lupon Clerk | Intake, scheduling, and document generation |
| `luponhead` | Lupon Member | Conciliation Team Head |
| `luponsecretary` | Lupon Member | Conciliation Team Secretary |
| `luponmember` | Lupon Member | Conciliation Team Member |
| `summonsserver` | Summons Server | Field delivery and proof-of-service recording |

> **Note**: These credentials are strictly for local development and testing. Passwords must be changed before deploying to staging or production.

---

## Database & Migrations

- `database/schema.sql` is the authoritative definition for fresh database installations.
- For updating existing databases that predate specific increments, refer to the step-by-step instructions in [database/README.md](database/README.md). Recent migrations include:
  - `database/migrations/20261020_001_create_paanyaya_stages_and_returns.sql` for the mandatory preliminary Paanyaya workflow and independent Officer's Return tracking.
  - `database/migrations/20261017_002_scheduling_conflict_and_mediation_improvements.sql` for scheduling capacity, holiday calendars, and venue conflict detection.
  - `database/migrations/20261016_001_enhance_file_complaint_kp7.sql` for the File Complaint module, versioned scanned KP Form 7 uploads, and demographic/guardian fields.
- Reference guides for developers and AI agents are maintained in [AI_DEVELOPMENT_GUIDE.md](AI_DEVELOPMENT_GUIDE.md) and [PROJECT_HANDOFF.md](PROJECT_HANDOFF.md).
