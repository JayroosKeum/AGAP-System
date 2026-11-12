<?php

session_start();

$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 3, 4], true)) {
    header('Location: ../auth/login.php');
    exit;
}

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/models/Paanyaya.php';
require_once __DIR__ . '/../../../backend/models/Setting.php';
require_once __DIR__ . '/../../../backend/services/CsrfService.php';

$paanyayaModel = new Paanyaya();
$statusFilter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$searchFilter = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$paanyayas = $paanyayaModel->getGroupedPaanyayaCases($statusFilter !== '' ? $statusFilter : null, $searchFilter !== '' ? $searchFilter : null);

$dbConn = (new Database())->connect();
$residentsForLookup = [];
try {
    $rStmt = $dbConn->query("
        SELECT resident_id, first_name, middle_name, last_name, suffix, purok, address, contact_no, email, home_latitude, home_longitude
        FROM residents
        ORDER BY last_name ASC, first_name ASC
    ");
    if ($rStmt) {
        $residentsForLookup = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $re) {
    $residentsForLookup = [];
}
$purokOptions = [
    'Purok 1', 'Purok 2', 'Purok 3', 'Purok 4', 'Purok 5', 'Purok 6', 'Purok 7',
    'Purok 8', 'Purok 9', 'Purok 10', 'Purok 11', 'Purok 12', 'Purok 13', 'Purok 14',
    'Doña Petra', 'Sitio', 'Non-Resident (Outside Barangay Tumana)'
];

// Count summary across grouped cases
$allList = $statusFilter === '' && $searchFilter === '' ? $paanyayas : $paanyayaModel->getGroupedPaanyayaCases();
$countTotal = count($allList);
$countScheduled = 0;
$countCompleted = 0;
$countCancelled = 0;
foreach ($allList as $item) {
    $st = $item['display_status'] ?? 'Scheduled';
    if (in_array($st, ['Scheduled', 'Pending', 'Docketed', 'Mediation', 'Conciliation', 'Arbitration', 'CFA Issued'], true)) $countScheduled++;
    elseif (in_array($st, ['Done', 'Completed', 'Resolved', 'Settled', 'Dismissed', 'Archived'], true)) $countCompleted++;
    elseif (in_array($st, ['Cancelled', 'Failed', 'Escalated', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated'], true)) $countCancelled++;
}

include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/complaints.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/complaints.css'); ?>">
<link rel="stylesheet" href="../../assets/css/agap-list-layout.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/agap-list-layout.css'); ?>">

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
.paanyaya-container {
    padding: 24px 32px;
    max-width: 1400px;
    margin: 0 auto;
}
.paanyaya-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
    flex-wrap: wrap;
    gap: 16px;
}
.paanyaya-header-title h1 {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 4px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}
.paanyaya-header-title p {
    font-size: 0.9rem;
    color: #64748b;
    margin: 0;
}
.paanyaya-badge-stage0 {
    font-size: 0.72rem;
    font-weight: 700;
    background: #e0e7ff;
    color: #3730a3;
    padding: 3px 9px;
    border-radius: 9999px;
    letter-spacing: 0.5px;
    text-transform: uppercase;
}
.btn-new-paanyaya {
    background: #2563eb;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.88rem;
    padding: 10px 18px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    box-shadow: 0 1px 3px rgba(37,99,235,0.25);
    transition: all .15s ease;
}
.btn-new-paanyaya:hover {
    background: #1d4ed8;
    transform: translateY(-1px);
    box-shadow: 0 4px 10px rgba(37,99,235,0.3);
}
.paanyaya-stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}
.paanyaya-stat-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.03);
    cursor: pointer;
    text-decoration: none;
    display: block;
    user-select: none;
    position: relative;
    transition: all .18s cubic-bezier(0.16, 1, 0.3, 1);
}
.paanyaya-stat-card:hover {
    transform: translateY(-2px);
    border-color: #cbd5e1;
    box-shadow: 0 6px 16px rgba(0,0,0,0.06);
}
.paanyaya-stat-card:focus-visible {
    outline: none;
    box-shadow: 0 0 0 3px rgba(37,99,235,0.35);
}
.paanyaya-stat-card.is-active {
    border-width: 2px;
}
.paanyaya-stat-card.card-all.is-active {
    border-color: #0f172a;
    background: #f8fafc;
    box-shadow: 0 0 0 1px #0f172a;
}
.paanyaya-stat-card.card-scheduled.is-active {
    border-color: #2563eb;
    background: #f8faff;
    box-shadow: 0 0 0 1px #2563eb;
}
.paanyaya-stat-card.card-settled.is-active {
    border-color: #16a34a;
    background: #f0fdf4;
    box-shadow: 0 0 0 1px #16a34a;
}
.paanyaya-stat-card.card-cancelled.is-active {
    border-color: #64748b;
    background: #f8fafc;
    box-shadow: 0 0 0 1px #64748b;
}
.paanyaya-stat-card .stat-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #64748b;
    margin-bottom: 6px;
}
.paanyaya-stat-card .stat-value {
    font-size: 1.7rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1;
}
.paanyaya-table-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
}
.paanyaya-toolbar {
    padding: 16px 20px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: #f8fafc;
}
.paanyaya-search-form {
    display: flex;
    align-items: center;
    gap: 10px;
    flex: 1;
    max-width: 500px;
}
.paanyaya-search-input {
    flex: 1;
    padding: 8px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.88rem;
    background: #ffffff;
}
.table-responsive {
    width: 100%;
    overflow-x: auto;
}
.paanyaya-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88rem;
    text-align: left;
}
.paanyaya-table th {
    background: #f8fafc;
    padding: 11px 14px;
    font-weight: 800;
    color: #64748b;
    border-bottom: 1px solid #e2e8f0;
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.035em;
}
.paanyaya-table td {
    padding: 13px 14px;
    border-bottom: 1px solid #edf2f7;
    color: #334155;
    vertical-align: middle;
}
.paanyaya-table tr:hover {
    background: #fafbff;
}
.status-pill-scheduled {
    background: #dbeafe;
    color: #1e40af;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #bfdbfe;
    display: inline-block;
}
.status-pill-resolved {
    background: #dcfce7;
    color: #15803d;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #bbf7d0;
    display: inline-block;
}
.status-pill-cancelled {
    background: #fee2e2;
    color: #991b1b;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #fecaca;
    display: inline-block;
}
.status-pill-escalated {
    background: #f3e8ff;
    color: #6b21a8;
    font-weight: 700;
    font-size: 0.75rem;
    padding: 3px 10px;
    border-radius: 9999px;
    border: 1px solid #e9d5ff;
    display: inline-block;
}
.btn-action-view {
    padding: 6px 12px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all .15s ease;
}
.btn-action-view:hover {
    background: #f8fafc;
    border-color: #94a3b8;
}
.btn-action-print {
    padding: 6px 12px;
    background: #f0fdf4;
    color: #15803d;
    border: 1px solid #bbf7d0;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    transition: all .15s ease;
}
.btn-action-print:hover {
    background: #dcfce7;
    border-color: #86efac;
}

/* Modals */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 9999;
    padding: 16px;
}
.modal-card {
    background: #ffffff;
    border-radius: 12px;
    width: 100%;
    max-width: 620px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.2);
    overflow: hidden;
    max-height: 92vh;
    display: flex;
    flex-direction: column;
}
.modal-card > form {
    display: flex;
    flex-direction: column;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
    margin: 0;
}
.paanyaya-parties-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 16px;
}
.paanyaya-parties-grid .party-section-card {
    margin-bottom: 0;
}
#schedulePaanyayaModal label span + span {
    display: none !important;
}
@media (max-width: 920px) {
    .paanyaya-parties-grid {
        grid-template-columns: 1fr;
    }
}
.modal-card-large {
    max-width: 920px;
}
.modal-head {
    padding: 16px 22px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #f8fafc;
    flex-shrink: 0;
}
.modal-head h3 {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 700;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: 8px;
}
.modal-close-btn {
    background: none;
    border: none;
    font-size: 1.4rem;
    line-height: 1;
    color: #64748b;
    cursor: pointer;
    padding: 4px;
    transition: color 0.15s ease;
}
.modal-close-btn:hover {
    color: #0f172a;
}
.modal-body {
    padding: 20px 24px;
    overflow-y: auto;
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
}
.modal-foot {
    padding: 14px 22px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    flex-shrink: 0;
}
.form-group {
    margin-bottom: 16px;
}
.form-group label {
    display: block;
    font-size: 0.84rem;
    font-weight: 600;
    color: #334155;
    margin-bottom: 6px;
}
.form-control {
    width: 100%;
    padding: 9px 12px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.9rem;
    box-sizing: border-box;
    background: #ffffff;
    font-family: inherit;
}
.form-control:focus {
    outline: none;
    border-color: #2563eb;
    box-shadow: 0 0 0 2px rgba(37,99,235,0.2);
}
.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
}
.form-row-3 {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 12px;
}
@media (max-width: 640px) {
    .form-row, .form-row-3 {
        grid-template-columns: 1fr;
    }
}
.modal-section-title {
    font-size: 0.88rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
#schedulePaanyayaModal label::after {
    content: none !important;
}
.party-section-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 14px 16px;
    margin-bottom: 16px;
}

/* Attendance Pills */
.attendance-party-box {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 12px;
}
.attendance-party-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.attendance-party-label {
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    color: #475569;
}
.attendance-party-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: #0f172a;
}
.attendance-pills-row {
    display: flex;
    gap: 8px;
}
.attendance-radio-pill {
    flex: 1;
    position: relative;
}
.attendance-radio-pill input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.attendance-pill-label {
    display: block;
    text-align: center;
    padding: 7px 10px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 0.82rem;
    font-weight: 700;
    color: #475569;
    background: #ffffff;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
}
.attendance-radio-pill input[value="Present"]:checked + .attendance-pill-label {
    background: #dcfce7;
    color: #15803d;
    border-color: #86efac;
    box-shadow: 0 0 0 1px #16a34a;
}
.attendance-radio-pill input[value="Absent"]:checked + .attendance-pill-label {
    background: #fee2e2;
    color: #b91c1c;
    border-color: #fca5a5;
    box-shadow: 0 0 0 1px #dc2626;
}

/* Escalation Button */
.btn-escalate-mediation {
    background: #7c3aed;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.84rem;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 1px 3px rgba(124, 58, 237, 0.25);
    transition: all .15s ease;
}
.btn-escalate-mediation:hover {
    background: #6d28d9;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(124, 58, 237, 0.3);
}
.btn-second-paanyaya {
    background: #0284c7;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.84rem;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s ease;
}
.btn-second-paanyaya:hover {
    background: #0369a1;
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(2, 132, 199, 0.3);
}
.btn-resolve-dialogue {
    background: #16a34a;
    color: #ffffff;
    font-weight: 600;
    font-size: 0.84rem;
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all .15s ease;
}
.btn-resolve-dialogue:hover {
    background: #15803d;
}
.is-locked-btn {
    opacity: 0.55 !important;
    cursor: not-allowed !important;
    pointer-events: none !important;
}

