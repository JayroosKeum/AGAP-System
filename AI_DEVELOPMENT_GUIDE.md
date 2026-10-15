# AGAP AI Development Guide

Use this guide when changing AGAP. Its purpose is to keep the system understandable, safe, and small enough to maintain. Read `PROJECT_GUIDELINES.md` first.

## 1. Simplify before adding

Do not solve a workflow problem by adding another status, table, screen, service, or migration unless the existing path cannot be simplified first.

Before editing, identify:

1. The user action that starts the process.
2. The single record that owns the state.
3. The approved action that changes the state.
4. The screen and API that show the result.
5. A test for the happy path and a blocked path.

If these cannot be named clearly, propose a simplification plan before implementing.

## 2. Sources of truth

| Concern | Source of truth |
| --- | --- |
| Complaint details and parties | `complaints`, `complaint_parties`, `residents` |
| Case stage and final disposition | `cases.case_status` |
| Hearing schedule and completion | `hearings` |
| Presiding officer and substitution | `hearings.presiding_officer_id`, `hearings.substitute_presider_id`, `hearings.substitute_reason`, `hearings.parties_consent_to_substitute` |
| Attendance and non-appearance | `hearing_attendance`, `hearings.complainant_attendance`, `hearings.respondent_attendance` |
| Show-cause justification evaluation | `show_cause_evaluations` |
| Hearing minutes and settlement notes | `hearing_minutes` |
| Summons service attempts | `summon_deliveries` |
| Pangkat membership & challenges | `pangkat_groups`, `pangkat_members`, `pangkat_challenges`, `pangkat_extensions` |
| History | `case_history` and audit log |

Do not introduce another table that represents the same fact. Dashboards may derive data from these records, but must not become alternate sources of truth.

## 3. Canonical case flow

```text
Complaint → Reviewed → Docketed
          → Summons issued → Service recorded
          → Mediation (1st → 2nd → 3rd) → approved outcome
              ├─ Settled (KP Form 16) / dismissed / final outcome
              └─ Referred to Pangkat → Conciliation (1st → 2nd → 3rd) → approved outcome
                                                ├─ Settled
                                                └─ CFA review → CFA issued (KP Form 20)
```

- Scheduling a hearing is not an outcome.
- Issuing a document is not proof of service.
- Initial summons (KP Form 9) serves for the 1st Mediation; 2nd and subsequent mediations do not require summons re-issuance and inherit prior service notice.
- Non-appearance in 2nd mediation unlocks KP Form 18 (Complainant) and KP Form 19 (Respondent) show-cause hearing notices.
- Certificate to Bar Action (KP Form 21) or Counterclaim (KP Form 22) requires a recorded show-cause evaluation ruling non-appearance as unjustified.
- A hearing count never changes a case stage by itself.
- Elevation to Pangkat Conciliation requires completion of 3 mediation sessions with an unresolved outcome and an explicit authorized action.
- A stage changes only through an explicit, authorized server-side action.
- A terminal outcome prevents ordinary scheduling.
- A legal/KP policy owner approves statutory rules, KP form wording, deadline calculations, and CFA grounds before they are changed.

## 4. State changes

Use `cases.case_status` only for broad stages and final outcomes:

```text
Docketed → Mediation → Conciliation → Settled | Dismissed | CFA Issued | Archived
```

Do not use case status for UI hints such as “ready,” “attempt 2,” or “needs review.” Store a specific fact on the relevant record or calculate it for display.

Every transition needs an authorized endpoint, a reason or approved outcome, actor and timestamp history, previous-state validation, and one clear user-facing result.

## 5. Responsibilities by layer

```text
Page / JavaScript  → presents data and requests an action
API endpoint       → authenticates, authorizes, validates input, returns JSON
Controller         → coordinates the use case and audit event
Model / service    → reads or changes one domain concern transactionally
Database           → protects relationships and required data
```

Never put legal workflow gates only in JavaScript. Do not duplicate a transition in multiple models. Do not infer transitions from counts or labels.

## 6. Implementation checklist

Before editing:

- Search existing code and schema use with `rg`.
- Inspect the API response and live schema for data-related bugs.
- Preserve unrelated user changes.

While editing:

- Use prepared PDO statements.
- Wrap multi-table writes in one transaction.
- Keep endpoints JSON-only with meaningful HTTP status codes.
- Enforce authorization on the server; browser controls are only usability aids.
- Add an idempotent migration for schema changes and update `database/schema.sql` for fresh installs.
- Let optional panels fail independently; a workspace error must not hide core complaint/case data.

After editing:

- Run syntax checks for modified PHP and JavaScript.
- Exercise the affected API with a representative record when safe.
- Test a permitted action and a blocked action.
- Run `git diff --check`.
- Report changes, behaviour, and required migrations.

## 7. Migration rules

- Migrations are forward-only and idempotent.
- Use `INFORMATION_SCHEMA` checks where installation versions differ.
- Keep one migration focused on one concern.
- Do not make runtime code require a column before its migration is supplied.
- Do not silently swallow integrity failures; log technical details and return a recoverable message.

## 8. UI rules

- One screen answers: current stage, last action, and next permitted action.
- Show one primary action, not competing workflow buttons.
- Explain why unavailable actions are blocked.
- Prefer progressive disclosure over dashboards inside every page.
- Use consistent terminology: **Mediation**, **Conciliation**, **Pangkat**, **CFA**.

## 9. AI must not

- Add a parallel workflow, duplicate status, or duplicate entity table.
- Make a legal conclusion from free text alone.
- Auto-escalate to Conciliation because a count reaches three.
- Auto-issue CFA from a button click or keyword match.
- Put security, authorization, or legal gates only in the browser.
- Rewrite working modules or change unrelated data without approval.

## 10. Definition of done

A change is complete only when it has one owner, one authoritative state path, a verified API response, a usable screen state, and an explanation of how it behaves when the action is not allowed.
