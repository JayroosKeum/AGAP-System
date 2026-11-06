<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/PartyModel.php';
require_once __DIR__ . '/../../models/Complaint.php';
require_once __DIR__ . '/../../services/AuditService.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Authorization & Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Only POST is accepted.']);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int)$_SESSION['role_id'], [1, 2, 3], true)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized. Only Lupon Clerk and Administrator can save complaints.']);
    exit;
}

try {
    // 2. Parse Input Data (supports JSON payload or form-encoded POST)
    $inputData = $_POST;
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $jsonDecoded = json_decode($rawInput, true);
        if (is_array($jsonDecoded)) {
            $inputData = array_merge($inputData, $jsonDecoded);
        }
    }

    $db = (new Database())->connect();
    $db->beginTransaction();

    $partyModel = new PartyModel($db);
    $complaintModel = new Complaint();
    $audit = new AuditService();

    $complaintId = !empty($inputData['complaint_id']) ? (int)$inputData['complaint_id'] : 0;
    $isUpdate = ($complaintId > 0);

    // 3. Resolve / Decouple Resident Profiles
    // Complainant Profile
    $compData = [
        'resident_id' => !empty($inputData['complainant_resident_id']) ? (int)$inputData['complainant_resident_id'] : null,
        'name' => $inputData['complainant_name'] ?? null,
        'first_name' => $inputData['complainant_first_name'] ?? null,
        'middle_name' => $inputData['complainant_middle_name'] ?? null,
        'last_name' => $inputData['complainant_last_name'] ?? null,
        'suffix' => $inputData['complainant_suffix'] ?? null,
        'contact_no' => $inputData['complainant_contact'] ?? ($inputData['complainant_contact_no'] ?? null),
        'email' => $inputData['complainant_email'] ?? null,
        'preferred_contact' => $inputData['complainant_preferred_contact'] ?? 'SMS',
        'address' => $inputData['complainant_address'] ?? null,
        'house_street' => $inputData['complainant_house_street'] ?? null,
        'purok' => $inputData['complainant_purok'] ?? null,
        'gender' => $inputData['complainant_gender'] ?? null,
        'civil_status' => $inputData['complainant_civil_status'] ?? null,
        'birth_date' => !empty($inputData['complainant_birthdate']) ? $inputData['complainant_birthdate'] : (!empty($inputData['complainant_birth_date']) ? $inputData['complainant_birth_date'] : null),
        'occupation' => $inputData['complainant_occupation'] ?? null,
        'is_tenant' => !empty($inputData['complainant_is_tenant']) ? 1 : 0,
        'residency_verified' => isset($inputData['complainant_residency_verified']) ? (!empty($inputData['complainant_residency_verified']) ? 1 : 0) : 1
    ];

    $compResidentId = $partyModel->findOrCreateResident($compData);

    // Respondent Profile
    $respData = [
        'resident_id' => !empty($inputData['respondent_resident_id']) ? (int)$inputData['respondent_resident_id'] : null,
        'name' => $inputData['respondent_name'] ?? null,
        'first_name' => $inputData['respondent_first_name'] ?? null,
        'middle_name' => $inputData['respondent_middle_name'] ?? null,
        'last_name' => $inputData['respondent_last_name'] ?? null,
        'suffix' => $inputData['respondent_suffix'] ?? null,
        'contact_no' => $inputData['respondent_contact'] ?? ($inputData['respondent_contact_no'] ?? null),
        'email' => $inputData['respondent_email'] ?? null,
        'address' => $inputData['respondent_address'] ?? null,
        'house_street' => $inputData['respondent_house_street'] ?? null,
        'purok' => $inputData['respondent_purok'] ?? null,
        'gender' => $inputData['respondent_gender'] ?? null,
        'civil_status' => $inputData['respondent_civil_status'] ?? null,
        'birth_date' => !empty($inputData['respondent_birthdate']) ? $inputData['respondent_birthdate'] : (!empty($inputData['respondent_birth_date']) ? $inputData['respondent_birth_date'] : null),
        'occupation' => $inputData['respondent_occupation'] ?? null,
        'is_tenant' => !empty($inputData['respondent_is_tenant']) ? 1 : 0,
        'residency_verified' => isset($inputData['respondent_residency_verified']) ? (!empty($inputData['respondent_residency_verified']) ? 1 : 0) : 1
    ];

    $respResidentId = $partyModel->findOrCreateResident($respData);

    // 4. Save or Update Complaint Record
    $complaintTitle = trim((string)($inputData['complaint_title'] ?? ''));
    if (empty($complaintTitle)) {
        $complaintTitle = 'Complaint: ' . trim(($compData['first_name'] ?? '') . ' ' . ($compData['last_name'] ?? '')) . ' vs. ' . trim(($respData['first_name'] ?? '') . ' ' . ($respData['last_name'] ?? ''));
    }

    $narrative = trim((string)($inputData['narrative'] ?? ($inputData['salaysay'] ?? '')));
    $categoryId = !empty($inputData['category_id']) ? (int)$inputData['category_id'] : 1;
    $caseType = in_array($inputData['case_type'] ?? '', ['Civil', 'Criminal'], true) ? $inputData['case_type'] : 'Civil';
    $incidentDate = !empty($inputData['incident_date']) ? $inputData['incident_date'] : null;
    $incidentTime = !empty($inputData['incident_time']) ? $inputData['incident_time'] : null;
    $incidentLocation = trim((string)($inputData['incident_location'] ?? 'Barangay Tumana'));
    $incidentPurok = trim((string)($inputData['incident_purok'] ?? ''));
    $incidentStreet = trim((string)($inputData['incident_street'] ?? ''));
    $incidentLandmark = trim((string)($inputData['incident_landmark'] ?? ''));
    $requestedResolution = trim((string)($inputData['requested_resolution'] ?? ''));
    $isDraft = !empty($inputData['is_draft']) ? 1 : 0;
    $encodedBy = (int)$_SESSION['user_id'];

    if ($isUpdate) {
        $updateSql = "
            UPDATE complaints SET
                complaint_title = ?,
                narrative = ?,
                category_id = ?,
                case_type = ?,
                incident_date = ?,
                incident_time = ?,
                incident_location = ?,
                incident_purok = ?,
                incident_street = ?,
                incident_landmark = ?,
                requested_resolution = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE complaint_id = ?
        ";
        $updStmt = $db->prepare($updateSql);
        $updStmt->execute([
            $complaintTitle,
            $narrative,
            $categoryId,
            $caseType,
            $incidentDate,
            $incidentTime,
            $incidentLocation,
            $incidentPurok,
            $incidentStreet,
            $incidentLandmark,
            $requestedResolution,
            $complaintId
        ]);
    } else {
        // Generate complaint number
        $year = (int)date('Y');
        $cCountStmt = $db->query("SELECT COUNT(*) FROM complaints WHERE YEAR(created_at) = $year");
        $series = ((int)$cCountStmt->fetchColumn()) + 1;
        $complaintNumber = sprintf('CMP-%04d-%04d', $year, $series);

        $insertSql = "
            INSERT INTO complaints (
                complaint_number, complaint_title, narrative, category_id, case_type,
                incident_date, incident_time, incident_location, incident_purok,
                incident_street, incident_landmark, requested_resolution, is_draft,
                status, encoded_by, created_at, updated_at
            ) VALUES (
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?,
                ?, ?, ?, ?,
                'Mediation', ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ";
        $insStmt = $db->prepare($insertSql);
        $insStmt->execute([
            $complaintNumber,
            $complaintTitle,
            $narrative,
            $categoryId,
            $caseType,
            $incidentDate,
            $incidentTime,
            $incidentLocation,
            $incidentPurok,
            $incidentStreet,
            $incidentLandmark,
            $requestedResolution,
            $isDraft,
            $encodedBy
        ]);
        $complaintId = (int)$db->lastInsertId();
        if (!$isDraft) {
            require_once __DIR__ . '/../../models/CaseModel.php';
            $docketDate = date('Y-m-d');
            $docketCaseType = in_array($caseType, ['Civil', 'Criminal', 'Others'], true) ? $caseType : 'Others';
            $caseInsStmt = $db->prepare("INSERT INTO cases (complaint_id, case_type, case_status, docket_date) VALUES (?, ?, 'Mediation', ?)");
            $caseInsStmt->execute([$complaintId, $docketCaseType, $docketDate]);
            $caseId = (int)$db->lastInsertId();
            $complaintNumber = CaseModel::generateCaseNumber($db, $docketDate, $caseId);
            $db->prepare('UPDATE cases SET case_number = ? WHERE case_id = ?')->execute([$complaintNumber, $caseId]);
            $db->prepare('UPDATE complaints SET complaint_number = ?, status = ? WHERE complaint_id = ?')->execute([$complaintNumber, 'Mediation', $complaintId]);
        }
    }

    // 5. Link Parties to Complaint
    $compMeta = [
        'is_minor' => !empty($inputData['complainant_is_minor']) ? 1 : 0,
        'guardian_name' => $inputData['complainant_guardian_name'] ?? null,
        'guardian_relationship' => $inputData['complainant_guardian_relationship'] ?? null,
        'guardian_contact' => $inputData['complainant_guardian_contact'] ?? null,
        'guardian_address' => $inputData['complainant_guardian_address'] ?? null,
        'preferred_contact' => $compData['preferred_contact'],
        'suffix' => $compData['suffix'],
        'occupation' => $compData['occupation'],
        'gender' => $compData['gender'],
        'civil_status' => $compData['civil_status'],
        'birth_date' => $compData['birth_date']
    ];
    $partyModel->linkPartyToComplaint($complaintId, $compResidentId, 'Complainant', $compMeta);

    $respMeta = [
        'is_minor' => !empty($inputData['respondent_is_minor']) ? 1 : 0,
        'guardian_name' => $inputData['respondent_guardian_name'] ?? null,
        'guardian_relationship' => $inputData['respondent_guardian_relationship'] ?? null,
        'guardian_contact' => $inputData['respondent_guardian_contact'] ?? null,
        'guardian_address' => $inputData['respondent_guardian_address'] ?? null,
        'suffix' => $respData['suffix'],
        'occupation' => $respData['occupation'],
        'gender' => $respData['gender'],
        'civil_status' => $respData['civil_status'],
        'birth_date' => $respData['birth_date'],
        'contact_unknown' => !empty($inputData['respondent_contact_unknown']) ? 1 : 0,
        'middle_name_unknown' => !empty($inputData['respondent_middle_name_unknown']) ? 1 : 0,
        'birthdate_unknown' => !empty($inputData['respondent_birthdate_unknown']) ? 1 : 0,
        'address_requires_verification' => !empty($inputData['respondent_address_requires_verification']) ? 1 : 0
    ];
    $partyModel->linkPartyToComplaint($complaintId, $respResidentId, 'Respondent', $respMeta);

    // 6. Cross-Module Update Flow: Synchronize child records (Summons, Paanyaya, Hearings)
    $partyModel->propagatePartyUpdates($complaintId);

    // 7. Audit Logging
    $audit->log(
        $encodedBy,
        $isUpdate ? 'Updated Complaint Facts & Parties' : 'Created Complaint with Resident Linkage',
        'Complaints',
        $complaintId
    );

    $db->commit();

    // 8. Check for Concurrent Disputes
    $concurrentDisputes = $partyModel->checkConcurrentDisputesForComplaint($complaintId);

    // Fetch complaint number for response
    $cNumStmt = $db->prepare('SELECT complaint_number, status FROM complaints WHERE complaint_id = ?');
    $cNumStmt->execute([$complaintId]);
    $complaintRow = $cNumStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'is_update' => $isUpdate,
        'complaint_id' => $complaintId,
        'complaint_number' => $complaintRow['complaint_number'] ?? null,
        'status' => $complaintRow['status'] ?? 'Mediation',
        'complainant_resident_id' => $compResidentId,
        'respondent_resident_id' => $respResidentId,
        'concurrent_disputes' => $concurrentDisputes,
        'message' => $isUpdate 
            ? 'Complaint facts and relational parties successfully updated.' 
            : 'Complaint successfully created with unified resident linkage.',
        'redirect_url' => '../../frontend/pages/complaints/complaint-details.php?id=' . $complaintId . '&tab=mediation'
    ]);

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to save complaint: ' . $e->getMessage()
    ]);
}
