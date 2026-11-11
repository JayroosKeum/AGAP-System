<?php

function authorize(array $allowedRoles)
{
    if (
        !isset($_SESSION['role_id']) ||
        !in_array($_SESSION['role_id'], $allowedRoles)
    ) {
        http_response_code(403);

        die("Unauthorized Access");
    }
}

if (!function_exists('agapSessionUserId')) {
    function agapSessionUserId(): int
    {
        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    }
}

if (!function_exists('agapGetRoleName')) {
    function agapGetRoleName(?PDO $conn = null): string
    {
        $cached = trim((string) ($_SESSION['role_name'] ?? ''));
        if ($cached !== '') {
            return $cached;
        }

        $roleId = (int) ($_SESSION['role_id'] ?? 0);
        if ($roleId <= 0) {
            return '';
        }

        if ($conn !== null) {
            try {
                $stmt = $conn->prepare('SELECT role_name FROM roles WHERE role_id = ? LIMIT 1');
                $stmt->execute([$roleId]);
                $name = trim((string) ($stmt->fetchColumn() ?: ''));
                if ($name !== '') {
                    $_SESSION['role_name'] = $name;
                    return $name;
                }
            } catch (Throwable $e) {
                error_log('agapGetRoleName lookup warning: ' . $e->getMessage());
            }
        }

        return match ($roleId) {
            1 => 'Administrator',
            2 => 'Lupon Clerk',
            3 => 'Lupon Member',
            4 => 'Summons Server',
            default => '',
        };
    }
}

if (!function_exists('agapHasRole')) {
    function agapHasRole(array $allowedRoleNames, ?PDO $conn = null): bool
    {
        if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            return false;
        }

        $roleName = agapGetRoleName($conn);
        if ($roleName !== '' && in_array($roleName, $allowedRoleNames, true)) {
            return true;
        }

        $legacyMap = [
            1 => 'Administrator',
            2 => 'Lupon Clerk',
            3 => 'Lupon Member',
            4 => 'Summons Server',
        ];
        $roleId = (int) ($_SESSION['role_id'] ?? 0);
        return isset($legacyMap[$roleId]) && in_array($legacyMap[$roleId], $allowedRoleNames, true);
    }
}

if (!function_exists('agapRequireApiRoles')) {
    function agapRequireApiRoles(array $allowedRoleNames, ?PDO $conn = null): string
    {
        require_once __DIR__ . '/../services/SecurityBootstrap.php';
        SecurityBootstrap::startSession();

        if (!isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            exit;
        }

        require_once __DIR__ . '/../services/SingleSessionService.php';
        SingleSessionService::enforceForApi($conn);

        if (!agapHasRole($allowedRoleNames, $conn)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Unauthorized for this action.']);
            exit;
        }

        return agapGetRoleName($conn);
    }
}

if (!function_exists('agapResidentOwnsComplaint')) {
    function agapResidentOwnsComplaint(PDO $conn, int $complaintId, int $userId): bool
    {
        if ($complaintId <= 0 || $userId <= 0) {
            return false;
        }
        static $hasCreatedBy = null;
        if ($hasCreatedBy === null) {
            try {
                $col = $conn->query("SHOW COLUMNS FROM complaints LIKE 'created_by'")->fetchColumn();
                $hasCreatedBy = ($col !== false);
            } catch (Throwable $e) {
                $hasCreatedBy = false;
            }
        }
        $where = $hasCreatedBy
            ? '(c.created_by = ? OR c.encoded_by = ?)'
            : 'c.encoded_by = ?';
        $params = $hasCreatedBy ? [$complaintId, $userId, $userId] : [$complaintId, $userId];
        $stmt = $conn->prepare("SELECT 1 FROM complaints c WHERE c.complaint_id = ? AND {$where} LIMIT 1");
        $stmt->execute($params);
        return (bool) $stmt->fetchColumn();
    }
}
