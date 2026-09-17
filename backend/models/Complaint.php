<?php

require_once __DIR__ . '/../config/database.php';

class Complaint
{
    private $conn;

    public function __construct()
    {
        $database = new Database();

        $this->conn =
            $database->connect();
    }

    public function getAll()
    {
        try {

            $stmt =
            $this->conn->prepare("
                SELECT
                    c.*,
                    cc.category_name
                FROM complaints c
                LEFT JOIN complaint_categories cc
                    ON c.category_id = cc.category_id
                ORDER BY created_at DESC
            ");

            $stmt->execute();

            return $stmt->fetchAll(
                PDO::FETCH_ASSOC
            );

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return [];
        }
    }

    public function getById($id)
    {
        try {

            $stmt =
            $this->conn->prepare("
                SELECT *
                FROM complaints
                WHERE complaint_id=?
            ");

            $stmt->execute([$id]);

            return $stmt->fetch(
                PDO::FETCH_ASSOC
            );

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    public function create($data): array
    {
        try {

            $validation = $this->validateIncidentInput($data);
            if (!$validation['success']) {
                return $validation;
            }

            if(session_status() === PHP_SESSION_NONE)
            {
                session_start();
            }

            $this->conn->beginTransaction();
            $stmt =
            $this->conn->prepare("
                INSERT INTO complaints
                (
                    category_id,
                    complaint_title,
                    incident_date,
                    incident_time,
                    incident_location,
                    incident_landmark,
                    narrative,
                    additional_details,
                    status,
                    encoded_by
                )
                VALUES
                (
                    ?,?,?,?,?,?,?,?,?,?
                )
            ");

            $stmt->execute([
                $data['category_id'],
                $data['complaint_title'],
                $data['incident_date'],
                trim((string) ($data['incident_time'] ?? '')) ?: null,
                trim((string) ($data['incident_location'] ?? '')) ?: null,
                trim((string) ($data['incident_landmark'] ?? '')) ?: null,
                $data['narrative'],
                trim((string) ($data['additional_details'] ?? '')) ?: null,
                'Filed',
                $_SESSION['user_id']
            ]);

            $complaintId = (int) $this->conn->lastInsertId();
            $complaintNumber = sprintf('CMP-%s-%05d', date('Y'), $complaintId);
            $numberStatement = $this->conn->prepare(
                'UPDATE complaints SET complaint_number = ? WHERE complaint_id = ?'
            );
            $numberStatement->execute([$complaintNumber, $complaintId]);

            $this->conn->commit();
            return ['success' => true, 'message' => 'Complaint created successfully.'];

        }
        catch(Exception $e)
        {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }

            error_log(
                $e->getMessage()
            );

            return ['success' => false, 'message' => 'Unable to create the complaint. Please try again.'];
        }
    }

    public function update($id, $data): array
    {
        try {

            $complaintId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!$complaintId) {
                return ['success' => false, 'message' => 'Select a valid complaint to update.'];
            }

            $validation = $this->validateIncidentInput($data);
            if (!$validation['success']) {
                return $validation;
            }

            $exists = $this->conn->prepare('SELECT complaint_id FROM complaints WHERE complaint_id = ?');
            $exists->execute([$complaintId]);
            if (!$exists->fetchColumn()) {
                return ['success' => false, 'message' => 'The complaint could not be found.'];
            }

            $stmt =
            $this->conn->prepare("
                UPDATE complaints
                SET
                    category_id=?,
                    complaint_title=?,
                    incident_date=?,
                    incident_time=?,
                    incident_location=?,
                    incident_landmark=?,
                    narrative=?,
                    additional_details=?
                WHERE complaint_id=?
            ");

            $stmt->execute([
                $data['category_id'],
                $data['complaint_title'],
                $data['incident_date'],
                trim((string) ($data['incident_time'] ?? '')) ?: null,
                trim((string) ($data['incident_location'] ?? '')) ?: null,
                trim((string) ($data['incident_landmark'] ?? '')) ?: null,
                $data['narrative'],
                trim((string) ($data['additional_details'] ?? '')) ?: null,
                $complaintId
            ]);

            return ['success' => true, 'message' => 'Complaint updated successfully.'];

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return ['success' => false, 'message' => 'Unable to update the complaint. Please try again.'];
        }
    }

    public function review(int $id, string $status, ?string $notes): array
    {
        $allowed = ['Under Review', 'Needs Information', 'Accepted', 'Rejected'];
        if (!in_array($status, $allowed, true)) {
            return ['success' => false, 'message' => 'Select a valid review decision.'];
        }
        $exists = $this->conn->prepare("SELECT complaint_id FROM complaints WHERE complaint_id = ? AND status NOT IN ('Docketed', 'Archived')");
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) return ['success' => false, 'message' => 'This complaint cannot be reviewed in its current status.'];
        $stmt = $this->conn->prepare('UPDATE complaints SET status = ?, review_notes = ? WHERE complaint_id = ?');
        $stmt->execute([$status, $notes, $id]);
        return ['success' => true, 'message' => 'Complaint review saved.'];
    }

    public function delete($id)
    {
        try {

            $stmt =
            $this->conn->prepare("
                DELETE FROM complaints
                WHERE complaint_id=?
            ");

            return $stmt->execute([
                $id
            ]);

        }
        catch(Exception $e)
        {
            error_log(
                $e->getMessage()
            );

            return false;
        }
    }

    private function validateIncidentInput(array $data): array
    {
        $categoryId = filter_var($data['category_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $title = trim((string) ($data['complaint_title'] ?? ''));
        $date = trim((string) ($data['incident_date'] ?? ''));
        $time = trim((string) ($data['incident_time'] ?? ''));
        $narrative = trim((string) ($data['narrative'] ?? ''));

        if (!$categoryId) return ['success' => false, 'message' => 'Select a valid complaint category.'];
        $category = $this->conn->prepare('SELECT category_id FROM complaint_categories WHERE category_id = ?');
        $category->execute([$categoryId]);
        if (!$category->fetchColumn()) return ['success' => false, 'message' => 'Select a valid complaint category.'];
        if ($title === '') return ['success' => false, 'message' => 'Complaint title is required.'];
        if (mb_strlen($title) > 255) return ['success' => false, 'message' => 'Complaint title must be 255 characters or fewer.'];
        if ($date === '') return ['success' => false, 'message' => 'Incident date is required.'];
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) return ['success' => false, 'message' => 'Incident date is invalid.'];
        if ($time !== '' && !preg_match('/^([01]\\d|2[0-3]):[0-5]\\d$/', $time)) return ['success' => false, 'message' => 'Incident time is invalid.'];
        if ($narrative === '') return ['success' => false, 'message' => 'Incident narrative is required.'];
        if (mb_strlen($narrative) > 15000) return ['success' => false, 'message' => 'Narrative is too long. Use 15,000 characters or fewer.'];
        foreach (['incident_location' => 255, 'incident_landmark' => 255, 'additional_details' => 5000] as $field => $maxLength) {
            if (mb_strlen(trim((string) ($data[$field] ?? ''))) > $maxLength) {
                return ['success' => false, 'message' => ucwords(str_replace('_', ' ', $field)) . " must be {$maxLength} characters or fewer."];
            }
        }
        return ['success' => true];
    }
}
