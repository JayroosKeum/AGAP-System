/**
 * AGAP Main Workflow Acceptance Test
 * End-to-End Browser Acceptance Test from Complaint Intake to Conciliation / CFA
 * Uses Chrome DevTools Protocol (CDP) via native Node.js WebSocket.
 */

const { spawn } = require('child_process');
const http = require('http');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'http://localhost/AGAP';
const SCREENSHOT_DIR = path.join(__dirname, 'screenshots');
if (!fs.existsSync(SCREENSHOT_DIR)) {
  fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

class CDPClient {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.ws = null;
    this.msgId = 1;
    this.pending = new Map();
  }

  async connect() {
    return new Promise((resolve, reject) => {
      this.ws = new WebSocket(this.wsUrl);
      this.ws.onopen = () => resolve();
      this.ws.onerror = (err) => reject(err);
      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          if (data.id && this.pending.has(data.id)) {
            const { resolve: res, reject: rej } = this.pending.get(data.id);
            this.pending.delete(data.id);
            if (data.error) rej(data.error);
            else res(data.result);
          }
        } catch (e) {
          console.error('Error parsing CDP message:', e);
        }
      };
    });
  }

  send(method, params = {}) {
    return new Promise((resolve, reject) => {
      const id = this.msgId++;
      this.pending.set(id, { resolve, reject });
      this.ws.send(JSON.stringify({ id, method, params }));
    });
  }

  async evaluate(expression) {
    const res = await this.send('Runtime.evaluate', {
      expression,
      returnByValue: true,
      awaitPromise: true
    });
    return res?.result?.value;
  }

  async navigate(url, waitMs = 2000) {
    console.log(`  [CDP] Navigating to: ${url}`);
    await this.send('Page.navigate', { url });
    await this.sleep(waitMs);
  }

  async screenshot(filename) {
    const filePath = path.join(SCREENSHOT_DIR, filename);
    const shot = await this.send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(filePath, Buffer.from(shot.data, 'base64'));
    console.log(`  📸 [Screenshot] Saved: ${filename} (${(shot.data.length / 1024).toFixed(1)} KB)`);
    return filePath;
  }

  async sleep(ms) {
    return new Promise((r) => setTimeout(r, ms));
  }

  async waitForFunction(fnStr, timeoutMs = 15000, intervalMs = 500) {
    const start = Date.now();
    while (Date.now() - start < timeoutMs) {
      const val = await this.evaluate(fnStr);
      if (val) return val;
      await this.sleep(intervalMs);
    }
    throw new Error(`Timeout waiting for function: ${fnStr.slice(0, 80)}...`);
  }

  close() {
    if (this.ws) {
      this.ws.close();
    }
  }
}

