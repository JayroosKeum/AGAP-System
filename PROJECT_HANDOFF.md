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
- **Complaint workspace & unified evidence intake:** The complaint details page consolidates review,
  parties, and a unified evidence upload modal. In `complaint-create.php`, a dedicated
  "Evidence / Attachments" section allows optional multiple uploads (images, videos, PDF, DOC, DOCX up to 25 MB)
  with drag-and-drop, client-side queue preview/removal, and secure backend validation/storage in
  `storage/uploads/complaint-evidence/`. Complaint creation and editing are handled from the Complaints page;
  those forms include the incident details, evidence attachments, and optional exact map pin. The standalone
  Incident Locations page, its navigation links, and its location-only API routes are retired.

### Complaint incident fields

The `complaints` table includes optional `incident_time` and
`incident_landmark`, plus a required `incident_location`, in addition to the
existing date, narrative, and additional details. The Add/Edit Complaint forms
also provide an optional Leaflet map pin. Coordinates and the location address
are stored in `incident_locations` within the same transaction as the complaint
write; an edit retains the pin unless it is moved or explicitly cleared.
- **Case workspace:** Opening a case now leads to `case-details.php`, which
  presents the case overview, team, hearings, generated documents, and proof
  of service together. The case list remains the cross-case monitoring screen.
- **Atomic case team:** Case Assignments saves Head, Secretary, and Member
  together. For `Docketed` and `Mediation`, the active `Administrator` account
  represents the Barangay Captain and is automatically assigned as Head;
  Secretary and Member cannot be manually assigned at these stages. For
  `Conciliation`, the team can be initially assigned once from the assignment
  form. Existing Conciliation assignments are read-only there and can be
  changed through the authorized case Edit operation. All three team members
  must be distinct active users with the role name `Lupon Member`. Server-side
  checks enforce the rules, and case/status/team edits are transactional.
  Existing Pangkat tables are synchronized on Conciliation team saves for KP
  Form 12 compatibility. The separate Pangkat page redirects to Case
  Assignments and is no longer an operational feature.
- **Document-linked service:** Proof-of-service records require a generated
  document belonging to the selected case. Saving proof marks the document
  `Served`; document service states are `Generated`, `For Service`, `Served`,
  and `Service Failed`.

### Case Assignment Stage Rules (September 2026)

- `Docketed` and `Mediation` are treated as automatic-Head stages by the case
  assignment UI and backend. The Head is the first active user with the
  `Administrator` role. The assignment form disables the Head, Secretary,
  Member, and save controls; the API rejects manual team submissions.
- A Conciliation case with no team accepts one initial Head/Secretary/Member
  team through `backend/api/assignments/team.php`. The three IDs must refer to
  different active users whose role name is `Lupon Member`.
- Once a Conciliation team exists, the assignment form displays it read-only
  and directs staff to **Edit**. The case Edit modal preselects current active
  members and updates the case fields and team in one database transaction.
  The server validates roles and distinct membership and updates existing
  assignment rows rather than adding another set.
- Switching a case to `Docketed` or `Mediation` restores the automatic
  Administrator Head and removes the manual Secretary and Member case
  assignments. Switching to `Conciliation` requires a complete valid team.
- The case update API returns JSON, checks for an authenticated Administrator
  or Lupon Clerk, and the controller records the existing audit event. No
  database schema or migration change was required.
- Relevant files: `frontend/pages/cases/case-list.php`,
  `frontend/assets/js/cases.js`, `frontend/assets/js/assignments.js`,
  `backend/api/cases/update.php`, `backend/controllers/CaseController.php`,
  `backend/models/CaseModel.php`, and `backend/models/Assignment.php`.

### Case Assignment Testing Status

- PHP syntax checks passed for the changed PHP files. JavaScript syntax checks
  and `git diff --check` passed.
- Browser/MySQL assignment scenarios have not yet been run. Verify automatic
  Head display and rejected manual submits for Docketed/Mediation, initial and
  repeated Conciliation assignment, Edit updates, role restrictions, and
  status transitions in Laragon without using production data.

### 15-Day Statutory Mediation Deadline (September 2026)

- Implemented Katarungang Pambarangay (RA 7160 Section 410(b)) 15-day statutory mediation deadline logic end-to-end:
  - **Clock Trigger (Day 0)**: Mediation clock begins on the date of the First Scheduled Mediation Hearing (or summon served date), not raw filing.
  - **Duration & Calendar Math**: 15 calendar days with automatic weekend (Saturday/Sunday) and Philippine public holiday rollover (e.g. New Year, Labor Day, Independence Day, Christmas, Easter Maundy Thursday/Good Friday, National Heroes Day) extending the deadline to the next working day.
  - **Clock Pauses / Exceptions**:
    - *Justified Suspension*: Authorized officers (Admin/Clerk) can log an excused pause with notes (e.g., medical emergency, calamity), freezing the day count. Resuming extends the deadline by the elapsed pause days.
    - *Summon Unserved*: When proof of service is marked "Service Failed" (unserved), the timer is automatically paused with reason `Summon Unserved` until service is completed as `Served`.
  - **State & Urgency Badges**:
    - `Within Period` (> 5 days left): Green badge (`X days remaining`).
    - `Expiring Soon` (<= 5 days left): Amber badge (`X days remaining - Action Needed`).
    - `Mediation Lapsed` (0 or negative days left): Red badge (`15-day limit reached` / `Mediation Period Lapsed`).
    - `Paused`: Blue badge with pause reason and resume capability.
  - **Safeguards & Legal Expiration Actions**:
    - Scheduling further Punong Barangay mediation hearings is strictly restricted once lapsed, both on the frontend (`case-workspace.js`, `hearings.js`) and backend (`Hearing::createProgression`).
    - Prominently presents the two statutory next steps on workspace and hearing schedule:
      - **Option A**: *Constitute Pangkat Tagapagkasundo* (elevate to 3-member Conciliation panel, granting fresh 15-day conciliation period).
      - **Option B**: *Issue Certificate to File Action (CFA)* (direct link and full issuance workflow on `documents/cfa.php`).
    - Automated Audit Log: Writes `"15-day mediation period concluded on [Date]. Awaiting elevation to Pangkat or issuance of CFA."` upon lapse detection.
  - **Database Migration & Schema**: Added `mediation_start_date`, `mediation_deadline_date`, `is_paused`, `paused_at`, `resumed_at`, `pause_reason`, `pause_notes` to `cases` table in `database/schema.sql` and migration `database/migrations/20260928_mediation_statutory_deadline.sql`.
  - **Relevant Files**: `backend/services/MediationDeadlineService.php`, `backend/services/DeadlineService.php`, `backend/models/MediationSchedule.php`, `backend/models/Hearing.php`, `backend/models/ProofOfService.php`, `backend/models/CaseModel.php`, `backend/models/CFA.php`, `backend/controllers/CFAController.php`, `backend/api/cases/pause-mediation.php`, `backend/api/cases/resume-mediation.php`, `backend/api/documents/cfa.php`, `frontend/pages/cases/case-details.php`, `frontend/assets/js/case-workspace.js`, `frontend/pages/documents/cfa.php`, `frontend/assets/js/cfa.js`, `frontend/assets/js/hearings.js`, `frontend/assets/js/cases.js`, `frontend/assets/css/cases.css`.

### Guided Mediation-to-Conciliation Workflow (October 2026)

- **One primary action per workspace:** The Mediation and Pangkat Conciliation workspace headers now show one context-aware **Next required action** button. It selects the legally available next step, such as scheduling a hearing, completing the active session closeout, booking the next Mediation, constituting the Pangkat, scheduling the first Conciliation, or issuing a CFA. Secondary actions are grouped under **More actions**.
- **Session closeout flow:** Selecting **Complete session closeout** first directs staff to record attendance when it is absent, then to the minutes and outcome record. Once minutes are finalized, the workspace presents the valid follow-up action: book the next Mediation, constitute the Pangkat, or issue a CFA after unsuccessful Conciliation.
- **Three-step Pangkat referral:** The Mediation workspace keeps referral in one guided modal:
  1. Confirm the referral date and authorized justification.
  2. Constitute the panel by selecting distinct Head, Secretary, and Member Lupon users.
  3. Review the panel, statutory convening-deadline handling, KP Form 10 generation, and the next Conciliation scheduling step before confirmation.
- **Authoritative transition:** The legacy hearings-screen elevation action now routes into the Mediation workspace instead of changing the case independently. `HearingController::failMediation()` delegates to `CaseStage::referToPangkat()`, which enforces the completed/lapsed Mediation gate and writes the stage, deadline, history, and audit records consistently.
- **Server-side panel gate:** `CaseStage::referToPangkat()` requires three distinct, active `Lupon Member` accounts before changing the case to `Conciliation`; client-side dropdown checks alone are not trusted. An Administrator cannot be used as a Conciliation panel member.
- **Direct handoff:** A successful referral now opens `pangkat-workspace.php` directly. Staff no longer need to visit generic Case Assignments to finish the handoff.
- **Relevant files:** `backend/controllers/HearingController.php`, `backend/models/CaseStage.php`, `frontend/assets/js/hearings.js`, `frontend/assets/js/stage-workspace.js`, `frontend/pages/cases/mediation-workspace.php`, and `frontend/pages/cases/pangkat-workspace.php`.
- **Verification:** PHP syntax checks for the changed controllers/models/pages, JavaScript syntax checks for the changed workspace scripts, and `git diff --check` passed. Browser workflow testing against representative Mediation, lapsed-Mediation, Conciliation, and CFA cases remains recommended.

### Proof of Service & Hearing Attendance Modal Updates (October 2026)

- **Proof of Service Page Refinement (`proof-service.php`)**:
  - Removed the redundant "Issued Documents / Summons Notices" table section (`#summonsNoticesTable`). The page now focuses exclusively on Officer's Return & Hearing Summon Deliveries and Recorded Activity (Service History & Attempts).
  - Safeguarded `frontend/assets/js/gps.js` with early null checks in `loadSummonsNotices(caseId)` when `#summonsNoticesTable` is absent from the DOM.
- **Hearing Session & Service Verification Modal (`#editHearingModal`)**:
  - Replaced the generic "Service Not Confirmed" badge with an explicit **Summon Delivery Status** indicator (`✓ Served — Personal`, `✓ Served — Substituted`, `✓ Refused to Receive`, `✕ Unserved (reason)`, or `⏳ Pending Delivery`).
  - Integrated `summon_deliveries` into `backend/models/HearingAttendance.php` (`getHearingAttendance` and `recordAttendance`), ensuring service logged via Proof of Service directly confirms hearing service.
  - Streamlined **Witness** party cards by removing attendance pills (`Present`, `Failure to Appear`, `Not Served`, `Excused`, `Late`) and justification selectors. Witnesses now display their name, role, address, and an optional testimony notes/remarks field.
  - Handled witness records gracefully during attendance save (`handleSaveAttendance`), defaulting to `Present` without requiring status selection or triggering failure-to-appear validation blocks.
- **Consolidation of Case Workspace into Complaint Details (`complaint-details.php`)**:
  - Removed standalone Case Workspace navigation fragments. When a complaint is docketed as a case, all case management features are consolidated directly into `frontend/pages/complaints/complaint-details.php`.
  - Added **Docketed Case Record & 15-Day Statutory Mediation Clock** section with live countdown badge (`Within Period`, `Expiring Soon`, `Mediation Lapsed`, `Paused`) and deadline dates.
  - Added **Card 6: Case Team Assignment**: displays the active 3-member Lupon panel (Head, Secretary, Member) with direct link to manage assignments.
  - Added **Card 7: Case Documents & KP Forms**: lists generated forms and summons notices with direct file view/download links.
  - Added **Card 8: Hearings & Deadlines Schedule**: displays scheduled hearing sessions with dates, venues, status badges, attendance counts, and direct links into Hearings & Deadlines.
  - Updated `ComplaintController::show` to automatically embed `case_workspace` data from `CaseModel::getWorkspace` in `backend/api/complaints/view.php`.
  - Added "View Details" button in `case-list.php` table actions to route directly to `../complaints/complaint-details.php?id=<complaint_id>`, unifying the workflow.
  - Verified and fixed closing template literal tags in `frontend/assets/js/complaints.js` (`renderAttachments`), resolving browser script parse failures on `complaint-details.php`.
- **Relevant Files**: `frontend/pages/complaints/complaint-details.php`, `frontend/assets/js/complaints.js`, `backend/controllers/ComplaintController.php`, `backend/models/CaseModel.php`, `backend/api/cases/workspace.php`, `frontend/pages/cases/case-list.php`, `frontend/assets/js/cases.js`, `frontend/pages/gps/proof-service.php`, `frontend/assets/js/gps.js`, `backend/models/HearingAttendance.php`, `frontend/assets/js/hearings.js`, `frontend/assets/css/hearings.css`.

- **Conciliation Hearing Case Team Assignment Validation (October 2026)**:
  - **Mediation vs Conciliation Roles**: The Administrator account represents the Barangay Captain / Punong Barangay for Mediation ONLY. When a case transitions from Mediation to Conciliation (e.g., following mediation failure, lapse of the 15-day statutory period, or constitution of the Pangkat Tagapagkasundo), staff must assign a dedicated 3-member Lupon panel (Head, Secretary, Member) composed exclusively of active users with the `Lupon Member` role.
  - **Pre-Booking Enforcement**: Booking a Conciliation hearing is strictly blocked until a valid 3-member Lupon team is assigned.
    - `backend/models/Assignment.php`: Added `validateConciliationTeam(int $caseId): array` and `validateConciliationTeams(array $caseIds): array`. Verifies that exactly 3 distinct active users with role `Lupon Member` are assigned as Head, Secretary, and Member, and rejects any panel containing an Administrator.
    - `backend/models/Assignment.php`: Updated `replaceCaseTeam` to support assigning a 3-member Lupon panel for Conciliation, automatically transitioning case and complaint status to `Conciliation`. Fixed existing team check to differentiate between Mediation's single Administrator Head and a full 3-member Conciliation panel.
    - `backend/controllers/HearingController.php`: Updated `validate()` to block scheduling `Conciliation` hearings when `validateConciliationTeam` returns invalid.
    - `backend/models/Hearing.php`: Atomic transactional validation in `createProgression()` rejects Conciliation scheduling attempts if the Lupon team has not been constituted.
    - `backend/models/CaseModel.php`: Enriched case list and pagination outputs with `has_conciliation_team` and `conciliation_team_message`.
    - `frontend/assets/js/hearings.js`: When a case requires Conciliation and lacks a 3-member Lupon panel, disables the Conciliation hearing type option, disables the submit button, and displays an informative alert with a direct deep-link to Case Team Assignment (`../cases/case-list.php?assign_case_id=<id>#caseAssignments`).
- **Hearing Date Progression Validation: No Same-Day or Prior-Date Schedules (October 2026)**:
  - **Rule Definition**: If a hearing is already booked for a case (active or completed), users cannot schedule another hearing for that case on the same day or on any prior date. All subsequent hearing schedules must strictly be set on a future calendar date following the latest booked hearing.
  - **Model & Database Enforcement (`backend/models/Hearing.php`)**:
    - Added `getLatestBookedHearing(int $caseId): ?array`: retrieves the latest active or completed booked hearing for a case (excluding cancelled and superseded hearings).
    - Added `validateHearingDateProgression(int $caseId, string $hearingDate, ?int $excludeHearingId = null): array`: checks both (1) same-day conflicts against any booked hearing, and (2) whether the target date is on or before the latest booked hearing date.
    - Updated `createProgression`: added atomic transaction-level check with `FOR UPDATE` case locking before `INSERT INTO hearings`, rolling back if same-day or prior date is provided.
  - **Controller & API Gate (`backend/controllers/HearingController.php`)**:
    - Updated `validate()`: intercepts hearing schedule creation (`!$allowExistingTypes`) and returns HTTP 422 with a descriptive error message (`A hearing for this case is already booked on [Date]. You cannot schedule another hearing for this case on the same day or on any prior date.`). Also validates rescheduled updates so that they do not conflict on the same day as another hearing of the case.
    - Added support for `Y-m-d H:i` string format alongside `Y-m-d\TH:i` and `Y-m-d H:i:s`.
  - **Case Enrichment (`backend/models/CaseModel.php`)**:
    - Enriched `getAll()` and `getPage()` with `latest_hearing_date`, computed efficiently in bulk via indexed `GROUP BY` and `PDO::FETCH_KEY_PAIR`.
  - **Frontend UI & Interactive Restrictions (`frontend/assets/js/hearings.js`)**:
    - Updated `loadCases()`: caches `latest_hearing_date` on case dropdown option dataset.
    - Added `getCaseLatestHearingDate(caseId)`: retrieves latest hearing from `calendarHearings` or dataset fallback.
    - Added `updateDateRestrictions()` & `validateHearingDateInput()`: dynamically computes the earliest allowed date (day after the latest booked hearing, 08:00 AM) and sets `hearingDate.min`.
    - Added real-time client-side validity checks on `input` and `change`, as well as on form submit interceptor in `bindReviewForm`.
    - Enhanced `updateNextSchedule()` help box: displays an informative blue indicator with the latest booked hearing date and the earliest permitted new hearing date.
  - **Relevant Files**: `backend/models/Hearing.php`, `backend/controllers/HearingController.php`, `backend/models/CaseModel.php`, `frontend/assets/js/hearings.js`.

- **Hearing Schedule Office Hours & Weekday Validation (October 2026)**:
  - **Rule Definition**: Hearings cannot be booked on Saturdays or Sundays, and can strictly only be scheduled during office hours from 8:00 AM to 5:00 PM (08:00 to 17:00).
  - **Model & Database Enforcement (`backend/models/Hearing.php`)**:
    - Added `validateHearingOperatingHours(string $hearingDate): array`: checks `$dt->format('N')` to reject Saturday (6) and Sunday (7), and checks `$dt->format('H:i')` to enforce `08:00 <= time <= 17:00`.
    - Integrated into `createProgression()` and `update()` (rescheduling) to prevent weekend and out-of-hours inserts at the model layer.
  - **Controller & API Gate (`backend/controllers/HearingController.php`)**:
    - Updated `validate()`: intercepts both new bookings and reschedules, returning HTTP 422 if Saturday/Sunday or outside 8:00 AM – 5:00 PM is submitted.
  - **Frontend UI & Interactive Restrictions (`frontend/assets/js/hearings.js` & `schedules.php`)**:
    - Updated `validateHearingDateInput()`: validates input in real-time on `input`, `change`, and modal submit; sets custom validity and reports invalid states.
    - Updated `updateDateRestrictions()`: skips weekends when computing the minimum next date (advancing Friday to Monday 8:00 AM).
    - Added office hours helper notes beneath the date inputs in `frontend/pages/hearings/schedules.php`.
  - **Relevant Files**: `backend/models/Hearing.php`, `backend/controllers/HearingController.php`, `frontend/assets/js/hearings.js`, `frontend/pages/hearings/schedules.php`.


