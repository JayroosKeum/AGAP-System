<?php
$file = __DIR__ . '/../backend/models/ProofOfService.php';
$content = file_get_contents($file);

$target = '$stmt->execute();' . "\r\n" . '        return $stmt->fetchAll(PDO::FETCH_ASSOC);';
if (strpos($content, $target) === false) {
    $target = '$stmt->execute();' . "\n" . '        return $stmt->fetchAll(PDO::FETCH_ASSOC);';
}

$replacement = <<<'CODE'
        $stmt->execute();
        $formalCases = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Also query pending delivery for Paanyaya preliminary invitation stages
        $paaStmt = $this->conn->prepare("
            SELECT 
                COALESCE(c.case_id, co.complaint_id) AS case_id,
                COALESCE(c.case_number, co.complaint_number) AS case_number,
                COALESCE(c.case_status, co.status) AS case_status,
                co.complaint_id,
                co.complaint_number,
                co.complaint_title,
                ps.id AS hearing_id,
                CASE 
                    WHEN ps.invitation_date IS NOT NULL AND ps.invitation_time IS NOT NULL 
                    THEN CONCAT(ps.invitation_date, ' ', ps.invitation_time)
                    WHEN ps.invitation_date IS NOT NULL 
                    THEN CONCAT(ps.invitation_date, ' 14:00:00')
                    ELSE co.created_at
                END AS hearing_date,
                ps.stage_type AS hearing_type,
                ps.status AS hearing_status,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Complainant'
                ) AS complainant_names,
                (
                    SELECT GROUP_CONCAT(TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name)) SEPARATOR ', ')
                    FROM complaint_parties cp
                    INNER JOIN residents r ON r.resident_id = cp.resident_id
                    WHERE cp.complaint_id = co.complaint_id AND cp.party_type = 'Respondent'
                ) AS respondent_names,
                'Served' AS complainant_service_status,
                COALESCE(
                    (
                        SELECT CASE 
                            WHEN por.delivery_outcome = 'Successfully delivered' THEN 'Served'
                            ELSE CONCAT('Attempt ', por.attempt_number, ' (Unserved)')
                        END
                        FROM paanyaya_officer_returns por
                        WHERE por.paanyaya_stage_id = ps.id
                        ORDER BY por.attempt_number DESC, por.return_id DESC LIMIT 1
                    ),
                    'Pending'
                ) AS respondent_service_status,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND por.delivery_outcome != 'Successfully delivered'
                ) AS respondent_failed_attempts,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND por.delivery_outcome = 'Successfully delivered'
                ) AS respondent_served_attempts,
                (
                    SELECT COUNT(*)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id
                ) AS respondent_total_attempts,
                (
                    SELECT MAX(por.service_datetime)
                    FROM paanyaya_officer_returns por
                    WHERE por.paanyaya_stage_id = ps.id AND DATE(por.service_datetime) = CURRENT_DATE()
                ) AS served_today_date,
                1 AS is_paanyaya,
                ps.stage_type AS paanyaya_stage_type,
                ps.status AS paanyaya_stage_status
            FROM paanyaya_stages ps
            INNER JOIN complaints co ON co.complaint_id = ps.complaint_id
            LEFT JOIN cases c ON c.complaint_id = co.complaint_id
            WHERE ps.status IN ('In Progress', 'Attempts Exhausted – For Review', 'Not Started', 'Successfully Delivered')
              AND NOT EXISTS (
                  SELECT 1 FROM hearings h WHERE h.case_id = c.case_id AND h.status = 'Scheduled'
              )
              AND (
                  (ps.stage_type = 'Paanyaya 2' AND ps.status != 'Not Started')
                  OR (ps.stage_type = 'Paanyaya 1' AND NOT EXISTS (
                      SELECT 1 FROM paanyaya_stages ps2 
                      WHERE ps2.complaint_id = ps.complaint_id 
                        AND ps2.stage_type = 'Paanyaya 2' 
                        AND ps2.status != 'Not Started'
                  ))
              )
            ORDER BY ps.updated_at DESC
        ");
        $paaStmt->execute();
        $paaCases = $paaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Combined list of active cases for Proof of Service
        return array_merge($paaCases, $formalCases);
CODE;

if (strpos($content, '$paaCases = $paaStmt->fetchAll') === false) {
    $content = str_replace($target, $replacement, $content);
    file_put_contents($file, $content);
    echo "ProofOfService.php updated.\n";
} else {
    echo "Already updated.\n";
}
