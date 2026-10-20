<?php
$path = __DIR__ . '/../backend/models/Paanyaya.php';
$content = file_get_contents($path);

$targetStart = "public function checkSummonsPrerequisite(int \$complaintId): array";
$posStart = strpos($content, $targetStart);
$posEnd = strpos($content, "public function getTwoStagesData(int \$complaintId): array", $posStart);

$newMethod = <<<'PHP'
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
                'reason' => 'paanyaya_not_started',
                'message' => 'Complete the required Paanyaya preliminary delivery attempts before issuing the first formal summons.'
            ];
        }

        // Check attempts and outcomes for Stage 1
        $countStmt = $this->conn->prepare("
            SELECT COUNT(*) AS total_count,
                   SUM(CASE WHEN delivery_outcome = 'Successfully delivered' THEN 1 ELSE 0 END) AS delivered_count
            FROM paanyaya_officer_returns
            WHERE paanyaya_stage_id = ?
        ");
        $countStmt->execute([(int)$stage1['id']]);
        $stats1 = $countStmt->fetch(PDO::FETCH_ASSOC);
        $count1 = (int)($stats1['total_count'] ?? 0);
        $isDelivered1 = ((int)($stats1['delivered_count'] ?? 0) > 0) || ($stage1['status'] === 'Successfully Delivered');
        $isExhausted1 = ($count1 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered1) || ($stage1['status'] === 'Attempts Exhausted – For Review');

        // Check if explicitly approved by Lupon review
        if ($stage1['review_status'] === 'Approved for Summons' || ($stage2 && $stage2['review_status'] === 'Approved for Summons')) {
            return [
                'allowed' => true,
                'message' => 'Formal summons unlocked via authorized Lupon review approval.'
            ];
        }

        // If Paanyaya 2 was initiated or required
        $isPaanyaya2Active = ($stage1['review_status'] === 'Approved for Paanyaya 2') || ($stage2 && in_array($stage2['status'], ['In Progress', 'Successfully Delivered', 'Attempts Exhausted – For Review', 'Completed'], true));

        if ($isPaanyaya2Active) {
            if (!$stage2 || $stage2['status'] === 'Not Started') {
                return [
                    'allowed' => false,
                    'reason' => 'paanyaya_2_not_started',
                    'message' => 'Complete the required Paanyaya 2 delivery attempts before issuing the first formal summons.'
                ];
            }

            $countStmt->execute([(int)$stage2['id']]);
            $stats2 = $countStmt->fetch(PDO::FETCH_ASSOC);
            $count2 = (int)($stats2['total_count'] ?? 0);
            $isDelivered2 = ((int)($stats2['delivered_count'] ?? 0) > 0) || ($stage2['status'] === 'Successfully Delivered');
            $isExhausted2 = ($count2 >= self::MAX_STAGE_ATTEMPTS && !$isDelivered2) || ($stage2['status'] === 'Attempts Exhausted – For Review');

            // 1. If Paanyaya 2 was served -> UNLOCKED!
            if ($isDelivered2 || $stage2['status'] === 'Completed') {
                return [
                    'allowed' => true,
                    'message' => 'Paanyaya 2 successfully served. Formal summons unlocked.'
                ];
            }

            // 2. If 6 attempts failed on Paanyaya 2 -> UNLOCKED!
            if ($isExhausted2 || $count2 >= self::MAX_STAGE_ATTEMPTS) {
                return [
                    'allowed' => true,
                    'message' => '6 Paanyaya 2 delivery attempts completed without service. Formal summons unlocked to proceed with formal docketing.'
                ];
            }

            // Still in progress (< 6 attempts and unserved)
            return [
                'allowed' => false,
                'reason' => 'paanyaya_2_in_progress',
                'message' => 'Paanyaya 2 delivery attempt ' . $count2 . ' of 6 recorded. Must be served or complete 6 failed attempts before issuing summons.'
            ];
        }

        // 1. If Paanyaya 1 was served -> UNLOCKED!
        if ($isDelivered1 || $stage1['status'] === 'Completed') {
            return [
                'allowed' => true,
                'message' => 'Paanyaya 1 successfully served. Formal summons unlocked.'
            ];
        }

        // 2. If 6 attempts failed on Paanyaya 1 -> UNLOCKED!
        if ($isExhausted1 || $count1 >= self::MAX_STAGE_ATTEMPTS) {
            return [
                'allowed' => true,
                'message' => '6 Paanyaya delivery attempts completed without service. Formal summons unlocked to proceed with formal docketing.'
            ];
        }

        // Paanyaya 1 is still in progress (< 6 attempts and unserved)
        return [
            'allowed' => false,
            'reason' => 'paanyaya_in_progress',
            'message' => 'Paanyaya delivery attempt ' . $count1 . ' of 6 recorded. Must be served or complete 6 failed attempts before issuing formal summons.'
        ];
    }

    /**
     * Gets complete Paanyaya workflow status for both stages.
     */
    
PHP;

$content = substr($content, 0, $posStart) . $newMethod . substr($content, $posEnd);
file_put_contents($path, $content);
echo "Updated checkSummonsPrerequisite in Paanyaya.php\n";
