<?php
session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);

if (!in_array($roleId, [1, 2, 3], true)) {
    http_response_code(403);
    die('Access Denied');
}

$canManageSettlements = in_array($roleId, [1, 2], true);

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/hearings.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/hearings.css'); ?>">
<link rel="stylesheet" href="../../assets/css/documents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/documents.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="page-header">
            <div>
                <h1>Amicable Settlements &amp; Compliance</h1>
                <p>Manage settlement agreements, installment tracking, 10-day repudiation period, and execution motions.</p>
            </div>
            <?php if ($canManageSettlements): ?>
            <button
                type="button"
                class="btn-create"
                onclick="openNewSettlementModal()"
            >
                + Record Amicable Settlement
            </button>
            <?php endif; ?>
        </div>

        <div id="settlementAlert" role="alert" style="display: none;" class="alert"></div>

        <!-- KPI Cards -->
        <div class="kpi-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 20px;">
            <div style="background: #fff; padding: 16px 20px; border-radius: 10px; border: 1px solid #e2e8f0; box-shadow: 0 1px 3px rgba(0,0,0,0.04);">
                <div style="font-size: 0.8rem; color: #64748b; font-weight: 600; text-transform: uppercase;">Total Settlements</div>
                <div id="kpiTotalSettlements" style="font-size: 1.6rem; font-weight: 700; color: #1e293b; margin-top: 4px;">0</div>
            </div>
            <div style="background: #eff6ff; padding: 16px 20px; border-radius: 10px; border: 1px solid #bfdbfe;">
                <div style="font-size: 0.8rem; color: #1d4ed8; font-weight: 600; text-transform: uppercase;">10-Day Repudiation Window</div>
                <div id="kpiRepudiationActive" style="font-size: 1.6rem; font-weight: 700; color: #1e40af; margin-top: 4px;">0</div>
            </div>
            <div style="background: #f0fdf4; padding: 16px 20px; border-radius: 10px; border: 1px solid #bbf7d0;">
                <div style="font-size: 0.8rem; color: #15803d; font-weight: 600; text-transform: uppercase;">Fully Complied / Paid</div>
                <div id="kpiFullyComplied" style="font-size: 1.6rem; font-weight: 700; color: #166534; margin-top: 4px;">0</div>
            </div>
            <div style="background: #fef2f2; padding: 16px 20px; border-radius: 10px; border: 1px solid #fecaca;">
                <div style="font-size: 0.8rem; color: #b91c1c; font-weight: 600; text-transform: uppercase;">Overdue / Breached / Executed</div>
                <div id="kpiOverdueBreached" style="font-size: 1.6rem; font-weight: 700; color: #991b1b; margin-top: 4px;">0</div>
            </div>
        </div>

        <!-- Filter / Search Toolbar -->
        <section class="hearings-search-card" style="margin-bottom: 20px;">
            <div style="padding: 16px 20px; display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;">
                <div style="flex: 1; min-width: 220px;">
                    <label for="filterSearch" style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Search Keyword</label>
                    <input type="search" id="filterSearch" class="search-input-field" placeholder="Search case no., complaint, terms...">
                </div>
                <div style="min-width: 180px;">
                    <label for="filterCompliance" style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Compliance Status</label>
                    <select id="filterCompliance" class="toolbar-select">
                        <option value="">All Compliance Statuses</option>
                        <option value="Pending">Pending</option>
                        <option value="Partially Paid">Partially Paid</option>
                        <option value="Fully Paid">Fully Paid</option>
                        <option value="Overdue">Overdue</option>
                        <option value="Breached">Breached</option>
                        <option value="Complied">Complied</option>
                        <option value="Violated">Violated</option>
                    </select>
                </div>
                <div style="min-width: 180px;">
                    <label for="filterRepudiation" style="font-size: 0.82rem; font-weight: 600; color: #475569; display: block; margin-bottom: 4px;">Repudiation Status</label>
                    <select id="filterRepudiation" class="toolbar-select">
                        <option value="">All Repudiation Statuses</option>
                        <option value="Within Repudiation Period">Within 10-Day Period</option>
                        <option value="Enforceable">Enforceable / Final</option>
                        <option value="Repudiated">Repudiated</option>
                    </select>
                </div>
                <div>
                    <button type="button" class="btn-create" onclick="loadSettlements(1)">Apply Filter</button>
                    <button type="button" class="btn-secondary" onclick="resetFilters()">Reset</button>
                </div>
            </div>
        </section>

        <!-- Settlements List Table -->
        <section class="document-card">
            <h2>Amicable Settlement Dockets</h2>
            <div class="table-container">
                <table class="combined-records-table">
                    <thead>
                        <tr>
                            <th>Case No. / Complaint</th>
                            <th>Settlement Date</th>
                            <th>Repudiation Status (10 Days)</th>
                            <th>Milestones</th>
                            <th>Obligation / Amount</th>
                            <th>Compliance</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="settlementsTableBody">
                        <tr><td colspan="7" class="empty-state">Loading amicable settlements...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="settlementPagination" class="complaints-pagination" style="display: none; padding: 14px 20px;">
                <span id="settlementPaginationSummary" class="complaints-pagination-summary"></span>
                <div id="settlementPaginationControls" class="complaints-pagination-controls"></div>
            </div>
        </section>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Create / Edit Settlement           -->
