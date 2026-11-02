<?php

class Purok
{
    private PDO $conn;

    public function __construct(?PDO $conn = null)
    {
        if ($conn !== null) {
            $this->conn = $conn;
        } else {
            require_once dirname(__DIR__) . '/config/database.php';
            $this->conn = (new Database())->connect();
        }
    }

    /**
     * Retrieves all Puroks and areas with complaint counts.
     */
    public function getPuroks(bool $onlyActive = false): array
    {
        try {
            $sql = "SELECT p.*, 
                           (SELECT COUNT(*) FROM complaints c WHERE c.incident_purok = p.purok_name) as complaint_count
                    FROM barangay_puroks p";
            if ($onlyActive) {
                $sql .= " WHERE p.is_active = 1";
            }
            $sql .= " ORDER BY p.sort_order ASC, p.purok_name ASC";
            $stmt = $this->conn->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\PDOException $e) {
            // Fallback default Tumana list if table is not yet migrated
            return [
                ['id' => 1, 'purok_name' => 'Purok 1', 'description' => 'Purok 1', 'is_active' => 1, 'sort_order' => 1, 'complaint_count' => 0],
                ['id' => 2, 'purok_name' => 'Purok 2', 'description' => 'Purok 2', 'is_active' => 1, 'sort_order' => 2, 'complaint_count' => 0],
                ['id' => 3, 'purok_name' => 'Purok 3', 'description' => 'Purok 3', 'is_active' => 1, 'sort_order' => 3, 'complaint_count' => 0],
                ['id' => 4, 'purok_name' => 'Purok 4', 'description' => 'Purok 4', 'is_active' => 1, 'sort_order' => 4, 'complaint_count' => 0],
                ['id' => 5, 'purok_name' => 'Purok 5', 'description' => 'Purok 5', 'is_active' => 1, 'sort_order' => 5, 'complaint_count' => 0],
                ['id' => 6, 'purok_name' => 'Purok 6', 'description' => 'Purok 6', 'is_active' => 1, 'sort_order' => 6, 'complaint_count' => 0],
                ['id' => 7, 'purok_name' => 'Palmera Homes', 'description' => 'Palmera Homes', 'is_active' => 1, 'sort_order' => 7, 'complaint_count' => 0],
                ['id' => 8, 'purok_name' => 'Farmers Compound', 'description' => 'Farmers Compound', 'is_active' => 1, 'sort_order' => 8, 'complaint_count' => 0],
                ['id' => 9, 'purok_name' => 'Twin River Subdivision', 'description' => 'Twin River', 'is_active' => 1, 'sort_order' => 9, 'complaint_count' => 0],
                ['id' => 10, 'purok_name' => 'Riverside Area', 'description' => 'Riverside Area', 'is_active' => 1, 'sort_order' => 10, 'complaint_count' => 0],
                ['id' => 11, 'purok_name' => 'Concepcion Boundary', 'description' => 'Concepcion Boundary', 'is_active' => 1, 'sort_order' => 11, 'complaint_count' => 0]
            ];
        }
    }

    /**
     * Adds or updates a Purok.
     */
    public function savePurok(array $data): array
    {
        $id = (int)($data['id'] ?? 0);
        $name = trim((string)($data['purok_name'] ?? ''));
        $desc = trim((string)($data['description'] ?? ''));
        $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $sortOrder = isset($data['sort_order']) ? (int)$data['sort_order'] : 0;

        if ($name === '') {
            return ['success' => false, 'message' => 'Purok / Area name is required.'];
        }

        try {
            if ($id > 0) {
                $stmt = $this->conn->prepare("UPDATE barangay_puroks SET purok_name = :name, description = :desc, is_active = :active, sort_order = :sort WHERE id = :id");
                $stmt->execute([':name' => $name, ':desc' => $desc, ':active' => $isActive, ':sort' => $sortOrder, ':id' => $id]);
                return ['success' => true, 'message' => 'Purok updated successfully.'];
            } else {
                $stmt = $this->conn->prepare("INSERT INTO barangay_puroks (purok_name, description, is_active, sort_order) VALUES (:name, :desc, :active, :sort)");
                $stmt->execute([':name' => $name, ':desc' => $desc, ':active' => $isActive, ':sort' => $sortOrder]);
                return ['success' => true, 'id' => (int)$this->conn->lastInsertId(), 'message' => 'Purok created successfully.'];
            }
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    /**
     * Soft-deactivates or deletes a Purok.
     */
    public function deletePurok(int $purokId): array
    {
        try {
            $nameStmt = $this->conn->prepare("SELECT purok_name FROM barangay_puroks WHERE id = ?");
            $nameStmt->execute([$purokId]);
            $purokName = $nameStmt->fetchColumn();

            if (!$purokName) {
                return ['success' => false, 'message' => 'Purok not found.'];
            }

            $countStmt = $this->conn->prepare("SELECT COUNT(*) FROM complaints WHERE incident_purok = ?");
            $countStmt->execute([$purokName]);
            $linked = (int)$countStmt->fetchColumn();

            if ($linked > 0) {
                $stmt = $this->conn->prepare("UPDATE barangay_puroks SET is_active = 0 WHERE id = ?");
                $stmt->execute([$purokId]);
                return ['success' => true, 'message' => "Purok '{$purokName}' is used in {$linked} complaint(s). It has been deactivated instead of deleted to protect historical cases."];
            }

            $stmt = $this->conn->prepare("DELETE FROM barangay_puroks WHERE id = ?");
            $stmt->execute([$purokId]);
            return ['success' => true, 'message' => "Purok '{$purokName}' deleted permanently."];
        } catch (\PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}