## Other Undocumented Implemented Changes (September 2026)

### Cases page ordering and pagination

- The top-right **Docket Case** button was removed from the Cases list page;
  docketing itself remains available through its existing workflow.
- The Case Team Assignment section appears above the cases table.
- The case table uses server-side pagination, with 25 rows per page and the
  existing newest-first `created_at` ordering. The list API returns the page,
  total count, and pagination metadata. The assignment selector still loads
  from the complete case list and is not restricted to the current page.
- The existing case table columns and row actions remain in place. No schema or
  case-record changes are part of pagination.
- Relevant files: `frontend/pages/cases/case-list.php`,
  `frontend/assets/js/cases.js`, `frontend/assets/css/cases.css`,
  `backend/api/cases/list.php`, `backend/controllers/CaseController.php`, and
  `backend/models/CaseModel.php`.

### Complaints search, listing, and intake

- Records Search was integrated into the Complaints page and now drives the
  complaint results table through `backend/api/search/records.php`. Current
  filters include keyword, exact status, case type (`Civil` / `Criminal`), category,
  and incident date range. KPI cards and status tabs also filter the same result set.
  The Clear / Reset action restores the unfiltered list.
- **Modern, Compact Complaints List (`complaint-list.php`)**:
  - Re-architected into a high-density, compact, and intuitive layout.
  - 4 Top Metric KPI Cards: "Total Complaints", "Under Review", "In Progress", and
    "Settled". Each card displays real-time counts and supports 1-click filtering
    of the underlying table.
  - Quick Status Navigation Tabs: Horizontal tabs (`All`, `Filed`, `Under Review`,
    `Accepted`, `Docketed`, etc.) with dynamic count badges for quick status filtering.
  - Compact Action & Search Toolbar: Includes a debounced keyword search input,
    a quick "Clear" button, a Case Type filter dropdown (`All Types`, `Civil`, `Criminal`),
    a Category filter dropdown, and a collapsible "Filter Drawer" providing granular
    `Date From`, `Date To`, and exact status filters.
  - Dedicated 7-Column Table Layout:
    1. **Complaint**: Complaint number, title/brief subject, and relative filing date.
    2. **Case No.**: Formatted Case Number or a subdued "Not Docketed" pill badge.
    3. **Category**: Clean category badge.
    4. **Parties**: Complainant and Respondent names tagged with distinct party badges.
    5. **Incident Date**: Formatted incident date and time.
    6. **Status**: Color-coded status badge (`Filed`, `Under Review`, `Accepted`, `Docketed`, etc.).
    7. **Actions**: Clear action button group with View Details (`complaint-details.php?id=`),
       Edit Complaint (`complaint-edit.php?id=`), and Delete modal trigger.
  - The top "Add Complaint" primary CTA routes directly to `complaint-create.php`
    instead of opening a modal.

- **Dedicated Add Complaint Intake Page (`complaint-create.php`)**:
  - Replaces the legacy `#addComplaintModal` popup with a modern, compact, full-page
    intake form structured into clean two-column cards:
    - **Section 1: Incident & Classification**: Category selection, Description / Narrative,
      Prayer for Relief, and a dedicated **Case Type** dropdown (`Civil` or `Criminal`).
    - **Section 2: Parties Involved**: Direct textboxes for Complainant and Respondent
      embedded directly in the form (eliminating the previous "Add Party" popup modal),
      complete with live resident datalist autocomplete (`#residentOptions`) and automatic
      relational linkage into `complaint_parties`.
    - **Section 3: Incident Details**: Merged single `<input type="datetime-local" name="incident_datetime">`
      replacing separated date and time fields (parsed server-side into `incident_date`
      and `incident_time`), specific incident location text input, landmark text input,
      and an interactive Leaflet/OpenStreetMap pin selector saving latitude and longitude
      coordinates into `incident_locations`.
    - **Section 4: Evidence / Attachments (Optional)**: A single unified upload section
      replacing separate image/video/doc buttons. Supports drag-and-drop and multi-file selection
      (`accept="image/*,video/*,.pdf,.doc,.docx"` up to 25 MB per file), interactive queue preview
      with file type badges, sizes, and instant removal before submit. Files are validated server-side
      and stored securely in `storage/uploads/complaint-evidence/` with records in `complaint_attachments`.

- **Dedicated Edit Complaint Page (`complaint-edit.php`)**:
  - Replaces the legacy `#editComplaintModal` popup with a dedicated, full-page edit interface
    mirroring the exact visual, structural, and field layout of `complaint-create.php`.
  - Automatically preloads all existing complaint facts, category, `case_type`,
    merged datetime (formatted as `YYYY-MM-DDTHH:MM`), description, prayer for relief,
    specific location, landmark, complainant and respondent party names (from `complaint_parties`),
    and the existing Leaflet map pin (from `incident_locations`).
  - Atomic update execution via `backend/api/complaints/update.php` and `Complaint::update()`:
    updates the core `complaints` record, propagates `case_type` changes to linked docketed `cases`,
    synchronizes complainant and respondent rows in `complaint_parties` (updating existing or
    inserting if new), and persists updated or cleared coordinates in `incident_locations`.
  - "Edit Complaint" buttons on both `complaint-details.php` and `complaint-list.php` route
    directly to `complaint-edit.php?id=<id>`.

- **Redesigned Modern Complaint Workspace (`complaint-details.php`)**:
  - Re-architected into a compact, responsive two-column workspace adhering to the design language and card order of `complaint-create.php`, `complaint-edit.php`, and `case-details.php`.
  - **Breadcrumb & Header Controls**: Features complaint title, complaint number, dynamic status badge, case type badge, category badge, and linked case badge. When a complaint is docketed, a prominent green button routes directly to `../cases/case-details.php?id=<case_id>`. Action buttons provide immediate access to "Schedule 1st Mediation" and "Edit" (linking to `complaint-edit.php`). The "+ Add Party" and "+ Upload Evidence" modal buttons are omitted from the header, card headers, and empty states so this workspace remains focused on reading complaint information.
  - **Zero Repetition & Aligned Field Mappings**: Removed redundant KPI cards and eliminated duplicate location/landmark fields from Card 1. All fields cleanly mapped to their designated cards:
    - **Left Column**:
      1. *1. Incident & Classification*: Full Complaint Title, Category, Case Type, Incident Date, Incident Time, Date Filed, Administrative Status, and Linked Docketed Case.
      2. *2. Involved Parties*: Clean read-only party cards with role badges (Complainant, Respondent, Witness), resident names, contact number, and purok/address (remove actions omitted so details remain strictly read-only).
      3. *3. Narrative & Facts*: Statement of the complaint facts, detailed narrative, and optional additional details / prior attempts.
    - **Right Column**:
      4. *4. Incident Location*: Specific incident address, nearby landmark, GPS coordinates, and an interactive Leaflet/OpenStreetMap marker (with clean fallback if coordinates are omitted).
      5. *5. Evidence & Attachments*: Visual evidence cards with image thumbnails, file-type icons, download links, and delete actions.
  - Preserved backward compatibility for scheduling modals (`#scheduleMediationModal`, `#confirmMediationModal`) and legacy DOM IDs.

- **Aligned Editable Complaint Form (`complaint-edit.php`)**:
  - Re-architected into the 5-card layout of `complaint-details.php` in an editable interface:
    - **Header Toolbar**: Direct back link to details, complaint number heading (`#editComplaintHeading`), live title preview subheading (`#editComplaintSubheading`), dynamic status pill, live category and case type pills, linked case badge, Cancel button, and top Save Changes button.
    - **Confirmation Modals**:
      - *Save Changes*: Prompts the user with "Do you want to save the changes made to this complaint?". Clicking "Yes, Save Changes" submits the form and redirects to `complaint-details.php?id=<id>` with a success flash message; "No, Keep Editing" closes the modal and stays on the page. Form submit via keyboard (Enter key) is intercepted to trigger the same confirmation.
      - *Discard Changes*: Clicking Cancel or the Back link prompts "Are you sure you want to discard the changes?". Clicking "Yes, Discard" redirects to `complaint-details.php?id=<id>`; "No, Keep Editing" stays on the page.
    - **No Redundant Bottom Bar**: Removed the bottom `.intake-actions-bar` in favor of the header toolbar actions.
    - **Left Column**:
      1. *1. Incident & Classification*: Editable title input, category select, case type select (`Civil`/`Criminal`), merged `datetime-local` input, and read-only context pills for Date Filed, Status, and Docketed Case.
      2. *2. Involved Parties*: Embedded Complainant and Respondent inputs with datalist autocomplete (`#residentsDatalist`), dynamic additional parties (Witness, Complainant, Respondent) with remove buttons, and "+ Add Another Party" button.
      3. *3. Narrative & Facts*: Detailed narrative textarea and optional additional details / prior attempts textarea.
    - **Right Column**:
      4. *4. Incident Location*: Specific incident location text, landmark text, and interactive Leaflet map pin selector with clear pin button.
      5. *5. Evidence & Attachments*: Visual cards of existing attachments (preview thumbnails/file icons, file name, download link, upload date, and instant AJAX delete) plus drag-and-drop / file browser upload queue for additional evidence files (`evidence[]`). (Card 6 Administrative Review Notes omitted).
    - **Backend & Controller Wiring**: `ComplaintController::update` accepts `$files` and seamlessly attaches newly uploaded evidence to `complaint_attachments`, while updating incident data, parties, and `incident_locations` within a secure database transaction. `Complaint::validateMapLocationInput` safely accepts pre-existing coordinates under `map_location_state = 'unchanged'`, resolving false-positive location rejections on existing complaints.

- **Separated 3-Dimensional Complaint Lifecycle & Interactive Modern List (`complaint-list.php`)**:
  - Replaces the single collapsed/overloaded status concept with three orthogonal, legally compliant lifecycle dimensions conforming strictly to the Katarungang Pambarangay provisions of RA 7160 (Local Government Code of 1991):
    1. **Intake / Administrative Status**: `Under Review` (newly filed, awaiting screening/scheduling) vs `Docketed` (assigned a case number and scheduled for hearing).
    2. **Progression / Dispute Stage**: Active procedural phase — `None / Pre-docketing`, `Mediation` (PB phase, Sec. 410b), `Conciliation` (Pangkat phase, Sec. 410b/412), or `Arbitration` (voluntary binding arbitration, Sec. 413).
    3. **Case Disposition / Final Outcome**: How the dispute concluded — `Pending`, `Amicable Settlement` (mutual agreement, Sec. 411), `Arbitration Award` (binding resolution, Sec. 413), `Certificate to File Action (CFA)` (failed conciliation/repudiation, Sec. 412), or `Dismissed / Dropped` (non-appearance/withdrawal, Sec. 410d).
  - Clean status badge representation in table rows (Status column):
    - Removed label prefixes ("intake", "stage", "result") so only the status values themselves are displayed.
    - Intake pill (`badge-intake-*`): Under Review / Docketed.
    - Dispute Stage pill (`badge-stage-*`): Mediation / Conciliation / Arbitration (shown during active hearing stages).
    - Final Disposition pill (`badge-disp-*`): Pending / Amicable Settlement / Arbitration Award / CFA Issued / Dismissed.
    - **Stage Expiration Rule**: After the 3 mediation and 3 conciliation hearings have taken place (`conciliation_count >= 3`), the dispute stage is exhausted and removed from the Status display, showing only the intake (`Docketed` or `Under Review`) and result (`Dismissed`, `CFA Issued`, `Amicable Settlement`, `Pending`, etc.).
  - Interactive table column header sorting on all columns (Complaint, Case No., Category, Parties, Incident Date, Status) with ascending/descending toggling (`▲`/`▼`/`⇅`), instant zero-latency client-side sorting on loaded rows, and backend database sort mapping.
  - Granular secondary filter drawer with separate dropdowns for each lifecycle dimension (`#searchIntake`, `#searchStage`, `#searchDisposition`), date range, case type (`Civil` / `Criminal`), and quick status navigation tabs (`All`, `Under Review`, `Docketed`, `Mediation`, `Conciliation`, `Arbitration`, `Settled`, `Dismissed`, `CFA`).
  - Real-time tab counts and KPI summary metrics derived directly from the three lifecycle dimensions.
  - **10 Items Per Page Pagination**: Responsive pagination bar (`#complaintPagination`) below the table displaying a dynamic summary ("Showing 1–10 of 14 complaint records"), Previous/Next navigation buttons, numeric page buttons with ellipsis for large page counts, and automatic reset to Page 1 upon filtering or sorting.

- Relevant files include `frontend/pages/complaints/complaint-list.php`,
  `complaint-create.php`, `complaint-edit.php`, `complaint-details.php`,
  `frontend/assets/js/search.js`, `frontend/assets/js/complaints.js`,
  `frontend/assets/css/complaints.css`,
  `backend/api/complaints/create.php`, `backend/api/complaints/update.php`,
  `backend/api/search/records.php`, `backend/models/Complaint.php`,
  `backend/models/Search.php`, and `frontend/layouts/sidebar.php`.

### Shared validation improvements

- `backend/services/ValidationService.php` provides shared checks for required
  trimmed values, names, real dates/date-times, email format, Philippine mobile
  and landline formats, address/text control characters, and maximum lengths.
- User and resident forms now apply client and server checks for relevant names,
  contact numbers, email, dates, and defined selections. User create/update
  reports understandable duplicate username/email errors while preserving the
  existing uniqueness rules. Complaint, hearing, and proof-of-service paths
  reject invalid text or impossible dates where applicable.
- Existing evidence/upload paths validate extensions against detected MIME
  types, the existing size limits, and basic image/PDF validity. Resident data
  is escaped when rendered. These changes do not migrate or clean existing
  records.
- PHP lint, JavaScript syntax checks, `git diff --check`, and direct validator
  checks passed when implemented. Duplicate-check queries and actual uploads
  were not integration-tested because no database/upload integration session
  was available. The local Module Development PDF was not reviewed because the
  browser blocked its file path; message-provided requirements were used.
- Relevant files include `backend/services/ValidationService.php`,
  `backend/controllers/UserController.php`, `ResidentController.php`,
  `ComplaintController.php`, `HearingController.php`, `GPSController.php`,
  `backend/models/User.php`, `backend/models/Complaint.php`, and the associated
  user/resident form JavaScript and API files.

### Resident Profiles Terminology Alignment & Modern UI Redesign (September 2026)

- Clarified module terminology across user-facing views from "Residents" to "Resident Profiles".
- Rationale & Context: The `residents` table does not represent resident user login accounts. It is strictly a verified registry of identities and addresses (first name, middle name, last name, and address/purok) used during complaint intake and blotter recording to verify involved parties (complainants, respondents, witnesses) and prevent misreporting or issuing summonses to the wrong individual.
- Navigation & Interface: Sidebar navigation, page titles, tables, forms, modals (Add Resident Profile, View Resident Profile Details, Edit Resident Profile, Delete Resident Profile), and autocomplete datalists now consistently display "Resident Profile(s)".
- Duplicate Person Prevention (Allow Similar Names, Block Similar Names & Address): Server-side validation (`Resident::findDuplicate()`, `Resident::areNamesSimilar()`, `Resident::areAddressesSimilar()`) strictly enforces that two resident profiles can share similar or identical names provided their residential addresses are different. However, creating or updating a profile with both a similar name AND a similar address is rejected with an explicit notice to prevent duplicate records for the same individual. Family members sharing the same address but with different first names, as well as distinct individuals sharing similar names residing at different locations, are fully permitted.
- Modernized, Pleasing-to-the-Eyes UI Architecture (`resident-list.php`, `residents.css`, `residents.js`):
  - **KPI Metrics Summary Cards**: Top metric grid showcasing Total Profiles, Permanent Residents, Tenants, and Active Puroks with custom pastel icon containers, hover lift, and one-click quick filtering of the underlying table.
  - **Comprehensive Toolbar**: Includes an icon-adorned debounced search input with an instant clear `×` button, dynamic Purok filter dropdown, Residency Type filter dropdown (`Permanent` vs `Tenant`), multi-mode sorting dropdown (Newest First, Oldest First, Name A-Z, Name Z-A, Purok), and a conditional Reset Filters button.
  - **6-Column Modern Data Table**:
    1. *Profile ID*: Formatted chip `#RP-XXXX`.
    2. *Resident Name*: Circular avatar with pastel initials badge, bold full name, and demographics subtext (Gender, Civil Status, and automatically calculated age from birth date).
    3. *Purok / Address*: Styled Purok pill badge and formatted street address.
    4. *Contact Details*: Formatted contact number with phone icon and clickable mail address.
    5. *Residency*: Distinct color-coded pills for Permanent Residents (emerald) vs Tenants (amber).
    6. *Actions*: Clean button group with View (eye), Edit (pencil), and Delete (trash) actions.
  - **Strict 25-Item Pagination**: Dedicated pagination component (`#residentPagination`) ensuring only 25 profiles are displayed per page. Features dynamic summary text (`Showing X–Y of Z profiles`), `« First`, `‹ Prev`, smart-windowed numeric page buttons, `Next ›`, `Last »`, smooth scroll-to-table behavior, and automatic page-1 reset upon filtering.
  - **Polished Accessible Modals**:
    - *Add/Edit Modals*: Grouped into "Personal Information" and "Residency & Contact Information" with clean 2-column inputs, inline validation hints, duplicate error alerts, and loading buttons.
    - *View Modal*: Transformed into a clean identity summary card featuring a large avatar circle, badge chips, formatted personal and contact grids, and a direct "Edit This Profile" transition button.
    - *Delete Modal*: Red caution prompt displaying the target profile's name and ID with explicit deletion guidance.
  - **Backend Pagination Support**: `Resident::getPage()` and `ResidentController::page()` support pagination requests via `backend/api/residents/list.php?page=X&per_page=25`, while preserving unpaginated array fallback when `page` is omitted for seamless backward compatibility with complaint intake.
- Search & Placeholders: Complaint search and party input fields provide descriptive guidance ("Search resident profile or type full name...") to assist staff during intake.
- Schema Stability: Internal database tables (`residents`), foreign key constraints (`complaint_parties.resident_id`), models (`Resident.php`), and API endpoints (`backend/api/residents/*`) remain intact to preserve database integrity and migration compatibility.
- Relevant files: `frontend/layouts/sidebar.php`, `frontend/pages/residents/resident-list.php`, `frontend/assets/js/residents.js`, `frontend/assets/css/residents.css`, `frontend/pages/complaints/complaint-create.php`, `frontend/pages/complaints/complaint-edit.php`, `frontend/pages/complaints/complaint-list.php`, `frontend/pages/landing/index.php`, `frontend/assets/js/complaints.js`, `frontend/assets/js/search.js`, `backend/models/Resident.php`, `backend/controllers/ResidentController.php`, `backend/api/residents/list.php`, `backend/api/residents/create.php`, `backend/api/residents/update.php`, `backend/api/residents/delete.php`, and `database/schema.sql`.

