# AGAP-Remastered AI Handoff

## 1. Project Identity

- Project name: `AGAP-Remastered`
- Database name: `remastered_db`
- Local environment: Laragon
- Database interface: HeidiSQL
- Database server: MariaDB or MySQL
- Backend: Native PHP
- Database access: PDO
- Dependency rule: Composer must not be used
- Authentication: Session-based
- Current primary account: Administrator
- Administrator represents the Barangay Captain or Lupon Head
- Do not create a separate `luponhead` account or role
- Resident self-service portal is not required

## 2. Mandatory Development Rules

1. Inspect and trace the existing implementation before changing a workflow.
2. Do not rebuild an existing process from scratch.
3. Do not create a parallel or replacement workflow when an existing workflow can be refined.
4. Reuse and update existing files when necessary.
5. Preserve existing data, history, backend authority, transactions, and audit records.
6. Use safe, forward-only, data-preserving SQL migrations when schema changes are required.
7. Update the canonical `database/schema.sql` whenever the database structure changes.
8. Use prepared PDO statements for database operations.
9. Enforce authorization and business rules on the backend.
10. Use CSRF protection for state-changing requests.
11. Return complete final code for every modified or created file.
12. Provide an exact `.txt` copy of every JavaScript file.
13. Keep the JavaScript file and corresponding `.txt` copy identical.
14. Clearly separate retained, fixed, refined, and new behavior in change documentation.
15. Prioritize missing module and process functionality over cosmetic work or unnecessary hardening.
16. Do not describe static shells, placeholders, or hardcoded values as completed functionality.
17. Do not use Composer packages or Composer autoloading.

## 3. Current Application Structure

The application uses the following general structure:

```text
AGAP-Remastered/
├── backend/
│   ├── api/
│   │   ├── ai/
│   │   ├── complaints/
│   │   ├── geocoding/
│   │   └── profiles/
│   ├── config/
│   ├── controllers/
│   ├── models/
│   ├── services/
│   └── validators/
├── database/
│   ├── migrations/
│   └── schema.sql
├── docs/
│   └── workflows/
├── frontend/
│   ├── assets/
│   │   ├── css/
│   │   └── js/
│   │       └── pages/
│   ├── components/
│   └── pages/
├── scripts/
├── storage/
│   └── cache/
└── index.php
```

## 4. Navigation and Interface Requirements

- Preserve the dashboard-aligned layout and styling.
- Preserve the collapsible navigation pane.
- Navigation expansion is hover-based rather than click-based.
- Navigation sections include:
  - Overview
  - Case Management
  - Records
  - Administration
- Profiles is under Case Management.
- Cases contains the main process:
  - Summon
  - Mediation
  - Conciliation
  - CFA
- New pages must visually align with the dashboard and existing navigation behavior.

## 5. Profiles Module Status

The Profiles module is a controlled resident identity registry used for party verification and dispute proceedings. It is not a resident login or self-service account system.

### 5.1 Retained Registry Features

The Profiles page must retain these features before and outside the Add Profile modal:

- Profiles page heading and registry description
- Add Profile button
- KPI summary cards
  - All Profiles
  - Permanent Residents
  - Tenants
  - Non-Residents, when supported by the active schema
  - Active Puroks
- Search by profile name, Purok, address, contact information, or profile identifier
- Purok or area filter
- Residency filter
- Sorting controls
- Profile result count
- Responsive profile table
- Profile name and demographic summary
- Address
- Purok badge
- Residency badge
- View action
- Edit action
- Archive/reactivate or existing safe removal behavior
- Pagination with 25 profiles per page
- Existing JSON list and view endpoints
- Existing profile history and audit behavior

Do not remove or replace these registry features when implementing or refining the Add Profile modal.

## 6. Add Profile Modal

### 6.1 Modal Identity

- Modal title: `Add Profile`
- Modal subtext: `Register an identity record for party verification and dispute proceedings.`
- The modal opens from the existing Add Profile button.
- The modal must not replace the Profiles registry page.
- The modal must remain responsive and scroll within the viewport.

