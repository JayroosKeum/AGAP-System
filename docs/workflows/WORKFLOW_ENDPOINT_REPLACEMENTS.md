# Workflow Endpoint Replacements

## Summons

- `backend/api/summons/next-attempt.php` → `backend/api/summons/start-attempt.php`
- `backend/api/summons/record-delivery.php` → `backend/api/summons/record-attempt.php`
- Readiness checks → `backend/api/summons/readiness.php`

## Mediation

- Overview → `backend/api/mediation/overview.php`
- Final outcome → `backend/api/mediation/outcome.php`

## Conciliation

- Overview → `backend/api/conciliation/overview.php`
- Pangkat activation → `backend/api/conciliation/activate-pangkat.php`
- Final outcome → `backend/api/conciliation/outcome.php`

## CFA

- Overview → `backend/api/cfa/overview.php`
- Submit → `backend/api/cfa/submit.php`
- Review → `backend/api/cfa/review.php`
- Issue → `backend/api/cfa/issue.php`
- Release → `backend/api/cfa/release.php`

## Workflow

- Transition → `backend/api/workflow/transition.php`
- History → `backend/api/workflow/history.php`
- Workspace → `backend/api/workflow/workspace.php`
- Integrity scan → `backend/api/workflow/integrity-scan.php`
