<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/AuditService.php';

class ServerDashboardController
{
    private PDO $conn;
    private AuditService $audit;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->audit = new AuditService();
    }

    /**
     * Retrieve complete field dispatch payload for Process Server ("Kuya").
     */
    public function getDashboardData(array $params = []): array
    {
        $today = date('Y-m-d');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $purokFilter = trim((string) ($params['purok'] ?? 'all'));
        $statusFilter = trim((string) ($params['status'] ?? 'pending'));

        // 1. Dispatch Summary KPIs
        // a. Pending Delivery (Target service date is today/tomorrow or active pending delivery)
        $pendingStmt = $this->conn->prepare("
            SELECT COUNT(*) 
            FROM summon_deliveries sd
            JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE sd.delivery_status = 'Pending'
              AND (DATE(h.hearing_date) IN (?, ?) OR DATE(sd.created_at) >= DATE_SUB(?, INTERVAL 7 DAY))
        ");
        $pendingStmt->execute([$today, $tomorrow, $today]);
        $pendingDelivery = (int) $pendingStmt->fetchColumn();

        // b. Served Today
        $servedStmt = $this->conn->prepare("
            SELECT COUNT(*)
            FROM summon_deliveries sd
            WHERE sd.delivery_status LIKE 'Served%'
              AND (DATE(sd.served_at) = ? OR DATE(sd.updated_at) = ?)
        ");
        $servedStmt->execute([$today, $today]);
        $servedToday = (int) $servedStmt->fetchColumn();

        // c. Unserved / Need Follow-up (Party moved, address not found, refused, etc.)
        $unservedStmt = $this->conn->query("
            SELECT COUNT(*)
            FROM summon_deliveries sd
            WHERE sd.delivery_status IN ('Unserved', 'Attempted', 'Refused')
               OR (sd.unserved_reason IS NOT NULL AND TRIM(sd.unserved_reason) != '')
        ");
        $unservedFollowup = (int) $unservedStmt->fetchColumn();

        // 2. Distinct Purok List for Route Filter Dropdown
        $purokStmt = $this->conn->query("
            SELECT DISTINCT purok
            FROM residents
            WHERE purok IS NOT NULL AND TRIM(purok) != ''
            ORDER BY purok ASC
        ");
        $puroks = $purokStmt->fetchAll(PDO::FETCH_COLUMN);

        // 3. Dispatch Tasks Query with Purok & Status filters
        $where = ["h.status NOT IN ('Cancelled', 'Office Cancelled')"];
        $queryParams = [];

        if ($statusFilter === 'served') {
            $where[] = "(sd.delivery_status LIKE 'Served%')";
        } elseif ($statusFilter === 'unserved') {
            $where[] = "(sd.delivery_status IN ('Unserved', 'Attempted', 'Refused') OR (sd.unserved_reason IS NOT NULL AND TRIM(sd.unserved_reason) != ''))";
        } elseif ($statusFilter === 'all') {
            // No status constraint
        } else {
            // Default: Pending deliveries
            $where[] = "(sd.delivery_status = 'Pending')";
        }

        if ($purokFilter !== 'all' && $purokFilter !== '') {
            $where[] = "r.purok = ?";
            $queryParams[] = $purokFilter;
        }

        $whereClause = implode(' AND ', $where);

        $sql = "
            SELECT sd.delivery_id, sd.case_id, sd.hearing_id, sd.party_type, sd.delivery_status,
                   sd.attempt_number, sd.service_method, sd.served_at, sd.unserved_reason,
                   sd.failure_notes, sd.remarks, sd.proof_file_path, sd.form_type,
                   c.case_number, c.case_status,
                   h.hearing_type, h.hearing_date, h.venue,
                   r.resident_id,
                   TRIM(CONCAT_WS(' ', r.first_name, r.middle_name, r.last_name, r.suffix)) AS full_name,
                   r.contact_no, r.purok, r.house_street, r.address, r.gender,
                   DATE(h.hearing_date) AS target_date,
                   DATEDIFF(DATE(h.hearing_date), CURDATE()) AS days_until_hearing
            FROM summon_deliveries sd
            JOIN residents r ON r.resident_id = sd.resident_id
            JOIN cases c ON c.case_id = sd.case_id
            JOIN hearings h ON h.hearing_id = sd.hearing_id
            WHERE $whereClause
            ORDER BY 
                CASE WHEN sd.delivery_status = 'Pending' THEN 0 ELSE 1 END,
                h.hearing_date ASC,
                r.purok ASC,
                sd.delivery_id DESC
            LIMIT 100
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($queryParams);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'kpis' => [
                'pending_delivery' => $pendingDelivery,
                'served_today' => $servedToday,
                'unserved_followup' => $unservedFollowup
            ],
            'puroks' => $puroks,
            'active_purok' => $purokFilter,
            'active_status' => $statusFilter,
            'tasks' => $tasks
        ];
    }

    /**
     * 1-Tap Service Logger with photo/camera proof upload support.
     * Actions:
     * - 'served': Mark summons as served (Personal / Substituted)
     * - 'refused': Mark summons as Refused to Sign
     * - 'unserved': Mark summons as Unserved (Not Found, Moved Out, etc.)
     */
    public function logServiceAction(array $data, ?array $file = null): array
    {
        $deliveryId = filter_var($data['delivery_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$deliveryId) {
            return ['success' => false, 'message' => 'Invalid delivery ID.'];
        }

        $stmt = $this->conn->prepare("
            SELECT sd.*, c.case_number, h.hearing_date, r.first_name, r.last_name
            FROM summon_deliveries sd
            JOIN cases c ON c.case_id = sd.case_id
            JOIN hearings h ON h.hearing_id = sd.hearing_id
            JOIN residents r ON r.resident_id = sd.resident_id
            WHERE sd.delivery_id = ?
        ");
        $stmt->execute([$deliveryId]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) {
            return ['success' => false, 'message' => 'Delivery task record not found.'];
        }

        $action = strtolower(trim((string) ($data['action'] ?? '')));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $userId = (int) ($data['user_id'] ?? ($_SESSION['user_id'] ?? 6));
        $now = date('Y-m-d H:i:s');

        // Handle Photo Upload (Proof of Service: house gate, recipient photo)
        $proofFilePath = $delivery['proof_file_path'] ?? null;
        if ($file && !empty($file['tmp_name']) && $file['error'] === UPLOAD_ERR_OK) {
            $proofFilePath = $this->saveProofPhoto($deliveryId, $file);
        }

        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        if ($recipientName === '') {
            $recipientName = trim($delivery['first_name'] . ' ' . $delivery['last_name']);
        }

        if ($action === 'served') {
            $serviceMethod = trim((string) ($data['service_method'] ?? 'Personal Notice'));
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = 'Served Personal',
                    service_method = ?,
                    served_at = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    recipient_name = ?,
                    remarks = ?,
                    proof_file_path = COALESCE(?, proof_file_path),
                    unserved_reason = NULL,
                    updated_at = NOW()
                WHERE delivery_id = ?
            ");
            $upd->execute([
                $serviceMethod,
                $now,
                $userId,
                $userId,
                $recipientName,
                $remarks ?: 'Personal service delivered and acknowledged.',
                $proofFilePath,
                $deliveryId
            ]);

            $this->audit->log(
                $userId,
                'SUMMON_SERVED',
                'Summons',
                "Summons delivery #{$deliveryId} marked as SERVED for Case {$delivery['case_number']}"
            );

            return [
                'success' => true,
                'message' => 'Matagumpay na naitala ang paghahatid (Served)!',
                'delivery_status' => 'Served Personal',
                'proof_file_path' => $proofFilePath
            ];
        }

        if ($action === 'refused') {
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = 'Served Refused',
                    service_method = 'Tendered (Refused)',
                    served_at = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    recipient_name = ?,
                    unserved_reason = 'Refused to Sign',
                    failure_notes = ?,
                    remarks = ?,
                    proof_file_path = COALESCE(?, proof_file_path),
                    updated_at = NOW()
                WHERE delivery_id = ?
            ");
            $upd->execute([
                $now,
                $userId,
                $userId,
                $recipientName,
                'Party refused to accept or sign the summons form in person.',
                $remarks ?: 'Tumanggi lumagda ang panig ngunit iniwan ang kopya.',
                $proofFilePath,
                $deliveryId
            ]);

            $this->audit->log(
                $userId,
                'SUMMON_REFUSED',
                'Summons',
                "Summons delivery #{$deliveryId} recorded as REFUSED TO SIGN for Case {$delivery['case_number']}"
            );

            return [
                'success' => true,
                'message' => 'Naitala ang pagtanggi lumagda (Refused to Sign).',
                'delivery_status' => 'Served Refused',
                'proof_file_path' => $proofFilePath
            ];
        }

        if ($action === 'unserved') {
            $reason = trim((string) ($data['unserved_reason'] ?? 'Address Not Found'));
            $upd = $this->conn->prepare("
                UPDATE summon_deliveries
                SET delivery_status = 'Unserved',
                    unserved_reason = ?,
                    failure_notes = ?,
                    remarks = ?,
                    served_by = ?,
                    served_by_user_id = ?,
                    proof_file_path = COALESCE(?, proof_file_path),
                    updated_at = NOW()
                WHERE delivery_id = ?
            ");
            $upd->execute([
                $reason,
                $remarks ?: "Unserved attempt: {$reason}",
                $remarks,
                $userId,
                $userId,
                $proofFilePath,
                $deliveryId
            ]);

            $this->audit->log(
                $userId,
                'SUMMON_UNSERVED',
                'Summons',
                "Summons delivery #{$deliveryId} marked as UNSERVED ({$reason}) for Case {$delivery['case_number']}"
            );

            return [
                'success' => true,
                'message' => 'Naitala bilang Unserved / Need Follow-up.',
                'delivery_status' => 'Unserved',
                'proof_file_path' => $proofFilePath
            ];
        }

        return ['success' => false, 'message' => "Hindi kilalang service action: {$action}"];
    }

    /**
     * Save uploaded proof photo to storage/proofs.
     */
    private function saveProofPhoto(int $deliveryId, array $file): ?string
    {
        $uploadDir = __DIR__ . '/../../storage/proofs';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $fileName = sprintf('proof_delivery_%d_%s_%s.%s', $deliveryId, date('Ymd_His'), bin2hex(random_bytes(4)), $ext);
        $targetPath = $uploadDir . '/' . $fileName;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            return 'storage/proofs/' . $fileName;
        }

        return null;
    }
}