<!-- ========================================== -->
<div id="settlementModal" class="modal">
    <div class="modal-content modal-content-lg">
        <div class="modal-header">
            <h2 id="settlementModalTitle">Record Amicable Settlement (KP Form 16)</h2>
            <button type="button" class="close-btn" onclick="closeModal('settlementModal')">&times;</button>
        </div>
        <div id="settlementFormAlert" class="alert" style="display: none; margin-bottom: 14px;"></div>
        <form id="settlementForm" onsubmit="handleSaveSettlement(event)">
            <input type="hidden" name="settlement_id" id="formSettlementId">

            <div class="form-group">
                <label for="formCaseId">Select Case Docket <span class="required-mark">*</span></label>
                <select id="formCaseId" name="case_id" required onchange="handleCaseSelectionChange(this.value)">
                    <option value="">Select a case docket</option>
                </select>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 14px;">
                <div class="form-group">
                    <label for="formSettlementDate">Settlement Date <span class="required-mark">*</span></label>
                    <input type="date" id="formSettlementDate" name="settlement_date" value="<?php echo date('Y-m-d'); ?>" required onchange="updateRepudiationHelpDate(this.value)">
                    <small style="color: #64748b; font-size: 0.8rem; display: block; margin-top: 4px;">
                        10-day statutory repudiation deadline starts from this date.
                    </small>
                </div>
                <div class="form-group">
                    <label for="formRepudiationDeadline">10-Day Repudiation Deadline</label>
                    <input type="date" id="formRepudiationDeadline" readonly style="background: #f8fafc;">
                    <small id="repudiationCountdownHint" style="color: #1e3a8a; font-size: 0.8rem; display: block; margin-top: 4px;">
                        Sec. 418, LGC: Either party may repudiate within 10 days.
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label for="formAgreementDetails">Settlement Terms &amp; Mutual Obligations <span class="required-mark">*</span></label>
                <textarea id="formAgreementDetails" name="agreement_details" rows="4" required placeholder="State complete agreed terms, commitments, concessions, payment amounts, and timelines verbatim as agreed upon by the parties."></textarea>
            </div>

            <!-- Financial / Obligation Section -->
            <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px; margin-bottom: 16px;">
                <h3 style="font-size: 0.92rem; font-weight: 700; color: #1e293b; margin: 0 0 10px;">Financial Obligations &amp; Installments</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="formTotalAmount">Total Settlement Amount (₱)</label>
                        <input type="number" step="0.01" min="0" id="formTotalAmount" name="total_amount" value="0.00" oninput="handleAmountChange()">
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="formResponsibleParty">Obligated / Paying Party</label>
                        <select id="formResponsibleParty" name="responsible_party">
                            <option value="Respondent">Respondent</option>
                            <option value="Complainant">Complainant</option>
                            <option value="Both">Both Parties (Mutual)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="formComplianceDueDate">Full Compliance Due Date</label>
                        <input type="date" id="formComplianceDueDate" name="compliance_due_date">
                    </div>
                </div>

                <div style="margin-top: 12px;">
                    <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.88rem; font-weight: 600; color: #1e293b;">
                        <input type="checkbox" id="formHasInstallment" name="has_installment" value="1" onchange="toggleInstallmentSchedule(this.checked)">
                        Split payment into installment schedule
                    </label>
                </div>

                <!-- Dynamic Installment Schedule Container -->
                <div id="installmentScheduleContainer" style="display: none; margin-top: 14px; border-top: 1px dashed #cbd5e1; pt-3; padding-top: 12px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #475569;">Installment Schedule</span>
                        <button type="button" class="btn-secondary" style="padding: 4px 10px; font-size: 0.78rem;" onclick="addInstallmentRow()">+ Add Installment</button>
                    </div>
                    <div id="installmentRowsList"></div>
                </div>
            </div>

            <!-- Milestone Checklist (Section H) -->
            <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 8px; padding: 14px 18px; margin-bottom: 18px;">
                <h3 style="font-size: 0.92rem; font-weight: 700; color: #166534; margin: 0 0 8px;">Statutory Milestone Checklist</h3>
                <p style="font-size: 0.8rem; color: #15803d; margin: 0 0 12px;">Per Section H rules, verify all mandatory procedural milestones before final archiving.</p>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 0.86rem; color: #1e293b;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_terms_read" name="terms_read_to_parties" value="1">
                        <span><strong>Terms Read:</strong> Read &amp; understood by both parties</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_comp_signed" name="complainant_signed" value="1">
                        <span><strong>Complainant:</strong> Signature / thumbmark affixed</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_resp_signed" name="respondent_signed" value="1">
                        <span><strong>Respondent:</strong> Signature / thumbmark affixed</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_pb_attested" name="pb_attested" value="1">
                        <span><strong>Punong Barangay:</strong> Attestation completed</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_sealed" name="barangay_sealed" value="1">
                        <span><strong>Barangay Seal:</strong> Official seal affixed to document</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_case_folder" name="original_in_case_folder" value="1">
                        <span><strong>Original:</strong> Filed safely in official case folder</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                        <input type="checkbox" id="m_copies_issued" name="certified_copies_issued" value="1">
                        <span><strong>Certified Copies:</strong> Issued to both parties</span>
                    </label>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('settlementModal')">Cancel</button>
                <button type="submit" class="btn-create" id="btnSaveSettlement">Save Settlement Record</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: View Settlement Details & Timeline -->