### 6.2 Required Fields

Every visible Add Profile field is required.

#### Personal Information

- First Name
- Middle Name
- Last Name
- Birth Date
- Gender
- Civil Status

#### Residency and Contact Information

- Contact Number
- Email Address
- Purok / Zone
- Residency Status
- Complete Street Address

#### Required Select Values

Gender:

- Male
- Female
- Other

Civil Status:

- Single
- Married
- Widowed
- Separated

Residency Status:

- Permanent Resident
- Temporary Resident
- Tenant

### 6.3 Required-Field Presentation

- Every visible field label must display an asterisk.
- The asterisk must remain horizontally aligned beside the field label.
- Use one consistent inline label structure and style for all asterisks.
- Empty required fields must receive a red border.
- Empty required fields must show `This field is required.`
- Validation must run on blur, change, and form submission.
- Backend validation must match frontend validation.
- A profile must not be saved when any visible field is empty or invalid.

## 7. Profiles Map Integration

### 7.1 Leaflet Configuration

- Leaflet is used for the embedded address map.
- Map center: approximately `14.6548, 121.0862`.
- The map displays OpenStreetMap tiles.
- OpenStreetMap attribution must remain visible.
- A blue highlighted polygon identifies the configured Barangay Tumana area.
- The boundary polygon is stored locally in the JavaScript implementation so the map does not depend on a boundary lookup request.
- The marker is draggable.
- Clicking the map moves the marker.
- The map can be hidden and shown using the map visibility button.

### 7.2 Bidirectional Synchronization

The pin and address fields must update each other.

#### Map to Fields

A map click or marker drag must update:

- Latitude
- Longitude
- Complete Street Address
- Purok / Zone, when a matching value is available

If reverse geocoding is unavailable:

- Preserve the selected coordinates.
- Keep the marker at the selected location.
- Allow manual address and Purok entry.
- Display a clear non-blocking lookup message.

#### Fields to Map

Changing the Complete Street Address must:

- Perform forward geocoding.
- Update the marker position when a match is found.
- Update the stored latitude and longitude.

Changing the Purok / Zone must:

- Reposition the marker to the configured Purok center.
- Update the stored latitude and longitude.
- Populate a base Tumana address when the address is empty.

### 7.3 Geocoding Endpoints

```text
backend/api/geocoding/reverse.php
backend/api/geocoding/forward.php
```

Requirements:

- Require authenticated Administrator access.
- Return JSON.
- Prefer cURL when available.
- Fall back to `allow_url_fopen` when available.
- Use a descriptive application User-Agent.
- Cache reverse-geocoding results under `storage/cache/geocoding/`.
- Do not prevent manual address entry if the external geocoding service is unavailable.

### 7.4 Map Save Requirement

A new profile cannot be saved without valid latitude and longitude values. Coordinates can be established by:

- Clicking the map
- Dragging the marker
- Selecting a configured Purok
- Entering an address that is successfully forward-geocoded

## 8. Profiles Module Files

### 8.1 Existing or Modified Files

```text
frontend/pages/profiles.php
frontend/assets/css/profiles.css
frontend/assets/js/pages/profiles.js
frontend/assets/js/pages/profiles.js.txt
frontend/assets/js/pages/profile-add-modal.js
frontend/assets/js/pages/profile-add-modal.js.txt
frontend/components/profile-add-modal.php

backend/models/Profile.php
backend/services/profiles.php
backend/validators/profile-validator.php
backend/api/profiles/create.php
backend/api/profiles/list.php
backend/api/profiles/save.php
backend/api/profiles/status.php
backend/api/profiles/view.php
backend/api/geocoding/reverse.php
backend/api/geocoding/forward.php

database/migrations/20261015_001_profiles_module.sql
database/migrations/20261015_002_profile_modal_fields.sql
database/schema.sql

docs/workflows/profiles-module.md
docs/workflows/profiles-add-modal.md
docs/workflows/profiles-required-fields-map-sync.md
```

### 8.2 JavaScript Copy Rule

These copies must remain exactly identical:

