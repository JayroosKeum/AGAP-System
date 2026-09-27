<?php

require_once __DIR__ . '/../config/database.php';

class Resident
{
    private $conn;

    public function __construct()
    {
        $database = new Database();
        $this->conn = $database->connect();
    }

    public function getAll()
    {
        $sql = "
            SELECT *
            FROM residents
            ORDER BY resident_id DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPage(int $page = 1, int $perPage = 25): array
    {
        $totalStmt = $this->conn->query("SELECT COUNT(*) FROM residents");
        $total = (int) $totalStmt->fetchColumn();
        $totalPages = (int) ceil($total / $perPage);
        $page = max(1, min($page, max(1, $totalPages)));
        $offset = ($page - 1) * $perPage;

        $stmt = $this->conn->prepare("
            SELECT *
            FROM residents
            ORDER BY resident_id DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total_records' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function getById($id)
    {
        $sql = "
            SELECT *
            FROM residents
            WHERE resident_id = ?
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute([$id]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        $sql = "
            INSERT INTO residents
            (
                first_name,
                middle_name,
                last_name,
                birth_date,
                gender,
                civil_status,
                contact_no,
                email,
                address,
                purok,
                is_tenant
            )
            VALUES
            (
                ?,?,?,?,?,?,?,?,?,?,?
            )
        ";

        $stmt = $this->conn->prepare($sql);

        $success = $stmt->execute([
            $data['first_name'],
            $data['middle_name'],
            $data['last_name'],
            $data['birth_date'],
            $data['gender'],
            $data['civil_status'],
            $data['contact_no'],
            $data['email'],
            $data['address'],
            $data['purok'],
            $data['is_tenant']
        ]);

        if (!$success) {
            return false;
        }

        return (int) $this->conn->lastInsertId();
    }

    private function normalizeStr(?string $str): string
    {
        if ($str === null) return '';
        $clean = mb_strtolower(trim($str));
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $clean);
        return trim((string) $clean);
    }

    /**
     * Checks if a resident profile already exists representing the same person.
     * Two people can have similar names, but not the same description/address making them the same person.
     *
     * @param array $data Input profile fields
     * @param int|null $excludeId Resident ID to exclude when updating
     * @return array|null The duplicate record if found, or null if unique
     */
    public function findDuplicate(array $data, ?int $excludeId = null): ?array
    {
        $firstName = trim($data['first_name'] ?? '');
        $middleName = trim($data['middle_name'] ?? '');
        $lastName = trim($data['last_name'] ?? '');
        $address = trim($data['address'] ?? '');
        $purok = trim($data['purok'] ?? '');
        $birthDate = trim($data['birth_date'] ?? '');
        $contactNo = trim($data['contact_no'] ?? '');

        $normFirst = $this->normalizeStr($firstName);
        $normLast = $this->normalizeStr($lastName);
        $normMiddle = $this->normalizeStr($middleName);
        $normAddress = $this->normalizeStr($address);
        $normPurok = $this->normalizeStr($purok);
        $normContact = preg_replace('/\D/', '', $contactNo);

        $sql = "
            SELECT resident_id, first_name, middle_name, last_name, birth_date, contact_no, address, purok
            FROM residents
            WHERE LOWER(TRIM(first_name)) = LOWER(:first_name)
              AND LOWER(TRIM(last_name)) = LOWER(:last_name)
        ";
        if ($excludeId !== null) {
            $sql .= " AND resident_id != :exclude_id";
        }

        $stmt = $this->conn->prepare($sql);
        $params = [
            ':first_name' => $firstName,
            ':last_name' => $lastName,
        ];
        if ($excludeId !== null) {
            $params[':exclude_id'] = $excludeId;
        }
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($candidates as $cand) {
            $candMiddle = $this->normalizeStr($cand['middle_name'] ?? '');
            $candAddress = $this->normalizeStr($cand['address'] ?? '');
            $candPurok = $this->normalizeStr($cand['purok'] ?? '');
            $candBirth = trim($cand['birth_date'] ?? '');
            $candContact = preg_replace('/\D/', '', $cand['contact_no'] ?? '');

            // 1. Middle name check:
            // If both specify a distinct middle name (e.g. 'Santos' vs 'Reyes'), they are different people.
            if ($normMiddle !== '' && $candMiddle !== '') {
                $isInitialMatch = (mb_strlen($normMiddle) === 1 && str_starts_with($candMiddle, $normMiddle))
                               || (mb_strlen($candMiddle) === 1 && str_starts_with($normMiddle, $candMiddle));
                if ($normMiddle !== $candMiddle && !$isInitialMatch) {
                    continue; // Distinct middle names -> different individuals
                }
            }

            // 2. Identifying description / address / details check:
            $sameAddress = ($normAddress !== '' && $candAddress !== '' && $normAddress === $candAddress);
            $similarAddress = false;
            if ($normAddress !== '' && $candAddress !== '') {
                if ($sameAddress || str_contains($candAddress, $normAddress) || str_contains($normAddress, $candAddress)) {
                    $similarAddress = true;
                }
            }

            $samePurok = ($normPurok !== '' && $candPurok !== '' && $normPurok === $candPurok);
            $sameContact = ($normContact !== '' && $candContact !== '' && $normContact === $candContact);
            $sameBirth = ($birthDate !== '' && $candBirth !== '' && $birthDate === $candBirth);

            // Same address: duplicate of the same person
            if ($sameAddress || $similarAddress) {
                return $cand;
            }

            // Same purok AND (same contact OR same birth date)
            if ($samePurok && ($sameContact || $sameBirth)) {
                return $cand;
            }

            // Same contact number
            if ($sameContact) {
                return $cand;
            }

            // Same birth date AND same purok
            if ($sameBirth && $samePurok) {
                return $cand;
            }

            // If neither has any distinguishing location/description and names match completely
            if ($normAddress === '' && $candAddress === '' && $normPurok === '' && $candPurok === '') {
                return $cand;
            }
        }

        return null;
    }

    public function update($id, $data)
    {
        $sql = "
            UPDATE residents
            SET
                first_name=?,
                middle_name=?,
                last_name=?,
                birth_date=?,
                gender=?,
                civil_status=?,
                contact_no=?,
                email=?,
                address=?,
                purok=?,
                is_tenant=?
            WHERE resident_id=?
        ";

        $stmt = $this->conn->prepare($sql);

        return $stmt->execute([
            $data['first_name'],
            $data['middle_name'],
            $data['last_name'],
            $data['birth_date'],
            $data['gender'],
            $data['civil_status'],
            $data['contact_no'],
            $data['email'],
            $data['address'],
            $data['purok'],
            $data['is_tenant'],
            $id
        ]);
    }

    public function delete($id)
    {
        $stmt = $this->conn->prepare(
            "DELETE FROM residents WHERE resident_id=?"
        );

        return $stmt->execute([$id]);
    }
}