<?php
declare(strict_types=1);

require_once __DIR__ . '/../models/Complaint.php';
require_once __DIR__ . '/../models/Profile.php';

final class ComplaintService
{
    public function __construct(private PDO $pdo) {}

    public function create(array $input, int $actorId, array $files = []): array
    {
        $title = trim((string)($input['complaint_title'] ?? ''));
        $narrative = trim((string)($input['narrative'] ?? ''));
        $catId = filter_var($input['category_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $caseType = in_array(trim((string)($input['case_type'] ?? '')), ['Civil', 'Criminal'], true) ? trim((string)$input['case_type']) : 'Civil';
        $street = trim((string)($input['incident_street'] ?? $input['incident_location'] ?? ''));

        $errors = [];
        if ($title === '') $errors['complaint_title'] = 'Complaint Title is required.';
        if (!$catId) $errors['category_id'] = 'Please select a complaint category.';
        if ($narrative === '') $errors['narrative'] = 'Narrative statement is required.';
        if ($street === '') $errors['incident_street'] = 'Street or specific location is required.';

        $compId = filter_var($input['complainant_resident_id'] ?? $input['complainant_profile_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
        $respId = filter_var($input['respondent_resident_id'] ?? $input['respondent_profile_id'] ?? null, FILTER_VALIDATE_INT) ?: null;

        if (!$compId) {
            $errors['complainant_resident_id'] = 'Please select a registered profile for the Complainant.';
        }
        if (!$respId) {
            $errors['respondent_resident_id'] = 'Please select a registered profile for the Respondent.';
        }
        if ($compId && $respId && $compId === $respId) {
            $errors['party_distinct'] = 'The complainant and respondent cannot be the same profile.';
        }

        $profileModel = new Profile($this->pdo);
        $compProfile = $compId ? $profileModel->find($compId) : null;
        $respProfile = $respId ? $profileModel->find($respId) : null;

        if ($respProfile && ($respProfile['residency_status'] === 'Non-Resident' || strcasecmp((string)($respProfile['purok_sitio'] ?? ''), 'Non-Resident') === 0)) {
            $errors['respondent_resident_id'] = 'Only residents and tenants living within Barangay Tumana can be named as respondents.';
        }

        if (!empty($errors)) {
            return ['ok' => false, 'errors' => $errors, 'message' => reset($errors)];
        }

        $model = new Complaint($this->pdo);
        $this->pdo->beginTransaction();

        try {
            $id = $model->create($input, $actorId);

            // Add Complainant
            $compName = $compProfile ? trim(implode(' ', array_filter([$compProfile['first_name'], $compProfile['middle_name'] ?? '', $compProfile['last_name'], $compProfile['suffix'] ?? '']))) : trim((string)($input['complainant_name'] ?? 'Complainant'));
            $compContact = $compProfile['contact_no'] ?? (string)($input['complainant_contact'] ?? '');
            $compAddress = $compProfile['address_line'] ?? (string)($input['complainant_address'] ?? '');
            $compPurok = $compProfile['purok_sitio'] ?? (string)($input['complainant_purok'] ?? '');
            $model->addParty($id, 'Complainant', $compId, $compName, $compContact, $compAddress, $compPurok);

            // Add Respondent
            $respName = $respProfile ? trim(implode(' ', array_filter([$respProfile['first_name'], $respProfile['middle_name'] ?? '', $respProfile['last_name'], $respProfile['suffix'] ?? '']))) : trim((string)($input['respondent_name'] ?? 'Respondent'));
            $respContact = $respProfile['contact_no'] ?? (string)($input['respondent_contact'] ?? '');
            $respAddress = $respProfile['address_line'] ?? (string)($input['respondent_address'] ?? '');
            $respPurok = $respProfile['purok_sitio'] ?? (string)($input['respondent_purok'] ?? '');
            $model->addParty($id, 'Respondent', $respId, $respName, $respContact, $respAddress, $respPurok);

            // Add Additional Parties / Witnesses
            $extraIds = $input['party_resident_ids'] ?? [];
            $extraTypes = $input['party_types'] ?? [];
            if (is_array($extraIds)) {
                foreach ($extraIds as $idx => $extraIdRaw) {
                    $extraId = filter_var($extraIdRaw, FILTER_VALIDATE_INT) ?: null;
                    if (!$extraId) continue;
                    $extraType = in_array($extraTypes[$idx] ?? '', ['Complainant', 'Respondent', 'Witness'], true) ? $extraTypes[$idx] : 'Witness';
                    $extraProf = $profileModel->find($extraId);
                    if ($extraProf) {
                        $eName = trim(implode(' ', array_filter([$extraProf['first_name'], $extraProf['middle_name'] ?? '', $extraProf['last_name'], $extraProf['suffix'] ?? ''])));
                        $model->addParty($id, $extraType, $extraId, $eName, (string)($extraProf['contact_no'] ?? ''), (string)($extraProf['address_line'] ?? ''), (string)($extraProf['purok_sitio'] ?? ''));
                    }
                }
            }

            // Process uploaded evidence attachments
            $evidenceFiles = $files['evidence'] ?? [];
            if (!empty($evidenceFiles['name']) && is_array($evidenceFiles['name'])) {
                $uploadDir = __DIR__ . '/../../storage/uploads/complaint-evidence/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileCount = count($evidenceFiles['name']);
                for ($i = 0; $i < $fileCount; $i++) {
                    if (($evidenceFiles['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                        $origName = basename((string)$evidenceFiles['name'][$i]);
                        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                        $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
                        $destPath = $uploadDir . $safeName;

                        if (move_uploaded_file((string)$evidenceFiles['tmp_name'][$i], $destPath)) {
                            $mime = (string)($evidenceFiles['type'][$i] ?? 'application/octet-stream');
                            $size = (int)($evidenceFiles['size'][$i] ?? 0);
                            $model->addAttachment($id, $origName, 'storage/uploads/complaint-evidence/' . $safeName, $mime, $size);
                        }
                    }
                }
            }

            // Add Audit History
            $model->addHistory($id, $actorId, [
                'stage' => 'Intake',
                'status' => 'Filed',
                'title' => $title,
                'case_type' => $caseType
            ]);

            $this->pdo->commit();
            return ['ok' => true, 'id' => $id, 'message' => 'Complaint filed successfully.'];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'errors' => ['general' => $e->getMessage()], 'message' => $e->getMessage()];
        }
    }

    public function find(int $id): ?array
    {
        $model = new Complaint($this->pdo);
        $complaint = $model->find($id);
        if (!$complaint) {
            return null;
        }
        $complaint['parties'] = $model->getParties($id);
        $complaint['attachments'] = $model->getAttachments($id);
        $complaint['history'] = $model->getHistory($id);
        return $complaint;
    }

    public function all(?string $status = null, ?string $q = null, ?string $caseType = null): array
    {
        $model = new Complaint($this->pdo);
        return $model->all($status, $q, $caseType);
    }
}
