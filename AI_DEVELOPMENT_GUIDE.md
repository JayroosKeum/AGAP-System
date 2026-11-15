# AGAP AI Development Guide

Use this guide when changing AGAP. Start with the [project README](README.md) for local setup, the [database guide](database/README.md) for migrations, and the [deployment checklist](DEPLOYMENT.md) for production.

## Start with the existing workflow

- Identify the user action, the record that owns its state, and the endpoint that changes it. Follow that path before adding a page, table, or status.
- Keep staff actions on the original complaint when they can continue there. Use the existing role queues as entry points, including the clerk's preparation queue and the summons server's Proof of Service page.
- Preserve existing entry points and data. Check whether a record is using the current preparation workflow or an older Paanyaya path before changing shared code.
- Keep changes scoped to the requested feature. Preserve local edits and push only when the user explicitly asks.

## Resident intake and Paanyaya

| Step | Current behavior |
| --- | --- |
| Resident intake | Residents accept the user agreement, register, and submit a concern from the resident portal. Incident dates and times cannot be in the future (enforced on both client and server). The dashboard shows submissions owned by the signed-in account. |
| Walk-in intake | A clerk or administrator records the complaint, starting with the narrative, validates that the incident date and time are not in the future, and selects an on-duty Lupon Member for screening. LISO duty is an assignment of a Lupon Member. |
| Screening | The assigned Lupon Member (`role_id = 3`) opens the intake in a clean screening view and records the screening decision. Marking an intake `Eligible` is single-click/idempotent (`COALESCE(screening_decision, '') <> 'Eligible'`) and replaces the form with a read-only completion banner. For an online intake, the member can send a resident-facing information request. The complaint then waits for the resident's reply before it can be screened again. Eligible undocketed intakes enter the **Paanyaya** stage first and appear in the clerk's Paanyaya queue. |
| Resident reply | The resident opens the complaint from the dashboard, reads the request, and submits one response for the latest unanswered request. The complaint returns to the assigned Lupon Member's screening queue and displays the complete request and response history. Staff review notes remain private. Ownership, CSRF, duplicate-response, and stale-request checks are enforced on the server. |
| Preparation | On the original complaint, the clerk or administrator verifies the parties, enters the appointment and venue, generates and reviews the PDF, and marks it Ready for Release. While in Paanyaya, KP Form 7 remains on hold (`Pending Paanyaya Outcome`) and post-docket tabs (`Mediation`, `Conciliation`, `Outcomes`) remain locked. |
| Release and service | The clerk or administrator assigns an active summons server. The server records delivery in **Proof of Service → My Paanyaya Assignments**. Unsuccessful delivery requires a reason and allows a retry. The complaint displays the result and any proof. |
| Dialogue | Confirmed delivery of that invitation enables the clerk or administrator to save attendance, the actual dialogue date and time, minutes, and the outcome on the complaint. Settlement requires agreed terms and resolves the complaint at Paanyaya without creating a formal case or KP Form 7. |
| Next action | A request for a second Paanyaya enables a separate invitation on the same complaint with its own PDF, service history, and dialogue. Formal complaint docketing (`cases`), KP Form 7 generation, and the `Mediation` stage are dynamically unlocked **only** when Paanyaya concludes without resolution (`Escalate_Mediation`). |
| Resident timeline | An online filer can open a complaint detail page showing information requests and replies, screening decisions, confirmed Paanyaya appointments, formal docketing, and recorded final outcomes. Pending information requests link directly to the reply form. The timeline uses resident-safe labels and does not expose internal screening notes, dialogue minutes, or case-history remarks. |

The clerk's Paanyaya next-action queue covers eligible undocketed non-draft intakes (`Walk-in` or `Online`), including records already progressing through Paanyaya. It identifies the next staff action and links to the matching complaint section: invitation preparation or review, delivery assignment or retry, dialogue minutes, second invitation, or formal docketing. Items waiting only for the summons server remain visible with an awaiting-delivery state. Completed settlements and docketed complaints leave the queue.

