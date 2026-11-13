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
| Screening | The Lupon Member (`role_id = 3`) opens the intake in a clean read-only screening view (non-interactive dropdown indicators, post-docket tabs, operational cards, and clerk/admin edit controls are hidden) and records the screening decision. Marking an intake `Eligible` is single-click/idempotent (`COALESCE(screening_decision, '') <> 'Eligible'`) and replaces the form with a read-only completion banner. Eligible undocketed intakes enter the **Paanyaya** stage first and appear in the clerk's Paanyaya preparation queue. |
| Preparation | On the original complaint, the clerk or administrator verifies the parties, enters the appointment and venue, generates and reviews the PDF, and marks it Ready for Release. While in Paanyaya, KP Form 7 remains on hold (`Pending Paanyaya Outcome`) and post-docket tabs (`Mediation`, `Conciliation`, `Outcomes`) remain locked. |
| Release and service | The clerk or administrator assigns an active summons server. The server records delivery in **Proof of Service → My Paanyaya Assignments**. Unsuccessful delivery requires a reason and allows a retry. The complaint displays the result and any proof. |
| Dialogue | Confirmed delivery of that invitation enables the clerk or administrator to save attendance, the actual dialogue date and time, minutes, and the outcome on the complaint. Settlement requires agreed terms and resolves the complaint at Paanyaya without creating a formal case or KP Form 7. |
| Next action | A request for a second Paanyaya enables a separate invitation on the same complaint with its own PDF, service history, and dialogue. Formal complaint docketing (`cases`), KP Form 7 generation, and the `Mediation` stage are dynamically unlocked **only** when Paanyaya concludes without resolution (`Escalate_Mediation`). |

The Paanyaya preparation queue covers eligible undocketed non-draft intakes (`Walk-in` or `Online`) that have not started legacy Paanyaya rows. The current workflow supports two invitations. Delivery retries are recorded against the same invitation. Authorization, allowed outcomes, and stage gates are explicit, database-driven server-side rules (no hardcoded stage overrides). Parties, duty status, assignment, dates, venue, proof, and minutes come from saved records or staff input.

## Authoritative records

| Fact | Where to read it |
| --- | --- |
| Resident account link and submission ownership | `resident_user_profiles` and `complaints.submitted_by` |
| Intake, parties, screening, and duty | `complaints`, `complaint_parties`, `residents`, `complaint_intake_screenings`, and `liso_duty_sessions` |
| Prepared invitation and delivery result | `paanyaya_preparations` and `paanyaya_service_attempts`, scoped to the complaint and invitation number |
| Dialogue schedule, attendance, and outcome | `paanyaya_stages`, `paanyaya_sessions`, and `paanyaya_minutes`. The `paanyayas` register reflects the scheduled invitation and progression. |
| Formal case, stage, hearing, and service | `cases.case_status`, `case_stages`, `hearings`, `hearing_attendance`, `hearing_minutes`, and `summon_deliveries` |
| Formal documents and history | `document_templates`, `generated_documents`, `case_history`, and `audit_trails` |

Use the saved state for action availability and dashboard counts. Confirm service before opening dialogue for the prepared invitation. Use explicit authorized actions for formal case transitions, including Pangkat referral and CFA issuance. Verify scheduling rules, KP form wording, and statutory deadlines with the project's policy owner before changing them.

## Implementation and verification

- Use prepared PDO statements and enforce session, role, record ownership, and CSRF checks on the server.
- Keep multi-record state changes and their audit entries consistent. Lock or recheck the current record before a transition so stale submissions and retries cannot overwrite outcomes.
- Follow the endpoint's response convention. AJAX APIs use JSON with appropriate status codes. Complaint form actions use a redirect and feedback on the original record.
- Make schema upgrades safe to rerun where practical, preserve existing records, and update `database/schema.sql` for new installations. Verify the target database before applying migrations. The [database guide](database/README.md) lists the workflow migration order.
- Run syntax checks for modified PHP and JavaScript and run `git diff --check`. Exercise the normal browser path, a blocked action, and a retry where relevant. Use synthetic accounts and an isolated local database for tests that create records.
- For Gemini dictation, keep credentials server-side. Require explicit consent before sending audio and let the user review and edit the transcript before inserting it into the narrative. Use synthetic audio and a mocked response for browser tests that cover the UI.
