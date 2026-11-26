<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $host = (string) getenv('DB_HOST');
    $database = (string) getenv('DB_NAME');
    $username = (string) getenv('DB_USER');
    $port = filter_var(getenv('DB_PORT') ?: '3306', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
    if (!preg_match('/^[a-zA-Z0-9.-]+$/', $host) || !preg_match('/^[a-zA-Z0-9_-]+$/', $database) || $username === '' || $port === false) {
        throw new RuntimeException('Set DB_HOST, DB_NAME, DB_USER and DB_PORT for the target database.');
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10];
    if (getenv('WEBSITE_SITE_NAME') || getenv('DB_SSL_CA')) {
        $ca = getenv('DB_SSL_CA') ?: '/etc/ssl/certs/ca-certificates.crt';
        if (!is_readable($ca)) {
            throw new RuntimeException('The database CA file could not be read.');
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    $db = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $username, (string) getenv('DB_PASS'), $options);
    if ((string) $db->query('SELECT DATABASE()')->fetchColumn() !== $database) {
        throw new RuntimeException('The selected database does not match DB_NAME.');
    }

    $column = $db->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $columnType = static function (string $table, string $name) use ($column): string {
        $column->execute([$table, $name]);
        return (string) $column->fetchColumn();
    };
    if ($columnType('paanyaya_preparations', 'invitation_number') === '') {
        throw new RuntimeException('Install the earlier Paanyaya invitation migrations first.');
    }

    $legacyColumns = [
        ['settlements', 'settlement_status'],
        ['arbitration_records', 'award_status'],
        ['case_transmittals', 'source_document_id'],
        ['execution_proceedings', 'arbitration_id'],
    ];
    $orderFourReady = static function () use ($legacyColumns, $columnType): bool {
        foreach ($legacyColumns as [$table, $name]) {
            if ($columnType($table, $name) === '') {
                return false;
            }
        }
        return true;
    };
    $revisionA1A10Ready = static function () use ($db, $columnType): bool {
        $hasColumns = $columnType('complaints', 'deleted_at') !== ''
            && $columnType('complaints', 'assigned_queue') !== ''
            && $columnType('complaints', 'routing_rule_version') !== ''
            && $columnType('complaints', 'resolution_type') !== ''
            && $columnType('cases', 'deleted_at') !== ''
            && $columnType('residents', 'contact_no_hash') !== ''
            && $columnType('residents', 'contact_last4_hash') !== ''
            && $columnType('residents', 'email_hash') !== ''
            && $columnType('users', 'email_hash') !== ''
            && $columnType('users', 'contact_no_hash') !== ''
            && $columnType('audit_trails', 'entry_hash') !== ''
            && $columnType('audit_trails', 'prev_hash') !== ''
            && $columnType('closure_requests', 'failed_verification_attempts') !== ''
            && $columnType('closure_requests', 'locked_until') !== '';
        if (!$hasColumns) {
            return false;
        }
        $hasTables = (bool) $db->query("SHOW TABLES LIKE 'anonymous_concerns'")->fetchColumn()
            && (bool) $db->query("SHOW TABLES LIKE 'closure_requests'")->fetchColumn()
            && (bool) $db->query("SHOW TABLES LIKE 'complaint_routing_logs'")->fetchColumn();
        if (!$hasTables) {
            return false;
        }
        $hasSuperAdmin = (bool) $db->query("SELECT 1 FROM roles WHERE role_name = 'Super Administrator' LIMIT 1")->fetchColumn();
        $triggers = $db->query("SHOW TRIGGERS LIKE 'audit_trails'")->fetchAll(PDO::FETCH_COLUMN);
        $hasTriggers = in_array('trg_audit_trails_no_update', $triggers, true)
            && in_array('trg_audit_trails_no_delete', $triggers, true);
        return $hasSuperAdmin && $hasTriggers;
    };
    if (($argv[1] ?? null) !== '--check-only') {
        if (!(int) $db->query("SELECT GET_LOCK(CONCAT('agap-release:', SHA1(DATABASE())), 30)")->fetchColumn()) {
            throw new RuntimeException('Could not acquire the release migration lock.');
        }
        $migrations = [
            '20261115_002_mediation_pause_decisions.sql' => $columnType('mediation_pause_decisions', 'decision_id') === '',
            '20261115_003_preserve_show_cause_and_cfa_decisions.sql' => !str_contains($columnType('show_cause_evaluations', 'action_taken'), "'Excused'") || $columnType('cfa_records', 'resident_id') === '',
            '20261115_004_order4_settlement_arbitration_transmittal.sql' => !$orderFourReady(),
            '20261115_005_link_paanyaya_threads.sql' => true,
            '20261116_001_link_resident_google_identity.sql' => true,
            '20261124_001_category_case_type_mapping_and_paanyaya_other.sql' => $columnType('complaint_categories', 'default_case_type') === '' || $columnType('paanyayas', 'preliminary_other_category_specification') === '',
            '20261126_001_revision_a1_a10.sql' => !$revisionA1A10Ready(),
            '20261126_002_closure_hardening.sql' => $columnType('closure_requests', 'failed_verification_attempts') === '' || $columnType('closure_requests', 'locked_until') === '',
            '20261126_003_closure_notice_delivery.sql' => $columnType('closure_requests', 'notice_delivery_status') === '',
            '20261126_004_encrypt_names.sql' => $columnType('residents', 'first_name') !== 'varchar(512)' || $columnType('users', 'first_name') !== 'varchar(512)',
        ];
        $migrationDir = $argv[1] ?? dirname(__DIR__, 2) . '/database/migrations';
        foreach ($migrations as $file => $needed) {
            if (!$needed) {
                continue;
            }
            $sql = file_get_contents($migrationDir . '/' . $file);
            if ($sql === false) {
                throw new RuntimeException('Could not read ' . $file);
            }
            $statement = $db->query($sql);
            while ($statement->nextRowset()) {
                // Drain all results so PDO reports errors in any statement in the file.
            }
            $statement->closeCursor();
            fwrite(STDOUT, "Applied {$file}\n");
        }
    }

    if (!$orderFourReady() || !$revisionA1A10Ready() || $columnType('closure_requests', 'notice_delivery_status') === ''
        || $columnType('closure_requests', 'notice_delivery_channels') === ''
        || $columnType('residents', 'first_name') !== 'varchar(512)' || $columnType('users', 'first_name') !== 'varchar(512)'
        || $columnType('paanyayas', 'parent_paanyaya_id') === ''
        || $columnType('mediation_pause_decisions', 'decision_id') === ''
        || !str_contains($columnType('show_cause_evaluations', 'action_taken'), "'Excused'")
        || $columnType('cfa_records', 'resident_id') === ''
        || $columnType('users', 'google_subject') === ''
        || !$db->query("SHOW INDEX FROM users WHERE Key_name = 'uq_users_google_subject' AND Non_unique = 0")->fetch()) {
        throw new RuntimeException('The target schema failed the release checks.');
    }
    fwrite(STDOUT, "Release schema verified.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Release schema check failed: ' . ($e instanceof PDOException ? 'database error ' . $e->getCode() : $e->getMessage()) . PHP_EOL);
    exit(1);
}
