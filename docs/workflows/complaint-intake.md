# Complaint Intake

## Retained Behavior
- Administrator authority, shared sidebar, Profiles registry, and existing dispute stages remain authoritative.

## Fixed Behavior
- Replaces a complaint intake shell with a working full-page form.

## Refined Behavior
- Reuses Profiles for complainant and respondent verification while allowing manual non-resident complainant entry.

## New Behavior
- Transactional complaint filing, party records, intake history, profile lookup, incident map pinning, reverse geocoding, validation, and redirect to complaint details.

## Database Changes
- Adds `complaints`, `complaint_parties`, and `complaint_history` only when they do not already exist.

## Route
- Add `complaints-create` to the existing `index.php` page allowlist/router.
- Route it to `frontend/pages/complaints-create.php`.
- Do not replace the complaints list or details routes.

## JavaScript Copies
- `frontend/assets/js/pages/complaints-create.js.txt` is an exact copy of `complaints-create.js`.
