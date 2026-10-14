# Profiles Module

## Retained behavior
Administrator-only access, shared dashboard shell, controlled profile registry, complaint-party-ready identifiers.

## Fixed behavior
The shell is replaced by a working module. Destructive deletion is replaced by archive/reactivate to preserve referenced identity history.

## Refined behavior
Old Resident Profiles behavior is adapted to `profiles`, `profile_id`, remastered sessions, server authorization, CSRF, transactions, and profile history. Similar names are permitted when addresses differ.

## New behavior
KPI cards, debounced search, Purok/residency/status filters, five sorting modes, strict 25-item client pagination, formatted RP identifiers, demographics and age display, initials avatars, create/view/edit, location coordinates, archive/reactivate, JSON list/view endpoints, and audit history.

## JavaScript copy
`frontend/assets/js/pages/profiles.js.txt` is an exact copy of `profiles.js`.
