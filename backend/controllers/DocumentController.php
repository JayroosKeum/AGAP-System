<?php

require_once __DIR__ . '/../models/Document.php';
require_once __DIR__ . '/../services/PDFService.php';
require_once __DIR__ . '/../services/AuditService.php';

class DocumentController
{
    private Document $document;
    private AuditService $audit;

    public function __construct()
    {
        $this->document = new Document();
        $this->audit = new AuditService();
    }

    public function cases(): array
    {
        return ['success' => true, 'data' => $this->document->getAvailableCases()];
    }

    public function kp12Data(int $caseId): array
    {
        $data = $this->document->getKp12Data($caseId);
        if (!$data) {
            return ['success' => false, 'message' => 'Case not found.'];
        }
        return ['success' => true, 'data' => $data];
    }

    public function index(): array
    {
        return ['success' => true, 'data' => $this->document->getAllGenerated()];
    }

    public function generateKp12(array $input, int $userId): array
    {
        $caseId = filter_var($input['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $noticeDate = trim((string) ($input['notice_date'] ?? ''));
        $notice = DateTimeImmutable::createFromFormat('!Y-m-d', $noticeDate);
        if (!$caseId || !$notice || $notice->format('Y-m-d') !== $noticeDate) {
            return ['success' => false, 'message' => 'Select a valid case and notice date.'];
        }

        $data = $this->document->getKp12Data((int) $caseId);
        if (!$data) {
            return ['success' => false, 'message' => 'Case not found.'];
        }
        if ($data['case_status'] === 'Archived') {
            return ['success' => false, 'message' => 'Documents cannot be generated for an archived case.'];
        }
        if (empty($data['complainants']) || empty($data['respondents'])) {
            return ['success' => false, 'message' => 'The case must have at least one complainant and one respondent.'];
        }
        if (empty($data['hearing_date'])) {
            return ['success' => false, 'message' => 'Schedule a Conciliation hearing before generating KP Form 12.'];
        }
        if (empty($data['chairman_name'])) {
            return ['success' => false, 'message' => 'Assign a Pangkat Chairman before generating KP Form 12.'];
        }

        $data['notice_date'] = $noticeDate;
        $root = dirname(__DIR__, 2);
        $relativeDirectory = 'storage/generated-documents/' . date('Y') . '/' . $this->safeSegment($data['case_number']);
        $absoluteDirectory = $root . '/' . $relativeDirectory;
        if (!is_dir($absoluteDirectory) && !mkdir($absoluteDirectory, 0750, true) && !is_dir($absoluteDirectory)) {
            return ['success' => false, 'message' => 'Generated-document storage is unavailable.'];
        }

        $fileName = 'KP-Form-12-' . $this->safeSegment($data['case_number']) . '-' . date('Ymd-His') . '.pdf';
        $relativePath = $relativeDirectory . '/' . $fileName;
        $absolutePath = $root . '/' . $relativePath;

        try {
            (new PDFService())->generateKp12($data, $absolutePath);
            $templateId = $this->document->getOrCreateTemplate(
                'KP Form 12 - Paabiso ng Pagdinig',
                'Notice of Hearing for Conciliation Proceedings'
            );
            $documentId = $this->document->createGeneratedDocument((int) $caseId, $templateId, $userId, $relativePath);
            $this->audit->log($userId, 'Generated KP Form 12', 'Documents', $documentId);

            return [
                'success' => true,
                'message' => 'KP Form 12 generated successfully.',
                'document_id' => $documentId,
                'download_url' => '../../../backend/api/documents/download.php?id=' . $documentId,
            ];
        } catch (Throwable $exception) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
            error_log($exception->getMessage());
            return ['success' => false, 'message' => $exception->getMessage()];
        }
    }

    public function getDownload(int $documentId): array|false
    {
        return $this->document->getGeneratedById($documentId);
    }

    public function delete(int $documentId, int $userId): array
    {
        $record = $this->document->getGeneratedById($documentId);
        if (!$record) {
            return ['success' => false, 'message' => 'Generated document not found.'];
        }
        $absolutePath = dirname(__DIR__, 2) . '/' . $record['file_path'];
        if (!$this->document->deleteGenerated($documentId)) {
            return ['success' => false, 'message' => 'Unable to delete the document record.'];
        }
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
        $this->audit->log($userId, 'Deleted Generated Document', 'Documents', $documentId);
        return ['success' => true, 'message' => 'Generated document deleted successfully.'];
    }

    private function safeSegment(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');
    }
}