/* Case Grouping & Vertical Stepper Dropdown (modeled after step progression) */
tr[data-paanyaya-row="1"] {
    cursor: pointer;
    transition: background-color 0.14s ease;
}
tr[data-paanyaya-row="1"].is-expanded {
    background: #f0f9ff !important;
}
tr[data-paanyaya-row="1"].is-expanded td {
    border-bottom: 1px solid #bae6fd !important;
}
.paanyaya-status-dual {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 4px;
}
.paanyaya-step-chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 3px 9px;
    border-radius: 9999px;
    background: #eff6ff;
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
    white-space: nowrap;
}
.paanyaya-step-chip.is-multi {
    background: #ecfdf5;
    color: #15803d;
    border-color: #86efac;
}
.paanyaya-expand-btn {
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    border-radius: 6px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 0;
}
.paanyaya-expand-btn:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}
.paanyaya-expand-btn[aria-expanded="true"] {
    background: #dcfce7;
    border-color: #22c55e;
    color: #15803d;
}
.paanyaya-expand-btn svg {
    width: 14px;
    height: 14px;
    transition: transform 0.18s ease;
}
.paanyaya-expand-btn[aria-expanded="true"] svg {
    transform: rotate(90deg);
}
.paanyaya-series-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 9999px;
    font-size: 0.7rem;
    font-weight: 700;
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
.paanyaya-case-chip {
    display: inline-flex;
    align-items: center;
    padding: 1px 7px;
    border-radius: 6px;
    font-size: 0.7rem;
    font-weight: 700;
    background: #f1f5f9;
    color: #334155;
    border: 1px solid #e2e8f0;
}
.paanyaya-dropdown-row {
    background: #f8fafc;
}
.paanyaya-dropdown-row td.paanyaya-dropdown-cell {
    padding: 12px 20px 18px 56px;
    border-top: 0;
    background: #f8fafc;
}
.paanyaya-stepper-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    padding: 16px 20px;
    box-shadow: 0 4px 16px rgba(15, 23, 42, 0.05);
}
.paanyaya-stepper-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 14px;
    padding-bottom: 10px;
    border-bottom: 1px solid #f1f5f9;
}
.paanyaya-stepper-title {
    font-size: 0.8rem;
    font-weight: 800;
    color: #0f172a;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.paanyaya-vstepper {
    display: flex;
    flex-direction: column;
}
.paanyaya-vstep {
    display: flex;
    align-items: stretch;
    gap: 14px;
}
.paanyaya-vstep-rail {
    display: flex;
    flex-direction: column;
    align-items: center;
    width: 30px;
    flex-shrink: 0;
    position: relative;
}
.paanyaya-vstep-pointer {
    font-size: 0.72rem;
    font-weight: 900;
    line-height: 1;
    color: #16a34a;
    margin: 2px 0 5px;
    background: #ffffff;
    padding: 0 3px;
}
.paanyaya-vstep-circle {
    width: 28px;
    height: 28px;
    border-radius: 9999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 800;
    flex-shrink: 0;
    z-index: 1;
}
.paanyaya-vstep.is-done .paanyaya-vstep-circle {
    background: #22c55e;
    color: #ffffff;
    border: 2px solid #22c55e;
}
.paanyaya-vstep.is-current .paanyaya-vstep-circle {
    background: #22c55e;
    color: #ffffff;
    border: 2px solid #16a34a;
    box-shadow: 0 0 0 5px rgba(34, 197, 94, 0.22);
}
.paanyaya-vstep.is-upcoming .paanyaya-vstep-circle {
    background: #ffffff;
    color: #94a3b8;
    border: 2px solid #cbd5e1;
}
.paanyaya-vstep-line {
    width: 2px;
    flex: 1 1 auto;
    min-height: 20px;
    background: #cbd5e1;
    margin: 4px 0 2px;
}
.paanyaya-vstep.is-done .paanyaya-vstep-line {
    background: #22c55e;
}
.paanyaya-vstep:last-child .paanyaya-vstep-line {
    display: none;
}
.paanyaya-vstep-content {
    flex: 1 1 auto;
    min-width: 0;
    padding-bottom: 12px;
}
.paanyaya-vstep:last-child .paanyaya-vstep-content {
    padding-bottom: 0;
}
.paanyaya-vstep-box {
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    background: #ffffff;
    padding: 11px 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.paanyaya-vstep.is-current .paanyaya-vstep-box {
    background: linear-gradient(135deg, #f0fdf4 0%, #ffffff 100%);
    border: 1.5px solid #22c55e;
    box-shadow: 0 4px 14px rgba(34, 197, 94, 0.13);
}
.paanyaya-vstep.is-upcoming .paanyaya-vstep-box {
    background: #f8fafc;
    border: 1px dashed #cbd5e1;
}
.paanyaya-vstep-meta {
    display: flex;
    flex-direction: column;
    gap: 3px;
    min-width: 0;
}
.paanyaya-vstep-top {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}
.paanyaya-vstep-name {
    font-size: 0.88rem;
    font-weight: 700;
    color: #334155;
}
.paanyaya-vstep.is-current .paanyaya-vstep-name {
    color: #14532d;
    font-weight: 800;
}
.paanyaya-vstep.is-upcoming .paanyaya-vstep-name {
    color: #94a3b8;
    font-weight: 600;
}
.paanyaya-vstep-recent-pill {
    font-size: 0.67rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    padding: 2px 7px;
    border-radius: 9999px;
    background: #16a34a;
    color: #ffffff;
}
.paanyaya-vstep-sub {
    font-size: 0.78rem;
    color: #64748b;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.paanyaya-vstep.is-current .paanyaya-vstep-sub {
    color: #166534;
}
@media (max-width: 768px) {
    .agap-underline-tabs {
        flex-wrap: wrap;
        gap: 6px 14px;
    }
    .paanyaya-dropdown-row td.paanyaya-dropdown-cell {
        padding: 10px 12px 14px 12px;
    }
    .paanyaya-stepper-card {
        padding: 12px 14px;
        position: sticky;
        left: 12px;
        max-width: calc(100vw - 52px);
        box-sizing: border-box;
    }
}
</style>

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>

    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>

        <div class="agap-list-shell">
            <div class="agap-list-card">
                <!-- Header Section -->
                <div class="agap-list-header">
                    <div class="agap-list-title-wrap">
                        <div class="agap-list-title-row">
                            <h1>Paanyaya</h1>
                            <span class="agap-stage-pill">Stage 0 &middot; Informal Dialogue</span>
                        </div>
                        <p class="agap-list-subtitle">Pre-docketed amicable dispute invitations pursuant to Barangay Tumana KP process.</p>
                    </div>
                    <button type="button" class="agap-list-primary-btn" id="btnOpenScheduleModal">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        <span>Schedule New Paanyaya</span>
                    </button>
                </div>

                <!-- Underlined Tabs + Inline Search -->
                <div class="agap-list-subbar">
                    <nav class="agap-underline-tabs" aria-label="Paanyaya status filter">
                        <a href="index.php<?php echo $searchFilter !== '' ? '?search=' . urlencode($searchFilter) : ''; ?>"
                           class="agap-tab-item <?php echo $statusFilter === '' ? 'active' : ''; ?>"
                           data-status=""
                           id="paanyayaCardAll">
                            <span>All</span>
                            <span class="agap-tab-count"><?php echo $countTotal; ?></span>
                        </a>
                        <a href="index.php?status=Scheduled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>"
                           class="agap-tab-item <?php echo $statusFilter === 'Scheduled' ? 'active' : ''; ?>"
                           data-status="Scheduled"
                           id="paanyayaCardScheduled">
                            <span>Scheduled</span>
                            <span class="agap-tab-count"><?php echo $countScheduled; ?></span>
                        </a>
                        <a href="index.php?status=Resolved<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>"
                           class="agap-tab-item <?php echo in_array($statusFilter, ['Resolved', 'Completed', 'Settled', 'Done'], true) ? 'active' : ''; ?>"
                           data-status="Resolved"
                           id="paanyayaCardSettled">
                            <span>Settled / Done</span>
                            <span class="agap-tab-count"><?php echo $countCompleted; ?></span>
                        </a>
                        <a href="index.php?status=Cancelled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>"
                           class="agap-tab-item <?php echo in_array($statusFilter, ['Cancelled', 'Escalated', 'Failed'], true) ? 'active' : ''; ?>"
                           data-status="Cancelled"
                           id="paanyayaCardCancelled">
                            <span>Cancelled / Escalated</span>
                            <span class="agap-tab-count"><?php echo $countCancelled; ?></span>
                        </a>
                    </nav>

                    <form method="GET" action="index.php" class="agap-inline-controls" id="paanyayaSearchForm">
                        <?php if ($statusFilter !== ''): ?>
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>">
                        <?php endif; ?>
                        <div class="agap-inline-search">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="11" cy="11" r="8"></circle>
                                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                            </svg>
                            <input type="text" name="search" id="paanyayaSearchInput" placeholder="Search complainant, respondent, status..." value="<?php echo htmlspecialchars($searchFilter); ?>" autocomplete="off">
                            <button type="button" id="paanyayaClearSearchBtn" class="search-clear-btn" title="Clear search" aria-label="Clear search">&times;</button>
                        </div>
                        <?php if ($searchFilter !== ''): ?>
                            <a href="index.php<?php echo $statusFilter !== '' ? '?status=' . urlencode($statusFilter) : ''; ?>" class="agap-reset-link-btn" style="display:inline-flex; align-items:center; text-decoration:none;">Clear</a>
                        <?php endif; ?>
                    </form>
                </div>

                <div class="agap-list-meta">
                    <span>Click any row to expand the Paanyaya progression, schedule details, and manage invitations.</span>
                </div>

                <!-- Bordered Table Card -->
                <div class="agap-list-table-wrap">
                    <table class="paanyaya-table">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;" aria-label="Expand toggle"></th>
                                <th>Complainant</th>
                                <th>Respondent</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="paanyayaTbody">
                            <?php if (empty($paanyayas)): ?>
                                <tr id="paanyayaServerEmptyRow">
                                    <td colspan="4" style="text-align: center; color: #64748b; padding: 40px 20px;">
                                        <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">No Paanyaya invitations found</div>
                                        <p style="font-size: 0.84rem; margin: 4px 0 0; color: #64748b;">Click "Schedule New Paanyaya" above to issue an informal invitation.</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                    $resolveBadgeVariant = static function (string $status): string {
                                        if (in_array($status, ['Done', 'Resolved', 'Completed', 'Settled', 'Dismissed', 'Archived', 'CFA Issued', 'RESPONDENT_DEFAULT'], true)) {
                                            return 'is-green';
                                        }
                                        if (in_array($status, ['Escalated', 'DISMISSED_BARRED', 'Repudiated'], true)) {
                                            return 'is-purple';
                                        }
                                        if (in_array($status, ['Cancelled', 'Failed'], true)) {
                                            return 'is-red';
                                        }
                                        return 'is-blue';
                                    };

                                    $renderPaanyayaActions = static function (
                                        array $row,
                                        string $actionStatus,
                                        int $attemptNum,
                                        int $linkedCid,
                                        string $invLabel,
                                        string $compLabel,
                                        string $dateLabel,
                                        string $timeLabel,
                                        bool $includeStage = true
                                    ): string {
                                        $pid = (int)($row['paanyaya_id'] ?? 0);
                                        $cid = (int)($row['complaint_id'] ?? $linkedCid);
                                        $cnum = (string)($row['complaint_number'] ?? '');
                                        ob_start();
                                        ?>
                                        <button type="button" class="agap-btn-act is-primary-soft btn-preview-modal"
                                                data-paanyaya-id="<?php echo $pid; ?>"
                                                data-complaint-id="<?php echo $cid; ?>"
                                                data-attempt="<?php echo $attemptNum; ?>"
                                                data-name="<?php echo htmlspecialchars($invLabel, ENT_QUOTES); ?>"
                                                title="Preview Paanyaya Official Letter">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                            <span>Letter</span>
                                        </button>
                                        <?php if ($includeStage && $linkedCid > 0): ?>
                                            <a href="stage.php?complaint_id=<?php echo $linkedCid; ?>" class="agap-btn-act" title="Open the full Paanyaya stage workspace">
                                                <span>Stage</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($pid > 0): ?>
                                            <button type="button" class="agap-btn-act btn-open-attendance"
                                                    data-paanyaya-id="<?php echo $pid; ?>"
                                                    data-complaint-id="<?php echo $linkedCid; ?>"
                                                    data-invitee="<?php echo htmlspecialchars($invLabel, ENT_QUOTES); ?>"
                                                    data-complainant="<?php echo htmlspecialchars($compLabel, ENT_QUOTES); ?>"
                                                    data-date="<?php echo htmlspecialchars($dateLabel, ENT_QUOTES); ?>"
                                                    data-time="<?php echo htmlspecialchars($timeLabel, ENT_QUOTES); ?>"
                                                    data-status="<?php echo htmlspecialchars($actionStatus, ENT_QUOTES); ?>"
                                                    data-attempt="<?php echo $attemptNum; ?>"
                                                    data-complaint-number="<?php echo htmlspecialchars($cnum, ENT_QUOTES); ?>"
                                                    title="Manage the Paanyaya stage, attendance, outcome, and escalation">
                                                <span>Manage</span>
                                            </button>
                                        <?php elseif ($linkedCid > 0): ?>
                                            <a href="stage.php?complaint_id=<?php echo $linkedCid; ?>" class="agap-btn-act" title="Manage formal Paanyaya stage">
                                                <span>Manage Stage</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php
                                        return (string)ob_get_clean();
                                    };
                                ?>
                                <?php foreach ($paanyayas as $groupIndex => $caseGroup): ?>
                                    <?php
                                        $p = $caseGroup['latest'] ?? $caseGroup;
                                        $items = $caseGroup['items'] ?? [$p];
                                        $groupKey = (string)($caseGroup['group_key'] ?? ('single:' . (int)($p['paanyaya_id'] ?? $groupIndex)));
                                        $dropdownDomId = 'paanyaya-dropdown-' . preg_replace('/[^a-zA-Z0-9_-]/', '-', $groupKey) . '-' . $groupIndex;
                                        $hasSecond = !empty($caseGroup['has_second_paanyaya']);
                                        $itemCount = (int)($caseGroup['item_count'] ?? count($items));

                                        $d = !empty($p['scheduled_date']) ? date('M j, Y', strtotime($p['scheduled_date'])) : '—';
                                        $t = !empty($p['scheduled_time']) ? date('g:i A', strtotime($p['scheduled_time'])) : '';
                                        $displaySt = (string)($caseGroup['display_status'] ?? ($p['current_workflow_status'] ?? ($p['status'] ?? 'Scheduled')));
                                        $rawWorkflowSt = (string)($caseGroup['workflow_status'] ?? ($p['current_workflow_status'] ?? ($p['status'] ?? 'Scheduled')));
                                        $workflowStage = (string)($caseGroup['workflow_stage'] ?? ($p['current_workflow_stage'] ?? 'Stage 0 · Informal Dialogue'));
                                        $badgeVariant = $resolveBadgeVariant($displaySt);

                                        $invName = trim((string) ($p['invitee_name'] ?: 'Inanyayahan'));
                                        $invParts = preg_split('/\s+/', $invName) ?: ['I'];
                                        $invInitials = strtoupper(mb_substr($invParts[0] ?? 'I', 0, 1, 'UTF-8') . (isset($invParts[1]) ? mb_substr($invParts[1], 0, 1, 'UTF-8') : ''));

                                        $compName = trim((string) (!empty($p['complainant_name']) ? $p['complainant_name'] : 'Nagpaanyaya'));
                                        $compParts = preg_split('/\s+/', $compName) ?: ['C'];
                                        $compInitials = strtoupper(mb_substr($compParts[0] ?? 'C', 0, 1, 'UTF-8') . (isset($compParts[1]) ? mb_substr($compParts[1], 0, 1, 'UTF-8') : ''));

                                        $invAddrLine = trim(implode(', ', array_filter([$p['invitee_address'] ?? '', $p['invitee_purok'] ?? ''])));
                                        $invContactLine = trim((string)($p['invitee_contact_no'] ?? ''));
                                        $compAddrLine = trim(implode(', ', array_filter([$p['complainant_address'] ?? '', $p['complainant_purok'] ?? ''])));
                                        $compContactLine = trim((string)($p['complainant_contact_no'] ?? ''));

                                        $stepSearchTokens = [];
                                        foreach ($items as $sItem) {
                                            $stepSearchTokens[] = (string)($sItem['step_label'] ?? '');
                                            $stepSearchTokens[] = (string)($sItem['status'] ?? '');
                                            $stepSearchTokens[] = (string)($sItem['step_display_status'] ?? '');
                                            if (!empty($sItem['scheduled_date'])) {
                                                $stepSearchTokens[] = date('M j, Y', strtotime($sItem['scheduled_date']));
                                            }
                                        }
                                        $searchBlob = strtolower(implode(' ', array_merge([
                                            $invName, $compName, $invAddrLine, $invContactLine, $compAddrLine,
                                            $displaySt, $rawWorkflowSt, (string)($p['complaint_number'] ?? ''),
                                            $hasSecond ? '2nd paanyaya second' : '1st paanyaya'
                                        ], $stepSearchTokens)));

                                        $linkedComplaintId = (int)($p['complaint_id'] ?: ($p['escalated_to_complaint_id'] ?? 0));
                                        $latestAttempt = (int)($p['step_index'] ?? ($p['attempt_number'] ?? 1));
                                        $latestStepLabel = (string)($p['step_label'] ?? ($latestAttempt === 2 ? '2nd Paanyaya' : ($latestAttempt === 3 ? '3rd Paanyaya' : '1st Paanyaya')));
                                    ?>
                                    <tr data-paanyaya-row="1"
                                        data-group-key="<?php echo htmlspecialchars($groupKey, ENT_QUOTES); ?>"
                                        data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES); ?>">
                                        <td class="cell-expand" style="width: 44px; text-align: center;">
                                            <button type="button"
                                                    class="paanyaya-expand-btn"
                                                    aria-expanded="false"
                                                    aria-controls="<?php echo htmlspecialchars($dropdownDomId, ENT_QUOTES); ?>"
                                                    title="View Paanyaya progression for this case">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
                                            </button>
                                        </td>
                                        <td>
                                            <div class="agap-person-cell">
                                                <span class="agap-person-avatar" aria-hidden="true"><?php echo htmlspecialchars($compInitials); ?></span>
                                                <div class="agap-person-meta">
                                                    <span class="agap-person-name"><?php echo htmlspecialchars($compName); ?></span>
                                                    <?php if ($compAddrLine !== '' || $compContactLine !== ''): ?>
                                                        <span class="agap-person-sub"><?php echo htmlspecialchars(trim(implode(' · ', array_filter([$compAddrLine, $compContactLine])))); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="agap-person-cell">
                                                <span class="agap-person-avatar is-respondent" aria-hidden="true"><?php echo htmlspecialchars($invInitials); ?></span>
                                                <div class="agap-person-meta">
                                                    <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap;">
                                                        <span class="agap-person-name"><?php echo htmlspecialchars($invName); ?></span>
                                                        <?php if (!empty($p['complaint_number'])): ?>
                                                            <span class="paanyaya-case-chip">Case #<?php echo htmlspecialchars($p['complaint_number']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($invAddrLine !== '' || $invContactLine !== ''): ?>
                                                        <span class="agap-person-sub"><?php echo htmlspecialchars(trim(implode(' · ', array_filter([$invAddrLine, $invContactLine])))); ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="paanyaya-status-dual">
                                                <span class="paanyaya-step-chip <?php echo ($hasSecond || $latestAttempt >= 2) ? 'is-multi' : ''; ?>">
                                                    <?php echo htmlspecialchars($latestStepLabel); ?>
                                                </span>
                                                <span class="agap-pill-badge <?php echo $badgeVariant; ?>">
                                                    <?php echo $displaySt === 'Done' ? '✓ Done' : htmlspecialchars($displaySt); ?>
                                                </span>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr class="paanyaya-dropdown-row"
                                        id="<?php echo htmlspecialchars($dropdownDomId, ENT_QUOTES); ?>"
                                        data-paanyaya-dropdown-for="<?php echo htmlspecialchars($groupKey, ENT_QUOTES); ?>"
                                        hidden>
                                        <td colspan="4" class="paanyaya-dropdown-cell">
                                            <div class="paanyaya-stepper-card">
                                                <div class="paanyaya-stepper-header">
                                                    <div class="paanyaya-stepper-title">
                                                        <span>Paanyaya Progression</span>
                                                        <?php if (!empty($p['complaint_number'])): ?>
                                                            <span class="paanyaya-case-chip">Case #<?php echo htmlspecialchars($p['complaint_number']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($hasSecond): ?>
                                                            <span class="paanyaya-series-pill"><?php echo max(2, $itemCount); ?> Connected Invitations</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                        <span style="font-size: 0.76rem; color: #64748b; font-weight: 600;">
                                                            <?php echo htmlspecialchars($workflowStage); ?>
                                                        </span>
                                                        <?php if ($linkedComplaintId > 0): ?>
                                                            <a href="stage.php?complaint_id=<?php echo $linkedComplaintId; ?>" class="agap-btn-act" title="Open the full Paanyaya stage workspace">
                                                                <span>Stage Workspace</span>
                                                            </a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="paanyaya-vstepper">
                                                    <?php
                                                        $showUpcomingSecond = (
                                                            count($items) === 1 && !$hasSecond
                                                            && !in_array($displaySt, ['Done', 'Resolved', 'Completed', 'Settled', 'Cancelled', 'Escalated', 'Dismissed', 'Archived', 'CFA Issued'], true)
                                                        );
                                                    ?>
                                                    <?php foreach ($items as $item): ?>
                                                        <?php
                                                            $stepNum = (int)($item['step_index'] ?? ($item['attempt_number'] ?? 1));
                                                            $stepLabel = (string)($item['step_label'] ?? ($stepNum === 2 ? '2nd Paanyaya' : '1st Paanyaya'));
                                                            $isStepCurrent = !empty($item['is_latest']);
                                                            $stepClass = $isStepCurrent ? 'is-current' : 'is-done';

                                                            $itemDate = !empty($item['scheduled_date']) ? date('M j, Y', strtotime($item['scheduled_date'])) : '—';
                                                            $itemTime = !empty($item['scheduled_time']) ? date('g:i A', strtotime($item['scheduled_time'])) : '';
                                                            $itemRawStatus = (string)($item['status'] ?? 'Scheduled');
                                                            $itemDispStatus = (string)($item['step_display_status'] ?? ($isStepCurrent ? $itemRawStatus : 'Done'));
                                                            $itemActionStatus = $isStepCurrent ? $itemRawStatus : $itemDispStatus;
                                                            $itemBadgeVariant = $resolveBadgeVariant($itemDispStatus);
                                                            $itemLinkedCid = (int)($item['complaint_id'] ?: ($item['escalated_to_complaint_id'] ?? $linkedComplaintId));
                                                            $itemInvName = trim((string)($item['invitee_name'] ?: $invName));
                                                            $itemCompName = trim((string)($item['complainant_name'] ?: $compName));
                                                        ?>
                                                        <div class="paanyaya-vstep <?php echo $stepClass; ?>">
                                                            <div class="paanyaya-vstep-rail" aria-hidden="true">
                                                                <?php if ($isStepCurrent): ?>
                                                                    <span class="paanyaya-vstep-pointer">&#709;</span>
                                                                <?php endif; ?>
                                                                <span class="paanyaya-vstep-circle">
                                                                    <?php if (!$isStepCurrent): ?>
                                                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                                                    <?php else: ?>
                                                                        <?php echo $stepNum; ?>
                                                                    <?php endif; ?>
                                                                </span>
                                                                <span class="paanyaya-vstep-line"></span>
                                                            </div>
                                                            <div class="paanyaya-vstep-content">
                                                                <div class="paanyaya-vstep-box">
                                                                    <div class="paanyaya-vstep-meta">
                                                                        <div class="paanyaya-vstep-top">
                                                                            <span class="paanyaya-vstep-name"><?php echo htmlspecialchars($stepLabel); ?></span>
                                                                            <?php if ($isStepCurrent): ?>
                                                                                <span class="paanyaya-vstep-recent-pill">Most Recent</span>
                                                                            <?php endif; ?>
                                                                            <span class="agap-pill-badge <?php echo $itemBadgeVariant; ?>">
                                                                                <?php echo $itemDispStatus === 'Done' ? '✓ Done' : htmlspecialchars($itemDispStatus); ?>
                                                                            </span>
                                                                        </div>
                                                                        <div class="paanyaya-vstep-sub">
                                                                            <span>Scheduled: <strong><?php echo htmlspecialchars($itemDate); ?><?php echo $itemTime !== '' ? ' · ' . htmlspecialchars($itemTime) : ''; ?></strong></span>
                                                                            <?php if (!empty($item['complainant_attended']) || !empty($item['invitee_attended'])): ?>
                                                                                <span>Attendance: <?php echo htmlspecialchars((string)($item['complainant_attended'] ?? 'Pending')); ?> / <?php echo htmlspecialchars((string)($item['invitee_attended'] ?? 'Pending')); ?></span>
                                                                            <?php endif; ?>
                                                                            <?php if (!empty($item['outcome_notes'])): ?>
                                                                                <span><?php echo htmlspecialchars(mb_strimwidth(trim((string)$item['outcome_notes']), 0, 90, '...', 'UTF-8')); ?></span>
                                                                            <?php endif; ?>
                                                                        </div>
                                                                    </div>
                                                                    <div class="agap-row-actions">
                                                                        <?php echo $renderPaanyayaActions($item, $itemActionStatus, $stepNum, $itemLinkedCid, $itemInvName, $itemCompName, $itemDate, $itemTime, false); ?>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                    <?php if ($showUpcomingSecond): ?>
                                                        <div class="paanyaya-vstep is-upcoming">
                                                            <div class="paanyaya-vstep-rail" aria-hidden="true">
                                                                <span class="paanyaya-vstep-circle">2</span>
                                                                <span class="paanyaya-vstep-line"></span>
                                                            </div>
                                                            <div class="paanyaya-vstep-content">
                                                                <div class="paanyaya-vstep-box">
                                                                    <div class="paanyaya-vstep-meta">
                                                                        <div class="paanyaya-vstep-top">
                                                                            <span class="paanyaya-vstep-name">2nd Paanyaya</span>
                                                                            <span class="agap-pill-badge" style="background:#f1f5f9; color:#64748b; border-color:#cbd5e1;">Pending</span>
                                                                        </div>
                                                                        <div class="paanyaya-vstep-sub">
                                                                            <span>Not yet scheduled — will mark this case Done once issued.</span>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination & Items Per Page Footer -->
                <div class="agap-list-footer" id="paanyayaListFooter" <?php echo empty($paanyayas) ? 'hidden' : ''; ?>>
                    <div class="agap-page-size-wrap">
                        <span>Items Per Page</span>
                        <select id="paanyayaPageSize" class="agap-page-size-select" aria-label="Items per page">
                            <option value="10" selected>10</option>
                            <option value="15">15</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                        </select>
                        <span class="agap-page-summary" id="paanyayaPaginationSummary">Showing 0 records</span>
                    </div>
                    <div class="agap-pagination-controls" id="paanyayaPaginationControls"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 1: SCHEDULE NEW PAANYAYA (With Salaysay Statement Narrative Field)
     ========================================================================= -->
<div class="modal-overlay" id="schedulePaanyayaModal">
    <div class="modal-card" style="max-width: 1120px; width: 95vw; max-height: 92vh;">
        <div class="modal-head">
            <h3>✉️ Schedule &amp; Send Paanyaya Invitation</h3>
            <button type="button" class="modal-close-btn" id="modalCloseBtn">&times;</button>
        </div>
        <form id="schedulePaanyayaForm">
            <div class="modal-body">
                <div class="paanyaya-parties-grid">
                <!-- 1. COMPLAINANT (NAGPAANYAYA) -->
                <div class="party-section-card">
                    <div class="modal-section-title" style="justify-content: space-between; margin-bottom: 10px;">
                        <span style="color: #166534;">1. Details of Complainant (Nagpaanyaya)</span>
                        <span style="font-size: 0.74rem; font-weight: 600; color: #15803d; background: #dcfce7; padding: 2px 8px; border-radius: 999px;">Required</span>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="schedCompResidentSelect" style="font-size: 0.78rem; color: #475569;">Quick Fill from Resident Directory (Optional)</label>
                        <select id="schedCompResidentSelect" class="form-control" style="font-size: 0.85rem; background: #ffffff;">
                            <option value="">-- Search / Select Existing Resident Profile or Type Manually Below --</option>
                            <?php foreach ($residentsForLookup as $rItem): ?>
                                <?php
                                    $rFull = trim(implode(' ', array_filter([$rItem['first_name'] ?? '', $rItem['middle_name'] ?? '', $rItem['last_name'] ?? '', $rItem['suffix'] ?? ''])));
                                    $rDisp = ($rItem['last_name'] ?? '') . ', ' . ($rItem['first_name'] ?? '') . (!empty($rItem['middle_name']) ? ' ' . $rItem['middle_name'] : '');
                                    $rAddrDisp = trim(implode(', ', array_filter([$rItem['address'] ?? '', $rItem['purok'] ?? ''])));
                                ?>
                                <option value="<?php echo (int)$rItem['resident_id']; ?>"
                                        data-first="<?php echo htmlspecialchars($rItem['first_name'] ?? '', ENT_QUOTES); ?>"
                                        data-middle="<?php echo htmlspecialchars($rItem['middle_name'] ?? '', ENT_QUOTES); ?>"
                                        data-last="<?php echo htmlspecialchars($rItem['last_name'] ?? '', ENT_QUOTES); ?>"
                                        data-address="<?php echo htmlspecialchars($rItem['address'] ?? '', ENT_QUOTES); ?>"
                                        data-purok="<?php echo htmlspecialchars($rItem['purok'] ?? '', ENT_QUOTES); ?>"
                                        data-contact="<?php echo htmlspecialchars($rItem['contact_no'] ?? '', ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($rItem['email'] ?? '', ENT_QUOTES); ?>"
                                        data-lat="<?php echo htmlspecialchars((string)($rItem['home_latitude'] ?? ''), ENT_QUOTES); ?>"
                                        data-lng="<?php echo htmlspecialchars((string)($rItem['home_longitude'] ?? ''), ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($rDisp . ($rAddrDisp ? ' — ' . $rAddrDisp : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="schedComplainantResidentId" name="complainant_resident_id" value="">
                    </div>

                    <div class="form-row-3" style="margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedComplainantFirstName">First Name</label>
                            <input type="text" id="schedComplainantFirstName" name="complainant_first_name" class="form-control" placeholder="First name" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedComplainantMiddleName">Middle Name</label>
                            <input type="text" id="schedComplainantMiddleName" name="complainant_middle_name" class="form-control" placeholder="Middle name (optional)">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedComplainantLastName">Last Name</label>
                            <input type="text" id="schedComplainantLastName" name="complainant_last_name" class="form-control" placeholder="Last name" required>
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:6px;">
                                <label for="schedComplainantAddress" style="margin-bottom:0;">House No. / Street Address</label>
                                <button type="button" id="btnPinCompAddress" style="padding:2px 8px; font-size:0.72rem; font-weight:600; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; border-radius:6px; cursor:pointer;">📍 Pin on Map</button>
                            </div>
                            <input type="text" id="schedComplainantAddress" name="complainant_address" class="form-control" placeholder="e.g. Blk 4 Lot 12 Champagne St." required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedComplainantPurok">Purok / Zone</label>
                            <select id="schedComplainantPurok" name="complainant_purok" class="form-control" required>
                                <option value="">Select Purok / Zone...</option>
                                <?php foreach ($purokOptions as $pOpt): ?>
                                    <option value="<?php echo htmlspecialchars($pOpt); ?>"><?php echo htmlspecialchars($pOpt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom: 0;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label for="schedComplainantContact" style="margin-bottom: 0; white-space: nowrap;">Contact Number <span style="color:#ef4444;" id="compContactReqStar">*</span></label>
                                <label style="margin-bottom: 0; font-size: 0.74rem; font-weight: 500; color: #64748b; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                    <input type="checkbox" id="chkCompNoContact"> No contact no.
                                </label>
                            </div>
                            <input type="text" id="schedComplainantContact" name="complainant_contact_no" class="form-control" placeholder="09XX-XXX-XXXX" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedComplainantEmail">Email Address <span style="font-size: 0.74rem; color: #64748b; font-weight: normal;">(Optional)</span></label>
                            <input type="email" id="schedComplainantEmail" name="complainant_email" class="form-control" placeholder="name@example.com">
                        </div>
                        <div style="grid-column: 1 / -1; margin-top: 4px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px;">
                            <input type="hidden" id="compLocationLat" name="complainant_latitude" value="">
                            <input type="hidden" id="compLocationLng" name="complainant_longitude" value="">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                <div style="font-size: 0.78rem; font-weight: 700; color: #065f46; display: flex; align-items: center; gap: 5px;">
                                    📍 Pin Complainant Residence Location on Map
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" id="compRecenterMapBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 6px; cursor: pointer;">Recenter Tumana</button>
                                    <button type="button" id="compClearPinBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; border-radius: 6px; cursor: pointer;">Clear Pin</button>
                                </div>
                            </div>
                            <div style="font-size: 0.72rem; color: #64748b; margin-bottom: 6px;">
                                Click on the map or drag the pin to auto-populate the Complainant's Street Address and Purok/Zone.
                            </div>
                            <div id="compLocationMap" style="width: 100%; height: 215px; border-radius: 8px; border: 1px solid #cbd5e1; z-index: 1;"></div>
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 6px; font-size: 0.73rem;">
                                <span id="compMapStatus" style="color: #64748b;">No pin placed yet. Click on map to auto-fill address.</span>
                                <span id="compMapCoords" style="font-family: monospace; color: #475569; font-weight: 600;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 2. PERSON BEING INVITED (INANYAYAHAN) -->
                <div class="party-section-card">
                    <div class="modal-section-title" style="justify-content: space-between; margin-bottom: 10px;">
                        <span style="color: #1e40af;">2. Details of Person Being Invited (Inanyayahan)</span>
                        <span style="font-size: 0.74rem; font-weight: 600; color: #1d4ed8; background: #dbeafe; padding: 2px 8px; border-radius: 999px;">Required for Delivery</span>
                    </div>

                    <div class="form-group" style="margin-bottom: 12px;">
                        <label for="schedInvResidentSelect" style="font-size: 0.78rem; color: #475569;">Quick Fill from Resident Directory (Optional)</label>
                        <select id="schedInvResidentSelect" class="form-control" style="font-size: 0.85rem; background: #ffffff;">
                            <option value="">-- Search / Select Existing Resident Profile or Type Manually Below --</option>
                            <?php foreach ($residentsForLookup as $rItem): ?>
                                <?php
                                    $rDisp = ($rItem['last_name'] ?? '') . ', ' . ($rItem['first_name'] ?? '') . (!empty($rItem['middle_name']) ? ' ' . $rItem['middle_name'] : '');
                                    $rAddrDisp = trim(implode(', ', array_filter([$rItem['address'] ?? '', $rItem['purok'] ?? ''])));
                                ?>
                                <option value="<?php echo (int)$rItem['resident_id']; ?>"
                                        data-first="<?php echo htmlspecialchars($rItem['first_name'] ?? '', ENT_QUOTES); ?>"
                                        data-middle="<?php echo htmlspecialchars($rItem['middle_name'] ?? '', ENT_QUOTES); ?>"
                                        data-last="<?php echo htmlspecialchars($rItem['last_name'] ?? '', ENT_QUOTES); ?>"
                                        data-address="<?php echo htmlspecialchars($rItem['address'] ?? '', ENT_QUOTES); ?>"
                                        data-purok="<?php echo htmlspecialchars($rItem['purok'] ?? '', ENT_QUOTES); ?>"
                                        data-contact="<?php echo htmlspecialchars($rItem['contact_no'] ?? '', ENT_QUOTES); ?>"
                                        data-email="<?php echo htmlspecialchars($rItem['email'] ?? '', ENT_QUOTES); ?>"
                                        data-lat="<?php echo htmlspecialchars((string)($rItem['home_latitude'] ?? ''), ENT_QUOTES); ?>"
                                        data-lng="<?php echo htmlspecialchars((string)($rItem['home_longitude'] ?? ''), ENT_QUOTES); ?>">
                                    <?php echo htmlspecialchars($rDisp . ($rAddrDisp ? ' — ' . $rAddrDisp : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="schedInviteeResidentId" name="invitee_resident_id" value="">
                    </div>

                    <div class="form-row-3" style="margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedInviteeFirstName">First Name</label>
                            <input type="text" id="schedInviteeFirstName" name="invitee_first_name" class="form-control" placeholder="First name" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedInviteeMiddleName">Middle Name</label>
                            <input type="text" id="schedInviteeMiddleName" name="invitee_middle_name" class="form-control" placeholder="Middle name (optional)">
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedInviteeLastName">Last Name</label>
                            <input type="text" id="schedInviteeLastName" name="invitee_last_name" class="form-control" placeholder="Last name" required>
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom: 12px;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:6px;">
                                <label for="schedInviteeAddress" style="margin-bottom:0;">House No. / Street Address (Delivery)</label>
                                <button type="button" id="btnPinInvAddress" style="padding:2px 8px; font-size:0.72rem; font-weight:600; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; border-radius:6px; cursor:pointer;">📍 Pin on Map</button>
                            </div>
                            <input type="text" id="schedInviteeAddress" name="invitee_address" class="form-control" placeholder="e.g. #45 Road 1 Ext., Doña Petra" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedInviteePurok">Purok / Zone</label>
                            <select id="schedInviteePurok" name="invitee_purok" class="form-control" required>
                                <option value="">Select Purok / Zone...</option>
                                <?php foreach ($purokOptions as $pOpt): ?>
                                    <option value="<?php echo htmlspecialchars($pOpt); ?>"><?php echo htmlspecialchars($pOpt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom: 0;">
                        <div class="form-group" style="margin-bottom: 0;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                                <label for="schedInviteeContact" style="margin-bottom: 0; white-space: nowrap;">Contact Number <span style="color:#ef4444;" id="invContactReqStar">*</span></label>
                                <label style="margin-bottom: 0; font-size: 0.74rem; font-weight: 500; color: #64748b; display: inline-flex; align-items: center; gap: 4px; cursor: pointer;">
                                    <input type="checkbox" id="chkInvNoContact"> Unknown / None
                                </label>
                            </div>
                            <input type="text" id="schedInviteeContact" name="invitee_contact_no" class="form-control" placeholder="09XX-XXX-XXXX" required>
                        </div>
                        <div class="form-group" style="margin-bottom: 0;">
                            <label for="schedInviteeEmail">Email Address <span style="font-size: 0.74rem; color: #64748b; font-weight: normal;">(Optional)</span></label>
                            <input type="email" id="schedInviteeEmail" name="invitee_email" class="form-control" placeholder="name@example.com">
                        </div>
                        <div style="grid-column: 1 / -1; margin-top: 4px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 10px;">
                            <input type="hidden" id="invLocationLat" name="invitee_latitude" value="">
                            <input type="hidden" id="invLocationLng" name="invitee_longitude" value="">
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                <div style="font-size: 0.78rem; font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 5px;">
                                    📍 Pin Respondent / Invitee Residence Location on Map
                                </div>
                                <div style="display: flex; gap: 6px;">
                                    <button type="button" id="invRecenterMapBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #cbd5e1; background: #f8fafc; color: #334155; border-radius: 6px; cursor: pointer;">Recenter Tumana</button>
                                    <button type="button" id="invClearPinBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; border-radius: 6px; cursor: pointer;">Clear Pin</button>
                                </div>
                            </div>
                            <div style="font-size: 0.72rem; color: #64748b; margin-bottom: 6px;">
                                Click on the map or drag the pin to auto-populate the Respondent's Street Address and Purok/Zone.
                            </div>
                            <div id="invLocationMap" style="width: 100%; height: 215px; border-radius: 8px; border: 1px solid #cbd5e1; z-index: 1;"></div>
                            <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 6px; font-size: 0.73rem;">
                                <span id="invMapStatus" style="color: #64748b;">No pin placed yet. Click on map to auto-fill address.</span>
                                <span id="invMapCoords" style="font-family: monospace; color: #475569; font-weight: 600;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                </div><!-- /.paanyaya-parties-grid -->

                <!-- 3. LOCATION PINNING (BARANGAY TUMANA MAP) -->
                <div class="party-section-card" style="display: none;">
                    <div class="modal-section-title" style="justify-content: space-between; margin-bottom: 10px;">
                        <span style="color: #0f766e;">3. Location Pinning (Invitee / Incident Location in Barangay Tumana)</span>
                        <span style="font-size: 0.74rem; font-weight: 600; color: #0f766e; background: #ccfbf1; padding: 2px 8px; border-radius: 999px;">Interactive Map Pinning</span>
                    </div>
                    <input type="hidden" id="paanyayaLocationLat" name="location_latitude" value="">
                    <input type="hidden" id="paanyayaLocationLng" name="location_longitude" value="">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; margin-bottom: 10px;">
                        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.84rem;">
                            <span style="font-weight: 700; color: #334155;">📍 Location Coordinates:</span>
                            <span id="paanyayaLocationCoords" style="color: #64748b; font-family: monospace;">Click on the map to pin location</span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" id="paanyayaRecenterMapBtn" class="btn-action-view" style="padding: 5px 12px; font-size: 0.78rem;">Recenter</button>
                            <button type="button" id="paanyayaClearPinBtn" class="btn-action-view" style="padding: 5px 12px; font-size: 0.78rem;">Clear pin</button>
                        </div>
                    </div>
                    <div id="paanyayaLocationMap" style="width: 100%; height: 260px; border-radius: 10px; border: 1px solid #cbd5e1; z-index: 1;"></div>
                    <div style="font-size: 0.74rem; color: #64748b; margin-top: 6px;">
                        Click anywhere inside the highlighted Barangay Tumana boundary (or drag the marker) to pin the delivery/incident location and auto-fill the street address if empty.
                    </div>
                </div>

                <!-- 4. SCHEDULE & VENUE -->
                <div class="form-row-3" style="margin-bottom: 14px;">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedDate">Scheduled Date</label>
                        <input type="date" id="schedDate" name="scheduled_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedTime">Scheduled Time</label>
                        <input type="time" id="schedTime" name="scheduled_time" class="form-control" value="14:00" required>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="schedVenue">Venue / Tanggapan</label>
                        <input type="text" id="schedVenue" name="venue" class="form-control" value="Tanggapan ng Barangay Tumana" required>
                    </div>
                </div>

                <!-- 4. SALAYSAY (STATEMENT / DISPUTE NARRATIVE) -->
                <div class="form-group" style="margin-bottom: 4px;">
                    <label for="schedSalaysay" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Salaysay (Statement / Reason for Invitation)</span>
                        <span style="font-size: 0.74rem; color: #16a34a; font-weight: 600;">Required · Inilalagay sa Liham at Mediation</span>
                    </label>
                    <textarea id="schedSalaysay" name="salaysay" class="form-control" rows="3" placeholder="Ilarawan ang buod ng sumbong, dahilan ng pag-anyaya, o salaysay ng nagpaanyaya..." required></textarea>
                    <div style="font-size: 0.74rem; color: #64748b; margin-top: 4px;">
                        Ito ay ilalagay sa opisyal na Paanyaya letter at awtomatikong ililipat sa Pormal na Mediation kapag inakyat ang kaso.
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-action-view" id="modalCancelBtn">Cancel</button>
                <button type="submit" class="btn-new-paanyaya" id="btnSubmitPaanyaya">Save &amp; Generate Paanyaya</button>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: IN-PAGE LETTER PREVIEW MODAL (Replaces Page Redirection)
     ========================================================================= -->
<div class="modal-overlay" id="paanyayaPreviewModal">
    <div class="modal-card modal-card-large">
        <div class="modal-head">
            <div>
                <h3 id="previewModalTitle">✉️ Paanyaya Letter Preview</h3>
                <div id="previewModalSubtitle" style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Official Barangay Tumana Invitation</div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <button type="button" class="btn-action-print" id="btnPrintFromModal" title="Print Letter">
                    🖨️ Print
                </button>
                <a href="#" target="_blank" class="btn-action-view" id="btnDownloadFromModal" title="Download PDF">
                    ⬇️ Download PDF
                </a>
                <button type="button" class="modal-close-btn" id="previewModalCloseBtn">&times;</button>
            </div>
        </div>
        <div class="modal-body" style="padding: 0; background: #525659;">
            <div id="previewLoadingNotice" style="padding: 40px 20px; text-align: center; color: #ffffff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;">
                <div style="font-size: 1.8rem;">⏳</div>
                <div style="font-weight: 600;">Naglo-load ng Paanyaya Letter...</div>
            </div>
            <iframe id="paanyayaPreviewIframe" src="" style="width: 100%; height: 65vh; min-height: 500px; border: none; display: none;"></iframe>
        </div>
        <div class="modal-foot">
            <button type="button" class="btn-action-view" id="previewModalCloseFootBtn">Close</button>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 3: ATTENDANCE TRACKING & SEAMLESS ESCALATION MODAL
     ========================================================================= -->
<div class="modal-overlay" id="paanyayaAttendanceModal">
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <h3>📋 Paanyaya Stage</h3>
                <div style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Stage 0 dialogue, attendance, outcome, and Mediation escalation</div>
            </div>
            <button type="button" class="modal-close-btn" id="attendanceModalCloseBtn">&times;</button>
        </div>
        <form id="attendanceActionForm">
            <input type="hidden" id="attPaanyayaId" name="paanyaya_id" value="">
            <input type="hidden" id="attComplaintId" name="complaint_id" value="">

            <div class="modal-body">
                <!-- Case Info Pill Strip -->
                <div style="background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; margin-bottom: 16px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                        <span style="font-size: 0.82rem; font-weight: 700; color: #1e293b;" id="attInviteeLabel">Inanyayahan: —</span>
                        <span class="status-pill-scheduled" id="attStatusBadge">Scheduled</span>
                    </div>
                    <div style="font-size: 0.82rem; color: #475569;" id="attComplainantLabel">Nagpaanyaya: —</div>
                    <div style="font-size: 0.78rem; color: #64748b; margin-top: 4px;" id="attScheduleLabel">Iskedyul: —</div>
                </div>

                <!-- Status Lock Notice Banner -->
                <div id="attLockNoticeBanner" style="display: none; margin-bottom: 14px; border-radius: 8px; padding: 12px 14px; font-size: 0.82rem; line-height: 1.45;"></div>

                <!-- 1. Complainant Attendance Tracking -->
                <div class="attendance-party-box">
                    <div class="attendance-party-head">
                        <div class="attendance-party-label">Nagpaanyaya (Complainant)</div>
                        <div class="attendance-party-name" id="attCompNameDisplay">Nagpaanyaya</div>
                    </div>
                    <div class="attendance-pills-row">
                        <label class="attendance-radio-pill">
                            <input type="radio" name="complainant_attended" value="Present" checked>
                            <span class="attendance-pill-label">✓ Present</span>
                        </label>
                        <label class="attendance-radio-pill">
                            <input type="radio" name="complainant_attended" value="Absent">
                            <span class="attendance-pill-label">✕ Absent</span>
                        </label>
                    </div>
                </div>

                <!-- 2. Invitee (Respondent) Attendance Tracking -->
                <div class="attendance-party-box">
                    <div class="attendance-party-head">
                        <div class="attendance-party-label">Inanyayahan (Invitee / Respondent)</div>
                        <div class="attendance-party-name" id="attInvNameDisplay">Inanyayahan</div>
                    </div>
                    <div class="attendance-pills-row">
                        <label class="attendance-radio-pill">
                            <input type="radio" name="invitee_attended" value="Present">
                            <span class="attendance-pill-label">✓ Present</span>
                        </label>
                        <label class="attendance-radio-pill">
                            <input type="radio" name="invitee_attended" value="Absent" checked>
                            <span class="attendance-pill-label">✕ Absent</span>
                        </label>
                    </div>
                </div>

                <!-- 3. Salaysay (Statement Narrative) -->
                <div class="form-group">
                    <label for="attSalaysay" style="display: flex; justify-content: space-between; align-items: center;">
                        <span>Salaysay (Statement Narrative)</span>
                        <span style="font-size: 0.74rem; color: #16a34a; font-weight: 600;">✓ Awtomatikong lilipat sa Mediation</span>
                    </label>
                    <textarea id="attSalaysay" name="salaysay" class="form-control" rows="3" placeholder="Salaysay ng nagpaanyaya ukol sa reklamo..."></textarea>
                </div>

                <!-- 4. Action Control Section (In Place of Tala ng Pag-uusap) -->
                <div class="attendance-action-panel" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px; margin-top: 6px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-size: 0.8rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.03em;">
                            Aksyon / Hakbang sa Usapin
                        </span>
                        <span id="attAttemptBadge" class="status-pill-scheduled" style="font-size: 0.72rem; padding: 2px 8px;">Unang Paanyaya</span>
                    </div>

                    <!-- Action Buttons -->
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                        <button type="button" class="btn-action-view" id="btnSaveAttendanceOnly" style="padding: 9px 12px; background: #ffffff; border: 1px solid #cbd5e1; font-weight: 600; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            💾 Save Attendance
                        </button>
                        <button type="button" class="btn-resolve-dialogue" id="btnResolveDialogue" style="padding: 9px 12px; font-weight: 600; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            🤝 Kasunduan (Settle)
                        </button>
                        <button type="button" class="btn-second-paanyaya" id="btnOpenSecondPaanyaya" style="padding: 9px 12px; font-weight: 600; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            📩 2nd Paanyaya
                        </button>
                        <button type="button" class="btn-escalate-mediation" id="btnEscalateMediation" style="padding: 9px 12px; font-weight: 600; border-radius: 6px; display: flex; align-items: center; justify-content: center; gap: 6px;">
                            ⚖️ Continue to Formal Complaint Details
                        </button>
                    </div>

                    <!-- Inline 2nd Paanyaya Scheduling Form -->
                    <div id="secondPaanyayaPanel" style="display: none; margin-top: 12px; padding: 12px; background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px;">
                        <div style="font-size: 0.84rem; font-weight: 700; color: #1e40af; margin-bottom: 6px; display: flex; align-items: center; justify-content: space-between;">
                            <span>📅 Mag-iskedyul ng Ikalawang Paanyaya (2nd Paanyaya)</span>
                            <span style="font-size: 0.72rem; color: #2563eb; background: #dbeafe; padding: 2px 6px; border-radius: 4px; font-weight: 600;">Attempt 2</span>
                        </div>
                        <p style="font-size: 0.78rem; color: #1e3a8a; margin: 0 0 10px 0;">
                            Awtomatikong itatala ang pagdalo sa Unang Paanyaya at lilikha ng opisyal na liham para sa <strong>Ikalawang Paanyaya</strong>.
                        </p>
                        <div class="form-row" style="margin-bottom: 10px;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="p2Date" style="font-size: 0.78rem; font-weight: 600; color: #1e40af;">Petsa ng 2nd Paanyaya</label>
                                <input type="date" id="p2Date" class="form-control" style="font-size: 0.85rem; padding: 6px 10px; background: #ffffff;">
                            </div>
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="p2Time" style="font-size: 0.78rem; font-weight: 600; color: #1e40af;">Oras ng 2nd Paanyaya</label>
                                <input type="time" id="p2Time" value="14:00" class="form-control" style="font-size: 0.85rem; padding: 6px 10px; background: #ffffff;">
                            </div>
                        </div>
                        <div style="display: flex; justify-content: flex-end; gap: 8px;">
                            <button type="button" class="btn-action-view" id="btnCancelP2" style="padding: 5px 12px; font-size: 0.8rem;">Bawiin</button>
                            <button type="button" class="btn-action-print" id="btnConfirmP2" style="background: #2563eb; color: #ffffff; border-color: #1d4ed8; padding: 5px 14px; font-size: 0.82rem; font-weight: 700; cursor: pointer;">
                                ✓ Ilabas ang 2nd Paanyaya
                            </button>
                        </div>
                    </div>

                    <!-- Notice if already in 2nd Paanyaya -->
                    <div id="alreadySecondPaanyayaNotice" style="display: none; margin-top: 10px; padding: 8px 12px; background: #fef3c7; border: 1px solid #fde047; border-radius: 6px; font-size: 0.78rem; color: #854d0e;">
                        ℹ️ Ang usaping ito ay nasa <strong>Ikalawang Paanyaya (Attempt 2)</strong> na. Alinsunod sa proseso ng Katarungang Pambarangay, dalawang paanyaya lamang ang pinahihintulutan bago ang pormal na Mediation. Kung walang kasunduan o hindi sumipot ang inanyayahan, mangyaring i-click ang <strong>Escalate to Mediation</strong>.
                    </div>

                    <!-- Escalated to Mediation Navigation Link -->
                    <div id="attViewMediationLinkWrap" style="display: none; margin-top: 12px;">
                        <a id="attViewMediationLink" href="#" class="btn-escalate-mediation" style="display: flex; align-items: center; justify-content: center; gap: 8px; text-decoration: none; padding: 10px 14px; border-radius: 6px; font-weight: 700; font-size: 0.85rem; box-shadow: 0 1px 3px rgba(124, 58, 237, 0.25);">
                            ⚖️ Tingnan ang Pormal na Reklamo sa Mediation &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <div class="modal-foot">
                <button type="button" class="btn-action-view" id="attendanceCancelBtn">Isara (Close)</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // =========================================================================
    // 1. SCHEDULE PAANYAYA MODAL
    // =========================================================================
    const schedModal = document.getElementById('schedulePaanyayaModal');
    const openSchedBtn = document.getElementById('btnOpenScheduleModal');
    const closeSchedBtn = document.getElementById('modalCloseBtn');
    const cancelSchedBtn = document.getElementById('modalCancelBtn');
    const schedForm = document.getElementById('schedulePaanyayaForm');
    const submitSchedBtn = document.getElementById('btnSubmitPaanyaya');
    const schedDateInput = document.getElementById('schedDate');
    const schedTimeInput = document.getElementById('schedTime');

    const updateMinDate = () => {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const todayStr = `${year}-${month}-${day}`;
        if (schedDateInput) schedDateInput.min = todayStr;
    };
    updateMinDate();

    const TUMANA_PUROK_COORDS = {
        'Purok 1': [14.6612, 121.0978],
        'Purok 2': [14.6596, 121.0985],
        'Purok 3': [14.6584, 121.0991],
        'Purok 4': [14.6572, 121.0995],
        'Purok 5': [14.6561, 121.0989],
        'Purok 6': [14.6551, 121.0996],
        'Purok 7': [14.6540, 121.0988],
        'Purok 8': [14.6531, 121.0994],
        'Doña Petra': [14.6546, 121.0972],
        'Non-Resident (Outside Barangay Tumana)': [14.6507, 121.1029]
    };

    const TUMANA_BOUNDARY_COORDS = [
        [14.6634144, 121.0956791], [14.6606021, 121.0952328], [14.6552864, 121.0947448],
        [14.6541990, 121.0948085], [14.6535998, 121.0949336], [14.6505044, 121.0955011],
        [14.6505663, 121.0968930], [14.6502364, 121.1029754], [14.6508212, 121.1029839],
        [14.6526992, 121.1028818], [14.6545169, 121.1029072], [14.6562260, 121.1029303],
        [14.6612702, 121.1032425], [14.6621330, 121.0998690], [14.6634144, 121.0956791]
    ];

    const isWithinTumana = (lat, lng) => {
        let inside = false;
        for (let i = 0, j = TUMANA_BOUNDARY_COORDS.length - 1; i < TUMANA_BOUNDARY_COORDS.length; j = i++) {
            const xi = TUMANA_BOUNDARY_COORDS[i][0], yi = TUMANA_BOUNDARY_COORDS[i][1];
            const xj = TUMANA_BOUNDARY_COORDS[j][0], yj = TUMANA_BOUNDARY_COORDS[j][1];
            const intersect = ((yi > lng) !== (yj > lng)) && (lat < (xj - xi) * (lng - yi) / ((yj - yi) || 1e-12) + xi);
            if (intersect) inside = !inside;
        }
        return inside;
    };

    const createPaanyayaPartyMapController = (cfg) => {
        let map = null;
        let marker = null;
        let geocodeSeq = 0;

        const updateStatus = (msg, isActive = false) => {
            const stEl = document.getElementById(cfg.statusId);
            if (stEl) {
                stEl.textContent = msg;
                stEl.style.color = isActive ? cfg.accentColor : '#64748b';
                stEl.style.fontWeight = isActive ? '600' : '400';
            }
        };

        const setPin = (lat, lng, statusText = 'Residence location pinned on map', shouldReverseGeocode = false) => {
            const latNum = parseFloat(lat);
            const lngNum = parseFloat(lng);
            if (isNaN(latNum) || isNaN(lngNum)) return;

            const latEl = document.getElementById(cfg.latId);
            const lngEl = document.getElementById(cfg.lngId);
            const coordsEl = document.getElementById(cfg.coordsId);
            if (latEl) latEl.value = latNum.toFixed(7);
            if (lngEl) lngEl.value = lngNum.toFixed(7);
            if (cfg.syncLegacyIds) {
                const legLat = document.getElementById('paanyayaLocationLat');
                const legLng = document.getElementById('paanyayaLocationLng');
                if (legLat) legLat.value = latNum.toFixed(7);
                if (legLng) legLng.value = lngNum.toFixed(7);
            }
            if (coordsEl) {
                coordsEl.textContent = `${latNum.toFixed(5)}, ${lngNum.toFixed(5)}`;
            }
            updateStatus(statusText, true);

            if (map && typeof L !== 'undefined') {
                if (!marker) {
                    marker = L.marker([latNum, lngNum], { draggable: true }).addTo(map);
                    marker.on('dragend', function(ev) {
                        const pos = ev.target.getLatLng();
                        setPin(pos.lat, pos.lng, 'Pin dragged — fetching street address...', true);
                    });
                } else {
                    marker.setLatLng([latNum, lngNum]);
                }
                map.panTo([latNum, lngNum]);
            }

            if (shouldReverseGeocode) {
                reverseGeocodeToInputs(latNum, lngNum);
            }
        };

        const clearPin = () => {
            const latEl = document.getElementById(cfg.latId);
            const lngEl = document.getElementById(cfg.lngId);
            const coordsEl = document.getElementById(cfg.coordsId);
            if (latEl) latEl.value = '';
            if (lngEl) lngEl.value = '';
            if (coordsEl) coordsEl.textContent = '';
            if (map && marker) {
                map.removeLayer(marker);
                marker = null;
            }
            updateStatus('No pin placed yet. Click on map to auto-fill address.', false);
        };

        const reverseGeocodeToInputs = async (lat, lng) => {
            const seq = ++geocodeSeq;
            const addrInput = document.getElementById(cfg.addressId);
            const purokSelect = document.getElementById(cfg.purokId);
            updateStatus('Looking up street address from map pin...', true);

            const insideTumana = isWithinTumana(lat, lng);
            try {
                const url = `https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lng)}&zoom=18&addressdetails=1`;
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (seq !== geocodeSeq) return;
                if (res.ok) {
                    const data = await res.json();
                    const addr = (data && data.address) ? data.address : {};
                    const houseNo = addr.house_number || addr.building || addr.amenity || '';
                    const road = addr.road || addr.pedestrian || addr.footway || addr.path || addr.street || addr.residential || '';
                    const area = addr.neighbourhood || addr.quarter || addr.hamlet || addr.subdivision || addr.allotments || addr.suburb || addr.village || '';

                    let streetLine = [houseNo, road].filter(Boolean).join(' ').trim();
                    if (!streetLine && area) {
                        streetLine = area;
                    }
                    if (!streetLine && data && data.display_name) {
                        const rawParts = data.display_name.split(',').map(s => s.trim()).filter(Boolean);
                        streetLine = rawParts.slice(0, 2).join(', ');
                    } else if (streetLine && area && !streetLine.toLowerCase().includes(area.toLowerCase()) && !area.toLowerCase().includes('tumana')) {
                        streetLine = `${streetLine}, ${area}`;
                    }

                    if (!insideTumana && data && data.display_name) {
                        const rawParts = data.display_name.split(',').map(s => s.trim()).filter(Boolean);
                        const outsideParts = rawParts.filter(p => !/^(metro manila|national capital region|philippines|\d{4})$/i.test(p));
                        streetLine = outsideParts.slice(0, 3).join(', ') || streetLine;
                    }

                    if (addrInput && streetLine) {
                        addrInput.value = streetLine;
                    }

                    if (purokSelect) {
                        if (!insideTumana) {
                            purokSelect.value = 'Non-Resident (Outside Barangay Tumana)';
                        } else {
                            const fullText = `${streetLine} ${area} ${data.display_name || ''}`.toLowerCase();
                            if (fullText.includes('doña petra') || fullText.includes('dona petra')) {
                                purokSelect.value = 'Doña Petra';
                            } else {
                                const purokMatch = fullText.match(/purok\s*([1-8])/i) || fullText.match(/zone\s*([1-8])/i);
                                if (purokMatch && purokMatch[1]) {
                                    purokSelect.value = `Purok ${purokMatch[1]}`;
                                } else if (!purokSelect.value || purokSelect.value === 'Non-Resident (Outside Barangay Tumana)') {
                                    // Match nearest Purok coordinate inside Tumana
                                    let bestPurok = 'Purok 1';
                                    let bestDist = Infinity;
                                    Object.entries(TUMANA_PUROK_COORDS).forEach(([pName, coords]) => {
                                        if (pName.startsWith('Non-Resident')) return;
                                        const d = Math.hypot(coords[0] - lat, coords[1] - lng);
                                        if (d < bestDist) {
                                            bestDist = d;
                                            bestPurok = pName;
                                        }
                                    });
                                    purokSelect.value = bestPurok;
                                }
                            }
                        }
                    }

                    updateStatus(
                        streetLine
                            ? `Pinned & auto-filled: ${streetLine}${insideTumana ? '' : ' (Outside Tumana)'}`
                            : 'Location pinned on map',
                        true
                    );
                    return;
                }
            } catch (e) {
                // Ignore network failure and use fallback
            }

            if (addrInput && !addrInput.value.trim()) {
                addrInput.value = `Pinned Residence (${lat.toFixed(5)}, ${lng.toFixed(5)})`;
            }
            if (purokSelect && !insideTumana) {
                purokSelect.value = 'Non-Resident (Outside Barangay Tumana)';
            }
            updateStatus('Residence location pinned on map', true);
        };

        const forwardGeocodeFromInputs = async () => {
            const seq = ++geocodeSeq;
            const addrVal = (document.getElementById(cfg.addressId)?.value || '').trim();
            const purokVal = (document.getElementById(cfg.purokId)?.value || '').trim();
            if (!addrVal && !purokVal) return;

            updateStatus('Locating address on map...', false);
            const isNonResident = purokVal.toLowerCase().includes('non-resident');
            const queryParts = [];
            if (addrVal) queryParts.push(addrVal);
            if (!isNonResident) {
                if (purokVal && !purokVal.toLowerCase().startsWith('purok')) {
                    queryParts.push(purokVal);
                }
                queryParts.push('Tumana, Marikina, Metro Manila, Philippines');
            } else {
                queryParts.push('Philippines');
            }

            try {
                const url = `https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=ph&q=${encodeURIComponent(queryParts.join(', '))}`;
                const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (seq !== geocodeSeq) return;
                if (res.ok) {
                    const data = await res.json();
                    if (Array.isArray(data) && data.length > 0 && data[0].lat && data[0].lon) {
                        setPin(parseFloat(data[0].lat), parseFloat(data[0].lon), 'Auto-pinned from address', false);
                        return;
                    }
                }
            } catch (e) {}

            if (seq !== geocodeSeq) return;
            if (purokVal && TUMANA_PUROK_COORDS[purokVal]) {
                const [fLat, fLng] = TUMANA_PUROK_COORDS[purokVal];
                setPin(fLat, fLng, `Pinned to ${purokVal} area (drag pin to exact house)`, false);
            }
        };

        const initMap = () => {
            const mapEl = document.getElementById(cfg.mapId);
            if (!mapEl || typeof L === 'undefined') return;
            if (!map) {
                map = L.map(mapEl, {
                    center: [14.6575, 121.0989],
                    zoom: 16,
                    minZoom: 13,
                    maxZoom: 19
                });
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap'
                }).addTo(map);

                L.polygon(TUMANA_BOUNDARY_COORDS, {
                    color: cfg.accentColor,
                    weight: 2,
                    fillColor: cfg.accentColor,
                    fillOpacity: 0.10
                }).addTo(map);

                map.on('click', function(ev) {
                    setPin(ev.latlng.lat, ev.latlng.lng, 'Pinned on map — fetching street address...', true);
                });

                document.getElementById(cfg.recenterBtnId)?.addEventListener('click', () => {
                    map.setView([14.6575, 121.0989], 16);
                });
                document.getElementById(cfg.clearBtnId)?.addEventListener('click', clearPin);
                document.getElementById(cfg.pinBtnId)?.addEventListener('click', forwardGeocodeFromInputs);
                document.getElementById(cfg.addressId)?.addEventListener('change', forwardGeocodeFromInputs);
                document.getElementById(cfg.purokId)?.addEventListener('change', forwardGeocodeFromInputs);
            }
            setTimeout(() => {
                map.invalidateSize();
            }, 180);
        };

        return { initMap, setPin, clearPin, forwardGeocodeFromInputs };
    };

    const compMapCtrl = createPaanyayaPartyMapController({
        mapId: 'compLocationMap',
        latId: 'compLocationLat',
        lngId: 'compLocationLng',
        statusId: 'compMapStatus',
        coordsId: 'compMapCoords',
        recenterBtnId: 'compRecenterMapBtn',
        clearBtnId: 'compClearPinBtn',
        pinBtnId: 'btnPinCompAddress',
        addressId: 'schedComplainantAddress',
        purokId: 'schedComplainantPurok',
        accentColor: '#059669',
        syncLegacyIds: false
    });

    const invMapCtrl = createPaanyayaPartyMapController({
        mapId: 'invLocationMap',
        latId: 'invLocationLat',
        lngId: 'invLocationLng',
        statusId: 'invMapStatus',
        coordsId: 'invMapCoords',
        recenterBtnId: 'invRecenterMapBtn',
        clearBtnId: 'invClearPinBtn',
        pinBtnId: 'btnPinInvAddress',
        addressId: 'schedInviteeAddress',
        purokId: 'schedInviteePurok',
        accentColor: '#2563eb',
        syncLegacyIds: true
    });

    let paanyayaMapInstance = null;
    let paanyayaMapMarker = null;
    const tumanaCenter = [14.6570, 121.0992];
    const tumanaBoundary = [
        [14.663414, 121.095679],
        [14.661270, 121.103242],
        [14.656226, 121.102930],
        [14.650236, 121.102975],
        [14.650504, 121.095501],
        [14.655286, 121.094745],
        [14.663414, 121.095679]
    ];

    const setPaanyayaMapPin = (lat, lng, autoReverseGeocode = true) => {
        const latNum = parseFloat(lat);
        const lngNum = parseFloat(lng);
        if (isNaN(latNum) || isNaN(lngNum)) return;

        const latInput = document.getElementById('paanyayaLocationLat');
        const lngInput = document.getElementById('paanyayaLocationLng');
        const coordsEl = document.getElementById('paanyayaLocationCoords');
        if (latInput) latInput.value = latNum.toFixed(6);
        if (lngInput) lngInput.value = lngNum.toFixed(6);
        if (coordsEl) {
            coordsEl.textContent = `${latNum.toFixed(5)}, ${lngNum.toFixed(5)}`;
            coordsEl.style.color = '#0f766e';
            coordsEl.style.fontWeight = '700';
        }

        if (paanyayaMapInstance && typeof L !== 'undefined') {
            if (!paanyayaMapMarker) {
                paanyayaMapMarker = L.marker([latNum, lngNum], { draggable: true }).addTo(paanyayaMapInstance);
                paanyayaMapMarker.on('dragend', function(ev) {
                    const pos = ev.target.getLatLng();
                    setPaanyayaMapPin(pos.lat, pos.lng, true);
                });
            } else {
                paanyayaMapMarker.setLatLng([latNum, lngNum]);
            }
            paanyayaMapInstance.panTo([latNum, lngNum]);
        }

        if (autoReverseGeocode) {
            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${encodeURIComponent(latNum)}&lon=${encodeURIComponent(lngNum)}`)
                .then(r => r.ok ? r.json() : null)
                .then(data => {
                    if (!data || !data.address) return;
                    const a = data.address;
                    const streetParts = [a.house_number, a.road || a.pedestrian || a.residential || a.suburb || a.neighbourhood].filter(Boolean);
                    const invAddrEl = document.getElementById('schedInviteeAddress');
                    if (invAddrEl && !invAddrEl.value.trim() && streetParts.length > 0) {
                        invAddrEl.value = streetParts.join(' ');
                    }
                    const invPurokEl = document.getElementById('schedInviteePurok');
                    if (invPurokEl && !invPurokEl.value) {
                        const dispLower = String(data.display_name || '').toLowerCase();
                        if (dispLower.includes('doña petra') || dispLower.includes('dona petra')) {
                            invPurokEl.value = 'Doña Petra';
                        } else if (!dispLower.includes('tumana')) {
                            invPurokEl.value = 'Non-Resident (Outside Barangay Tumana)';
                        } else {
                            invPurokEl.value = 'Purok 1';
                        }
                    }
                })
                .catch(() => {});
        }
    };

    const clearPaanyayaMapPin = () => {
        const latInput = document.getElementById('paanyayaLocationLat');
        const lngInput = document.getElementById('paanyayaLocationLng');
        const coordsEl = document.getElementById('paanyayaLocationCoords');
        if (latInput) latInput.value = '';
        if (lngInput) lngInput.value = '';
        if (coordsEl) {
            coordsEl.textContent = 'Click on the map to pin location';
            coordsEl.style.color = '#64748b';
            coordsEl.style.fontWeight = '400';
        }
        if (paanyayaMapInstance && paanyayaMapMarker) {
            paanyayaMapInstance.removeLayer(paanyayaMapMarker);
            paanyayaMapMarker = null;
        }
    };

    const initPaanyayaLocationMap = () => {
        const mapEl = document.getElementById('paanyayaLocationMap');
        if (!mapEl || typeof L === 'undefined') return;
        if (!paanyayaMapInstance) {
            paanyayaMapInstance = L.map(mapEl, {
                center: tumanaCenter,
                zoom: 16,
                minZoom: 13,
                maxZoom: 19
            });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(paanyayaMapInstance);

            L.polygon(tumanaBoundary, {
                color: '#2563eb',
                weight: 2,
                fillColor: '#3b82f6',
                fillOpacity: 0.12
            }).addTo(paanyayaMapInstance);

            paanyayaMapInstance.on('click', function(ev) {
                setPaanyayaMapPin(ev.latlng.lat, ev.latlng.lng, true);
            });
        }
        setTimeout(() => {
            paanyayaMapInstance.invalidateSize();
        }, 160);
    };

    document.getElementById('paanyayaRecenterMapBtn')?.addEventListener('click', function() {
        if (paanyayaMapInstance) {
            paanyayaMapInstance.setView(tumanaCenter, 16);
        }
    });
    document.getElementById('paanyayaClearPinBtn')?.addEventListener('click', clearPaanyayaMapPin);

    const openSchedModal = () => {
        updateMinDate();
        if (schedModal) schedModal.style.display = 'flex';
        compMapCtrl.initMap();
        invMapCtrl.initMap();
        initPaanyayaLocationMap();
    };
    const closeSchedModal = () => { if (schedModal) schedModal.style.display = 'none'; };

    if (openSchedBtn) openSchedBtn.addEventListener('click', openSchedModal);
    if (closeSchedBtn) closeSchedBtn.addEventListener('click', closeSchedModal);
    if (cancelSchedBtn) cancelSchedBtn.addEventListener('click', closeSchedModal);
    if (schedModal) {
        schedModal.addEventListener('click', (e) => {
            if (e.target === schedModal) closeSchedModal();
        });
    }

    // Resident Directory Quick-Fill & Contact Toggle Handlers
    const compResSelect = document.getElementById('schedCompResidentSelect');
    if (compResSelect) {
        compResSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            document.getElementById('schedComplainantResidentId').value = this.value || '';
            if (!this.value || !opt) return;
            document.getElementById('schedComplainantFirstName').value = opt.dataset.first || '';
            document.getElementById('schedComplainantMiddleName').value = opt.dataset.middle || '';
            document.getElementById('schedComplainantLastName').value = opt.dataset.last || '';
            document.getElementById('schedComplainantAddress').value = opt.dataset.address || '';
            const purokSel = document.getElementById('schedComplainantPurok');
            if (purokSel && opt.dataset.purok) {
                purokSel.value = opt.dataset.purok;
            }
            const contactInput = document.getElementById('schedComplainantContact');
            const chkNoContact = document.getElementById('chkCompNoContact');
            if (contactInput) {
                contactInput.value = opt.dataset.contact || '';
                if (!opt.dataset.contact && chkNoContact) {
                    chkNoContact.checked = true;
                    contactInput.required = false;
                    contactInput.disabled = true;
                } else if (chkNoContact) {
                    chkNoContact.checked = false;
                    contactInput.required = true;
                    contactInput.disabled = false;
                }
            }
            document.getElementById('schedComplainantEmail').value = opt.dataset.email || '';
            if (opt.dataset.lat && opt.dataset.lng) {
                compMapCtrl.setPin(opt.dataset.lat, opt.dataset.lng, 'Pinned from Resident Directory', false);
            } else {
                compMapCtrl.forwardGeocodeFromInputs();
            }
        });
    }

    const invResSelect = document.getElementById('schedInvResidentSelect');
    if (invResSelect) {
        invResSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            document.getElementById('schedInviteeResidentId').value = this.value || '';
            if (!this.value || !opt) return;
            document.getElementById('schedInviteeFirstName').value = opt.dataset.first || '';
            document.getElementById('schedInviteeMiddleName').value = opt.dataset.middle || '';
            document.getElementById('schedInviteeLastName').value = opt.dataset.last || '';
            document.getElementById('schedInviteeAddress').value = opt.dataset.address || '';
            const purokSel = document.getElementById('schedInviteePurok');
            if (purokSel && opt.dataset.purok) {
                purokSel.value = opt.dataset.purok;
            }
            const contactInput = document.getElementById('schedInviteeContact');
            const chkNoContact = document.getElementById('chkInvNoContact');
            if (contactInput) {
                contactInput.value = opt.dataset.contact || '';
                if (!opt.dataset.contact && chkNoContact) {
                    chkNoContact.checked = true;
                    contactInput.required = false;
                    contactInput.disabled = true;
                } else if (chkNoContact) {
                    chkNoContact.checked = false;
                    contactInput.required = true;
                    contactInput.disabled = false;
                }
            }
            document.getElementById('schedInviteeEmail').value = opt.dataset.email || '';
            if (opt.dataset.lat && opt.dataset.lng) {
                invMapCtrl.setPin(opt.dataset.lat, opt.dataset.lng, 'Pinned from Resident Directory', false);
                setPaanyayaMapPin(opt.dataset.lat, opt.dataset.lng, false);
            } else {
                invMapCtrl.forwardGeocodeFromInputs();
            }
        });
    }

    const chkCompNoContact = document.getElementById('chkCompNoContact');
    if (chkCompNoContact) {
        chkCompNoContact.addEventListener('change', function() {
            const inp = document.getElementById('schedComplainantContact');
            const star = document.getElementById('compContactReqStar');
            if (!inp) return;
            inp.required = !this.checked;
            inp.disabled = this.checked;
            if (this.checked) inp.value = '';
            if (star) star.style.display = this.checked ? 'none' : 'inline';
        });
    }

    const chkInvNoContact = document.getElementById('chkInvNoContact');
    if (chkInvNoContact) {
        chkInvNoContact.addEventListener('change', function() {
            const inp = document.getElementById('schedInviteeContact');
            const star = document.getElementById('invContactReqStar');
            if (!inp) return;
            inp.required = !this.checked;
            inp.disabled = this.checked;
            if (this.checked) inp.value = '';
            if (star) star.style.display = this.checked ? 'none' : 'inline';
        });
    }

    if (schedForm) {
        schedForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            const compResId = document.getElementById('schedComplainantResidentId')?.value || '';
            const compFirst = document.getElementById('schedComplainantFirstName').value.trim();
            const compMiddle = document.getElementById('schedComplainantMiddleName').value.trim();
            const compLast = document.getElementById('schedComplainantLastName').value.trim();
            const compAddr = document.getElementById('schedComplainantAddress').value.trim();
            const compPurok = document.getElementById('schedComplainantPurok').value.trim();
            const compContact = document.getElementById('schedComplainantContact').value.trim();
            const compEmail = document.getElementById('schedComplainantEmail').value.trim();
            const compNoContact = document.getElementById('chkCompNoContact')?.checked;

            const invResId = document.getElementById('schedInviteeResidentId')?.value || '';
            const invFirst = document.getElementById('schedInviteeFirstName').value.trim();
            const invMiddle = document.getElementById('schedInviteeMiddleName').value.trim();
            const invLast = document.getElementById('schedInviteeLastName').value.trim();
            const invAddr = document.getElementById('schedInviteeAddress').value.trim();
            const invPurok = document.getElementById('schedInviteePurok').value.trim();
            const invContact = document.getElementById('schedInviteeContact').value.trim();
            const invEmail = document.getElementById('schedInviteeEmail').value.trim();
            const invNoContact = document.getElementById('chkInvNoContact')?.checked;

            const date = schedDateInput.value;
            const time = schedTimeInput.value;
            const venue = document.getElementById('schedVenue').value.trim() || 'Tanggapan ng Barangay Tumana';
            const salaysay = document.getElementById('schedSalaysay').value.trim();

            if (!compFirst || !compLast) {
                agapAlert('Please enter both First Name and Last Name of the complainant (nagpaanyaya).', 'warning');
                return;
            }
            if (!compAddr || !compPurok) {
                agapAlert('Please enter the Street Address and Purok/Zone of the complainant (nagpaanyaya).', 'warning');
                return;
            }
            if (!compNoContact && !compContact) {
                agapAlert('Please enter the Contact Number of the complainant or check "No contact no.".', 'warning');
                return;
            }

            if (!invFirst || !invLast) {
                agapAlert('Please enter both First Name and Last Name of the person being invited (inanyayahan).', 'warning');
                return;
            }
            if (!invAddr || !invPurok) {
                agapAlert('Please enter the Street Address and Purok/Zone of the person being invited (inanyayahan) for delivery.', 'warning');
                return;
            }
            if (!invNoContact && !invContact) {
                agapAlert('Please enter the Contact Number of the invitee or check "Unknown / None".', 'warning');
                return;
            }

            if (!date || !time) {
                agapAlert('Please provide both scheduled date and time.', 'warning');
                return;
            }
            if (!salaysay) {
                agapAlert('Please provide the Salaysay (Statement / Reason for Invitation).', 'warning');
                return;
            }

            const complainantFullName = [compFirst, compMiddle, compLast].filter(Boolean).join(' ');
            const inviteeFullName = [invFirst, invMiddle, invLast].filter(Boolean).join(' ');

            submitSchedBtn.disabled = true;
            submitSchedBtn.textContent = 'Saving...';

            try {
                const payload = {
                    complainant_resident_id: compResId,
                    complainant_first_name: compFirst,
                    complainant_middle_name: compMiddle,
                    complainant_last_name: compLast,
                    complainant_name: complainantFullName,
                    complainant_address: compAddr,
                    complainant_purok: compPurok,
                    complainant_contact_no: compContact,
                    complainant_email: compEmail,
                    invitee_resident_id: invResId,
                    invitee_first_name: invFirst,
                    invitee_middle_name: invMiddle,
                    invitee_last_name: invLast,
                    invitee_name: inviteeFullName,
                    invitee_address: invAddr,
                    invitee_purok: invPurok,
                    invitee_contact_no: invContact,
                    invitee_email: invEmail,
                    complainant_latitude: document.getElementById('compLocationLat')?.value || '',
                    complainant_longitude: document.getElementById('compLocationLng')?.value || '',
                    invitee_latitude: document.getElementById('invLocationLat')?.value || '',
                    invitee_longitude: document.getElementById('invLocationLng')?.value || '',
                    location_latitude: document.getElementById('invLocationLat')?.value || document.getElementById('paanyayaLocationLat')?.value || '',
                    location_longitude: document.getElementById('invLocationLng')?.value || document.getElementById('paanyayaLocationLng')?.value || '',
                    scheduled_date: date,
                    scheduled_time: time,
                    venue: venue,
                    salaysay: salaysay,
                    require_party_details: 1,
                    complaint_id: 0,
                    has_e_signature: 1
                };

                const res = await fetch('../../../backend/api/paanyaya/schedule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const result = await res.json().catch(() => ({}));

                if (!res.ok || !result.success) {
                    agapAlert(result.message || 'Hindi ma-iskedyul ang Paanyaya.', 'error');
                    submitSchedBtn.disabled = false;
                    submitSchedBtn.textContent = 'Save & Generate Paanyaya';
                    return;
                }

                closeSchedModal();
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup('Matagumpay na nai-iskedyul ang Paanyaya para kay ' + inviteeFullName + '!', 'Tagumpay!');
                } else {
                    alert('Matagumpay na nai-iskedyul ang Paanyaya para kay ' + inviteeFullName + '!');
                }
                window.location.reload();
            } catch (err) {
                console.error(err);
                agapAlert('May naganap na error sa pag-save ng Paanyaya.', 'error');
                submitSchedBtn.disabled = false;
                submitSchedBtn.textContent = 'Save & Generate Paanyaya';
            }
        });
    }

    // =========================================================================
    // 2. LETTER PREVIEW MODAL (Replaces Page Redirection)
    // =========================================================================
    const previewModal = document.getElementById('paanyayaPreviewModal');
    const previewIframe = document.getElementById('paanyayaPreviewIframe');
    const previewLoading = document.getElementById('previewLoadingNotice');
    const previewCloseBtn = document.getElementById('previewModalCloseBtn');
    const previewCloseFootBtn = document.getElementById('previewModalCloseFootBtn');
    const previewPrintBtn = document.getElementById('btnPrintFromModal');
    const previewDownloadBtn = document.getElementById('btnDownloadFromModal');
    const previewTitle = document.getElementById('previewModalTitle');
    const previewSubtitle = document.getElementById('previewModalSubtitle');

    const closePreviewModal = () => {
        if (previewModal) previewModal.style.display = 'none';
        if (previewIframe) previewIframe.src = '';
    };

    if (previewCloseBtn) previewCloseBtn.addEventListener('click', closePreviewModal);
    if (previewCloseFootBtn) previewCloseFootBtn.addEventListener('click', closePreviewModal);
    if (previewModal) {
        previewModal.addEventListener('click', (e) => {
            if (e.target === previewModal) closePreviewModal();
        });
    }

    document.querySelectorAll('.btn-preview-modal').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const paanyayaId = parseInt(this.dataset.paanyayaId || '0', 10) || 0;
            const complaintId = parseInt(this.dataset.complaintId || '0', 10) || 0;
            const attempt = parseInt(this.dataset.attempt || '1', 10) || 1;
            const attemptQuery = attempt >= 2 ? `&attempt_number=${attempt}` : '';
            const invitee = this.dataset.name || 'Inanyayahan';

            if (previewTitle) previewTitle.textContent = `✉️ Paanyaya Letter: ${invitee}`;
            if (previewSubtitle) previewSubtitle.textContent = `Barangay Tumana Official Dialogue Invitation Letter`;

            const previewUrl = (paanyayaId > 0)
                ? `../../../backend/api/paanyaya/preview.php?paanyaya_id=${paanyayaId}&format=html${attemptQuery}`
                : `../../../backend/api/paanyaya/preview.php?complaint_id=${complaintId}&format=html${attemptQuery}`;

            const pdfUrl = (paanyayaId > 0)
                ? `../../../backend/api/paanyaya/preview.php?paanyaya_id=${paanyayaId}&format=pdf${attemptQuery}`
                : `../../../backend/api/paanyaya/preview.php?complaint_id=${complaintId}&format=pdf${attemptQuery}`;

            if (previewDownloadBtn) {
                previewDownloadBtn.href = pdfUrl + '&download=1';
            }

            if (previewLoading) previewLoading.style.display = 'flex';
            if (previewIframe) {
                previewIframe.style.display = 'none';
                previewIframe.onload = function() {
                    if (previewLoading) previewLoading.style.display = 'none';
                    previewIframe.style.display = 'block';
                };
                previewIframe.src = previewUrl;
            }

            if (previewModal) previewModal.style.display = 'flex';
        });
    });

    if (previewPrintBtn) {
        previewPrintBtn.addEventListener('click', function() {
            if (previewIframe && previewIframe.contentWindow) {
                previewIframe.contentWindow.focus();
                previewIframe.contentWindow.print();
            }
        });
    }

    // =========================================================================
    // 3. ATTENDANCE TRACKING & SEAMLESS ESCALATION MODAL
    // =========================================================================
    const attModal = document.getElementById('paanyayaAttendanceModal');
    const attCloseBtn = document.getElementById('attendanceModalCloseBtn');
    const attCancelBtn = document.getElementById('attendanceCancelBtn');
    const attIdInput = document.getElementById('attPaanyayaId');
    const attComplaintIdInput = document.getElementById('attComplaintId');
    const attInviteeLabel = document.getElementById('attInviteeLabel');
    const attComplainantLabel = document.getElementById('attComplainantLabel');
    const attScheduleLabel = document.getElementById('attScheduleLabel');
    const attStatusBadge = document.getElementById('attStatusBadge');
    const attCompNameDisplay = document.getElementById('attCompNameDisplay');
    const attInvNameDisplay = document.getElementById('attInvNameDisplay');
    const attSalaysayInput = document.getElementById('attSalaysay');
    const attAttemptBadge = document.getElementById('attAttemptBadge');

    const btnSaveAttOnly = document.getElementById('btnSaveAttendanceOnly');
    const btnResolveDiag = document.getElementById('btnResolveDialogue');
    const btnEscalateMed = document.getElementById('btnEscalateMediation');
    const btnOpenSecondPaanyaya = document.getElementById('btnOpenSecondPaanyaya');
    const secondPaanyayaPanel = document.getElementById('secondPaanyayaPanel');
    const alreadySecondPaanyayaNotice = document.getElementById('alreadySecondPaanyayaNotice');
    const p2DateInput = document.getElementById('p2Date');
    const p2TimeInput = document.getElementById('p2Time');
    const btnCancelP2 = document.getElementById('btnCancelP2');
    const btnConfirmP2 = document.getElementById('btnConfirmP2');

    const closeAttModal = () => { if (attModal) attModal.style.display = 'none'; };
    if (attCloseBtn) attCloseBtn.addEventListener('click', closeAttModal);
    if (attCancelBtn) attCancelBtn.addEventListener('click', closeAttModal);
    if (attModal) {
        attModal.addEventListener('click', (e) => {
            if (e.target === attModal) closeAttModal();
        });
    }

    function getSuggestedNextDate() {
        const d = new Date();
        let added = 0;
        while (added < 3) {
            d.setDate(d.getDate() + 1);
            if (d.getDay() !== 0 && d.getDay() !== 6) {
                added++;
            }
        }
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    // =========================================================================
    // STATE MACHINE / LOCK ENGINE:
    // - If it was settled in the paanyaya, it should lock other buttons
    // - If it was escalated into a mediation and saved, it should lock the buttons
    // =========================================================================
    function applyAttendanceLockState(status, complaintId, attempt, complaintNumber = '') {
        const normStatus = String(status || '').trim().toLowerCase();
        const parsedComplaintId = parseInt(complaintId, 10) || 0;
        const isSettled = ['resolved', 'settled', 'completed', 'done'].includes(normStatus);
        const isEscalated = normStatus === 'escalated' || (parsedComplaintId > 0 && !isSettled);

        const lockNotice = document.getElementById('attLockNoticeBanner');
        const viewMedWrap = document.getElementById('attViewMediationLinkWrap');
        const viewMedLink = document.getElementById('attViewMediationLink');
        const compRadios = document.querySelectorAll('input[name="complainant_attended"]');
        const invRadios = document.querySelectorAll('input[name="invitee_attended"]');

        if (isSettled) {
            // -----------------------------------------------------------------
            // CASE 1: SETTLED IN PAANYAYA -> LOCK OTHER BUTTONS
            // -----------------------------------------------------------------
            if (lockNotice) {
                lockNotice.style.display = 'flex';
                lockNotice.style.alignItems = 'flex-start';
                lockNotice.style.gap = '10px';
                lockNotice.style.background = '#ecfdf5';
                lockNotice.style.border = '1.5px solid #86efac';
                lockNotice.style.color = '#065f46';
                lockNotice.innerHTML = `
                    <span style="font-size: 1.25rem; line-height: 1;">🔒</span>
                    <div>
                        <strong style="display: block; font-size: 0.88rem; color: #047857; margin-bottom: 2px;">
                            Naresolba sa Paanyaya (Kasunduan)
                        </strong>
                        <span style="font-size: 0.8rem; color: #065f46; line-height: 1.4;">
                            Ang usaping ito ay ganap nang naayos at naresolba sa pribadong kasunduan (Settled). Naka-lock na ang ibang mga pindutan upang maiwasan ang pagbabago.
                        </span>
                    </div>
                `;
            }

            // Lock other buttons: Save Attendance, 2nd Paanyaya, Escalate to Mediation
            if (btnSaveAttOnly) {
                btnSaveAttOnly.disabled = true;
                btnSaveAttOnly.classList.add('is-locked-btn');
                btnSaveAttOnly.style.opacity = '0.55';
                btnSaveAttOnly.style.cursor = 'not-allowed';
                btnSaveAttOnly.style.pointerEvents = 'none';
                btnSaveAttOnly.style.background = '#f1f5f9';
                btnSaveAttOnly.style.color = '#94a3b8';
                btnSaveAttOnly.style.borderColor = '#cbd5e1';
                btnSaveAttOnly.innerHTML = '🔒 Save Attendance';
                btnSaveAttOnly.title = 'Naka-lock dahil naresolba na ang Paanyaya sa Kasunduan.';
            }

            if (btnOpenSecondPaanyaya) {
                btnOpenSecondPaanyaya.disabled = true;
                btnOpenSecondPaanyaya.classList.add('is-locked-btn');
                btnOpenSecondPaanyaya.style.opacity = '0.55';
                btnOpenSecondPaanyaya.style.cursor = 'not-allowed';
                btnOpenSecondPaanyaya.style.pointerEvents = 'none';
                btnOpenSecondPaanyaya.style.background = '#e2e8f0';
                btnOpenSecondPaanyaya.style.color = '#64748b';
                btnOpenSecondPaanyaya.style.borderColor = '#cbd5e1';
                btnOpenSecondPaanyaya.innerHTML = '🔒 2nd Paanyaya';
                btnOpenSecondPaanyaya.title = 'Naka-lock dahil naresolba na ang Paanyaya sa Kasunduan.';
            }

            if (btnEscalateMed) {
                btnEscalateMed.disabled = true;
                btnEscalateMed.classList.add('is-locked-btn');
                btnEscalateMed.style.opacity = '0.55';
                btnEscalateMed.style.cursor = 'not-allowed';
                btnEscalateMed.style.pointerEvents = 'none';
                btnEscalateMed.style.background = '#e2e8f0';
                btnEscalateMed.style.color = '#64748b';
                btnEscalateMed.style.borderColor = '#cbd5e1';
                btnEscalateMed.innerHTML = '🔒 Formal Complaint Details';
                btnEscalateMed.title = 'Naka-lock dahil naresolba na ang Paanyaya sa Kasunduan.';
            }

            // The Kasunduan button itself shows settled state and is finalized
            if (btnResolveDiag) {
                btnResolveDiag.disabled = true;
                btnResolveDiag.classList.remove('is-locked-btn');
                btnResolveDiag.style.opacity = '1';
                btnResolveDiag.style.cursor = 'default';
                btnResolveDiag.style.pointerEvents = 'none';
                btnResolveDiag.style.background = '#15803d';
                btnResolveDiag.style.color = '#ffffff';
                btnResolveDiag.style.borderColor = '#166534';
                btnResolveDiag.innerHTML = '✓ Kasunduan (Settled)';
                btnResolveDiag.title = 'Naitatala na bilang Kasunduan (Settled).';
            }

            // Lock attendance inputs & narrative
            compRadios.forEach(r => r.disabled = true);
            invRadios.forEach(r => r.disabled = true);
            if (attSalaysayInput) {
                attSalaysayInput.readOnly = true;
                attSalaysayInput.style.backgroundColor = '#f8fafc';
                attSalaysayInput.style.color = '#475569';
            }

            if (secondPaanyayaPanel) secondPaanyayaPanel.style.display = 'none';
            if (alreadySecondPaanyayaNotice) alreadySecondPaanyayaNotice.style.display = 'none';
            if (viewMedWrap) viewMedWrap.style.display = 'none';

        } else if (isEscalated) {
            // -----------------------------------------------------------------
            // CASE 2: ESCALATED INTO A MEDIATION AND SAVED -> LOCK ALL BUTTONS
            // -----------------------------------------------------------------
            const caseLabel = complaintNumber ? ` (Case #${complaintNumber})` : '';
            if (lockNotice) {
                lockNotice.style.display = 'flex';
                lockNotice.style.alignItems = 'flex-start';
                lockNotice.style.gap = '10px';
                lockNotice.style.background = '#fffbeb';
                lockNotice.style.border = '1.5px solid #fde047';
                lockNotice.style.color = '#854d0e';
                lockNotice.innerHTML = `
                    <span style="font-size: 1.25rem; line-height: 1;">🔒</span>
                    <div>
                        <strong style="display: block; font-size: 0.88rem; color: #b45309; margin-bottom: 2px;">
                            Nai-akyat sa Pormal na Mediation${caseLabel}
                        </strong>
                        <span style="font-size: 0.8rem; color: #854d0e; line-height: 1.4;">
                            Ang usaping ito ay pormal nang nai-doket sa Mediation at nai-save bilang pormal na reklamo. Naka-lock ang lahat ng pindutan ng aksyon.
                        </span>
                    </div>
                `;
            }

            // Lock all action buttons
            if (btnSaveAttOnly) {
                btnSaveAttOnly.disabled = true;
                btnSaveAttOnly.classList.add('is-locked-btn');
                btnSaveAttOnly.style.opacity = '0.55';
                btnSaveAttOnly.style.cursor = 'not-allowed';
                btnSaveAttOnly.style.pointerEvents = 'none';
                btnSaveAttOnly.style.background = '#f1f5f9';
                btnSaveAttOnly.style.color = '#94a3b8';
                btnSaveAttOnly.style.borderColor = '#cbd5e1';
                btnSaveAttOnly.innerHTML = '🔒 Save Attendance';
                btnSaveAttOnly.title = 'Naka-lock dahil nai-akyat na sa Mediation.';
            }

            if (btnResolveDiag) {
                btnResolveDiag.disabled = true;
                btnResolveDiag.classList.add('is-locked-btn');
                btnResolveDiag.style.opacity = '0.55';
                btnResolveDiag.style.cursor = 'not-allowed';
                btnResolveDiag.style.pointerEvents = 'none';
                btnResolveDiag.style.background = '#e2e8f0';
                btnResolveDiag.style.color = '#64748b';
                btnResolveDiag.style.borderColor = '#cbd5e1';
                btnResolveDiag.innerHTML = '🔒 Kasunduan (Settle)';
                btnResolveDiag.title = 'Naka-lock dahil nai-akyat na sa Mediation.';
            }

            if (btnOpenSecondPaanyaya) {
                btnOpenSecondPaanyaya.disabled = true;
                btnOpenSecondPaanyaya.classList.add('is-locked-btn');
                btnOpenSecondPaanyaya.style.opacity = '0.55';
                btnOpenSecondPaanyaya.style.cursor = 'not-allowed';
                btnOpenSecondPaanyaya.style.pointerEvents = 'none';
                btnOpenSecondPaanyaya.style.background = '#e2e8f0';
                btnOpenSecondPaanyaya.style.color = '#64748b';
                btnOpenSecondPaanyaya.style.borderColor = '#cbd5e1';
                btnOpenSecondPaanyaya.innerHTML = '🔒 2nd Paanyaya';
                btnOpenSecondPaanyaya.title = 'Naka-lock dahil nai-akyat na sa Mediation.';
            }

            if (btnEscalateMed) {
                btnEscalateMed.disabled = true;
                btnEscalateMed.classList.remove('is-locked-btn');
                btnEscalateMed.style.opacity = '1';
                btnEscalateMed.style.cursor = 'default';
                btnEscalateMed.style.pointerEvents = 'none';
                btnEscalateMed.style.background = '#6d28d9';
                btnEscalateMed.style.color = '#ffffff';
                btnEscalateMed.style.borderColor = '#5b21b6';
                btnEscalateMed.innerHTML = '⚖️ Escalated to Mediation 🔒';
                btnEscalateMed.title = 'Nai-akyat na sa Pormal na Mediation.';
            }

            // Direct link to the complaint details
            if (parsedComplaintId > 0 && viewMedWrap && viewMedLink) {
                viewMedWrap.style.display = 'block';
                viewMedLink.href = `../complaints/complaint-details.php?id=${parsedComplaintId}&tab=mediation`;
            } else if (viewMedWrap) {
                viewMedWrap.style.display = 'none';
            }

            // Lock inputs
            compRadios.forEach(r => r.disabled = true);
            invRadios.forEach(r => r.disabled = true);
            if (attSalaysayInput) {
                attSalaysayInput.readOnly = true;
                attSalaysayInput.style.backgroundColor = '#f8fafc';
                attSalaysayInput.style.color = '#475569';
            }

            if (secondPaanyayaPanel) secondPaanyayaPanel.style.display = 'none';
            if (alreadySecondPaanyayaNotice) alreadySecondPaanyayaNotice.style.display = 'none';

        } else {
            // -----------------------------------------------------------------
            // CASE 3: NORMAL UNLOCKED ACTIVE PAANYAYA
            // -----------------------------------------------------------------
            if (lockNotice) lockNotice.style.display = 'none';
            if (viewMedWrap) viewMedWrap.style.display = 'none';

            if (btnSaveAttOnly) {
                btnSaveAttOnly.disabled = false;
                btnSaveAttOnly.classList.remove('is-locked-btn');
                btnSaveAttOnly.style.opacity = '';
                btnSaveAttOnly.style.cursor = '';
                btnSaveAttOnly.style.pointerEvents = '';
                btnSaveAttOnly.style.background = '#ffffff';
                btnSaveAttOnly.style.color = '';
                btnSaveAttOnly.style.borderColor = '#cbd5e1';
                btnSaveAttOnly.innerHTML = '💾 Save Attendance';
                btnSaveAttOnly.title = '';
            }

            if (btnResolveDiag) {
                btnResolveDiag.disabled = false;
                btnResolveDiag.classList.remove('is-locked-btn');
                btnResolveDiag.style.opacity = '';
                btnResolveDiag.style.cursor = '';
                btnResolveDiag.style.pointerEvents = '';
                btnResolveDiag.style.background = '#16a34a';
                btnResolveDiag.style.color = '#ffffff';
                btnResolveDiag.style.borderColor = '';
                btnResolveDiag.innerHTML = '🤝 Kasunduan (Settle)';
                btnResolveDiag.title = '';
            }

            if (btnOpenSecondPaanyaya) {
                btnOpenSecondPaanyaya.classList.remove('is-locked-btn');
                btnOpenSecondPaanyaya.style.opacity = '';
                btnOpenSecondPaanyaya.style.cursor = '';
                btnOpenSecondPaanyaya.style.pointerEvents = '';
                btnOpenSecondPaanyaya.style.background = '#0284c7';
                btnOpenSecondPaanyaya.style.color = '#ffffff';
                btnOpenSecondPaanyaya.style.borderColor = '';
                btnOpenSecondPaanyaya.innerHTML = '📩 2nd Paanyaya';
                btnOpenSecondPaanyaya.title = '';

                if (attempt >= 2) {
                    btnOpenSecondPaanyaya.style.display = 'none';
                    btnOpenSecondPaanyaya.disabled = true;
                    if (alreadySecondPaanyayaNotice) alreadySecondPaanyayaNotice.style.display = 'block';
                } else {
                    btnOpenSecondPaanyaya.style.display = 'flex';
                    btnOpenSecondPaanyaya.disabled = false;
                    if (alreadySecondPaanyayaNotice) alreadySecondPaanyayaNotice.style.display = 'none';
                }
            }

            if (btnEscalateMed) {
                btnEscalateMed.disabled = false;
                btnEscalateMed.classList.remove('is-locked-btn');
                btnEscalateMed.style.opacity = '';
                btnEscalateMed.style.cursor = '';
                btnEscalateMed.style.pointerEvents = '';
                btnEscalateMed.style.background = '#7c3aed';
                btnEscalateMed.style.color = '#ffffff';
                btnEscalateMed.style.borderColor = '';
                btnEscalateMed.innerHTML = '⚖️ Continue to Formal Complaint Details';
                btnEscalateMed.title = '';
            }

            compRadios.forEach(r => r.disabled = false);
            invRadios.forEach(r => r.disabled = false);
            if (attSalaysayInput) {
                attSalaysayInput.readOnly = false;
                attSalaysayInput.style.backgroundColor = '';
                attSalaysayInput.style.color = '';
            }
        }
    }

    document.querySelectorAll('.btn-open-attendance').forEach(btn => {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            const paanyayaId = parseInt(this.dataset.paanyayaId || 0, 10);
            const complaintId = parseInt(this.dataset.complaintId || 0, 10);
            const invitee = this.dataset.invitee || 'Inanyayahan';
            const complainant = this.dataset.complainant || 'Nagpaanyaya';
            const date = this.dataset.date || '';
            const time = this.dataset.time || '';
            const status = this.dataset.status || 'Scheduled';
            const attempt = parseInt(this.dataset.attempt || 1, 10);
            const complaintNumber = this.dataset.complaintNumber || '';

            if (attIdInput) attIdInput.value = paanyayaId;
            if (attComplaintIdInput) attComplaintIdInput.value = complaintId;
            if (attInviteeLabel) attInviteeLabel.textContent = `Inanyayahan: ${invitee}`;
            if (attComplainantLabel) attComplainantLabel.textContent = `Nagpaanyaya: ${complainant}`;
            if (attScheduleLabel) attScheduleLabel.textContent = `Iskedyul: ${date} ${time}`;
            if (attStatusBadge) {
                attStatusBadge.textContent = status;
                const isStResolved = ['resolved', 'settled', 'completed', 'done'].includes(status.toLowerCase());
                const isStEscalated = status.toLowerCase() === 'escalated' || (complaintId > 0 && !isStResolved);
                attStatusBadge.className = isStResolved ? 'status-pill-resolved' : (isStEscalated ? 'status-pill-escalated' : 'status-pill-scheduled');
            }
            if (attCompNameDisplay) attCompNameDisplay.textContent = complainant;
            if (attInvNameDisplay) attInvNameDisplay.textContent = invitee;

            // Update Attempt UI
            if (attAttemptBadge) {
                if (attempt >= 2) {
                    attAttemptBadge.textContent = 'Ikalawang Paanyaya (Attempt 2)';
                    attAttemptBadge.className = 'status-pill-escalated';
                } else {
                    attAttemptBadge.textContent = 'Unang Paanyaya (Attempt 1)';
                    attAttemptBadge.className = 'status-pill-scheduled';
                }
            }

            if (secondPaanyayaPanel) secondPaanyayaPanel.style.display = 'none';

            // Reset inputs to clean state
            if (attSalaysayInput) attSalaysayInput.value = '';
            setRadio('complainant_attended', 'Present');
            setRadio('invitee_attended', 'Absent');

            // Apply locked state immediately based on dataset attributes
            applyAttendanceLockState(status, complaintId, attempt, complaintNumber);

            // Open modal immediately
            if (attModal) attModal.style.display = 'flex';

            // Fetch latest persisted attendance and salaysay
            try {
                const res = await fetch(`../../../backend/api/paanyaya/attendance.php?paanyaya_id=${paanyayaId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const row = json.data;
                    if (row.salaysay && attSalaysayInput) attSalaysayInput.value = row.salaysay;
                    if (row.complainant_attended) setRadio('complainant_attended', row.complainant_attended);
                    if (row.invitee_attended) setRadio('invitee_attended', row.invitee_attended);

                    const effStatus = status.toLowerCase() === 'done' ? 'Done' : (row.status || status);
                    const effComplaintId = parseInt(row.complaint_id || row.escalated_to_complaint_id || complaintId, 10);
                    const effCompNumber = row.complaint_number || complaintNumber;

                    if (attComplaintIdInput) attComplaintIdInput.value = effComplaintId;
                    if (attStatusBadge) {
                        attStatusBadge.textContent = effStatus;
                        const isStResolved = ['resolved', 'settled', 'completed'].includes(effStatus.toLowerCase());
                        const isStEscalated = effStatus.toLowerCase() === 'escalated' || effComplaintId > 0;
                        attStatusBadge.className = isStResolved ? 'status-pill-resolved' : (isStEscalated ? 'status-pill-escalated' : 'status-pill-scheduled');
                    }

                    // Re-apply lock state with verified server data
                    applyAttendanceLockState(effStatus, effComplaintId, attempt, effCompNumber);
                }
            } catch (err) {
                console.warn('Could not fetch existing attendance data:', err);
            }
        });
    });

    function setRadio(name, value) {
        const rad = document.querySelector(`input[name="${name}"][value="${value}"]`);
        if (rad) rad.checked = true;
    }

    function getRadio(name) {
        return document.querySelector(`input[name="${name}"]:checked`)?.value || 'Present';
    }

    async function submitAttendanceAction(actionType, confirmMessage = null) {
        if (confirmMessage && !confirm(confirmMessage)) {
            return;
        }

        const paanyayaId = parseInt(attIdInput.value, 10);
        if (!paanyayaId) {
            agapAlert('Invalid Paanyaya record.', 'error');
            return;
        }

        const payload = {
            paanyaya_id: paanyayaId,
            complaint_id: parseInt(attComplaintIdInput.value || 0, 10),
            complainant_attended: getRadio('complainant_attended'),
            invitee_attended: getRadio('invitee_attended'),
            salaysay: attSalaysayInput?.value.trim() || '',
            action: actionType,
            p2_date: p2DateInput?.value || '',
            p2_time: p2TimeInput?.value || '14:00'
        };

        const activeBtn = (actionType === 'escalate') ? btnEscalateMed : 
                          ((actionType === 'resolve') ? btnResolveDiag : 
                          ((actionType === 'schedule_second_paanyaya') ? btnConfirmP2 : btnSaveAttOnly));
        const originalText = activeBtn ? activeBtn.innerHTML : '';
        if (activeBtn) {
            activeBtn.disabled = true;
            activeBtn.innerHTML = 'Saving...';
        }

        try {
            const res = await fetch('../../../backend/api/paanyaya/attendance.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify(payload)
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok || !data.success) {
                agapAlert(data.message || 'Nabigo ang pag-proseso ng aksyon.', 'error');
                if (activeBtn) {
                    activeBtn.disabled = false;
                    activeBtn.innerHTML = originalText;
                }
                return;
            }

            closeAttModal();

            if (actionType === 'escalate' && data.case_number) {
                const navMsg = `Matagumpay na nai-akyat ang Paanyaya sa Pormal na Mediation (Stage 1)!\nCase Number: ${data.case_number}\n\nNailipat ang Salaysay at tala ng Pagdalo ng mga panig.`;
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup(navMsg, 'Inilipat sa Mediation!');
                } else {
                    alert(navMsg);
                }
                if (data.complaint_id) {
                    window.location.href = `../complaints/complaint-details.php?id=${data.complaint_id}&tab=mediation`;
                    return;
                }
            } else if (actionType === 'schedule_second_paanyaya') {
                const msg = data.message || 'Matagumpay na nai-iskedyul ang Ikalawang Paanyaya (2nd Paanyaya)!';
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup(msg, 'Nai-iskedyul ang 2nd Paanyaya');
                } else {
                    alert(msg);
                }
            } else {
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup(data.message || 'Matagumpay na naitala ang aksyon.', 'Tagumpay!');
                } else {
                    alert(data.message || 'Matagumpay na naitala ang aksyon.');
                }
            }

            window.location.reload();
        } catch (err) {
            console.error(err);
            agapAlert('May naganap na error sa pag-proseso ng aksyon.', 'error');
            if (activeBtn) {
                activeBtn.disabled = false;
                activeBtn.innerHTML = originalText;
            }
        }
    }

    if (btnSaveAttOnly) {
        btnSaveAttOnly.addEventListener('click', () => submitAttendanceAction('save_attendance'));
    }
    if (btnResolveDiag) {
        btnResolveDiag.addEventListener('click', () => submitAttendanceAction('resolve', 'Sigurado ka bang naayos na ang sigalot at mamarkahang "Resolved" ang Paanyaya na ito?'));
    }
    if (btnEscalateMed) {
        btnEscalateMed.addEventListener('click', async () => {
            const paanyayaId = parseInt(attIdInput?.value, 10);
            if (!paanyayaId) {
                agapAlert('Invalid Paanyaya record.', 'error');
                return;
            }

            const confirmed = confirm(
                'Magpatuloy sa Formal Complaint Details?\n\n' +
                'Isi-save muna ang attendance at Salaysay ng Paanyaya. Pagkatapos, ilagay ang mga kinakailangang detalye para sa pormal na reklamo tulad ng category, case type, incident details, at relief na hinihingi. Hindi pa ito mai-dodocket hangga\'t hindi naisusumite ang form.'
            );
            if (!confirmed) return;

            const originalText = btnEscalateMed.innerHTML;
            btnEscalateMed.disabled = true;
            btnEscalateMed.innerHTML = 'Saving Paanyaya...';

            try {
                const response = await fetch('../../../backend/api/paanyaya/attendance.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        paanyaya_id: paanyayaId,
                        complaint_id: parseInt(attComplaintIdInput?.value || 0, 10),
                        complainant_attended: getRadio('complainant_attended'),
                        invitee_attended: getRadio('invitee_attended'),
                        salaysay: attSalaysayInput?.value.trim() || '',
                        action: 'save_attendance'
                    })
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'Unable to save the Paanyaya attendance.');
                }

                window.location.href = `../complaints/complaint-create.php?escalate_paanyaya_id=${encodeURIComponent(paanyayaId)}`;
            } catch (error) {
                console.error(error);
                agapAlert(error.message || 'Hindi naisave ang Paanyaya attendance.', 'error');
                btnEscalateMed.disabled = false;
                btnEscalateMed.innerHTML = originalText;
            }
        });
    }
    if (btnOpenSecondPaanyaya) {
        btnOpenSecondPaanyaya.addEventListener('click', () => {
            if (!secondPaanyayaPanel) return;
            const isHidden = (secondPaanyayaPanel.style.display === 'none' || secondPaanyayaPanel.style.display === '');
            secondPaanyayaPanel.style.display = isHidden ? 'block' : 'none';
            if (isHidden && p2DateInput) {
                p2DateInput.value = getSuggestedNextDate();
                const today = new Date().toISOString().split('T')[0];
                p2DateInput.min = today;
            }
        });
    }
    if (btnCancelP2) {
        btnCancelP2.addEventListener('click', () => {
            if (secondPaanyayaPanel) secondPaanyayaPanel.style.display = 'none';
        });
    }
    if (btnConfirmP2) {
        btnConfirmP2.addEventListener('click', () => {
            const p2Date = p2DateInput?.value || '';
            if (!p2Date) {
                agapAlert('Mangyaring pumili ng petsa para sa 2nd Paanyaya.', 'warning');
                return;
            }
            submitAttendanceAction('schedule_second_paanyaya', `Sigurado ka bang nais nang ilabas ang Ikalawang Paanyaya (2nd Paanyaya) sa darating na ${p2Date}?`);
        });
    }

    function agapAlert(msg, type = 'info') {
        if (typeof window.showPopupModal === 'function') {
            window.showPopupModal(msg, type, type === 'error' ? 'Pansin' : 'Paalala');
        } else if (typeof window.agapNotify === 'function') {
            window.agapNotify(msg, type);
        } else {
            alert(msg);
        }
    }

    // Tab toggle & client-side live filtering + pagination
    document.querySelectorAll('.agap-tab-item[data-status]').forEach(tab => {
        tab.addEventListener('click', function(e) {
            if (this.classList.contains('active') && this.dataset.status !== '') {
                e.preventDefault();
                const searchParam = new URLSearchParams(window.location.search).get('search');
                window.location.href = 'index.php' + (searchParam ? '?search=' + encodeURIComponent(searchParam) : '');
            }
        });
    });

    const paanyayaTbody = document.getElementById('paanyayaTbody');
    const paanyayaRows = paanyayaTbody ? Array.from(paanyayaTbody.querySelectorAll('tr[data-paanyaya-row="1"]')) : [];
    const paanyayaSearchInput = document.getElementById('paanyayaSearchInput');
    const paanyayaClearBtn = document.getElementById('paanyayaClearSearchBtn');
    const paanyayaPageSizeEl = document.getElementById('paanyayaPageSize');
    const paanyayaFooter = document.getElementById('paanyayaListFooter');
    const paanyayaSummary = document.getElementById('paanyayaPaginationSummary');
    const paanyayaControls = document.getElementById('paanyayaPaginationControls');
    let paanyayaCurrentPage = 1;
    let paanyayaPageSize = Number(paanyayaPageSizeEl?.value || 10) || 10;

    function syncPaanyayaEmptyRow(showEmpty) {
        if (!paanyayaTbody) return;
        let emptyRow = document.getElementById('paanyayaFilterEmptyRow');
        if (showEmpty) {
            if (!emptyRow) {
                emptyRow = document.createElement('tr');
                emptyRow.id = 'paanyayaFilterEmptyRow';
                emptyRow.innerHTML = '<td colspan="4" style="text-align:center; color:#64748b; padding:36px 20px;"><div style="font-weight:700; color:#0f172a;">No matching Paanyaya invitations</div><p style="margin:4px 0 0; font-size:0.84rem;">Try clearing your search keyword.</p></td>';
                paanyayaTbody.appendChild(emptyRow);
            }
            emptyRow.style.display = '';
        } else if (emptyRow) {
            emptyRow.style.display = 'none';
        }
    }

    function renderPaanyayaPagination(total, totalPages, startIdx, endIdx) {
        if (!paanyayaFooter || !paanyayaSummary || !paanyayaControls) return;
        if (total === 0) {
            paanyayaFooter.hidden = true;
            return;
        }
        paanyayaFooter.hidden = false;
        paanyayaSummary.textContent = `Showing ${startIdx + 1}–${endIdx} of ${total} record${total === 1 ? '' : 's'}`;
        paanyayaControls.replaceChildren();
        if (totalPages <= 1) return;

        const addPageBtn = (label, pageNum, disabled = false, active = false) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = `agap-page-btn${active ? ' is-active' : ''}`;
            btn.textContent = label;
            btn.disabled = disabled;
            if (active) btn.setAttribute('aria-current', 'page');
            btn.addEventListener('click', () => {
                if (!disabled && paanyayaCurrentPage !== pageNum) {
                    paanyayaCurrentPage = pageNum;
                    renderPaanyayaPage();
                }
            });
            paanyayaControls.appendChild(btn);
        };

        addPageBtn('‹', paanyayaCurrentPage - 1, paanyayaCurrentPage <= 1);
        const maxButtons = 5;
        let startPage = Math.max(1, paanyayaCurrentPage - Math.floor(maxButtons / 2));
        let endPage = Math.min(totalPages, startPage + maxButtons - 1);
        if (endPage - startPage < maxButtons - 1) startPage = Math.max(1, endPage - maxButtons + 1);
        if (startPage > 1) {
            addPageBtn('1', 1, false, paanyayaCurrentPage === 1);
            if (startPage > 2) {
                const ell = document.createElement('span');
                ell.className = 'agap-page-ellipsis';
                ell.textContent = '…';
                paanyayaControls.appendChild(ell);
            }
        }
        for (let i = startPage; i <= endPage; i++) {
            addPageBtn(String(i), i, false, i === paanyayaCurrentPage);
        }
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) {
                const ell = document.createElement('span');
                ell.className = 'agap-page-ellipsis';
                ell.textContent = '…';
                paanyayaControls.appendChild(ell);
            }
            addPageBtn(String(totalPages), totalPages, false, paanyayaCurrentPage === totalPages);
        }
        addPageBtn('›', paanyayaCurrentPage + 1, paanyayaCurrentPage >= totalPages);
    }

    const getDropdownRowFor = (tr) => {
        const next = tr ? tr.nextElementSibling : null;
        return (next && next.classList.contains('paanyaya-dropdown-row')) ? next : null;
    };

    const setPaanyayaRowExpanded = (tr, expand) => {
        if (!tr) return;
        const dropdownRow = getDropdownRowFor(tr);
        const expandBtn = tr.querySelector('.paanyaya-expand-btn');
        tr.classList.toggle('is-expanded', expand);
        if (expandBtn) expandBtn.setAttribute('aria-expanded', expand ? 'true' : 'false');
        if (dropdownRow) {
            const isParentVisible = tr.style.display !== 'none';
            dropdownRow.hidden = !(expand && isParentVisible);
        }
    };

    paanyayaRows.forEach(tr => {
        const expandBtn = tr.querySelector('.paanyaya-expand-btn');
        expandBtn?.addEventListener('click', (e) => {
            e.stopPropagation();
            setPaanyayaRowExpanded(tr, !tr.classList.contains('is-expanded'));
        });
        tr.addEventListener('click', (e) => {
            if (e.target.closest('a, button, input, select, textarea, label')) return;
            setPaanyayaRowExpanded(tr, !tr.classList.contains('is-expanded'));
        });
    });

    function renderPaanyayaPage() {
        if (!paanyayaRows.length) return;
        const q = (paanyayaSearchInput?.value || '').trim().toLowerCase();
        if (paanyayaClearBtn) {
            paanyayaClearBtn.style.display = q ? 'block' : 'none';
        }
        const matched = q
            ? paanyayaRows.filter(tr => (tr.dataset.search || '').includes(q))
            : paanyayaRows;

        const total = matched.length;
        const totalPages = Math.max(1, Math.ceil(total / paanyayaPageSize));
        if (paanyayaCurrentPage > totalPages) paanyayaCurrentPage = totalPages;
        if (paanyayaCurrentPage < 1) paanyayaCurrentPage = 1;

        const startIdx = (paanyayaCurrentPage - 1) * paanyayaPageSize;
        const endIdx = Math.min(startIdx + paanyayaPageSize, total);
        const visibleSet = new Set(matched.slice(startIdx, endIdx));

        paanyayaRows.forEach(tr => {
            const visible = visibleSet.has(tr);
            tr.style.display = visible ? '' : 'none';
            const dropdownRow = getDropdownRowFor(tr);
            if (dropdownRow) {
                dropdownRow.hidden = !(visible && tr.classList.contains('is-expanded'));
            }
        });
        syncPaanyayaEmptyRow(total === 0);
        renderPaanyayaPagination(total, totalPages, startIdx, endIdx);
    }

    paanyayaSearchInput?.addEventListener('input', () => {
        paanyayaCurrentPage = 1;
        renderPaanyayaPage();
    });

    paanyayaClearBtn?.addEventListener('click', () => {
        if (paanyayaSearchInput) paanyayaSearchInput.value = '';
        paanyayaCurrentPage = 1;
        renderPaanyayaPage();
    });

    paanyayaPageSizeEl?.addEventListener('change', () => {
        paanyayaPageSize = Number(paanyayaPageSizeEl.value || 10) || 10;
        paanyayaCurrentPage = 1;
        renderPaanyayaPage();
    });

    if (paanyayaRows.length) {
        renderPaanyayaPage();
    }
});
</script>

<?php include '../../layouts/footer.php'; ?>
