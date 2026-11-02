<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../models/LuponDuty.php';
require_once __DIR__ . '/../../models/User.php';

// Secretary (5), Lupon Clerk (2), Admin (1) can view and assign duty schedules
$roleId = (int) ($_SESSION['role_id'] ?? 0);
if (!in_array($roleId, [1, 2, 5], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Only Secretary, Lupon Clerk, or Admin can manage Lupon duty schedules.']);
    exit;
}

$luponDuty = new LuponDuty();
$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $userId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : null;
        $members = $luponDuty->getLuponMembers();
        $schedules = $luponDuty->getSchedules($userId);

        echo json_encode([
            'success' => true,
            'members' => $members,
            'schedules' => $schedules
        ]);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;
        $action = trim((string) ($data['action'] ?? 'save'));

        if ($action === 'create_lupon_account') {
            // "in the settings where in the admin can add a lupon account."
            // Only admin (role_id = 1) can add accounts
            if ($roleId !== 1) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only the Administrator can create new user accounts.']);
                exit;
            }

            $userModel = new User();
            $firstName = trim((string) ($data['first_name'] ?? ''));
            $lastName = trim((string) ($data['last_name'] ?? ''));
            $username = trim((string) ($data['username'] ?? ''));
            $password = (string) ($data['password'] ?? '');
            $email = trim((string) ($data['email'] ?? ''));
            $contactNo = trim((string) ($data['contact_no'] ?? ''));

            if ($firstName === '' || $lastName === '' || $username === '' || $password === '') {
                echo json_encode(['success' => false, 'message' => 'Please fill in First Name, Last Name, Username, and Password.']);
                exit;
            }

            $res = $userModel->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'username' => $username,
                'password' => $password,
                'email' => $email,
                'contact_no' => $contactNo,
                'role_id' => 3, // Lupon Member
                'status' => 'Active'
            ]);

            if ($res) {
                echo json_encode(['success' => true, 'message' => 'Lupon Member account created successfully.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Failed to create Lupon Member account. Username may already exist.']);
            }
            exit;
        }

        if ($action === 'delete') {
            $id = (int) ($data['id'] ?? 0);
            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid schedule ID.']);
                exit;
            }
            $res = $luponDuty->deleteSchedule($id);
            echo json_encode(['success' => $res, 'message' => $res ? 'Duty schedule removed.' : 'Failed to delete schedule.']);
            exit;
        }

        // Sync or save duty days for a Lupon member
        $targetUserId = (int) ($data['user_id'] ?? 0);
        if ($targetUserId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Please select a Lupon member.']);
            exit;
        }

        $dutyDays = is_array($data['duty_days'] ?? null) ? $data['duty_days'] : [];
        if (empty($dutyDays) && !empty($data['duty_day'])) {
            $dutyDays = [trim((string) $data['duty_day'])];
        }

        $startTime = !empty($data['start_time']) ? trim((string) $data['start_time']) : '08:00:00';
        $endTime = !empty($data['end_time']) ? trim((string) $data['end_time']) : '17:00:00';
        $notes = !empty($data['notes']) ? trim((string) $data['notes']) : null;

        $res = $luponDuty->syncUserDutyDays($targetUserId, $dutyDays, $startTime, $endTime, $notes);
        echo json_encode([
            'success' => $res,
            'message' => $res ? 'Lupon duty schedule updated successfully.' : 'Failed to update schedule.'
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
