<?php
require_once __DIR__ . '/../models/User.php';
class UserController {
    private $user;
    public function __construct(){ $this->user = new User(); }
    public function index(){ return $this->user->getAll(); }
    public function create($data){ return $this->user->create($data); }
    public function update($id,$data){ return $this->user->update($id,$data); }
    public function delete($id){ return $this->user->delete($id); }
}
