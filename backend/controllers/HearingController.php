<?php

require_once __DIR__ . '/../models/Hearing.php';
require_once __DIR__ . '/../models/Attendance.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class HearingController
{
    private $hearing;
    private $attendance;
    private $audit;

    public function __construct()
    {
        $this->hearing = new Hearing();
        $this->attendance = new Attendance();
        $this->audit = new AuditService();
    }

    public function create($data)
    {
        $result = $this->hearing->create($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Created Hearing',
                'Hearings'
            );
        }

        return $result;
    }

    public function update($id, $data)
    {
        $result = $this->hearing->update($id, $data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Updated Hearing',
                'Hearings',
                $id
            );
        }

        return $result;
    }

    public function calendar()
    {
        return $this->hearing->getAll();
    }

    public function attendance($data)
    {
        $result = $this->attendance->record($data);

        if ($result) {
            $this->audit->log(
                $_SESSION['user_id'],
                'Recorded Attendance',
                'Hearings'
            );
        }

        return $result;
    }
}