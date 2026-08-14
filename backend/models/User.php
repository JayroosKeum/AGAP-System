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

    public function findActiveByUsernameOrEmail(string $identity): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT user_id, username, email, first_name, last_name FROM users
             WHERE status = 'Active' AND (username = ? OR email = ?) LIMIT 1"
        );
        $stmt->execute([$identity, $identity]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function verifyPassword(int $userId, string $password): bool
    {
        $stmt = $this->db->prepare('SELECT password_hash FROM users WHERE user_id = ? AND status = \'Active\'');
        $stmt->execute([$userId]);
        $hash = $stmt->fetchColumn();
        return is_string($hash) && password_verify($password, $hash);
    }

    public function updatePassword(int $userId, string $password): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND status = \'Active\'');
        return $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $userId]) && $stmt->rowCount() === 1;
    }

    public function createPasswordResetToken(int $userId, string $tokenHash, string $expiresAt): bool
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
            $stmt = $this->db->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $tokenHash, $expiresAt]);
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log($exception->getMessage());
            return false;
        }
    }

    public function consumePasswordResetToken(string $tokenHash, string $password): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT reset_id, user_id FROM password_reset_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW() FOR UPDATE');
            $stmt->execute([$tokenHash]);
            $token = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$token) { $this->db->rollBack(); return ['success' => false, 'message' => 'This reset link is invalid or has expired.']; }
            $update = $this->db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ? AND status = \'Active\'');
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $token['user_id']]);
            if ($update->rowCount() !== 1) { $this->db->rollBack(); return ['success' => false, 'message' => 'This account is no longer active.']; }
            $this->db->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE reset_id = ?')->execute([$token['reset_id']]);
            $this->db->commit();
            return ['success' => true, 'user_id' => (int) $token['user_id']];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack(); error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to reset the password.'];
        }
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
