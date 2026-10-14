# Profiles Required Fields and Map Synchronization

## Modified
- frontend/components/profile-add-modal.php
- frontend/assets/css/profiles.css
- frontend/assets/js/pages/profile-add-modal.js
- frontend/assets/js/pages/profile-add-modal.js.txt
- backend/validators/profile-validator.php

## Added
- backend/api/geocoding/forward.php

## Behavior
- Every visible Add Profile field is required.
- Required asterisks use one aligned inline label style.
- Map clicks and marker dragging update coordinates, address, and Purok.
- Address changes forward-geocode and reposition the marker.
- Purok changes reposition the marker and populate an address base when empty.