The current workflow supports two invitations. Delivery retries are recorded against the same invitation. Authorization, allowed outcomes, and stage gates are explicit, database-driven server-side rules. Parties, duty status, assignment, dates, venue, proof, and minutes come from saved records or staff input.

## Authoritative records

| Fact | Where to read it |
| --- | --- |
| Resident account link and submission ownership | `resident_user_profiles` and `complaints.submitted_by` |
| Intake, parties, screening, and duty | `complaints`, `complaint_parties`, `residents`, `complaint_intake_screenings`, and `liso_duty_sessions` |
| Resident information requests and replies | `complaint_information_requests`, scoped to the complaint and ordered by `request_id` |
| Prepared invitation and delivery result | `paanyaya_preparations`, `paanyaya_invitation_versions`, and `paanyaya_service_attempts`, linked by complaint, invitation number, and document version |
| Dialogue schedule, attendance, and outcome | `paanyaya_stages`, `paanyaya_sessions`, and `paanyaya_minutes`. The `paanyayas` register reflects the scheduled invitation and progression. |
| Formal case, stage, hearing, and service | `cases.case_status`, `case_stages`, `hearings`, `hearing_attendance`, `hearing_minutes`, and `summon_deliveries` |
| Mediation commencement and procedural deadline | `mediation_commencements.first_actual_meeting_at` and `mediation_deadline_at`, coordinated by `MediationCommencementService` and `MediationDeadlineService` |
| Show-cause findings and CFA issuance | `show_cause_evaluations` links the hearing, resident, and notice delivery. `CFA::determineCfaEligibility()` evaluates the saved evidence and `cfa_records` records issuance. |
| Daily docket call order and schedule rules | `hearings.queue_order`, `paanyayas.queue_order`, `paanyaya_sessions.queue_order`, `system_settings`, `scheduling_holidays`, and `venue_unavailabilities` via `DocketScheduleService` and `OfficeLogisticsService` |
| Formal documents and history | `document_templates`, `generated_documents`, `case_history`, and `audit_trails` |

Use the saved state for action availability and dashboard counts. Confirm service before opening dialogue for the prepared invitation. Use explicit authorized actions for formal case transitions, including Pangkat referral and CFA issuance. Verify scheduling rules, KP form wording, and statutory deadlines with the project's policy owner before changing them.

## Daily docket ordering and rescheduling

The Administrator dashboard (`frontend/assets/js/dashboard.js`) and Clerk Agenda Stream (`frontend/assets/js/clerk-dashboard.js`) share one frontend controller (`frontend/assets/js/docket-drag-schedule.js`, `window.AgapDocketDragSchedule`) and one backend scheduling service (`backend/services/DocketScheduleService.php`, exposed through `backend/api/hearings/queue.php` and `backend/api/hearings/check-availability.php`). Queue reordering and formal appointment rescheduling are strictly separated:

- **Same-day queue reordering (`action = reorder_queue`)**:
  - Changes call sequence (`hearings.queue_order`, `paanyayas.queue_order`, and `paanyaya_sessions.queue_order`) **only**.
  - Never recalculates or alters official appointment times (`hearings.hearing_date`, `hearings.end_time`, `paanyayas.scheduled_time`, `paanyaya_sessions.scheduled_time`, `paanyaya_preparations.appointment_at`, or `paanyaya_service_attempts.appointment_at`) or served notices.
  - Uses the active view's selected single docket date (`queue_date`). Reordering is disabled in multi-day (`weekly`, `monthly`, `quarterly`, `annual`), filtered, or paginated views unless the complete affected day is loaded.
  - Cancelled drags (`Escape` or invalid drop targets) and unchanged sequences (`no_op = true`) make no database or audit changes. Failed requests restore the pre-drag DOM order, sequence badges, and time pills.
