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
| Formal documents and history | `document_templates`, `generated_documents`, `case_history`, and `audit_trails` |

Use the saved state for action availability and dashboard counts. Confirm service before opening dialogue for the prepared invitation. Use explicit authorized actions for formal case transitions, including Pangkat referral and CFA issuance. Verify scheduling rules, KP form wording, and statutory deadlines with the project's policy owner before changing them.

## Daily docket ordering and rescheduling

The Administrator dashboard and Clerk Agenda Stream allow staff to drag same-day docket cards. The current endpoint persists the visible sequence and recalculates appointment times from the earliest submitted slot. It updates formal hearing times and Paanyaya register/session times, skips lunch, preserves excluded occupied slots, and rejects a sequence that exceeds configured office hours. Calendar cells select a date and do not accept dropped cards.

Treat queue ordering and appointment rescheduling as separate operations when extending this feature. A formal schedule edit already requires an active hearing, a reason, scheduling validation, conflict checks, an audit entry, and member notification. Any drag workflow that changes an official appointment must provide equivalent safeguards and update every appointment source used by notices and the resident timeline.

Before relying on or extending docket dragging, address these current gaps:

- Lock cards and reject API changes for hearings or Paanyaya sessions that are already `In_Session`, attendance-recorded, concluded, completed, cancelled, resolved, or superseded.
- Restrict `reorder_queue` to the Administrator and Lupon Clerk roles and validate that a submitted Paanyaya session belongs to the submitted Paanyaya record.
- Do not save an unchanged or cancelled drag. Keep the original sequence and restore it when the server rejects the change.
- Derive `queue_date` from the actual selected docket date. Do not reorder a filtered, paginated, week, month, or year list as though it were one complete day.
- If an appointment time changes, reconcile `paanyayas`, `paanyaya_sessions`, `paanyaya_preparations`, `paanyaya_service_attempts`, and `paanyaya_stages`, then regenerate or supersede affected notices and notify the parties.
- Record old and new values, the reason, actor, and each affected hearing or invitation in the audit/history trail.
- Cover no-op drags, failed saves, in-progress records, served invitations, filtered lists, concurrent requests, and direct unauthorized API requests in automated tests.

## Implementation and verification

- Use prepared PDO statements and enforce session, role, record ownership, and CSRF checks on the server.
- Keep multi-record state changes and their audit entries consistent. Lock or recheck the current record before a transition so stale submissions and retries cannot overwrite outcomes.
- Follow the endpoint's response convention. AJAX APIs use JSON with appropriate status codes. Complaint form actions use a redirect and feedback on the original record.
- Make schema upgrades safe to rerun where practical, preserve existing records, and update `database/schema.sql` for new installations. Verify the target database before applying migrations. The [database guide](database/README.md) lists the workflow migration order.
- Run syntax checks for modified PHP and JavaScript and run `git diff --check`. Exercise the normal browser path, a blocked action, and a retry where relevant. Use synthetic accounts and an isolated local database for tests that create records.
- Run `npm test` as the repository baseline. The Paanyaya end-to-end suite covers walk-in and online intake, resident information requests and replies, invitation preparation, failed delivery and retry, dialogue outcomes, the clerk's next-action links, the resident timeline, settlement, a second invitation, escalation, single-case docketing, and the KP Form 7 hold.
- For Gemini dictation, keep credentials server-side. Require explicit consent before sending audio and let the user review and edit the transcript before inserting it into the narrative. Use synthetic audio and a mocked response for browser tests that cover the UI.
