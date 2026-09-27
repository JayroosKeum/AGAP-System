<?php

require_once __DIR__ . '/../models/Resident.php';
require_once __DIR__ . '/../services/ValidationService.php';

class ResidentController
{
    private $resident;

    public function __construct()
    {
        $this->resident = new Resident();
    }

    public function index()
    {
        return $this->resident->getAll();
    }

    public function page(int $page, int $perPage = 25): array
    {
        return $this->resident->getPage($page, $perPage);
    }

    public function show($id)
    {
        return $this->resident->getById($id);
    }

    public function store($data): array
    {
        $validated = ValidationService::residentData(is_array($data) ? $data : []);
        if (!$validated['success']) return $validated;

        $duplicate = $this->resident->findDuplicate($validated['data']);
        if ($duplicate !== null) {
            $existingName = trim(implode(' ', array_filter([$duplicate['first_name'], $duplicate['middle_name'] ?? '', $duplicate['last_name']])));
            $details = !empty($duplicate['address']) ? " at {$duplicate['address']}" : (!empty($duplicate['purok']) ? " in Purok {$duplicate['purok']}" : '');
            return [
                'success' => false,
                'message' => "A resident profile for {$existingName}{$details} already exists (Profile #{$duplicate['resident_id']}). To avoid misreporting, duplicate profiles for the same person are not permitted."
            ];
        }

        $savedId = $this->resident->create($validated['data']);
        if (!$savedId) return ['success' => false, 'message' => 'Unable to save the resident profile. Please check the information and try again.'];

        require_once __DIR__ . '/../services/AuditService.php';
        (new AuditService())->log($_SESSION['user_id'] ?? null, 'Created Resident Profile', 'Residents', (int) $savedId);
        return [
            'success' => true,
            'message' => 'Resident profile created successfully.',
            'id' => (int) $savedId
        ];
    }

    public function update($id, $data): array
    {
        $residentId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$residentId) return ['success' => false, 'message' => 'Please select a valid resident profile to update.'];
        $validated = ValidationService::residentData(is_array($data) ? $data : []);
        if (!$validated['success']) return $validated;

        $duplicate = $this->resident->findDuplicate($validated['data'], (int) $residentId);
        if ($duplicate !== null) {
            $existingName = trim(implode(' ', array_filter([$duplicate['first_name'], $duplicate['middle_name'] ?? '', $duplicate['last_name']])));
            $details = !empty($duplicate['address']) ? " at {$duplicate['address']}" : (!empty($duplicate['purok']) ? " in Purok {$duplicate['purok']}" : '');
            return [
                'success' => false,
                'message' => "Another resident profile for {$existingName}{$details} already exists (Profile #{$duplicate['resident_id']}). Duplicate profiles for the same person are not permitted."
            ];
        }

        $saved = $this->resident->update((int) $residentId, $validated['data']);
        if (!$saved) return ['success' => false, 'message' => 'Unable to update the resident profile. Please check the information and try again.'];

        require_once __DIR__ . '/../services/AuditService.php';
        (new AuditService())->log($_SESSION['user_id'] ?? null, 'Updated Resident Profile', 'Residents', (int) $residentId);
        return [
            'success' => true,
            'message' => 'Resident profile updated successfully.',
            'id' => (int) $residentId
        ];
    }

    public function destroy($id)
{
    $result =
        $this->resident->delete($id);

    if($result)
    {
        require_once __DIR__ .
        '/../services/AuditService.php';

        $audit = new AuditService();

        $audit->log(
            $_SESSION['user_id'],
            'Deleted Resident Profile',
            'Residents',
            $id
        );
    }

    return $result;
}
}