```text
frontend/assets/js/pages/profiles.js
frontend/assets/js/pages/profiles.js.txt

frontend/assets/js/pages/profile-add-modal.js
frontend/assets/js/pages/profile-add-modal.js.txt
```

## 9. Profiles Database Expectations

The Profiles module uses a `profiles` table and profile history records.

Expected profile information includes:

- Profile identifier
- Profile number
- First name
- Middle name
- Last name
- Optional suffix at the schema level if retained for compatibility
- Birth date
- Gender
- Civil status
- Contact number
- Email address
- Complete street address
- Purok or zone
- Barangay
- City or municipality
- Province
- Residency status
- Latitude
- Longitude
- Status
- Created by
- Updated by
- Created timestamp
- Updated timestamp

Do not destructively remove compatibility fields merely because the Add Profile modal does not display them.

## 10. Duplicate Profile Handling

- Similar or identical names may exist when addresses are different.
- Reject a new or updated profile when both the name and address indicate the same person record.
- Duplicate validation must run on the backend.
- Duplicate messages must identify the existing profile clearly enough for the Administrator to review it.
- Do not rely only on exact string equality if the existing implementation already supports normalized or similar-name/address comparison.

## 11. Profile Lifecycle and Audit Rules

- Avoid destructive deletion when a profile is referenced by complaints or cases.
- Prefer archive and reactivate behavior.
- Preserve profile history.
- Record the Administrator responsible for create, update, archive, and reactivate actions.
- Use database transactions for profile writes and history records.
- Do not allow the frontend to be the final authority for status or authorization decisions.

## 12. Complaint and Case Integration

Profiles are intended to become the stable identity source for:

- Complainants
- Respondents
- Witnesses
- Other dispute parties

When integrating Profiles with Complaints:

1. Reuse the stable Profiles module.
2. Preserve current complaint records and party links.
3. Do not duplicate identity data unnecessarily.
4. Use backend-controlled profile selection and validation.
5. Preserve compatibility with existing complaint workflows.
6. Do not begin this integration by rebuilding complaint intake.

## 13. Process Development Direction

After stabilizing Profiles, continue refining the existing dispute process in this order:

1. Complaint intake and party linkage
2. Summons
3. Mediation
4. Conciliation
5. CFA
6. Stage-specific what-if scenarios
7. Notices, schedules, outcomes, and transitions
8. Reports and record retrieval

For every process improvement:

- Trace the existing workflow first.
- Identify retained behavior.
- Identify broken behavior.
- Identify refinements.
- Identify genuinely new behavior.
- Update existing files rather than creating parallel workflows.

## 14. Required Change Documentation Format

Every implementation handoff must use these headings:

```text
## Retained Behavior
## Fixed Behavior
## Refined Behavior
## New Behavior
## Database Changes
## Modified Files
## New Files
## JavaScript Copies
## Verification Steps
```

## 15. Current Profiles Verification Checklist

1. Open the Profiles page as Administrator.
2. Confirm KPI cards remain visible.
3. Confirm search, Purok filtering, residency filtering, sorting, and pagination still work.
4. Confirm View and Edit actions remain available.
5. Open the Add Profile modal.
6. Submit with empty fields.
7. Confirm every visible field shows required validation.
8. Confirm every required asterisk is aligned beside the corresponding label.
9. Select a Purok and confirm the marker moves.
10. Enter a valid address and confirm the marker moves after forward geocoding.
11. Click the map and confirm the marker, coordinates, address, and Purok update.
12. Drag the marker and confirm the same field updates occur.
13. Disable or interrupt geocoding and confirm manual address entry remains possible.
14. Confirm the profile cannot be saved without coordinates.
15. Save a valid profile and confirm the registry refreshes or opens the saved profile.
16. Confirm profile history records the create action.
17. Confirm both JavaScript `.txt` copies exactly match their `.js` files.

## 16. Latest Profiles Package

The latest generated implementation package in the current work sequence is:

```text
AGAP-Remastered-Profiles-Required-Map-Sync.zip
```

This package preserves the Profiles registry and adds:

