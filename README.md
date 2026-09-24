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

### 1. Complaint Intake, Management & Records Search

- **Dedicated Add Complaint Page (`complaint-create.php`)**:
  - Full-page, compact two-column card intake layout replacing legacy modal popups.
  - **Classification**: Incident category, narrative description, prayer for relief, and a mandatory **Case Type** dropdown (`Civil` or `Criminal`).
  - **Parties Involved**: Complainant and Respondent textboxes directly embedded in the intake form (eliminating separate "Add Party" modals), featuring live datalist autocomplete against registered barangay residents (`#residentOptions`) and automatic database linking to `complaint_parties`.
  - **Merged Incident Datetime**: Merged single `<input type="datetime-local" name="incident_datetime">` parsed server-side into `incident_date` (DATE) and `incident_time` (TIME).
  - **Geographic Tagging**: Specific incident location, landmark, and an interactive Leaflet/OpenStreetMap pin selector persisting exact GPS coordinates into `incident_locations`.
- **Dedicated Edit Complaint Page (`complaint-edit.php`)**:
  - Full-page edit interface with complete structural parity with `complaint-create.php`.
  - Preloads all existing facts, category, `case_type`, merged datetime (`YYYY-MM-DDTHH:MM`), parties, and Leaflet pin coordinates.
  - Atomic server updates: synchronizes `complaints`, propagates `case_type` to linked `cases`, updates/inserts `complaint_parties`, and persists or clears coordinates in `incident_locations`.
- **Modern, Compact Complaints List (`complaint-list.php`)**:
  - **4 Top KPI Metric Cards**: "Total Complaints", "Under Review", "In Progress", and "Settled" with one-click filtering.
  - **Quick Status Tabs**: Horizontal status tabs (`All`, `Filed`, `Under Review`, `Accepted`, `Docketed`, etc.) with live counts.
  - **Compact Toolbar**: Debounced keyword search, quick clear button, Case Type dropdown (`All`, `Civil`, `Criminal`), Category filter dropdown, and collapsible filter drawer for date ranges (`Date From`, `Date To`) and exact status.
  - **7-Column Table Layout**:
    1. **Complaint** (Complaint Number, Title, Filing Date)
    2. **Case No.** (Formatted Case No. or "Not Docketed" badge)
    3. **Category** (Badge)
    4. **Parties** (Complainant and Respondent with distinct role badges)
    5. **Incident Date** (Formatted date & time)
    6. **Status** (Color-coded status badge)
    7. **Actions** (View Details, Edit Page, Delete modal trigger)
- **Complaint Workspace (`complaint-details.php`)**:
  - Multi-stage review gate: `Filed` $\rightarrow$ `Under Review` / `Needs Information` $\rightarrow$ `Accepted` / `Rejected`.
  - Secure evidence upload vault supporting images (JPG, PNG), videos (MP4, WebM), and PDF documents up to 25 MB with MIME verification.

### 2. Mediation & Conciliation Progression Engine

- **Statutory Hearing Progression**:
  - **Mediation Stage**: 1st Mediation $\rightarrow$ 2nd Mediation $\rightarrow$ 3rd Mediation (up to 3 mediation sessions conducted by the Punong Barangay).
  - **Progression to Conciliation**: Unresolved cases advance to conciliation: 1st Conciliation $\rightarrow$ 2nd Conciliation $\rightarrow$ 3rd Conciliation (up to 3 conciliation sessions conducted by the Pangkat Tagapagkasundo).
  - **Scheduling Ceiling**: Once the 3rd Conciliation hearing is scheduled, the scheduling engine permanently halts further hearing additions, enforcing KP statutory limits.
- **Automatic Case Docketing Trigger**:
  - Scheduling the **1st Mediation** hearing automatically triggers case docketing (`cases.case_status = 'Docketed'`) and updates complaint status (`complaints.status = 'Docketed'`) within the same database transaction.
- **Hearing Conflict & Deadline Management**:
  - Prevents double-booking of cases or venues at identical date/times.
  - Automated calculation of statutory deadlines (`case_deadlines`) for Initial Hearings, Mediation, and Conciliation.

### 3. Case Management & Unified Case Teams

- **Case Workspace (`case-details.php`)**: Centralized command center presenting case facts, active case team, hearing schedules, generated KP forms, and proof-of-service records.
- **Unified Case Teams**:
  - Mediation stages assign the active `Administrator` as Punong Barangay/Head.
  - Conciliation stages require a three-member team (Head, Secretary, Member) selected from active users with the `Lupon Member` role, atomically validated and stored in `case_assignments`.

### 4. KP Document Generation & Proof of Service

- **Document Center (`document-center.php`)**:
  - Automated generation of official Katarungang Pambarangay forms (e.g., KP Form 12 - Notice of Hearing for Conciliation Proceedings).
  - Dynamic template binding with Dompdf, generating secure PDFs archived under `storage/generated-documents/<year>/<case-number>/`.
- **Proof of Service**:
  - Tracks service of notices and summonses (`Generated`, `For Service`, `Served`, `Service Failed`).
  - Mobile-friendly proof capture with photo evidence uploads (JPG, PNG, WebP up to 5 MB) stored securely.

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
- For updating existing databases that predate specific increments, refer to the step-by-step instructions in [database/README.md](database/README.md).
- Reference guides for developers and AI agents are maintained in [AI_DEVELOPMENT_GUIDE.md](AI_DEVELOPMENT_GUIDE.md) and [PROJECT_HANDOFF.md](PROJECT_HANDOFF.md).