- **Formal appointment rescheduling (`action = reschedule_item`, `action = check_slots`)**:
  - Triggered by dropping a docket card onto another calendar date (`[data-date]` in `#dashboardCalendarGrid`) or activating the card's accessible **Reschedule** control. Dropping a card onto the calendar never saves or infers an appointment silently; it restores the card in the current list and opens the confirmation modal.
  - Fetches valid time slots, venues, presiding officers, and rule explanations from `DocketScheduleService::getAvailableSlots`, derived dynamically from `system_settings`, `scheduling_holidays`, `venue_unavailabilities`, `OfficeLogisticsService`, and `Hearing::findConflict`.
  - Displays old vs. proposed Date, Official Time, Call Order (`#`), Venue, and Presiding Officer, and requires both explicit confirmation (`confirmed = true`) and a mandatory reason (`reschedule_reason`).
  - For formal hearings, updates `hearings` and marks previous delivery returns read-only as `Superseded`. Each recipient gets a pending delivery linked to the hearing and its own document. Proof of Service previews and prints the issued details stored in `hearing_reschedule_notices` and records the return against the selected delivery. After actual Mediation commencement, rescheduling preserves the saved procedural deadline.
  - For prepared Paanyaya invitations, rescheduling archives the prior PDF and its schedule and saves a new version for the same invitation number. Staff review and release the revised PDF through the existing complaint workflow. Dialogue and resident appointment confirmation require successful delivery of that version. History retains the appointment, PDF, result, notes, and proof associated with each prior attempt.
- **Server-side eligibility and concurrency (`DocketScheduleService`)**:
  - Only active Administrators and Lupon Clerks (verified against `users` and `roles`) may reorder or reschedule; Lupon Members and unauthenticated/CSRF-invalid requests are rejected (`401`/`403`).
  - Records that are `In_Session`, attendance-recorded (`Attendance Recorded`, `attendance_recorded_at IS NOT NULL`, or party attendance recorded), minutes-recorded, `Concluded`, `Completed`, `Cancelled`, `Office Cancelled`, `Resolved`, `Unresolved`, `Escalated`, `Settled`, or superseded are locked (`422`).
  - Every submitted `paanyaya_sessions.session_id` is verified against the submitted `complaint_id` and `paanyayas.paanyaya_id`.
  - The server checks the submitted expected timestamp, appointment, and queue order inside row-locked transactions (`DocketScheduleService::validateStaleSnapshot()`). It rejects stale, duplicate, cross-date, incomplete, and filtered or paginated payloads. Both dashboards (`docket-drag-schedule.js` + `dashboard.js` + `clerk-dashboard.js`) capture and preserve the pre-drag / modal-open snapshot across slot refreshes, require `require_concurrency_snapshot: true`, restore original order/state on HTTP 409 rejection, and offer a **Reload docket** action.

## Implemented attendance, show cause, and CFA

- Formal attendance and docket check-in use service evidence for the exact recipient and operative hearing notice. A retry on a replacement notice creates a new attempt and retains the failed return and proof. Preserve the distinction among `Not Served`, `Pending Verification`, `Excused`, and verified non-appearance. Apply a prior-notice exception only where the saved basis covers that recipient and hearing.
- The show-cause evaluation endpoint requires a linked show-cause hearing and verifies service of the original and show-cause notices and the recipient's unexcused absence. The bar approval workflow verifies the selected person's evidence and commits the approval, certificate document, case history, and audit together. Valid respondent defaults from the evaluation endpoint use the standard CFA issuance path.
- The Outcomes tab, Proof of Service, preview, and standard CFA issuance share the server eligibility decision. Delivery failures and verified no-shows are counted per respondent and applicable service sequence. Issuance rechecks eligibility under lock. A document failure rolls back a new issuance. Duplicate submissions return the saved issuance and its PDF when available. A client-supplied reopening flag cannot establish eligibility.
- For existing installations, follow the [database migration guide](database/README.md) for the replacement-notice and recipient-specific show-cause upgrades. Older role-only show-cause records remain available in history. New bar approvals and issuance require evidence tied to the named recipient.

## Implemented workflow handoffs

### Order 1 — Paanyaya to first formal summons

- The Clerk Agenda shortcuts verify the complaint, invitation version, session, and service before writing to the prepared-invitation workflow. Staff use the complaint's dialogue form when a shortcut lacks valid dialogue details, minutes, or agreed terms. Legacy records use their existing path.
- A current, valid escalation authorizes docketing once on the original prepared complaint. First-summons preview and issuance require the linked formal case. Proof of Service Stage 0 shows invitations, versioned returns, and dialogue after docketing. Earlier attempts retain their original appointment and result.

