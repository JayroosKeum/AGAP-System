<?php

declare(strict_types=1);

/**
 * Idempotent PII encryption backfill and key-rotation CLI utility (A6).
 *
 * Usage:
 *   php backend/cli/pii_backfill_and_rotate.php           # Encrypt plaintext rows & populate blind indexes
 *   php backend/cli/pii_backfill_and_rotate.php --rotate  # Re-encrypt all rows under current APP_PII_KEY (using APP_PII_KEYS_LEGACY to decrypt)
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI access only.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PiiCryptoService.php';

$rotate = in_array('--rotate', $argv ?? [], true);
$db = (new Database())->connect();
$crypto = new PiiCryptoService();

if (!$crypto->isConfigured()) {
    fwrite(STDERR, "ERROR: APP_PII_KEY must be configured in environment/.env.\n");
    exit(1);
}

$stats = ['residents' => 0, 'complaint_parties' => 0, 'users' => 0];

// 1. Residents
$resRows = $db->query('SELECT resident_id, contact_no, contact_no_hash, contact_last4_hash, email, email_hash, address, house_street FROM residents')->fetchAll(PDO::FETCH_ASSOC);
$resUpd = $db->prepare(
    'UPDATE residents
     SET contact_no = ?, contact_no_hash = ?, contact_last4_hash = ?,
         email = ?, email_hash = ?, address = ?, house_street = ?
     WHERE resident_id = ?'
);

foreach ($resRows as $row) {
    $contactPlain = $crypto->decrypt($row['contact_no']);
    $emailPlain = $crypto->decrypt($row['email']);
    $addressPlain = $crypto->decrypt($row['address']);
    $streetPlain = $crypto->decrypt($row['house_street']);

    $contactHash = $crypto->contactBlindIndex($contactPlain);
    $last4Hash = $crypto->contactLast4BlindIndex($contactPlain);
    $emailHash = $crypto->emailBlindIndex($emailPlain);

    $needsUpdate = $rotate
        || ($contactPlain !== null && $contactPlain !== '' && !PiiCryptoService::isEncrypted($row['contact_no']))
        || ($emailPlain !== null && $emailPlain !== '' && !PiiCryptoService::isEncrypted($row['email']))
        || ($addressPlain !== null && $addressPlain !== '' && !PiiCryptoService::isEncrypted($row['address']))
        || ($streetPlain !== null && $streetPlain !== '' && !PiiCryptoService::isEncrypted($row['house_street']))
        || $row['contact_no_hash'] !== $contactHash
        || $row['contact_last4_hash'] !== $last4Hash
        || $row['email_hash'] !== $emailHash;

    if ($needsUpdate) {
        $resUpd->execute([
            $crypto->encrypt($contactPlain),
            $contactHash,
            $last4Hash,
            $crypto->encrypt($emailPlain),
            $emailHash,
            $crypto->encrypt($addressPlain),
            $crypto->encrypt($streetPlain),
            (int) $row['resident_id'],
        ]);
        $stats['residents']++;
    }
}

// 2. Complaint Parties (guardian fields)
$cpRows = $db->query('SELECT party_id, guardian_name, guardian_contact, guardian_address FROM complaint_parties')->fetchAll(PDO::FETCH_ASSOC);
$cpUpd = $db->prepare('UPDATE complaint_parties SET guardian_name = ?, guardian_contact = ?, guardian_address = ? WHERE party_id = ?');
foreach ($cpRows as $row) {
    $gName = $crypto->decrypt($row['guardian_name']);
    $gContact = $crypto->decrypt($row['guardian_contact']);
    $gAddr = $crypto->decrypt($row['guardian_address']);

    $needsUpdate = $rotate
        || ($gName !== null && $gName !== '' && !PiiCryptoService::isEncrypted($row['guardian_name']))
        || ($gContact !== null && $gContact !== '' && !PiiCryptoService::isEncrypted($row['guardian_contact']))
        || ($gAddr !== null && $gAddr !== '' && !PiiCryptoService::isEncrypted($row['guardian_address']));

    if ($needsUpdate) {
        $cpUpd->execute([
            $crypto->encrypt($gName),
            $crypto->encrypt($gContact),
            $crypto->encrypt($gAddr),
            (int) $row['party_id'],
        ]);
        $stats['complaint_parties']++;
    }
}

// 3. Users (contact_no encrypted; email_hash & contact_no_hash blind-indexed)
$userRows = $db->query('SELECT user_id, email, email_hash, contact_no, contact_no_hash FROM users')->fetchAll(PDO::FETCH_ASSOC);
$userUpd = $db->prepare('UPDATE users SET email_hash = ?, contact_no = ?, contact_no_hash = ? WHERE user_id = ?');
foreach ($userRows as $row) {
    $contactPlain = $crypto->decrypt($row['contact_no']);
    $emailHash = $crypto->emailBlindIndex($row['email']);
    $contactHash = $crypto->contactBlindIndex($contactPlain);

    $needsUpdate = $rotate
        || ($contactPlain !== null && $contactPlain !== '' && !PiiCryptoService::isEncrypted($row['contact_no']))
        || $row['email_hash'] !== $emailHash
        || $row['contact_no_hash'] !== $contactHash;

    if ($needsUpdate) {
        $userUpd->execute([
            $emailHash,
            $crypto->encrypt($contactPlain),
            $contactHash,
            (int) $row['user_id'],
        ]);
        $stats['users']++;
    }
}

echo json_encode([
    'success' => true,
    'mode' => $rotate ? 'rotate' : 'backfill',
    'updated_rows' => $stats,
], JSON_PRETTY_PRINT) . PHP_EOL;
