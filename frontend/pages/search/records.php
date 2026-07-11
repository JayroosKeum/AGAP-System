<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array((int) $_SESSION['role_id'], [1, 2, 3], true)) die('Access Denied');
include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/search.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/search.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    
    <main class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        
        <div class="page-header">
            <div>
                <h1>Records Search</h1>
                <p>Find case files, complaint records, and recurring parties.</p>
            </div>
        </div>
        
        <section class="search-card">
            <form id="recordsSearchForm" class="search-form">
                <div class="search-field wide">
                    <label for="searchQuery">Keyword</label>
                    <input id="searchQuery" name="q" type="search" placeholder="Case no., complaint no., title, narrative, or resident name">
                </div>
                
                <div class="search-field">
                    <label for="searchStatus">Status</label>
                    <select id="searchStatus" name="status">
                        <option value="">All statuses</option>
                        <option>Docketed</option>
                        <option>Mediation</option>
                        <option>Conciliation</option>
                        <option>Arbitration</option>
                        <option>Settled</option>
                        <option>Dismissed</option>
                        <option>CFA Issued</option>
                        <option>Archived</option>
                    </select>
                </div>
                
                <div class="search-field">
                    <label for="searchType">Case type</label>
                    <select id="searchType" name="case_type">
                        <option value="">All types</option>
                        <option>Civil</option>
                        <option>Criminal</option>
                    </select>
                </div>
                
                <div class="search-field">
                    <label for="searchFrom">Incident date from</label>
                    <input id="searchFrom" name="date_from" type="date">
                </div>
                
                <div class="search-field">
                    <label for="searchTo">Incident date to</label>
                    <input id="searchTo" name="date_to" type="date">
                </div>
                
                <div class="search-actions">
                    <button class="btn-create" type="submit">Search records</button>
                    <button class="btn-secondary" id="clearSearch" type="button">Clear</button>
                </div>
            </form>
        </section>
        
        <p id="resultSummary" class="result-summary" aria-live="polite"></p>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Case</th>
                        <th>Complaint</th>
                        <th>Category</th>
                        <th>Parties</th>
                        <th>Status</th>
                        <th>Incident date</th>
                        <th>History</th>
                    </tr>
                </thead>
                <tbody id="searchResults">
                    <tr>
                        <td colspan="7">Use the filters above to search records.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </main>
</div>

<script src="../../assets/js/search.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/search.js'); ?>"></script>
<?php include '../../layouts/footer.php'; ?>