### Order 2 — Mediation through Pangkat Conciliation

- The workspace and referral endpoint share a readiness check based on distinct, completed Mediation hearings with finalized minutes and any applicable recorded deadline exception. The required number of qualifying completions and the booking limit serve different purposes. Cases closed for respondent default cannot be referred.
- The explicit referral saves the Pangkat panel and records handoff. When staff book the first Conciliation hearing in that action, referral also saves the hearing and rendered KP Forms 10, 11, and 12. A failed required render rolls back the handoff and removes newly generated files. Retries reuse the saved referral. A deferred first booking remains pending.
- The Mediation clock starts at the first actual meeting. Later bookings, rescheduling, and referral use the saved deadline after commencement. Referral completes open Mediation deadlines, and alerts check that the case is still in Mediation. Existing installations need the [Mediation pause decisions migration](database/migrations/20261115_002_mediation_pause_decisions.sql).

### Order 3A — Staff show-cause decisions

- The Mediation and Conciliation workspaces show each recipient's original hearing, operative show-cause notice and service state, and recorded determination. New notices have a saved PDF for each recipient and preserve the verified service for the original hearing. Legacy findings without a named recipient remain hearing history. The submission rechecks attendance and service under lock and rejects a stale notice selection.
- A determination leaves the hearing open while another recipient has an outstanding absence to evaluate. For an active case, a justified result requires staff to choose a valid replacement appointment. After a case disposition, staff can record an excuse for a remaining recipient on the same matter while preserving the disposition.

### Order 3B — CFA eligibility, issuance, and PDF recovery

- The CFA impasse ground requires finalized minutes from the operative Conciliation hearing. A valid settlement repudiation records eligibility for KP Form 20 through standard issuance. The PDF record, case disposition, history, and audit commit with the issuance.
- Staff can recover a missing CFA PDF from the saved issuance. Recovery preserves its form, date, reason, and case history. New bar certificates store the issued recipient. Recovery requires unambiguous evidence for older bar certificates. Existing databases need the [show-cause and CFA decisions migration](database/migrations/20261115_003_preserve_show_cause_and_cfa_decisions.sql).

## Remaining workflow implementation

Follow the order below when planning the work. Changes to session limits, summons-attempt limits, certificate mappings, or procedural periods require a confirmed policy basis. Continue work on evidence, transitions, and verification using the current rules while any policy discrepancy is resolved.

### Order 4A — Signed settlement and arbitration award

- Keep an agreement recorded in minutes distinct from a signed and attested settlement. Require a recorded written arbitration agreement and its actual signing date before issuing an award. Commit the award, document, stage outcome, and disposition together.

### Order 4A — Signed settlement and arbitration award (Implemented)

- **Minutes vs. formal settlement**: Saving or finalizing Mediation/Conciliation `hearing_minutes` with `Settled` keeps `cases.case_status` active and sets `case_stages.stage_status = 'Awaiting Outcome'` without creating settlement deadlines. `CaseStage::recordOutcome()` blocks `Settled` until a `Signed and Attested` `settlements` row exists.
- **Draft vs. signed & attested settlement (`Settlement::saveSettlement`)**: Settlements without `complainant_signed`, `respondent_signed`, and `pb_attested` are saved as `Draft` (leaving case status active and deadlines `NULL`). Once all three are recorded, `saveSettlement()` atomically renders KP Form 16, stores `settlements.document_id`, transitions the case/stage to `Settled`, and records `case_deadlines`. Older incomplete records backfilled by migration `20261115_004` are marked `Legacy Review Required` and require explicit staff review (`confirmLegacySettlementReview`).
- **Arbitration agreement & atomic award (`Arbitration`)**: `recordAgreement()` records the signed KP Form 14 `agreement_date` and PDF (`agreement_document_id`), computing the 5-calendar-day repudiation deadline and Day 15 award deadline. `issueAward()` (`POST backend/api/documents/arbitration.php`) locks `cases` + `arbitration_records`, rejects missing/unsigned/repudiated agreements or out-of-window dates (Day 6–15 calendar days) with no request/clock fallback, renders KP Form 15 atomically, rolls back and unlinks the PDF on failure, and returns existing finalized awards idempotently.

