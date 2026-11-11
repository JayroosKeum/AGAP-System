<?php

if (!function_exists('renderKpJurisdictionVerificationPanel')) {
    function renderKpJurisdictionVerificationPanel(
        array $old = [],
        array $intakeScreening = [],
        array $complaint = []
    ): void {
        $impMax = (float) ($old['offense_imprisonment_max'] ?? ($intakeScreening['offense_imprisonment_max'] ?? 0));
        $selectedImp = '0';
        if ($impMax > 1.0) {
            $selectedImp = '2';
        } elseif ($impMax > 0.5) {
            $selectedImp = '1';
        } elseif ($impMax > 0.0) {
            $selectedImp = '0.5';
        }

        $capacityType = strtolower(trim((string) ($old['party_capacity_type'] ?? '')));
        if ($capacityType === '') {
            if (!empty($old['party_is_juridical'])) {
                $capacityType = 'juridical';
            } elseif (!empty($old['party_is_government']) || !empty($intakeScreening['party_is_government'])) {
                $capacityType = 'government';
            } elseif (!empty($old['party_is_public_officer']) || !empty($intakeScreening['party_is_public_officer'])) {
                $capacityType = 'public_officer';
            } else {
                $capacityType = 'individual';
            }
        }

        $partiesInDiffLgu = !empty($old['parties_in_diff_lgu']) || !empty($intakeScreening['parties_in_diff_lgu']);
        $propertyInDiffLgu = !empty($old['property_in_diff_lgu']) || !empty($intakeScreening['property_in_diff_lgu']);
        $accusedDetained = !empty($old['accused_under_detention']) || !empty($intakeScreening['accused_under_detention']);
        $habeasNeeded = !empty($old['habeas_corpus_needed']) || !empty($intakeScreening['habeas_corpus_needed']);
        $provisionalNeeded = !empty($old['provisional_remedy_needed']) || !empty($intakeScreening['provisional_remedy_needed']);
        $prescriptionRisk = !empty($old['prescription_risk']) || !empty($intakeScreening['prescription_risk']);
        $indigenousCommunity = !empty($old['indigenous_community']) || !empty($intakeScreening['indigenous_community']);
        $hasPrivateParty = array_key_exists('has_private_offended_party', $old)
            ? !empty($old['has_private_offended_party'])
            : (array_key_exists('has_private_offended_party', $intakeScreening)
                ? !empty($intakeScreening['has_private_offended_party'])
                : true);
        $respException = !empty($old['respondent_kp_jurisdiction_exception']);
        ?>
        <section id="section-jurisdiction" class="intake-section-card kp-jurisdiction-panel">
            <div class="intake-section-header">
                <div class="section-header-left">
                    <span class="section-badge-num kp-badge-shield">KP</span>
                    <div class="section-title-wrap">
                        <h3>KP Jurisdiction &amp; Eligibility Verification</h3>
                        <p>Live screening under Katarungang Pambarangay Law (R.A. 7160 §§ 408–412).</p>
                    </div>
                </div>
                <span id="kpOverallStatusBadge" class="kp-overall-pill is-eligible">Within KP Jurisdiction</span>
            </div>

            <input type="hidden" name="jurisdiction_screened" id="jurisdictionScreened" value="1">
            <input type="hidden" name="parties_same_barangay" id="kpPartiesSameBarangay" value="<?php echo $partiesInDiffLgu ? '0' : '1'; ?>">
            <input type="hidden" name="party_is_government" id="kpPartyIsGovernment" value="<?php echo $capacityType === 'government' ? '1' : '0'; ?>">
            <input type="hidden" name="party_is_public_officer" id="kpPartyIsPublicOfficer" value="<?php echo $capacityType === 'public_officer' ? '1' : '0'; ?>">
            <input type="hidden" name="party_is_juridical" id="kpPartyIsJuridical" value="<?php echo $capacityType === 'juridical' ? '1' : '0'; ?>">
            <input type="hidden" name="has_private_offended_party" id="kpHasPrivateOffendedParty" value="<?php echo $hasPrivateParty ? '1' : '0'; ?>">

            <div class="kp-jurisdiction-grid">
                <!-- 1. RESIDENCY & VENUE -->
                <div class="kp-check-card" id="kpCheckVenueCard">
                    <div class="kp-check-card-head">
                        <div>
                            <div class="kp-check-title">1. Where Parties Live (Venue)</div>
                            <div class="kp-check-cite">R.A. 7160 §§ 408(f), 409</div>
                        </div>
                        <span class="kp-check-pill is-pass" id="kpVenueStatusPill">Eligible</span>
                    </div>
                    <div class="kp-check-summary" id="kpVenueSummary">
                        Checking complainant and respondent residency...
                    </div>
                    <div class="kp-check-controls">
                        <label class="kp-inline-check" id="kpRespExceptionWrap" hidden>
                            <input type="checkbox" id="chkKpPanelRespException" <?php echo $respException ? 'checked' : ''; ?>>
                            <span>Verified §409(c/d) exception: dispute involves real property or workplace/school in Barangay Tumana.</span>
                        </label>
                        <label class="kp-inline-check">
                            <input type="checkbox" name="parties_in_diff_lgu" id="chkKpDifferentLgu" value="1" <?php echo $partiesInDiffLgu ? 'checked' : ''; ?>>
                            <span>Parties live in different cities/municipalities (not same-barangay residents).</span>
                        </label>
                        <label class="kp-inline-check">
                            <input type="checkbox" name="property_in_diff_lgu" id="chkKpPropertyDiffLgu" value="1" <?php echo $propertyInDiffLgu ? 'checked' : ''; ?>>
                            <span>Disputed real property is outside Marikina City (§408(e)).</span>
                        </label>
                    </div>
                </div>

                <!-- 2. INDIVIDUAL / NATURAL PERSON CHECK -->
                <div class="kp-check-card" id="kpCheckCapacityCard">
                    <div class="kp-check-card-head">
                        <div>
                            <div class="kp-check-title">2. Individual Person Check</div>
                            <div class="kp-check-cite">R.A. 7160 §408(a)–(b) • SC Circ. 14-93</div>
                        </div>
                        <span class="kp-check-pill is-pass" id="kpCapacityStatusPill">Individual Persons</span>
                    </div>
                    <div class="kp-inline-alert" id="kpCorporateNameAlert" hidden></div>
                    <div class="kp-radio-list">
                        <label class="kp-radio-item">
                            <input type="radio" name="party_capacity_type" value="individual" <?php echo $capacityType === 'individual' ? 'checked' : ''; ?>>
                            <span>All parties are private individual (natural) persons</span>
                        </label>
                        <label class="kp-radio-item">
                            <input type="radio" name="party_capacity_type" value="juridical" <?php echo $capacityType === 'juridical' ? 'checked' : ''; ?>>
                            <span>Corporation, partnership, cooperative, or association (Excluded)</span>
                        </label>
                        <label class="kp-radio-item">
                            <input type="radio" name="party_capacity_type" value="government" <?php echo $capacityType === 'government' ? 'checked' : ''; ?>>
                            <span>Government agency or instrumentality (§408(a) Excluded)</span>
                        </label>
                        <label class="kp-radio-item">
                            <input type="radio" name="party_capacity_type" value="public_officer" <?php echo $capacityType === 'public_officer' ? 'checked' : ''; ?>>
                            <span>Public officer acting in official capacity (§408(b) Excluded)</span>
                        </label>
                    </div>
                </div>

                <!-- 3. IMPRISONMENT <= 1 YEAR -->
                <div class="kp-check-card" id="kpCheckImprisonmentCard">
                    <div class="kp-check-card-head">
                        <div>
                            <div class="kp-check-title">3. Imprisonment Limit (≤ 1 Year)</div>
                            <div class="kp-check-cite">R.A. 7160 §408(c)</div>
                        </div>
                        <span class="kp-check-pill is-pass" id="kpImprisonmentStatusPill">≤ 1 Year</span>
                    </div>
                    <div class="kp-select-wrap">
                        <label for="offenseImprisonmentMax" class="kp-select-label">Maximum statutory imprisonment for the offense:</label>
                        <select id="offenseImprisonmentMax" name="offense_imprisonment_max" class="form-control">
                            <option value="0" <?php echo $selectedImp === '0' ? 'selected' : ''; ?>>Civil Dispute / Not Applicable (No imprisonment)</option>
                            <option value="0.5" <?php echo $selectedImp === '0.5' ? 'selected' : ''; ?>>Criminal: Up to 6 months (Arresto Mayor — KP Eligible)</option>
                            <option value="1" <?php echo $selectedImp === '1' ? 'selected' : ''; ?>>Criminal: Over 6 months up to 1 year (Max KP Limit)</option>
                            <option value="2" <?php echo $selectedImp === '2' ? 'selected' : ''; ?>>Criminal: Exceeds 1 year (&gt; 1 Year — Outside KP Jurisdiction)</option>
                        </select>
                    </div>
                    <div class="kp-check-summary" id="kpImprisonmentHint" style="margin-top: 8px;">
                        KP covers criminal offenses punishable by imprisonment of 1 year or less.
                    </div>
                </div>

                <!-- 4. FINE / DAMAGES <= P5,000 -->
                <div class="kp-check-card" id="kpCheckFineCard">
                    <div class="kp-check-card-head">
                        <div>
                            <div class="kp-check-title">4. Fine / Claim Limit (≤ ₱5,000)</div>
                            <div class="kp-check-cite">R.A. 7160 §408(c)</div>
                        </div>
                        <span class="kp-check-pill is-pass" id="kpFineStatusPill">≤ ₱5,000</span>
                    </div>
                    <div class="kp-check-summary" id="kpFineSummary">
                        Current claim / estimated damages: ₱0.00 (within KP limit).
                    </div>
                    <div style="margin-top: 10px;">
                        <button type="button" class="btn-outline-sm" id="btnJumpToFineInput" style="font-size: 0.78rem; padding: 4px 10px;">
                            Review / Edit Monetary Amount
                        </button>
                    </div>
                </div>
            </div>

            <!-- Collapsible §408(d) / §412 Exclusions -->
            <details class="kp-extra-exclusions" <?php echo ($accusedDetained || $habeasNeeded || $provisionalNeeded || $prescriptionRisk || $indigenousCommunity || !$hasPrivateParty) ? 'open' : ''; ?>>
                <summary>Additional R.A. 7160 §§ 408(d), 412 &amp; DILG Exclusion Checks (Optional)</summary>
                <div class="kp-extra-exclusions-grid">
                    <label class="kp-inline-check">
                        <input type="checkbox" id="chkKpNoPrivateParty" <?php echo !$hasPrivateParty ? 'checked' : ''; ?>>
                        <span>Offense has no private offended party (§408(d))</span>
                    </label>
                    <label class="kp-inline-check">
                        <input type="checkbox" name="accused_under_detention" id="chkKpAccusedDetained" value="1" <?php echo $accusedDetained ? 'checked' : ''; ?>>
                        <span>Accused is currently under detention (§412(a))</span>
                    </label>
                    <label class="kp-inline-check">
                        <input type="checkbox" name="habeas_corpus_needed" id="chkKpHabeasCorpus" value="1" <?php echo $habeasNeeded ? 'checked' : ''; ?>>
                        <span>Personal liberty / habeas corpus required (§412(b))</span>
                    </label>
                    <label class="kp-inline-check">
                        <input type="checkbox" name="provisional_remedy_needed" id="chkKpProvisionalRemedy" value="1" <?php echo $provisionalNeeded ? 'checked' : ''; ?>>
                        <span>Action requires provisional remedy (§412(c))</span>
                    </label>
                    <label class="kp-inline-check">
                        <input type="checkbox" name="prescription_risk" id="chkKpPrescriptionRisk" value="1" <?php echo $prescriptionRisk ? 'checked' : ''; ?>>
                        <span>Action may be barred by statute of limitations (§412(d))</span>
                    </label>
                    <label class="kp-inline-check">
                        <input type="checkbox" name="indigenous_community" id="chkKpIndigenous" value="1" <?php echo $indigenousCommunity ? 'checked' : ''; ?>>
                        <span>Indigenous Cultural Community customary dispute mechanism (DILG advisory)</span>
                    </label>
                </div>
            </details>

            <!-- Live Overall Verdict -->
            <div id="kpOverallVerdictBanner" class="kp-verdict-banner is-eligible">
                <div class="kp-verdict-title" id="kpOverallVerdictTitle">
                    Eligible for Katarungang Pambarangay Conciliation
                </div>
                <ul class="kp-verdict-list" id="kpOverallVerdictList"></ul>
            </div>
        </section>
        <?php
    }
}
