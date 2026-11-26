<?php

require_once __DIR__ . '/../models/CaseModel.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../middleware/rbac.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class CaseController
{
    private $case;
    private $audit;
    private PDO $conn;

    public function __construct()
    {
        $this->conn = (new Database())->connect();
        $this->case = new CaseModel($this->conn);
        $this->audit = new AuditService($this->conn);
    }

    public function index()
    {
        $rows = $this->case->getAll();
        if (isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            $uid = agapSessionUserId();
            $roleName = agapGetRoleName($this->conn);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => agapCanAccessCase($this->conn, (int) $row['case_id'], $uid, $roleName, 'read')
            ));
        }
        return $rows;
    }

    public function page(int $page): array
    {
        $result = $this->case->getPage($page, 25);
        if (isset($_SESSION['user_id'], $_SESSION['role_id']) && isset($result['cases']) && is_array($result['cases'])) {
            $uid = agapSessionUserId();
            $roleName = agapGetRoleName($this->conn);
            $result['cases'] = array_values(array_filter(
                $result['cases'],
                fn (array $row): bool => agapCanAccessCase($this->conn, (int) $row['case_id'], $uid, $roleName, 'read')
            ));
        }
        return $result;
    }

    public function show($id)
    {
        $caseId = (int) $id;
        if ($caseId <= 0) {
            return false;
        }
        if (isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            if (!agapCanAccessCase($this->conn, $caseId, agapSessionUserId(), agapGetRoleName($this->conn), 'read')) {
                return false;
            }
        }
        return $this->case->getById($caseId);
    }

    public function workspace(int $id): array
    {
        if (isset($_SESSION['user_id'], $_SESSION['role_id'])) {
            if (!agapCanAccessCase($this->conn, $id, agapSessionUserId(), agapGetRoleName($this->conn), 'read')) {
                return ['success' => false, 'message' => 'Unauthorized to access this case.'];
            }
        }
        $workspace = $this->case->getWorkspace($id);
        return $workspace ? ['success' => true, 'data' => $workspace] : ['success' => false, 'message' => 'Case not found.'];
    }

    public function getDocketingError($complaintId)
    {
        return $this->case->getDocketingError($complaintId);
    }

    public function store($data)
    {
        $result = $this->case->create($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Case',
                'Cases'
            );
        }

        return $result;
    }

    public function update($id, $data)
    {
        $result = $this->case->update($id, $data);

        if (is_array($result) && !empty($result['success'])) {
            $this->audit->log(
                $_SESSION['user_id'],
                isset($data['head_id'], $data['secretary_id'], $data['member_id'])
                    ? 'Updated Case and Lupon Team'
                    : 'Updated Case',
                'Cases',
                $id
            );
        }

        return $result;
    }

    public function archive($id)
    {
        $result = $this->case->archive($id);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Archived Case',
                'Cases',
                $id
            );
        }

        return $result;
    }
}
