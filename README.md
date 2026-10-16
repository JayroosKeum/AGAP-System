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
  - [2. Mediation & Conciliation Progression Engine](#2-mediation--conciliation-progression-engine)
  - [3. Case Management & Unified Case Teams](#3-case-management--unified-case-teams)
  - [4. KP Document Generation & Proof of Service](#4-kp-document-generation--proof-of-service)
  - [5. Security, Audit Trails & Notifications](#5-security-audit-trails--notifications)
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
  - **8 Process-Based Status Navigation Tabs**: Horizontal quick-access tabs with live count pills for each stage and disposition: `All`, `Under Review`, `Docketed`, `Mediation`, `Conciliation`, `Arbitration`, `Settled`, `Dismissed`, and `CFA`.
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

### 2. Mediation & Conciliation Progression Engine

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
- **Office Logistics & Strict Scheduling Constraints**:
  - Enforces weekday-only scheduling (Monday to Friday, 8:00 AM to 5:00 PM).
  - Rejects hearings scheduled on the same date as an existing hearing for the case or on any prior date.
  - Room collision and presider conflict detection powered by `OfficeLogisticsService`.

### 3. Case Management & Unified Case Teams

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
- **Case & Team Assignments (`case-list.php`)**:
  - Live search bar positioned directly above the case team assignment table.
  - Filtered to display ongoing cases only (settled, dismissed, and archived cases are excluded).
  - Simplified 3-column table: Case No. (clickable link to Complaint Details), Status, and Action.
  - Integrated Substitute Presider assignment modal.
- **Unified Case Teams**:
  - Mediation stages assign the active `Administrator` as Punong Barangay/Head.
  - Conciliation stages require a three-member team (Head, Secretary, Member) selected from active users with the `Lupon Member` role, atomically validated and stored in `case_assignments`.

### 4. KP Document Generation & Proof of Service

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
  - **Unified Expandable Case Table**: Displays all cases requiring summons tracking. Rows expand/collapse inline to reveal side-by-side service records for Complainant and Respondent.
  - **Live Filter Tabs & Search**: Instant keyword search across Case No., parties, and titles; filter tabs for **All Cases** and **Pending Delivery** with live counter pills.
  - **Top KPI Indicators**: Compact summary counters for Total Cases, Pending Delivery, CFA Eligible, and Served Today.
  - **Complainant & Respondent Visual Parity**: Matching two-column card structure, badges, typography, and detail lists (`Date/Time Served`, `Service Source / Server`, `Service Method`, `Remarks`, failure notes, proof links).
  - **Horizontal Summons Stepper**: Interactive progression nodes for Attempt 1, Attempt 2, Attempt 3, and CFA status with dynamic state badges (`Served`, `Pending`, `Failed`, `Locked`).
  - **Auto-Served Complainant Notice**: Automatically sets KP Form 8 to `Served` upon issuance of the 1st Attempt summons from the complaint page.
  - **Slide-in Officer's Return Drawer**: Modern off-canvas drawer (`#officerReturnModal`) with distinct Case Number, Party, and Document Form badges for recording delivery outcomes, recipient relationship, unserved reasons, notes, and photo proof attachments.
  - **Clean Action Footers**: Streamlined card footers focused on **Log Officer's Return** alongside next-stage progression buttons (*Issue Summon 2nd Attempt*, *Proceed to CFA*), removing clutter from redundant print buttons.

### 5. Security, Audit Trails & Notifications

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
- For updating existing databases that predate specific increments, refer to the step-by-step instructions in [database/README.md](database/README.md). Recent migrations include `database/migrations/20261016_001_enhance_file_complaint_kp7.sql` for the File Complaint module, versioned scanned KP Form 7 uploads, and demographic/guardian fields.
- Reference guides for developers and AI agents are maintained in [AI_DEVELOPMENT_GUIDE.md](AI_DEVELOPMENT_GUIDE.md) and [PROJECT_HANDOFF.md](PROJECT_HANDOFF.md).
