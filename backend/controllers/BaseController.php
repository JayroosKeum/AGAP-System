<?php

require_once __DIR__ .
'/../services/AuditService.php';

class BaseController
{
    protected $audit;

    public function __construct()
    {
        if(
            session_status() ===
            PHP_SESSION_NONE
        )
        {
            session_start();
        }

        $this->audit =
            new AuditService();
    }
}