<?php

declare(strict_types=1);

/**
 * Evaluates deterministic KP classification & routing rules over labeled
 * local English/Filipino narratives, including ambiguous and no-match cases (A2).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/ComplaintClassificationService.php';
require_once __DIR__ . '/../services/ComplaintRoutingService.php';

$db = (new Database())->connect();

$dataset = [
    [
        'label' => 'Unpaid debt (Tagalog)',
        'title' => 'Hindi nabayarang utang',
        'narrative' => 'Umutang siya ng Php 15,000 noong nakaraang buwan at hindi pa nagbabayad.',
        'case_type' => 'Civil',
        'expected_category_match' => 'non-payment of debt',
        'expected_route' => 'KP_ELIGIBLE',
        'expected_queue' => 'LISO_SCREENING',
    ],
    [
        'label' => 'Loud karaoke nuisance',
        'title' => 'Maingay na videoke',
        'narrative' => 'Gabi-gabi nagpapatugtog ng malakas na karaoke lampas hatinggabi.',
        'case_type' => 'Civil',
        'expected_category_match' => 'neighborhood nuisance',
        'expected_route' => 'KP_ELIGIBLE',
        'expected_queue' => 'LISO_SCREENING',
    ],
    [
        'label' => 'VAWC domestic abuse',
        'title' => 'Pananakit ng kinakasama',
        'narrative' => 'Physical abuse by live-in partner and economic abuse.',
        'case_type' => 'Criminal',
        'expected_category_match' => 'physical injuries',
        'expected_route' => 'VAWC',
        'expected_queue' => 'VAWC_REFERRAL_DESK',
    ],
    [
        'label' => 'BCPC minor involvement',
        'title' => 'Child neglect and bullying',
        'narrative' => 'A minor student suffered child neglect and bullying in school.',
        'case_type' => 'Criminal',
        'expected_category_match' => null,
        'expected_route' => 'BCPC',
        'expected_queue' => 'BCPC_REFERRAL_DESK',
    ],
    [
        'label' => 'PNP grave crime',
        'title' => 'Grave threat with firearm',
        'narrative' => 'Suspect made a threat with a gun and illegal drugs.',
        'case_type' => 'Criminal',
        'expected_category_match' => 'threats',
        'expected_route' => 'PNP',
        'expected_queue' => 'PNP_PROSECUTOR_REFERRAL',
    ],
    [
        'label' => 'Theft above KP threshold',
        'title' => 'Ninakaw na motorsiklo',
        'narrative' => 'Ninakaw ang gamit na nagkakahalaga ng PHP 12,000.',
        'case_type' => 'Criminal',
        'expected_category_match' => 'theft',
        'expected_route' => 'PNP',
        'expected_queue' => 'PNP_PROSECUTOR_REFERRAL',
    ],
    [
        'label' => 'Civil claim over 200k',
        'title' => 'Unpaid contract balance',
        'narrative' => 'Breach of contract with remaining balance of PHP 350,000.',
        'case_type' => 'Civil',
        'expected_category_match' => 'breach of contract',
        'expected_route' => 'OVER_LIMIT',
        'expected_queue' => 'REVIEW_EXCEPTION_QUEUE',
    ],
    [
        'label' => 'Ambiguous conflicting external indicators',
        'title' => 'Domestic violence and firearm',
        'narrative' => 'Physical abuse by husband involving a gun and a minor.',
        'case_type' => 'Criminal',
        'expected_category_match' => null,
        'expected_route' => 'VAWC',
        'expected_queue' => 'REVIEW_EXCEPTION_QUEUE',
    ],
    [
        'label' => 'No-match vague narrative',
        'title' => 'Humihingi ng tulong',
        'narrative' => 'Nais ko lang pong magtanong tungkol sa proseso ng barangay.',
        'case_type' => 'Civil',
        'expected_category_match' => 'others',
        'expected_route' => 'KP_ELIGIBLE',
        'expected_queue' => 'REVIEW_EXCEPTION_QUEUE',
    ],
];

$catCorrect = 0;
$catTotal = 0;
$routeCorrect = 0;
$queueCorrect = 0;

foreach ($dataset as $item) {
    $cls = ComplaintClassificationService::suggestFromNarrative($db, $item['narrative']);
    $primaryName = mb_strtolower((string) ($cls['primary']['category_name'] ?? ''));
    $confidence = (string) ($cls['primary']['confidence'] ?? '');

    if ($item['expected_category_match'] !== null) {
        $catTotal++;
        if (str_contains($primaryName, $item['expected_category_match'])) {
            $catCorrect++;
        }
    }

    $routing = ComplaintRoutingService::evaluate([
        'complaint_title' => $item['title'],
        'description' => $item['narrative'],
        'category' => $primaryName,
        'case_type' => $item['case_type'],
        'classifier_confidence' => $confidence,
    ]);

    if ($routing['route'] === $item['expected_route']) {
        $routeCorrect++;
    }
    if ($routing['assigned_queue'] === $item['expected_queue']) {
        $queueCorrect++;
    }
}

$total = count($dataset);
echo json_encode([
    'rule_version' => ComplaintRoutingService::RULE_VERSION,
    'samples' => $total,
    'category_accuracy' => sprintf('%d/%d (%.1f%%)', $catCorrect, $catTotal, ($catCorrect / max(1, $catTotal)) * 100),
    'route_accuracy' => sprintf('%d/%d (%.1f%%)', $routeCorrect, $total, ($routeCorrect / $total) * 100),
    'queue_accuracy' => sprintf('%d/%d (%.1f%%)', $queueCorrect, $total, ($queueCorrect / $total) * 100),
], JSON_PRETTY_PRINT) . PHP_EOL;

if ($routeCorrect !== $total || $queueCorrect !== $total || $catCorrect !== $catTotal) {
    exit(1);
}