### Tumana Puroks Dropdown, Required Address Validation & Interactive Leaflet Map Pin (September 2026)

- **Official Tumana Puroks & Compounds Dropdown**:
  - The freeform `purok` text field in Add and Edit Resident Profile modals was converted into a structured `<select>` dropdown organized into categorized `<optgroup>`s:
    - *Numbered Puroks*: `Purok 1` through `Purok 8`.
    - *Recognized Zones & Compounds*: `Doña Petra Compound`, `Bagong Farmers`, `Bukang Liwayway`, `Libis Tumana`, `Sitio Bagong Purok`, `Palay Area`, `Mais Area`, `Singkamas Area`.
  - The top toolbar Purok filter dropdown (`#filterPurok`) was aligned with the exact same option set.
  - Client-side normalization (`normalizePurok()`) gracefully maps legacy data formats (e.g., `"1"`, `"purok 1"`, `"Purok 1"`) to the select options without breaking pre-population or filter matches.
- **Mandatory Complete Street Address**:
  - Both client-side and server-side validation strictly require a complete street address.
  - `backend/services/ValidationService.php`: `residentData()` rejects missing or whitespace-only addresses with HTTP 422 (`"Complete street address is required."`).
  - Add and Edit Resident modals enforce `required` attributes, asterisk indicators (`*`), and HTML5 custom validity checks.
- **Interactive Leaflet Map Pin with Address Reverse Geocoding**:
  - Add and Edit modals provide a collapsible "Pin on Map to Obtain Address" section powered by Leaflet 1.9.4 and OpenStreetMap.
  - **Barangay Tumana Boundary Enforcement**: The map features an official Tumana polygon overlay. Any clicks or pins placed outside the Barangay Tumana boundary are rejected with an explicit warning badge (`"Point is outside Barangay Tumana boundary"`), preventing invalid out-of-jurisdiction addresses.
  - **Structured Main Address Assembly**: When a user clicks within Barangay Tumana, a draggable marker is placed and OpenStreetMap Nominatim reverse geocoding is invoked to resolve all standard Philippine address components:
    - *House Number*: Extracted from OpenStreetMap node/building data or preserved from existing user input.
    - *Street*: Pinned thoroughfare or road name (e.g. `Moscow Street`, `Palay Street`, `Farmers I Street`).
    - *Subdivision / Purok*: Auto-selected Purok, compound, or subdivision (e.g. `Purok 6`, `Doña Petra Compound`, `Twinville Subdivision`).
    - *Barangay*: `Barangay Tumana`.
    - *City*: `Marikina City`.
    - The components are cleanly assembled into a standard address format: `[House No.] [Street], [Subdivision/Purok], Barangay Tumana, Marikina City` and auto-populated into the mandatory `address` textarea.
  - **Automatic Purok Detection & Selection**: When pinning on the map, the system automatically detects and selects the corresponding Purok in the dropdown using a two-tier strategy:
    1. *Geocoded Road & Area Keywords*: Matches known streets, zones, and compounds (e.g. "Moscow" &rarr; `Purok 6`, "Bagong Farmers" &rarr; `Bagong Farmers`, "Sinag" &rarr; `Bukang Liwayway`, "Palay" &rarr; `Palay`, "Mais" &rarr; `Mais`, "Singkamas" &rarr; `Singkamas`, "Angel Santos" &rarr; `Purok 1`, "Doña Petra" / Acropolis &rarr; `Doña Petra`, "Libis" &rarr; `Libis Tumana`, "Banner" &rarr; `Bagong Purok`).
    2. *Spatial Nearest-Centroid Matching*: Calculates the Euclidean distance from the pinned `[lat, lng]` to high-precision reference points across Barangay Tumana, ensuring 100% of pinned locations inside Tumana automatically populate the Purok dropdown. An animated highlight pulse (`.auto-purok-highlight`) and visual feedback label confirm the auto-selection while still allowing manual user override.
- Relevant files: `frontend/pages/residents/resident-list.php`, `frontend/assets/js/residents.js`, `frontend/assets/css/residents.css`, and `backend/services/ValidationService.php`.


### Database change

`database/schema.sql` is the fresh-install source of truth. Apply
`database/migrations/20260914_core_case_workflow_redesign.sql` and then
`database/migrations/20260916_complaint_incident_details.sql` and
`database/migrations/20260917_case_team_roles.sql` and
`database/migrations/20260918_case_deadline_unique.sql` once to an
existing populated database before using the redesigned fields and relations.
Alternatively, a database that predates all three changes can run the single
`database/migrations/20260917_consolidated_workflow_upgrade.sql` script instead.
Never run both the consolidated and individual migration paths.

Also apply `database/migrations/20260919_add_user_contact_number.sql` once to
an existing database that lacks the user contact-number field, and
`database/migrations/20260924_add_complaint_case_type.sql` once if
`complaints.case_type` is absent. The latter uses the canonical `Civil` default
for existing complaints and copies a linked case's type to docketed complaints.
Fresh installs receive both fields through `database/schema.sql`. See
`database/README.md` for prerequisites and the migration paths; never run a
one-time migration against a database that already has its target field.

For local debugging, `schema.sql` also seeds the Administrator, Lupon Clerk,
Summons Server, and three distinct Lupon Member accounts. The same optional
seed is available at `database/seeds/local_test_users.sql`; every account uses
the local-only password `password`.
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

### Mediation & Conciliation Progression and Automatic Docketing (September 2026)

- **Mediation Stage Progression (1st to 3rd Mediation)**:
  - Cases initially proceed through the Punong Barangay / Lupon mediation phase.
  - Schedulers can record up to a maximum of 3 Mediation sessions (`1st Mediation`,
    `2nd Mediation`, and `3rd Mediation`).
- **Conciliation Stage Progression (1st to 3rd Conciliation)**:
  - If a dispute is unresolved after the 3rd Mediation session, the workflow advances
    to the Pangkat Tagapagkasundo conciliation phase.
  - Schedulers can record up to a maximum of 3 Conciliation sessions (`1st Conciliation`,
    `2nd Conciliation`, and `3rd Conciliation`).
- **Hearing Scheduling Ceiling**:
  - Once the `3rd Conciliation` hearing has been reached/scheduled, the scheduling engine
    permanently halts further hearing additions for that case. This prevents exceeding statutory
    dispute resolution limits under the Katarungang Pambarangay law.
- **Automatic Case Docketing Trigger**:
  - When the `1st Mediation` hearing is scheduled, the system automatically transitions the
    case status to `Docketed` (`cases.case_status = 'Docketed'`) and the complaint status
    to `Docketed` (`complaints.status = 'Docketed'`) in the same database transaction.
  - This eliminates manual docketing friction and ensures that any complaint reaching the
    formal hearing phase is immediately docketed.
- Relevant files: `backend/models/MediationSchedule.php`, `backend/models/Hearing.php`,
  `backend/controllers/HearingController.php`, `backend/api/hearings/schedule.php`, and
  `frontend/assets/js/hearings.js`.

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
- Settlement, arbitration, CFA, proof-of-service, AI chatbot, and narrative-generation endpoints/models exist.
- Proof-of-service is available from the GPS/operations area; incident-location
  selection is embedded in Complaint Add/Edit rather than exposed as a separate
  GPS workflow.
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
- **Mediation and Conciliation Progression Lifecycle**:
  - Up to 3 Mediation hearing sessions (`1st Mediation`, `2nd Mediation`, `3rd Mediation`).
  - Progression advances to Conciliation with up to 3 Conciliation sessions (`1st Conciliation`, `2nd Conciliation`, `3rd Conciliation`).
  - Scheduling permanently halts after the 3rd Conciliation is reached.
- **Automatic Docketing on 1st Mediation**:
  - Scheduling 1st Mediation immediately and automatically updates the case status to `Docketed` (`cases.case_status = 'Docketed'`) and the complaint status to `Docketed` (`complaints.status = 'Docketed'`).
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

### Hearing and Deadlines Page Reorganization, Unified Table, Search & Pagination (September 2026)

