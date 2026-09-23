<?php

require_once __DIR__ . '/../models/Location.php';
require_once __DIR__ . '/../models/ProofOfService.php';
require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/ValidationService.php';

class GPSController
{
    private Location $location;
    private ProofOfService $proof;
    private AuditService $audit;
    private Document $document;

    public function __construct()
    {
        $this->location = new Location();
        $this->proof = new ProofOfService();
        $this->audit = new AuditService();
        $this->document = new Document();
    }

    public function complaints(): array { return ['success' => true, 'data' => $this->location->getComplaints()]; }
    public function locations(): array { return ['success' => true, 'data' => $this->location->getAll()]; }
    public function cases(): array { return ['success' => true, 'data' => $this->proof->getAvailableCases()]; }
    public function documents(int $caseId): array { return ['success' => true, 'data' => $this->document->getGeneratedByCase($caseId)]; }

    public function location(int $complaintId): array
    {
        if (!$this->location->complaintExists($complaintId)) return ['success' => false, 'message' => 'Complaint not found.'];
        return ['success' => true, 'data' => $this->location->getByComplaint($complaintId)];
    }

    public function saveLocation(array $data, int $userId): array
    {
        $complaintId = filter_var($data['complaint_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $latitude = filter_var($data['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $longitude = filter_var($data['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
        $address = trim((string) ($data['address'] ?? ''));
        if (!$complaintId || $latitude === false || $longitude === false || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) return ['success' => false, 'message' => 'Select a complaint and a valid point on the map.'];
        if (mb_strlen($address) > 2000) return ['success' => false, 'message' => 'Address must not exceed 2,000 characters.'];
        $result = $this->location->save((int) $complaintId, (float) $latitude, (float) $longitude, $address !== '' ? $address : null);
        if ($result['success']) $this->audit->log($userId, 'Saved incident location', 'GPS', (int) $complaintId);
        return $result + ['message' => $result['success'] ? 'Incident location saved.' : null];
    }

    public function proofs(int $caseId): array
    {
        if ($caseId < 1) return ['success' => false, 'message' => 'A valid case is required.'];
        return ['success' => true, 'data' => $this->proof->getByCase($caseId)];
    }

    public function saveProof(array $data, array $file, int $userId): array
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $documentId = filter_var($data['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $servedDate = trim((string) ($data['served_date'] ?? ''));
        $date = null;
        if (ValidationService::dateTime($servedDate, 'Y-m-d\\TH:i')) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $servedDate);
        } elseif (ValidationService::dateTime($servedDate, 'Y-m-d H:i:s')) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $servedDate);
        }
        $remarks = trim((string) ($data['remarks'] ?? ''));
        if (!$caseId || !$documentId || !$date || $date > new DateTimeImmutable('+5 minutes')) return ['success' => false, 'message' => 'Select an active case, generated document, and a valid service date that is not in the future.'];
        if (mb_strlen($remarks) > 2000) return ['success' => false, 'message' => 'Verification details must not exceed 2,000 characters.'];
        $upload = $this->storeImage($file);
        if (!$upload['success']) return $upload;
        $result = $this->proof->create((int) $caseId, (int) $documentId, $userId, $date->format('Y-m-d H:i:s'), $remarks !== '' ? $remarks : null, $upload['path']);
        if (!$result['success'] && $upload['path']) @unlink(dirname(__DIR__, 2) . '/' . $upload['path']);
        if ($result['success']) { $this->document->markServed((int) $documentId, (int) $caseId); $this->audit->log($userId, 'Recorded proof of service', 'GPS', (int) $result['proof_id']); }
        return $result + ['message' => $result['success'] ? 'Proof of service recorded.' : null];
    }

    public function proofImage(int $proofId): array|false { return $this->proof->getById($proofId); }

    private function storeImage(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['success' => true, 'path' => null];
        $temporaryPath = (string) ($file['tmp_name'] ?? '');
        $actualSize = $temporaryPath !== '' && is_uploaded_file($temporaryPath) ? filesize($temporaryPath) : false;
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || $actualSize === false || $actualSize < 1
            || $actualSize > 5 * 1024 * 1024 || (int) ($file['size'] ?? -1) !== $actualSize) {
            return ['success' => false, 'message' => 'Upload a valid JPG, PNG, or WebP image no larger than 5 MB.'];
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        $extensions = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if (!isset($extensions[$mime]) || !in_array($extension, $extensions[$mime], true) || @getimagesize($temporaryPath) === false) {
            return ['success' => false, 'message' => 'Proof image must be a valid JPG, PNG, or WebP file.'];
        }
        $relativeDirectory = 'storage/uploads/proof-of-service'; $directory = dirname(__DIR__, 2) . '/' . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) return ['success' => false, 'message' => 'Proof-image storage is unavailable.'];
        $storedExtension = $mime === 'image/jpeg' ? 'jpg' : $extension;
        $relativePath = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $storedExtension;
        if (!move_uploaded_file($temporaryPath, dirname(__DIR__, 2) . '/' . $relativePath)) return ['success' => false, 'message' => 'Unable to save the proof image.'];
        return ['success' => true, 'path' => $relativePath];
    }
}