- Required validation for every visible Add Profile field
- Aligned required-field asterisks
- Map-to-field synchronization
- Address-to-map synchronization
- Purok-to-map synchronization
- Forward geocoding endpoint
- Exact JavaScript text copy

## 18. Complaints Module Architecture

The Complaints module in `AGAP-Remastered` provides full-lifecycle management of barangay dispute records, aligning with the Katarungang Pambarangay (KP) process.

### 18.1 Route and File Map

- Landing & List View: `index.php?page=complaints` (`frontend/pages/complaints.php`)
- Detail & History View: `index.php?page=complaints&action=view&id={complaint_id}` (`frontend/pages/complaints.php`)
- Dedicated Intake View: `index.php?page=complaints-create` and `index.php?page=complaints&action=create` (`frontend/pages/complaints-create.php`)
- Create API Endpoint: `backend/api/complaints/create.php`
- Service Layer: `backend/services/complaints.php`
- Model Layer: `backend/models/Complaint.php`
- Validator: `backend/validators/complaint-validator.php`

### 18.2 Complaints List & Lifecycle Management

Located in `frontend/pages/complaints.php`:

- **Role & Access**: Strictly restricted to authenticated Administrators via `requireAdmin()`.
- **Lifecycle Status Tabs**:
  - `All`: Total count of all complaints.
  - `Pending`: Complaints with status `Filed`, `Pending Review`, `Under Review`, or process stage `Intake`.
  - `Mediation`: Complaints currently in Punong Barangay mediation.
  - `Conciliation`: Complaints undergoing Pangkat ng Tagapagkasundo conciliation.
  - `CFA`: Complaints where a Certificate to File Action has been issued.
  - Counts are computed directly from the database and displayed as pill badges in the tab headers.
- **Search & Filter Toolbar**:
  - Search query input matching Case Number, Title, Complainant name, Respondent name, or Location.
  - Case Type filter dropdown (`All Case Types`, `Civil`, `Criminal`).
  - Search icon with instant keyboard submit and clear handling.
- **Data Table**:
  - Displays Case No, Title, Category, Case Type, Complainant, Respondent, Location, Date Filed, and Status.
  - Action buttons: `View Details` linking to the complaint inspection view.
- **Add Complaint Action**: Prominent `+ Add Complaint` button linking to `index.php?page=complaints-create`.

### 18.3 Complaint Detail & Case Inspection View

When `action === 'view'` and a valid `id` is provided:

- **Case Header**: Displays Case No (e.g., `KP-2026-000001`), Incident Title, Process Stage pill, Status pill badge, Case Type pill (`Civil` or `Criminal`), and filing audit details (timestamp and creator).
- **Five Structural Cards**:
  1. **Incident & Classification**: Complaint Title, Category name, Case Type, Incident Date & Time.
  2. **Involved Parties**: Complainant profile details with link to registry, Respondent profile details with residency verification status, and all registered Witnesses.
  3. **Narrative & Facts**: Complete statement of facts and optional additional details.
  4. **Incident Location**: Complete address, Purok/Zone, Landmark, and an embedded Leaflet map showing the exact pinned location and the Barangay Tumana boundary polygon.
  5. **Evidence / Attachments & Proof Notes**: Downloadable file attachments (with file type badges and sizes) and extracted Proof OCR notes.
- **Audit Trail & Processing History**: Comprehensive chronological event timeline table logging action, acted by, and timestamp.

---

## 19. Dedicated 5-Card Complaint Intake System

The complaint intake form (`frontend/pages/complaints-create.php`) implements the original AGAP 5-card layout.

### 19.1 Layout & Container Specs

- **Container Wrapper**: Wrapped in `.complaint-intake-workspace` and `.complaint-intake-container` with symmetrical padding (`padding: 28px 36px 48px; max-width: 1680px; margin: 0 auto;`).
- **Header**: Includes `< Back to Complaints` link, `New Complaint Intake` title, and descriptive subtitle.
- **2-Column Grid**: `.complaint-intake-grid` (`minmax(0, 1.35fr) minmax(0, 1fr)`) containing:
  - Left Column: Cards 1, 2, and 3.
  - Right Column: Cards 4 and 5.
