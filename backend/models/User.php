<?php

require_once __DIR__ . '/../config/database.php';

class User {

    private $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    public function findByUsername($username)
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE username = ?"
        );

        $stmt->execute([$username]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data)
    {
        $stmt = $this->db->prepare("
            INSERT INTO users
            (
                first_name,
                last_name,
                username,
                email,
                password_hash,
                role_id
            )
            VALUES
            (
                ?,?,?,?,?,?
            )
        ");

        return $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['username'],
            $data['email'],
            password_hash(
                $data['password'],
                PASSWORD_DEFAULT
            ),
            $data['role_id']
        ]);
    }
}