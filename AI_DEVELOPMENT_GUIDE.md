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
| Prepared invitation and delivery result | `paanyaya_preparations` and `paanyaya_service_attempts`, scoped to the complaint and invitation number |
| Dialogue schedule, attendance, and outcome | `paanyaya_stages`, `paanyaya_sessions`, and `paanyaya_minutes`. The `paanyayas` register reflects the scheduled invitation and progression. |
| Formal case, stage, hearing, and service | `cases.case_status`, `case_stages`, `hearings`, `hearing_attendance`, `hearing_minutes`, and `summon_deliveries` |
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
  - For formal hearings, updates `hearings` (`hearing_date`, `end_time`, `duration_minutes`, `venue`, `presiding_officer_id`, `queue_order`, `reschedule_reason`), synchronizes `case_deadlines`, marks prior active `summon_deliveries` as `'Superseded'` while queueing fresh pending deliveries, marks affected `generated_documents` with `regeneration_reason`, and records `case_history`, `audit_trails`, and `notifications`.
  - For Paanyaya invitations, reconciles `paanyayas`, `paanyaya_sessions`, `paanyaya_stages`, `paanyaya_preparations` (regenerating the invitation PDF binary and incrementing `document_version`), `paanyaya_service_attempts` (synchronizing `appointment_at`, `document_version`, and superseding prior service notes), and legacy `complaint_paanyaya`, while recording `audit_trails` and `notifications`.
  - `ResidentComplaintTimeline` reads the confirmed current appointment (`COALESCE(p.appointment_at, a.appointment_at)` for Paanyaya and excluding superseded hearings via `NOT EXISTS (SELECT 1 FROM hearings nh WHERE nh.rescheduled_from_id = hearings.hearing_id)`).
- **Server-side eligibility and concurrency (`DocketScheduleService`)**:
  - Only active Administrators and Lupon Clerks (verified against `users` and `roles`) may reorder or reschedule; Lupon Members and unauthenticated/CSRF-invalid requests are rejected (`401`/`403`).
  - Records that are `In_Session`, attendance-recorded (`Attendance Recorded`, `attendance_recorded_at IS NOT NULL`, or party attendance recorded), minutes-recorded, `Concluded`, `Completed`, `Cancelled`, `Office Cancelled`, `Resolved`, `Unresolved`, `Escalated`, `Settled`, or superseded are locked (`422`).
  - Every submitted `paanyaya_sessions.session_id` is verified against the submitted `complaint_id` and `paanyayas.paanyaya_id`.
  - Stale (`expected_updated_at`, `expected_hearing_date`, `expected_queue_order`), duplicate, cross-date, incomplete, and filtered/paginated payloads are rejected safely (`409`/`422`) inside `FOR UPDATE` row-locked transactions.

## Pending implementation for attendance, show cause, and CFA

Use the existing hearing workspace, daily docket, Proof of Service, and Outcomes tab. Build on the recipient-specific replacement notices and apply their database migration before testing against an existing installation. Confirm any change to session limits, summons-attempt limits, certificate mappings, or deadlines with the barangay policy owner.

### Mediation and Conciliation attendance and show cause

- Use one server-side service check across the hearing workspace, docket check-in, and direct attendance and show-cause endpoints. For failure to appear, require verified service for the exact recipient on the operative notice for that hearing. A prior-notice exception requires a recorded basis for that recipient and hearing. Superseded returns remain available as historical evidence.
- Keep `Not Served`, `Pending Verification`, `Excused`, and verified non-appearance distinct. Tie each show-cause determination to the party, hearing, and relevant notice. Require a recorded determination before applying a bar or default where the existing procedure requires one. Show staff the party awaiting service and the next available action.
- Add a retry action in Proof of Service for an unserved replacement notice. Create a new attempt for the same hearing, recipient, and current notice. Preserve the failed return and its proof, apply the configured limit to that recipient's service sequence, and reject submissions against a superseded notice. Lock or recheck the current notice when saving the retry or return.

### CFA eligibility, preview, and issuance

- Use one server-side eligibility decision for the Outcomes tab, form preview, and issuance. Derive the ground and allowed KP Forms 20, 20-A, and 20-B from saved evidence. A client-supplied workflow flag, status, or reason cannot grant eligibility. If the noncompliance reopening action applies under existing policy, verify the saved settlement and required grounds on the server.
- Scope evidence to the relevant stage, hearing, and recipient. A conciliation impasse requires a finalized Conciliation outcome. Exclude draft and wrong-stage minutes, superseded service returns, and unverified or excused absences from eligibility. Count summons failures and no-shows for the applicable recipient and service sequence.
- Recheck authorization and eligibility under a transaction. Keep the certificate and its document, case and complaint status, and history consistent. A document-generation failure must leave issuance available for retry. Return the existing certificate on duplicate submissions and preserve its original date and reason.

### Regression coverage

- Reschedule a hearing with two respondents and serve one. Verify that the other remains pending and cannot advance to failure to appear or show cause.
- Record a failed replacement delivery, retry it successfully, and reject a stale submission against the superseded notice. Verify that the failed return and proof remain available.
- Reject a direct CFA request with `noncompliance_reopen` and no documented ground, and reject draft or wrong-stage minutes as a basis for issuance.
- Verify valid CFA issuance, a duplicate submission, and a document-generation failure followed by a successful retry. Exercise the staff browser path from Proof of Service through attendance and the Outcomes tab.

## Implementation and verification

- Use prepared PDO statements and enforce session, role, record ownership, and CSRF checks on the server.
- Keep multi-record state changes and their audit entries consistent. Lock or recheck the current record before a transition so stale submissions and retries cannot overwrite outcomes.
- Follow the endpoint's response convention. AJAX APIs use JSON with appropriate status codes. Complaint form actions use a redirect and feedback on the original record.
- Make schema upgrades safe to rerun where practical, preserve existing records, and update `database/schema.sql` for new installations. Verify the target database before applying migrations. The [database guide](database/README.md) lists the workflow migration order.
- Run syntax checks for modified PHP and JavaScript and run `git diff --check`. Exercise the normal browser path, a blocked action, and a retry where relevant. Use synthetic accounts and an isolated local database for tests that create records.
- Run `npm test` as the repository baseline. The Paanyaya end-to-end suite (`npm run test:paanyaya`) covers walk-in and online intake, resident information requests and replies, invitation preparation, failed delivery and retry, dialogue outcomes, the clerk's next-action links, the resident timeline, settlement, a second invitation, escalation, single-case docketing, and the KP Form 7 hold. The scheduling suite (`npm run test:sched`, `backend/tests/test_scheduling_fixes.php`) covers same-day queue reordering without appointment shifts, no-op drags, locked/in-progress/terminal records, role/CSRF/ownership enforcement, filtered/paginated/multi-day list protection, formal hearing and Paanyaya rescheduling (notice superseding, PDF regeneration, and resident timeline synchronization), and concurrent stale-state rejection.
- For Gemini dictation, keep credentials server-side. Require explicit consent before sending audio and let the user review and edit the transcript before inserting it into the narrative. Use synthetic audio and a mocked response for browser tests that cover the UI.
