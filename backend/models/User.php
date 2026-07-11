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

    public function getAll()
    {
        $stmt = $this->db->query('SELECT user_id, first_name, last_name, username, email, role_id FROM users ORDER BY last_name, first_name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function update($id, $data)
    {
        $sql = 'UPDATE users SET first_name=?, last_name=?, username=?, email=?, role_id=?';
        $values = [$data['first_name'], $data['last_name'], $data['username'], $data['email'], $data['role_id']];
        if (!empty($data['password'])) {
            $sql .= ', password_hash=?';
            $values[] = password_hash($data['password'], PASSWORD_DEFAULT);
        }
        $sql .= ' WHERE user_id=?';
        $values[] = $id;
        return $this->db->prepare($sql)->execute($values);
    }

    public function delete($id)
    {
        return $this->db->prepare('DELETE FROM users WHERE user_id=?')->execute([$id]);
    }
}
