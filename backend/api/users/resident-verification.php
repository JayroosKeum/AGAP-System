<?php

session_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/rbac.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/NotificationService.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$db = (new Database())->connect();
agapRequireApiRoles(['Administrator', 'Secretary', 'Lupon Clerk'], $db);
$actorUserId = agapSessionUserId();

function findMatchedResidentForUser(PDO $db, array $user): ?array
{
    $email = trim((string) ($user['email'] ?? ''));
    if ($email !== '') {
        $stmt = $db->prepare('SELECT * FROM residents WHERE LOWER(email) = LOWER(?) ORDER BY resident_id ASC LIMIT 1');
        $stmt->execute([$email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    $first = trim((string) ($user['first_name'] ?? ''));
    $last = trim((string) ($user['last_name'] ?? ''));
    if ($first !== '' && $last !== '') {
        $stmt = $db->prepare('SELECT * FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?) ORDER BY resident_id ASC LIMIT 1');
        $stmt->execute([$first, $last]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }
    }

    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $uStmt = $db->query("
            SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.username,
                   u.email, u.contact_no, u.status, u.created_at
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE r.role_name = 'Resident'
            ORDER BY u.created_at DESC, u.user_id DESC
        ");
        $users = $uStmt ? $uStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $items = [];
        $summary = ['total' => 0, 'verified' => 0, 'pending' => 0, 'incomplete' => 0];

        foreach ($users as $u) {
            $res = findMatchedResidentForUser($db, $u);
            $email = trim((string) ($res['email'] ?? $u['email'] ?? ''));
            $contactNo = trim((string) ($res['contact_no'] ?? $u['contact_no'] ?? ''));
            $purok = trim((string) ($res['purok'] ?? ''));
            $address = trim((string) ($res['address'] ?? ''));
            $verified = !empty($res['residency_verified']) ? 1 : 0;
            $hasContact = ($contactNo !== '');
            $hasAddress = ($address !== '' || $purok !== '');
            $profileComplete = ($email !== '' && $hasContact && $hasAddress);

            $summary['total']++;
            if ($verified === 1) {
                $summary['verified']++;
            } else {
                $summary['pending']++;
            }
            if (!$profileComplete) {
                $summary['incomplete']++;
            }

            $items[] = [
                'user_id' => (int) $u['user_id'],
                'resident_id' => $res ? (int) $res['resident_id'] : null,
                'full_name' => trim(implode(' ', array_filter([
                    $u['first_name'] ?? '',
                    $u['middle_name'] ?? '',
                    $u['last_name'] ?? '',
                ]))),
                'username' => (string) ($u['username'] ?? ''),
                'email' => $email,
                'contact_no' => $contactNo,
                'purok' => $purok,
                'address' => $address,
                'residency_verified' => $verified,
                'has_contact' => $hasContact,
                'has_address' => $hasAddress,
                'profile_complete' => $profileComplete,
                'status' => (string) ($u['status'] ?? 'Active'),
                'created_at' => (string) ($u['created_at'] ?? ''),
            ];
        }

        echo json_encode(['success' => true, 'summary' => $summary, 'residents' => $items]);
        exit;
    } catch (Throwable $e) {
        error_log('resident-verification GET error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to load resident verification list right now.']);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    $targetUserId = filter_var($payload['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $verified = in_array($payload['verified'] ?? null, [1, '1', true, 'true'], true) ? 1 : 0;

    if (!$targetUserId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid resident account is required.']);
        exit;
    }

    try {
        $uStmt = $db->prepare("
            SELECT u.user_id, u.first_name, u.middle_name, u.last_name, u.email, u.contact_no
            FROM users u
            INNER JOIN roles r ON r.role_id = u.role_id
            WHERE u.user_id = ? AND r.role_name = 'Resident'
            LIMIT 1
        ");
        $uStmt->execute([$targetUserId]);
        $userRow = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!$userRow) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Resident account was not found.']);
            exit;
        }

        $res = findMatchedResidentForUser($db, $userRow);
        $email = trim((string) ($res['email'] ?? $userRow['email'] ?? ''));
        $contactNo = trim((string) ($res['contact_no'] ?? $userRow['contact_no'] ?? ''));
        $purok = trim((string) ($res['purok'] ?? ''));
        $address = trim((string) ($res['address'] ?? ''));

        if ($verified === 1 && ($email === '' || $contactNo === '' || ($address === '' && $purok === ''))) {
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => 'Cannot verify yet: the resident must complete their Mobile Number and Address in their portal profile first.',
            ]);
            exit;
        }

        if ($res) {
            $upd = $db->prepare('UPDATE residents SET residency_verified = ?, updated_at = CURRENT_TIMESTAMP WHERE resident_id = ?');
            $upd->execute([$verified, (int) $res['resident_id']]);
            $residentId = (int) $res['resident_id'];
        } else {
            $ins = $db->prepare("
                INSERT INTO residents (
                    first_name, middle_name, last_name, contact_no, email, preferred_contact,
                    address, house_street, barangay, city_municipality, purok, is_tenant, residency_verified
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Tumana', 'Marikina City', ?, 0, ?)
            ");
            $ins->execute([
                $userRow['first_name'],
                $userRow['middle_name'] ?: null,
                $userRow['last_name'],
                $contactNo !== '' ? $contactNo : null,
                $email !== '' ? $email : null,
                $contactNo !== '' ? 'SMS' : 'Email',
                $address !== '' ? $address : null,
                $address !== '' ? $address : null,
                $purok !== '' ? $purok : null,
                $verified,
            ]);
            $residentId = (int) $db->lastInsertId();
        }

        $actionLabel = $verified === 1 ? 'Verified Resident Account Residency' : 'Revoked Resident Account Residency Verification';
        (new AuditService($db))->log($actorUserId, $actionLabel, 'Residents', $residentId);

        if ($verified === 1) {
            try {
                (new NotificationService())->notifyUser(
                    (int) $targetUserId,
                    'Resident Account Verified',
                    'Barangay Tumana officials have verified your resident profile, mobile number, and address.',
                    $actorUserId,
                    'success',
                    '../complaints/resident-portal.php#profile'
                );
            } catch (Throwable $ne) {
                error_log('Resident verification notification warning: ' . $ne->getMessage());
            }
        }

        echo json_encode([
            'success' => true,
            'verified' => $verified,
            'resident_id' => $residentId,
            'message' => $verified === 1
                ? 'Resident account verified successfully.'
                : 'Resident verification status reverted to Pending Verification.',
        ]);
        exit;
    } catch (Throwable $e) {
        error_log('resident-verification POST error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update verification status right now.']);
        exit;
    }
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
