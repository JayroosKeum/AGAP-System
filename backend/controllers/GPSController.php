<?php

require_once __DIR__ . '/../models/Location.php';
require_once __DIR__ . '/../models/ProofOfService.php';

class GPSController
{
    private $location;
    private $proof;

    public function __construct()
    {
        $this->location = new Location();
        $this->proof = new ProofOfService();
    }

    public function saveLocation($data)
    {
        return $this->location->saveLocation($data);
    }

    public function getLocation($complaintId)
    {
        return $this->location->getLocation($complaintId);
    }

    public function saveProof($data)
    {
        return $this->proof->create($data);
    }

    public function getProofs($caseId)
    {
        return $this->proof->getByCase($caseId);
    }
}