# Phase 8 End-to-End Validation

## Add

- `backend/services/WorkflowValidationService.php`
- `backend/api/workflow/validation.php`
- `database/validation/20261015_phase8_workflow_validation.sql`

No migration is required.

## Automated validation

Log in as Administrator and open:

- `backend/api/workflow/validation.php`
- `backend/api/workflow/validation.php?case_id=1`

A successful result has `failed: 0`.

## HeidiSQL validation

Run `database/validation/20261015_phase8_workflow_validation.sql` against `remastered_db`.

Each result set should return zero rows.

## Browser scenarios

### Scenario A: Unresolved full workflow

1. Create or use a Docketed test case.
2. Issue current notices and summons.
3. Record service for every respondent.
4. Confirm Mediation readiness.
5. Complete a Mediation hearing.
6. Record `UNRESOLVED`.
7. Verify stage `Pangkat Formation`.
8. Configure three distinct active Pangkat members.
9. Complete conflict checks and activate the Pangkat.
10. Complete a Conciliation hearing.
11. Record `UNRESOLVED` or `NO_CONFRONTATION_NO_FAULT_COMPLAINANT`.
12. Submit a CFA request.
13. Approve the CFA request.
14. Generate the correct KP Form document.
15. Issue the CFA.
16. Record release to a case party.
17. Run validation for the case.

### Scenario B: Mediation settlement

1. Complete a Mediation hearing.
2. Generate and execute the settlement document.
3. Record `SETTLED`.
4. Verify stage `Settled`.
5. Verify no CFA action is available.
6. Run validation for the case.

### Scenario C: Conciliation settlement

1. Reach Conciliation using the authorized Mediation referral.
2. Complete a Conciliation hearing.
3. Generate and execute the settlement document.
4. Record `SETTLED`.
5. Verify stage `Settled`.
6. Verify no CFA request is created.
7. Run validation for the case.

### Scenario D: Returned CFA review

1. Submit an eligible CFA request.
2. Review with decision `RETURN` and notes.
3. Verify stage returns to `Conciliation`.
4. Correct the deficient record.
5. Record a new valid Conciliation outcome and submit a new CFA request.
6. Run validation for the case.

## Required database checks per scenario

```sql
SELECT case_id, case_status
FROM cases
WHERE case_id = 1;
```

```sql
SELECT *
FROM case_stage_history
WHERE case_id = 1
ORDER BY created_at, stage_history_id;
```

```sql
SELECT *
FROM stage_outcomes
WHERE case_id = 1
ORDER BY recorded_at, outcome_id;
```

```sql
SELECT *
FROM workflow_integrity_events
WHERE case_id = 1
  AND resolved_at IS NULL;
```

## Acceptance criteria

- No PHP fatal or syntax error.
- No failed network request in browser developer tools.
- No duplicate active stage outcome.
- No direct stage jump caused by hearing count.
- Every stage change has one stage-history record.
- Failed service does not issue or enable CFA.
- Failed Mediation proceeds to Pangkat Formation, not directly to CFA.
- Conciliation cannot begin without three distinct active Pangkat members.
- CFA cannot be issued without an approved request and a case-owned document.
- Validation reports zero failures for the completed test case.
