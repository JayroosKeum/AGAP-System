<?php

session_start();

require_once __DIR__ . '/../../../backend/config/database.php';
require_once __DIR__ . '/../../../backend/middleware/rbac.php';
$rbacDb = (new Database())->connect();
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!agapHasRole(['Administrator', 'Lupon Clerk', 'Lupon Member', 'LISO', 'BPSO', 'Secretary', 'Barangay Secretary'], $rbacDb)) {
    header('Location: ../auth/login.php');
    exit;
}

$canEditPaanyaya = agapHasRole(['Administrator', 'Lupon Clerk', 'Secretary', 'Barangay Secretary'], $rbacDb);

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
        SELECT resident_id, first_name, middle_name, last_name, suffix, purok, is_tenant, address, contact_no, email, home_latitude, home_longitude
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
    'Doña Petra', 'Sitio', 'Non-Resident'
];

// Count summary across grouped cases
$allList = $paanyayaModel->getGroupedPaanyayaCases();
$countTotal = count($allList);
$countScheduled = 0;
$countCompleted = 0;
$countCancelled = 0;
foreach ($allList as $item) {
    $st = (string) ($item['display_status'] ?? '1st Paanyaya');
    if (in_array($st, ['1st Paanyaya', '2nd Paanyaya', 'Scheduled', 'Pending'], true)) $countScheduled++;
    elseif (str_starts_with($st, 'Settled') || in_array($st, ['Resolved', 'Settled'], true)) $countCompleted++;
    elseif (in_array($st, ['Escalated', 'Dismissed', 'Cancelled', 'Failed', 'DISMISSED_BARRED', 'RESPONDENT_DEFAULT', 'Repudiated', 'Archived'], true)) $countCancelled++;
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
    min-width: 760px;
    border-collapse: collapse;
    font-size: 0.86rem;
    text-align: left;
    border: none;
}
.agap-list-shell .paanyaya-table th,
.agap-list-shell .paanyaya-table td {
    border-top: none;
    border-left: none;
    border-right: none;
}
.agap-list-shell .paanyaya-table thead th {
    background: #f8fafc;
    padding: 11px 16px;
    font-size: 0.74rem;
    font-weight: 700;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    border-bottom: 1px solid #e2e8f0;
    white-space: nowrap;
}
.agap-list-shell .paanyaya-table tbody td {
    padding: 13px 16px;
    border-bottom: 1px solid #f1f5f9;
    color: #1e293b;
    vertical-align: middle;
}
.agap-list-shell .paanyaya-table tbody tr:last-child td {
    border-bottom: none;
}
.agap-list-shell .paanyaya-table tbody tr[data-paanyaya-row="1"]:hover {
    background: #f8fafc;
}
.agap-list-shell .paanyaya-table tbody tr.paanyaya-dropdown-row:hover {
    background: #f8fafc;
}
.agap-list-shell .paanyaya-table .sortable-th.sort-active-asc,
.agap-list-shell .paanyaya-table .sortable-th.sort-active-desc {
    color: #1b6553;
}
.agap-list-shell .paanyaya-table .sortable-th.sort-active-asc .sort-indicator,
.agap-list-shell .paanyaya-table .sortable-th.sort-active-desc .sort-indicator {
    color: #1b6553;
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

/* Multi-Step Schedule & Send Paanyaya Wizard Modal */
#schedulePaanyayaModal .modal-card.wiz-modal-card {
    width: min(1060px, calc(100vw - 32px));
    max-width: 1060px;
    height: min(760px, 90vh);
    max-height: 90vh;
    border-radius: 16px;
    background: #f8fafc;
}
#schedulePaanyayaModal .wiz-top-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 26px 14px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
#schedulePaanyayaModal .wiz-top-left {
    display: flex;
    align-items: center;
    gap: 14px;
    min-width: 0;
}
#schedulePaanyayaModal .wiz-top-back-btn {
    width: 36px;
    height: 36px;
    border-radius: 9999px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #334155;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
    transition: all 0.15s ease;
}
#schedulePaanyayaModal .wiz-top-back-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}
#schedulePaanyayaModal .wiz-top-title {
    margin: 0;
    font-size: 1.15rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.25;
}
#schedulePaanyayaModal .wiz-top-subtitle {
    margin: 2px 0 0;
    font-size: 0.82rem;
    color: #64748b;
}
#schedulePaanyayaModal .wiz-shell {
    display: grid;
    grid-template-columns: 265px minmax(0, 1fr);
    gap: 22px;
    padding: 20px 26px 24px;
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
    background: #f8fafc;
}
#schedulePaanyayaModal .wiz-sidebar {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    gap: 16px;
    min-height: 0;
    overflow-y: auto;
}
#schedulePaanyayaModal .wiz-progress-title {
    margin: 0 0 4px;
    font-size: 0.92rem;
    font-weight: 800;
    color: #0f172a;
}
#schedulePaanyayaModal .wiz-progress-meta {
    font-size: 0.78rem;
    color: #64748b;
    font-weight: 600;
    margin-bottom: 8px;
}
#schedulePaanyayaModal .wiz-progress-track {
    width: 100%;
    height: 4px;
    background: #e2e8f0;
    border-radius: 9999px;
    overflow: hidden;
    margin-bottom: 16px;
}
#schedulePaanyayaModal .wiz-progress-fill {
    height: 100%;
    width: 25%;
    background: #1b6553;
    border-radius: 9999px;
    transition: width 0.22s ease;
}
#schedulePaanyayaModal .wiz-step-list {
    display: flex;
    flex-direction: column;
    gap: 6px;
}
#schedulePaanyayaModal .wiz-step-item {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 11px;
    padding: 10px 12px;
    border-radius: 10px;
    border: 1px solid transparent;
    background: transparent;
    text-align: left;
    cursor: pointer;
    font-family: inherit;
    transition: all 0.16s ease;
}
#schedulePaanyayaModal .wiz-step-item:hover:not(.is-active) {
    background: #f1f5f9;
}
#schedulePaanyayaModal .wiz-step-item.is-active {
    background: #eef6f2;
    border-color: #cde2d6;
}
#schedulePaanyayaModal .wiz-step-num {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.78rem;
    font-weight: 800;
    flex-shrink: 0;
    background: #e2e8f0;
    color: #64748b;
    transition: all 0.16s ease;
}
#schedulePaanyayaModal .wiz-step-item.is-active .wiz-step-num {
    background: #1b6553;
    color: #ffffff;
}
#schedulePaanyayaModal .wiz-step-item.is-completed .wiz-step-num {
    background: #dcfce7;
    color: #15803d;
    border: 1px solid #bbf7d0;
}
#schedulePaanyayaModal .wiz-step-text {
    min-width: 0;
}
#schedulePaanyayaModal .wiz-step-label {
    display: block;
    font-size: 0.84rem;
    font-weight: 700;
    color: #475569;
    line-height: 1.25;
}
#schedulePaanyayaModal .wiz-step-item.is-active .wiz-step-label {
    color: #1b6553;
}
#schedulePaanyayaModal .wiz-step-item.is-completed .wiz-step-label {
    color: #0f172a;
}
#schedulePaanyayaModal .wiz-step-sub {
    display: block;
    font-size: 0.72rem;
    color: #94a3b8;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
#schedulePaanyayaModal .wiz-step-item.is-active .wiz-step-sub {
    color: #1b6553;
}
#schedulePaanyayaModal .wiz-help-box {
    background: #eff6ff;
    border: 1px solid #dbeafe;
    border-radius: 12px;
    padding: 13px 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
#schedulePaanyayaModal .wiz-help-icon {
    width: 22px;
    height: 22px;
    border-radius: 9999px;
    border: 1.5px solid #2563eb;
    color: #2563eb;
    font-size: 0.74rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: 1px;
}
#schedulePaanyayaModal .wiz-help-title {
    font-size: 0.8rem;
    font-weight: 700;
    color: #1e40af;
    margin: 0 0 3px;
}
#schedulePaanyayaModal .wiz-help-desc {
    font-size: 0.73rem;
    color: #3b82f6;
    line-height: 1.4;
    margin: 0;
}
#schedulePaanyayaModal .wiz-main-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    box-shadow: 0 4px 18px rgba(15, 23, 42, 0.04);
    display: flex;
    flex-direction: column;
    min-height: 0;
    overflow: hidden;
}
#schedulePaanyayaModal .wiz-card-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 24px 28px;
}
#schedulePaanyayaModal .wiz-panel {
    display: none;
}
#schedulePaanyayaModal .wiz-panel.is-active {
    display: block;
}
#schedulePaanyayaModal .wiz-panel-head {
    margin-bottom: 18px;
    padding-bottom: 12px;
    border-bottom: 1px solid #f1f5f9;
}
#schedulePaanyayaModal .wiz-panel-head h4 {
    margin: 0 0 4px;
    font-size: 1.08rem;
    font-weight: 800;
    color: #0f172a;
}
#schedulePaanyayaModal .wiz-panel-head p {
    margin: 0;
    font-size: 0.81rem;
    color: #64748b;
}
#schedulePaanyayaModal .wiz-subhead {
    font-size: 0.8rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #475569;
    margin: 16px 0 10px;
}
#schedulePaanyayaModal .form-control {
    padding: 9px 12px;
    border: 1px solid #e2e8f0;
    border-radius: 9px;
    font-size: 0.86rem;
    color: #0f172a;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
#schedulePaanyayaModal .form-control:focus {
    border-color: #1b6553;
    box-shadow: 0 0 0 3px rgba(27, 101, 83, 0.12);
}
#schedulePaanyayaModal .wiz-map-box {
    margin-top: 14px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 14px;
}
#schedulePaanyayaModal .wiz-card-foot {
    padding: 14px 28px;
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-shrink: 0;
}
#schedulePaanyayaModal .wiz-btn-secondary {
    height: 38px;
    padding: 0 16px;
    border-radius: 9px;
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #334155;
    font-family: inherit;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.15s ease;
}
#schedulePaanyayaModal .wiz-btn-secondary:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #0f172a;
}
#schedulePaanyayaModal .wiz-btn-primary {
    height: 38px;
    padding: 0 18px;
    border-radius: 9px;
    border: 1px solid #1b6553;
    background: #1b6553;
    color: #ffffff;
    font-family: inherit;
    font-size: 0.84rem;
    font-weight: 700;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    transition: all 0.15s ease;
}
#schedulePaanyayaModal .wiz-btn-primary:hover {
    background: #144d3f;
    border-color: #144d3f;
}
#schedulePaanyayaModal .wiz-btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
#schedulePaanyayaModal .wiz-review-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
    margin-bottom: 14px;
}
#schedulePaanyayaModal .wiz-review-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
}
#schedulePaanyayaModal .wiz-review-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    margin-bottom: 8px;
}
#schedulePaanyayaModal .wiz-review-card-title {
    font-size: 0.78rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #475569;
}
#schedulePaanyayaModal .wiz-review-edit-btn {
    border: 1px solid #cbd5e1;
    background: #ffffff;
    color: #1b6553;
    font-size: 0.72rem;
    font-weight: 700;
    border-radius: 6px;
    padding: 2px 8px;
    cursor: pointer;
}
#schedulePaanyayaModal .wiz-review-edit-btn:hover {
    background: #eef6f2;
    border-color: #1b6553;
}
#schedulePaanyayaModal .wiz-review-val {
    font-size: 0.88rem;
    font-weight: 700;
    color: #0f172a;
    margin-bottom: 3px;
}
#schedulePaanyayaModal .wiz-review-sub {
    font-size: 0.79rem;
    color: #64748b;
    line-height: 1.45;
}
@media (max-width: 860px) {
    #schedulePaanyayaModal .modal-card.wiz-modal-card {
        height: 92vh;
    }
    #schedulePaanyayaModal .wiz-shell {
        grid-template-columns: 1fr;
        padding: 14px 16px;
        gap: 14px;
        overflow-y: auto;
    }
    #schedulePaanyayaModal .wiz-sidebar {
        overflow: visible;
    }
    #schedulePaanyayaModal .wiz-step-list {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }
    #schedulePaanyayaModal .wiz-help-box {
        display: none;
    }
    #schedulePaanyayaModal .wiz-review-grid {
        grid-template-columns: 1fr;
    }
    #schedulePaanyayaModal .wiz-card-body {
        padding: 18px;
    }
    #schedulePaanyayaModal .wiz-card-foot {
        padding: 12px 18px;
    }
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
.agap-btn-act.is-icon-btn {
    padding: 6px 8px;
}
.agap-btn-act.is-icon-btn svg {
    width: 15px;
    height: 15px;
}

/* Redesigned Paanyaya Dialogue Record Modal (Clean SaaS Form Card) */
#paanyayaAttendanceModal .att-modal-card {
    max-width: 650px;
    border-radius: 16px;
    border: 1px solid rgba(15, 23, 42, 0.08);
    box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.24);
}
#paanyayaAttendanceModal .att-modal-head {
    padding: 16px 24px;
    background: #ffffff;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