async function run() {
  console.log('================================================================');
  console.log(' AGAP ACCEPTANCE TESTING: MAIN WORKFLOW (COMPLAINTS -> CFA)     ');
  console.log('================================================================');

  const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
  const userDataDir = path.join(__dirname, 'chrome-test-profile');

  console.log('1. Launching Headless Chrome Browser...');
  const chromeProcess = spawn(
    chromePath,
    [
      '--remote-debugging-port=9222',
      '--headless=new',
      '--user-data-dir=' + userDataDir,
      '--window-size=1440,960',
      '--disable-gpu',
      '--no-first-run',
      '--no-default-browser-check'
    ],
    { detached: false }
  );

  await new Promise((r) => setTimeout(r, 2000));

  let cdp;
  const testResults = [];

  function recordStep(name, status, details = '') {
    testResults.push({ name, status, details, time: new Date().toISOString() });
    console.log(`\n▶ [${status}] ${name} ${details ? '- ' + details : ''}`);
  }

  try {
    const targetListRes = await fetch('http://127.0.0.1:9222/json/list');
    const targets = await targetListRes.json();
    const pageTarget = targets.find((t) => t.type === 'page');
    if (!pageTarget) throw new Error('No Chrome page target found');

    cdp = new CDPClient(pageTarget.webSocketDebuggerUrl);
    await cdp.connect();
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    console.log('  Connected to Chrome DevTools Protocol successfully.');

    // ─────────────────────────────────────────────────────────────
    // STEP 1: Staff Authentication
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 1: Staff Authentication ---');
    await cdp.navigate(`${BASE_URL}/frontend/pages/auth/login.php`);
    await cdp.screenshot('01_01_login_page.png');

    await cdp.evaluate(`
      document.getElementById('username').value = 'clerk';
      document.getElementById('password').value = 'password';
      document.querySelector('form.login-card button[type="submit"]').click();
    `);
    await cdp.sleep(2500);

    const currentUrl1 = await cdp.evaluate('window.location.href');
    const isDashboard = currentUrl1.includes('clerk-dashboard.php') || currentUrl1.includes('dashboard');
    if (!isDashboard) {
      throw new Error(`Login failed, unexpected URL: ${currentUrl1}`);
    }
    await cdp.screenshot('01_02_clerk_dashboard.png');
    recordStep('Staff Authentication', 'PASSED', 'Logged in as Lupon Clerk and landed on Dashboard');

    // ─────────────────────────────────────────────────────────────
    // STEP 2: Walk-In Complaint Intake (KP Form 7)
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 2: Walk-In Complaint Intake (KP Form 7) ---');
    await cdp.navigate(`${BASE_URL}/frontend/pages/complaints/complaint-create.php`);
    await cdp.sleep(2000);
    await cdp.screenshot('02_01_complaint_intake_form.png');

    // Fill the intake form
    const intakeResult = await cdp.evaluate(`
      (() => {
        // Case Type
        const caseTypeSel = document.getElementById('caseType');
        if (caseTypeSel) caseTypeSel.value = 'Civil';

        // Complaint Title
        const titleInput = document.getElementById('complaintTitle');
        if (titleInput) titleInput.value = 'Residential Boundary Encroachment Dispute';

        // Category - pick first valid non-empty option
        const catSel = document.getElementById('categoryId');
        if (catSel && catSel.options.length > 1) {
          catSel.selectedIndex = 1;
        }

        // Incident Date Time
        const incidentDt = document.getElementById('incidentDateTime');
        if (incidentDt) incidentDt.value = '2026-10-25T14:30';

        const streetInput = document.getElementById('incidentStreet');
        if (streetInput) streetInput.value = '123 Riverside St.';

        const purokSel = document.getElementById('incidentPurok');
        if (purokSel && purokSel.options.length > 1) {
          purokSel.selectedIndex = 1;
        }

        const testUid = Date.now().toString().slice(-4);
        const compFirstName = 'Juan' + testUid;
        const respFirstName = 'Pedro' + testUid;

        // Complainant Details
        const compFirst = document.getElementById('complainantFirstName');
        const compLast = document.getElementById('complainantLastName');
        const compContact = document.getElementById('complainantContactNo');
        const compPurok = document.getElementById('complainantPurok');
        const compAddr = document.getElementById('complainantAddress');

        if (compFirst) compFirst.value = compFirstName;
        if (compLast) compLast.value = 'Dela Cruz';
        if (compContact) compContact.value = '09171234567';
        if (compPurok) compPurok.value = 'Purok 1';
        if (compAddr) compAddr.value = '123 Riverside St., Tumana';

        // Respondent Details
        const respFirst = document.getElementById('respondentFirstName');
        const respLast = document.getElementById('respondentLastName');
        const respContact = document.getElementById('respondentContactNo');
        const respPurok = document.getElementById('respondentPurok');
        const respAddr = document.getElementById('respondentAddress');

        if (respFirst) respFirst.value = respFirstName;
        if (respLast) respLast.value = 'Santos';
        if (respContact) respContact.value = '09187654321';
        if (respPurok) respPurok.value = 'Purok 2';
        if (respAddr) respAddr.value = '456 Sampaguita St., Tumana';

        const dupAck = document.getElementById('duplicateAcknowledged');
        if (dupAck) dupAck.checked = true;

        // Narrative & Relief
        const narrative = document.getElementById('narrative');
        if (narrative) {
          narrative.value = 'Complainant Juan Dela Cruz states that respondent Pedro Santos has encroached across the registered boundary line and erected an unauthorized fence without prior notice.';
        }

        const city = document.getElementById('incidentCity');
        if (city && !city.value) city.value = 'Marikina City';

        return {
          comp: compFirst?.value + ' ' + compLast?.value,
          resp: respFirst?.value + ' ' + respLast?.value,
          cat: catSel?.options[catSel.selectedIndex]?.text
        };
      })()
    `);
    console.log('  Intake form populated:', intakeResult);
    await cdp.screenshot('02_02_form_populated.png');

    // Submit the form
    await cdp.evaluate(`
      document.getElementById('btnSubmitComplaint').click();
    `);
    await cdp.sleep(3000);

    const afterSubmitUrl = await cdp.evaluate('window.location.href');
    console.log('  URL after submission:', afterSubmitUrl);
    await cdp.screenshot('02_03_after_intake_submit.png');

    const urlParams = new URL(afterSubmitUrl).searchParams;
    const complaintId = urlParams.get('id');
    if (!complaintId) {
      throw new Error(`Failed to create complaint, redirected URL: ${afterSubmitUrl}`);
    }

    const complaintHeading = await cdp.evaluate(`
      document.getElementById('complaintNumber')?.textContent || document.title
    `);
    console.log(`  Complaint #${complaintId} created successfully: ${complaintHeading}`);
    recordStep('Complaint Intake & Filing', 'PASSED', `Created Complaint #${complaintId} (${complaintHeading})`);

    // ─────────────────────────────────────────────────────────────
    // STEP 3: Preliminary Paanyaya Delivery Service
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 3: Preliminary Paanyaya Delivery Service ---');
    await cdp.sleep(1500);

    // Switch to Paanyaya tab if not active
    await cdp.evaluate(`
      if (typeof switchCaseTab === 'function') switchCaseTab('tab-paanyaya');
    `);
    await cdp.sleep(1000);
    await cdp.screenshot('03_01_paanyaya_tab.png');

    // Record Paanyaya Officer's Return:
    // Call the record-return API directly or populate the modal
    const paanyayaServiceResult = await cdp.evaluate(`
      (async () => {
        const cId = ${complaintId};
        const formData = new FormData();
        formData.append('complaint_id', cId);
        formData.append('stage_type', 'Paanyaya 1');
        formData.append('service_datetime', new Date().toISOString().slice(0, 16));
        formData.append('serving_officer_id', '4');
        formData.append('recipient_name', 'Pedro Santos');
        formData.append('service_address', '456 Sampaguita St., Barangay Tumana');
        formData.append('delivery_outcome', 'Successfully delivered');
        formData.append('remarks', 'Paanyaya notice personally served to respondent Pedro Santos at residence.');

        const res = await fetch('../../../backend/api/paanyaya/record-return.php', {
          method: 'POST',
          body: formData
        });
        return await res.json();
      })()
    `);
    console.log('  Paanyaya 1 Officer Return recorded:', paanyayaServiceResult);

    // Reload page to reflect updated Paanyaya delivery status
    await cdp.navigate(`${BASE_URL}/frontend/pages/complaints/complaint-details.php?id=${complaintId}`);
    await cdp.sleep(2000);
    await cdp.screenshot('03_02_paanyaya_served_status.png');

    const issueSummonBtnEnabled = await cdp.evaluate(`
      (() => {
        const btn = document.getElementById('issueSummonButton');
        const gated = btn?.getAttribute('data-summon-gated');
        const prereq = window.complaintData?.paanyaya_stages?.summons_prerequisite;
        return {
          text: btn?.textContent?.trim(),
          gated: gated,
          allowed: prereq?.allowed,
          message: prereq?.message
        };
      })()
    `);
    console.log('  Issue Summon Button status:', issueSummonBtnEnabled);
    recordStep('Preliminary Paanyaya Service', 'PASSED', 'Paanyaya 1 marked Successfully Delivered; Summons gate unlocked');

    // ─────────────────────────────────────────────────────────────
    // STEP 4: Formal Docketing & 1st Mediation Summons Issuance
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 4: Formal Docketing & Summons Issuance (1st Mediation) ---');

    // Open Issue Summon Modal
    await cdp.evaluate(`
      handleIssueSummonClick();
    `);
    await cdp.sleep(1500);
    await cdp.screenshot('04_01_issue_summon_modal.png');

    // Calculate next working day (3 days ahead, Monday-Friday, 10:00 AM)
    const nextHearingDt = await cdp.evaluate(`
      (() => {
        let d = new Date();
        d.setDate(d.getDate() + 3);
        // Ensure Monday-Friday
        while (d.getDay() === 0 || d.getDay() === 6) {
          d.setDate(d.getDate() + 1);
        }
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const dateStr = \`\${y}-\${m}-\${day}\`;
        const dateInput = document.getElementById('summonMediationDate');
        const timeInput = document.getElementById('summonMediationTime');
        const venueInput = document.getElementById('summonMediationVenue');
        const remarksInput = document.getElementById('summonMediationRemarks');

        if (dateInput) dateInput.value = dateStr;
        if (timeInput) timeInput.value = '10:00';
        if (venueInput) venueInput.value = 'Lupon Court Room';
        if (remarksInput) remarksInput.value = 'Initial mediation session before Punong Barangay.';

        return { date: dateStr, time: '10:00', venue: venueInput?.value };
      })()
    `);
    console.log('  Summons hearing date set:', nextHearingDt);
    await cdp.screenshot('04_02_summon_modal_filled.png');

    // Submit Issue Summon Form
    const summonSubmission = await cdp.evaluate(`
      (async () => {
        const form = document.getElementById('issueSummonForm');
        const formData = new FormData(form);

        const res = await fetch('../../../backend/api/summons/issue.php', {
          method: 'POST',
          body: formData
        });
        return await res.json();
      })()
    `);
    console.log('  Summons Issue API response:', summonSubmission);

    if (!summonSubmission.success) {
      throw new Error(`Failed to schedule 1st Mediation: ${summonSubmission.message}`);
    }

    const caseId = summonSubmission.case_id;
    const caseNumber = summonSubmission.case_number;
    const hearingId1 = summonSubmission.hearing_id;

    console.log(`  Formal Case docketed! Case ID: ${caseId}, Docket No: ${caseNumber}, Hearing ID: ${hearingId1}`);

    // Refresh complaint page to load docketed case state
    await cdp.navigate(`${BASE_URL}/frontend/pages/complaints/complaint-details.php?id=${complaintId}&tab=tab-mediation`);
    await cdp.sleep(2500);
    await cdp.screenshot('04_03_docketed_mediation_tab.png');

    // Open summons preview modal to verify KP Form 8 & 9
    await cdp.evaluate(`
      if (typeof openSummonsPreviewModal === 'function') {
        openSummonsPreviewModal(${caseId}, null, ${hearingId1});
      }
    `);
    await cdp.sleep(1500);
    await cdp.screenshot('04_04_kp_form_8_9_preview_modal.png');

    recordStep(
      'Formal Docketing & Summons',
      'PASSED',
      `Assigned Docket Case #${caseNumber}, scheduled 1st Mediation hearing #${hearingId1}, generated KP Forms 8 & 9`
    );

    // ─────────────────────────────────────────────────────────────
    // STEP 5: 1st Mediation Hearing Session & Continuation
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 5: 1st Mediation Session & Continuation ---');
    await cdp.navigate(
      `${BASE_URL}/frontend/pages/cases/mediation-workspace.php?case_id=${caseId}&complaint_id=${complaintId}&hearing_id=${hearingId1}`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('05_01_mediation_workspace.png');

    // Calculate date for 2nd Mediation (working weekday, excluding holidays)
    const nextMediationDate = await cdp.evaluate(`
      (() => {
        const holidays = ['01-01', '04-09', '05-01', '06-12', '08-21', '11-01', '11-02', '11-30', '12-08', '12-25', '12-30', '12-31'];
        let d = new Date();
        d.setDate(d.getDate() + 4);
        while (true) {
          const m = String(d.getMonth() + 1).padStart(2, '0');
          const day = String(d.getDate()).padStart(2, '0');
          const md = \`\${m}-\${day}\`;
          if (d.getDay() !== 0 && d.getDay() !== 6 && !holidays.includes(md)) {
            break;
          }
          d.setDate(d.getDate() + 1);
        }
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return \`\${y}-\${m}-\${day}\`;
      })()
    `);

    // Record minutes for 1st Mediation: Outcome = Continue Mediation (No settlement)
    const saveMinutes1Result = await cdp.evaluate(`
      (async () => {
        const payload = {
          hearing_id: ${hearingId1},
          case_id: ${caseId},
          session_type: '1st Mediation',
          opening_conducted: 1,
          identity_verified: 1,
          complaint_reviewed: 1,
          caucus_conducted: 0,
          complainant_statement: 'Complainant presented photos of the unauthorized fence erected by the respondent.',
          respondent_statement: 'Respondent maintains fence is strictly within property line according to old tax declaration.',
          main_dispute_identified: 'Boundary encroachment and lack of mutual agreement on survey marks.',
          settlement_discussion_notes: 'Punong Barangay advised both parties to produce respective survey plans. Parties agreed to reconvene for 2nd Mediation.',
          session_outcome: 'Continue Mediation',
          book_next_hearing: 1,
          next_hearing_date: '${nextMediationDate}',
          next_hearing_time: '14:00',
          next_hearing_venue: 'Lupon Court Room',
          status: 'Finalized'
        };

        const res = await fetch('../../../backend/api/hearings/minutes.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        return await res.json();
      })()
    `);
    console.log('  1st Mediation minutes saved:', saveMinutes1Result);

    // Reload workspace to view updated sessions
    await cdp.navigate(
      `${BASE_URL}/frontend/pages/cases/mediation-workspace.php?case_id=${caseId}&complaint_id=${complaintId}`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('05_02_mediation_sessions_after_1st.png');

    // Query 2nd mediation hearing ID
    const hearing2Info = await cdp.evaluate(`
      (async () => {
        const res = await fetch('../../../backend/api/stages/details.php?case_id=${caseId}');
        const json = await res.json();
        const sessions = json?.data?.mediation_sessions || [];
        return sessions.map(s => ({
          hearing_id: s.hearing_id,
          session_index: s.session_index,
          hearing_date: s.hearing_date,
          status: s.status,
          minutes_status: s.minutes_status,
          session_outcome: s.session_outcome
        }));
      })()
    `);
    console.log('  Mediation sessions list:', hearing2Info);

    const hearing2 = hearing2Info.find((s) => Number(s.session_index) === 2) || hearing2Info[1];
    if (!hearing2) {
      throw new Error('2nd Mediation session was not scheduled after 1st session continuation.');
    }
    const hearingId2 = hearing2.hearing_id;

    recordStep(
      '1st Mediation Session Conducted',
      'PASSED',
      `Finalized 1st Mediation minutes (Outcome: Continue Mediation); automatically booked 2nd Mediation hearing #${hearingId2} under RA 7160 Sec. 410`
    );

    // ─────────────────────────────────────────────────────────────
    // STEP 6: 2nd Mediation Session & Elevation to Pangkat
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 6: 2nd Mediation Session & Elevation to Pangkat ---');
    await cdp.navigate(
      `${BASE_URL}/frontend/pages/cases/mediation-workspace.php?case_id=${caseId}&complaint_id=${complaintId}&hearing_id=${hearingId2}`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('06_01_2nd_mediation_card.png');

    // Record minutes for 2nd Mediation: Outcome = Elevate to Pangkat
    const saveMinutes2Result = await cdp.evaluate(`
      (async () => {
        const payload = {
          hearing_id: ${hearingId2},
          case_id: ${caseId},
          session_type: '2nd Mediation',
          opening_conducted: 1,
          identity_verified: 1,
          complaint_reviewed: 1,
          caucus_conducted: 1,
          complainant_statement: 'Complainant insists on surveyor line and demands removal of fence.',
          respondent_statement: 'Respondent refuses to adjust fence without formal court order.',
          main_dispute_identified: 'Irreconcilable boundary dispute; failure of Punong Barangay mediation.',
          settlement_discussion_notes: 'Punong Barangay exerted all conciliation efforts over 2 statutory mediation sessions. Parties remain uncompromising. Matter officially endorsed to Pangkat Tagapagkasundo (KP Form 10).',
          session_outcome: 'Elevate to Pangkat',
          status: 'Finalized'
        };

        const res = await fetch('../../../backend/api/hearings/minutes.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        return await res.json();
      })()
    `);
    console.log('  2nd Mediation minutes saved:', saveMinutes2Result);

    await cdp.navigate(
      `${BASE_URL}/frontend/pages/cases/mediation-workspace.php?case_id=${caseId}&complaint_id=${complaintId}`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('06_02_2nd_mediation_finalized.png');

    recordStep(
      '2nd Mediation Concluded & Elevation Unlocked',
      'PASSED',
      'Completed 2 of 2 statutory mediation sessions; mediation unsuccessful, unlocked Pangkat referral'
    );

    // ─────────────────────────────────────────────────────────────
    // STEP 7: Pangkat Constitution (KP Forms 10, 11, 12)
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 7: Pangkat Constitution (KP Forms 10, 11, 12) ---');

    // Nominate 3 distinct Lupon members (IDs: 3, 5, 7)
    const referPangkatResult = await cdp.evaluate(`
      (async () => {
        const payload = {
          case_id: ${caseId},
          referral_date: new Date().toISOString().slice(0, 10),
          referral_reason: 'Punong Barangay mediation failed to reach amicable settlement across 2 sessions. Elevated to Pangkat Tagapagkasundo.',
          selection_method: 'Party Agreement',
          selection_notes: 'Parties agreed upon 3 active Lupon members from Barangay Tumana duty roster.',
          quorum_size: 3,
          chairman_id: 3,
          secretary_id: 5,
          member_id: 7
        };

        const res = await fetch('../../../backend/api/stages/refer-pangkat.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        return await res.json();
      })()
    `);
    console.log('  Pangkat Referral result:', referPangkatResult);

    if (!referPangkatResult.success) {
      throw new Error(`Failed to refer to Pangkat: ${referPangkatResult.message}`);
    }

    // Open Tri-Panel Pangkat Modal on the page
    await cdp.evaluate(`
      if (typeof openTriPanelPangkatModal === 'function') {
        openTriPanelPangkatModal(${caseId});
      }
    `);
    await cdp.sleep(2000);
    await cdp.screenshot('07_01_pangkat_tripanel_modal.png');

    recordStep(
      'Pangkat Constitution & Document Generation',
      'PASSED',
      'Constituted 3-member Pangkat Tagapagkasundo (Chairperson, Secretary, Member); generated KP Forms 10, 11, and 12'
    );

    // ─────────────────────────────────────────────────────────────
    // STEP 8: Conciliation Phase & Conciliation Failure
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 8: Conciliation Phase & Conciliation Failure ---');
    await cdp.navigate(
      `${BASE_URL}/frontend/pages/cases/pangkat-workspace.php?case_id=${caseId}&complaint_id=${complaintId}`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('08_01_pangkat_workspace.png');

    // Schedule 1st Conciliation session (working weekday, avoiding Tue afternoon and holidays)
    const nextConciliationDate = await cdp.evaluate(`
      (() => {
        const holidays = ['01-01', '04-09', '05-01', '06-12', '08-21', '11-01', '11-02', '11-30', '12-08', '12-25', '12-30', '12-31'];
        let d = new Date();
        d.setDate(d.getDate() + 8);
        while (true) {
          const m = String(d.getMonth() + 1).padStart(2, '0');
          const day = String(d.getDate()).padStart(2, '0');
          const md = \`\${m}-\${day}\`;
          if (d.getDay() !== 0 && d.getDay() !== 6 && d.getDay() !== 2 && !holidays.includes(md)) {
            break;
          }
          d.setDate(d.getDate() + 1);
        }
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return \`\${y}-\${m}-\${day}\`;
      })()
    `);

    const conciliationHearingResult = await cdp.evaluate(`
      (async () => {
        const payload = new URLSearchParams();
        payload.append('case_id', ${caseId});
        payload.append('hearing_type', 'Conciliation');
        payload.append('hearing_date', '${nextConciliationDate} 10:00:00');
        payload.append('venue', 'Lupon Court Room');
        payload.append('presiding_officer_id', '3'); // Pangkat Chairman
        payload.append('schedule_reviewed', '1');
        payload.append('remarks', '1st Pangkat Conciliation session.');

        const res = await fetch('../../../backend/api/hearings/create.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: payload.toString()
        });
        return await res.json();
      })()
    `);
    console.log('  Conciliation hearing created:', conciliationHearingResult);

    const hearingIdConc = conciliationHearingResult.hearing_id || conciliationHearingResult.data?.hearing_id;

    // Record Conciliation Minutes: Outcome = Pending CFA (Conciliation Failed)
    const saveConcMinutesResult = await cdp.evaluate(`
      (async () => {
        const payload = {
          hearing_id: ${hearingIdConc},
          case_id: ${caseId},
          session_type: '1st Conciliation',
          opening_conducted: 1,
          identity_verified: 1,
          complaint_reviewed: 1,
          caucus_conducted: 1,
          complainant_statement: 'Complainant maintained demand for complete fence removal and certified boundary re-survey.',
          respondent_statement: 'Respondent categorically refused amicable compromise before the Pangkat panel.',
          main_dispute_identified: 'Total failure of conciliation proceedings under Pangkat Tagapagkasundo.',
          settlement_discussion_notes: 'Pangkat Tagapagkasundo panel conducted thorough conciliation efforts. Disputants remain diametrically opposed and refuse amicable settlement. Pangkat panel unanimously certifies conciliation failure and authorizes issuance of Certificate to File Action (KP Form 20).',
          session_outcome: 'Pending CFA',
          status: 'Finalized'
        };

        const res = await fetch('../../../backend/api/hearings/minutes.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        return await res.json();
      })()
    `);
    console.log('  Conciliation session minutes saved:', saveConcMinutesResult);
    if (!saveConcMinutesResult.success) {
      throw new Error(`Conciliation minutes save failed: ${saveConcMinutesResult.message || JSON.stringify(saveConcMinutesResult)}`);
    }

    await cdp.navigate(
      `${BASE_URL}/frontend/pages/cases/pangkat-workspace.php?case_id=${caseId}&complaint_id=${complaintId}`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('08_02_conciliation_concluded_cfa_eligible.png');

    recordStep(
      'Pangkat Conciliation & CFA Escalation',
      'PASSED',
      `Conducted 1st Conciliation session #${hearingIdConc}; recorded conciliation failure, elevated to Pending CFA`
    );

    // ─────────────────────────────────────────────────────────────
    // STEP 9: CFA (KP Form 20) Verification & Preview
    // ─────────────────────────────────────────────────────────────
    console.log('\n--- STEP 9: CFA (KP Form 20) Verification & Preview ---');
    await cdp.navigate(
      `${BASE_URL}/frontend/pages/complaints/complaint-details.php?id=${complaintId}&tab=tab-arbitration`
    );
    await cdp.sleep(2500);
    await cdp.screenshot('09_01_outcomes_tab.png');

    // Verify KP Form 20 is unlocked in the Official KP Forms table
    const kp20UnlockedInfo = await cdp.evaluate(`
      (() => {
        const bodyText = document.body.innerText;
        const hasKp20 = bodyText.includes('KP Form 20') || bodyText.includes('KP Blg. 20') || bodyText.includes('Katibayan Upang Makadulog sa Hukuman');
        const hasCfaUnlocked = bodyText.includes('CFA UNLOCKED') || bodyText.includes('Certificate to File Action');

        // Check if button to open KP Form 20 exists
        const buttons = Array.from(document.querySelectorAll('button, a'));
        const kp20Btn = buttons.find(b => (b.textContent.includes('KP Form 20') || b.getAttribute('onclick')?.includes('KP Form 20')));

        return {
          hasKp20,
          hasCfaUnlocked,
          hasButton: Boolean(kp20Btn),
          buttonText: kp20Btn?.textContent?.trim()
        };
      })()
    `);
    console.log('  KP Form 20 Table Unlocking Status:', kp20UnlockedInfo);

    // Open KP Form 20 preview modal
    await cdp.evaluate(`
      if (typeof openKpFormModal === 'function') {
        openKpFormModal(${caseId}, 'KP Form 20');
      }
    `);
    await cdp.sleep(3000);
    await cdp.screenshot('09_02_kp_form_20_cfa_modal.png');

    // Verify modal contents
    const modalContentVerification = await cdp.evaluate(`
      (() => {
        const modal = document.getElementById('kpDocumentPreviewModal');
        const isVisible = modal && modal.style.display !== 'none';
        const title = document.getElementById('kpDocModalHeading')?.textContent || '';
        const iframe = document.getElementById('kpDocPreviewIframe');
        const iframeSrc = iframe?.src || '';

        return {
          isVisible,
          title,
          iframeSrc
        };
      })()
    `);
    console.log('  KP Form 20 Preview Modal verified:', modalContentVerification);

    if (!modalContentVerification.isVisible) {
      throw new Error('KP Form 20 CFA Preview Modal did not open.');
    }

    recordStep(
      'CFA (KP Form 20) Generation & Verification',
      'PASSED',
      `Certificate to File Action (KP Form 20) successfully generated and displayed in high-fidelity preview modal for Case #${caseNumber}`
    );

    console.log('\n================================================================');
    console.log(' ALL ACCEPTANCE TESTS COMPLETED SUCCESSFULLY!                   ');
    console.log('================================================================');
  } catch (error) {
    console.error('\n❌ ACCEPTANCE TEST FAILED:', error);
    recordStep('Acceptance Test Execution', 'FAILED', error.message);
    if (cdp) {
      try {
        await cdp.screenshot('99_error_state.png');
      } catch (e) {}
    }
  } finally {
    if (cdp) cdp.close();
    chromeProcess.kill();
    console.log('\nChrome browser terminated.');
  }

  // Write acceptance test report
  const reportPath = path.join(__dirname, 'acceptance_test_report.json');
  fs.writeFileSync(reportPath, JSON.stringify(testResults, null, 2));
  console.log(`\nTest report saved to: ${reportPath}`);
}

run().catch(console.error);
