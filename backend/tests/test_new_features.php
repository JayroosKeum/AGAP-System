<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/ComplaintRoutingService.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';
require_once __DIR__ . '/../services/ValidationService.php';
require_once __DIR__ . '/../config/database.php';

$assert = static function (bool $cond, string $message): void {
    if (!$cond) {
        fwrite(STDERR, "FAILED: {$message}\n");
        exit(1);
    }
};

// 1. Keyword-based routing engine tests
$vawc = ComplaintRoutingService::evaluate([
    'complaint_title' => 'Domestic violence report',
    'narrative' => 'Physical abuse by husband and economic abuse.',
    'case_type' => 'Criminal',
]);
$assert($vawc['route'] === 'VAWC' && !$vawc['kp_eligible'], 'VAWC keyword routing should route to VAWC');

$bcpc = ComplaintRoutingService::evaluate([
    'complaint_title' => 'Neighborhood dispute involving minor',
    'narrative' => 'Child neglect and bullying in school.',
    'involves_minor' => true,
]);
$assert($bcpc['route'] === 'BCPC' && !$bcpc['kp_eligible'], 'BCPC routing should flag minor involvement');

$theftOver = ComplaintRoutingService::evaluate([
    'complaint_title' => 'Stolen bicycle',
    'narrative' => 'Respondent stole a bicycle worth ₱12,500.',
    'case_type' => 'Criminal',
]);
$assert($theftOver['route'] === 'PNP' && !$theftOver['kp_eligible'], 'Theft over 5,000 should route to PNP');

$civilOver = ComplaintRoutingService::evaluate([
    'complaint_title' => 'Unpaid construction contract',
    'narrative' => 'Balance of PHP 350,000 remains unpaid.',
    'case_type' => 'Civil',
]);
$assert($civilOver['route'] === 'OVER_LIMIT', 'Civil claim over 200,000 should flag OVER_LIMIT');

$kpOk = ComplaintRoutingService::evaluate([
    'complaint_title' => 'Noise complaint',
    'narrative' => 'Loud karaoke past 10 PM.',
    'case_type' => 'Civil',
]);
$assert($kpOk['route'] === 'KP_ELIGIBLE' && $kpOk['kp_eligible'], 'Standard noise dispute should be KP_ELIGIBLE');

// 2. AES-256-GCM PII encryption tests
$crypto = new PiiCryptoService('test-secret-key-for-agap-pii');
$assert($crypto->isConfigured(), 'PiiCryptoService should report configured when key is provided');
$sample = '09171234567';
$encrypted = $crypto->encrypt($sample);
$assert(PiiCryptoService::isEncrypted($encrypted) && $encrypted !== $sample, 'Encrypted output should use enc:v1: prefix');
$assert($crypto->decrypt($encrypted) === $sample, 'Decrypted value should match original plaintext');
$assert($crypto->decrypt('legacy-plaintext') === 'legacy-plaintext', 'Legacy plaintext should pass through decrypt untouched');
$assert($crypto->blindIndex('09171234567') === $crypto->blindIndex(' 09171234567 '), 'Blind index should normalize whitespace');

$tamperCaught = false;
try {
    $crypto->decrypt(substr($encrypted, 0, -4) . 'AAAA');
} catch (RuntimeException $e) {
    $tamperCaught = true;
}
$assert($tamperCaught, 'GCM tag verification must reject tampered ciphertext');

// 3. Database connectivity & ServerDashboardController latest-attempt query check
$db = (new Database())->connect();
$stmt = $db->query("
    SELECT COUNT(*)
    FROM paanyaya_service_attempts a
    INNER JOIN (
        SELECT MAX(attempt_id) AS latest_attempt_id
        FROM paanyaya_service_attempts
        GROUP BY complaint_id, invitation_number
    ) latest_pa ON latest_pa.latest_attempt_id = a.attempt_id
");
$assert($stmt !== false, 'Latest Paanyaya attempt deduplication query should execute cleanly');

// 4. Input & Scheduling ValidationService tests
$assert(ValidationService::phone('09171234567') === true, 'Valid PH mobile should pass');
$assert(ValidationService::phone('+63 917 123 4567') === true, 'Valid +63 PH mobile should pass');
$assert(ValidationService::phone('0917ABC4567') === false, 'Phone number containing letters must fail');
$assert(ValidationService::phone('12345') === false, 'Short invalid number must fail');
$assert(ValidationService::futureOrTodayDate(date('Y-m-d')) === true, 'Today should pass futureOrTodayDate');
$assert(ValidationService::futureOrTodayDate('2020-01-01') === false, 'Past date must fail futureOrTodayDate');
$assert(ValidationService::pastOrTodayDate(date('Y-m-d')) === true, 'Today should pass pastOrTodayDate');
$assert(ValidationService::pastOrTodayDate('2099-01-01') === false, 'Future date must fail pastOrTodayDate');
$assert(ValidationService::pastOrTodayDate('1850-01-01') === false, 'Pre-1900 date must fail pastOrTodayDate');
$assert(ValidationService::workingDay('2026-11-18') === true, 'Wednesday 2026-11-18 should be a working day');
$assert(ValidationService::workingDay('2026-11-21') === false, 'Saturday 2026-11-21 should not be a working day');

echo "test_new_features passed all assertions.\n";
