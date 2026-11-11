<?php

require_once __DIR__ .
'/../config/database.php';

class AuditService
{
    private $conn;

    public function __construct(?PDO $conn = null)
    {
        if ($conn !== null) {
            $this->conn = $conn;
            return;
        }
        $database = new Database();

        $this->conn =
            $database->connect();
    }

    public function log(
        $userId,
        $action,
        $module,
        $recordId = null
    )
    {
        try {

            $stmt =
                $this->conn->prepare("
                INSERT INTO audit_trails
                (
                    user_id,
                    action,
                    module_name,
                    affected_record
                )
                VALUES
                (
                    ?,?,?,?
                )
            ");

            return $stmt->execute([
                $userId,
                $action,
                $module,
                $recordId
            ]);

        }
        catch(Throwable $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }
}
