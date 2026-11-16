<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

require_once __DIR__ . '/../controllers/AuthController.php';
require_once __DIR__ . '/../../vendor/autoload.php';

function check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function signingKey(): array
{
    $options = ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048];
    $laragonConfig = dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf';
    if (is_readable($laragonConfig)) {
        $options['config'] = $laragonConfig;
    }
    $key = openssl_pkey_new($options);
    check($key !== false && openssl_pkey_export($key, $private, null, $options), 'Generate the test signing key');
    return [$private, openssl_pkey_get_details($key)['key']];
}

function expectRejected(callable $verify): void
{
    try {
        $verify();
    } catch (DomainException | UnexpectedValueException) {
        return;
    }
    throw new RuntimeException('An invalid Google credential was accepted');
}

$db = (new Database())->connect();
$target = (string) $db->query('SELECT DATABASE()')->fetchColumn();
check($target !== 'agap_db' && $target === (string) getenv('DB_NAME'), 'Use a disposable test database');

session_start();
$created = [];
try {
    $fixture = bin2hex(random_bytes(6));
    $client = 'fixture-client.apps.googleusercontent.com';
    putenv('GOOGLE_RESIDENT_CLIENT_ID=' . $client);
    $nonce = bin2hex(random_bytes(32));
    $email = 'resident-' . $fixture . '@gmail.com';
    $subject = 'google-' . $fixture;
    [$private, $public] = signingKey();
    [$otherPrivate] = signingKey();
    $keys = ['fixture' => new Key($public, 'RS256')];
    $claims = [
        'iss' => 'https://accounts.google.com', 'aud' => $client, 'iat' => time() - 5,
        'exp' => time() + 120, 'sub' => $subject, 'email' => $email, 'email_verified' => true, 'nonce' => $nonce,
    ];
    $service = new GoogleResidentIdentity();
    $method = new ReflectionMethod($service, 'verifyCredential');
    $verify = static fn (array $fields, string $signer): array =>
        $method->invoke($service, JWT::encode($fields, $signer, 'RS256', 'fixture'), $nonce, $client, $keys);
    $identity = $verify($claims, $private);
    foreach (['aud' => 'wrong', 'iss' => 'wrong', 'exp' => time() - 1, 'nonce' => 'wrong',
        'email_verified' => false, 'email' => 'fixture@example.test'] as $claim => $value) {
        expectRejected(static fn () => $verify(array_replace($claims, [$claim => $value]), $private));
    }
    expectRejected(static fn () => $verify($claims, $otherPrivate));

    $auth = new AuthController();
    $data = [
        'first_name' => 'Google', 'last_name' => 'Fixture', 'username' => 'google_' . $fixture,
        'password' => 'FixturePassword46', 'password_confirmation' => 'FixturePassword46',
        'terms_agreed' => '1', 'email' => 'override-' . $fixture . '@example.test',
    ];
    check($auth->registerResident($data)['code'] === 'google_required', 'Registration requires Google proof when configured');
    check($auth->acceptResidentGoogleIdentity($identity) === 'register', 'Google begins a new resident registration');
    $account = $auth->registerResident($data);
    check($account['success'] && $account['google_verified'], 'Google registration succeeds');
    $created[] = $account;
    $lookup = $db->prepare('SELECT u.email, u.google_subject, r.residency_verified FROM users u
        JOIN resident_user_profiles p USING (user_id) JOIN residents r USING (resident_id) WHERE u.user_id = ?');
    $lookup->execute([$account['user_id']]);
    $profile = $lookup->fetch();
    check($profile['email'] === $email && $profile['google_subject'] === $subject, 'Store the signed identity instead of the posted email');
    check((int) $profile['residency_verified'] === 0, 'Email confirmation preserves separate residency review');
    check($auth->acceptResidentGoogleIdentity($identity) === 'signed_in', 'The same Google subject signs in');
    check((int) $_SESSION['user_id'] === (int) $account['user_id'], 'Google signs in to the matching account');
    check($auth->acceptResidentGoogleIdentity(['email' => $email, 'google_subject' => 'different-' . $fixture]) === 'email_in_use',
        'A different subject cannot link by matching email');
    check($auth->loginResident($data['username'], $data['password']), 'Password sign-in still works');

    putenv('GOOGLE_RESIDENT_CLIENT_ID=disabled');
    $data['username'] = 'legacy_' . $fixture;
    $legacy = $auth->registerResident($data);
    check($legacy['success'] && !$legacy['google_verified'], 'Registration remains available with a password when Google is unconfigured');
    $created[] = $legacy;
    check($auth->loginResident($data['username'], $data['password']), 'The password-only account can sign in');
} finally {
    foreach ($created as $account) {
        $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$account['user_id']]);
        $db->prepare('DELETE FROM residents WHERE resident_id = ?')->execute([$account['resident_id']]);
    }
    session_destroy();
}

echo "PASS: Google token verification, resident registration, returning sign-in, and password-only fallback.\n";
