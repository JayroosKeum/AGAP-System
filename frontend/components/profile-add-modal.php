<?php
declare(strict_types=1);
?>
<div class="profile-modal" id="addProfileModal" hidden aria-hidden="true">
  <div class="profile-modal__backdrop" data-close-profile-modal></div>
  <section class="profile-modal__panel" role="dialog" aria-modal="true" aria-labelledby="addProfileTitle">
    <header class="profile-modal__header">
      <div><h2 id="addProfileTitle">Add Profile</h2><p>Register an identity record for party verification and dispute proceedings.</p></div>
      <button type="button" class="profile-modal__close" data-close-profile-modal aria-label="Close">×</button>
    </header>
    <form id="addProfileForm" action="backend/api/profiles/create.php" method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?=htmlspecialchars($_SESSION['profiles_csrf'],ENT_QUOTES,'UTF-8')?>">
      <input type="hidden" name="latitude" id="profileLatitude">
      <input type="hidden" name="longitude" id="profileLongitude">
      <div class="profile-form-alert" id="addProfileAlert" hidden></div>
      <fieldset><legend>♙ PERSONAL INFORMATION</legend><div class="profile-modal__grid">
        <label><span class="profile-field-label">First Name <b>*</b></span><input name="first_name" placeholder="e.g. Juan" required><small data-error-for="first_name"></small></label>
        <label><span class="profile-field-label">Middle Name <b>*</b></span><input name="middle_name" placeholder="e.g. Santos" required><small data-error-for="middle_name"></small></label>
        <label><span class="profile-field-label">Last Name <b>*</b></span><input name="last_name" placeholder="e.g. Dela Cruz" required><small data-error-for="last_name"></small></label>
        <label><span class="profile-field-label">Birth Date <b>*</b></span><input type="date" name="birth_date" required><small data-error-for="birth_date"></small></label>
        <label><span class="profile-field-label">Gender <b>*</b></span><select name="sex" required><option value="">Select Gender</option><option>Male</option><option>Female</option><option>Other</option></select><small data-error-for="sex"></small></label>
        <label><span class="profile-field-label">Civil Status <b>*</b></span><select name="civil_status" required><option value="">Select Civil Status</option><option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option></select><small data-error-for="civil_status"></small></label>
      </div></fieldset>
      <fieldset><legend>⌾ RESIDENCY &amp; CONTACT INFORMATION (BARANGAY TUMANA)</legend><div class="profile-modal__grid">
        <label><span class="profile-field-label">Contact Number <b>*</b></span><input name="contact_no" placeholder="e.g. 0917 123 4567" required><small data-error-for="contact_no"></small></label>
        <label><span class="profile-field-label">Email Address <b>*</b></span><input type="email" name="email" placeholder="e.g. resident@example.com" required><small data-error-for="email"></small></label>
        <label><span class="profile-field-label">Purok / Zone (Tumana) <b>*</b></span><select name="purok_sitio" id="profilePurok" required><option value="">Select Purok / Area</option><option value="Tumana">Tumana</option><option value="Doña Petra">Doña Petra</option><option value="Purok 1">Purok 1</option><option value="Purok 2">Purok 2</option><option value="Purok 3">Purok 3</option><option value="Purok 4">Purok 4</option><option value="Purok 5">Purok 5</option><option value="Purok 6">Purok 6</option><option value="Purok 7">Purok 7</option></select><small data-error-for="purok_sitio"></small><em>Updated by map pinning, or select manually to reposition the pin.</em></label>
        <label><span class="profile-field-label">Residency Status <b>*</b></span><select name="residency_status" required><option value="">Select Residency Status</option><option>Permanent Resident</option><option>Temporary Resident</option><option>Tenant</option></select><small data-error-for="residency_status"></small></label>
      </div>
      <div class="profile-address-heading"><label for="profileAddress"><span class="profile-field-label">Complete Street Address <b>*</b></span></label><button type="button" id="toggleProfileMap">⌾ Hide Map</button></div>
      <div id="profileMapWrap"><div class="profile-map-info"><span>ⓘ Click or drag the pin to update the address and Purok. Editing the address or Purok repositions the pin.</span><button type="button" id="pinInstruction">Click map to pin</button></div><div id="profileMap"></div></div>
      <textarea id="profileAddress" name="address_line" rows="3" required placeholder="e.g. #24 Moscow Street, Purok 6, Barangay Tumana, Marikina City"></textarea><small data-error-for="address_line"></small>
      <p class="profile-format-help">Format: [House No.] [Street], [Subdivision/Purok], Barangay Tumana, Marikina City.</p>
      <input type="hidden" name="barangay" value="Tumana"><input type="hidden" name="city_municipality" value="Marikina City"><input type="hidden" name="province" value="Metro Manila"><input type="hidden" name="voter_status" value="Not Specified"><input type="hidden" name="occupation" value="Not Specified"><input type="hidden" name="notes" value="Not Specified">
      </fieldset>
      <footer class="profile-modal__footer"><button type="button" class="workspace-button secondary" data-close-profile-modal>Cancel</button><button type="submit" class="workspace-button" id="saveProfileButton">Save Profile</button></footer>
    </form>
  </section>
</div>
