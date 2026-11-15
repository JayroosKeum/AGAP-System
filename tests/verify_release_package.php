<?php
/**
 * Release package gate for AGAP Azure App Service deployments.
 * Fails (exit 1) if required runtime files are missing or prohibited files are present.
 */

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tests/verify_release_package.php <release-dir>\n");
    exit(1);
}

$releaseDir = realpath($argv[1]);
if ($releaseDir === false || !is_dir($releaseDir)) {
    fwrite(STDERR, "Release directory not found: {$argv[1]}\n");
    exit(1);
}

$requiredPaths = [
    'index.php',
    'activate.php',
    'verify-email.php',
    'composer.json',
    'backend/config/database.php',
    'backend/controllers/HearingController.php',
    'backend/services/DocketScheduleService.php',
    'backend/services/KpFormService.php',
    'backend/services/PDFService.php',
    'backend/libs/dompdf/autoload.inc.php',
    'vendor/autoload.php',
    'frontend/pages/landing/index.php',
    'frontend/assets/js/docket-drag-schedule.js',
    'frontend/assets/js/dashboard.js',
    'frontend/assets/js/clerk-dashboard.js',
    'frontend/assets/images/tumana_logo.jpg',
    'frontend/assets/images/marikina_logo.jpg',
    'database/schema.sql',
    'database/migrations/20261115_004_order4_settlement_arbitration_transmittal.sql',
];

$missing = [];
foreach ($requiredPaths as $rel) {
    $full = $releaseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    if (!file_exists($full)) {
        $missing[] = $rel;
    }
}

$prohibitedPrefixes = [
    '.git',
    '.github',
    '.agents',
    'brain',
    'storage',
    'logs',
    'output',
    'scratch',
    'node_modules',
    'tests',
    'backend/tests',
    'database/backups',
    'database/seeds',
    'database/seeders',
];

$prohibitedFiles = [
    '.env',
    '.env.local',
    'default',
];

$prohibitedExtensions = ['pdf', 'xlsx', 'log', 'bak', 'tmp', 'gz'];

$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($releaseDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    $abs = $item->getPathname();
    $rel = ltrim(str_replace('\\', '/', substr($abs, strlen($releaseDir))), '/');
    if ($rel === '') {
        continue;
    }

    foreach ($prohibitedPrefixes as $prefix) {
        if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
            $violations[] = "prohibited path: {$rel}";
            continue 2;
        }
    }

    $base = basename($rel);
    if (in_array($base, $prohibitedFiles, true) || preg_match('/^\.env\..+\.local$/', $base)) {
        $violations[] = "prohibited file: {$rel}";
        continue;
    }

    if ($item->isFile()) {
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (in_array($ext, $prohibitedExtensions, true)) {
            $violations[] = "prohibited extension (.{$ext}): {$rel}";
        }
    }
}

if (!empty($missing) || !empty($violations)) {
    foreach ($missing as $m) {
        fwrite(STDERR, "MISSING REQUIRED RUNTIME FILE: {$m}\n");
    }
    foreach (array_slice($violations, 0, 50) as $v) {
        fwrite(STDERR, "PROHIBITED PACKAGE ENTRY: {$v}\n");
    }
    if (count($violations) > 50) {
        fwrite(STDERR, '...and ' . (count($violations) - 50) . " more prohibited entries.\n");
    }
    exit(1);
}

echo "Release package verified cleanly: {$releaseDir}\n";
exit(0);
