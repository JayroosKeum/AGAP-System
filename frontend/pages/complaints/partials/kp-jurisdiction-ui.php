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

            <input type="hidden" name="offense_imprisonment_max" id="offenseImprisonmentMax" value="0">
            <input type="hidden" name="has_private_offended_party" id="kpHasPrivateOffendedParty" value="1">
            </div>

            <!-- Collapsible §412 Exclusions -->
            <details class="kp-extra-exclusions" <?php echo ($provisionalNeeded || $indigenousCommunity) ? 'open' : ''; ?>>
                <summary>Additional R.A. 7160 &amp; DILG Exclusion Checks (Optional)</summary>
                <div class="kp-extra-exclusions-grid">
                    <label class="kp-inline-check">
                        <input type="checkbox" name="provisional_remedy_needed" id="chkKpProvisionalRemedy" value="1" <?php echo $provisionalNeeded ? 'checked' : ''; ?>>
                        <span>Action requires provisional remedy (§412(c))</span>
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
        <script>
        (function () {
            const panel = document.getElementById('section-jurisdiction');
            if (!panel || panel.dataset.kpWired === '1') return;
            panel.dataset.kpWired = '1';

            const caseTypeSel = document.getElementById('caseType');
            const provisionalChk = document.getElementById('chkKpProvisionalRemedy');
            const diffLguChk = document.getElementById('chkKpDifferentLgu');
            const propDiffLguChk = document.getElementById('chkKpPropertyDiffLgu');
            const sameBrgyHidden = document.getElementById('kpPartiesSameBarangay');
            const govHidden = document.getElementById('kpPartyIsGovernment');
            const pubOffHidden = document.getElementById('kpPartyIsPublicOfficer');
            const jurHidden = document.getElementById('kpPartyIsJuridical');
            const capPill = document.getElementById('kpCapacityStatusPill');
            const venuePill = document.getElementById('kpVenueStatusPill');
            const overallBadge = document.getElementById('kpOverallStatusBadge');
            const verdictBanner = document.getElementById('kpOverallVerdictBanner');
            const verdictTitle = document.getElementById('kpOverallVerdictTitle');
            const verdictList = document.getElementById('kpOverallVerdictList');

            function syncKpPanel() {
                const capVal = document.querySelector('input[name="party_capacity_type"]:checked')?.value || 'individual';
                if (govHidden) govHidden.value = capVal === 'government' ? '1' : '0';
                if (pubOffHidden) pubOffHidden.value = capVal === 'public_officer' ? '1' : '0';
                if (jurHidden) jurHidden.value = capVal === 'juridical' ? '1' : '0';
                if (sameBrgyHidden && diffLguChk) sameBrgyHidden.value = diffLguChk.checked ? '0' : '1';

                const issues = [];
                if (diffLguChk?.checked) issues.push('Parties live in different cities/municipalities (§408(f)).');
                if (propDiffLguChk?.checked) issues.push('Disputed real property is outside Marikina City (§408(e)).');
                if (capVal !== 'individual') issues.push('Non-individual or public-capacity party (§408(a)-(b)).');
                if (provisionalChk?.checked) issues.push('Provisional remedy required (§412(c)).');

                if (venuePill) {
                    const vFail = Boolean(diffLguChk?.checked || propDiffLguChk?.checked);
                    venuePill.textContent = vFail ? 'Excluded' : 'Eligible';
                    venuePill.className = 'kp-check-pill ' + (vFail ? 'is-fail' : 'is-pass');
                }
                if (capPill) {
                    capPill.textContent = capVal === 'individual' ? 'Individual Persons' : 'Excluded';
                    capPill.className = 'kp-check-pill ' + (capVal === 'individual' ? 'is-pass' : 'is-fail');
                }

                const excluded = issues.length > 0;
                if (overallBadge) {
                    overallBadge.textContent = excluded ? 'Outside KP Jurisdiction' : 'Within KP Jurisdiction';
                    overallBadge.className = 'kp-overall-pill ' + (excluded ? 'is-excluded' : 'is-eligible');
                }
                if (verdictBanner) {
                    verdictBanner.className = 'kp-verdict-banner ' + (excluded ? 'is-excluded' : 'is-eligible');
                }
                if (verdictTitle) {
                    verdictTitle.textContent = excluded
                        ? 'KP Exclusion Triggered — Review Required'
                        : 'Eligible for Katarungang Pambarangay Conciliation';
                }
                if (verdictList) {
                    verdictList.replaceChildren();
                    issues.forEach(msg => {
                        const li = document.createElement('li');
                        li.textContent = msg;
                        verdictList.appendChild(li);
                    });
                }
            }

            panel.addEventListener('change', syncKpPanel);
            caseTypeSel?.addEventListener('change', syncKpPanel);
            syncKpPanel();
        })();
        </script>
        <?php
    }
}