#paanyayaAttendanceModal .att-modal-title-wrap {
    display: flex;
    align-items: center;
    gap: 10px;
}
#paanyayaAttendanceModal .att-modal-icon {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
#paanyayaAttendanceModal .att-modal-head h3 {
    font-size: 1rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
}
#paanyayaAttendanceModal .att-modal-body {
    padding: 18px 24px 20px;
    background: #ffffff;
}
.att-overline-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.att-overline-label {
    font-size: 0.69rem;
    font-weight: 700;
    letter-spacing: 0.07em;
    text-transform: uppercase;
    color: #94a3b8;
}
.att-meta-pills {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    flex-wrap: wrap;
}
.att-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px 16px;
    margin-bottom: 14px;
}
@media (max-width: 640px) {
    .att-form-grid {
        grid-template-columns: 1fr;
    }
}
.att-field-group {
    display: flex;
    flex-direction: column;
    gap: 6px;
    min-width: 0;
}
.att-field-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: #334155;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 6px;
}
.att-field-hint {
    font-size: 0.72rem;
    font-weight: 600;
    color: #16a34a;
}
.att-input-shell {
    min-height: 42px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
    padding: 5px 8px 5px 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    box-sizing: border-box;
}
.att-input-shell.is-readonly {
    background: #f8fafc;
    padding: 5px 12px;
    justify-content: flex-start;
    color: #334155;
    font-size: 0.84rem;
    font-weight: 500;
}
.att-party-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    min-width: 0;
    flex: 1 1 auto;
}
.att-party-avatar {
    width: 24px;
    height: 24px;
    border-radius: 9999px;
    background: #eef6f2;
    color: #1b6553;
    font-size: 0.68rem;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.att-party-avatar.is-respondent {
    background: #eff6ff;
    color: #1d4ed8;
}
.att-party-name {
    font-size: 0.84rem;
    font-weight: 600;
    color: #0f172a;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.att-seg-group {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    padding: 2px;
    border-radius: 7px;
    gap: 2px;
    flex-shrink: 0;
}
.att-seg-pill {
    position: relative;
    margin: 0;
    cursor: pointer;
}
.att-seg-pill input[type="radio"] {
    position: absolute;
    opacity: 0;
    width: 0;
    height: 0;
}
.att-seg-text {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 4px 8px;
    border-radius: 5px;
    font-size: 0.72rem;
    font-weight: 700;
    color: #64748b;
    transition: all 0.14s ease;
    user-select: none;
    line-height: 1.2;
}
.att-seg-pill input[value="Present"]:checked + .att-seg-text {
    background: #ffffff;
    color: #15803d;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}
.att-seg-pill input[value="Absent"]:checked + .att-seg-text {
    background: #ffffff;
    color: #b91c1c;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08);
}
.att-clean-select,
.att-clean-textarea {
    width: 100%;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #ffffff;
    color: #0f172a;
    font-family: inherit;
    font-size: 0.86rem;
    box-sizing: border-box;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.att-clean-select {
    height: 42px;
    padding: 0 12px;
    font-weight: 500;
    cursor: pointer;
}
.att-clean-textarea {
    padding: 10px 12px;
    resize: vertical;
    line-height: 1.45;
}
.att-clean-select:focus,
.att-clean-textarea:focus {
    outline: none;
    border-color: #1b6553;
    box-shadow: 0 0 0 3px rgba(27, 101, 83, 0.12);
}
.att-outcome-section {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 14px 16px;
}
.att-outcome-rule-badge {
    font-size: 0.71rem;
    font-weight: 700;
    color: #1b6553;
    background: #eef6f2;
    border: 1px solid #cde2d6;
    padding: 2px 9px;
    border-radius: 9999px;
}
.att-p2-drawer {
    margin-top: 12px;
    padding: 12px 14px;
    background: #ffffff;
    border: 1px dashed #cbd5e1;
    border-radius: 10px;
}
#paanyayaAttendanceModal .att-modal-foot {
    padding: 14px 24px;
    background: #ffffff;
    border-top: 1px solid #f1f5f9;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.att-foot-right {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.att-btn-ghost {
    padding: 8px 16px;
    border-radius: 9px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #334155;
    font-family: inherit;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    transition: background-color 0.15s ease, border-color 0.15s ease;
}
.att-btn-ghost:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
}
.att-btn-primary {
    padding: 8px 18px;
    border-radius: 9px;
    border: 1px solid #1b6553;
    background: #1b6553;
    color: #ffffff;
    font-family: inherit;
    font-size: 0.84rem;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 2px 6px rgba(27, 101, 83, 0.22);
    transition: background-color 0.15s ease, border-color 0.15s ease, transform 0.12s ease;
}
.att-btn-primary:hover {
    background: #102b2d;
    border-color: #102b2d;
}
.att-btn-primary.is-danger {
    background: #dc2626;
    border-color: #dc2626;
    box-shadow: 0 2px 6px rgba(220, 38, 38, 0.22);
}
.att-btn-primary.is-danger:hover {
    background: #b91c1c;
    border-color: #b91c1c;
}

/* Tabbed Paanyaya View Modal (Dialogue Details + KP Form) */
.preview-tabs-bar {
    display: flex;
    align-items: center;
    gap: 22px;
    padding: 0 24px;
    background: #ffffff;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.preview-tab-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 12px 2px;
    border: none;
    background: transparent;
    color: #64748b;
    font-family: inherit;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: color 0.15s ease;
}
.preview-tab-btn:hover {
    color: #0f172a;
}
.preview-tab-btn.is-active {
    color: #1b6553;
}
.preview-tab-btn.is-active::after {
    content: "";
    position: absolute;
    left: 0;
    right: 0;
    bottom: -1px;
    height: 2px;
    border-radius: 9999px;
    background: #1b6553;
}
.preview-tab-pill {
    font-size: 0.69rem;
    font-weight: 700;
    padding: 1px 7px;
    border-radius: 9999px;
    background: #eef6f2;
    color: #1b6553;
    border: 1px solid #cde2d6;
}
#paanyayaPreviewModal .modal-card-large {
    width: min(920px, calc(100vw - 32px));
    max-width: 920px;
    height: min(760px, 88vh);
    max-height: 88vh;
}
#paanyayaPreviewModal .modal-head {
    min-height: 68px;
    box-sizing: border-box;
}
#paanyayaPreviewModal .modal-body {
    flex: 1 1 auto;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}
.prev-dialogue-wrap {
    padding: 20px 24px 22px;
    background: #ffffff;
    flex: 1 1 auto;
    min-height: 0;
    width: 100%;
    height: 100%;
    overflow-y: auto;
    box-sizing: border-box;
    display: flex;
    flex-direction: column;
}
.prev-kp-wrap {
    flex: 1 1 auto;
    min-height: 0;
    width: 100%;
    height: 100%;
    display: flex;
    flex-direction: column;
    background: #525659;
    overflow: hidden;
    box-sizing: border-box;
}
.prev-notes-group {
    flex: 1 1 auto;
    min-height: 140px;
}
.prev-att-pill {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 9999px;
    font-size: 0.72rem;
    font-weight: 700;
    flex-shrink: 0;
}
.prev-att-pill.is-present {
    background: #dcfce7;
    color: #15803d;
}
.prev-att-pill.is-absent {
    background: #fee2e2;
    color: #b91c1c;
}
.prev-att-pill.is-pending {
    background: #f1f5f9;
    color: #64748b;
}
.prev-notes-box {
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    padding: 12px 14px;
    font-size: 0.85rem;
    line-height: 1.55;
    color: #1e293b;
    white-space: pre-wrap;
    flex: 1 1 auto;
    min-height: 120px;
    box-sizing: border-box;
}