- **Actions Footer**: Standalone bottom card (`position: static; margin: 0 0 40px 0; border-radius: 12px; padding: 18px 24px; background: #fff; border: 1px solid #e2e8f0;`) containing `Cancel` and `Submit Complaint` buttons right-aligned without sticky overlay clipping.

### 19.2 Intake Cards Breakdown

#### Card 1: Incident & Classification
- **Complaint Category**: Select dropdown populated dynamically from the canonical `complaint_categories` table (10 official categories).
- **Case Type**: Select dropdown (`Civil` or `Criminal`).
- **Complaint Title**: Required text input (max 255 chars).
- **Incident Date & Time**: Required datetime-local picker, defaulting to the current client date and time if empty.

#### Card 2: Involved Parties
- **Complainant Searchable Combobox**:
  - Labeled with `COMPLAINANT DETAILS` badge (`#e0f2fe; color: #0284c7;`).
  - Searchable input querying registered profiles in `window.AGAP_RESIDENTS`.
  - Supports Permanent Residents, Tenants, and Non-Residents.
  - Displays selected profile summary preview and hidden ID input.
- **Respondent Searchable Combobox**:
  - Labeled with `RESPONDENT DETAILS` badge (`#fee2e2; color: #dc2626;`).
  - Searchable input restricted to verified residents of Barangay Tumana.
  - Warns and disables non-resident profiles.
- **Dynamic Additional Parties (Witnesses)**:
  - Labeled with `WITNESS(ES) & ADDITIONAL PARTIES` badge (`#fef3c7; color: #d97706;`).
  - Container for dynamically appended party rows (`additional-party-row`).
  - `+ Add Witness / Extra Party` button adds a new row with party type select (`Witness`, `Complainant`, `Respondent`), combobox search, and removal button.
- **Distinct Party Validation**:
  - Real-time client-side validation (`window.validateDistinctParties`) preventing the same person from being selected as both Complainant and Respondent.
  - Outlines invalid fields in red and displays an alert banner if a conflict is detected.
  - Re-enforced on the backend during complaint creation.

#### Card 3: Narrative & Facts
- **Narrative**: Required textarea (up to 15,000 characters).
- **AI Enhancement**:
  - Full-width dashed button `✨ Enhance with AI` (`[data-enhance-narrative]`).
  - Sends current draft to `backend/api/ai/refine-narrative.php`.
  - Displays green suggestion preview box with `Apply suggestion` and `Keep my original` actions.
- **Additional Details**: Optional textarea (up to 5,000 characters) for prior settlement attempts, damages, injuries, or extra context.

#### Card 4: Incident Location
- **City**: Readonly `Marikina City`.
- **Barangay**: Dropdown populated with all 16 Marikina barangays (default `Tumana`).
- **Street / Specific Location**: Required text input.
- **Purok / Zone**: Numbered puroks (1 to 8) and compounds (Doña Petra, Bagong Farmers, Bukang Liwayway, Libis Tumana, Bagong Purok, Palay, Mais, Singkamas).
  - Automatically synchronizes with Barangay selection: disables `Outside Tumana` when Tumana is selected, and automatically sets `Outside Tumana` when another barangay is selected.
- **Nearby Landmark**: Optional text input.
- **Interactive Map**:
  - Embedded Leaflet map container (`#addComplaintMap`, height 280px).
  - Centered on Barangay Tumana with a blue boundary polygon (`#2563eb`).
  - Initialized with `map.invalidateSize()` and `map.fitBounds()` after layout stabilization to guarantee correct tile rendering.
  - Placing a pin or dragging the marker updates coordinate hidden inputs and reverse-geocodes the address.
  - Typing an address triggers debounced forward geocoding to update the pin.
  - Status display and `Clear pin` button.

#### Card 5: Evidence / Attachments
- **Drag & Drop Upload Zone**:
  - Dotted upload zone (`#evidenceDropZone`) accepting images, videos, PDF, and Word documents up to 25 MB each.
  - File input supports multi-file selection.
