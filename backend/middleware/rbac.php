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
            5 => 'Resident',
            default => '',
        };
    }
}

if (!function_exists('agapIsSuperAdmin')) {
    function agapIsSuperAdmin(?PDO $conn = null): bool
    {
        return agapGetRoleName($conn) === 'Super Administrator';
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
            5 => 'Resident',
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
        $stmt = $conn->prepare('SELECT 1 FROM complaints WHERE complaint_id = ? AND submitted_by = ? AND deleted_at IS NULL LIMIT 1');
        $stmt->execute([$complaintId, $userId]);
        return (bool) $stmt->fetchColumn();
    }
}

if (!function_exists('agapCanAccessComplaint')) {
    /**
     * Centralized server-side case/complaint authorization policy (A4 / A8).
     * Combines role authority with assignment/ownership and soft-deletion isolation.
     */
    function agapCanAccessComplaint(PDO $conn, int $complaintId, int $userId, string $roleName, string $mode = 'read'): bool
    {
        if ($complaintId <= 0 || $userId <= 0 || $roleName === '') {
            return false;
        }

        $stmt = $conn->prepare(
            'SELECT complaint_id, submitted_by, liso_assigned_to, deleted_at
             FROM complaints
             WHERE complaint_id = ?
             LIMIT 1'
        );
        $stmt->execute([$complaintId]);
        $complaint = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$complaint) {
            return false;
        }

        $isDeleted = $complaint['deleted_at'] !== null;
        if ($roleName === 'Super Administrator') {
            return true;
        }
        if ($isDeleted) {
            return false;
        }

        if ($roleName === 'Administrator' || $roleName === 'Lupon Clerk') {
            return true;
        }

        if ($roleName === 'Resident') {
            return (int) ($complaint['submitted_by'] ?? 0) === $userId;
        }

        if ($roleName === 'Lupon Member') {
            if ((int) ($complaint['liso_assigned_to'] ?? 0) === $userId) {
                return true;
            }
            $assignedStmt = $conn->prepare(
                'SELECT 1
                 FROM cases cs
                 LEFT JOIN case_assignments ca ON ca.case_id = cs.case_id AND ca.member_id = ?
                 LEFT JOIN pangkat_groups pg ON pg.case_id = cs.case_id
                 LEFT JOIN pangkat_members pm ON pm.pangkat_id = pg.pangkat_id AND pm.member_id = ?
                 LEFT JOIN hearings h ON h.case_id = cs.case_id AND (h.presiding_officer_id = ? OR h.substitute_presider_id = ?)
                 WHERE cs.complaint_id = ?
                   AND (ca.assignment_id IS NOT NULL OR pm.pangkat_member_id IS NOT NULL OR h.hearing_id IS NOT NULL)
                 LIMIT 1'
            );
            $assignedStmt->execute([$userId, $userId, $userId, $userId, $complaintId]);
            return (bool) $assignedStmt->fetchColumn();
        }

        if ($roleName === 'Summons Server') {
            if ($mode !== 'read') {
                return false;
            }
            $srvStmt = $conn->prepare(
                'SELECT 1
                 FROM complaints c
                 LEFT JOIN paanyaya_service_attempts psa ON psa.complaint_id = c.complaint_id AND psa.assigned_to = ?
                 LEFT JOIN cases cs ON cs.complaint_id = c.complaint_id
                 LEFT JOIN summon_deliveries sd ON sd.case_id = cs.case_id AND sd.served_by_user_id = ?
                 LEFT JOIN hearings h ON h.case_id = cs.case_id
                 LEFT JOIN hearing_party_services hps ON hps.hearing_id = h.hearing_id AND hps.serving_officer = ?
                 WHERE c.complaint_id = ?
                   AND (psa.attempt_id IS NOT NULL OR sd.delivery_id IS NOT NULL OR hps.service_id IS NOT NULL)
                 LIMIT 1'
            );
            $srvStmt->execute([$userId, $userId, $userId, $complaintId]);
            return (bool) $srvStmt->fetchColumn();
        }

        return false;
    }
}

if (!function_exists('agapCanAccessCase')) {
    function agapCanAccessCase(PDO $conn, int $caseId, int $userId, string $roleName, string $mode = 'read'): bool
    {
        if ($caseId <= 0 || $userId <= 0 || $roleName === '') {
            return false;
        }
        $stmt = $conn->prepare('SELECT complaint_id, deleted_at FROM cases WHERE case_id = ? LIMIT 1');
        $stmt->execute([$caseId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($row['deleted_at'] !== null && $roleName !== 'Super Administrator') {
            return false;
        }
        return agapCanAccessComplaint($conn, (int) $row['complaint_id'], $userId, $roleName, $mode);
    }
}

if (!function_exists('agapCanAccessDocument')) {
    function agapCanAccessDocument(PDO $conn, int $documentId, int $userId, string $roleName): bool
    {
        if ($documentId <= 0 || $userId <= 0 || $roleName === '') {
            return false;
        }
        $stmt = $conn->prepare(
            'SELECT gd.document_id, cs.case_id, cs.complaint_id
             FROM generated_documents gd
             INNER JOIN cases cs ON cs.case_id = gd.case_id
             WHERE gd.document_id = ?
             LIMIT 1'
        );
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return agapCanAccessComplaint($conn, (int) $row['complaint_id'], $userId, $roleName, 'read');
    }
}

if (!function_exists('agapCanAccessAttachment')) {
    function agapCanAccessAttachment(PDO $conn, int $attachmentId, int $userId, string $roleName): bool
    {
        if ($attachmentId <= 0 || $userId <= 0 || $roleName === '') {
            return false;
        }
        $stmt = $conn->prepare('SELECT complaint_id FROM complaint_attachments WHERE attachment_id = ? LIMIT 1');
        $stmt->execute([$attachmentId]);
        $complaintId = (int) ($stmt->fetchColumn() ?: 0);
        if ($complaintId <= 0) {
            return false;
        }
        return agapCanAccessComplaint($conn, $complaintId, $userId, $roleName, 'read');
    }
}

if (!function_exists('agapRequireComplaintAccess')) {
    function agapRequireComplaintAccess(PDO $conn, int $complaintId, string $mode = 'read', bool $json = true): void
    {
        $userId = agapSessionUserId();
        $roleName = agapGetRoleName($conn);
        if ($userId <= 0 || $roleName === '') {
            http_response_code(401);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            } else {
                echo 'Authentication required.';
            }
            exit;
        }
        if (!agapCanAccessComplaint($conn, $complaintId, $userId, $roleName, $mode)) {
            http_response_code(403);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'You are not authorized to access this complaint.']);
            } else {
                echo 'Unauthorized.';
            }
            exit;
        }
    }
}

