<?php

require_once __DIR__ . '/../models/Resident.php';

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

    public function store($data)
{
    $result = $this->resident->create($data);

    if($result)
    {
        require_once __DIR__ .
        '/../services/AuditService.php';

        $audit = new AuditService();

        $audit->log(
            $_SESSION['user_id'],
            'Created Resident',
            'Residents'
        );
    }

    return $result;
}

    public function update($id,$data)
{
    $result =
        $this->resident->update(
            $id,
            $data
        );

    if($result)
    {
        require_once __DIR__ .
        '/../services/AuditService.php';

        $audit = new AuditService();

        $audit->log(
            $_SESSION['user_id'],
            'Updated Resident',
            'Residents',
            $id
        );
    }

    return $result;
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