- **Live Evidence Queue Preview**:
  - Displays queued files with thumbnail preview (for images) or type icon (`IMG`, `VID`, `PDF`, `DOC`, `FILE`).
  - Shows file name, category badge, and formatted file size (KB / MB).
  - Individual remove buttons backed by a `DataTransfer` object.
  - `Clear All` button.
- **Proof Notes**: Textarea for manual context or OCR extracted text.
- **📷 Scan Proof (OCR)**:
  - Invokes `backend/api/ai/ocr-notes.php` with a selected document image.
  - Automatically appends extracted text to the Proof Notes field.

---

## 20. AI Microservices & Geocoding Endpoints

### 20.1 AI Endpoints

Located in `backend/api/ai/`, backed by `backend/controllers/AIController.php` and `backend/controllers/AiServiceController.php`:

1. **`refine-narrative.php`**:
   - Accepts POST JSON `{ narrative: string }`.
   - Uses Gemini LLM models to refine grammar, neutrality, and chronological clarity without adding invented facts.
   - Returns `{ success: true, narrative: string }`.
2. **`ocr-notes.php`**:
   - Accepts multipart file upload `image`.
   - Uses Gemini multimodal OCR vision to extract text from handwritten or printed proof documents.
   - Returns `{ success: true, text: string }`.
3. **`chatbot.php`**:
   - Lupon dispute procedure and legal reference assistant for administrators.
4. **`transcribe-audio.php`**:
   - Transcribes audio recordings of hearings and witness testimonies.

### 20.2 Geocoding Endpoints

Located in `backend/api/geocoding/`:

1. **`forward.php`**: Accepts address query and returns matched latitude/longitude.
2. **`reverse.php`**: Accepts coordinates and returns reverse-geocoded address. Caches responses in `storage/cache/geocoding/`.
3. **`tumana-boundary.php`**: Serves the official coordinate polygon of Barangay Tumana.

---

## 21. Backend Service, Model, and Transactions

### 21.1 ComplaintService (`backend/services/complaints.php`)

- **MySQL `ONLY_FULL_GROUP_BY` Compatibility**:
  - All queries aggregate party and attachment counts without invalid grouping column references.
- **Atomic Database Transaction**:
  - Encloses:
    1. Insert into `complaints`.
    2. Insert into `complaint_parties` (Complainant, Respondent, and all dynamic Witnesses).
    3. Storage of uploaded files under `storage/uploads/complaints/` and records in `complaint_attachments`.
    4. Initial audit trail log in `complaint_history`.
  - Automatically rolls back on any error.

### 21.2 Complaint Model (`backend/models/Complaint.php`)

- Generates canonical case numbers: `KP-YYYY-XXXXXX`.
- Stores complete incident metadata: title, case type, category, date, time, location, coordinates, narrative, additional details, and proof OCR notes.

### 21.3 Complaint Creation API (`backend/api/complaints/create.php`)

- Validates session authentication (`requireAdmin()`) and CSRF token (`$_SESSION['complaint_csrf']`).
- Handles multipart evidence file uploads.
- Redirects to `index.php?page=complaints&action=view&id={new_id}` on success with flash message.

---

## 22. Database Migrations & Canonical Schema

### 22.1 Migration Sequence

1. **`20261015_001_profiles_module.sql`**: Formalized `profiles` table and `profile_history`.
2. **`20261015_002_profile_modal_fields.sql`**: Added demographic, voter, and coordinate fields to `profiles`.
3. **`20261015_003_complaint_incident_intake.sql`**: Created core `complaints`, `complaint_parties`, and `complaint_history` tables.
4. **`20261015_004_original_complaint_schema_alignment.sql`**:
   - Created `complaint_categories` table populated with 10 standard categories.
   - Added incident fields to `complaints` (`incident_datetime`, `incident_date`, `incident_time`, `incident_city`, `incident_barangay`, `incident_street`, `incident_purok`, `incident_landmark`, `incident_latitude`, `incident_longitude`, `case_type`, `category_id`, `additional_details`, `proof_ocr_notes`, `process_stage`).
   - Updated `complaint_parties.party_type` ENUM to include `Witness`.
   - Created `complaint_attachments` table.
