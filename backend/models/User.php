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
            "SELECT u.*, r.role_name
             FROM users u
             INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.username = ?"
        );

        $stmt->execute([$username]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create(array $data): array
    {
        $password = (string) ($data['password'] ?? '');
        $hasPassword = trim($password) !== '';
        $passwordHash = $hasPassword ? password_hash($password, PASSWORD_DEFAULT) : null;
        $status = $hasPassword ? 'Active' : 'Inactive';
        $token = $hasPassword ? null : ($data['email_verification_token'] ?? null);
        $expires = $hasPassword ? null : ($data['email_verification_expires_at'] ?? null);

        $stmt = $this->db->prepare("
            INSERT INTO users
            (
                first_name,
                last_name,
                username,
                email,
                contact_no,
                password_hash,
                role_id,
                status,
                email_verification_token,
                email_verification_expires_at
            )
            VALUES
            (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )
        ");

        try {
            $stmt->execute([
                $data['first_name'],
                $data['last_name'],
                $data['username'],
                $data['email'],
                $data['contact_no'],
                $passwordHash,
                $data['role_id'],
                $status,
                $token,
                $expires
            ]);
            return ['success' => true, 'id' => (int) $this->db->lastInsertId(), 'status' => $status];
        } catch (PDOException $exception) {
            return $this->databaseError($exception);
        }
    }

    public function pendingVerificationRecipient(int $userId): array|false
    {
        $stmt = $this->db->prepare(
            'SELECT u.user_id, u.first_name, u.username, u.email, r.role_name
             FROM users u INNER JOIN roles r ON r.role_id = u.role_id
             WHERE u.user_id = ? AND u.status = \'Inactive\' AND u.email_verification_token IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function replaceEmailVerificationToken(int $userId, string $tokenHash, string $expiresAt): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET email_verification_token = ?, email_verification_expires_at = ?
             WHERE user_id = ? AND status = 'Inactive'"
        );
        return $stmt->execute([$tokenHash, $expiresAt, $userId]) && $stmt->rowCount() === 1;
    }

    public function consumeEmailVerificationToken(string $tokenHash, string $password): array
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                "SELECT user_id FROM users
                 WHERE email_verification_token = ? AND email_verification_expires_at >= NOW()
                   AND status = 'Inactive' FOR UPDATE"
            );
            $stmt->execute([$tokenHash]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'This verification link is invalid or has expired.'];
            }
            $update = $this->db->prepare(
                "UPDATE users SET password_hash = ?, status = 'Active', email_verification_token = NULL,
                 email_verification_expires_at = NULL WHERE user_id = ?"
            );
            $update->execute([password_hash($password, PASSWORD_DEFAULT), $user['user_id']]);
            $this->db->commit();
            return ['success' => true, 'user_id' => (int) $user['user_id']];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log($exception->getMessage());
            return ['success' => false, 'message' => 'Unable to verify this account.'];
        }
    }

    public function hasValidEmailVerificationToken(string $tokenHash): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM users WHERE email_verification_token = ?
             AND email_verification_expires_at >= NOW() AND status = 'Inactive' LIMIT 1"
        );
        $stmt->execute([$tokenHash]);
        return (bool) $stmt->fetchColumn();
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

    public function hasValidPasswordResetToken(string $tokenHash): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM password_reset_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at >= NOW() LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        return (bool) $stmt->fetchColumn();
    }

    public function getAll(): array
    {
        $stmt = $this->db->query('SELECT u.user_id, u.first_name, u.last_name, u.username, u.email, u.contact_no, u.role_id, u.status, r.role_name FROM users u INNER JOIN roles r ON r.role_id = u.role_id ORDER BY u.last_name, u.first_name');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function roleExists(int $roleId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM roles WHERE role_id = ?');
        $stmt->execute([$roleId]);
        return (bool) $stmt->fetchColumn();
    }

    public function roleName(int $roleId): ?string
    {
        $stmt = $this->db->prepare('SELECT role_name FROM roles WHERE role_id = ?');
        $stmt->execute([$roleId]);
        $name = $stmt->fetchColumn();
        return is_string($name) ? $name : null;
    }

    public function createResidentProfileForUser(int $userId, array $data): bool
    {
        try {
            $this->db->beginTransaction();
            $resident = $this->db->prepare('INSERT INTO residents (first_name, last_name, contact_no, email, address, barangay, city_municipality, residency_verified) VALUES (?, ?, ?, ?, NULL, \'Tumana\', \'Marikina City\', 0)');
            $resident->execute([$data['first_name'], $data['last_name'], $data['contact_no'] ?: null, $data['email']]);
            $residentId = (int) $this->db->lastInsertId();
            $this->db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)')->execute([$userId, $residentId]);
            $this->db->commit();
            return true;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            error_log($exception->getMessage());
            return false;
        }
    }

    /**
     * Create the public resident account, its matching resident record, and the
     * profile link as one transaction.  A resident must never be able to sign in
     * without a corresponding resident profile.
     */
    public function createResidentAccount(array $data): array
    {
        try {
            $this->db->beginTransaction();

            $role = $this->db->prepare("SELECT role_id FROM roles WHERE role_name = 'Resident' LIMIT 1");
            $role->execute();
            $residentRoleId = $role->fetchColumn();
            if ($residentRoleId === false) {
                throw new RuntimeException('The Resident role has not been configured.');
            }

            $user = $this->db->prepare(
                "INSERT INTO users (first_name, last_name, username, email, contact_no, password_hash, role_id, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')"
            );
            $user->execute([
                $data['first_name'],
                $data['last_name'],
                $data['username'],
                $data['email'],
                $data['contact_no'] ?: null,
                password_hash($data['password'], PASSWORD_DEFAULT),
                $residentRoleId,
            ]);
            $userId = (int) $this->db->lastInsertId();

            $resident = $this->db->prepare(
                "INSERT INTO residents (first_name, last_name, contact_no, email, barangay, city_municipality, residency_verified)
                 VALUES (?, ?, ?, ?, 'Tumana', 'Marikina City', 0)"
            );
            $resident->execute([
                $data['first_name'],
                $data['last_name'],
                $data['contact_no'] ?: null,
                $data['email'],
            ]);
            $residentId = (int) $this->db->lastInsertId();

            $profile = $this->db->prepare('INSERT INTO resident_user_profiles (user_id, resident_id) VALUES (?, ?)');
            $profile->execute([$userId, $residentId]);

            $this->db->commit();
            return ['success' => true, 'user_id' => $userId, 'resident_id' => $residentId];
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                return ['success' => false, 'code' => 'duplicate', 'message' => 'That username or email address is already registered.'];
            }
            error_log($exception->getMessage());
            return ['success' => false, 'code' => 'unavailable', 'message' => 'Unable to create your account at this time.'];
        }
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
                $sql .= ', password_hash=?, status=\'Active\', email_verification_token=NULL, email_verification_expires_at=NULL';
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