### Order 4B — Finality, execution, and court transmittal (Implemented)

- **Confirmed deadline rules**:
  - **Settlement repudiation (KP Form 16, RA 7160 Sec. 416/418)**: 10 working days (`MediationDeadlineService::calculateBusinessDays($settlementDate, 10)`), allowed while `$today <= $repudiation_deadline` for `Signed and Attested` settlements.
  - **Arbitration agreement repudiation & award issuance (KP Form 14/15, RA 7160 Sec. 413)**: 5 calendar days from `agreement_date` for repudiation; Day 6–15 calendar days from `agreement_date` for award issuance.
  - **Finality & court transmittal (RA 7160 Sec. 416)**: 10 working days from `settlement_date` or `award_date`; issued CFAs unlock transmittal immediately (0-day hold) when backed by a saved PDF.
  - **Barangay execution (KP Form 23/24/25, RA 7160 Sec. 417)**: Allowed after finality (past 10 working days) and within 6 months (`+6 months`) for both signed settlements and finalized arbitration awards (`execution_proceedings.arbitration_id`), after which it lapses to MTC (`mtcLapseResult()`).
- **Repudiation vs. noncompliance vs. execution**: `CFA::determineCfaEligibility()` keeps `REPUDIATED_SETTLEMENT` (covering both signed settlement and KP Form 14 agreement repudiation) distinct from `SETTLEMENT_NONCOMPLIANCE` (requiring `Signed and Attested` settlement or `Violated` award + documented breach). `backend/api/cases/reopen.php` locks records, rejects draft/legacy/repudiated/active-window instruments, marks the operative instrument `Violated`, and cancels active `case_transmittals` packets.
- **Court packet (`CaseTransmittal`)**: Packets link strictly to `source_type` (`Settlement`, `Arbitration Award`, `CFA`), `source_record_id`, and `source_document_id`. `preview.php` and `recordSubmission()` block missing-document, repudiated, or locked packets under `FOR UPDATE` locks, preserve superseded packets (`superseded_at`), and handle repeat submissions idempotently.
- **Remaining policy/UI notes**: Confirm whether legacy `Legacy Review Required` settlements should also expose a dedicated inline button on `settlements.php` (currently handled via `save.php?action=confirm_legacy_review` and workspace badges). Order 5 remains outside this change.

### Order 5 — Dashboard concurrency and deployment (Implemented)

- **5A — Stale docket save prevention**: `frontend/assets/js/docket-drag-schedule.js` captures the pre-drag / modal-open snapshot (`expected_updated_at`, `expected_queue_order`, and `expected_hearing_date` or `expected_scheduled_date` + `expected_scheduled_time`) from `dashboard.js` and `clerk-dashboard.js` (`data-queue-order`, `data-updated-at`, `data-official-time`) and preserves `originalSnapshot` across `loadRescheduleSlots()` refreshes. `DocketScheduleService::validateStaleSnapshot()` validates the full snapshot under `FOR UPDATE` row locks (including null/empty timestamps and same-second competing edits), returning HTTP 409 `STALE_PAYLOAD` (or HTTP 422 `MISSING_CONCURRENCY_SNAPSHOT` when `require_concurrency_snapshot` is set). On rejection, the UI restores the pre-drag order/display and exposes a **Reload docket** button.
- **5B — Gated Azure deployment & release package verification**: `.github/workflows/azure-webapp.yml` runs a `test-and-package` job before `deploy` (`needs: test-and-package`) using PHP 8.3, Node 20, `composer install --no-dev --optimize-autoloader`, disposable MySQL 8.0 (`agap_test` fresh schema + `agap_upgrade_test` upgrade through `20261115_004`), `npm test` (with `tests/run_tests.js` preflight verifying `SELECT DATABASE()` and Order 4 columns), and `php tests/verify_release_package.php release` (blocking deployment if required runtime/template/dependency files are missing or prohibited backups/seeds/`.env`/logs/uploads/PDFs/test output/scratch files are present, while preserving `/home/site/wwwroot/storage`).

