<?php
declare(strict_types=1);

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');

        session_set_cookie_params([
            'httponly' => true,
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}

function authenticateAdmin(PDO $pdo, string $username, string $password): bool
{
    if ($username !== 'admin' || $password === '') {
        return false;
    }

    $statement = $pdo->prepare(
        "SELECT
            users.user_id,
            users.first_name,
            users.middle_name,
            users.last_name,
            users.username,
            users.email,
            users.contact_no,
            users.password_hash,
            users.status,
            roles.role_id,
            roles.role_name
         FROM users
         INNER JOIN roles ON roles.role_id = users.role_id
         WHERE users.username = :username
           AND users.status = 'Active'
           AND roles.role_name = 'Administrator'
         LIMIT 1"
    );

    $statement->execute([
        'username' => $username,
    ]);

    $admin = $statement->fetch();

    if (!$admin || !password_verify($password, $admin['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);

    $_SESSION['admin_id'] = (int) $admin['user_id'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_first_name'] = $admin['first_name'];
    $_SESSION['admin_middle_name'] = $admin['middle_name'];
    $_SESSION['admin_last_name'] = $admin['last_name'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_contact_no'] = $admin['contact_no'];
    $_SESSION['admin_role_id'] = (int) $admin['role_id'];
    $_SESSION['admin_role_name'] = $admin['role_name'];

    return true;
}

function requireAdmin(): void
{
    if (
        !isset($_SESSION['admin_id'], $_SESSION['admin_role_name']) ||
        (int) $_SESSION['admin_id'] < 1 ||
        $_SESSION['admin_role_name'] !== 'Administrator'
    ) {
        header('Location: index.php?page=login');
        exit;
    }
}

function logoutAdmin(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $parameters = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $parameters['path'],
            $parameters['domain'],
            $parameters['secure'],
            $parameters['httponly']
        );
    }

    session_destroy();
}