/* Case Grouping & Vertical Stepper Dropdown (modeled after step progression) */
tr[data-paanyaya-row="1"] {
    cursor: pointer;
    transition: background-color 0.14s ease;
}
tr[data-paanyaya-row="1"].is-expanded {
    background: #eef6f2 !important;
}
tr[data-paanyaya-row="1"].is-expanded td {
    border-bottom-color: #cde2d6 !important;
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
    border-radius: 7px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: all 0.2s ease;
    padding: 0;
}
.paanyaya-expand-btn:hover {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #0f172a;
}
.paanyaya-expand-btn[aria-expanded="true"] {
    background: #eef6f2;
    border-color: #cde2d6;
    color: #1b6553;
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
    border-left: none;
    border-right: none;
    border-bottom: 1px solid #e2e8f0;
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
                            <span>Settled</span>
                            <span class="agap-tab-count"><?php echo $countCompleted; ?></span>
                        </a>
                        <a href="index.php?status=Cancelled<?php echo $searchFilter !== '' ? '&search=' . urlencode($searchFilter) : ''; ?>"
                           class="agap-tab-item <?php echo in_array($statusFilter, ['Cancelled', 'Escalated', 'Dismissed', 'Failed'], true) ? 'active' : ''; ?>"
                           data-status="Cancelled"
                           id="paanyayaCardCancelled">
                            <span>Escalated / Dismissed</span>
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

                <!-- Unified Expandable Case Table Workspace -->
                <div class="agap-list-table-wrap">
                    <div class="table-responsive" style="border: none; background: transparent;">
                        <table class="data-table proof-table paanyaya-table">
                            <thead>
                                <tr>
                                    <th style="width: 44px; text-align: center;" aria-label="Expand toggle"></th>
                                    <th class="sortable-th" data-sort-key="ref" title="Click to sort by Record / KP Number">
                                        <span class="th-content">KP Number <span class="sort-indicator" id="sortInd_paanyaya_ref">⇅</span></span>
                                    </th>
                                    <th class="sortable-th" data-sort-key="complainant" title="Click to sort by Complainant">
                                        <span class="th-content">Complainant <span class="sort-indicator" id="sortInd_paanyaya_complainant">⇅</span></span>
                                    </th>
                                    <th class="sortable-th" data-sort-key="respondent" title="Click to sort by Respondent">
                                        <span class="th-content">Respondent <span class="sort-indicator" id="sortInd_paanyaya_respondent">⇅</span></span>
                                    </th>
                                    <th class="sortable-th sort-active-desc" data-sort-key="date" title="Click to sort by Schedule Date">
                                        <span class="th-content">Schedule Date <span class="sort-indicator" id="sortInd_paanyaya_date">▼</span></span>
                                    </th>
                                    <th class="sortable-th" data-sort-key="status" title="Click to sort by Status">
                                        <span class="th-content">Status <span class="sort-indicator" id="sortInd_paanyaya_status">⇅</span></span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody id="paanyayaTbody">
                                <?php if (empty($paanyayas)): ?>
                                    <tr id="paanyayaServerEmptyRow">
                                        <td colspan="6" style="text-align: center; color: #64748b; padding: 40px 20px;">
                                            <div style="font-size: 0.95rem; font-weight: 700; color: #0f172a;">No Paanyaya invitations found</div>
                                            <p style="font-size: 0.84rem; margin: 4px 0 0; color: #64748b;">Click "Schedule New Paanyaya" above to issue an informal invitation.</p>
                                        </td>
                                    </tr>
                            <?php else: ?>
                                <?php
                                    $resolveBadgeVariant = static function (string $status): string {
                                        if (str_starts_with($status, 'Settled') || in_array($status, ['Resolved', 'Settled', 'CFA Issued', 'RESPONDENT_DEFAULT'], true)) {
                                            return 'is-green';
                                        }
                                        if (in_array($status, ['Escalated', 'DISMISSED_BARRED', 'Repudiated'], true)) {
                                            return 'is-purple';
                                        }
                                        if (in_array($status, ['Dismissed', 'Cancelled', 'Failed'], true)) {
                                            return 'is-red';
                                        }
                                        if ($status === '2nd Paanyaya') {
                                            return 'is-amber';
                                        }
                                        if (in_array($status, ['2nd Paanyaya Issued', 'Recorded'], true)) {
                                            return 'is-slate';
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
                                        $rawRowStatus = trim((string)($row['status'] ?? $actionStatus));
                                        $normRowStatus = strtolower($rawRowStatus);
                                        $hasRecorded = !empty($row['attendance_recorded_at'])
                                            || in_array($normRowStatus, ['completed', 'done', 'resolved', 'settled', 'dismissed', 'escalated'], true);
                                        $allowDialogueRecord = ($pid > 0
                                            && !in_array($normRowStatus, ['resolved', 'settled', 'completed', 'done', 'dismissed', 'escalated', 'cancelled'], true));
                                        $attemptTitleLabel = $attemptNum >= 2 ? '2nd Paanyaya' : '1st Paanyaya';
                                        $rowLinkedCaseId = (int)($row['linked_case_id'] ?? 0);
                                        $rowCompNumber = (string)($row['complaint_number'] ?? '');
                                        ob_start();
                                        ?>
                                        <?php if ($cid > 0 && $canEditPaanyaya): ?>
                                            <a href="stage.php?complaint_id=<?php echo $cid; ?>"
                                               class="agap-btn-act is-primary-soft is-icon-btn"
                                               title="Edit Paanyaya &amp; Schedule Details (Lupon Clerk / Secretary / Admin)"
                                               aria-label="Edit Paanyaya and Schedule Details"
                                               style="text-decoration: none;">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="agap-btn-act is-primary-soft btn-preview-modal"
                                                data-paanyaya-id="<?php echo $pid; ?>"
                                                data-complaint-id="<?php echo $cid; ?>"
                                                data-attempt="<?php echo $attemptNum; ?>"
                                                data-name="<?php echo htmlspecialchars($invLabel, ENT_QUOTES); ?>"
                                                data-recorded="<?php echo $hasRecorded ? '1' : '0'; ?>"
                                                data-status="<?php echo htmlspecialchars($rawRowStatus, ENT_QUOTES); ?>"
                                                title="<?php echo $hasRecorded ? 'View Dialogue Details &amp; KP Form' : 'View KP Form (Paanyaya Letter)'; ?>">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            <span>View</span>
                                        </button>
                                        <?php if ($allowDialogueRecord): ?>
                                            <button type="button"
                                                    class="agap-btn-act is-primary-soft is-icon-btn btn-open-attendance"
                                                    data-paanyaya-id="<?php echo $pid; ?>"
                                                    data-complaint-id="<?php echo $cid; ?>"
                                                    data-linked-case-id="<?php echo $rowLinkedCaseId; ?>"
                                                    data-invitee="<?php echo htmlspecialchars($invLabel, ENT_QUOTES); ?>"
                                                    data-complainant="<?php echo htmlspecialchars($compLabel, ENT_QUOTES); ?>"
                                                    data-date="<?php echo htmlspecialchars($dateLabel, ENT_QUOTES); ?>"
                                                    data-time="<?php echo htmlspecialchars($timeLabel, ENT_QUOTES); ?>"
                                                    data-status="<?php echo htmlspecialchars($rawRowStatus, ENT_QUOTES); ?>"
                                                    data-attempt="<?php echo $attemptNum; ?>"
                                                    data-complaint-number="<?php echo htmlspecialchars($rowCompNumber, ENT_QUOTES); ?>"
                                                    title="Record <?php echo htmlspecialchars($attemptTitleLabel, ENT_QUOTES); ?> Dialogue, Notes &amp; Outcome"
                                                    aria-label="Record <?php echo htmlspecialchars($attemptTitleLabel, ENT_QUOTES); ?> Dialogue, Notes and Outcome">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
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
                                        $latestAttempt = min(2, max(1, (int)($p['step_index'] ?? ($p['attempt_number'] ?? 1))));
                                        $latestStepLabel = (string)($p['step_label'] ?? ($latestAttempt >= 2 ? '2nd Paanyaya' : '1st Paanyaya'));
                                        $recordRefLabel = !empty($p['complaint_number'])
                                            ? (string)$p['complaint_number']
                                            : ('PY-' . str_pad((string)(int)($p['paanyaya_id'] ?? 0), 4, '0', STR_PAD_LEFT));
                                        $recordSubLabel = trim((string)($p['complaint_title'] ?? '')) !== ''
                                            ? (string)$p['complaint_title']
                                            : ($latestAttempt >= 2 ? '2nd Invitation' : '1st Invitation');
                                        $schedDateTimeText = trim($d . ($t !== '' ? ', ' . $t : ''));
                                        $schedSubLabel = trim((string)($p['venue'] ?? '')) !== ''
                                            ? (string)$p['venue']
                                            : 'Informal Dialogue';
                                        $sortDateVal = trim((string)($p['scheduled_date'] ?? '0000-00-00')) . ' ' . trim((string)($p['scheduled_time'] ?? '00:00:00'));
                                    ?>
                                    <tr data-paanyaya-row="1"
                                        data-group-key="<?php echo htmlspecialchars($groupKey, ENT_QUOTES); ?>"
                                        data-search="<?php echo htmlspecialchars($searchBlob . ' ' . strtolower($recordRefLabel . ' ' . $recordSubLabel), ENT_QUOTES); ?>"
                                        data-sort-ref="<?php echo htmlspecialchars(strtolower($recordRefLabel), ENT_QUOTES); ?>"
                                        data-sort-complainant="<?php echo htmlspecialchars(strtolower($compName), ENT_QUOTES); ?>"
                                        data-sort-respondent="<?php echo htmlspecialchars(strtolower($invName), ENT_QUOTES); ?>"
                                        data-sort-date="<?php echo htmlspecialchars($sortDateVal, ENT_QUOTES); ?>"
                                        data-sort-status="<?php echo htmlspecialchars(strtolower($displaySt), ENT_QUOTES); ?>">
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
                                            <div class="agap-rec-id-wrap">
                                                <span class="agap-rec-icon" aria-hidden="true">
                                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                </span>
                                                <div>
                                                    <span class="agap-rec-id-text" style="color: #1b6553;"><?php echo htmlspecialchars($recordRefLabel); ?></span>
                                                    <span class="agap-person-sub" style="margin-top: 1px; max-width: 190px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($recordSubLabel, ENT_QUOTES); ?>"><?php echo htmlspecialchars($recordSubLabel); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="agap-person-cell">
                                                <span class="agap-person-avatar" aria-hidden="true"><?php echo htmlspecialchars($compInitials); ?></span>
                                                <div class="agap-person-meta">
                                                    <span class="agap-person-name"><?php echo htmlspecialchars($compName); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="agap-person-cell">
                                                <span class="agap-person-avatar is-respondent" aria-hidden="true"><?php echo htmlspecialchars($invInitials); ?></span>
                                                <div class="agap-person-meta">
                                                    <span class="agap-person-name"><?php echo htmlspecialchars($invName); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="display:flex; flex-direction:column; align-items:flex-start; gap:3px;">
                                                <span style="font-weight: 700; color: #0f172a; font-size: 0.85rem; white-space: nowrap;"><?php echo htmlspecialchars($schedDateTimeText); ?></span>
                                                <span class="agap-pill-badge is-blue" style="font-size: 0.7rem; padding: 2px 8px; max-width: 210px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo htmlspecialchars($schedSubLabel, ENT_QUOTES); ?>"><?php echo htmlspecialchars($latestStepLabel); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="agap-pill-badge <?php echo $badgeVariant; ?>">
                                                <?php echo htmlspecialchars($displaySt); ?>
                                            </span>
                                        </td>
                                    </tr>
                                    <tr class="paanyaya-dropdown-row"
                                        id="<?php echo htmlspecialchars($dropdownDomId, ENT_QUOTES); ?>"
                                        data-paanyaya-dropdown-for="<?php echo htmlspecialchars($groupKey, ENT_QUOTES); ?>"
                                        hidden>
                                        <td colspan="6" class="paanyaya-dropdown-cell">
                                            <div class="paanyaya-stepper-card">
                                                <div class="paanyaya-stepper-header">
                                                    <div class="paanyaya-stepper-title">
                                                        <span>Paanyaya Progression</span>
                                                        <?php if (!empty($p['complaint_number'])): ?>
                                                            <span class="paanyaya-case-chip">Case #<?php echo htmlspecialchars($p['complaint_number']); ?></span>
                                                        <?php endif; ?>
                                                        <?php if ($hasSecond): ?>
                                                            <span class="paanyaya-series-pill">2 Connected Invitations</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                                                        <span style="font-size: 0.76rem; color: #64748b; font-weight: 600;">
                                                            <?php echo htmlspecialchars($workflowStage); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="paanyaya-vstepper">
                                                    <?php
                                                        $showUpcomingSecond = (
                                                            count($items) === 1
                                                            && !str_starts_with($displaySt, 'Settled')
                                                            && !in_array($displaySt, ['Resolved', 'Settled', 'Cancelled', 'Escalated', 'Dismissed', 'Archived', 'CFA Issued'], true)
                                                        );
                                                    ?>
                                                    <?php foreach (array_slice($items, 0, 2) as $item): ?>
                                                        <?php
                                                            $stepNum = min(2, max(1, (int)($item['step_index'] ?? ($item['attempt_number'] ?? 1))));
                                                            $stepLabel = (string)($item['step_label'] ?? ($stepNum >= 2 ? '2nd Paanyaya' : '1st Paanyaya'));
                                                            $isStepCurrent = !empty($item['is_latest']);
                                                            $stepClass = $isStepCurrent ? 'is-current' : 'is-done';

                                                            $itemDate = !empty($item['scheduled_date']) ? date('M j, Y', strtotime($item['scheduled_date'])) : '—';
                                                            $itemTime = !empty($item['scheduled_time']) ? date('g:i A', strtotime($item['scheduled_time'])) : '';
                                                            $itemRawStatus = (string)($item['status'] ?? 'Scheduled');
                                                            $itemDispStatus = (string)($item['step_display_status'] ?? ($isStepCurrent ? $itemRawStatus : '2nd Paanyaya Issued'));
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
                                                                                <?php echo htmlspecialchars($itemDispStatus); ?>
                                                                            </span>
                                                                        </div>
                                                                        <div class="paanyaya-vstep-sub">
                                                                            <span>Scheduled: <strong><?php echo htmlspecialchars($itemDate); ?><?php echo $itemTime !== '' ? ' · ' . htmlspecialchars($itemTime) : ''; ?></strong></span>
                                                                            <?php if (!empty($item['complainant_attended']) || !empty($item['invitee_attended'])): ?>
                                                                                <span>Attendance: <?php echo htmlspecialchars((string)($item['complainant_attended'] ?? 'Pending')); ?> / <?php echo htmlspecialchars((string)($item['invitee_attended'] ?? 'Pending')); ?></span>
                                                                            <?php endif; ?>
                                                                            <?php
                                                                                $stepNotes = trim((string)(($item['dialogue_notes'] ?? '') !== '' ? $item['dialogue_notes'] : ($item['outcome_notes'] ?? ($item['salaysay'] ?? ''))));
                                                                                if ($stepNotes !== ''):
                                                                            ?>
                                                                                <span>Notes: <?php echo htmlspecialchars(mb_strimwidth($stepNotes, 0, 90, '...', 'UTF-8')); ?></span>
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
                                                                            <span>Not yet scheduled.</span>
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
    <div class="modal-card wiz-modal-card">
        <div class="wiz-top-header">
            <div class="wiz-top-left">
                <button type="button" class="wiz-top-back-btn" id="wizTopBackBtn" aria-label="Close modal">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                </button>
                <div>
                    <h3 class="wiz-top-title">Schedule &amp; Send Paanyaya</h3>
                    <p class="wiz-top-subtitle">Issue a Stage 0 informal dialogue invitation in Barangay Tumana.</p>
                </div>
            </div>
            <button type="button" class="modal-close-btn" id="modalCloseBtn" aria-label="Close">&times;</button>
        </div>

        <form id="schedulePaanyayaForm" novalidate>
            <div class="wiz-shell">
                <!-- Left Step Progress Sidebar -->
                <aside class="wiz-sidebar" aria-label="Paanyaya registration progress">
                    <div>
                        <h4 class="wiz-progress-title">Paanyaya Progress</h4>
                        <div class="wiz-progress-meta" id="wizStepCountLabel">Step 1 of 4</div>
                        <div class="wiz-progress-track">
                            <div class="wiz-progress-fill" id="wizProgressFill"></div>
                        </div>

                        <div class="wiz-step-list" role="tablist">
                            <button type="button" class="wiz-step-item is-active" data-wiz-step="1">
                                <span class="wiz-step-num" data-wiz-num="1">1</span>
                                <span class="wiz-step-text">
                                    <span class="wiz-step-label">Complainant</span>
                                    <span class="wiz-step-sub">Nagpaanyaya &amp; location</span>
                                </span>
                            </button>
                            <button type="button" class="wiz-step-item" data-wiz-step="2">
                                <span class="wiz-step-num" data-wiz-num="2">2</span>
                                <span class="wiz-step-text">
                                    <span class="wiz-step-label">Respondent</span>
                                    <span class="wiz-step-sub">Inanyayahan delivery info</span>
                                </span>
                            </button>
                            <button type="button" class="wiz-step-item" data-wiz-step="3">
                                <span class="wiz-step-num" data-wiz-num="3">3</span>
                                <span class="wiz-step-text">
                                    <span class="wiz-step-label">Schedule &amp; Salaysay</span>
                                    <span class="wiz-step-sub">Date, venue &amp; reason</span>
                                </span>
                            </button>
                            <button type="button" class="wiz-step-item" data-wiz-step="4">
                                <span class="wiz-step-num" data-wiz-num="4">4</span>
                                <span class="wiz-step-text">
                                    <span class="wiz-step-label">Review &amp; Submit</span>
                                    <span class="wiz-step-sub">Verify &amp; generate notice</span>
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="wiz-help-box">
                        <span class="wiz-help-icon" aria-hidden="true">i</span>
                        <div>
                            <div class="wiz-help-title">Stage 0 Paanyaya Guide</div>
                            <p class="wiz-help-desc">Quick-fill from the resident directory or pin on the Tumana map to auto-fill address and purok details.</p>
                        </div>
                    </div>
                </aside>

                <!-- Right Step Card -->
                <div class="wiz-main-card">
                    <div class="wiz-card-body">
                        <!-- STEP 1: COMPLAINANT (NAGPAANYAYA) -->
                        <div class="wiz-panel is-active" id="wizPanel1" data-wiz-panel="1">
                            <div class="wiz-panel-head">
                                <h4>Complainant Information (Nagpaanyaya)</h4>
                                <p>Provide the initial details of the complainant requesting this informal dialogue.</p>
                            </div>

                            <div class="form-group">
                                <label for="schedCompResidentSelect">Quick Fill from Resident Directory (Optional)</label>
                                <select id="schedCompResidentSelect" class="form-control">
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
                                                data-is-tenant="<?php echo (int)($rItem['is_tenant'] ?? 0); ?>"
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

                            <div class="form-row-3" style="margin-bottom: 14px;">
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

                            <div class="wiz-subhead">Location &amp; Contact Details</div>
                            <div class="form-row" style="margin-bottom: 14px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="schedComplainantIsTenant">Residency Status <span style="color:#ef4444;">*</span></label>
                                    <select id="schedComplainantIsTenant" name="complainant_is_tenant" class="form-control" required>
                                        <option value="0">Resident (Permanent — Tumana)</option>
                                        <option value="1">Resident (Tenant / Renter — Tumana)</option>
                                        <option value="2">Non-Resident (Outside Barangay Tumana)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="schedComplainantPurok">Purok / Zone</label>
                                    <select id="schedComplainantPurok" name="complainant_purok" class="form-control" required>
                                        <option value="">Select Purok / Zone...</option>
                                        <?php foreach ($purokOptions as $pOpt): ?>
                                            <option value="<?php echo htmlspecialchars($pOpt); ?>"><?php echo htmlspecialchars($pOpt === 'Non-Resident' ? 'Non-Resident (Outside Barangay Tumana)' : $pOpt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 14px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:6px;">
                                    <label for="schedComplainantAddress" style="margin-bottom:0;">House No. / Street Address</label>
                                    <button type="button" id="btnPinCompAddress" style="padding:2px 8px; font-size:0.72rem; font-weight:600; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; border-radius:6px; cursor:pointer;">📍 Pin on Map</button>
                                </div>
                                <input type="text" id="schedComplainantAddress" name="complainant_address" class="form-control" placeholder="e.g. Blk 4 Lot 12 Champagne St." required>
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
                            </div>

                            <div class="wiz-map-box">
                                <input type="hidden" id="compLocationLat" name="complainant_latitude" value="">
                                <input type="hidden" id="compLocationLng" name="complainant_longitude" value="">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                    <div style="font-size: 0.79rem; font-weight: 700; color: #1b6553; display: flex; align-items: center; gap: 5px;">
                                        📍 Pin Complainant Residence Location on Map
                                    </div>
                                    <div style="display: flex; gap: 6px;">
                                        <button type="button" id="compRecenterMapBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; border-radius: 6px; cursor: pointer;">Recenter Tumana</button>
                                        <button type="button" id="compClearPinBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; border-radius: 6px; cursor: pointer;">Clear Pin</button>
                                    </div>
                                </div>
                                <div id="compLocationMap" style="width: 100%; height: 195px; border-radius: 8px; border: 1px solid #cbd5e1; z-index: 1;"></div>
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 6px; font-size: 0.73rem;">
                                    <span id="compMapStatus" style="color: #64748b;">No pin placed yet. Click on map to auto-fill address.</span>
                                    <span id="compMapCoords" style="font-family: monospace; color: #475569; font-weight: 600;"></span>
                                </div>
                            </div>
                        </div>

                        <!-- STEP 2: RESPONDENT / INVITEE (INANYAYAHAN) -->
                        <div class="wiz-panel" id="wizPanel2" data-wiz-panel="2">
                            <div class="wiz-panel-head">
                                <h4>Person Being Invited (Inanyayahan)</h4>
                                <p>Enter accurate identity and delivery details for the person receiving this invitation.</p>
                            </div>

                            <div class="form-group">
                                <label for="schedInvResidentSelect">Quick Fill from Resident Directory (Optional)</label>
                                <select id="schedInvResidentSelect" class="form-control">
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
                                                data-is-tenant="<?php echo (int)($rItem['is_tenant'] ?? 0); ?>"
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

                            <div class="form-row-3" style="margin-bottom: 14px;">
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

                            <div class="wiz-subhead">Delivery Location &amp; Contact Details</div>
                            <div class="form-row" style="margin-bottom: 14px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="schedInviteeIsTenant">Residency Status <span style="color:#ef4444;">*</span></label>
                                    <select id="schedInviteeIsTenant" name="invitee_is_tenant" class="form-control" required>
                                        <option value="0">Resident (Permanent — Tumana)</option>
                                        <option value="1">Resident (Tenant / Renter — Tumana)</option>
                                        <option value="2">Non-Resident (Outside Barangay Tumana)</option>
                                    </select>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="schedInviteePurok">Purok / Zone</label>
                                    <select id="schedInviteePurok" name="invitee_purok" class="form-control" required>
                                        <option value="">Select Purok / Zone...</option>
                                        <?php foreach ($purokOptions as $pOpt): ?>
                                            <option value="<?php echo htmlspecialchars($pOpt); ?>"><?php echo htmlspecialchars($pOpt === 'Non-Resident' ? 'Non-Resident (Outside Barangay Tumana)' : $pOpt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group" style="margin-bottom: 14px;">
                                <div style="display:flex; justify-content:space-between; align-items:center; gap:6px; margin-bottom:6px;">
                                    <label for="schedInviteeAddress" style="margin-bottom:0;">House No. / Street Address (Delivery)</label>
                                    <button type="button" id="btnPinInvAddress" style="padding:2px 8px; font-size:0.72rem; font-weight:600; border:1px solid #bfdbfe; background:#eff6ff; color:#1d4ed8; border-radius:6px; cursor:pointer;">📍 Pin on Map</button>
                                </div>
                                <input type="text" id="schedInviteeAddress" name="invitee_address" class="form-control" placeholder="e.g. #45 Road 1 Ext., Doña Petra" required>
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
                            </div>

                            <div class="wiz-map-box">
                                <input type="hidden" id="invLocationLat" name="invitee_latitude" value="">
                                <input type="hidden" id="invLocationLng" name="invitee_longitude" value="">
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 6px;">
                                    <div style="font-size: 0.79rem; font-weight: 700; color: #1e40af; display: flex; align-items: center; gap: 5px;">
                                        📍 Pin Respondent / Invitee Residence Location on Map
                                    </div>
                                    <div style="display: flex; gap: 6px;">
                                        <button type="button" id="invRecenterMapBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #cbd5e1; background: #ffffff; color: #334155; border-radius: 6px; cursor: pointer;">Recenter Tumana</button>
                                        <button type="button" id="invClearPinBtn" style="padding: 3px 8px; font-size: 0.72rem; font-weight: 600; border: 1px solid #fecaca; background: #fef2f2; color: #b91c1c; border-radius: 6px; cursor: pointer;">Clear Pin</button>
                                    </div>
                                </div>
                                <div id="invLocationMap" style="width: 100%; height: 195px; border-radius: 8px; border: 1px solid #cbd5e1; z-index: 1;"></div>
                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-top: 6px; font-size: 0.73rem;">
                                    <span id="invMapStatus" style="color: #64748b;">No pin placed yet. Click on map to auto-fill address.</span>
                                    <span id="invMapCoords" style="font-family: monospace; color: #475569; font-weight: 600;"></span>
                                </div>
                            </div>

                            <!-- Legacy Hidden Map Fallback Inputs -->
                            <div style="display: none;">
                                <input type="hidden" id="paanyayaLocationLat" name="location_latitude" value="">
                                <input type="hidden" id="paanyayaLocationLng" name="location_longitude" value="">
                                <span id="paanyayaLocationCoords"></span>
                                <button type="button" id="paanyayaRecenterMapBtn"></button>
                                <button type="button" id="paanyayaClearPinBtn"></button>
                                <div id="paanyayaLocationMap"></div>
                            </div>
                        </div>

                        <!-- STEP 3: SCHEDULE, VENUE & SALAYSAY -->
                        <div class="wiz-panel" id="wizPanel3" data-wiz-panel="3">
                            <div class="wiz-panel-head">
                                <h4>Schedule, Venue &amp; Salaysay</h4>
                                <p>Set when and where the informal dialogue will take place and state the reason for invitation.</p>
                            </div>

                            <div class="form-row" style="margin-bottom: 14px;">
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="schedDate">Scheduled Date</label>
                                    <input type="date" id="schedDate" name="scheduled_date" class="form-control" min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d', strtotime('+3 weekdays')); ?>" required>
                                </div>
                                <div class="form-group" style="margin-bottom: 0;">
                                    <label for="schedTime">Scheduled Time</label>
                                    <input type="time" id="schedTime" name="scheduled_time" class="form-control" value="14:00" required>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="schedVenue">Venue / Tanggapan</label>
                                <input type="text" id="schedVenue" name="venue" class="form-control" value="Tanggapan ng Barangay Tumana" required>
                            </div>

                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="schedSalaysay">Salaysay (Statement / Reason for Invitation)</label>
                                <textarea id="schedSalaysay" name="salaysay" class="form-control" rows="7" placeholder="Ilarawan ang buod ng sumbong, dahilan ng pag-anyaya, o salaysay ng nagpaanyaya..." required></textarea>
                                <div style="font-size: 0.76rem; color: #64748b; margin-top: 6px;">
                                    Ito ay ilalagay sa opisyal na Paanyaya letter at awtomatikong ililipat sa Pormal na Mediation kapag inakyat ang kaso.
                                </div>
                            </div>
                        </div>

                        <!-- STEP 4: REVIEW & SUBMIT -->
                        <div class="wiz-panel" id="wizPanel4" data-wiz-panel="4">
                            <div class="wiz-panel-head">
                                <h4>Review &amp; Confirm Invitation</h4>
                                <p>Please review all details below before generating the official 1st Paanyaya letter.</p>
                            </div>

                            <div class="wiz-review-grid">
                                <div class="wiz-review-card">
                                    <div class="wiz-review-card-head">
                                        <span class="wiz-review-card-title">Complainant (Nagpaanyaya)</span>
                                        <button type="button" class="wiz-review-edit-btn" data-wiz-jump="1">Edit</button>
                                    </div>
                                    <div class="wiz-review-val" id="revCompName">—</div>
                                    <div class="wiz-review-sub" id="revCompDetails">—</div>
                                </div>

                                <div class="wiz-review-card">
                                    <div class="wiz-review-card-head">
                                        <span class="wiz-review-card-title">Respondent (Inanyayahan)</span>
                                        <button type="button" class="wiz-review-edit-btn" data-wiz-jump="2">Edit</button>
                                    </div>
                                    <div class="wiz-review-val" id="revInvName">—</div>
                                    <div class="wiz-review-sub" id="revInvDetails">—</div>
                                </div>
                            </div>

                            <div class="wiz-review-card" style="margin-bottom: 14px;">
                                <div class="wiz-review-card-head">
                                    <span class="wiz-review-card-title">Dialogue Schedule &amp; Venue</span>
                                    <button type="button" class="wiz-review-edit-btn" data-wiz-jump="3">Edit</button>
                                </div>
                                <div class="wiz-review-val" id="revSchedDateTime">—</div>
                                <div class="wiz-review-sub" id="revSchedVenue">—</div>
                            </div>

                            <div class="wiz-review-card">
                                <div class="wiz-review-card-head">
                                    <span class="wiz-review-card-title">Salaysay / Reason for Invitation</span>
                                    <button type="button" class="wiz-review-edit-btn" data-wiz-jump="3">Edit</button>
                                </div>
                                <div class="wiz-review-sub" id="revSalaysay" style="color: #1e293b; white-space: pre-wrap;">—</div>
                            </div>
                        </div>
                    </div>

                    <div class="wiz-card-foot">
                        <div>
                            <button type="button" class="wiz-btn-secondary" id="modalCancelBtn">Cancel</button>
                            <button type="button" class="wiz-btn-secondary" id="btnWizPrev" style="display: none;">&larr; Previous</button>
                        </div>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <button type="button" class="wiz-btn-primary" id="btnWizNext">
                                <span>Next Step</span>
                                <span aria-hidden="true">&rarr;</span>
                            </button>
                            <button type="submit" class="wiz-btn-primary" id="btnSubmitPaanyaya" style="display: none;">Save &amp; Generate Paanyaya</button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- =========================================================================
     MODAL 2: IN-PAGE LETTER PREVIEW MODAL (Replaces Page Redirection)
     ========================================================================= -->
<div class="modal-overlay" id="paanyayaPreviewModal">
    <div class="modal-card modal-card-large">
        <div class="modal-head" style="background: #ffffff; border-bottom: 1px solid #f1f5f9;">
            <div>
                <h3 id="previewModalTitle" style="margin: 0; font-size: 1rem; color: #0f172a;">Paanyaya Record View</h3>
                <div id="previewModalSubtitle" style="font-size: 0.8rem; color: #64748b; margin-top: 2px;">Official Barangay Tumana KP Record</div>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <div id="previewKpActions" style="display: inline-flex; align-items: center; gap: 8px;">
                    <button type="button" class="btn-action-print" id="btnPrintFromModal" title="Print KP Form">
                        Print
                    </button>
                    <a href="#" target="_blank" class="btn-action-view" id="btnDownloadFromModal" title="Download PDF">
                        Download PDF
                    </a>
                </div>
                <button type="button" class="modal-close-btn" id="previewModalCloseBtn" aria-label="Close modal">&times;</button>
            </div>
        </div>

        <!-- Tabs shown after dialogue has been recorded -->
        <div id="previewModalTabs" class="preview-tabs-bar" role="tablist" style="display: none;">
            <button type="button" class="preview-tab-btn is-active" id="btnPreviewTabDialogue" data-preview-tab="dialogue" role="tab" aria-selected="true">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Dialogue Details</span>
                <span class="preview-tab-pill" id="previewTabRecordedPill">Recorded</span>
            </button>
            <button type="button" class="preview-tab-btn" id="btnPreviewTabKpForm" data-preview-tab="kp-form" role="tab" aria-selected="false">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                <span>KP Form</span>
            </button>
        </div>

        <div class="modal-body" style="padding: 0; background: #ffffff;">
            <!-- Tab 1: Recorded Dialogue Details -->
            <div id="previewTabDialoguePanel" class="prev-dialogue-wrap" style="display: none;">
                <div id="previewDialogueLoading" style="padding: 36px 20px; text-align: center; color: #64748b; font-size: 0.88rem;">
                    Loading dialogue details...
                </div>
                <div id="previewDialogueContent" style="display: none; flex-direction: column; min-height: 100%;">
                    <div class="att-overline-row">
                        <span class="att-overline-label">Recorded Dialogue Summary</span>
                        <div class="att-meta-pills">
                            <span id="prevAttemptBadge" class="status-pill-scheduled" style="font-size: 0.72rem; padding: 2px 9px;">1st Paanyaya</span>
                            <span id="prevStatusBadge" class="status-pill-scheduled" style="font-size: 0.72rem; padding: 2px 9px;">Scheduled</span>
                            <span id="prevCaseChip" class="paanyaya-case-chip" style="display: none;">Case #—</span>
                        </div>
                    </div>

                    <div class="att-form-grid">
                        <div class="att-field-group">
                            <label class="att-field-label">Nagpaanyaya (Complainant)</label>
                            <div class="att-input-shell">
                                <div class="att-party-chip">
                                    <span class="att-party-avatar" id="prevCompInitials" aria-hidden="true">NP</span>
                                    <div style="min-width: 0; flex: 1;">
                                        <div class="att-party-name" id="prevCompName">Nagpaanyaya</div>
                                        <div id="prevCompSub" style="font-size: 0.72rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: none;"></div>
                                    </div>
                                </div>
                                <span id="prevCompAttPill" class="prev-att-pill is-pending">Pending</span>
                            </div>
                        </div>

                        <div class="att-field-group">
                            <label class="att-field-label">Inanyayahan (Respondent)</label>
                            <div class="att-input-shell">
                                <div class="att-party-chip">
                                    <span class="att-party-avatar is-respondent" id="prevInvInitials" aria-hidden="true">IN</span>
                                    <div style="min-width: 0; flex: 1;">
                                        <div class="att-party-name" id="prevInvName">Inanyayahan</div>
                                        <div id="prevInvSub" style="font-size: 0.72rem; color: #64748b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: none;"></div>
                                    </div>
                                </div>
                                <span id="prevInvAttPill" class="prev-att-pill is-pending">Pending</span>
                            </div>
                        </div>

                        <div class="att-field-group">
                            <label class="att-field-label">Schedule Date &amp; Venue</label>
                            <div class="att-input-shell is-readonly">
                                <span id="prevScheduleText" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">—</span>
                            </div>
                        </div>

                        <div class="att-field-group">
                            <label class="att-field-label">Dialogue Recorded</label>
                            <div class="att-input-shell is-readonly">
                                <span id="prevRecordedAt" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">—</span>
                            </div>
                        </div>
                    </div>

                    <div class="att-field-group prev-notes-group" style="margin-bottom: 14px;">
                        <label class="att-field-label"><span>Notes</span></label>
                        <div id="prevNotesText" class="prev-notes-box">No notes recorded.</div>
                    </div>

                    <div class="att-outcome-section" style="flex-shrink: 0;">
                        <div class="att-field-label" style="margin-bottom: 4px;">
                            <span>Outcome</span>
                            <span class="att-outcome-rule-badge" id="prevOutcomeBadge">Dialogue Recorded</span>
                        </div>
                        <div id="prevOutcomeTitle" style="font-size: 0.9rem; font-weight: 700; color: #0f172a;">—</div>
                        <div id="prevOutcomeSub" style="font-size: 0.79rem; color: #64748b; margin-top: 2px;"></div>
                        <div id="prevViewMediationWrap" style="display: none; margin-top: 10px;">
                            <a id="prevViewMediationLink" href="#" class="att-btn-primary" style="text-decoration: none;">
                                View Formal Case in Mediation &rarr;
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 2: KP Form (Paanyaya Letter Preview) -->
            <div id="previewTabKpFormPanel" class="prev-kp-wrap">
                <div id="previewLoadingNotice" style="padding: 40px 20px; text-align: center; color: #ffffff; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; flex: 1 1 auto;">
                    <div style="font-size: 1.8rem;">&#9203;</div>
                    <div style="font-weight: 600;">Loading KP Form...</div>
                </div>
                <iframe id="paanyayaPreviewIframe" src="" style="width: 100%; height: 100%; flex: 1 1 auto; min-height: 0; border: none; display: none;"></iframe>
            </div>
        </div>
        <div class="modal-foot" style="background: #ffffff; border-top: 1px solid #f1f5f9;">
            <button type="button" class="att-btn-ghost" id="previewModalCloseFootBtn">Close</button>
        </div>
    </div>
</div>

<!-- =========================================================================
     MODAL 3: ATTENDANCE TRACKING & SEAMLESS ESCALATION MODAL
     ========================================================================= -->
<div class="modal-overlay" id="paanyayaAttendanceModal">
    <div class="modal-card att-modal-card">
        <div class="modal-head att-modal-head">
            <div class="att-modal-title-wrap">
                <span class="att-modal-icon" aria-hidden="true">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                </span>
                <h3>Paanyaya Dialogue Record</h3>
            </div>
            <button type="button" class="modal-close-btn" id="attendanceModalCloseBtn" aria-label="Close modal">&times;</button>
        </div>
        <form id="attendanceActionForm">
            <input type="hidden" id="attPaanyayaId" name="paanyaya_id" value="">
            <input type="hidden" id="attComplaintId" name="complaint_id" value="">

            <div class="modal-body att-modal-body">
                <!-- Top Overline & Meta Pills (Matches "PROJECT DETAILS" row) -->
                <div class="att-overline-row">
                    <span class="att-overline-label">Dialogue Details</span>
                    <div class="att-meta-pills">
                        <span id="attAttemptBadge" class="status-pill-scheduled" style="font-size: 0.72rem; padding: 2px 9px;">1st Paanyaya</span>
                        <span class="status-pill-scheduled" id="attStatusBadge" style="font-size: 0.72rem; padding: 2px 9px;">Scheduled</span>
                    </div>
                </div>

                <!-- Status Lock Notice Banner -->
                <div id="attLockNoticeBanner" style="display: none; margin-bottom: 14px; border-radius: 10px; padding: 11px 14px; font-size: 0.81rem; line-height: 1.45;"></div>

                <!-- 2-Col Grid: Parties Attendance + Schedule Date & Time -->
                <div class="att-form-grid">
                    <!-- Row 1, Col 1: Nagpaanyaya (Complainant) Attendance -->
                    <div class="att-field-group">
                        <label class="att-field-label">Nagpaanyaya (Complainant)</label>
                        <div class="att-input-shell">
                            <div class="att-party-chip">
                                <span class="att-party-avatar" id="attCompInitials" aria-hidden="true">NP</span>
                                <span class="att-party-name" id="attCompNameDisplay" title="Nagpaanyaya">Nagpaanyaya</span>
                            </div>
                            <div class="att-seg-group" role="radiogroup" aria-label="Complainant attendance">
                                <label class="att-seg-pill">
                                    <input type="radio" name="complainant_attended" value="Present" checked>
                                    <span class="att-seg-text">Present</span>
                                </label>
                                <label class="att-seg-pill">
                                    <input type="radio" name="complainant_attended" value="Absent">
                                    <span class="att-seg-text">Absent</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Row 1, Col 2: Inanyayahan (Respondent) Attendance -->
                    <div class="att-field-group">
                        <label class="att-field-label">Inanyayahan (Respondent)</label>
                        <div class="att-input-shell">
                            <div class="att-party-chip">
                                <span class="att-party-avatar is-respondent" id="attInvInitials" aria-hidden="true">IN</span>
                                <span class="att-party-name" id="attInvNameDisplay" title="Inanyayahan">Inanyayahan</span>
                            </div>
                            <div class="att-seg-group" role="radiogroup" aria-label="Respondent attendance">
                                <label class="att-seg-pill">
                                    <input type="radio" name="invitee_attended" value="Present">
                                    <span class="att-seg-text">Present</span>
                                </label>
                                <label class="att-seg-pill">
                                    <input type="radio" name="invitee_attended" value="Absent" checked>
                                    <span class="att-seg-text">Absent</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Row 2 (Full Width): Schedule Date & Time -->
                    <div class="att-field-group" style="grid-column: 1 / -1;">
                        <label class="att-field-label">Schedule Date &amp; Time</label>
                        <div class="att-input-shell is-readonly">
                            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            <span id="attScheduleLabel" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">—</span>
                        </div>
                    </div>
                </div>

                <!-- Unified Full-Width Notes Field (Salaysay + Minutes of the Meeting) -->
                <div class="att-field-group" style="margin-bottom: 14px;">
                    <label for="attNotes" class="att-field-label">
                        <span>Notes</span>
                        <span class="att-field-hint">Statement narrative &amp; minutes (transfers if escalated)</span>
                    </label>
                    <textarea id="attNotes" name="notes" class="att-clean-textarea" rows="4" placeholder="Record statement narrative (Salaysay), dialogue minutes, agreements, or remarks..."></textarea>
                </div>

                <!-- Bottom Outcome Section (Changes automatically based on Paanyaya attempt & attendance) -->
                <div class="att-outcome-section" id="attOutcomeSection">
                    <div class="att-field-group" style="margin-bottom: 0;">
                        <label for="attOutcomeSelect" class="att-field-label">
                            <span>Outcome</span>
                            <span class="att-outcome-rule-badge" id="attRuleHint">1st Paanyaya</span>
                        </label>
                        <select id="attOutcomeSelect" name="outcome_action" class="att-clean-select">
                            <option value="schedule_second_paanyaya">Schedule 2nd Paanyaya</option>
                            <option value="resolve">Settlement</option>
                        </select>
                    </div>

                    <!-- Conditional Inline 2nd Paanyaya Schedule Drawer -->
                    <div id="secondPaanyayaPanel" class="att-p2-drawer" style="display: none;">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; gap: 8px;">
                            <span style="font-size: 0.82rem; font-weight: 700; color: #0f172a;">Schedule 2nd Paanyaya (Attempt 2)</span>
                            <span style="font-size: 0.72rem; color: #1b6553; background: #eef6f2; border: 1px solid #cde2d6; padding: 2px 8px; border-radius: 9999px; font-weight: 700;">Final Attempt</span>
                        </div>
                        <div class="form-row" style="margin-bottom: 0;">
                            <div class="att-field-group">
                                <label for="p2Date" class="att-field-label" style="font-size: 0.76rem;">Petsa ng 2nd Paanyaya</label>
                                <input type="date" id="p2Date" class="form-control" style="font-size: 0.84rem; padding: 7px 10px; border-radius: 8px;">
                            </div>
                            <div class="att-field-group">
                                <label for="p2Time" class="att-field-label" style="font-size: 0.76rem;">Oras ng 2nd Paanyaya</label>
                                <input type="time" id="p2Time" value="14:00" class="form-control" style="font-size: 0.84rem; padding: 7px 10px; border-radius: 8px;">
                            </div>
                        </div>
                    </div>

                    <!-- Escalated to Mediation Navigation Link -->
                    <div id="attViewMediationLinkWrap" style="display: none; margin-top: 12px;">
                        <a id="attViewMediationLink" href="#" class="att-btn-primary" style="width: 100%; justify-content: center; text-decoration: none; box-sizing: border-box;">
                            Tingnan ang Pormal na Reklamo sa Mediation &rarr;
                        </a>
                    </div>
                </div>
            </div>

            <div class="modal-foot att-modal-foot">
                <button type="button" class="att-btn-ghost" id="attendanceCancelBtn">Cancel</button>
                <div class="att-foot-right">
                    <button type="button" class="att-btn-primary" id="btnConfirmOutcome">Save Record</button>
                </div>
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
                            purokSelect.value = 'Non-Resident';
                        } else {
                            const fullText = `${streetLine} ${area} ${data.display_name || ''}`.toLowerCase();
                            if (fullText.includes('doña petra') || fullText.includes('dona petra')) {
                                purokSelect.value = 'Doña Petra';
                            } else {
                                const purokMatch = fullText.match(/purok\s*([1-8])/i) || fullText.match(/zone\s*([1-8])/i);
                                if (purokMatch && purokMatch[1]) {
                                    purokSelect.value = `Purok ${purokMatch[1]}`;
                                } else if (!purokSelect.value || purokSelect.value === 'Non-Resident' || purokSelect.value.startsWith('Non-Resident')) {
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
                        purokSelect.dispatchEvent(new Event('change'));
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
                purokSelect.value = 'Non-Resident';
                purokSelect.dispatchEvent(new Event('change'));
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
                            invPurokEl.value = 'Non-Resident';
                        } else {
                            invPurokEl.value = 'Purok 1';
                        }
                        invPurokEl.dispatchEvent(new Event('change'));
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

    const wizStepButtons = Array.from(document.querySelectorAll('.wiz-step-item[data-wiz-step]'));
    const wizPanels = Array.from(document.querySelectorAll('.wiz-panel[data-wiz-panel]'));
    const wizStepCountLabel = document.getElementById('wizStepCountLabel');
    const wizProgressFill = document.getElementById('wizProgressFill');
    const btnWizPrev = document.getElementById('btnWizPrev');
    const btnWizNext = document.getElementById('btnWizNext');
    const wizTopBackBtn = document.getElementById('wizTopBackBtn');
    const wizCardBody = document.querySelector('#schedulePaanyayaModal .wiz-card-body');
    let currentWizStep = 1;

    const validateWizStep = (step) => {
        if (step === 1) {
            const compFirst = document.getElementById('schedComplainantFirstName').value.trim();
            const compLast = document.getElementById('schedComplainantLastName').value.trim();
            const compAddr = document.getElementById('schedComplainantAddress').value.trim();
            const compPurok = document.getElementById('schedComplainantPurok').value.trim();
            const compContact = document.getElementById('schedComplainantContact').value.trim();
            const compNoContact = document.getElementById('chkCompNoContact')?.checked;
            if (!compFirst || !compLast) {
                agapAlert('Please enter both First Name and Last Name of the complainant (nagpaanyaya).', 'warning');
                return false;
            }
            if (!compAddr || !compPurok) {
                agapAlert('Please enter the Street Address and Purok/Zone of the complainant (nagpaanyaya).', 'warning');
                return false;
            }
            if (!compNoContact && !compContact) {
                agapAlert('Please enter the Contact Number of the complainant or check "No contact no.".', 'warning');
                return false;
            }
            return true;
        }
        if (step === 2) {
            const invFirst = document.getElementById('schedInviteeFirstName').value.trim();
            const invLast = document.getElementById('schedInviteeLastName').value.trim();
            const invAddr = document.getElementById('schedInviteeAddress').value.trim();
            const invPurok = document.getElementById('schedInviteePurok').value.trim();
            const invContact = document.getElementById('schedInviteeContact').value.trim();
            const invNoContact = document.getElementById('chkInvNoContact')?.checked;
            if (!invFirst || !invLast) {
                agapAlert('Please enter both First Name and Last Name of the person being invited (inanyayahan).', 'warning');
                return false;
            }
            if (!invAddr || !invPurok) {
                agapAlert('Please enter the Street Address and Purok/Zone of the person being invited (inanyayahan) for delivery.', 'warning');
                return false;
            }
            if (!invNoContact && !invContact) {
                agapAlert('Please enter the Contact Number of the invitee or check "Unknown / None".', 'warning');
                return false;
            }
            return true;
        }
        if (step === 3) {
            const date = schedDateInput?.value || '';
            const time = schedTimeInput?.value || '';
            const salaysay = document.getElementById('schedSalaysay')?.value.trim() || '';
            if (!date || !time) {
                agapAlert('Please provide both scheduled date and time.', 'warning');
                return false;
            }
            if (!salaysay) {
                agapAlert('Please provide the Salaysay (Statement / Reason for Invitation).', 'warning');
                return false;
            }
            return true;
        }
        return true;
    };

    const formatResidencyStatusLabel = (val) => {
        const s = String(val ?? '0');
        if (s === '2') return 'Non-Resident (Outside Tumana)';
        if (s === '1') return 'Resident (Tenant / Renter)';
        return 'Resident (Permanent)';
    };

    const bindResidencyPurokSync = (tenantId, purokId) => {
        const tenantEl = document.getElementById(tenantId);
        const purokEl = document.getElementById(purokId);
        if (!tenantEl || !purokEl) return;

        tenantEl.addEventListener('change', () => {
            if (tenantEl.value === '2') {
                purokEl.value = 'Non-Resident';
            } else if (purokEl.value === 'Non-Resident' || purokEl.value.startsWith('Non-Resident')) {
                purokEl.value = '';
            }
        });
        purokEl.addEventListener('change', () => {
            if (purokEl.value === 'Non-Resident' || purokEl.value.startsWith('Non-Resident')) {
                purokEl.value = 'Non-Resident';
                tenantEl.value = '2';
            } else if (purokEl.value !== '' && tenantEl.value === '2') {
                tenantEl.value = '0';
            }
        });
    };
    bindResidencyPurokSync('schedComplainantIsTenant', 'schedComplainantPurok');
    bindResidencyPurokSync('schedInviteeIsTenant', 'schedInviteePurok');

    const populateWizReview = () => {
        const compResStatus = formatResidencyStatusLabel(document.getElementById('schedComplainantIsTenant')?.value);
        const compFull = [
            document.getElementById('schedComplainantFirstName').value.trim(),
            document.getElementById('schedComplainantMiddleName').value.trim(),
            document.getElementById('schedComplainantLastName').value.trim()
        ].filter(Boolean).join(' ') || '—';
        const compAddr = [
            document.getElementById('schedComplainantAddress').value.trim(),
            document.getElementById('schedComplainantPurok').value.trim()
        ].filter(Boolean).join(', ');
        const compContact = document.getElementById('chkCompNoContact')?.checked
            ? 'No contact number'
            : (document.getElementById('schedComplainantContact').value.trim() || '—');
        const compEmail = document.getElementById('schedComplainantEmail').value.trim();

        const invResStatus = formatResidencyStatusLabel(document.getElementById('schedInviteeIsTenant')?.value);
        const invFull = [
            document.getElementById('schedInviteeFirstName').value.trim(),
            document.getElementById('schedInviteeMiddleName').value.trim(),
            document.getElementById('schedInviteeLastName').value.trim()
        ].filter(Boolean).join(' ') || '—';
        const invAddr = [
            document.getElementById('schedInviteeAddress').value.trim(),
            document.getElementById('schedInviteePurok').value.trim()
        ].filter(Boolean).join(', ');
        const invContact = document.getElementById('chkInvNoContact')?.checked
            ? 'Unknown / None'
            : (document.getElementById('schedInviteeContact').value.trim() || '—');
        const invEmail = document.getElementById('schedInviteeEmail').value.trim();

        const revCompName = document.getElementById('revCompName');
        const revCompDetails = document.getElementById('revCompDetails');
        const revInvName = document.getElementById('revInvName');
        const revInvDetails = document.getElementById('revInvDetails');
        const revSchedDateTime = document.getElementById('revSchedDateTime');
        const revSchedVenue = document.getElementById('revSchedVenue');
        const revSalaysay = document.getElementById('revSalaysay');

        if (revCompName) revCompName.textContent = compFull;
        if (revCompDetails) {
            revCompDetails.textContent = [compResStatus, compAddr, compContact, compEmail].filter(Boolean).join(' · ') || '—';
        }
        if (revInvName) revInvName.textContent = invFull;
        if (revInvDetails) {
            revInvDetails.textContent = [invResStatus, invAddr, invContact, invEmail].filter(Boolean).join(' · ') || '—';
        }
        if (revSchedDateTime) {
            revSchedDateTime.textContent = formatPreviewDateTime(schedDateInput?.value || '', schedTimeInput?.value || '');
        }
        if (revSchedVenue) {
            revSchedVenue.textContent = document.getElementById('schedVenue')?.value.trim() || 'Tanggapan ng Barangay Tumana';
        }
        if (revSalaysay) {
            revSalaysay.textContent = document.getElementById('schedSalaysay')?.value.trim() || '—';
        }
    };

    const setWizStep = (step) => {
        currentWizStep = Math.min(4, Math.max(1, Number(step) || 1));

        wizPanels.forEach(panel => {
            const pStep = Number(panel.dataset.wizPanel || '0');
            panel.classList.toggle('is-active', pStep === currentWizStep);
        });

        wizStepButtons.forEach(btn => {
            const bStep = Number(btn.dataset.wizStep || '0');
            const isActive = bStep === currentWizStep;
            const isDone = bStep < currentWizStep;
            btn.classList.toggle('is-active', isActive);
            btn.classList.toggle('is-completed', isDone);
            const numBadge = btn.querySelector('[data-wiz-num]');
            if (numBadge) {
                numBadge.innerHTML = isDone ? '&#10003;' : String(bStep);
            }
        });

        if (wizStepCountLabel) wizStepCountLabel.textContent = `Step ${currentWizStep} of 4`;
        if (wizProgressFill) wizProgressFill.style.width = `${(currentWizStep / 4) * 100}%`;

        if (cancelSchedBtn) cancelSchedBtn.style.display = currentWizStep === 1 ? 'inline-flex' : 'none';
        if (btnWizPrev) btnWizPrev.style.display = currentWizStep > 1 ? 'inline-flex' : 'none';
        if (btnWizNext) btnWizNext.style.display = currentWizStep < 4 ? 'inline-flex' : 'none';
        if (submitSchedBtn) submitSchedBtn.style.display = currentWizStep === 4 ? 'inline-flex' : 'none';

        if (wizCardBody) wizCardBody.scrollTop = 0;

        if (currentWizStep === 1) {
            compMapCtrl.initMap();
        } else if (currentWizStep === 2) {
            invMapCtrl.initMap();
        } else if (currentWizStep === 4) {
            populateWizReview();
        }
    };

    const openSchedModal = () => {
        updateMinDate();
        if (schedModal) schedModal.style.display = 'flex';
        setWizStep(1);
        initPaanyayaLocationMap();
    };
    const closeSchedModal = () => { if (schedModal) schedModal.style.display = 'none'; };

    if (openSchedBtn) openSchedBtn.addEventListener('click', openSchedModal);
    if (closeSchedBtn) closeSchedBtn.addEventListener('click', closeSchedModal);
    if (wizTopBackBtn) wizTopBackBtn.addEventListener('click', closeSchedModal);
    if (cancelSchedBtn) cancelSchedBtn.addEventListener('click', closeSchedModal);
    if (btnWizPrev) {
        btnWizPrev.addEventListener('click', () => {
            if (currentWizStep > 1) setWizStep(currentWizStep - 1);
        });
    }
    if (btnWizNext) {
        btnWizNext.addEventListener('click', () => {
            if (!validateWizStep(currentWizStep)) return;
            if (currentWizStep < 4) setWizStep(currentWizStep + 1);
        });
    }
    wizStepButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetStep = Number(btn.dataset.wizStep || '1');
            if (targetStep === currentWizStep) return;
            if (targetStep > currentWizStep) {
                for (let s = currentWizStep; s < targetStep; s++) {
                    if (!validateWizStep(s)) {
                        setWizStep(s);
                        return;
                    }
                }
            }
            setWizStep(targetStep);
        });
    });
    document.querySelectorAll('[data-wiz-jump]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = Number(btn.dataset.wizJump || '1');
            setWizStep(target);
        });
    });
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
            const isTenantSel = document.getElementById('schedComplainantIsTenant');
            const rawPurok = (opt.dataset.purok || '').trim();
            const normPurok = rawPurok.toLowerCase().includes('non-resident') ? 'Non-Resident' : rawPurok;
            const rawTenant = opt.dataset.isTenant ?? (normPurok === 'Non-Resident' ? '2' : '0');
            if (isTenantSel) {
                isTenantSel.value = ['0', '1', '2'].includes(String(rawTenant)) ? String(rawTenant) : (normPurok === 'Non-Resident' ? '2' : '0');
            }
            if (purokSel && normPurok) {
                purokSel.value = normPurok;
            } else if (purokSel && isTenantSel?.value === '2') {
                purokSel.value = 'Non-Resident';
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
            const isTenantSel = document.getElementById('schedInviteeIsTenant');
            const rawPurok = (opt.dataset.purok || '').trim();
            const normPurok = rawPurok.toLowerCase().includes('non-resident') ? 'Non-Resident' : rawPurok;
            const rawTenant = opt.dataset.isTenant ?? (normPurok === 'Non-Resident' ? '2' : '0');
            if (isTenantSel) {
                isTenantSel.value = ['0', '1', '2'].includes(String(rawTenant)) ? String(rawTenant) : (normPurok === 'Non-Resident' ? '2' : '0');
            }
            if (purokSel && normPurok) {
                purokSel.value = normPurok;
            } else if (purokSel && isTenantSel?.value === '2') {
                purokSel.value = 'Non-Resident';
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
            const compIsTenant = document.getElementById('schedComplainantIsTenant')?.value ?? (compPurok === 'Non-Resident' ? '2' : '0');
            const compContact = document.getElementById('schedComplainantContact').value.trim();
            const compEmail = document.getElementById('schedComplainantEmail').value.trim();
            const compNoContact = document.getElementById('chkCompNoContact')?.checked;

            const invResId = document.getElementById('schedInviteeResidentId')?.value || '';
            const invFirst = document.getElementById('schedInviteeFirstName').value.trim();
            const invMiddle = document.getElementById('schedInviteeMiddleName').value.trim();
            const invLast = document.getElementById('schedInviteeLastName').value.trim();
            const invAddr = document.getElementById('schedInviteeAddress').value.trim();
            const invPurok = document.getElementById('schedInviteePurok').value.trim();
            const invIsTenant = document.getElementById('schedInviteeIsTenant')?.value ?? (invPurok === 'Non-Resident' ? '2' : '0');
            const invContact = document.getElementById('schedInviteeContact').value.trim();
            const invEmail = document.getElementById('schedInviteeEmail').value.trim();
            const invNoContact = document.getElementById('chkInvNoContact')?.checked;

            const date = schedDateInput.value;
            const time = schedTimeInput.value;
            const venue = document.getElementById('schedVenue').value.trim() || 'Tanggapan ng Barangay Tumana';
            const salaysay = document.getElementById('schedSalaysay').value.trim();

            if (!validateWizStep(1)) {
                setWizStep(1);
                return;
            }
            if (!validateWizStep(2)) {
                setWizStep(2);
                return;
            }
            if (!validateWizStep(3)) {
                setWizStep(3);
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
                    complainant_is_tenant: compIsTenant,
                    complainant_contact_no: compContact,
                    complainant_email: compEmail,
                    invitee_resident_id: invResId,
                    invitee_first_name: invFirst,
                    invitee_middle_name: invMiddle,
                    invitee_last_name: invLast,
                    invitee_name: inviteeFullName,
                    invitee_address: invAddr,
                    invitee_purok: invPurok,
                    invitee_is_tenant: invIsTenant,
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
    // 2. TABBED PAANYAYA RECORD & KP FORM VIEW MODAL
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
    const previewKpActions = document.getElementById('previewKpActions');
    const previewModalTabs = document.getElementById('previewModalTabs');
    const btnPreviewTabDialogue = document.getElementById('btnPreviewTabDialogue');
    const btnPreviewTabKpForm = document.getElementById('btnPreviewTabKpForm');
    const previewTabRecordedPill = document.getElementById('previewTabRecordedPill');
    const previewTabDialoguePanel = document.getElementById('previewTabDialoguePanel');
    const previewTabKpFormPanel = document.getElementById('previewTabKpFormPanel');
    const previewDialogueLoading = document.getElementById('previewDialogueLoading');
    const previewDialogueContent = document.getElementById('previewDialogueContent');

    let userSwitchedPreviewTab = false;

    function switchPreviewTab(tabName) {
        const showDialogue = tabName === 'dialogue';
        if (btnPreviewTabDialogue) {
            btnPreviewTabDialogue.classList.toggle('is-active', showDialogue);
            btnPreviewTabDialogue.setAttribute('aria-selected', showDialogue ? 'true' : 'false');
        }
        if (btnPreviewTabKpForm) {
            btnPreviewTabKpForm.classList.toggle('is-active', !showDialogue);
            btnPreviewTabKpForm.setAttribute('aria-selected', showDialogue ? 'false' : 'true');
        }
        if (previewTabDialoguePanel) previewTabDialoguePanel.style.display = showDialogue ? 'flex' : 'none';
        if (previewTabKpFormPanel) previewTabKpFormPanel.style.display = showDialogue ? 'none' : 'flex';
        if (previewKpActions) {
            previewKpActions.style.visibility = showDialogue ? 'hidden' : 'visible';
            previewKpActions.style.pointerEvents = showDialogue ? 'none' : 'auto';
        }
    }

    if (btnPreviewTabDialogue) {
        btnPreviewTabDialogue.addEventListener('click', () => {
            userSwitchedPreviewTab = true;
            switchPreviewTab('dialogue');
        });
    }
    if (btnPreviewTabKpForm) {
        btnPreviewTabKpForm.addEventListener('click', () => {
            userSwitchedPreviewTab = true;
            switchPreviewTab('kp-form');
        });
    }

    function setPreviewAttendancePill(el, value) {
        if (!el) return;
        const val = String(value || '').trim();
        if (val === 'Present') {
            el.textContent = 'Present';
            el.className = 'prev-att-pill is-present';
        } else if (val === 'Absent') {
            el.textContent = 'Absent';
            el.className = 'prev-att-pill is-absent';
        } else {
            el.textContent = 'Pending';
            el.className = 'prev-att-pill is-pending';
        }
    }

    function formatPreviewDateTime(dateStr, timeStr) {
        if (!dateStr) return '—';
        try {
            const dt = new Date(`${dateStr}T${timeStr || '00:00:00'}`);
            if (!Number.isNaN(dt.getTime())) {
                const datePart = dt.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
                if (timeStr) {
                    const timePart = dt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
                    return `${datePart} · ${timePart}`;
                }
                return datePart;
            }
        } catch (err) {}
        return `${dateStr} ${timeStr || ''}`.trim();
    }

    function formatRecordedTimestamp(ts) {
        if (!ts) return '—';
        try {
            const dt = new Date(String(ts).replace(' ', 'T'));
            if (!Number.isNaN(dt.getTime())) {
                return dt.toLocaleString('en-US', {
                    month: 'short', day: 'numeric', year: 'numeric',
                    hour: 'numeric', minute: '2-digit'
                });
            }
        } catch (err) {}
        return String(ts);
    }

    function renderPreviewDialogueDetails(row, fallbackAttempt = 1) {
        const effAttempt = Math.min(2, Math.max(1, parseInt(row.attempt_number || fallbackAttempt, 10) || 1));
        const rawStatus = String(row.status || 'Scheduled').trim();
        const effStatus = String(row.current_workflow_status || rawStatus).trim();
        const normEff = effStatus.toLowerCase();
        const normRaw = rawStatus.toLowerCase();
        const compName = row.complainant_name || 'Nagpaanyaya';
        const invName = row.invitee_name || 'Inanyayahan';
        const compAtt = row.complainant_attended || '';
        const invAtt = row.invitee_attended || '';
        const linkedCid = parseInt(row.complaint_id || row.escalated_to_complaint_id || 0, 10) || 0;
        const linkedCaseId = parseInt(row.linked_case_id || 0, 10) || 0;

        const prevAttemptBadge = document.getElementById('prevAttemptBadge');
        const prevStatusBadge = document.getElementById('prevStatusBadge');
        const prevCaseChip = document.getElementById('prevCaseChip');
        const prevCompInitials = document.getElementById('prevCompInitials');
        const prevCompName = document.getElementById('prevCompName');
        const prevCompSub = document.getElementById('prevCompSub');
        const prevCompAttPill = document.getElementById('prevCompAttPill');
        const prevInvInitials = document.getElementById('prevInvInitials');
        const prevInvName = document.getElementById('prevInvName');
        const prevInvSub = document.getElementById('prevInvSub');
        const prevInvAttPill = document.getElementById('prevInvAttPill');
        const prevScheduleText = document.getElementById('prevScheduleText');
        const prevRecordedAt = document.getElementById('prevRecordedAt');
        const prevNotesText = document.getElementById('prevNotesText');
        const prevOutcomeBadge = document.getElementById('prevOutcomeBadge');
        const prevOutcomeTitle = document.getElementById('prevOutcomeTitle');
        const prevOutcomeSub = document.getElementById('prevOutcomeSub');
        const prevViewMediationWrap = document.getElementById('prevViewMediationWrap');
        const prevViewMediationLink = document.getElementById('prevViewMediationLink');

        if (prevAttemptBadge) {
            prevAttemptBadge.textContent = effAttempt >= 2 ? '2nd Paanyaya' : '1st Paanyaya';
            prevAttemptBadge.className = effAttempt >= 2 ? 'status-pill-escalated' : 'status-pill-scheduled';
        }
        if (prevStatusBadge) {
            prevStatusBadge.textContent = effStatus;
            applyModalStatusBadgeClass(prevStatusBadge, effStatus, linkedCaseId);
        }
        if (previewTabRecordedPill) {
            const pillLabel = ['completed', 'done'].includes(normRaw)
                ? 'Recorded'
                : (['resolved', 'settled'].includes(normEff) ? 'Settled' : (normEff === 'dismissed' ? 'Dismissed' : (normEff === 'escalated' || linkedCaseId > 0 ? 'Escalated' : effStatus)));
            previewTabRecordedPill.textContent = pillLabel;
        }
        if (prevCaseChip) {
            if (row.complaint_number) {
                prevCaseChip.textContent = `Case #${row.complaint_number}`;
                prevCaseChip.style.display = 'inline-flex';
            } else {
                prevCaseChip.style.display = 'none';
            }
        }

        if (prevCompInitials) prevCompInitials.textContent = getPartyInitials(compName, 'NP');
        if (prevCompName) prevCompName.textContent = compName;
        if (prevCompSub) {
            const sub = [row.complainant_address, row.complainant_purok, row.complainant_contact_no].filter(Boolean).join(' · ');
            prevCompSub.textContent = sub;
            prevCompSub.style.display = sub ? 'block' : 'none';
        }
        setPreviewAttendancePill(prevCompAttPill, compAtt);

        if (prevInvInitials) prevInvInitials.textContent = getPartyInitials(invName, 'IN');
        if (prevInvName) prevInvName.textContent = invName;
        if (prevInvSub) {
            const sub = [row.invitee_address, row.invitee_purok, row.invitee_contact_no].filter(Boolean).join(' · ');
            prevInvSub.textContent = sub;
            prevInvSub.style.display = sub ? 'block' : 'none';
        }
        setPreviewAttendancePill(prevInvAttPill, invAtt);

        if (prevScheduleText) {
            const sched = formatPreviewDateTime(row.scheduled_date, row.scheduled_time);
            const venue = row.venue ? ` · ${row.venue}` : '';
            prevScheduleText.textContent = `${sched}${venue}`;
        }
        if (prevRecordedAt) {
            prevRecordedAt.textContent = formatRecordedTimestamp(row.attendance_recorded_at || row.updated_at);
        }

        if (prevNotesText) {
            const mergedNotes = mergeExistingNotes(row.salaysay, row.dialogue_notes);
            if (mergedNotes) {
                prevNotesText.textContent = mergedNotes;
                prevNotesText.style.color = '#1e293b';
                prevNotesText.style.fontStyle = 'normal';
            } else {
                prevNotesText.textContent = 'No notes recorded.';
                prevNotesText.style.color = '#94a3b8';
                prevNotesText.style.fontStyle = 'italic';
            }
        }

        let outcomeBadgeText = 'Dialogue Recorded';
        let outcomeTitleText = 'Proceeded with Meeting (Ongoing)';
        let outcomeSubText = 'Attendance and dialogue notes were saved for this invitation.';

        if (normEff === 'dismissed' || normRaw === 'dismissed') {
            outcomeBadgeText = 'Closed · Dismissed';
            outcomeTitleText = 'Dismissed (Complainant Absent)';
            outcomeSubText = 'Closed at Paanyaya stage due to complainant non-appearance.';
        } else if (['resolved', 'settled'].includes(normEff) || ['resolved', 'settled'].includes(normRaw)) {
            outcomeBadgeText = 'Closed · Settled';
            outcomeTitleText = 'Settlement Reached (Kasunduan — Settled)';
            outcomeSubText = 'Parties reached an amicable settlement at the Paanyaya dialogue.';
        } else if (normEff === 'escalated' || normRaw === 'escalated' || ['docketed', 'mediation', 'conciliation', 'arbitration', 'cfa issued'].includes(normEff) || linkedCaseId > 0) {
            outcomeBadgeText = 'Escalated · Mediation';
            outcomeTitleText = row.complaint_number
                ? `Scheduled to Formal Mediation (Case #${row.complaint_number})`
                : 'Scheduled to Formal Mediation';
            outcomeSubText = 'Attendance records and notes were transferred to the formal Mediation case.';
        } else if (['completed', 'done'].includes(normRaw)) {
            outcomeBadgeText = 'Attempt Completed';
            outcomeTitleText = effAttempt === 1 ? 'Completed — Issued 2nd Paanyaya' : 'Paanyaya Completed';
            outcomeSubText = effAttempt === 1
                ? '1st Paanyaya dialogue concluded and moved forward to 2nd Paanyaya.'
                : 'This dialogue session has been completed.';
        }

        if (prevOutcomeBadge) prevOutcomeBadge.textContent = outcomeBadgeText;
        if (prevOutcomeTitle) prevOutcomeTitle.textContent = outcomeTitleText;
        if (prevOutcomeSub) prevOutcomeSub.textContent = outcomeSubText;

        if (prevViewMediationWrap && prevViewMediationLink) {
            const showMedLink = linkedCid > 0 && (normEff === 'escalated' || ['docketed', 'mediation', 'conciliation', 'arbitration', 'cfa issued'].includes(normEff) || linkedCaseId > 0);
            prevViewMediationWrap.style.display = showMedLink ? 'block' : 'none';
            if (showMedLink) {
                prevViewMediationLink.href = `../complaints/complaint-details.php?id=${linkedCid}&tab=mediation`;
            }
        }

        if (previewDialogueLoading) previewDialogueLoading.style.display = 'none';
        if (previewDialogueContent) previewDialogueContent.style.display = 'flex';
    }

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
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            const paanyayaId = parseInt(this.dataset.paanyayaId || '0', 10) || 0;
            const complaintId = parseInt(this.dataset.complaintId || '0', 10) || 0;
            const attempt = parseInt(this.dataset.attempt || '1', 10) || 1;
            const attemptQuery = attempt >= 2 ? `&attempt_number=${attempt}` : '';
            const invitee = this.dataset.name || 'Inanyayahan';
            const initialRecorded = this.dataset.recorded === '1';

            userSwitchedPreviewTab = false;

            if (previewTitle) {
                previewTitle.textContent = initialRecorded
                    ? `Paanyaya Record: ${invitee}`
                    : `KP Form (Paanyaya): ${invitee}`;
            }
            if (previewSubtitle) {
                previewSubtitle.textContent = `${attempt >= 2 ? '2nd Paanyaya' : '1st Paanyaya'} · Barangay Tumana Official Record`;
            }

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

            if (previewDialogueLoading) previewDialogueLoading.style.display = 'block';
            if (previewDialogueContent) previewDialogueContent.style.display = 'none';

            if (previewModalTabs) {
                previewModalTabs.style.display = initialRecorded ? 'flex' : 'none';
            }
            switchPreviewTab(initialRecorded ? 'dialogue' : 'kp-form');

            if (previewModal) previewModal.style.display = 'flex';

            if (paanyayaId > 0) {
                try {
                    const res = await fetch(`../../../backend/api/paanyaya/attendance.php?paanyaya_id=${paanyayaId}`);
                    const json = await res.json();
                    if (json.success && json.data) {
                        const row = json.data;
                        const normStatus = String(row.status || '').trim().toLowerCase();
                        const hasRecorded = Boolean(row.attendance_recorded_at)
                            || ['completed', 'done', 'resolved', 'settled', 'dismissed', 'escalated'].includes(normStatus);

                        renderPreviewDialogueDetails(row, attempt);

                        if (previewModalTabs) {
                            previewModalTabs.style.display = hasRecorded ? 'flex' : 'none';
                        }
                        if (previewTitle) {
                            previewTitle.textContent = hasRecorded
                                ? `Paanyaya Record: ${row.invitee_name || invitee}`
                                : `KP Form (Paanyaya): ${row.invitee_name || invitee}`;
                        }
                        if (!userSwitchedPreviewTab) {
                            switchPreviewTab(hasRecorded ? 'dialogue' : 'kp-form');
                        }
                    }
                } catch (err) {
                    console.warn('Could not load Paanyaya dialogue details:', err);
                    if (previewModalTabs) previewModalTabs.style.display = 'none';
                    if (!userSwitchedPreviewTab) switchPreviewTab('kp-form');
                }
            }
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
    const attScheduleLabel = document.getElementById('attScheduleLabel');
    const attStatusBadge = document.getElementById('attStatusBadge');
    const attCompNameDisplay = document.getElementById('attCompNameDisplay');
    const attInvNameDisplay = document.getElementById('attInvNameDisplay');
    const attCompInitials = document.getElementById('attCompInitials');
    const attInvInitials = document.getElementById('attInvInitials');
    const attNotesInput = document.getElementById('attNotes');
    const attAttemptBadge = document.getElementById('attAttemptBadge');
    const attOutcomeSelect = document.getElementById('attOutcomeSelect');
    const attRuleHint = document.getElementById('attRuleHint');

    const btnSaveAttOnly = document.getElementById('btnSaveAttendanceOnly');
    const btnConfirmOutcome = document.getElementById('btnConfirmOutcome');
    const secondPaanyayaPanel = document.getElementById('secondPaanyayaPanel');
    const p2DateInput = document.getElementById('p2Date');
    const p2TimeInput = document.getElementById('p2Time');

    let currentAttAttempt = 1;
    let currentAttLocked = false;

    const closeAttModal = () => { if (attModal) attModal.style.display = 'none'; };
    if (attCloseBtn) attCloseBtn.addEventListener('click', closeAttModal);
    if (attCancelBtn) attCancelBtn.addEventListener('click', closeAttModal);
    if (attModal) {
        attModal.addEventListener('click', (e) => {
            if (e.target === attModal) closeAttModal();
        });
    }

    function getPartyInitials(name, fallback = 'AG') {
        const clean = String(name || '').trim();
        if (!clean) return fallback;
        const parts = clean.split(/\s+/).filter(Boolean);
        if (parts.length >= 2) {
            return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
        }
        return clean.slice(0, 2).toUpperCase();
    }

    function mergeExistingNotes(salaysay, dialogueNotes) {
        const s = String(salaysay || '').trim();
        const d = String(dialogueNotes || '').trim();
        if (d && s) {
            if (d === s || d.includes(s)) return d;
            if (s.includes(d)) return s;
            return `${s}\n\n${d}`;
        }
        return d || s || '';
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

    /**
     * Attendance-driven Outcome options:
     * 1st Paanyaya: "Schedule 2nd Paanyaya" and "Settlement" only.
     * 2nd Paanyaya: "Settlement", "Dismissed", and "Escalate to Mediation" only.
     */
    function getAttendanceOutcomeSpec(attempt, compAttended, invAttended) {
        const isSecond = Number(attempt) >= 2;
        const compPresent = compAttended === 'Present';
        const invPresent = invAttended === 'Present';

        if (!isSecond) {
            if (compPresent && invPresent) {
                return {
                    hint: '1st Paanyaya · Both Present',
                    defaultValue: 'resolve',
                    options: [
                        { value: 'resolve', label: 'Settlement' },
                        { value: 'schedule_second_paanyaya', label: 'Schedule 2nd Paanyaya' }
                    ]
                };
            }
            const absentWho = (!compPresent && !invPresent)
                ? 'Both Absent'
                : (!compPresent ? 'Complainant Absent' : 'Respondent Absent');
            return {
                hint: `1st Paanyaya · ${absentWho} — Schedule 2nd Paanyaya`,
                defaultValue: 'schedule_second_paanyaya',
                options: [
                    { value: 'schedule_second_paanyaya', label: 'Schedule 2nd Paanyaya' },
                    { value: 'resolve', label: 'Settlement' }
                ]
            };
        }

        // 2nd Paanyaya
        if (compPresent && invPresent) {
            return {
                hint: '2nd Paanyaya · Both Present',
                defaultValue: 'resolve',
                options: [
                    { value: 'resolve', label: 'Settlement' },
                    { value: 'escalate', label: 'Escalate to Mediation' },
                    { value: 'dismiss', label: 'Dismissed' }
                ]
            };
        }
        if (compPresent && !invPresent) {
            return {
                hint: '2nd Paanyaya · Respondent Absent — Escalate to Mediation',
                defaultValue: 'escalate',
                options: [
                    { value: 'escalate', label: 'Escalate to Mediation' },
                    { value: 'resolve', label: 'Settlement' },
                    { value: 'dismiss', label: 'Dismissed' }
                ]
            };
        }
        return {
            hint: !invPresent
                ? '2nd Paanyaya · Both Absent — Dismissed'
                : '2nd Paanyaya · Complainant Absent — Dismissed',
            defaultValue: 'dismiss',
            options: [
                { value: 'dismiss', label: 'Dismissed' },
                { value: 'resolve', label: 'Settlement' },
                { value: 'escalate', label: 'Escalate to Mediation' }
            ]
        };
    }

    function applyAttendanceOutcomeRules({ preserveSelection = false } = {}) {
        if (!attOutcomeSelect || currentAttLocked) return;

        const compAttended = getRadio('complainant_attended');
        const invAttended = getRadio('invitee_attended');
        const prevValue = attOutcomeSelect.value;
        const spec = getAttendanceOutcomeSpec(currentAttAttempt, compAttended, invAttended);

        attOutcomeSelect.innerHTML = '';
        spec.options.forEach(opt => {
            const o = document.createElement('option');
            o.value = opt.value;
            o.textContent = opt.label;
            attOutcomeSelect.appendChild(o);
        });

        const hasPrev = preserveSelection && spec.options.some(o => o.value === prevValue);
        attOutcomeSelect.value = hasPrev ? prevValue : spec.defaultValue;

        if (attRuleHint) {
            attRuleHint.textContent = spec.hint;
        }

        syncOutcomeUi();
    }

    function syncOutcomeUi() {
        const outcome = attOutcomeSelect ? attOutcomeSelect.value : 'schedule_second_paanyaya';
        const isLocked = currentAttLocked || Boolean(attOutcomeSelect && attOutcomeSelect.disabled);

        if (secondPaanyayaPanel) {
            if (!isLocked && outcome === 'schedule_second_paanyaya') {
                secondPaanyayaPanel.style.display = 'block';
                if (p2DateInput) {
                    const today = new Date().toISOString().split('T')[0];
                    p2DateInput.min = today;
                    if (!p2DateInput.value) {
                        p2DateInput.value = getSuggestedNextDate();
                    }
                }
            } else {
                secondPaanyayaPanel.style.display = 'none';
            }
        }

        if (btnConfirmOutcome && !isLocked) {
            const labelMap = {
                resolve: 'Save Settlement',
                schedule_second_paanyaya: 'Schedule 2nd Paanyaya',
                escalate: 'Escalate to Mediation',
                dismiss: 'Mark as Dismissed'
            };
            btnConfirmOutcome.textContent = labelMap[outcome] || 'Save Record';
            btnConfirmOutcome.classList.toggle('is-danger', outcome === 'dismiss');
        }
    }

    // =========================================================================
    // STATE MACHINE / LOCK ENGINE:
    // - If settled, dismissed, or escalated into mediation, locks modal fields
    // =========================================================================
    function applyAttendanceLockState(status, complaintId, attempt, complaintNumber = '', linkedCaseId = 0) {
        const normStatus = String(status || '').trim().toLowerCase();
        const parsedComplaintId = parseInt(complaintId, 10) || 0;
        const parsedLinkedCaseId = parseInt(linkedCaseId, 10) || 0;
        const isDismissed = normStatus === 'dismissed';
        const isSettled = ['resolved', 'settled', 'completed', 'done'].includes(normStatus);
        const isEscalated = normStatus === 'escalated'
            || ['docketed', 'mediation', 'conciliation', 'arbitration', 'cfa issued'].includes(normStatus)
            || (parsedLinkedCaseId > 0 && !isSettled && !isDismissed);

        const lockNotice = document.getElementById('attLockNoticeBanner');
        const viewMedWrap = document.getElementById('attViewMediationLinkWrap');
        const viewMedLink = document.getElementById('attViewMediationLink');
        const compRadios = document.querySelectorAll('input[name="complainant_attended"]');
        const invRadios = document.querySelectorAll('input[name="invitee_attended"]');

        const lockFields = () => {
            compRadios.forEach(r => { r.disabled = true; });
            invRadios.forEach(r => { r.disabled = true; });
            if (attNotesInput) {
                attNotesInput.readOnly = true;
                attNotesInput.style.backgroundColor = '#f8fafc';
                attNotesInput.style.color = '#475569';
            }
            if (btnSaveAttOnly) {
                btnSaveAttOnly.disabled = true;
                btnSaveAttOnly.style.display = 'none';
            }
            if (secondPaanyayaPanel) secondPaanyayaPanel.style.display = 'none';
        };

        if (isSettled) {
            currentAttLocked = true;
            if (lockNotice) {
                lockNotice.style.display = 'flex';
                lockNotice.style.alignItems = 'flex-start';
                lockNotice.style.gap = '10px';
                lockNotice.style.background = '#ecfdf5';
                lockNotice.style.border = '1px solid #bbf7d0';
                lockNotice.style.color = '#065f46';
                lockNotice.innerHTML = `
                    <span style="font-size: 1.05rem; line-height: 1.2;">&#128274;</span>
                    <div>
                        <strong style="display: block; font-size: 0.84rem; color: #047857; margin-bottom: 2px;">
                            Naresolba sa Paanyaya (Kasunduan)
                        </strong>
                        <span style="font-size: 0.78rem; color: #065f46; line-height: 1.4;">
                            Ang usaping ito ay ganap nang naayos at naresolba sa pribadong kasunduan (Settled). Naka-lock na ang mga field upang maiwasan ang pagbabago.
                        </span>
                    </div>
                `;
            }

            if (attOutcomeSelect) {
                attOutcomeSelect.innerHTML = '<option value="resolve">Settlement</option>';
                attOutcomeSelect.value = 'resolve';
                attOutcomeSelect.disabled = true;
            }
            if (attRuleHint) attRuleHint.textContent = 'Closed · Settled';
            if (btnConfirmOutcome) {
                btnConfirmOutcome.disabled = true;
                btnConfirmOutcome.classList.remove('is-danger');
                btnConfirmOutcome.style.opacity = '1';
                btnConfirmOutcome.style.cursor = 'default';
                btnConfirmOutcome.style.background = '#15803d';
                btnConfirmOutcome.style.borderColor = '#166534';
                btnConfirmOutcome.style.color = '#ffffff';
                btnConfirmOutcome.textContent = 'Settled (Kasunduan)';
                btnConfirmOutcome.title = 'Naitatala na bilang Kasunduan (Settled).';
            }

            lockFields();
            if (viewMedWrap) viewMedWrap.style.display = 'none';

        } else if (isDismissed) {
            currentAttLocked = true;
            if (lockNotice) {
                lockNotice.style.display = 'flex';
                lockNotice.style.alignItems = 'flex-start';
                lockNotice.style.gap = '10px';
                lockNotice.style.background = '#fef2f2';
                lockNotice.style.border = '1px solid #fecaca';
                lockNotice.style.color = '#991b1b';
                lockNotice.innerHTML = `
                    <span style="font-size: 1.05rem; line-height: 1.2;">&#128274;</span>
                    <div>
                        <strong style="display: block; font-size: 0.84rem; color: #b91c1c; margin-bottom: 2px;">
                            Na-dismiss sa Paanyaya
                        </strong>
                        <span style="font-size: 0.78rem; color: #991b1b; line-height: 1.4;">
                            Opisyal nang na-dismiss ang usaping ito (Complainant Absent). Naka-lock na ang mga field upang maiwasan ang pagbabago.
                        </span>
                    </div>
                `;
            }

            if (attOutcomeSelect) {
                attOutcomeSelect.innerHTML = '<option value="dismiss">Dismissed</option>';
                attOutcomeSelect.value = 'dismiss';
                attOutcomeSelect.disabled = true;
            }
            if (attRuleHint) attRuleHint.textContent = 'Closed · Dismissed';
            if (btnConfirmOutcome) {
                btnConfirmOutcome.disabled = true;
                btnConfirmOutcome.classList.remove('is-danger');
                btnConfirmOutcome.style.opacity = '1';
                btnConfirmOutcome.style.cursor = 'default';
                btnConfirmOutcome.style.background = '#dc2626';
                btnConfirmOutcome.style.borderColor = '#b91c1c';
                btnConfirmOutcome.style.color = '#ffffff';
                btnConfirmOutcome.textContent = 'Dismissed';
                btnConfirmOutcome.title = 'Na-dismiss na ang Paanyaya.';
            }

            lockFields();
            if (viewMedWrap) viewMedWrap.style.display = 'none';

        } else if (isEscalated) {
            currentAttLocked = true;
            const caseLabel = complaintNumber ? ` (Case #${complaintNumber})` : '';
            if (lockNotice) {
                lockNotice.style.display = 'flex';
                lockNotice.style.alignItems = 'flex-start';
                lockNotice.style.gap = '10px';
                lockNotice.style.background = '#fffbeb';
                lockNotice.style.border = '1px solid #fde68a';
                lockNotice.style.color = '#854d0e';
                lockNotice.innerHTML = `
                    <span style="font-size: 1.05rem; line-height: 1.2;">&#128274;</span>
                    <div>
                        <strong style="display: block; font-size: 0.84rem; color: #b45309; margin-bottom: 2px;">
                            Nai-akyat sa Pormal na Mediation${caseLabel}
                        </strong>
                        <span style="font-size: 0.78rem; color: #854d0e; line-height: 1.4;">
                            Ang usaping ito ay pormal nang nai-doket sa Mediation at nai-save bilang pormal na reklamo. Naka-lock ang mga field ng Paanyaya.
                        </span>
                    </div>
                `;
            }

            if (attOutcomeSelect) {
                attOutcomeSelect.innerHTML = '<option value="escalate">Escalate to Mediation</option>';
                attOutcomeSelect.value = 'escalate';
                attOutcomeSelect.disabled = true;
            }
            if (attRuleHint) attRuleHint.textContent = 'Escalated · Mediation';
            if (btnConfirmOutcome) {
                btnConfirmOutcome.disabled = true;
                btnConfirmOutcome.classList.remove('is-danger');
                btnConfirmOutcome.style.opacity = '1';
                btnConfirmOutcome.style.cursor = 'default';
                btnConfirmOutcome.style.background = '#6d28d9';
                btnConfirmOutcome.style.borderColor = '#5b21b6';
                btnConfirmOutcome.style.color = '#ffffff';
                btnConfirmOutcome.textContent = 'Escalated to Mediation';
                btnConfirmOutcome.title = 'Nai-akyat na sa Pormal na Mediation.';
            }

            lockFields();
            if (parsedComplaintId > 0 && viewMedWrap && viewMedLink) {
                viewMedWrap.style.display = 'block';
                viewMedLink.href = `../complaints/complaint-details.php?id=${parsedComplaintId}&tab=mediation`;
            } else if (viewMedWrap) {
                viewMedWrap.style.display = 'none';
            }

        } else {
            currentAttLocked = false;
            if (lockNotice) lockNotice.style.display = 'none';
            if (viewMedWrap) viewMedWrap.style.display = 'none';

            if (btnSaveAttOnly) {
                btnSaveAttOnly.disabled = false;
                btnSaveAttOnly.style.display = '';
                btnSaveAttOnly.textContent = 'Save Attendance & Notes';
                btnSaveAttOnly.title = '';
            }

            if (attOutcomeSelect) {
                attOutcomeSelect.disabled = false;
            }

            if (btnConfirmOutcome) {
                btnConfirmOutcome.disabled = false;
                btnConfirmOutcome.style.opacity = '';
                btnConfirmOutcome.style.cursor = '';
                btnConfirmOutcome.style.background = '';
                btnConfirmOutcome.style.borderColor = '';
                btnConfirmOutcome.style.color = '';
                btnConfirmOutcome.title = '';
            }

            compRadios.forEach(r => { r.disabled = false; });
            invRadios.forEach(r => { r.disabled = false; });
            if (attNotesInput) {
                attNotesInput.readOnly = false;
                attNotesInput.style.backgroundColor = '';
                attNotesInput.style.color = '';
            }

            applyAttendanceOutcomeRules({ preserveSelection: false });
        }
    }

    function applyModalStatusBadgeClass(badgeEl, rawStatus, linkedCaseId = 0) {
        if (!badgeEl) return;
        const norm = String(rawStatus || '').trim().toLowerCase();
        const isStDismissed = ['dismissed', 'cancelled', 'failed'].includes(norm);
        const isStResolved = ['resolved', 'settled', 'completed', 'done'].includes(norm);
        const isStEscalated = norm === 'escalated'
            || ['docketed', 'mediation', 'conciliation', 'arbitration', 'cfa issued'].includes(norm)
            || (linkedCaseId > 0 && !isStResolved && !isStDismissed);
        badgeEl.className = isStDismissed
            ? 'status-pill-cancelled'
            : (isStResolved ? 'status-pill-resolved' : (isStEscalated ? 'status-pill-escalated' : 'status-pill-scheduled'));
    }

    document.querySelectorAll('input[name="complainant_attended"], input[name="invitee_attended"]').forEach(radio => {
        radio.addEventListener('change', () => {
            applyAttendanceOutcomeRules({ preserveSelection: false });
        });
    });

    document.querySelectorAll('.btn-open-attendance').forEach(btn => {
        btn.addEventListener('click', async function(e) {
            e.preventDefault();
            e.stopPropagation();
            const paanyayaId = parseInt(this.dataset.paanyayaId || 0, 10);
            const complaintId = parseInt(this.dataset.complaintId || 0, 10);
            const linkedCaseId = parseInt(this.dataset.linkedCaseId || 0, 10);
            const invitee = this.dataset.invitee || 'Inanyayahan';
            const complainant = this.dataset.complainant || 'Nagpaanyaya';
            const date = this.dataset.date || '';
            const time = this.dataset.time || '';
            const status = this.dataset.status || 'Scheduled';
            const attempt = Math.min(2, Math.max(1, parseInt(this.dataset.attempt || 1, 10)));
            const complaintNumber = this.dataset.complaintNumber || '';

            currentAttAttempt = attempt;

            if (attIdInput) attIdInput.value = paanyayaId;
            if (attComplaintIdInput) attComplaintIdInput.value = complaintId;
            if (attScheduleLabel) {
                const schedText = `${date} ${time}`.trim();
                attScheduleLabel.textContent = schedText || '—';
            }
            if (attStatusBadge) {
                attStatusBadge.textContent = status;
                applyModalStatusBadgeClass(attStatusBadge, status, linkedCaseId);
            }
            if (attCompNameDisplay) {
                attCompNameDisplay.textContent = complainant;
                attCompNameDisplay.title = complainant;
            }
            if (attInvNameDisplay) {
                attInvNameDisplay.textContent = invitee;
                attInvNameDisplay.title = invitee;
            }
            if (attCompInitials) attCompInitials.textContent = getPartyInitials(complainant, 'NP');
            if (attInvInitials) attInvInitials.textContent = getPartyInitials(invitee, 'IN');

            if (attAttemptBadge) {
                if (attempt >= 2) {
                    attAttemptBadge.textContent = '2nd Paanyaya';
                    attAttemptBadge.className = 'status-pill-escalated';
                } else {
                    attAttemptBadge.textContent = '1st Paanyaya';
                    attAttemptBadge.className = 'status-pill-scheduled';
                }
            }

            if (p2DateInput) p2DateInput.value = '';
            if (secondPaanyayaPanel) secondPaanyayaPanel.style.display = 'none';

            if (attNotesInput) attNotesInput.value = '';
            setRadio('complainant_attended', 'Present');
            setRadio('invitee_attended', 'Absent');

            applyAttendanceLockState(status, complaintId, attempt, complaintNumber, linkedCaseId);

            if (attModal) attModal.style.display = 'flex';

            try {
                const res = await fetch(`../../../backend/api/paanyaya/attendance.php?paanyaya_id=${paanyayaId}`);
                const json = await res.json();
                if (json.success && json.data) {
                    const row = json.data;
                    const rowAttempt = parseInt(row.attempt_number || attempt, 10);
                    currentAttAttempt = Math.min(2, Math.max(1, rowAttempt));
                    if (attAttemptBadge) {
                        if (currentAttAttempt >= 2) {
                            attAttemptBadge.textContent = '2nd Paanyaya';
                            attAttemptBadge.className = 'status-pill-escalated';
                        } else {
                            attAttemptBadge.textContent = '1st Paanyaya';
                            attAttemptBadge.className = 'status-pill-scheduled';
                        }
                    }

                    if (attNotesInput) {
                        attNotesInput.value = mergeExistingNotes(row.salaysay, row.dialogue_notes);
                    }
                    if (row.complainant_attended) setRadio('complainant_attended', row.complainant_attended);
                    if (row.invitee_attended) setRadio('invitee_attended', row.invitee_attended);

                    const effStatus = row.current_workflow_status || row.status || status;
                    const effComplaintId = parseInt(row.complaint_id || row.escalated_to_complaint_id || complaintId, 10);
                    const effLinkedCaseId = parseInt(row.linked_case_id || linkedCaseId, 10) || 0;
                    const effCompNumber = row.complaint_number || complaintNumber;

                    if (attComplaintIdInput) attComplaintIdInput.value = effComplaintId;
                    if (attStatusBadge) {
                        attStatusBadge.textContent = effStatus;
                        applyModalStatusBadgeClass(attStatusBadge, effStatus, effLinkedCaseId);
                    }

                    applyAttendanceLockState(effStatus, effComplaintId, currentAttAttempt, effCompNumber, effLinkedCaseId);
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

    async function submitAttendanceAction(actionType, confirmMessage = null, triggerBtn = null) {
        if (confirmMessage && !confirm(confirmMessage)) {
            return;
        }

        const paanyayaId = parseInt(attIdInput.value, 10);
        if (!paanyayaId) {
            agapAlert('Invalid Paanyaya record.', 'error');
            return;
        }

        const notesText = attNotesInput?.value.trim() || '';
        const payload = {
            paanyaya_id: paanyayaId,
            complaint_id: parseInt(attComplaintIdInput.value || 0, 10),
            complainant_attended: getRadio('complainant_attended'),
            invitee_attended: getRadio('invitee_attended'),
            notes: notesText,
            salaysay: notesText,
            dialogue_notes: notesText,
            action: actionType,
            p2_date: p2DateInput?.value || '',
            p2_time: p2TimeInput?.value || '14:00'
        };

        const activeBtn = triggerBtn || btnConfirmOutcome || btnSaveAttOnly;
        const originalText = activeBtn ? activeBtn.textContent : '';
        if (btnSaveAttOnly) btnSaveAttOnly.disabled = true;
        if (btnConfirmOutcome) btnConfirmOutcome.disabled = true;
        if (activeBtn) activeBtn.textContent = 'Saving...';

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
                if (btnSaveAttOnly) btnSaveAttOnly.disabled = false;
                if (btnConfirmOutcome) btnConfirmOutcome.disabled = false;
                if (activeBtn) activeBtn.textContent = originalText;
                return;
            }

            closeAttModal();

            if (actionType === 'escalate' && data.case_number) {
                const navMsg = `Matagumpay na nai-akyat ang Paanyaya sa Pormal na Mediation (Stage 1)!\nCase Number: ${data.case_number}\n\nNailipat ang Notes at tala ng Pagdalo ng mga panig.`;
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
            } else if (actionType === 'dismiss') {
                const msg = data.message || 'Opisyal nang na-dismiss ang usapin.';
                if (typeof window.showSuccessPopup === 'function') {
                    await window.showSuccessPopup(msg, 'Dismissed');
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
            if (btnSaveAttOnly) btnSaveAttOnly.disabled = false;
            if (btnConfirmOutcome) btnConfirmOutcome.disabled = false;
            if (activeBtn) activeBtn.textContent = originalText;
        }
    }

    async function handleEscalateOutcome() {
        const paanyayaId = parseInt(attIdInput?.value, 10);
        if (!paanyayaId) {
            agapAlert('Invalid Paanyaya record.', 'error');
            return;
        }

        const linkedComplaintId = parseInt(attComplaintIdInput?.value || 0, 10);
        if (linkedComplaintId > 0) {
            submitAttendanceAction(
                'escalate',
                'Sigurado ka bang iaakyat na ang Paanyaya na ito sa Pormal na Mediation?',
                btnConfirmOutcome
            );
            return;
        }

        const confirmed = confirm(
            'Magpatuloy sa Formal Complaint Details?\n\n' +
            'Isi-save muna ang attendance at notes ng Paanyaya. Pagkatapos, ilagay ang mga kinakailangang detalye para sa pormal na reklamo tulad ng category, case type, incident details, at relief na hinihingi. Hindi pa ito mai-dodocket hangga\'t hindi naisusumite ang form.'
        );
        if (!confirmed) return;

        const originalText = btnConfirmOutcome ? btnConfirmOutcome.textContent : '';
        if (btnSaveAttOnly) btnSaveAttOnly.disabled = true;
        if (btnConfirmOutcome) {
            btnConfirmOutcome.disabled = true;
            btnConfirmOutcome.textContent = 'Saving Paanyaya...';
        }

        const notesText = attNotesInput?.value.trim() || '';

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
                    complaint_id: linkedComplaintId,
                    complainant_attended: getRadio('complainant_attended'),
                    invitee_attended: getRadio('invitee_attended'),
                    notes: notesText,
                    salaysay: notesText,
                    dialogue_notes: notesText,
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
            if (btnSaveAttOnly) btnSaveAttOnly.disabled = false;
            if (btnConfirmOutcome) {
                btnConfirmOutcome.disabled = false;
                btnConfirmOutcome.textContent = originalText;
            }
        }
    }

    if (attOutcomeSelect) {
        attOutcomeSelect.addEventListener('change', syncOutcomeUi);
    }

    if (btnConfirmOutcome) {
        btnConfirmOutcome.addEventListener('click', () => {
            const outcome = attOutcomeSelect ? attOutcomeSelect.value : 'schedule_second_paanyaya';
            if (outcome === 'resolve') {
                submitAttendanceAction(
                    'resolve',
                    'Sigurado ka bang naayos na ang sigalot at mamarkahang "Settled" ang Paanyaya na ito?',
                    btnConfirmOutcome
                );
            } else if (outcome === 'dismiss') {
                submitAttendanceAction(
                    'dismiss',
                    'Sigurado ka bang idi-dismiss ang usaping ito dahil hindi nakadalo ang Nagpaanyaya?',
                    btnConfirmOutcome
                );
            } else if (outcome === 'schedule_second_paanyaya') {
                const p2Date = p2DateInput?.value || '';
                if (!p2Date) {
                    agapAlert('Mangyaring pumili ng petsa para sa 2nd Paanyaya.', 'warning');
                    return;
                }
                submitAttendanceAction(
                    'schedule_second_paanyaya',
                    `Sigurado ka bang nais nang ilabas ang Ikalawang Paanyaya (2nd Paanyaya) sa darating na ${p2Date}?`,
                    btnConfirmOutcome
                );
            } else if (outcome === 'escalate') {
                handleEscalateOutcome();
            }
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
                emptyRow.innerHTML = '<td colspan="6" style="text-align:center; color:#64748b; padding:36px 20px;"><div style="font-weight:700; color:#0f172a;">No matching Paanyaya invitations</div><p style="margin:4px 0 0; font-size:0.84rem;">Try clearing your search keyword.</p></td>';
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

    let paanyayaSortKey = 'date';
    let paanyayaSortDir = 'desc';

    const sortPaanyayaHeaders = Array.from(document.querySelectorAll('.paanyaya-table thead th.sortable-th[data-sort-key]'));
    sortPaanyayaHeaders.forEach(th => {
        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (!key) return;
            if (paanyayaSortKey === key) {
                paanyayaSortDir = paanyayaSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                paanyayaSortKey = key;
                paanyayaSortDir = key === 'date' ? 'desc' : 'asc';
            }

            sortPaanyayaHeaders.forEach(h => {
                h.classList.remove('sort-active-asc', 'sort-active-desc');
                const ind = h.querySelector('.sort-indicator');
                if (ind) ind.textContent = '⇅';
            });
            th.classList.add(paanyayaSortDir === 'asc' ? 'sort-active-asc' : 'sort-active-desc');
            const activeInd = th.querySelector('.sort-indicator');
            if (activeInd) activeInd.textContent = paanyayaSortDir === 'asc' ? '▲' : '▼';

            if (paanyayaTbody && paanyayaRows.length) {
                const attrMap = {
                    ref: 'sortRef',
                    complainant: 'sortComplainant',
                    respondent: 'sortRespondent',
                    date: 'sortDate',
                    status: 'sortStatus'
                };
                const attr = attrMap[paanyayaSortKey] || 'sortDate';
                paanyayaRows.sort((a, b) => {
                    const av = String(a.dataset[attr] || '');
                    const bv = String(b.dataset[attr] || '');
                    const cmp = av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
                    return paanyayaSortDir === 'asc' ? cmp : -cmp;
                });
                const frag = document.createDocumentFragment();
                paanyayaRows.forEach(row => {
                    const dropdownRow = getDropdownRowFor(row);
                    frag.appendChild(row);
                    if (dropdownRow) frag.appendChild(dropdownRow);
                });
                const filterEmptyRow = document.getElementById('paanyayaFilterEmptyRow');
                paanyayaTbody.appendChild(frag);
                if (filterEmptyRow) paanyayaTbody.appendChild(filterEmptyRow);
                paanyayaCurrentPage = 1;
                renderPaanyayaPage();
            }
        });
    });

    if (paanyayaRows.length) {
        renderPaanyayaPage();
    }
});
</script>

<?php include '../../layouts/footer.php'; ?>
