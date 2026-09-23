<?php
require_once __DIR__ . '/../models/User.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/ValidationService.php';

class UserController {
    private User $user;
    private AuditService $audit;

    public function __construct()
    {
        $this->user = new User();
        $this->audit = new AuditService();
    }

    public function index(): array
    {
        return $this->user->getAll();
    }

    public function create(array $data, int $actorId): array
    {
        $validated = $this->validate($data, true);
        if (!$validated['success']) return $validated;
        $result = $this->user->create($validated['data']);
        if ($result['success']) $this->audit->log($actorId, 'Created user account', 'User Management', $result['id']);
        return $result + ['message' => $result['success'] ? 'User account created.' : ($result['message'] ?? 'Unable to create the user account.')];
    }

    public function update(int $id, array $data, int $actorId): array
    {
        if ($id < 1) return ['success' => false, 'message' => 'A valid user is required.'];
        $validated = $this->validate($data, false, $id);
        if (!$validated['success']) return $validated;
        $result = $this->user->update($id, $validated['data']);
        if ($result['success']) $this->audit->log($actorId, 'Updated user account', 'User Management', $id);
        return $result + ['message' => $result['success'] ? 'User account updated.' : ($result['message'] ?? 'Unable to update the user account.')];
    }

    public function delete(int $id, int $actorId): array
    {
        if ($id < 1) return ['success' => false, 'message' => 'A valid user is required.'];
        if ($id === $actorId) return ['success' => false, 'message' => 'You cannot delete your own account.'];
        $result = $this->user->delete($id);
        if ($result['success']) $this->audit->log($actorId, 'Deleted user account', 'User Management', $id);
        return $result + ['message' => $result['success'] ? 'User account deleted.' : ($result['message'] ?? 'Unable to delete the user account.')];
    }

    private function validate(array $data, bool $passwordRequired, ?int $exceptUserId = null): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $username = trim((string) ($data['username'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        $contactNo = trim((string) ($data['contact_no'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $roleId = filter_var($data['role_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (!ValidationService::name($firstName)) return ['success' => false, 'message' => 'Please enter a valid first name (up to 100 characters).'];
        if (!ValidationService::name($lastName)) return ['success' => false, 'message' => 'Please enter a valid last name (up to 100 characters).'];
        if (!preg_match('/^[A-Za-z0-9_.-]{3,100}$/', $username)) return ['success' => false, 'message' => 'Username must be 3 to 100 letters, numbers, dots, underscores, or hyphens.'];
        if ($this->user->usernameInUse($username, $exceptUserId)) return ['success' => false, 'message' => 'That username is already in use. Please choose another.'];
        if ($email === '' || mb_strlen($email) > 150 || !ValidationService::email($email)) return ['success' => false, 'message' => 'Please enter a valid email address (up to 150 characters).'];
        if ($this->user->emailInUse($email, $exceptUserId)) return ['success' => false, 'message' => 'That email address is already in use. Please enter another.'];
        if (!ValidationService::phone($contactNo) || mb_strlen($contactNo) > 20) return ['success' => false, 'message' => 'Please enter a valid Philippine telephone number (up to 20 characters).'];
        if (!$roleId || !$this->user->roleExists((int) $roleId)) return ['success' => false, 'message' => 'Choose a valid role.'];
        if ($passwordRequired && trim($password) === '') return ['success' => false, 'message' => 'Please enter a password for the new user account.'];
        if ($password !== '' && (strlen($password) < 12 || !preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\\d/', $password))) return ['success' => false, 'message' => 'Use at least 12 characters with uppercase, lowercase, and a number for the password.'];

        return ['success' => true, 'data' => ['first_name' => $firstName, 'last_name' => $lastName, 'username' => $username, 'email' => $email, 'contact_no' => $contactNo === '' ? null : $contactNo, 'password' => $password, 'role_id' => (int) $roleId]];
    }
}
