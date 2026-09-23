<?php

require_once __DIR__ . '/../config/database.php';

class User {

    private PDO $db;

    public function __construct()
    {
        $database = new Database();
        $this->db = $database->connect();
    }

    public function findByUsername(string $username): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM users WHERE username = ?"
        );

        $stmt->execute([$username]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(array $data): array
    {
        $stmt = $this->db->prepare("
            INSERT INTO users
            (
                first_name,
                last_name,
                username,
                email,
                contact_no,
                password_hash,
                role_id
            )
            VALUES
            (
                ?,?,?,?,?,?,?
            )
        ");

        try {
            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['username'],
                $data['email'],
                $data['contact_no'],
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['role_id']
            ]);
            return ['success' => true, 'id' => (int) $this->db->lastInsertId()];
        } catch (PDOException $exception) {
            return $this->databaseError($exception);
        }
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

    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.contact_no, u.role_id, r.role_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id ORDER BY u.last_name, u.first_name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function roleExists(int $roleId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM roles WHERE role_id = ?');
        $stmt->execute([$roleId]);
        return (bool) $stmt->fetchColumn();
    }

    public function usernameInUse(string $username, ?int $exceptUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE username = ?';
        $values = [$username];
        if ($exceptUserId !== null) {
            $sql .= ' AND user_id <> ?';
            $values[] = $exceptUserId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($values);
        return (bool) $stmt->fetchColumn();
    }

    public function emailInUse(string $email, ?int $exceptUserId = null): bool
    {
        $sql = 'SELECT 1 FROM users WHERE email = ?';
        $values = [$email];
        if ($exceptUserId !== null) {
            $sql .= ' AND user_id <> ?';
            $values[] = $exceptUserId;
        }
        $stmt = $this->db->prepare($sql . ' LIMIT 1');
        $stmt->execute($values);
        return (bool) $stmt->fetchColumn();
    }

    public function update(int $id, array $data): array
    {
        try {
            $sql = 'UPDATE users SET first_name=?, last_name=?, username=?, email=?, contact_no=?, role_id=?';
            $values = [$data['first_name'], $data['last_name'], $data['username'], $data['email'], $data['contact_no'], $data['role_id']];
            if ($data['password'] !== '') {
                $sql .= ', password_hash=?';
                $values[] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            $sql .= ' WHERE user_id=?';
            $values[] = $id;
            $stmt = $this->db->prepare($sql);
            $stmt->execute($values);
            if ($stmt->rowCount() === 0) {
                $exists = $this->db->prepare('SELECT 1 FROM users WHERE user_id = ?');
                $exists->execute([$id]);
                return $exists->fetchColumn()
                    ? ['success' => true]
                    : ['success' => false, 'message' => 'User was not found.'];
            }
            return ['success' => true];
        } catch (PDOException $exception) {
            return $this->databaseError($exception);
        }
    }

    public function delete(int $id): array
    {
        try {
            $stmt = $this->db->prepare('DELETE FROM users WHERE user_id=?');
            $stmt->execute([$id]);
            return $stmt->rowCount() === 1 ? ['success' => true] : ['success' => false, 'message' => 'User was not found.'];
        } catch (PDOException $exception) {
            return ['success' => false, 'message' => 'This user cannot be deleted because their account is referenced by case records.'];
        }
    }

    private function databaseError(PDOException $exception): array
    {
        if ($exception->getCode() === '23000') return ['success' => false, 'message' => 'Username or email address is already in use.'];
        error_log($exception->getMessage());
        return ['success' => false, 'message' => 'Unable to save the user account.'];
    }
}