<!-- ========================================== -->
<div id="viewSettlementModal" class="modal">
    <div class="modal-content modal-content-lg">
        <div class="modal-header">
            <h2>Settlement Details &amp; Compliance Status</h2>
            <button type="button" class="close-btn" onclick="closeModal('viewSettlementModal')">&times;</button>
        </div>
        <div id="viewSettlementBody" style="padding: 10px 0;"></div>
        <div class="modal-actions">
            <button type="button" class="btn-secondary" onclick="closeModal('viewSettlementModal')">Close</button>
        </div>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Record Installment Payment          -->
<!-- ========================================== -->
<div id="paymentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Record Installment Payment</h2>
            <button type="button" class="close-btn" onclick="closeModal('paymentModal')">&times;</button>
        </div>
        <div id="paymentFormAlert" class="alert" style="display: none; margin-bottom: 14px;"></div>
        <form id="paymentForm" onsubmit="handleRecordPayment(event)">
            <input type="hidden" name="installment_id" id="payInstallmentId">
            <div class="form-group">
                <label>Installment Details</label>
                <div id="payInstallmentSummary" style="padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 0.88rem; color: #1e293b;"></div>
            </div>
            <div class="form-group">
                <label for="payAmount">Amount Paid (₱) <span class="required-mark">*</span></label>
                <input type="number" step="0.01" min="0.01" id="payAmount" name="amount_paid" required>
            </div>
            <div class="form-group">
                <label for="payDate">Payment Date &amp; Time <span class="required-mark">*</span></label>
                <input type="datetime-local" id="payDate" name="payment_date" required>
            </div>
            <div class="form-group">
                <label for="payReceipt">Official Receipt / Acknowledgment No. <span class="required-mark">*</span></label>
                <input type="text" id="payReceipt" name="receipt_number" placeholder="e.g., OR-2026-00412" required>
            </div>
            <div class="form-group">
                <label for="payNotes">Notes / Remarks</label>
                <textarea id="payNotes" name="notes" rows="2" placeholder="Optional notes regarding the payment or receiving party"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('paymentModal')">Cancel</button>
                <button type="submit" class="btn-create">Record Payment Receipt</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Sworn Statement of Repudiation      -->
