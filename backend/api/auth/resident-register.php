<?php

require_once __DIR__ . '/../../services/SecurityBootstrap.php';
SecurityBootstrap::startSession();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/User.php';
require_once __DIR__ . '/../../services/AuditService.php';
require_once __DIR__ . '/../../services/SingleSessionService.php';
require_once __DIR__ . '/../../services/ValidationService.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = $_POST;
if (empty($input)) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$step = trim((string) ($input['step'] ?? 'submit'));
$email = trim((string) ($input['email'] ?? ''));
$firstName = trim((string) ($input['first_name'] ?? ''));
$middleName = trim((string) ($input['middle_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$username = trim((string) ($input['username'] ?? ''));
$contactNo = trim((string) ($input['contact_no'] ?? ''));
$purok = trim((string) ($input['purok'] ?? ''));
$address = trim((string) ($input['address'] ?? ''));
$password = (string) ($input['password'] ?? '');
$confirm = (string) ($input['confirm_password'] ?? ($input['password_confirmation'] ?? ''));

if (!ValidationService::email($email)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide a valid email address (up to 150 characters).']);
    exit;
}
if (!ValidationService::name($firstName, 80, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid first name (letters only, up to 80 characters).']);
    exit;
}
if ($middleName !== '' && !ValidationService::name($middleName, 80, false)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid middle name (letters only, up to 80 characters).']);
    exit;
}
if (!ValidationService::name($lastName, 80, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid last name (letters only, up to 80 characters).']);
    exit;
}

if ($step === 'check_email') {
    try {
        $db = (new Database())->connect();
        $dupEmailStmt = $db->prepare('SELECT user_id FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $dupEmailStmt->execute([$email]);
        if ($dupEmailStmt->fetchColumn()) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'An account with that email address already exists. Please sign in instead.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Email is available. Continue setting your username and password.']);
        exit;
    } catch (Throwable $e) {
        error_log('resident-register check_email error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to verify email right now. Please try again.']);
        exit;
    }
}

if (!ValidationService::username($username, 4, 50)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Username must be 4–50 characters using letters, numbers, dots, underscores, or hyphens.']);
    exit;
}
if ($contactNo !== '' && (mb_strlen($contactNo, 'UTF-8') > 20 || !ValidationService::phone($contactNo))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Provide a valid Philippine mobile or telephone number (e.g. 09XXXXXXXXX).']);
    exit;
}
if ($purok !== '' && (!ValidationService::maxLength($purok, 100) || !ValidationService::address($purok))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Purok must be 100 characters or fewer without control characters.']);
    exit;
}
if ($address !== '' && (!ValidationService::maxLength($address, 255) || !ValidationService::address($address))) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Street address must be 255 characters or fewer without control characters.']);
    exit;
}
if (($pwErr = ValidationService::passwordError($password, $confirm)) !== null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $pwErr]);
    exit;
}

$acceptTerms = strtolower(trim((string) ($input['accept_terms'] ?? '')));
if (!in_array($acceptTerms, ['1', 'true', 'yes', 'on'], true)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Please read and check the Terms and Conditions & Data Privacy agreement before creating an account.',
    ]);
    exit;
}

try {
    $db = (new Database())->connect();
    $userModel = new User();
    $residentRoleId = $userModel->findRoleIdByName('Resident');
    if (!$residentRoleId) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Resident portal role is not configured yet.']);
        exit;
    }

    $dupStmt = $db->prepare('SELECT user_id FROM users WHERE username = ? OR LOWER(email) = LOWER(?) LIMIT 1');
    $dupStmt->execute([$username, $email]);
    if ($dupStmt->fetchColumn()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'An account with that username or email already exists.']);
        exit;
    }

    $db->beginTransaction();

    $createRes = $userModel->create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'username' => $username,
        'email' => $email,
        'contact_no' => $contactNo !== '' ? $contactNo : null,
        'password' => $password,
        'role_id' => $residentRoleId,
    ]);
    if (empty($createRes['success']) || empty($createRes['id'])) {
        $db->rollBack();
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => $createRes['message'] ?? 'Unable to create resident account.']);
        exit;
    }
    $newUserId = (int) $createRes['id'];

    if ($middleName !== '') {
        $db->prepare('UPDATE users SET middle_name = ? WHERE user_id = ?')->execute([$middleName, $newUserId]);
    }

    // Link by email first or create an unverified resident entry (mobile & address added later in My Profile)
    $resStmt = $db->prepare('SELECT resident_id FROM residents WHERE LOWER(email) = LOWER(?) LIMIT 1');
    $resStmt->execute([$email]);
    $residentId = (int) ($resStmt->fetchColumn() ?: 0);
    if ($residentId <= 0) {
        $insRes = $db->prepare("
            INSERT INTO residents (
                first_name, middle_name, last_name, contact_no, email, preferred_contact,
                address, house_street, barangay, city_municipality, purok, is_tenant, residency_verified
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Tumana', 'Marikina City', ?, 0, 0)
        ");
        $insRes->execute([
            $firstName,
            $middleName !== '' ? $middleName : null,
            $lastName,
            $contactNo !== '' ? $contactNo : null,
            $email,
            $contactNo !== '' ? 'SMS' : 'Email',
            $address !== '' ? $address : null,
            $address !== '' ? $address : null,
            $purok !== '' ? $purok : null,
        ]);
        $residentId = (int) $db->lastInsertId();
    }

    $db->commit();

    session_regenerate_id(true);
    $_SESSION['user_id'] = $newUserId;
    $_SESSION['role_id'] = $residentRoleId;
    $_SESSION['role_name'] = 'Resident';
    $_SESSION['username'] = $username;
    $_SESSION['resident_id'] = $residentId;
    $sessionToken = SingleSessionService::acquireSessionLock($db, $newUserId);
    if ($sessionToken !== null) {
        $_SESSION['session_token'] = $sessionToken;
    }

    (new AuditService())->log($newUserId, 'Registered Resident Portal Account', 'Authentication', $newUserId);

    echo json_encode([
        'success' => true,
        'message' => 'Resident portal account created and signed in.',
        'user_id' => $newUserId,
        'resident_id' => $residentId,
        'redirect_url' => '../../frontend/pages/complaints/resident-portal.php',
    ]);
    exit;
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('resident-register error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to register right now. Please try again.']);
    exit;
}