5. **`scripts/migrate_complaint_schema.php`**: Automated PHP migration script for backward-compatible execution.

### 22.2 Canonical `database/schema.sql`

`database/schema.sql` has been dumped and synchronized with `remastered_db`. It defines:

- `users` (administrative accounts)
- `profiles` & `profile_history`
- `complaints` & `complaint_history`
- `complaint_categories` (seeded with 10 official categories)
- `complaint_parties`
- `complaint_attachments`

---

## 23. Complete Files Manifest

```text
AGAP-Remastered/
├── backend/
│   ├── api/
│   │   ├── ai/
│   │   │   ├── chatbot.php
│   │   │   ├── narrative.php
│   │   │   ├── ocr-notes.php
│   │   │   ├── refine-narrative.php
│   │   │   └── transcribe-audio.php
│   │   ├── complaints/
│   │   │   └── create.php
│   │   ├── geocoding/
│   │   │   ├── _client.php
│   │   │   ├── forward.php
│   │   │   ├── reverse.php
│   │   │   └── tumana-boundary.php
│   │   └── profiles/
│   │       ├── create.php
│   │       ├── list.php
│   │       ├── lookup.php
│   │       ├── save.php
│   │       ├── status.php
│   │       └── view.php
│   ├── config/
│   │   └── database.php
│   ├── controllers/
│   │   ├── AIController.php
│   │   └── AiServiceController.php
│   ├── models/
│   │   ├── Complaint.php
│   │   └── Profile.php
│   ├── services/
│   │   ├── auth.php
│   │   ├── complaints.php
│   │   └── profiles.php
│   └── validators/
│       ├── complaint-validator.php
│       └── profile-validator.php
├── database/
│   ├── migrations/
│   │   ├── 20261015_001_profiles_module.sql
│   │   ├── 20261015_002_profile_modal_fields.sql
│   │   ├── 20261015_003_complaint_incident_intake.sql
│   │   └── 20261015_004_original_complaint_schema_alignment.sql
│   └── schema.sql
├── docs/
│   └── workflows/
│       ├── complaint-intake.md
│       ├── profiles-add-modal.md
│       ├── profiles-module.md
│       └── profiles-required-fields-map-sync.md
├── frontend/
│   ├── assets/
│   │   ├── css/
│   │   │   ├── app.css
│   │   │   ├── complaints.css
│   │   │   ├── dashboard.css
│   │   │   ├── navigation-pane.css
│   │   │   └── profiles.css
│   │   └── js/
│   │       ├── complaints.js
│   │       ├── complaints.js.txt
│   │       └── pages/
│   │           ├── complaints-create.js
│   │           ├── complaints-create.js.txt
│   │           ├── profile-add-modal.js
│   │           ├── profile-add-modal.js.txt
│   │           ├── profiles.js
│   │           └── profiles.js.txt
│   ├── components/
│   │   ├── profile-add-modal.php
│   │   └── sidebar.php
│   └── pages/
│       ├── complaints.php
│       ├── complaints-create.php
│       ├── dashboard.php
│       └── profiles.php
├── scripts/
│   └── migrate_complaint_schema.php
└── index.php
```

---

## 24. JavaScript Exact `.txt` Copy Rule

In accordance with Mandatory Rules 12 & 13, exact matching `.txt` copies are maintained in the repository:

- `frontend/assets/js/complaints.js` <-> `frontend/assets/js/complaints.js.txt`
- `frontend/assets/js/pages/complaints-create.js` <-> `frontend/assets/js/pages/complaints-create.js.txt`
- `frontend/assets/js/pages/profiles.js` <-> `frontend/assets/js/pages/profiles.js.txt`
- `frontend/assets/js/pages/profile-add-modal.js` <-> `frontend/assets/js/pages/profile-add-modal.js.txt`