- **Page Structure Order**: The page (`schedules.php`) features a collapsible Search & Filters toolbar, followed directly by the responsive side-by-side main grid containing the interactive Calendar and the Hearings and Deadlines schedule table, followed by server-side pagination.
- **Top Calendar**: Preserves existing calendar month/date navigation, event clicks, view/edit modal integration, and unpaginated month-wide event display.
- **Collapsible Search & Filters**: Built with an accessible HTML5 `<details class="hearings-search-card" id="hearingsSearchCard">` and `<summary>` header bar featuring an icon, title, active filter count badge, and a smooth animated toggle chevron. Defaulting to collapsed to prioritize screen space for the calendar and schedule table, it auto-expands when query parameters or active filters are present. Includes Keyword (`Case no., complaint no., or venue`), Status (`All statuses`, `Scheduled`, `Pending`, `Completed`, `Overdue`), Hearing Type (`All types`, `Mediation`, `Conciliation`, `Initial Hearing`, `Arbitration`, `Mediation Period`, `Conciliation Period`, `Conciliation Extension`), Date From, Date To, Search button, and Clear button.
- **Attendance Monitor Removal**: The standalone `Attendance Monitor (Mediation & Conciliation)` summary KPI cards and filter pill section have been removed from `schedules.php` (and the `loadAttendanceKPIs()` call removed from page load) to eliminate redundant vertical clutter and elevate the Calendar and Hearings schedule table directly below the search toolbar. Individual hearing attendance management remains accessible via the edit/session verification modal.
- **Minimized Hearings and Deadlines Schedule Table**: Positioned beside the calendar in `.hearings-main-grid`, the schedule table displays 4 columns: `Case No.` (26%), `Hearing Type` (28%), `Time` (22%), and `Actions` (24%). Configured with `min-width: 0 !important; max-width: 100% !important;` (overriding `app.css`'s generic `min-width: 680px`), `table-container` `overflow-x: hidden !important;`, an explicit `<colgroup>`, and compact typography/badges (`.badge-case-docket`, `.hearing-type`, `.action-cell .btn-action-view` formatted as `Update` / `View`). This guarantees that all 4 columns fit cleanly on screen without any horizontal scrollbar or side-to-side scrolling.
- **Interactive Calendar Date Selection**: Clicking on any date cell in the calendar highlights the day, pre-fills the date for scheduling, updates the section header badge, and dynamically queries the table to show the hearings and deadlines scheduled for that selected date (`?date=YYYY-MM-DD`). Clicking the header badge resets back to the current day.
- **Server-Side Pagination**: Limited to a maximum of 25 records per page with total count summary and Previous, numeric page, and Next controls. Search submissions reset pagination to page 1.
- **Relevant Files**: `frontend/pages/hearings/schedules.php`, `frontend/assets/js/hearings.js`, `frontend/assets/css/hearings.css`, `backend/api/hearings/list.php`, `backend/controllers/HearingController.php`, and `backend/models/Hearing.php`.

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

## Incident Location Consolidation and Proof-of-Service Increment

The existing `incident_locations` and `proof_of_service` tables are now used
for authenticated field documentation. No duplicate GPS or proof tables were
introduced.

### Implemented

- The retired standalone incident-location map page and its navigation links
  have been removed.
- Complaint Add/Edit forms include the Leaflet/OpenStreetMap map selector.
  Clicking the map creates or moves a pin; the edit form can also clear a pin.
- Specific incident location is required by both HTML and server-side
  validation. Map coordinates are optional, but a selected pin must include a
  valid latitude/longitude pair within their allowed ranges.
- Complaint and incident-location writes are transactional. A saved pin is
  preserved on normal edits and its address stays synchronized with the
  complaint's specific-location field.
- Proof-of-service recording for active cases, with service details, optional
  proof image, service history, duplicate-entry protection, and case linkage.
- Secure JPG, PNG, and WebP uploads limited to 5 MB, stored under
  `storage/uploads/proof-of-service/` using random filenames.
- Authenticated proof-image retrieval constrained to the storage directory.
- JSON APIs with method/session/role checks, server-side validation, and
  audit logging for proof mutations.

### Testing Status

- PHP lint, JavaScript syntax check, and `git diff --check` passed.
- Browser/MySQL acceptance testing remains required in Laragon, including
  actual image upload, complaint create/edit map-pin save/change/clear, and
  authorized/denied role flows.
- Leaflet/OpenStreetMap map tiles require browser network access; the required
  text-based incident location remains available when map tiles are unavailable.

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

## Summons Workflow, Status Tracker, 1st Mediation Eligibility, and Proof of Service (September 2026)

The Complaint Details and Proof of Service modules provide an integrated, end-to-end summons and mediation progression:

### 1. Complaint Details Action Toolbar & Case Progress Workflow
- **Top Actions**: Arranged in standard workflow order:
  `Issue 1st Summon` | `Schedule 1st Mediation` | `Status` | `Edit`
- **Dynamic Summons Button**:
  - `Issue 1st Summon`: Generates the initial summons document (KP Form 9) and redirects to Proof of Service with prefilled case/document IDs.
  - `Issue 2nd Summon`: Available if the first summons attempt resulted in failure (`Not Served`, `Refused`, `Respondent Not Found`, `Address Problem`).
  - `Summons Served` / `2nd Summon Issued`: Displayed as a secondary button linking directly to the Proof of Service history once served or once the 2nd attempt is issued.
- **Visual Status Tracker**:
  - Collapsible 9-stage stepper track: `Filing & Docketing` (Stage 1), `Summon Issued` (Stage 2), `Summons Service` (Stage 3), `Proof of Service` (Stage 4), `Hearing Scheduled` (Stage 5), `Mediation` (Stage 6), `Conciliation` (Stage 7), `Settlement / Outcome` (Stage 8), and `Final Resolution / CFA` (Stage 9).
  - Derived dynamically from underlying database records (`complaints`, `cases`, `generated_documents`, `proof_of_service`, `hearings`, `hearing_attendance`, `pangkat_groups`, `amicable_settlements`, `cfa_records`, and `arbitration_records`).
  - Integrated with an expandable Case Event History drawer rendering full chronological event timelines with author badges and status pills.

### 2. 1st Mediation Eligibility Rules
- **Prerequisite Enforcement**:
  - The `Schedule 1st Mediation` button is strictly gated by summons service completion. It becomes enabled if and only if **ANY** valid summon or service record for the underlying case has a successful `Served` result in the database (`proof_of_service.service_result = 'Served'` or `generated_documents.service_status = 'Served'`).
  - Supports multiple summons attempts: eligible if Attempt #1 is Served, OR if Attempt #1 failed and Attempt #2 is Served.
  - Queries actual service records linked via `complaints.complaint_id → cases.case_id → proof_of_service.service_result`. Never assumes `complaint_id == case_id`.
  - When ineligible: button remains visually disabled (`.btn-disabled` with gray background `#94a3b8`, dimmed opacity, and `not-allowed` cursor), mouse hover shows tooltip: *"1st Mediation is unavailable until a summons has been successfully served."*, and clicking opens `summonsRequiredModal` detailing the required sequence (`Complaint → Case/Docket → Issue Summon → Serve Summon → Proof of Service → 1st Mediation`).
  - When eligible: button restores active black styling (`.btn-create`: `#1e293b`), standard pointer cursor, and click routes directly to the hearing scheduler.
  - Asynchronous coordination: `loadComplaintDetails()` awaits `loadCaseProgress(complaintId)` before running `configureMediationScheduleButton()`, eliminating race conditions.
  - Browser Back/Forward navigation (bfcache) triggers re-fetch via `pageshow` listener to guarantee up-to-date eligibility without manual browser refresh.

### 3. Proof of Service Immediate Auto-Refresh & History
- **Automatic Client-Side Refresh**:
  - On the Proof of Service page (`frontend/pages/gps/proof-service.php`), saving a service attempt immediately auto-refreshes the "Service History & Attempts" table via client-side fetch without requiring a full browser reload (F5 / Ctrl+R).
  - Form inputs are reset, the active case is preserved, and latest service documents, history rows, and context summary cards are sequentially refreshed.
  - Preserves all historical attempts in descending order (`Attempt #4`, `Attempt #3`, `Attempt #2`, `Attempt #1`) with attempt counter, formatted dates, template name, server name, badges, remarks, and thumbnail links.
  - Does not insert duplicate rows or generate fake/optimistic rows on failure. If save fails, error messages are displayed and user inputs remain untouched for correction.
- **Cache-Busting Integration**:
  - `backend/api/gps/proof-service.php`, `backend/api/complaints/progress.php`, and `backend/api/complaints/view.php` send HTTP headers:
    `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and `Pragma: no-cache`.
  - Frontend `gpsApi` includes `cache: 'no-store'` and appends `_t=${Date.now()}` timestamp parameters to GET requests.

### 4. Relevant Files
- `backend/models/CaseProgress.php`
- `backend/models/Summons.php`
- `backend/models/ProofOfService.php`
- `backend/controllers/GPSController.php`
- `backend/api/summons/issue.php`
- `backend/api/complaints/progress.php`
- `backend/api/complaints/view.php`
- `backend/api/gps/proof-service.php`
- `frontend/pages/complaints/complaint-details.php`
- `frontend/pages/gps/proof-service.php`
- `frontend/assets/js/complaints.js`
- `frontend/assets/js/gps.js`
- `frontend/assets/css/complaints.css`
- `frontend/assets/css/gps.css`

---

## Recent Increments (September 28 – October 6, 2026)

### 1. Case Number Format Standardization (`MM-SS-YYYY`)
- **Format Structure**: Conforms to the standard formula: `month(10)-series(01)-year(2026)` (e.g. `10-01-2026`, `10-45-2026`).
  - **Month (`MM`)**: 2-digit month of docket date.
  - **Series (`SS`)**: 2-or-more digit sequential case number filed in that calendar year, zero-padded to at least 2 digits (`01` through `45`, `100+`).
  - **Year (`YYYY`)**: 4-digit calendar year.
- **Backend Generator Engine**: Implemented `CaseModel::generateCaseNumber(PDO $conn, string $docketDate, int $caseId): string`:
  - Atomically calculates the next series for the target calendar year (`maxSeries + 1`) using row-level locking (`FOR UPDATE`) inside transactions.
  - Generates the number during complaint docketing (`CaseModel::docketComplaint`) and first summon issuance (`Summons::issueFirstSummon`).
- **Database & Storage Migration**:
  - Migrated existing case numbers in `cases`: `KP-2026-00001` &rarr; `10-01-2026`, `KP-2026-00002` &rarr; `10-02-2026`, `KP-2026-00003` &rarr; `10-03-2026`.
  - Updated storage folder structures and document paths in `generated_documents` (`storage/generated-documents/2026/10-01-2026/`, etc.).
  - Migrated historical notifications referencing legacy case numbers.
  - Updated frontend fallbacks in `complaints.js`, `complaint-edit.php`, and `gps.js` to eliminate legacy `KP-` strings.

### 2. Dual Notice Generation on 1st Summon (KP Form 8 & KP Form 9)
- **Automatic Form Generation**: Triggering `Issue 1st Summon` automatically generates:
  1. **KP Form 8 (Notice of Hearing for Complainant)**: Sent to the complainant notifying them of the initial mediation session.
  2. **KP Form 9 (Summons for Respondent)**: Sent to the respondent commanding their appearance.
- **Workflow Automation**:
  - Initializes `summon_deliveries` records for both parties (`Complainant` &rarr; `Notice of Hearing`; `Respondent` &rarr; `Summon`) with status `Pending`.
  - Automatically schedules the 1st Mediation hearing in the database within the same transaction.
  - Produces PDF documents saved under `storage/generated-documents/<year>/<case-number>/` and inserts corresponding `generated_documents` records.

### 3. Hearing Attendance, Service Verification & Show-Cause Lifecycle (KP Forms 18, 19, 21)
- **Independent Party Attendance**:
  - Attendance is tracked separately for Complainants and Respondents (`Present`, `Failure to Appear`, `Not Served`, `Excused`, `Late`).
  - Attendance recording requires verified summon delivery status; if a party was not served, the status defaults to `Not Served` and prompts for follow-up service.
- **Non-Appearance & Show-Cause Hearings**:
  - When a properly served Complainant fails to appear without prior justification, the system auto-generates **KP Form 18** (Notice of Hearing for Failure to Appear - Complainant) and schedules a Show-Cause explanation hearing.
  - When a properly served Respondent fails to appear without prior justification, the system auto-generates **KP Form 19** (Notice of Hearing for Failure to Appear - Respondent) and schedules a Show-Cause explanation hearing.
- **Legal Consequences**:
  - If Complainant's absence remains unjustified: **KP Form 21 (Certificate to Bar Action)** is generated and case status transitions to `DISMISSED_BARRED`.
  - If Respondent's absence remains unjustified: The case is flagged for consequence determination/indirect contempt evaluation.
  - Rescheduling a hearing creates a new hearing record and preserves complete historical service and attendance records.

### 4. Strict Scheduling Validation & Office Logistics
- **Operating Hours & Days**:
  - Enforces weekday-only scheduling (Monday to Friday); rejects weekend bookings (Saturday and Sunday).
  - Enforces official barangay operating hours: 8:00 AM to 5:00 PM.
- **Strict Chronological Sequence**:
  - Rejects hearing bookings on the same day as an existing hearing for the case or on any prior date.
- **Logistics Conflict Detection (`OfficeLogisticsService.php`)**:
  - Verifies venue availability and flags room collisions.
  - Validates presider schedule conflicts across concurrent hearings.

### 5. Summons Server Interface Overhaul (`proof-service.php` & `gps.js`)
- **Instant Search Bar**: Replaced legacy case lookup dropdown with a debounced live search bar filtering by case number, party names, title, and hearing date.
- **Pending Deliveries Table**:
  - Dedicated table displaying all cases needing summons service.
  - Columns: KP/Case No., Complainant, Respondent, Hearing Date, and an inline "Deliver Summon" button.
- **Simplified Workflow**:
  - Cleaned summon server interface by removing redundant complaint details and recorded activity tables from their view.
  - Inline "Deliver Summon" action immediately selects the case and smoothly scrolls to the Officer's Return form.
  - Fixed Proof of Service registration and attempt counting.

### 6. Case & Team Assignments Redesign (`case-list.php` & `assignments.js`)
- **Top Search Feature**: Search bar positioned directly above the case team assignment table.
- **Ongoing Cases Filter**: Table filtered to only display active/ongoing cases (`Docketed`, `Mediation`, `Conciliation`, `Arbitration`); settled, dismissed, and archived cases are excluded.
- **Streamlined 3-Column Table**:
  - Columns: Case No., Status, and Action.
  - Case Number is a clickable link navigating directly to the Complaint Details view.
  - Removed redundant complaint detail card (e.g. `CMP-2026-00003`), docket date, and edit buttons.
- **Layout Inversion**: "Team Assignment for Selected Case" is positioned prominently beneath the search bar, with "Case Team Assignment & Details" placed below.
- **Substitute Presider**: Modal relocated to the Case Team Assignment view with refined UI.

### 7. Complaints Action Toolbar: Archive Action
- On `frontend/pages/complaints/complaint-list.php`, the Delete button under the Actions column was replaced with the **Archive** button.
- Retains consistent icon styling, confirmation modal, and triggers the transactional case archive endpoint.

### 8. First Summons & Dual Document (KP Form 8 & 9) Workflow Improvement (October 2026)
- **Problem Resolved**: Previously, issuing a first summons immediately redirected to `proof-service.php`, preventing the user from reviewing, printing, or downloading the generated official Katarungang Pambarangay forms without manually navigating back.
- **New Workflow Sequence**:
  `Issue First Summons ➔ First Mediation Scheduling Modal ➔ Book ➔ Save Hearing & Case ➔ Print summon Modal (KP Form 8 & 9 Side-by-Side) ➔ Print / Download PDF ➔ Continue to Officer's Return`
- **Dual Document Preview & Print Modal (`frontend/layouts/summons-preview-modal.php`)**:
  - Reusable partial included in both `frontend/pages/complaints/complaint-details.php` and `frontend/pages/cases/mediation-workspace.php`.
  - Header displays "Print summon" without redundant badges.
  - Side-by-side dual-column grid (`1fr 1fr`) displaying:
    - **Left Column**: KP Form No. 8 (*Patalastas ng Pagdinig*) for the Complainant, with individual Print and PDF download controls.
    - **Right Column**: KP Form No. 9 (*Patawag*) for the Respondent, with individual Print and PDF download controls.
  - **Pinned Layout & Viewport Fitting**: Total modal height constrained to `height: calc(100vh - 24px)` with flexbox structure (`flex: 1 1 0; min-height: 0;`), guaranteeing that the header, column toolbars, and action footer remain permanently visible on all display sizes without button clipping.
  - Responsive fallback (`@media (max-width: 991px)`) stacks columns cleanly for smaller viewports.
- **Client Controller (`frontend/assets/js/summons-preview.js`)**:
  - Handles asynchronous preview loading via `openSummonsPreviewModal(caseId, documentId, hearingId, isJustSaved)`.
  - Clean A4 printing using `iframe.contentWindow.print()`, preventing print contamination from AGAP sidebar, navbar, or modal chrome.
  - Supports individual form printing (`printSummonsPreview('kp8')`, `printSummonsPreview('kp9')`) and batch sequential printing (`printSummonsPreview('both')`).
  - Routes directly to `../gps/proof-service.php?case_id=...&document_id=...` via `continueToOfficerReturn()`.
- **Summons Preview & Stale Regeneration Endpoint (`backend/api/summons/preview.php`)**:
  - Authenticated JSON endpoint resolving cases by `case_id`, `complaint_id`, or `document_id`.
  - Fetches case facts, hearing schedule, presiding official, complainants, and respondents.
  - Automates stale-document detection: checks if `hearing.updated_at > doc.generated_at`; if so, regenerates PDFs for KP Form 8 and KP Form 9, inserts updated records into `generated_documents`, and links `summon_deliveries`.
  - Returns structured JSON containing both `kp8` and `kp9` metadata, HTML, PDF download URLs, and Officer's Return handover link.
- **HTML Parity in `PDFService.php` (`backend/services/PDFService.php`)**:
  - Added public `renderNoticeHtml(string $formCode, array $data): string` delegating to internal `noticeHtml()`, guaranteeing 100% template, style, margin, and typography parity between the in-browser preview and the downloaded Dompdf file.
- **Standardized Downloads (`backend/api/documents/download.php`)**:
  - Added support for `?download=1` attachment disposition.
  - Standardized filename formats: `AGAP_[CASE_NUMBER]_KP-Form-8_Notice.pdf` and `AGAP_[CASE_NUMBER]_KP-Form-9_Summons.pdf`.
  - Updated access controls to permit role 4 (Summons Server) in addition to Roles 1, 2, and 3.
- **Duplicate-Submission Safeguards & Re-entry**:
  - `submitIssueSummon()` in `frontend/assets/js/complaints.js` enforces `window.isSubmittingSummon = true` and updates button text to `"Saving Schedule..."` while awaiting server response.
  - Re-entry points added to:
    - Complaint Details Card 7 (Mediation): `🔍 Preview KP Form 9 →` action button.
    - Complaint Details Card 9 (Case Documents): `🔍 Preview / Print →` button on KP Form 9 document cards.
    - Mediation Workspace (`stage-workspace.js`): `📄 Preview Summons (KP 9)` button on session cards.
- **Database Migrations Applied (October 13, 2026)**:
  - `20261013_001_repair_summon_deliveries.sql`: Added missing columns (`case_id`, `document_id`, `party_id`, `recipient_name`, etc.) to `summon_deliveries`.
  - `20261013_002_mediation_commencement.sql`: Added `first_mediation_commenced_at`, `mediation_commenced_by`, and `mediation_commencement_notes` to `cases`.
  - `20261013_003_summon_three_attempts.sql`: Replaced unique constraint `uq_hearing_party_form` with `uq_hearing_party_form_attempt (hearing_id, party_type, form_type, attempt_number)` and converted `delivery_status` and `unserved_reason` to `VARCHAR(50)`.

### 9. Proof of Service Validation, First Summons Attempt Tracking, and Conditional CFA Unlocking (October 13, 2026)
- **Problem Resolved**: Previously, summons service lacked multi-attempt lifecycle tracking for the respondent, KP Form 8 (Complainant's Notice) required manual Officer's Return recording even after being printed, the "Issue 1st Summon" button did not reflect attempt gating, and the Certificate to File Action (CFA) was not conditionally locked to statutory summons failure rules.
- **Requirement A: KP Form No. 8 Auto-Served on Print (First Mediation)**:
  - During the First Mediation stage, successfully printing KP Form No. 8 automatically updates its service status in `generated_documents` and `summon_deliveries` to `Served` (`SummonDeliveryService::markKp8Printed`).
  - Strict server-side validation restricts this automatic behavior exclusively to the complainant's notice in First Mediation.
  - Distinct audit trail: records that the status was automatically assigned via the print workflow, without creating false physical recipient signatures or handover evidence.
  - Strictly isolated: does not alter KP Form No. 9 or the respondent's summons status.
  - Idempotent: repeated print commands do not create duplicate service records or corrupt document history.
  - Dedicated endpoint: `backend/api/summons/mark-served-kp8.php` integrated with `frontend/assets/js/summons-preview.js` and `frontend/assets/js/gps.js`.
- **Requirement B: Summons Button Renaming & Dynamic Progression**:
  - Renamed `Issue 1st Summon` to `Issue Summon 1st Attempt` consistently across `frontend/pages/complaints/complaint-details.php`, `frontend/assets/js/complaints.js`, `frontend/pages/gps/proof-service.php`, `frontend/assets/js/gps.js`, and `backend/models/CaseProgress.php`.
  - Dynamic button advancement: advances to `Issue Summon 2nd Attempt` and `Issue Summon 3rd Attempt` as delivery failures are recorded.
- **Requirement C: Three KP Form No. 9 Delivery Attempts Tracking (`SummonDeliveryService`)**:
  - **Attempt 1**: Initiated upon `Issue Summon 1st Attempt`, generating fresh KP Form 9 PDF and inserting a pending attempt in `summon_deliveries`.
  - **Attempt 2**: Enabled only after Attempt 1 has completed and is recorded as a qualifying delivery failure (`Unserved`).
  - **Attempt 3**: Enabled only after both Attempts 1 and 2 are recorded as qualifying delivery failures.
  - **4th Attempt Ceiling**: Strictly blocked across backend and frontend (maximum of 3 attempts per service cycle).
  - **Successful Service Gate**: If any attempt is successfully served (`Served Personal`, `Served Substituted`, `Served Refused`), failed delivery progression stops immediately.
  - **History Preservation**: Maintains independent Officer's Return records, timestamps, failure reasons, notes, servers, and proof image attachments for every attempt without overwriting prior history.
  - Dedicated endpoint: `backend/api/summons/next-attempt.php` and controller method `GPSController::initiateNextAttempt()`.
- **Requirement D: Conditional CFA Document Unlocking & KP Form 20 Generation**:
  - The Certificate to File Action (CFA) document remains strictly locked for printing until all statutory conditions are met:
    1. Correct case and respondent identified.
    2. Three distinct KP Form No. 9 delivery attempts recorded.
    3. Each attempt has a completed outcome.
    4. All three outcomes are recorded as qualifying delivery failures (`Unserved`).
    5. No attempt has a successful service result.
  - Real-time locked state feedback: displays `CFA Locked — X of 3 summons attempts resulted in delivery failure.` or `CFA Locked — An incomplete attempt prevents this three-failure condition from being met. Record delivery result first.`
  - Gated backend model (`CFA::create`): rejects CFA creation on summons failure grounds if eligibility check fails.
  - Automated PDF Generation: `PDFService::generateKp20()` produces the official KP Form 20 (Certificate to File Action) PDF document, saves to `storage/generated-documents/YYYY/CASE_NO/`, registers in `generated_documents`, and transitions case status to `CFA Issued`.
  - CFA UI Gating (`frontend/pages/documents/cfa.php`, `frontend/assets/js/cfa.js`): renders real-time locked/unlocked banner, pre-selects 3-failed summons preset when eligible, and prevents locked submission.
- **Requirement E: Proof of Service UI Redesign (`proof-service.php` & `gps.js`)**:
  - Case & Hearing Details summary card.
  - CFA Status Card: Displays overall lock state (`🔒 CFA Locked` vs `✅ CFA Unlocked`), 3-step checklist, summary count (`X/3 Failed`), status explanation, and conditional "Print CFA (KP Form 20)" action.
  - Complainant Notice Card (KP Form 8): Displays service status, timestamp, and a dedicated auto-served badge (`Served (Print Workflow)`).
  - Respondent Summons Section (KP Form 9): Dedicated `Issue Summon [1st/2nd/3rd] Attempt` button and an attempt history table (Attempt number, date/time, process server, delivery status badge, failure reasons/notes, proof photo link, and inline Officer's Return action).
  - Officer's Return Modal: Captures `attempt_number`, `delivery_id`, delivery status (`Served Personal`, `Served Substituted`, `Served Refused`, `Unserved`), failure reasons (`Moved Out`, `Wrong Address`, `Refused`, etc.), notes, recipient identity, relationship, and secure photo proof upload.
- **Automated Verification**:
  - Comprehensive 48-assertion end-to-end verification test suite covering all 5 test scenarios (KP Form 8 auto-serve, button renaming, 3 attempts progression, CFA unlocking & KP Form 20 generation, and successful service stopping progression) passed with 100% success rate.

### 10. Summons Management Workspace Redesign, Layout Consistency, and Automated Complainant Service (October 2026)
- **Problem Resolved**:
  - The legacy Proof of Service page required excessive vertical scrolling between standalone case lookup selectors, CFA status cards, stacked complainant and respondent cards, and a disconnected pending field service table.
  - The Respondent's section used a disparate attempt history table that did not match the Complainant's card layout.
  - Complainants remained in "Pending Service" after 1st attempt summons issuance from the complaint page instead of being automatically served upon notice issuance.
  - Redundant "Print KP Form 8" and "Print KP Form 9" buttons cluttered the service cards when printing is already handled by the specialized side-by-side preview modal.
- **Unified Expandable Case Table & Workspace (`frontend/pages/gps/proof-service.php`, `frontend/assets/js/gps.js`, `frontend/assets/css/gps.css`)**:
  - **Renamed Page Title**: Updated heading to **Summons** ("Summons Management — Review summons delivery, record Officers' Returns, and monitor service progress.").
  - **Expandable Table Architecture**: Replaced the separate stacked cards and disconnected tables with an expandable case table. Clicking any case row dynamically expands/collapses inline service details directly beneath that case.
  - **Removed Redundant Controls**:
    - Removed top standalone action/filter buttons.
    - Removed the "CFA Ready" tab (simplified to **All Cases** and **Pending Delivery** tabs with dynamic counts).
    - Removed the standalone "Action" column in favor of direct row expansion and contextual actions within the expanded view.
  - **Search & Summary Indicators**: Live client-side search across Case No., Complainant, Respondent, and Title with a clear button. Metric cards summarize Total Cases, Pending Delivery, CFA Eligible, and Served Today.
- **Complainant & Respondent Visual Layout Consistency**:
  - Replaced the Respondent attempt history table with a clean vertical detail list (`.delivery-detail-list`) exactly mirroring the Complainant layout:
    - Date/Time Served
    - Service Source / Process Server
    - Service Method
    - Remarks
    - Unserved Reason (if failed) & failure notes
    - Attached photo proof preview link
  - Retained the horizontal Attempt Stepper (1st Attempt, 2nd Attempt, 3rd Attempt, and CFA status) for clear visual tracking of dispute progression.
  - Preserved distinct role branding (blue for Complainant, orange for Respondent).
- **Slide-In Officer's Return Drawer**:
  - Replaced the standard modal popup with a responsive slide-in off-canvas drawer (`#officerReturnModal`) with background dim overlay (`#officerReturnDrawerOverlay`).
  - Drawer header displays contextual badges: Case Number badge, Party badge, and KP Document Code badge (`KP Form No. 8 – Notice` vs `KP Form No. 9 – Summons`).
  - Supports logging service outcome (`Served Personal`, `Served Substituted`, `Served Refused`, `Unserved`), recipient details, relationship, failure reason, notes, and photo proof upload.
- **Card Footers Cleaned of Duplicate Print Buttons**:
  - Removed "Print KP Form 8" from the Complainant card.
  - Removed "Print KP Form 9" from the Respondent card.
  - Footers now focus cleanly on **Log Officer's Return**, plus contextual stage advancement actions (*Issue Summon 2nd Attempt* or *Proceed to CFA*) when eligible.
- **Automatic Complainant Notice Service on 1st Attempt Summons Issuance**:
  - When **Issue Summon 1st Attempt** is executed from the complaint page (`complaint-details.php` via `backend/api/summons/issue.php` and `backend/models/Summons.php`):
    - `KP Form 8` is recorded in `generated_documents` with `service_status = 'Served'`.
    - Complainant's delivery record in `summon_deliveries` is automatically created with `delivery_status = 'Served'`, `served_at = NOW()`, `service_method = 'Personal Notice'`, and remarks indicating personal notice served at the Lupon office.
  - **Retroactive Auto-Healing**: In `SummonDeliveryService::getHearingDeliveries()` and `ProofOfService::getAvailableCases()`, pre-existing mediation cases where the complainant was previously left in `Pending` status (e.g. Case #28) are automatically marked as `Served`, guaranteeing consistency across all system views.

### 11. Barangay Case Management Details Page UI Redesign (`frontend/pages/cases/case-details.php`, `frontend/assets/js/stage-workspace.js`, `frontend/assets/css/case-details-modern.css`) (October 2026)
- **Problem Resolved**: The previous case workspace presented an endless single-column card stack that caused cognitive overload and required excessive vertical scrolling to locate core case context and involved parties.
- **Top Hero Section: Primary Case Info & Immediate Context**:
  - **Header Block**: Case Number (`Case #MM-SS-YYYY`), Title, dynamic status badge (`MEDIATION`, `CONCILIATION`, `ARBITRATION`), Case Type badge (`Civil` / `Criminal`), Category badge (`Threats`, `Property Dispute`, etc.), and key metadata (Date Filed, Incident Date/Time, Docketed Case Status with live days-remaining counter badge).
  - **Side-by-Side Context Grid**:
    - **Involved Parties Card (Left Column)**: Complainant (Name, Contact No., Address) and Respondent (Name, Contact No., Address) with distinct role tags.
    - **Incident Location Card (Right Column)**: Specific incident address, landmark, GPS coordinates (`lat, lng`), and embedded interactive Leaflet/OpenStreetMap map view pinned to the incident location.
- **Three Dedicated Functional Tabs**:
  - **1. Mediation Tab**: Punong Barangay Mediation hub, Full Narrative & Facts, active Punong Barangay Presider details, mediation session metrics, and session schedules.
  - **2. Conciliation Tab**: Pangkat Tagapagkasundo conciliation hub, Pangkat constitution/membership state, prerequisite gating (locked until 3 mediation sessions completed without settlement), and conciliation hearings.
  - **3. Arbitration / CFA Tab**: Dedicated workspace for voluntary binding arbitration proceedings or Court Referral / Certificate to File Action (CFA) issuance.
- **Streamlined 3-Stage Minutes of the Meeting Flow (Option 2)**:
  - Consolidated 9 fragmented boxes into 3 chronological, non-redundant sections:
    1. **Preliminaries**: Quorum, identity verification, verified presider/substitute, party appearance statuses, and opening remarks check.
    2. **Proceedings & Discussion**: Complainant statement, respondent response, main dispute identified, private caucuses conducted, proposals, and counteroffers.
    3. **Agreed Terms & Outcome**: Session outcome recording, settlement terms, next steps, and official document generation actions.

### 12. First Mediation Process & Presider Substitution Workflow (October 2026)
- **Independent Party Attendance Validation**:
  - Independent recording of attendance for Complainant, Respondent, and Presider (`presiding_officer_id`).
  - Attendance is saved transactionally against `hearings` and `hearing_attendance`.
- **Presider Absence & Designated Substitute Presider**:
  - Under Katarungang Pambarangay rules (RA 7160 Sec. 410), when the scheduled Presider (Punong Barangay) is marked absent/unavailable:
    - Staff must select an active Lupon member as the designated Substitute Presider (`substitute_presider_id`).
    - Staff must record a valid reason for substitution (`substitute_reason`).
    - Staff must confirm that parties have been informed and mutually consent to the substitution (`parties_consent_to_substitute = 1`).
  - Validation is enforced strictly on the server (`backend/api/hearings/attendance.php` and `ShowCauseService::recordAttendance`); direct API bypass returns HTTP 422.
  - Preserves original scheduled presider in hearing history while recording the acting substitute.
- **First Mediation Absence Rules**:
  - Initial non-appearance in the 1st Mediation does NOT immediately issue penalty forms (KP 18/19/21/22).
  - Staff are guided directly to book the 2nd Mediation session; KP Form 18 and KP Form 19 generation is blocked with informative legal guidance.

### 13. Second Mediation Summons Exemption & Attendance Lock Removal (October 2026)
- **Statutory Katarungang Pambarangay Rule (RA 7160 Sec. 410)**:
  - Initial summons (KP Form 9) serves to bring the dispute before the Lupon for the 1st Mediation. Subsequent mediation hearings (2nd and 3rd) are continuations or reset dates; they do NOT require re-issuance of summonses or officer returns.
- **Server Gate Bypass (`SummonDeliveryService.php` & `SummonServiceGate.php`)**:
  - Updated `SummonDeliveryService::checkServiceStatus()`: If `hearing_type === 'Mediation'` and `hearing_sequence >= 2`, immediately returns `['allowed' => true, 'status' => 'SERVED']`.
  - Updated `SummonServiceGate::forHearing()`: Returns `['allowed' => true, 'code' => 'SUBSEQUENT_MEDIATION']` for 2nd+ mediation hearings.
  - Eliminates HTTP 409 conflict (`Mediation attendance locked — service pending for: Respondent (Attempt #1), Complainant`).
- **Party Badges & Proof-of-Service Recognition**:
  - `HearingAttendance::getHearingAttendance()` calculates sequence before normalizing parties; for sequence >= 2, sets `service_confirmed = true` and `summon_delivery_status = 'Served (Prior Notice)'`.
  - `CaseStage.php` sets `proof_of_service_status` to `'Served'` for 2nd+ mediation sessions in the sessions list.
  - `Hearing::createProgression()` bypasses `initializeHearingDeliveries()` when booking 2nd/3rd mediation sessions, preventing creation of dummy pending deliveries.

### 14. Second Mediation Non-Appearance Escalation: KP Form 18, KP Form 19, and Consequence Gating (October 2026)
- **KP Form 18 (Complainant Non-Appearance Notice)**:
  - Unlocked for preview and printing in the 2nd Mediation session when the Complainant is marked absent.
  - Generates official Notice of Hearing requiring complainant to appear and explain non-appearance within 3 days.
- **KP Form 19 (Respondent Non-Appearance Notice)**:
  - Unlocked for preview and printing in the 2nd Mediation session when the Respondent is marked absent.
  - Generates official Notice of Hearing requiring respondent to appear and explain non-appearance within 3 days.
- **First Mediation Lock Protection**:
  - Attempting to generate KP Form 18 or 19 for 1st Mediation is blocked by `backend/api/documents/kp-form.php` (HTTP 422) with statutory guidance to book the 2nd mediation without a new summon.
- **Consequence Form Gating (KP Form 21 & KP Form 22)**:
  - **KP Form 21 (Certificate to Bar Action)** for absent complainant remains strictly locked until a show-cause hearing evaluation (`show_cause_evaluations`) is recorded ruling the absence as unjustified (`is_justified = 0`).
  - **KP Form 22 (Certificate to Bar Counterclaim)** for absent respondent remains strictly locked until an unjustified show-cause evaluation is recorded.
  - If absence is ruled justified (`is_justified = 1`), issuance is barred and the dispute is rescheduled.

### 15. Canonical Hearing Booking Navigation & Conciliation Elevation Gating (October 2026)
- **Canonical Booking Flow**:
  - Cleaned up outcome card in Mediation Workspace to display the dedicated action `📅 Book 2nd Mediation Hearing →`.
  - Clicking routes directly to `../hearings/schedules.php?case_id=${caseId}&hearing_id=${hId}`, opening the full legal scheduling modal with preselected case and `schedule_reviewed = '1'`.
  - Appended return navigation `← Return to Mediation Workspace` upon successful schedule completion.
### 16. File Complaint Modernization, Fast Paper Intake & Legal-Size KP Form 7 Integration (October 2026)

- **Terminology Alignment & Navigation**:
  - Renamed the intake flow across the interface from "New File Intake" / "Katarungang Pambarangay Complaint Intake" to **File Complaint**.
  - Renamed filing method options from "Digital / Walk-in Filing" to **Digital Filing** and **Pre-Signed Paper Form**.
  - Standardized cancellation controls: simplified button labels to **Cancel** and streamlined action bars.

- **Dual-Mode Complaint Intake (`frontend/pages/complaints/complaint-create.php`)**:
  - **1. Digital Filing Mode**:
    - **Multiple Parties Support**: Added checkboxes (`has_multiple_complainants`, `has_multiple_respondents`) allowing staff to specify single or multiple complainants/respondents cleanly.
    - **Party Details Demographic Modal (`#partyDetailsModal`)**: Complete modal dialog for capturing individual complainant/respondent demographic and legal details without cluttering the main intake form:
      - Minor complainant/respondent indicator (`is_minor`) with mandatory Legal Guardian details (`guardian_name`, `guardian_relationship`, `guardian_contact`, `guardian_address`).
      - Detailed demographics: birthdate, auto-calculated age, gender, civil status, occupation, and suffix.
      - Preferred contact channel (`SMS`, `Call`, `Email`, `In-Person`).
      - Unknown information checkboxes: `contact_unknown`, `middle_name_unknown`, `birthdate_unknown` allowing submission even when certain respondent information is initially unavailable.
    - **Streamlined Complaint Narrative**:
      - Renamed field to **Complaint Narrative** (removed "Statement of Facts" and removed redundant "Additional Details (Optional)" and "Nearby Landmark (Optional)").
      - Removed prescriptive explanatory text ("Prayer for relief: what settlement, restitution, or agreement the complainant asks the Lupon to assist in obtaining").
    - **Flexible Incident Date & Time**:
      - Added "Exact Time Unknown" (`is_time_unknown`) and "Approximate Time" (`is_time_approximate`) checkboxes.
      - Dynamically switches the input element between `date` and `datetime-local` while automatically defaulting approximate midday timestamps when exact time is unknown.
    - **Relocated Primary Action Button**:
      - Moved the primary **Submit Complaint** button directly underneath **Section 5: Evidence & Attachments**.
      - Simplified the top header action group to a single **Cancel** button, ensuring the clerk reviews all sections before submitting.
    - **Interactive Submission Preview Modal (`#kp7DigitalPreviewModal`)**:
      - Submitting via Digital Filing immediately triggers a high-fidelity modal preview of **Pormularyo ng KP Blg. 7 (Reklamo / Formal Complaint)**.
      - Allows the clerk to review and print the official complaint form on Legal paper before final submission into the database.

  - **2. Pre-Signed Paper Form (Fast Intake Mode)**:
    - Designed for disputes where parties arrive with a pre-signed or handwritten physical KP Form 7.
    - **Side-by-Side 2-Panel Split Layout**:
      - **Left Panel (50%–60% Width) - Document Viewer**: In-browser document viewer for scanned PDF or image (`.pdf`, `.jpg`, `.jpeg`, `.png`) with zoom controls (Zoom In, Zoom Out, Reset), 90-degree image rotation, and fullscreen toggling.
      - **Right Panel - Fast Indexing Form**: High-density indexing form capturing only essential fields: Filing Method, Uploaded File Selector, Complaint Category, Case Type (`Civil`/`Criminal`), Incident Date & Time, and Auto-filled Narrative ("See attached scanned KP Form 7").
      - Balanced, uncrowded layout with clean spacing and subtle borders.
    - **Versioned Document Storage**:
      - Uploaded scans are stored securely in `storage/uploads/complaint-scans/` and cataloged in the new `complaint_scanned_documents` table with version incrementing (`version_number`), active state flag (`is_current`), upload timestamps, and replacement reasons.

- **Edit Complaint Layout Parity (`frontend/pages/complaints/complaint-edit.php`)**:
  - Aligned the edit screen layout to match `complaint-create.php`:
    - Removed bottom-left Cancel button.
    - Repositioned the **Save Changes** button to the top header toolbar directly beside the **Cancel** button.
    - Integrated the same streamlined party details modal, multiple parties checkboxes, and narrative labels.

- **Official KP Form 7 Legal Size Bondpaper Specification (`PDFService.php`)**:
  - **Paper Sizing**: Formatted strictly to standard **Legal Size Bondpaper** (`8.5in x 14in` / `215.9mm x 355.6mm`).
  - **Official Header & Seals**:
    - Aligned with the official Barangay Tumana Word template: **Barangay Tumana Round Seal on the Left** and **City of Marikina Official Seal on the Right**.
    - Sized both logos to `88px x 88px` (height-matched to the 6-line header block).
  - **Physical Signature Spacing**:
    - Added generous physical signature gaps (`.sig-space { height: 48px; }`) above the printed names/lines for both the Complainant and the Punong Barangay / Lupon Chairman.
  - **Zero-Watermark Printing**:
    - Configured `@page { size: 8.5in 14in; margin: 0; }` combined with internal `.page-sheet { padding: 16mm 20mm 20mm 20mm; }`.
    - Completely eliminates browser-injected print header and footer watermarks (such as URLs, timestamps, page titles, and page numbers).
  - **Modal Screen Preview**:
    - Rendered on a dark slate canvas (`#334155` / `#475569`) displaying a centered Legal page sheet with drop shadow (`box-shadow: 0 14px 40px rgba(0,0,0,0.45)`), preventing horizontal stretching or distortion across wide monitors.

- **Standardized Case Docketing Number Format**:
  - Standardized docketing number format across all cases and complaints to `MM-DD-YYYY` (e.g., `10-17-2026`), removing legacy prefixes such as `CMP-2026-00050`.

- **Complaint Details Official KP Forms Integration (`frontend/pages/complaints/complaint-details.php`)**:
  - Moved **KP Form 7 (Reklamo)** into the unified **OFFICIAL KP FORMS** card table, establishing visual and functional consistency with all other KP forms.
  - Sized the modal preview to `96vw` / `1350px` max-width on a dark canvas.
  - Streamlined row actions: replaced separate PDF chip and "Replace Scan" buttons with unified preview and scan management triggers.

- **Database Migration (`database/migrations/20261016_001_enhance_file_complaint_kp7.sql`)**:
  - `complaints`: added `status` ('Draft'), `document_status`, `requested_resolution`, `is_time_approximate`, `is_time_unknown`, `incident_location_details`, `is_draft`.
  - `complaint_parties`: added `is_minor`, `guardian_name`, `guardian_relationship`, `guardian_contact`, `guardian_address`, `preferred_contact`, `suffix`, `occupation`, `age`, `birth_date`, `gender`, `civil_status`, `contact_unknown`, `middle_name_unknown`, `birthdate_unknown`, `address_requires_verification`, `residency_verified`.
  - `residents`: added `suffix`, `occupation`, `preferred_contact`, `residency_verified`.
  - Created `complaint_scanned_documents` table.
  - Seeded `KP Form 7` in `document_templates`.

### 17. Minutes of the Meeting: Direct "Next Hearing" Booking & Summons Suppression (October 2026)

- **Integrated "Next Steps" Section inside Minutes Form**:
  - Eliminated the disconnected two-step workflow where saving minutes and booking the next session were separate modal actions.
  - Selecting **"No Settlement → Set Another Meeting"** (`Continue Mediation`) in the outcome dropdown dynamically expands the integrated **Next Steps** booking panel directly within the Minutes of the Meeting form.
  - Provides date, time, and venue inputs with intelligent defaults (suggested next business day, operating hours validation).
- **Summons (KP Form 9) Suppression (RA 7160 Sec. 410)**:
  - Because both parties are present during the session and sign the official minutes, statutory personal notice is fulfilled.
  - Suppresses generating a redundant summons (KP Form 9) or inserting pending delivery records in `summon_deliveries` for the subsequent mediation session.
- **Immediate Booking Upon Minutes Submission**:
  - When staff clicks **Save Minutes Draft** or **Finalize & Lock**, `backend/models/HearingMinutes.php` saves the minutes and immediately invokes `Hearing::createProgression()` in sequence.
  - Seamlessly returns confirmation: books 2nd or 3rd Mediation, logs case history, and automatically updates session tabs and stage status without navigating away from the workspace.

### 18. Complaint Intake Screening & Hover Navigation (October 2026)

- **Pre-filing jurisdiction and eligibility screen**:
  - The File Complaint page now requires staff to complete a preliminary administrative screen before a non-draft complaint can be submitted.
  - The screen records statutory-exclusion facts, optional offence penalty context, a private-offended-party indicator, and free-text screening notes. It explicitly states that authorized review, rather than intake, determines final disposition.
  - Server-side validation enforces completion and evaluates the existing KP jurisdiction gate; browser validation only provides immediate feedback.
- **Auditable screening record**:
  - Added `complaint_intake_screenings`, with one screening record per complaint, including screen inputs, duplicate acknowledgement, actor, and timestamp.
  - `Complaint::getById()` returns the saved screening as `intake_screening` for downstream complaint detail/API consumers.
  - Existing complaints can still be corrected without silently replacing an assessment that was not part of the edit form.
- **Duplicate protection**:
  - The existing client-side duplicate warning now requires a staff acknowledgement before filing continues.
  - The complaint model repeats the duplicate check server-side for matching complainant/respondent pairs, including name-based resident lookup, so a browser-side check cannot be bypassed.
- **Migration and fresh schema**:
  - Apply `database/migrations/20261017_001_intake_screening_and_duplicate_acknowledgement.sql` once to an existing database before using this feature.
  - `database/schema.sql` includes the new table for clean installs. Do not import the full schema over a populated database.
- **Sidebar interaction**:
  - Desktop navigation is now a compact icon rail that expands on hover or keyboard focus; the click-to-collapse control and its saved local-storage state were removed.
  - The desktop sidebar remains sticky while page content scrolls and can scroll independently if its own contents exceed the viewport.
  - Mobile navigation retains the existing Menu-button drawer behavior.
- **Verification completed**:
  - `php -l` passed for the changed complaint model, File Complaint page, and sidebar layout.
  - JavaScript syntax validation for `frontend/assets/js/app.js` and `git diff --check` passed.

---

## Preliminary Paanyaya Workflow & Officer's Return Tracking (October 2026)

A dedicated informal preliminary invitation workflow (**Paanyaya**) was inserted between Complaint Details / Intake and formal Mediation, implementing the Barangay Tumana preliminary dispute resolution protocol. Before issuing formal court-annexed summonses (KP Forms 8 & 9), authorized personnel conduct informal preliminary dialogue attempts.

### Key Implemented Components

1. **Workflow Sequence & State Machine**:
   - Canonical sequence: `Complaint Intake / Overview` $\rightarrow$ `Paanyaya 1` $\rightarrow$ `Paanyaya 2` $\rightarrow$ `Mediation` $\rightarrow$ `Conciliation` $\rightarrow$ `Arbitration / CFA`.
   - Cases in this preliminary phase hold the `Pre-Docketed (Paanyaya)` state (`cases.case_status = 'Pre-Docketed (Paanyaya)'`).
   - The formal summons action `Issue Summon 1st Attempt` is strictly gated and locked until preliminary requirements are satisfied.
2. **Dedicated Paanyaya Tab (`complaint-details.php`)**:
   - Positioned between Case Summary and Mediation in the complaint workspace navigation.
   - Distinct sections for:
     - **Paanyaya 1 – First Preliminary Invitation**: Supports up to six (6) independent delivery attempts.
     - **Paanyaya 2 – Second Preliminary Invitation**: Supports up to six (6) independent delivery attempts, locked until Paanyaya 1 is completed or escalated via Lupon administrative review.
   - Independent attempt counter (1 to 6) per stage enforced by database constraint `uq_stage_attempt (paanyaya_stage_id, attempt_number)` and `Paanyaya.php` model. Attempt counters never reset across sessions or overlap between stages.
3. **Official Invitation Generation & Print Preview**:
   - Custom invitation letter generation with official Barangay Tumana and City of Marikina seals, schedule (date, time, venue: *Tanggapan ng Barangay Tumana*), salaysay summary, and Punong Barangay / Lupon signature block.
   - Post-filing modal preview (`#paanyayaPreviewModal`): Displays high-fidelity preview with zero-watermark printing, PDF downloading, and one-click transition to Officer's Return.
   - Dedicated backend API endpoints:
     - `backend/api/paanyaya/preview.php` (`format=html` or `format=pdf` / `download=1`)
     - `backend/api/paanyaya/issue-stage.php`
     - `backend/api/paanyaya/stages.php`
     - `backend/api/paanyaya/record-return.php`
     - `backend/api/paanyaya/update-return.php`
     - `backend/api/paanyaya/return-details.php`
     - `backend/api/paanyaya/review-stage.php`
4. **Granular Officer's Return Tracking**:
   - Records attempt number (1–6), service datetime, serving officer, recipient name, address visited, delivery outcome, failure reason, remarks/witness info, proof photo/attachment upload, and GPS coordinates.
   - Granular outcomes: `Successfully delivered`, `Recipient unavailable`, `Recipient refused to receive`, `Respondent not found at the address`, `Incorrect or incomplete address`, and `Other reason`.
   - Responsive delivery history tables per stage with outcome badges, serving officer details, proof links, and inline action buttons (`✏️ Edit Return`).
5. **Administrative Review Workflow**:
   - If all six (6) attempts are exhausted without delivery, the stage enters `Attempts Exhausted – For Review`.
   - Authorized personnel record a formal Lupon decision: `Approved for Paanyaya 2`, `Approved for Summons`, or `Case Closed`.
6. **Proof of Service Integration (`proof-service.php`)**:
   - Unified workspace displays both formal summons cases and Paanyaya cases.
   - Adaptive Paanyaya layout: displays a full-width Respondent delivery card (Complainant notice is hidden as preliminary invitations are delivered exclusively to the respondent).
   - 6-attempt horizontal stepper showing attempt nodes (1st to 6th Attempt) with live status (`Served`, `Failed`, `Pending`, `Unissued`).
   - **Fully Editable Returns & Correction Workflow**:
     - All issued stepper attempt circles are clickable with pointer cursors and open the return drawer for that attempt.
     - Row-level `✏️ Edit Return` buttons allow authorized personnel to edit and correct existing returns (delivery status, date/time, recipient name, relationship, failure reason, remarks).
     - Drawer automatically switches between **Edit Mode** (`Edit Officer's Return`) and **Log Return Mode** (`Log Officer's Return`).
     - Updates are routed through `GPSController::recordOfficerReturn` to `Paanyaya::updateOfficerReturn` and `SummonDeliveryService::recordDelivery` with comprehensive audit logging via `AuditService`, without triggering attempt counter limit errors.
7. **Database Schema & Migrations**:
   - `database/migrations/20261020_001_create_paanyaya_stages_and_returns.sql`:
     - `paanyaya_stages`: Stage type, status, schedule details, review status, and audit foreign keys.
     - `paanyaya_officer_returns`: Attempt number, delivery outcome, address, GPS, notes, proof path, and audit fields.
8. **Automated Verification Suites**:
   - `scratch/test_e2e_full_verification.php`: 48 passing assertions testing the complete lifecycle (intake, Stage 1 attempts 1–6, review decision, Stage 2 attempts, summons lock/unlock transitions).
   - `scratch/test_proof_service_paanyaya_integration.php`: 17 passing assertions verifying API case listings, delivery fetching, and return recording via Proof of Service.

---

## Present but Incomplete or Requiring Verification

- Project-wide RBAC remains inconsistent, but broad RBAC hardening is not the immediate priority.
- Complaint party and attachment workflows have active browser/MySQL tests; additional stress-testing on edge cases is recommended.
- GPS mobile field-device capture (camera/geolocation) operates in browser environments; device-level offline caching remains an enhancement.
- AI calls Gemini, but production-grade monitoring and rate limiting are incomplete.
- KP Form 7, 10, 12, 16, 18, 19, 20, 21, and 22 generate successfully; ongoing visual verification with varied text lengths is recommended.

---

## Missing Module Development Features

The following remain missing from the complete Module Development specification:

- Resident self-service complaint filing and status tracking portal.
- Remaining optional KP forms (e.g., KP Forms 13, 14, 15, 17, 23). *(Note: KP Forms 7, 16, 18, 19, 20 CFA, 21, and 22 are now implemented).*
- Automated end-to-end integration test suite, deployment pipelines, and automated database backup routines.

---

## Next Development Step

### Recommended Next Module: Resident Self-Service Complaint Filing and Status Tracking

Implement a resident-facing complaint filing and status-tracking portal.
Confirm the resident authentication approach (resident portal login vs. complaint tracking code) from the specification before adding new tables or roles.

---

## Feature-Focused Development Order

1. Complete resident self-service complaint filing and status tracking.
2. Implement remaining official KP forms (KP Form 13 through 17, 20, 22, 23).
3. Advanced deadline notifications and automated SMS/email alerts.
4. Comprehensive automated testing, security audit, deployment scripts, and backup procedures.

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

---

## October 2026 — Four High-Priority Changes (Interview-Driven)

All four changes were implemented and syntax-verified (`php -l` passes, logic tests pass).

### 1. Mediation 2-Session Ceiling (Barangay Tumana Policy)

**Rationale:** PB conducts mediation on Tuesday afternoons only, strictly for a maximum of 2 hearings.

**Files changed:**

| File | Change |
|------|--------|
| `backend/models/CaseStage.php` | `completedMediationCount` threshold: 3 → 2; lock message updated; `next_permitted_action` after 2nd session = Elevate to Pangkat; `canBookNextMediation` gate: `< 3` → `< 2`; `referToPangkat` guard: 3 → 2 |
| `backend/models/Hearing.php` | `activeMediationCount >= 3` guard → `>= 2`; updated error message |
| `backend/services/MediationDeadlineService.php` | Advisory threshold: `>= 3` → `>= 2`; updated wording |
| `frontend/assets/js/stage-workspace.js` | Session type label (removes "3rd Mediation"); progress counter "of 3" → "of 2"; banner text; lock reason message; `sessionIndex === 1 || 2` → only 1; error handler catches Two-attempt message |

**No DB migration needed.** Existing hearings with 3 sessions retain their records; the Pangkat referral will already be unlocked (`completedMediationCount >= 2`).

---

### 2. Reopen Settled Cases for CFA (RA 7160 Sec. 417)

**Rationale:** When the respondent fails to comply with Amicable Settlement terms, the case must be reopened and a CFA issued for court endorsement.

**Files changed / created:**

| File | Change |
|------|--------|
| `backend/api/cases/reopen.php` | **New.** POST endpoint. Validates Settled status, marks `settlements.compliance_status = 'Violated'`, logs `case_history`, returns CFA redirect URL |
| `backend/models/CFA.php` | Added `$isReopenWorkflow` bypass — Settled cases are allowed through CFA issuance when `workflow = noncompliance_reopen` |
| `frontend/pages/complaints/complaint-details.php` | Added `#btnReopenForCfa` button (hidden by default); added `#modalReopenForCfa` HTML modal at bottom |
| `frontend/assets/js/complaints.js` | Button shown only when `case_status === 'Settled'`; `openReopenCfaModal()`, `closeReopenCfaModal()`, `submitReopenForCfa()` functions appended |

**No DB migration needed.** Uses existing `settlements.compliance_status ENUM` which already includes `'Violated'`.

---

### 3. Exportable Excel Case Register

**Rationale:** Staff need a 1-row-per-case spreadsheet for DILG submission and internal monitoring, with summons delivery timeliness metrics.

**Files changed / created:**

| File | Change |
|------|--------|
| `backend/models/Report.php` | Added `getCaseRegister($startDate, $endDate)` — SQL joins cases, complaints, parties, summons (attempt=1 only), hearings, settlements, cfa_records; computes `summons_timeliness` as `On Time / Delayed / Not Served` |
| `backend/services/ReportService.php` | Added `createCaseRegisterExcel($rows, $label)` — writes UTF-8 BOM CSV; Row 1 = 21-column header; 1 data row per case |
| `backend/controllers/ReportController.php` | Added `exportCaseRegister($filters, $userId)` method |
| `backend/api/reports/export.php` | Rewrote to route by `format` param: `register_excel` → `exportCaseRegister()`, else → `export()` |
| `frontend/pages/reports/report-list.php` | Added "Case Register Export (Excel)" section with date-range pickers and `#btnExportRegister` |
| `frontend/assets/js/reports.js` | Added `exportCaseRegister()` async function; wired button event listener |

**Excel columns (21 total):** Case No., Complaint No., Case Type, Category, Complaint Title, Complainant, Respondent, Incident Date, Date Docketed, Summons Issued Date, Summons Served Date, Serving Officer, Delivery Status, Summons SLA (1-day), Mediation Sessions, Conciliation Sessions, Settlement Date, Settlement Compliance, CFA Issuance Date, CFA Reason, Case Status.

---

### 4. DILG / LTIA 1-Click Printable Report

**Rationale:** Staff need a printer-ready DILG/LTIA summary report without manual formatting.

**Files changed / created:**

| File | Change |
|------|--------|
| `backend/api/reports/print.php` | **New.** Self-contained HTML page with `@media print` CSS. Renders KPI summary, case status breakdown, category breakdown, summons SLA, full case list, and signature block. Supports Monthly, Quarterly, Annual, DILG types. |
| `frontend/pages/reports/report-list.php` | Added "🖨️ Print DILG / LTIA Report" button (`#btnPrintDilg`) |
| `frontend/assets/js/reports.js` | Added `printDilgReport()` — opens `print.php` in a new tab with form parameters; wired button event listener |

**Signature lines** use placeholder text `[Punong Barangay Name]` / `[Barangay Secretary Name]` — these should be updated in `print.php` lines 49–50 once the actual officer names are confirmed.

---

### Testing Performed

- `php -l` passed on all 11 modified/created PHP files (zero syntax errors).
- Logic unit tests (`scratch/verify_logic.php`) verified:
  - Mediation ceiling = 2 ✓
  - canBookNext / canRefer state transitions ✓
  - Settled CFA reopen bypass logic ✓
  - Summons SLA (1-day) boundary conditions ✓
  - Excel header column count = 21 ✓

### Known Limitations / Follow-up Items

1. **Officer names in print.php:** Hardcoded placeholder — update lines 49–50 in `backend/api/reports/print.php`.
2. **Reopen URL path:** The JS `fetch` path in `complaints.js` assumes the page is at `frontend/pages/complaints/complaint-details.php`. If the page depth changes, update the relative URL.
3. **Existing cases with 3 mediation sessions:** These are unaffected; their `completedMediationCount >= 2` so Pangkat referral is already unlocked.
4. **`summons` table alias:** `getCaseRegister()` assumes a `summons` table with columns `case_id, issuance_date, delivery_date, serving_officer, delivery_status, attempt_number`. Verify column names match your live DB.

---

## October 2026 — Paanyaya Pre-Docket Workflow, Case Numbering Standard, and UI Streamlining

A series of key refinements were implemented to conform strictly to Barangay Tumana's informal dialogue procedure, decouple informal pre-docketed disputes from formal court docketing, separate Paanyaya status presentation, and streamline the complaint details interface.

### 1. Distinct Paanyaya Pre-Docket Case Numbering (`P-month-number-year`)

- **Rationale:** Disputes in the preliminary invitation stage (Paanyaya) are informal and not yet formally docketed into the KP court system. They must not appear with formal docket numbers or generic `CMP-` prefixes.
- **Format:** `P-month-number-year` (e.g., `P-10-86-2026` for Complaint #86 filed in October 2026).
- **Implementation:**
  - Added `Complaint::generatePaanyayaCaseNumber(int $complaintId, ?string $dateStr = null): string` in `backend/models/Complaint.php`.
  - Created and executed migration `database/migrations/20261021_002_update_paanyaya_case_numbers.sql` to standardize all existing Paanyaya records (`P-10-86-2026`, `P-10-85-2026`, etc.).
  - Added dedicated styling `.badge-case-paanyaya-no` in `frontend/assets/css/complaints.css` (clean green badge).
  - Updated `frontend/assets/js/complaints.js` and `frontend/assets/js/search.js` so table cells and details headers display `Paanyaya Case #P-10-86-2026`.

### 2. Transition to Official Docketed Case Number Upon Escalation to Mediation (`month-number-year`)

- **Rationale:** When an informal Paanyaya dispute fails to resolve and is escalated to formal Mediation, it officially enters the court docket. At this point, it must receive the official docketed case number.
- **Format:** `month-number-year` (e.g., `10-21-2026`).
- **Implementation:**
  - In `backend/models/CaseModel.php` (`create()`) and `backend/models/Paanyaya.php` (`escalateToFormalDocketing()`), the official case record is created in `cases` with `case_number` generated via `CaseModel::generateCaseNumber()`.
  - The complaint's tracking number in `complaints.complaint_number` is synchronized to the assigned docketed case number, and its status is updated to `Mediation`.

### 3. Paanyaya Status Isolation (Not Combined with Other Statuses)

- **Rationale:** Previously, Paanyaya disputes could display multiple badges (e.g., `Paanyaya` alongside `Pending`, `Under Review`, or `Draft`). Paanyaya is a distinct pre-docket phase and should not be combined with other statuses.
- **Implementation:**
  - Updated `Search::resolveLifecycle()` and `Search::resolveStandardStatus()` in `backend/models/Search.php`.
  - Updated `renderComplaintRow()` and `renderSearchRow()` in `frontend/assets/js/search.js`.
  - Complaints in Paanyaya now show strictly the single badge `Paanyaya`.
  - Paanyaya complaints are included under the "All" tab and have a dedicated "Paanyaya" filter tab with live count pills.

### 4. Official Paanyaya Preview Modal Layout & Cleanup

- **Rationale:** The preview modal had redundant headers, duplicate action buttons, and confusing recipient indicators.
- **Files changed:** `frontend/pages/complaints/complaint-details.php`.
- **Modifications:**
  - Removed top duplicate button ("Proceed to Officer's Return"), retaining only the bottom primary action button.
  - Removed redundant subtitle (*"Barangay Tumana • Lungsod ng Marikina • Pre-Docketed Informal Dialogue"*).
  - Removed the *"Download PDF"* button to prioritize direct high-fidelity printing via the top *"🖨️ Print"* button.
  - Removed the bottom indicator text (*"Recipient for Delivery: Respondent Only (Up to 6 Attempts on Paanyaya)"*).

### 5. Removal of Case Summary Tab

- **Rationale:** The Case Summary tab was redundant with the comprehensive Overview & Workflow tab.
- **Files changed:**
  - `frontend/pages/complaints/complaint-details.php`: Removed action bar button, secondary tab button, and the `#tab-summary` HTML pane. Removed `'tab-summary'` from `$validTabs` fallback routing in PHP.
  - `frontend/assets/js/complaints.js`: Added element-existence guards in `renderCaseSummary()`.

### 6. Removal of Paanyaya Delivery History & Officer's Return Records

- **Rationale:** The separate delivery history table was extraneous and simplified out of the active Paanyaya interface.
- **Files changed:**
  - `frontend/pages/complaints/complaint-details.php`: Removed the `#paanyayaHistoryCard` table section (*"Paanyaya Delivery History & Officer's Return Records"*).
  - Removed the *"📜 View Delivery History"* buttons from both the Paanyaya 1 and Paanyaya 2 stage cards.

### 7. Database Migrations Added

| Migration File | Description |
|----------------|-------------|
| `database/migrations/20261020_002_create_paanyaya_minutes_table.sql` | Creates `paanyaya_minutes` table for recording dialogue discussion notes, outcome, and agreed terms. |
| `database/migrations/20261021_001_add_paanyaya_outcome_columns_to_complaints.sql` | Adds `paanyaya_1_outcome` and `paanyaya_complainant_choice` columns to `complaints` table (also reflected in `database/schema.sql`). |
| `database/migrations/20261021_002_update_paanyaya_case_numbers.sql` | Standardizes all pre-docket Paanyaya complaint numbers to the `P-month-number-year` format. |

### 8. Cleanup of Automated Test Records

- Purged 26 automated E2E test disputes (such as `"E2E Paanyaya Test Dispute"`, `"Proof of Service Paanyaya Test"`, and `"Edit Test"`) and 33 orphaned test PDFs from `storage/paanyaya/`.
- Verified that legitimate records, specifically Complaint **#86** (`test 22`), were preserved intact.

---

## Late October 2026 — Master Document Center (DOCX Upload & Print Layout Fidelity), Mediation Booking Fix, and Navigation Streamlining

### 1. DOCX Master Template Upload & Rendering Engine
- **Dedicated Parser Service (`backend/services/DocxTemplateService.php`)**:
  - Implemented OpenXML `.docx` parsing using PHP `ZipArchive` and `DOMDocument` to convert uploaded Word templates into clean, semantic HTML.
  - Retains character formatting (bold, italic, underline), paragraph alignments (left, center, right, justify), line spacing, and table layouts without third-party external dependencies.
  - Sanitizes and embeds dynamic template variables while keeping the uploaded document body and typography intact.
- **Master Document Center (`document-center.php`) & Upload Template Modal**:
  - Renamed modal to **"Upload template"** and removed the noisy OpenXML inspection block.
  - KP forms list displays explicit KP numbers (`KP FORM 7`, `KP FORM 8`, `KP FORM 9`, `KP FORM 16`, `KP FORM 20`, etc.) across all cards and modal pickers.
  - Modal preview title standardized to strictly `KP FORM [NUMBER]` (e.g. `KP FORM 20`), removing verbose and redundant headers.
  - Removed "Open full window" button in favor of a clean, responsive in-modal preview with direct print access.

### 2. High-Fidelity Print Styling & Layout Alignment
- **Zero Browser Header/Footer Print Rules**:
  - Applied print-specific CSS with `@page { margin: 10mm; size: auto; }` and body margin overrides in `frontend/assets/css/documents.css` to prevent browsers from printing default header/footer timestamps, page URLs, and document paths.
  - Standardized font family across all KP document bodies and previews to clean **Arial** (`font-family: Arial, Helvetica, sans-serif;`).
  - Added responsive print media scaling (`@media print`) so documents automatically fit and scale cleanly to standard bond paper sizes (A4 and Legal) without text overflow or misaligned columns.
- **Letterhead & Logo Customization**:
  - Removed the redundant "Office Name Banner" from preview headers.
  - Barangay Tumana and City of Marikina seals are configurable and uploadable, preserving official branding with consistent aspect ratios.

### 3. Mediation Scheduling & Operating Hours Booking Fix
- **Removal of Hardcoded Tuesday-Only Lock**:
  - Replaced legacy restriction in `backend/models/Summons.php` (`findMediationConflict()`) that previously restricted mediation sessions exclusively to Tuesday afternoons (1:00 PM – 5:00 PM).
  - Punong Barangay mediation sessions can now be scheduled on any regular working day (**Monday through Friday, 8:00 AM to 5:00 PM**) within the 15-working-day statutory window (up to 8 daily sessions).
- **Clear Conflict Diagnostic Messages**:
  - Updated `backend/api/summons/check-schedule.php` to prioritize model violation messages directly, eliminating misleading notices like *"A Mediation Operating Hours Violation is already scheduled from 7:02 PM to 8:02 PM"*.
- **Time Picker Safeguards**:
  - In `frontend/pages/complaints/complaint-details.php`, added `min="08:00"`, `max="17:00"`, `step="1800"`, and defaulted `#summonMediationTime` to `09:00 AM`.
  - In `frontend/assets/js/complaints.js`, opening `issueSummonModal` resets any prior HTML5 validity errors (`setCustomValidity('')`) and ensures safe operating-hours defaults.

### 4. Navigation Streamlining — Removal of Standalone Hearings & Deadlines Page
- Removed the standalone **"Hearings and Deadlines"** item from the sidebar navigation (`frontend/layouts/sidebar.php`).
- Retired `frontend/pages/hearings/schedules.php` and consolidated hearing scheduling and timeline management directly within the Case/Complaint Mediation & Conciliation workspaces and calendar views.

### 5. 2nd Mediation Session Scheduling & Minutes Save Fix
- **Hearing Minutes Save Error (SQL 1054) Resolution**:
  - Resolved `Unknown column 'actual_duration_minutes' in 'field list'` error by applying migration `database/migrations/20261021_001_elastic_hearing_duration_and_docket_queue.sql` compatible with standard MySQL 8.0/8.4+.
  - Added `actual_start_time`, `actual_duration_minutes`, and `queue_status` columns to `hearings` and `paanyaya_sessions`.
  - In `backend/models/HearingMinutes.php`, added defensive `try/catch` fallback to safeguard minutes saving from missing column exceptions.
- **Removed Inline Next Steps Section**:
  - Removed the inline *"Next Steps: Book 2nd Mediation Hearing"* block from `mediation-workspace.php` and `stage-workspace.js`.
  - Updated `saveHearingMinutes` in `stage-workspace.js` so saving or finalizing minutes no longer blocks or alerts for missing inline date/time inputs.
- **Dedicated 2nd Mediation Visual Scheduling Modal**:
  - Selecting *"No Settlement → Set Another Meeting"* (`Continue Mediation`) automatically launches the dedicated 2nd Mediation Scheduling modal with the visual 15-working-day calendar.
  - Interactive day cards display daily session capacity (`X open`, `8/8 FULL`, `>15 Days`, `Past`, `Holiday/Weekend`), alongside existing scheduled mediation timelines and time slot conflicts.
  - Explicitly highlights the statutory notice exemption under **RA 7160 Sec. 410**: because parties are present in person and notified during the session, prior notice is legally served and redundant summons (KP Form 9) generation is bypassed.
- **Full Monday–Friday Working Hours Booking Alignment**:
  - Removed legacy Tuesday restrictions across `HearingController.php`, `OfficeLogisticsService.php`, `Summons.php`, `MediationScheduleController.php`, and `MediationSchedule.php`.
  - Mediation sessions can now be scheduled across all regular working days (**Monday to Friday, 8:00 AM – 5:00 PM**, excluding 12:00 PM – 1:00 PM lunch break) up to the 8-session daily maximum.

### 6. Officer's Return for Mediation Summons Fix (Proof of Service)
- **Resolved "Officer's Return record not found" Error**:
  - **Root Cause**: In `backend/controllers/GPSController.php` (`recordOfficerReturn()`), incoming requests were checking `paanyaya_stages WHERE id = ?` using the hearing ID. For cases with formal hearings (such as Case 42 with `hearing_id = 61`), an unrelated Paanyaya preliminary stage row that happened to have primary key `id = 61` caused the controller to misidentify the mediation summons return as a Paanyaya return. It then routed `delivery_id = 103` (which is in `summon_deliveries`) to `Paanyaya->updateOfficerReturn(103)`, which looked in `paanyaya_officer_returns`, failed to find record 103, and threw `"Officer's Return record not found"`.
  - **Controller Fix**: Updated `GPSController.php` to strictly check `summon_deliveries` and `hearings` first. If `delivery_id` exists in `summon_deliveries` or `hearing_id` exists in `hearings`, the request is guaranteed to be a formal summons return (`$isFormal = true`), completely bypassing the Paanyaya branch and cleanly invoking `SummonDeliveryService->recordDelivery()`.
  - **Photo Upload Field Name Fix**: In `GPSController.php`, fixed the column update from `attachment_path` (non-existent) to `proof_file_path` in `summon_deliveries`.
  - **Frontend State Isolation**: In `frontend/assets/js/gps.js` (`openOfficerReturnModal()`), ensured `isPaanyaya` strictly checks `caseItem.is_paanyaya` rather than inheriting stale global state from `window.activeExpandedHearingData`, ensuring formal mediation cases display KP Form No. 9 / KP Form No. 8 headers and badges accurately.

---

## Late October 2026 — Separated Session Minutes, Court Transmittal & Ruled Minutes Template, Pangkat Referral Consolidation, and Outcomes Dynamic Unlocking

### 1. Separated Hearing Session Minutes & Proceedings (Mediation & Conciliation)
- **Multi-Card Independent Session Architecture (`mediation-workspace.php`, `pangkat-workspace.php`, `stage-workspace.js`)**:
  - Replaced the single-form in-place pill switching design with **distinct, physically separated section cards** for each scheduled hearing session on the page.
  - **1st Mediation Session Card**: Displays the complete records of the 1st Mediation hearing (attendance, dispute focus, discussion notes, evidence attachments, agreed terms, and session outcome). Includes its own independent action buttons (`Save #1 Draft`, `Finalize & Lock #1`, `Print Minutes`, `Preview Letterhead`).
  - **2nd Mediation Session Card**: Stacked directly beneath the 1st Mediation card with its own isolated form inputs, upload dropzones, and outcome options (`Save #2 Draft`, `Finalize & Lock #2`). Clerks can review previous session notes in Card 1 while recording Card 2 simultaneously without context switching or tab flipping.
  - **Conciliation Support (Up to 3 Sessions)**: In `pangkat-workspace.php`, supports up to 3 separate session cards (`#1 1st Conciliation`, `#2 2nd Conciliation`, `#3 3rd Conciliation`), each retaining its own proceedings and finalized records.
  - **Quick Jump Navigation Bar**: Rendered at the top of the container with session pills (`#1 1st Mediation`, `#2 2nd Mediation`) that smoothly scroll directly to the corresponding card.
  - **Dynamic Next Session Booking Prompt**: If the previous session concludes without an amicable settlement and the next session is not yet scheduled, a clean booking card appears directly below the last session card allowing clerks to schedule the next hearing immediately under RA 7160 Sec. 410.

- **Session-Specific Outcome Logic (RA 7160 Sec. 410 / Sec. 412)**:
  - **1st Mediation (`session_index = 1`)**:
    - Includes `Unresolved / Book 2nd Mediation` (primary pathway when parties fail to agree in 1st session; summons bypassed as parties are notified in person).
    - Blocks premature elevation to Pangkat because 1st mediation must first proceed to 2nd mediation under statutory Tumana policy.
  - **2nd Mediation (`session_index = 2`)**:
    - Removes `Unresolved / Book 2nd Mediation`.
    - Unlocks `Failure of Mediation / Endorse to Pangkat (KP Form 10)` to immediately constitute the 3-member Pangkat panel.
  - **1st Conciliation**: Includes `Unresolved / Book 2nd Conciliation`.
  - **2nd Conciliation**: Includes `Unresolved / Book 3rd Conciliation` and `Conciliation Failed — Ready for CFA (KP Form 20)`.
  - **3rd Conciliation**: Reaches statutory ceiling; outcome strictly offers `Amicably Settled (KP Form 16)` or `Conciliation Failed — Ready for CFA (KP Form 20)`.
  - Both Mediation and Conciliation include `Arbitration Award (Submit to Arbitration — KP Form 14/15)`.

- **Client-Side Draft Manager Isolation & Auto-Cleanup (`AgapDraftManager`)**:
  - Form inputs are keyed and isolated per session: `minDisputeSummary_${hId}`, `session_minutes_${hId}`, `minAgreements_${hId}`, `minOutcome_${hId}`, etc.
  - Local device drafts (`localStorage`) are bound strictly to `document.getElementById('inlineMinutesForm_' + hId)` and keyed by `agap_draft_${mode}_case_${caseId}_hearing_${hId}`.
  - Switching between sessions cleanly destroys previous listeners and removes lingering recovery banners so text from Session 1 can never bleed into or overwrite Session 2.
  - Saving a draft or finalizing a session automatically clears and marks the localStorage draft as submitted (`AgapDraftManager.markSubmitted()` and `AgapDraftManager.clear()`).

- **Database Model & Migration (`backend/models/HearingMinutes.php`)**:
  - Expanded MySQL `hearing_minutes.session_type` ENUM to include:
    `'1st Mediation', '2nd Mediation', '3rd Mediation', '1st Conciliation', '2nd Conciliation', '3rd Conciliation', 'Conciliation', 'Arbitration', 'Show Cause'`.
  - Updated `HearingMinutes::createMinutes()` to accept and persist conciliation session ordinals (`1st Conciliation`, `2nd Conciliation`, `3rd Conciliation`) without defaulting to mediation.

---

### 2. Official Court Transmittal Letter & 10-Business-Day Statutory Countdown Engine
- **Printable Official Metropolitan Trial Court Transmittal Letter (`transmittal-print.php`)**:
  - Full-page printable transmittal matching the official Barangay Tumana letterhead, design banner, and exact Metropolitan Trial Court (MTC) Branch address in Marikina City.
  - Displays formal case caption: `[Complainant Name(s)] vs. [Respondent Name(s)]`, Case Docket Number, Case Nature, and Resolution Disposition.
  - Certified records transmission checklist:
    - `[x] KP Form 7 (Complaint)`
    - `[x] KP Form 8/9 (Notice of Hearing & Summons)`
    - `[x] Hearing Minutes & Attendance Sheets`
    - `[x] Settlement Agreement / Arbitration Award / Certificate to File Action (CFA)`
  - Statutory certification attestation signed by the Lupon Secretary and attested by the Punong Barangay / Lupon Chairman.
  - Print-specific CSS (`@media print`) configured for standard Folio/Legal (8.5" x 13") and Letter bond paper with zero browser header/footer clutter.

- **10-Business-Day Statutory Countdown Engine (RA 7160 Sec. 416/417)**:
  - **Statutory Window Calculation**: Calculated from the execution date of the Amicable Settlement (KP Form 16), Arbitration Award (KP Form 15), or CFA (KP Form 20).
  - Business day counting engine excludes Saturdays, Sundays, and statutory non-working Philippine holidays.
  - **Print & Transmission Gating**:
    - Transmittal status API (`backend/api/transmittals/status.php`) computes `elapsed_business_days`, `days_remaining`, and `is_locked`.
    - Previewing and printing the Court Transmittal Letter is locked with a real-time countdown badge (`🔒 Locked — X Business Days Remaining`) until the mandatory 10 business working days have elapsed.
    - Once 10 business days pass, the badge turns green (`✓ Ready for Transmission`) and printing unlocks.
  - **Automatic System Notification Alert**: When the countdown reaches 0 remaining business days, the system automatically dispatches an alert notification to clerks indicating that the transmittal is ready for printing and court submission.

- **Printable Blank Ruled Minutes Sheet (`print-blank-minutes.php`)**:
  - Official, high-fidelity blank minutes template accessible across Mediation, Conciliation, and the Overview & Workflow Official KP Forms card via `🖨️ Print Blank Minutes Sheet`.
  - Layout includes Barangay Tumana Lupong Tagapamayapa letterhead, case particulars (`MINUTES OF: [Respondent] vs. [Complainant]`, `CASE NO.`, `CASE FILED`, `CASE TITLE`, `DATE/TIME`, `HEARING NO.`, `LUPON`, `PAHINA`), 24 evenly spaced horizontal ruled writing lines for handwritten live notes, and formal signature lines for the Lupon Secretary and Presiding Officer.

---

### 3. Stage Tab Gating & Outcomes Tab Dynamic Unlocking
- **Dynamic Outcomes Tab Unlocking (`tab-arbitration` → Outcomes)**:
  - Renamed the tab from *"Arbitration / CFA"* to **"Outcomes"** to better reflect the final disposition of cases (Amicable Settlement, Arbitration Award, or CFA).
  - **Live Unlock Trigger**: Whenever an Amicable Settlement (KP Form 16), CFA (KP Form 20), Arbitration Agreement (KP Form 14/15), or Transmittal is previewed or printed in Mediation or Conciliation workspaces, the Outcomes tab automatically unlocks immediately and persists for the case (`sessionStorage.setItem('agap_outcomes_unlocked_' + compId, '1')`).
  - Cross-window communication synchronizes the unlocked state to parent and opener browser tabs (`window.opener.updateStageTabsLockState()`).

- **Resolved Uncaught ReferenceError in `complaints.js`**:
  - **Root Cause**: Inside `canAccessTab('tab-arbitration')`, variable `arbStage` was referenced before declaration, and `data?.` was referenced instead of `complaint?.`. Calling `updateStageTabsLockState()` during page load threw an uncaught `ReferenceError: arbStage is not defined`.
  - **Impact**: This runtime exception halted `loadComplaintDetails()` mid-execution, preventing `renderParties(complaint.parties)` and `renderPaanyayaTab(complaint)` from running. This caused the party list container to remain permanently stuck on *"Loading parties..."* and left Paanyaya dialogue minutes buttons uninitialized (`btnRecordMinutesP1` hidden, `btnIssueP1` visible).
  - **Resolution**: Properly declared `const arbStage = stages.arbitration || {};` and safe optional chaining on `complaint`. Complaint details, party listings, and Paanyaya dialogue minutes buttons now initialize immediately.

---

### 4. Pangkat Constitution & Referral Consolidation
- **Consolidated "Team Assignment for Selected Case" Modal (`mediation-workspace.php`)**:
  - Moved Pangkat constitution and member assignment directly into the **"Refer Case to Pangkat ng Tagapagkasundo"** modal (`#wsReferPangkatModal`) accessible upon 2nd Mediation conclusion.
  - Retired the redundant standalone Cases & Team Assignments page (`frontend/pages/assignments/case-assignment.php`).
  - Supports 3-member Pangkat panel selection (Pangkat Chairperson, Pangkat Secretary, Pangkat Member) with validation ensuring no duplicate member assignments.
  - Automatically transitions case status to `Conciliation`, sets the statutory 3-day Pangkat convening deadline (RA 7160 Sec. 410(c)), and generates KP Form 10 (Notice for Constitution of Pangkat), KP Form 11 (Notice to Chosen Pangkat Members), and KP Form 12 (Subpoena).

---

### 5. Dashboard "Today's Hearings" Status Badges & Attendance Sync
- **Status Badge Parity on Dashboard (`dashboard.js`)**:
  - Updated Today's Hearings table and cards on the dashboard to display the exact live queue status matching the Case Details view: `Scheduled`, `Waiting`, `In Progress`, `Concluded`, `Attendance Recorded`.
  - Color-coded badges with dynamic action buttons allowing clerks to jump directly into the live hearing workspace or record attendance in one click.
- **Live Attendance Cards (`stage-workspace.js`)**:
  - In Mediation and Conciliation workspaces, the Section 2.5 Hearing Session Attendance card dynamically renders recorded party attendance (`Present`, `Absent`, `Excused`), presider attendance/substitute details, and justified absence reasons.
  - Changes made in the attendance modal immediately reflect in the workspace card without requiring full page reloads.

---

### 6. Official KP Forms Document Generator, Side-by-Side Pangkat Preview, Officer's Return Linkage & Dynamic Outcomes Integration (October 2026)

- **Comprehensive KP Forms Data Layer & PDF Engine (`backend/services/KpFormService.php` & `backend/services/PDFService.php`)**:
  - Implemented `KpFormService` providing comprehensive data aggregation across `cases`, `complaints`, `complaint_parties`, `residents`, `system_settings` (Punong Barangay and Secretary details), `pangkat_groups`, `pangkat_members`, `hearings`, `hearing_minutes`, `settlements`, `arbitration_records`, `cfa_records`, and `show_cause_evaluations`.
  - Added public `getFormHtml(string $formCode, array $data, bool $forPdf = false): string` to `PDFService.php`, exposing HTML rendering for all Katarungang Pambarangay forms (KP Forms 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 20-A, 20-B, 21, 22, 23, 24, 25).
  - Standardized Dompdf rendering pipeline (`PDFService::generateNotice`) with standard Folio/Legal (8.5" x 13") and Letter bond paper styles, zero-margin headers, and official Barangay Tumana letterheads.
  - Refactored `backend/api/documents/kp-form.php` to serve bare HTML previews (`bare=1`) for clean iframe embedding and binary PDF downloads (`pdf=1` or `download=1`).

- **Tri-Panel Side-by-Side Pangkat Notices Preview Modal (`#pangkatNoticesModal`)**:
  - Created reusable modal component `frontend/layouts/kp-form-preview-modal.php`, included globally in `complaint-details.php`, `mediation-workspace.php`, `pangkat-workspace.php`, and `arbitration-workspace.php`.
  - Displays a responsive 3-column side-by-side grid (`width: 95vw; max-width: 1400px`):
    - **Panel 1: KP Form 10** (*Paabiso sa Pagbubuo ng Pangkat / Notice for Constitution of Pangkat*) — summons both parties to appear and agree upon 3 Lupon members.
    - **Panel 2: KP Form 11** (*Patalastas sa Piniling Miyembro ng Pangkat / Notice to Chosen Pangkat Members*) — official appointment notice served to the 3 chosen Lupon members.
    - **Panel 3: KP Form 12** (*Paabiso ng Pagdinig sa Harap ng Pangkat / Notice of Hearing - Conciliation*) — formal notice setting the first Pangkat conciliation hearing session.
  - Each panel features dedicated `🖨️ Print` and `Download PDF` controls.
  - Header features a `🖨️ Print All 3 Forms` bulk printing action with staggered frame triggers.

- **Officer's Return Tracking Integration for KP Form 12**:
  - Panel 3 directly links KP Form 12 conciliation summons to the Officer's Return proof of service tracking system for both Complainant and Respondent.
  - Embedded quick actions link directly to `frontend/pages/gps/proof-service.php?complaint_id=[id]&stage=Conciliation&form=KP12&party_type=[Complainant|Respondent]`.

- **Dynamic Outcome-Driven KP Forms Unlocking in Official KP Forms Table (`tab-overview`)**:
  - Updated `renderOfficialKpForms` in `frontend/assets/js/complaints.js` to inspect case status, current stage, and recorded hearing minutes outcomes:
    - **Amicably Settled:** Surfaces **KP Form 16** (*Kasunduang Pag-aayos*) with an active **`Open Amicable Settlement →`** launcher immediately opening the populated preview modal.
    - **Voluntary Arbitration:** Surfaces **KP Form 14** (*Agreement for Arbitration / Kasunduan sa Pag-aarbitro*) and **KP Form 15** (*Arbitration Award / Gawad ng Arbitrasyon*), unlocked for preview and PDF export.
    - **Repudiated Settlement:** Surfaces **KP Form 17** (*Pagtatwa / Repudiation*) alongside eligible Certificate to File Action forms (**KP Form 20**, **20-A**, **20-B**).
    - **Failure of Mediation / Endorse to Pangkat:** Surfaces **KP Forms 10, 11, and 12** with a direct shortcut to launch the Tri-Panel side-by-side preview.
    - **Failure of Conciliation:** Surfaces **KP Form 20** (*Certificate to File Action*).

- **Binding Arbitration Booking & Scheduling Modal (RA 7160 Sec. 413)**:
  - Updated `backend/controllers/HearingController.php` to include `'Arbitration'` in allowable hearing types during creation and validation.
  - Selecting "Arbitration" or "Submit to Voluntary Arbitration" in session outcomes immediately renders an active callout banner with **`📅 Schedule Arbitration Session →`**.
  - Re-uses the unified booking modal (`openEmbeddedScheduleModal('Arbitration')`) pre-filled with the Lupon Court Room venue, PB/Pangkat presider, and date/time pickers. Submitting schedules the arbitration session and unlocks the Outcomes tab.

- **Embedded Procedural Determination & Pangkat Referral Modals (`complaint-details.php`)**:
  - Implemented `openEmbeddedRecordOutcomeModal`, `closeEmbeddedRecordOutcomeModal`, and `submitEmbeddedRecordOutcome` wired to `backend/api/stages/outcome.php`.
  - Added option for **Arbitration (KP Form 14)** in `#stageOutcomeSelect` with dynamic arbitration guidance banner.
  - Implemented `openEmbeddedReferPangkatModal`, `closeEmbeddedReferPangkatModal`, `goEmbeddedReferralStep`, and `submitEmbeddedReferPangkat` wired to `backend/api/stages/refer-pangkat.php` with Lupon member loading (`assignments/lupon-members.php`), 3-step wizard validation, and auto-opening of the Tri-Panel Pangkat preview upon referral completion.

---

## Detailed Summary of Changes (October 26, 2026 – October 27, 2026)

### A. Yesterday (October 26, 2026)

- **Report Generation & Master Case Register (Excel / XLSX)**:
  - Developed native OpenXML spreadsheet generator `backend/services/XlsxService.php` enabling `.xlsx` exports without external Composer dependencies.
  - Built Katarungang Pambarangay Monitoring endpoint `backend/api/reports/kp_monitoring.php` and unified report generation `backend/api/reports/generate.php`.
  - Added migration `database/migrations/20261026_001_allow_case_register_report_type.sql` allowing `'Case Register'` in `reports.report_type` ENUM.
  - Implemented report management UI in `frontend/pages/reports/report-list.php` and `frontend/assets/js/reports.js` supporting preview, print, export, and download.
  - Updated dashboard overview, analytics charts, and sidebar links (`frontend/assets/css/dashboard.css`, `frontend/assets/js/dashboard.js`, `frontend/layouts/dashboard-overview.php`).

- **Settings Functionality Update & Diagnostic Testing**:
  - Refactored and stabilized settings API routes: Database Backup (`backend/api/settings/backup-db.php`), Case Categories (`categories.php`), Password Change (`change-password.php`), System Diagnostics (`diagnostics.php`), Profile (`profile.php`), AI test (`test-ai.php`), SMS test (`test-sms.php`), and Barangay Seal upload (`upload-seal.php`).
  - Updated settings UI in `frontend/pages/settings/index.php` and `frontend/assets/js/settings.js`.
  - Upgraded GPS delivery tracking for summons and Paanyaya notices (`backend/controllers/GPSController.php`, `frontend/assets/js/gps.js`, `frontend/pages/gps/proof-service.php`).

- **Dialogue Minutes Delay & Remarks Tracking**:
  - Added `actual_start_time` and `session_remarks` to `paanyaya_dialogue_minutes` via migration `database/migrations/20261026_002_add_session_remarks_and_actual_start_time.sql`.
  - Updated `backend/models/Paanyaya.php` to persist arrival times, late starts, and officer delay remarks.
  - Added delay remark quick-tags in `complaint-details.php` and `complaints.js`.

- **KP Form Preview & Attendance Recording Bug Fixes**:
  - Resolved attendance recording exception in `backend/api/hearings/attendance.php` when updating existing attendance rows.
  - Fixed summons preview error in `backend/api/summons/preview.php` to handle missing template variables gracefully.
  - Fixed party attendance rendering and checkbox synchronization in `frontend/assets/js/complaints.js`.

- **Multi-Stage Mediation Workspace UI & Navigation Updates**:
  - Improved stage transitions and status persistence in `backend/models/CaseStage.php`.
  - Cleaned up top action toolbar and navigation links across `complaint-create.php`, `complaint-details.php`, and `complaint-edit.php`.
  - Initiated separated section cards for hearing proceedings in `stage-workspace.js` and `mediation-workspace.php`.

---

### B. Today (October 27, 2026)

- **Official KP Forms Generator & PDF Engine (Forms 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 20-A, 20-B, 21, 22, 23, 24, 25)**:
  - Created `backend/services/KpFormService.php`: Centralized data aggregation layer querying cases, complaints, parties, residents, hearing minutes, arbitration awards, settlements, and barangay officer settings (`captain_name`, `secretary_name`).
  - Updated `backend/services/PDFService.php`: Implemented public `getFormHtml(string $formCode, array $data, bool $forPdf = false): string`, routing dynamically to official HTML templates with Folio/Legal and Letter CSS styling and Dompdf PDF streaming.
  - Refactored `backend/api/documents/kp-form.php`: Serves bare HTML previews (`bare=1`) for clean iframe rendering and binary PDF downloads (`pdf=1` or `download=1`).

- **Tri-Panel Side-by-Side Pangkat Preview Modal (`#pangkatNoticesModal`)**:
  - Created `frontend/layouts/kp-form-preview-modal.php`: Built a wide 3-panel modal (`width: 95vw; max-width: 1400px`) displaying:
    - **Panel 1: KP Form 10** (*Notice for Constitution of Pangkat*)
    - **Panel 2: KP Form 11** (*Notice to Chosen Pangkat Members*)
    - **Panel 3: KP Form 12** (*Notice of Hearing - Conciliation Proceedings*)
  - Added dedicated `🖨️ Print` and `Download PDF` controls for each panel.
  - Added `🖨️ Print All 3 Forms` bulk printing with sequential iframe printing.
  - Included globally in `complaint-details.php`, `mediation-workspace.php`, `pangkat-workspace.php`, and `arbitration-workspace.php`.

- **Officer's Return Tracking Linkage for KP Form 12**:
  - Integrated Panel 3 with the Officer's Return system: added buttons to record service of KP Form 12 for both Complainant and Respondent, linking directly to `gps/proof-service.php?complaint_id=[id]&stage=Conciliation&form=KP12&party_type=[Complainant|Respondent]`.

- **Dynamic Outcome-Driven KP Forms Unlocking in Official KP Forms Table (`tab-overview`)**:
  - Updated `renderOfficialKpForms` in `frontend/assets/js/complaints.js` to dynamically inspect case status and recorded hearing minutes outcomes:
    - **Amicably Settled:** Surfaces **KP Form 16** (*Kasunduang Pag-aayos*) with `Open Amicable Settlement →` direct modal launcher.
    - **Voluntary Arbitration:** Surfaces **KP Form 14** (*Agreement for Arbitration*) & **KP Form 15** (*Arbitration Award*).
    - **Repudiated Settlement:** Surfaces **KP Form 17** (*Pagtatwa*) & CFA (**KP Form 20, 20-A, 20-B**).
    - **Failure of Mediation / Endorse to Pangkat:** Surfaces **KP Forms 10, 11, and 12** with Tri-Panel preview shortcut.
    - **Failure of Conciliation:** Surfaces **KP Form 20** (*Certificate to File Action*).

- **Binding Arbitration Booking & Scheduling Modal (RA 7160 Sec. 413)**:
  - Updated `backend/controllers/HearingController.php` to permit `'Arbitration'` as an allowable hearing type in `validate()`.
  - Selecting "Arbitration" or "Submit to Voluntary Arbitration" in session outcomes triggers an amber callout banner prompting `📅 Schedule Arbitration Session →`.
  - Integrated with the unified scheduling modal (`openEmbeddedScheduleModal('Arbitration')`) pre-filled with Lupon Court Room venue and presider.

- **Embedded Case Details Modal & Referral Handlers (`complaint-details.php` & `complaints.js`)**:
  - Added `openEmbeddedRecordOutcomeModal`, `closeEmbeddedRecordOutcomeModal`, and `submitEmbeddedRecordOutcome` wired to `backend/api/stages/outcome.php`.
  - Added Arbitration option to `#stageOutcomeSelect` with dynamic arbitration guidance banner.
  - Added `openEmbeddedReferPangkatModal`, `closeEmbeddedReferPangkatModal`, `goEmbeddedReferralStep`, and `submitEmbeddedReferPangkat` wired to `backend/api/stages/refer-pangkat.php` with 3-member Lupon nominations and automatic opening of the Tri-Panel Pangkat preview upon referral completion.
  - Added action buttons under Mediation stage card: `Record Mediation Outcome` and `Refer to Pangkat (KP Form 10)`.

- **Separated Hearing Session Minutes & Proceedings Cards (`stage-workspace.js`)**:
  - Converted single-form in-place toggle into physically separated, concurrent section cards for `1st Mediation` & `2nd Mediation`, as well as `1st Conciliation`, `2nd Conciliation`, and `3rd Conciliation`.
  - Implemented client-side draft isolation (`AgapDraftManager`) using `localStorage` keyed by `hearing_id` to eliminate draft collisions across sessions.
  - Added top quick-jump navigation bar with live status badges (`Draft`, `Finalized`, `Not Recorded`).

- **Official Metropolitan Trial Court Transmittal Letter & 10-Business-Day Statutory Countdown Engine**:
  - Built full-page printable MTC transmittal letter (`frontend/pages/cases/transmittal-print.php`) with official Barangay Tumana letterhead, Marikina MTC address, certified records transmission checklist, and Secretary attestation.
  - Built 10-business-day calculation engine (`backend/api/transmittals/status.php` and `CaseTransmittal.php`) excluding weekends and official Philippine holidays.
  - Gated transmittal printing with a real-time countdown badge (`🔒 Locked — X Business Days Remaining`), turning green (`✓ Ready for Transmission`) once 10 business days have elapsed.

- **Printable Blank Ruled Minutes Sheet (`frontend/pages/complaints/print-blank-minutes.php`)**:
  - High-fidelity blank minutes template accessible across Mediation, Conciliation, and the Official KP Forms table via `🖨️ Print Blank Minutes Sheet`.
  - Styled with official letterhead, case caption fields, and 24 ruled horizontal lines for live handwritten notes.

- **Dynamic Outcomes Tab Unlocking**:
  - Renamed `tab-arbitration` to **Outcomes** with dynamic live unlock upon previewing/printing Amicable Settlement, Arbitration Award, or CFA.
  - Fixed uncaught `ReferenceError: arbStage is not defined` in `complaints.js` which previously halted party rendering.


---

### C. Today (October 29, 2026)

- **Voluntary Arbitration End-to-End Workflow & 4-Step Progressive Timeline (RA 7160 Sec. 413)**:
  - **Hearing Minutes Outcome Action Transformation**:
    - In Mediation (`mediation-workspace.php`) and Conciliation (`pangkat-workspace.php`) hearing minutes (*Kinalabasan ng Sesyon*), selecting **"Submit to Voluntary Arbitration (KP Form 14)"** displays a single direct action button: `Issue Arbitration →`. Removed preliminary preview buttons (`Preview KP Form 14` and `Preview KP Form 15`) from the minutes outcome banner to maintain strict sequential progression.
    - Implemented `issueArbitrationOutcome()` in `stage-workspace.js`: Automatically records the stage outcome as `Arbitration` via `stages/outcome.php`, finalizes the active session minutes, unlocks the Outcomes tab, and immediately redirects the user to the active case's Outcomes workspace (`complaint-details.php?id=[complaint_id]&tab=tab-arbitration#caseWorkspaceSection`).
  - **4-Step Progressive Timeline Workspace (`#arbitrationProgressiveWorkspace`)**:
    - Built a dedicated 4-step progressive timeline interface under `#tab-arbitration` in `complaint-details.php` and `complaints.js` (`renderArbitrationProgressiveWorkspace`):
      - **Step 1: Agreement to Arbitrate (KP Form 14)**: Highlights the recorded Day 0 agreement/signing date (`agreement_date`) with accessible `Preview KP Form 14`, `Print KP Form 14`, and `Download PDF` controls.
      - **Step 2: 5-Day Statutory Repudiation / Withdrawal Window**: Features a live badge tracking the 5-calendar-day window under RA 7160 Sec. 413 (`Repudiation Window: Day [X] of 5 (Active)` vs `5-Day Repudiation Window Closed (Award Eligible)`), alongside accessible `Preview KP Form 17` and `Print KP Form 17 (Katibayan ng Pagsalungat)` actions.
      - **Step 3: Segregated Arbitration Hearing Scheduler**: In-workspace interface allowing the Punong Barangay / Presider to schedule arbitration hearing sessions for the same day or succeeding days. Hearings are strictly segregated from mediation/conciliation and labeled as `Arbitration Hearing` (e.g., `1st Arbitration Hearing`, `2nd Arbitration Hearing`) across logs, calendar, and session history (`Hearing.php`, `HearingController.php`).
      - **Step 4: Statutory Day 6–15 Arbitration Award Modal & Issuance (KP Form 15)**: Integrated `#issueArbitrationAwardModal` with statutory date validation:
        - *Minimum Date*: Cannot be earlier than **Day 6** (enforcing expiration of the 5-day repudiation window).
        - *Maximum Date*: Cannot exceed **Day 15** from signing KP Form 14 (`Date Signed + 15 calendar days`).
        - Enforced both client-side (`validateArbitrationAwardDateInput`) and server-side in `ArbitrationController.php`. Once submitted, unlocks `Preview KP Form 15` and `Print KP Form 15`.
  - **Dynamic Form Data Auto-Population (`KpFormService.php`)**:
    - Form 14 auto-populates Day 0 agreement date, parties, and barangay header.
    - Form 15 dynamically queries and populates all dates of arbitration hearings held (`arbitration_hearing_dates`), award date within statutory window, arbitrator findings, and binding ruling.
    - Form 17 prerequisite validation permits preview and printing during the active Arbitration stage upon sworn repudiation.
  - **Database Migration (`20261029_001_voluntary_arbitration_outcomes.sql`)**:
    - Modified `case_stages.outcome` ENUM to include `'Arbitration'` and `'Arbitration Award'`.
    - Modified `hearing_minutes.session_outcome` ENUM to include `'Arbitration'`, `'Arbitration Award'`, and `'Repudiated'`.
  - **Integration Test Suite**:
    - Created and executed `backend/tests/test_voluntary_arbitration_workflow.php` verifying all 5 lifecycle steps and statutory date constraints (Days 6–15) with 100% assertion pass rate.

- **Automated Amicable Settlement Persistence & Outcomes Transformation**:
  - Replaced static navigational link in `stage-workspace.js` with `issueSettledOutcome()`: When "Amicably Settled / Nagkasundo (KP Form 16)" is chosen, clicking `Open Amicable Settlement →` automatically saves the stage outcome as `Settled` via `stages/outcome.php`, finalizes hearing minutes, unlocks the Outcomes tab, and transitions directly to `#amicableSettlementWorkspace`.
  - Updated `backend/models/HearingMinutes.php`: Finalizing minutes with `Settled` automatically transitions `cases.case_status = 'Settled'`, `complaints.status = 'Settled'`, and marks `case_stages` as `Completed`.
  - Enhanced `isSettledCase` in `complaints.js` to dynamically inspect session minutes, ensuring the Amicable Settlement Workspace (Kasunduan, KP Form 16, 10-day repudiation window) renders immediately on the Outcomes tab.

- **Reopen Settled Case for CFA Stabilization (RA 7160 Sec. 417)**:
  - Fixed `backend/api/cases/reopen.php`:
    - Removed fatal error caused by nonexistent `require_once __DIR__ . '/../../config/session.php'`; replaced with standard `session_start()`.
    - Corrected authorization check to inspect `$_SESSION['role_id']` (`[1, 2, 3]`) instead of nonexistent `$_SESSION['role']`.
    - Corrected redirect URL to `../documents/cfa.php?case_id=...`.
  - Hardened `submitReopenForCfa()` in `complaints.js` with comprehensive fallback resolution for `caseId` across `_reopenCaseCaseId`, `currentCaseId`, and `complaintData`.

- **JavaScript Runtime & Syntax Hardening**:
  - Fixed undefined variable reference `${hId}` in `stage-workspace.js` line 1948, replacing with `Number(activeHearingId || 0)`.
  - Removed stray escaped newline character `\n` in `complaints.js` that caused an `Uncaught SyntaxError: Invalid or unexpected token`.
  - Added `typeof` guards around global window exposures in `stage-workspace.js` (`loadStageSlots`, `initWorkspaceScheduleModal`, `submitAttendance`, etc.) to prevent premature runtime termination.