if (!function_exists('agapRequireCaseAccess')) {
    function agapRequireCaseAccess(PDO $conn, int $caseId, string $mode = 'read', bool $json = true): void
    {
        $userId = agapSessionUserId();
        $roleName = agapGetRoleName($conn);
        if ($userId <= 0 || $roleName === '') {
            http_response_code(401);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Authentication required.']);
            } else {
                echo 'Authentication required.';
            }
            exit;
        }
        if (!agapCanAccessCase($conn, $caseId, $userId, $roleName, $mode)) {
            http_response_code(403);
            if ($json) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'You are not authorized to access this case.']);
            } else {
                echo 'Unauthorized.';
            }
            exit;
        }
    }
}

if (!function_exists('agapBuildComplaintVisibilitySql')) {
    /**
     * Returns [sqlPredicate, params] restricting complaint queries to non-deleted
     * records visible to the active session user.
     *
     * @return array{0: string, 1: array<int, mixed>}
     */
    function agapBuildComplaintVisibilitySql(string $complaintAlias, PDO $conn, bool $includeDeletedForSuperAdmin = false): array
    {
        $userId = agapSessionUserId();
        $roleName = agapGetRoleName($conn);
        if ($userId <= 0 || $roleName === '') {
            return ['1 = 0', []];
        }

        $deletedClause = ($includeDeletedForSuperAdmin && $roleName === 'Super Administrator')
            ? '1 = 1'
            : "{$complaintAlias}.deleted_at IS NULL";

        if (in_array($roleName, ['Super Administrator', 'Administrator', 'Lupon Clerk'], true)) {
            return [$deletedClause, []];
        }

        if ($roleName === 'Resident') {
            return ["{$deletedClause} AND {$complaintAlias}.submitted_by = ?", [$userId]];
        }

        if ($roleName === 'Lupon Member') {
            $sql = "{$deletedClause} AND (
                {$complaintAlias}.liso_assigned_to = ?
                OR EXISTS (
                    SELECT 1 FROM cases cs_vis
                    LEFT JOIN case_assignments ca_vis ON ca_vis.case_id = cs_vis.case_id AND ca_vis.member_id = ?
                    LEFT JOIN pangkat_groups pg_vis ON pg_vis.case_id = cs_vis.case_id
                    LEFT JOIN pangkat_members pm_vis ON pm_vis.pangkat_id = pg_vis.pangkat_id AND pm_vis.member_id = ?
                    LEFT JOIN hearings h_vis ON h_vis.case_id = cs_vis.case_id AND (h_vis.presiding_officer_id = ? OR h_vis.substitute_presider_id = ?)
                    WHERE cs_vis.complaint_id = {$complaintAlias}.complaint_id
                      AND (ca_vis.assignment_id IS NOT NULL OR pm_vis.pangkat_member_id IS NOT NULL OR h_vis.hearing_id IS NOT NULL)
                )
            )";
            return [$sql, [$userId, $userId, $userId, $userId, $userId]];
        }

        if ($roleName === 'Summons Server') {
            $sql = "{$deletedClause} AND (
                EXISTS (SELECT 1 FROM paanyaya_service_attempts psa_vis WHERE psa_vis.complaint_id = {$complaintAlias}.complaint_id AND psa_vis.assigned_to = ?)
                OR EXISTS (
                    SELECT 1 FROM cases cs_srv
                    LEFT JOIN summon_deliveries sd_srv ON sd_srv.case_id = cs_srv.case_id AND sd_srv.served_by_user_id = ?
                    LEFT JOIN hearings h_srv ON h_srv.case_id = cs_srv.case_id
                    LEFT JOIN hearing_party_services hps_srv ON hps_srv.hearing_id = h_srv.hearing_id AND hps_srv.serving_officer = ?
                    WHERE cs_srv.complaint_id = {$complaintAlias}.complaint_id
                      AND (sd_srv.delivery_id IS NOT NULL OR hps_srv.service_id IS NOT NULL)
                )
            )";
            return [$sql, [$userId, $userId, $userId]];
        }

        return ['1 = 0', []];
    }
}
