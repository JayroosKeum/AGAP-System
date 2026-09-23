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

    public function show($id)
    {
        return $this->resident->getById($id);
    }

    public function store($data): array
    {
        $validated = ValidationService::residentData(is_array($data) ? $data : []);
        if (!$validated['success']) return $validated;
        $saved = $this->resident->create($validated['data']);
        if (!$saved) return ['success' => false, 'message' => 'Unable to save the resident. Please check the information and try again.'];

        require_once __DIR__ . '/../services/AuditService.php';
        (new AuditService())->log($_SESSION['user_id'], 'Created Resident', 'Residents');
        return ['success' => true];
    }

    public function update($id, $data): array
    {
        $residentId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$residentId) return ['success' => false, 'message' => 'Please select a valid resident to update.'];
        $validated = ValidationService::residentData(is_array($data) ? $data : []);
        if (!$validated['success']) return $validated;
        $saved = $this->resident->update((int) $residentId, $validated['data']);
        if (!$saved) return ['success' => false, 'message' => 'Unable to update the resident. Please check the information and try again.'];

        require_once __DIR__ . '/../services/AuditService.php';
        (new AuditService())->log($_SESSION['user_id'], 'Updated Resident', 'Residents', (int) $residentId);
        return ['success' => true];
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
            'Deleted Resident',
            'Residents',
            $id
        );
    }

    return $result;
}
}