Whenever any of these JavaScript files is modified, the corresponding `.txt` file must be updated simultaneously with identical contents.

---

## 25. Git Tracking & Deployment Status

- **Remote URL**: `https://github.com/JayroosKeum/AGAP-System.git`
- **Active Working Branch**: `sanga-remastered`
- **Tracking**: Set up to track `origin/sanga-remastered`.
- **Exclusions (`.gitignore`)**:
  - `AGAP.zip.txt` (large binary archive)
  - `/storage/cache/*` (geocoding and original extraction caches)
  - `/storage/uploads/*`
  - `/storage/logs/*`
  - `/.env`

---

## 26. Complete Verification Checklist

1. **Profiles Module Verification**:
   - Open `index.php?page=profiles` as Administrator.
   - Confirm KPI cards, search, filters, table, and pagination load properly.
   - Click `+ Add Profile` and confirm the modal opens.
   - Test required field validations and bidirectional Leaflet map sync.
   - Confirm new profile saves and creates an audit history entry.

2. **Complaints List Verification**:
   - Open `index.php?page=complaints` as Administrator.
   - Confirm lifecycle tabs (`All`, `Pending`, `Mediation`, `Conciliation`, `CFA`) display accurate counts.
   - Test search query box and Case Type filter dropdown.
   - Click `+ Add Complaint` and verify navigation to `index.php?page=complaints-create`.

3. **Complaint Intake Form (5 Cards) Verification**:
   - Open `index.php?page=complaints-create`.
   - Confirm all 5 intake cards are cleanly visible and symmetrically padded.
   - **Card 1**: Verify Category dropdown lists 10 categories, Case Type has Civil/Criminal, Title and Datetime work.
   - **Card 2**: Search and select Complainant. Search and select Respondent (verified Tumana residents only). Test selecting the same profile and confirm distinct party warning appears. Click `+ Add Witness / Extra Party` and verify additional party row is appended.
   - **Card 3**: Enter draft narrative. Click `✨ Enhance with AI` and confirm AI preview box displays suggestions with `Apply` and `Keep` actions.
   - **Card 4**: Confirm Leaflet map loads centered on Tumana with blue boundary polygon. Click/drag pin and verify coordinate and reverse-geocoding sync. Change barangay to non-Tumana and verify Purok switches to `Outside Tumana`.
   - **Card 5**: Drag files into evidence zone and confirm file queue preview renders thumbnail/icon, badge, and size. Click `📷 Scan Proof (OCR)` and select an image to verify text extraction into Proof Notes.
   - **Bottom Action Bar**: Confirm `Cancel` and `Submit Complaint` buttons are cleanly housed in a standalone card with zero sticky clipping.
   - Submit a valid complaint and confirm redirect to the Complaint Details view.

4. **Complaint Detail View Verification**:
   - Verify Case Number (`KP-YYYY-XXXXXX`), Stage pill, Status pill, and metadata.
   - Confirm all 5 cards reflect submitted data, map displays the pinned incident location with Tumana boundary, attachments are downloadable, and the chronological audit history timeline logs the filing action.

---

## 27. Next Dispute Process Phases

With Profiles and Complaints modules stabilized and aligned, subsequent dispute resolution phases should be implemented in this sequence:

1. **Summons Issuance & Tracking**:
   - KP Form No. 8 (Notice of Hearing for Complainant) auto-served tracking.
   - KP Form No. 9 (Summons for Respondent) tracking 3 successive delivery attempts with officer returns and proof of service.
2. **Mediation (Punong Barangay Stage)**:
   - Scheduling mediation sessions, mediation hearings, minutes of proceeding, and amicable settlement (KP Form No. 16) or failure to settle.
3. **Conciliation (Pangkat ng Tagapagkasundo Stage)**:
   - Constitution of the Pangkat, Pangkat hearings, amicable settlement, or arbitration agreement (KP Form No. 14).
4. **Certification to File Action (CFA)**:
   - Conditional unlocking and automated generation of KP Form No. 20 based on verified grounds (e.g., 3 failed summons attempts, repudiation, or failure to settle).
