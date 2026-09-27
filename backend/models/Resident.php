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

    public static function normalizeNameStr(?string $str): string
    {
        if ($str === null) return '';
        $clean = mb_strtolower(trim($str));
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $clean);
        return trim((string) $clean);
    }

    public static function normalizeAddressStr(?string $str): string
    {
        if ($str === null) return '';
        $clean = mb_strtolower(trim($str));

        // Normalize street/road abbreviations
        $replacements = [
            '/\bst\b|\bst\./i' => 'street',
            '/\bave\b|\bave\./i' => 'avenue',
            '/\brd\b|\brd\./i' => 'road',
            '/\bblvd\b|\bblvd\./i' => 'boulevard',
            '/\bbrgy\b|\bbrgy\.|\bbgy\b/i' => 'barangay',
            '/\bsubd\b|\bsubd\./i' => 'subdivision',
            '/\bext\b|\bext\./i' => 'extension',
            '/\bno\b|\bno\.|\b#\b/i' => '',
        ];
        $clean = preg_replace(array_keys($replacements), array_values($replacements), $clean);

        // Strip common city/barangay boilerplate to isolate street, compound, or house location
        $boilerplate = [
            '/\bbarangay\s+tumana\b/i' => '',
            '/\btumana\b/i' => '',
            '/\bmarikina\s+city\b/i' => '',
            '/\bmarikina\b/i' => '',
            '/\bmetro\s+manila\b/i' => '',
            '/\bphilippines\b/i' => '',
        ];
        $clean = preg_replace(array_keys($boilerplate), array_values($boilerplate), $clean);

        // Strip non-alphanumeric characters except spaces
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $clean);
        return trim(preg_replace('/\s+/', ' ', $clean));
    }

    public static function normalizePurokStr(?string $str): string
    {
        if ($str === null) return '';
        $clean = mb_strtolower(trim($str));
        $clean = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $clean);
        $clean = trim(preg_replace('/\s+/', ' ', $clean));
        if (preg_replace('/^purok\s*/', '', $clean) !== '') {
            $num = preg_replace('/^purok\s*/', '', $clean);
            if (is_numeric($num)) return "purok $num";
        }
        return $clean;
    }

    public static function extractHouseNumber(string $normAddr): ?string
    {
        // Detect leading house/building numbers or block/lot prefixes
        if (preg_match('/^(\d+[-A-Za-z0-9]*)\s+/', $normAddr, $m)) {
            return $m[1];
        }
        if (preg_match('/\b(?:lot|blk|block|unit|house)\s*(\d+[-A-Za-z0-9]*)/', $normAddr, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Determines whether two sets of name components represent similar or matching names.
     */
    public static function areNamesSimilar(array $p1, array $p2): bool
    {
        $f1 = self::normalizeNameStr($p1['first_name'] ?? '');
        $l1 = self::normalizeNameStr($p1['last_name'] ?? '');
        $m1 = self::normalizeNameStr($p1['middle_name'] ?? '');

        $f2 = self::normalizeNameStr($p2['first_name'] ?? '');
        $l2 = self::normalizeNameStr($p2['last_name'] ?? '');
        $m2 = self::normalizeNameStr($p2['middle_name'] ?? '');

        if ($f1 === '' || $l1 === '' || $f2 === '' || $l2 === '') {
            return false;
        }

        // Distinct middle names check:
        // In the Philippines, maternal maiden names are distinct family lines.
        // If both provide distinct full middle names (e.g. 'Santos' vs 'Reyes'), they are different people.
        if ($m1 !== '' && $m2 !== '') {
            $isInitial1 = mb_strlen($m1) === 1 || (mb_strlen($m1) === 2 && str_ends_with($m1, '.'));
            $isInitial2 = mb_strlen($m2) === 1 || (mb_strlen($m2) === 2 && str_ends_with($m2, '.'));
            $initChar1 = mb_substr($m1, 0, 1);
            $initChar2 = mb_substr($m2, 0, 1);

            if (!$isInitial1 && !$isInitial2) {
                if ($m1 !== $m2 && levenshtein($m1, $m2) > 1) {
                    return false; // Distinct middle names -> Different people
                }
            } else {
                if ($initChar1 !== $initChar2) {
                    return false; // Mismatched middle initial
                }
            }
        }

        // 1. Exact first & last name match
        if ($f1 === $f2 && $l1 === $l2) {
            return true;
        }

        // 2. Similar first name (typo / minor variation) with same or similar last name
        $lMatch = ($l1 === $l2) || (levenshtein($l1, $l2) <= 1) || (str_contains($l1, $l2) || str_contains($l2, $l1));
        if ($lMatch) {
            if ($f1 === $f2 || levenshtein($f1, $f2) <= 1) {
                return true;
            }
            similar_text($f1, $f2, $fSim);
            if ($fSim >= 80.0) {
                return true;
            }
        }

        // 3. Overall full name text similarity
        $full1 = trim("$f1 $m1 $l1");
        $full2 = trim("$f2 $m2 $l2");
        similar_text($full1, $full2, $sim);
        if ($sim >= 85.0) {
            return true;
        }

        // 4. Token subset: all tokens of one name are present in the other (e.g. "Michelle Aquino" vs "Michelle Sartillo Aquino")
        $tokens1 = array_filter(explode(' ', $full1));
        $tokens2 = array_filter(explode(' ', $full2));
        $intersect = array_intersect($tokens1, $tokens2);
        if (count($intersect) >= 2 && (count($intersect) === count($tokens1) || count($intersect) === count($tokens2))) {
            return true;
        }

        return false;
    }

    /**
     * Determines whether two addresses represent the same or similar residential location.
     */
    public static function areAddressesSimilar(string $rawAddr1, ?string $rawPurok1, string $rawAddr2, ?string $rawPurok2): bool
    {
        $norm1 = self::normalizeAddressStr($rawAddr1);
        $norm2 = self::normalizeAddressStr($rawAddr2);
        $p1 = self::normalizePurokStr($rawPurok1);
        $p2 = self::normalizePurokStr($rawPurok2);

        // If both addresses are empty, compare puroks
        if ($norm1 === '' && $norm2 === '') {
            return ($p1 !== '' && $p2 !== '' && $p1 === $p2);
        }

        // If one is empty and the other is not, we don't have enough to say they have similar addresses
        if ($norm1 === '' || $norm2 === '') {
            return false;
        }

        // Check if both specify distinct house numbers (e.g. #123 vs #456 on Moscow Street)
        $house1 = self::extractHouseNumber($norm1);
        $house2 = self::extractHouseNumber($norm2);
        if ($house1 !== null && $house2 !== null && $house1 !== $house2) {
            return false; // Different house numbers -> Different address
        }

        // Exact normalized address match
        if ($norm1 === $norm2) {
            return true;
        }

        // Substring / containment (e.g. "123 moscow street" and "moscow street")
        if (str_contains($norm1, $norm2) || str_contains($norm2, $norm1)) {
            if ($p1 !== '' && $p2 !== '' && $p1 !== $p2) {
                return false; // Conflicting puroks
            }
            return true;
        }

        // High text similarity on the address
        similar_text($norm1, $norm2, $addrSim);
        if ($addrSim >= 80.0) {
            if ($p1 !== '' && $p2 !== '' && $p1 !== $p2) {
                return false;
            }
            return true;
        }

        // Same purok AND meaningful street name token match
        if ($p1 !== '' && $p2 !== '' && $p1 === $p2) {
            $tokens1 = array_filter(explode(' ', $norm1));
            $tokens2 = array_filter(explode(' ', $norm2));
            $common = array_intersect($tokens1, $tokens2);
            $significant = array_diff($common, ['street', 'road', 'avenue', 'compound', 'sitio', 'area', 'purok']);
            if (count($significant) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a resident profile already exists representing the same person.
     * Rule: Allow similar names, but do NOT allow similar names and address.
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

        if ($firstName === '' || $lastName === '') {
            return null;
        }

        // Fetch candidate matches from the database where either last name or first name could match
        $sql = "
            SELECT resident_id, first_name, middle_name, last_name, birth_date, contact_no, address, purok
            FROM residents
            WHERE (
                LOWER(TRIM(last_name)) = LOWER(:last_name)
                OR SOUNDEX(last_name) = SOUNDEX(:last_name)
                OR LOWER(TRIM(first_name)) = LOWER(:first_name)
            )
        ";
        if ($excludeId !== null) {
            $sql .= " AND resident_id != :exclude_id";
        }

        $stmt = $this->conn->prepare($sql);
        $params = [
            ':last_name' => $lastName,
            ':first_name' => $firstName,
        ];
        if ($excludeId !== null) {
            $params[':exclude_id'] = $excludeId;
        }
        $stmt->execute($params);
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $inputPerson = [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'address' => $address,
            'purok' => $purok,
        ];

        foreach ($candidates as $cand) {
            // 1. Are names similar?
            if (!self::areNamesSimilar($cand, $inputPerson)) {
                continue; // Different names are allowed regardless of address
            }

            // 2. If names ARE similar, are addresses also similar?
            $addressSimilar = self::areAddressesSimilar(
                $cand['address'] ?? '',
                $cand['purok'] ?? '',
                $address,
                $purok
            );

            // "allow similar names but do not allow similar names and address"
            if ($addressSimilar) {
                return $cand; // Duplicate: similar name AND similar address
            }
            // If names are similar but address is different, continue (allowed!)
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