<!-- ========================================== -->
<div id="repudiationModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Sworn Statement of Repudiation (Sec. 418)</h2>
            <button type="button" class="close-btn" onclick="closeModal('repudiationModal')">&times;</button>
        </div>
        <div id="repudiationFormAlert" class="alert" style="display: none; margin-bottom: 14px;"></div>
        <div style="background: #fffbeb; border: 1px solid #fef08a; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-size: 0.84rem; color: #854d0e;">
            <strong>Statutory Rule (Sec. 418, LGC):</strong> Any party may repudiate the settlement within ten (10) days from the date thereof, by filing a statement sworn to before the Lupon Chairman alleging that consent was vitiated by fraud, violence, or intimidation.
        </div>
        <form id="repudiationForm" onsubmit="handleFileRepudiation(event)">
            <input type="hidden" name="settlement_id" id="repSettlementId">
            <div class="form-group">
                <label for="repParty">Repudiating Party <span class="required-mark">*</span></label>
                <select id="repParty" name="repudiated_by" required>
                    <option value="">Select party filing repudiation</option>
                </select>
            </div>
            <div class="form-group">
                <label for="repReason">Sworn Grounds for Repudiation <span class="required-mark">*</span></label>
                <textarea id="repReason" name="repudiation_reason" rows="3" required placeholder="Specify why consent was vitiated (e.g. fraud, deceit, duress, or intimidation) under sworn oath."></textarea>
            </div>
            <div class="form-group">
                <label for="repDocPath">Supporting Document / Sworn Affidavit Path</label>
                <input type="text" id="repDocPath" name="supporting_document_path" placeholder="Path or reference to notarized sworn affidavit">
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('repudiationModal')">Cancel</button>
                <button type="submit" class="btn-danger" style="background: #dc2626; color: #fff; border: none; padding: 9px 18px; border-radius: 6px; font-weight: 600; cursor: pointer;">File Sworn Repudiation</button>
            </div>
        </form>
    </div>
</div>

<!-- ========================================== -->
<!-- MODAL: Motion for Execution (Section K)    -->
<!-- ========================================== -->
<div id="executionModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Motion for Execution &amp; Enforcement</h2>
            <button type="button" class="close-btn" onclick="closeModal('executionModal')">&times;</button>
        </div>
        <div id="executionFormAlert" class="alert" style="display: none; margin-bottom: 14px;"></div>
        <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px 16px; border-radius: 8px; margin-bottom: 14px; font-size: 0.84rem; color: #1e40af;">
            <strong>Sec. 417, LGC (Execution):</strong> The amicable settlement may be enforced by execution by the Lupon through the Punong Barangay within six (6) months from the date of the settlement or the date obligation fell due.
        </div>
        <form id="executionForm" onsubmit="handleFileExecution(event)">
            <input type="hidden" name="settlement_id" id="execSettlementId">
            <div class="form-group">
                <label for="execParty">Filing Party <span class="required-mark">*</span></label>
                <select id="execParty" name="motion_filed_by" required>
                    <option value="">Select party filing execution motion</option>
                </select>
            </div>
            <div class="form-group">
                <label for="execObligation">Obligation Violated <span class="required-mark">*</span></label>
                <textarea id="execObligation" name="obligation_violated" rows="2" required placeholder="Describe which specific terms, amounts, or commitments were breached or left unfulfilled."></textarea>
            </div>
            <div class="form-group">
                <label for="execAmount">Amount / Remedy Demanded <span class="required-mark">*</span></label>
                <input type="text" id="execAmount" name="amount_or_requirement" required placeholder="e.g. ₱5,000.00 unpaid balance or immediate vacation of premises">
            </div>
            <div class="form-group">
                <label for="execNotes">Evidence Notes / Non-Compliance Summary</label>
                <textarea id="execNotes" name="evidence_notes" rows="2" placeholder="Summary of unheeded demand letters, text notices, or failure to pay"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal('executionModal')">Cancel</button>
                <button type="submit" class="btn-create">Submit Motion for Execution</button>
            </div>
        </form>
    </div>
</div>

<script>
window.AGAP_SETTLEMENTS = Object.freeze({
    canManage: <?php echo $canManageSettlements ? 'true' : 'false'; ?>
});
</script>
<script src="../../assets/js/settlements.js?v=<?php echo time(); ?>"></script>

<?php include '../../layouts/footer.php'; ?>
