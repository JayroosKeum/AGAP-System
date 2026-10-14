# Add Profile Modal

## Modified files
- frontend/pages/profiles.php
- frontend/assets/css/profiles.css
- backend/models/Profile.php
- backend/validators/profile-validator.php
- database/migrations/20261015_001_profiles_module.sql
- database/schema.profiles.sql

## New files
- frontend/components/profile-add-modal.php
- frontend/assets/js/pages/profile-add-modal.js
- frontend/assets/js/pages/profile-add-modal.js.txt
- backend/api/profiles/create.php
- backend/api/geocoding/_client.php
- backend/api/geocoding/reverse.php
- backend/api/geocoding/tumana-boundary.php
- database/migrations/20261015_002_profile_modal_fields.sql

## Required order
1. Run database/migrations/20261015_002_profile_modal_fields.sql if migration 001 was already imported.
2. Copy all files using their included paths.
