<?php
session_start();
if (!isset($_SESSION['role_id']) || !in_array($_SESSION['role_id'], [1, 2, 3])) die('Access Denied');
include '../../layouts/header.php';
?>

<link rel="stylesheet" href="../../assets/css/dashboard.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/dashboard.css'); ?>">
<link rel="stylesheet" href="../../assets/css/documents.css?v=<?php echo filemtime(__DIR__ . '/../../assets/css/documents.css'); ?>">

<div class="dashboard-layout">
    <?php include '../../layouts/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include '../../layouts/navbar.php'; ?>
        
        <div class="page-header">
            <div>
                <h1>KP Documents</h1>
                <p>Generate official Katarungang Pambarangay forms from docketed cases.</p>
            </div>
        </div>
        
        <section class="document-card">
            <h2>KP Form 9 - Summons</h2>
            <p>Prepare a summons for a party to appear before the Lupong Tagapamayapa.</p>
            
            <div class="form-group">
                <label for="documentCaseId">Case</label>
                <select id="documentCaseId">
                    <option value="">Select a case</option>
                </select>
            </div>
            
            <button type="button" class="btn-create" onclick="generateSummons()">Generate Summons</button>
        </section>
    </div>
</div>

<script src="../../assets/js/documents.js?v=<?php echo filemtime(__DIR__ . '/../../assets/js/documents.js'); ?>"></script>

<?php include '../../layouts/footer.php'; ?>