<?php

$path = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($path);

// 1. Alias por.return_id AS id in getStageAttempts
$targetGetAttempts = "SELECT por.*,";
$replaceGetAttempts = "SELECT por.*, por.return_id AS id,";
$content = str_replace($targetGetAttempts, $replaceGetAttempts, $content);

// 2. Refined checkSummonsPrerequisite
$targetPrereqStart = "public function checkSummonsPrerequisite(int \$complaintId): array";
$posPrereqStart = strpos($content, $targetPrereqStart);
$posPrereqEnd = strpos($content, "public function getTwoStagesData(int \$complaintId): array", $posPrereqStart);

if ($posPrereqStart !== false && $posPrereqEnd !== false) {
    $newPrereqCode = <<<'PHP'
public function checkSummonsPrerequisite(int $complaintId): array
    {
        $stages = $this->conn->prepare("SELECT * FROM paanyaya_stages WHERE complaint_id = ?");
        $stages->execute([$complaintId]);
        $rows = $stages->fetchAll(PDO::FETCH_ASSOC);

        $stage1 = null;
        $stage2 = null;
        foreach ($rows as $r) {
            if ($r['stage_type'] === self::STAGE_1) $stage1 = $r;
            if ($r['stage_type'] === self::STAGE_2) $stage2 = $r;
        }

        // If Paanyaya 1 doesn't exist or is Not Started
        if (!$stage1 || $stage1['status'] === 'Not Started') {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_1_not_started',
                'message' => 'Complete the required Paanyaya process before issuing the first formal summons.'
            ];
        }

        // Check if explicitly approved for summons by authorized review at either stage
        if ($stage1['review_status'] === 'Approved for Summons' || ($stage2 && $stage2['review_status'] === 'Approved for Summons')) {
            return [
                'allowed' => true,
                'message' => 'Formal summons unlocked via authorized Lupon review approval.'
            ];
        }

        // If Paanyaya 1 was reviewed and approved for Paanyaya 2, or if Paanyaya 2 is already underway/completed
        $isPaanyaya2Required = ($stage1['review_status'] === 'Approved for Paanyaya 2') || ($stage2 && $stage2['status'] !== 'Not Started');

        if ($isPaanyaya2Required) {
            if (!$stage2 || $stage2['status'] === 'Not Started') {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_not_started',
                    'message' => 'Complete the required Paanyaya 2 process before issuing the first formal summons.'
                ];
            }

            $countStmt = $this->conn->prepare("
                SELECT COUNT(*) AS total_count,
                       SUM(CASE WHEN delivery_outcome = 'Successfully delivered' THEN 1 ELSE 0 END) AS delivered_count
                FROM paanyaya_officer_returns
                WHERE paanyaya_stage_id = ?
            ");
            $countStmt->execute([(int)$stage2['id']]);
            $stats2 = $countStmt->fetch(PDO::FETCH_ASSOC);
            $isDelivered2 = (int)($stats2['delivered_count'] ?? 0) > 0 || $stage2['status'] === 'Successfully Delivered';

            if ($stage2['review_status'] === 'Approved for Summons') {
                return [
                    'allowed' => true,
                    'message' => 'Formal summons unlocked via authorized Lupon review approval.'
                ];
            }

            if ($stage2['status'] === 'In Progress' && !$isDelivered2) {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_in_progress',
                    'message' => 'Complete the required Paanyaya 2 process before issuing the first formal summons.'
                ];
            }

            if ($stage2['status'] === 'Attempts Exhausted – For Review' && !$isDelivered2 && $stage2['review_status'] === 'None') {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_exhausted_pending_review',
                    'message' => 'All 6 Paanyaya 2 delivery attempts exhausted. Authorized review is required before advancing.'
                ];
            }

            if ($isDelivered2 || $stage2['status'] === 'Successfully Delivered' || $stage2['status'] === 'Completed') {
                return [
                    'allowed' => true,
                    'message' => 'Paanyaya 2 successfully completed. Formal summons unlocked.'
                ];
            }

            return [
                'allowed' => false,
                'reason' => 'paanyaya_2_incomplete',
                'message' => 'Complete the required Paanyaya 2 process before issuing the first formal summons.'
            ];
        }

        // Otherwise evaluate Paanyaya 1
        $countStmt = $this->conn->prepare("
            SELECT COUNT(*) AS total_count,
                   SUM(CASE WHEN delivery_outcome = 'Successfully delivered' THEN 1 ELSE 0 END) AS delivered_count
            FROM paanyaya_officer_returns
            WHERE paanyaya_stage_id = ?
        ");
        $countStmt->execute([(int)$stage1['id']]);
        $stats1 = $countStmt->fetch(PDO::FETCH_ASSOC);
        $isDelivered1 = (int)($stats1['delivered_count'] ?? 0) > 0 || $stage1['status'] === 'Successfully Delivered';

        if ($stage1['status'] === 'In Progress' && !$isDelivered1) {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_1_in_progress',
                'message' => 'Complete the required Paanyaya process before issuing the first formal summons.'
            ];
        }

        if ($stage1['status'] === 'Attempts Exhausted – For Review' && !$isDelivered1 && $stage1['review_status'] === 'None') {
            return [
                'allowed' => false,
                'reason' => 'paanyaya_1_exhausted_pending_review',
                'message' => 'All 6 Paanyaya 1 delivery attempts exhausted. Authorized review is required before advancing.'
            ];
        }

        if ($isDelivered1 || $stage1['status'] === 'Successfully Delivered' || $stage1['status'] === 'Completed') {
            return [
                'allowed' => true,
                'message' => 'Paanyaya 1 successfully completed. Formal summons unlocked.'
            ];
        }

        return [
            'allowed' => false,
            'reason' => 'paanyaya_incomplete',
            'message' => 'Complete the required Paanyaya process before issuing the first formal summons.'
        ];
    }

    /**
     * Gets complete Paanyaya workflow status for both stages.
     */
    
PHP;
    $content = substr($content, 0, $posPrereqStart) . $newPrereqCode . substr($content, $posPrereqEnd);
    echo "Updated checkSummonsPrerequisite in Paanyaya.php\n";
}

// 3. In reviewStage, update stage status to Completed when approved
$targetReviewStatus = "status = IF(? = 'Case Closed', 'Completed', status),";
$replaceReviewStatus = "status = 'Completed',";
$content = str_replace($targetReviewStatus, $replaceReviewStatus, $content);
echo "Updated reviewStage status update in Paanyaya.php\n";

file_put_contents($path, $content);
echo "Paanyaya.php updated successfully!\n";