## Implementation and verification

### Local integration test baseline

Use a disposable MySQL database for `npm test`. The canonical `database/schema.sql` creates and selects `agap_db`. To provision `agap_test`, use the local `output/agap_test.sql` export if available, or copy the canonical schema and change both `CREATE DATABASE` and `USE` to `agap_test` before importing. The fresh import includes all seven staff users. `DB_NAME` selects an existing database. For an older test database, follow the [database guide](database/README.md) and confirm it includes migrations `20261115_003` and `20261115_004`.

The verified Laragon setup uses PHP 8.3.36 and MySQL on `127.0.0.1:3306`. Use a dedicated PowerShell session so the app keeps using its usual database settings. Adjust the PHP path and port for your installation, and ensure the runner and its PHP HTTP subprocesses use the same database credentials.

```powershell
$env:DB_NAME = 'agap_test'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3306'
$env:PHP_BINARY = 'C:\laragon\bin\php\php-8.3.36-Win32-vs16-x64\php.exe'
npm test
```

On the freshly imported `agap_test`, all seven suites passed (`7 passed, 0 failed`), including 154 regression assertions covering Orders 3A, 3B, 4A, and 4B. Upgrade-path verification with synthetic historical fixtures also confirmed `20261115_004` preserves existing finalized awards and marks incomplete legacy settlements as `Legacy Review Required`.

### Implementation checks

- Use prepared PDO statements and enforce session, role, record ownership, and CSRF checks on the server.
- Keep multi-record state changes and their audit entries consistent. Lock or recheck the current record before a transition so stale submissions and retries cannot overwrite outcomes.
- Follow the endpoint's response convention. AJAX APIs use JSON with appropriate status codes. Complaint form actions use a redirect and feedback on the original record.
- Make schema upgrades safe to rerun where practical, preserve existing records, and update `database/schema.sql` for new installations. Verify the target database before applying migrations. The [database guide](database/README.md) lists the workflow migration order.
- Run syntax checks for modified PHP and JavaScript and run `git diff --check`. Exercise the normal browser path, a blocked action, and a retry where relevant. Use synthetic accounts and an isolated local database for tests that create records.
- Run `npm test` as the repository baseline. The Paanyaya end-to-end suite (`npm run test:paanyaya`) covers walk-in and online intake, resident information requests and replies, invitation preparation, failed delivery and retry, dialogue outcomes, the clerk's next-action links, the resident timeline, settlement, a second invitation, escalation, single-case docketing, and the KP Form 7 hold. The scheduling suite (`npm run test:sched`, `backend/tests/test_scheduling_fixes.php`) covers same-day queue reordering without appointment shifts, no-op drags, locked/in-progress/terminal records, role/CSRF/ownership enforcement, filtered/paginated/multi-day list protection, formal hearing and Paanyaya rescheduling (notice superseding, PDF regeneration, and resident timeline synchronization), and concurrent stale-state rejection.
- The show-cause and CFA regression suites cover differential service for co-respondents, failed replacement returns and retries, recipient-specific determinations, premature issuance, duplicate submissions, and rollback on certificate generation failure. Keep those checks in place when changing the earlier handoffs.
- After Orders 3A and 3B, run staff acceptance tests for Paanyaya through Mediation and Conciliation, show cause, and CFA on a fresh test database and an upgraded test database. Use separate cases for impasse, non-appearance, repudiation, and missing-PDF recovery. Include settlement, award, execution, and court transmittal in full-workflow acceptance after Order 4. Order 5 adds browser coverage for competing dashboard edits and makes integration tests part of deployment.
- For Gemini dictation, keep credentials server-side. Require explicit consent before sending audio and let the user review and edit the transcript before inserting it into the narrative. Use synthetic audio and a mocked response for browser tests that cover the UI.
