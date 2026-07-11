<?php

require_once __DIR__ . '/../models/Search.php';

class SearchController
{
    private $search;

    public function __construct()
    {
        $this->search = new Search();
    }

    public function records(array $filters): array
    {
        return $this->search->records($filters);
    }
}
