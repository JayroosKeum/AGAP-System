<?php

declare(strict_types=1);

/**
 * Keyword- and threshold-based complaint routing engine for Barangay Tumana.
 * Evaluates intake narratives, categories, and claim amounts to flag external
 * referrals (VAWC, BCPC, PNP/Prosecutor) or civil claim thresholds.
 */
final class ComplaintRoutingService
{
    public const RULE_VERSION = '2026.11-kp-v1';
    public const CIVIL_CLAIM_LIMIT = 200000.0;
    public const THEFT_KP_LIMIT = 5000.0;

    /**
     * Receiving units approved for Barangay Tumana grievance operations.
     */
    public const APPROVED_QUEUES = [
        'BPSO_VALIDATION' => 'BPSO Desk Validation & Blotter Queue',
        'LISO_SCREENING' => 'LISO Preliminary Screening Queue',
        'LUPON_PAANYAYA_PREP' => 'Lupon Clerk Paanyaya Preparation Queue',
        'VAWC_REFERRAL_DESK' => 'Barangay VAWC Desk (Referral Created)',
        'BCPC_REFERRAL_DESK' => 'Barangay BCPC Desk (Referral Created)',
        'PNP_PROSECUTOR_REFERRAL' => 'PNP / Prosecutor Referral Queue',
        'MARIKINA_SETTLEMENT_REFERRAL' => 'Marikina City Settlement Office (Endorsement)',
        'CENO_REFERRAL' => 'Marikina CENO (City Environment & Natural Resources Office)',
        'TMO_REFERRAL' => 'Marikina TMO (Traffic / Transportation Management Office)',
        'REVIEW_EXCEPTION_QUEUE' => 'Manual Review Exception Queue',
    ];

    /**
     * Approved external agencies for LISO pre-filing counseling & Step 3/4 endorsement
     * when a grievance falls outside Katarungang Pambarangay jurisdiction.
     */
    public const EXTERNAL_AGENCIES = [
        'PNP' => [
            'label' => 'PNP',
            'full_name' => 'Philippine National Police (Marikina City Police Station)',
            'queue' => 'PNP_PROSECUTOR_REFERRAL',
            'route' => 'PNP',
            'jurisdiction_note' => 'Criminal / police blotter matters outside KP jurisdiction.',
        ],
        'Marikina Settlement Office' => [
            'label' => 'Marikina Settlement Office',
            'full_name' => 'Marikina City Settlement Office',
            'queue' => 'MARIKINA_SETTLEMENT_REFERRAL',
            'route' => 'MARIKINA_SETTLEMENT',
            'jurisdiction_note' => 'City-level settlement / inter-barangay or municipal conciliation matters.',
        ],
        'CENO' => [
            'label' => 'CENO',
            'full_name' => 'Marikina City Environment & Natural Resources Office (CENO)',
            'queue' => 'CENO_REFERRAL',
            'route' => 'CENO',
            'jurisdiction_note' => 'Environmental, sanitation, waste, or ecological ordinance complaints.',
        ],
        'TMO' => [
            'label' => 'TMO',
            'full_name' => 'Marikina Traffic & Transportation Management Office (TMO)',
            'queue' => 'TMO_REFERRAL',
            'route' => 'TMO',
            'jurisdiction_note' => 'Traffic, road obstruction, parking, and public transport disputes.',
        ],
        'VAWC' => [
            'label' => 'Barangay VAWC Desk',
            'full_name' => 'Barangay Tumana VAWC Desk / PNP WCPD',
            'queue' => 'VAWC_REFERRAL_DESK',
            'route' => 'VAWC',
            'jurisdiction_note' => 'RA 9262 / RA 11313 gender-based violence and repeat harassment.',
        ],
        'BCPC' => [
            'label' => 'Barangay BCPC Desk',
            'full_name' => 'Barangay Council for the Protection of Children (BCPC)',
            'queue' => 'BCPC_REFERRAL_DESK',
            'route' => 'BCPC',
            'jurisdiction_note' => 'Child protection, RA 7610, or juvenile welfare matters (RA 9344).',
        ],
        'MTC / Prosecutor' => [
            'label' => 'MTC / City Prosecutor',
            'full_name' => 'Office of the City Prosecutor / Metropolitan Trial Court (Marikina City)',
            'queue' => 'PNP_PROSECUTOR_REFERRAL',
            'route' => 'PROSECUTOR',
            'jurisdiction_note' => 'Judicial or prosecutorial filing outside barangay conciliation authority.',
        ],
    ];

    /**
     * Resolves an agency key or label into canonical queue & metadata.
     *
     * @return array{key: string, label: string, full_name: string, queue: string, route: string, jurisdiction_note: string}|null
     */
    public static function resolveExternalAgency(string $input): ?array
    {
        $needle = trim($input);
        if ($needle === '') {
            return null;
        }
        foreach (self::EXTERNAL_AGENCIES as $key => $meta) {
            if (strcasecmp($needle, $key) === 0 || strcasecmp($needle, $meta['label']) === 0 || strcasecmp($needle, $meta['full_name']) === 0) {
                return ['key' => $key] + $meta;
            }
        }
        return null;
    }

    private const VAWC_KEYWORDS = [
        'vawc', 'ra 9262', 'domestic violence', 'battered', 'physical abuse by husband',
        'physical abuse by partner', 'live-in partner', 'ex-boyfriend', 'spouse abuse',
        'economic abuse', 'marital abuse', 'sexual harassment', 'catcalling', 'stalking',
        'unwanted sexual', 'ra 11313', 'safe spaces',
    ];

    private const BCPC_KEYWORDS = [
        'bcpc', 'child abuse', 'minor', 'batang', 'bata', 'anak', 'ra 7610', 'ra 9344',
        'child neglect', 'bullying in school', 'juvenile', 'student', 'underage',
    ];

    private const PNP_GRAVE_KEYWORDS = [
        'murder', 'homicide', 'rape', 'arson', 'kidnapping', 'abduction', 'illegal drugs',
        'shabu', 'marijuana', 'firearm', 'gun', 'deadly weapon', 'stabbing', 'shooting',
        'robbery with violence', 'carnapping', 'cybercrime', 'extortion',
    ];

    private const THEFT_KEYWORDS = [
        'theft', 'nakaw', 'ninakaw', 'steal', 'stole', 'stolen', 'shoplifting', 'pilferage',
        'estafa', 'swindling', 'scam',
    ];

    /**
     * Evaluate a complaint payload and return a structured routing recommendation.
     *
     * @param array<string, mixed> $data
     * @return array{
     *   route: string,
     *   kp_eligible: bool,
     *   severity: string,
     *   reasons: array<int, string>,
     *   matched_keywords: array<int, string>,
     *   detected_amount: float|null
     * }
     */
    public static function evaluate(array $data): array
    {
        $title = strtolower(trim((string) ($data['complaint_title'] ?? $data['title'] ?? '')));
        $narrative = strtolower(trim((string) ($data['description'] ?? $data['narrative'] ?? '')));
        $category = strtolower(trim((string) ($data['category'] ?? '')));
        $caseType = strtolower(trim((string) ($data['case_type'] ?? 'civil')));
        $combined = trim($title . ' ' . $narrative . ' ' . $category);

        $reasons = [];
        $matched = [];
        $route = 'KP_ELIGIBLE';
        $severity = 'normal';
        $kpEligible = true;

        if (!empty($data['is_repeat_offense_vawc'])) {
            $route = 'VAWC';
            $kpEligible = false;
            $severity = 'critical';
            $reasons[] = 'RA 11313 §11: Repeat Safe Spaces Act offenses must be routed to the Barangay VAWC Desk or PNP WCPD.';
        }

        $vawcHits = self::findKeywords($combined, self::VAWC_KEYWORDS);
        if ($vawcHits !== []) {
            $matched = array_merge($matched, $vawcHits);
            $route = 'VAWC';
            $kpEligible = false;
            $severity = 'critical';
            $reasons[] = 'Detected VAWC / Safe Spaces indicators (' . implode(', ', $vawcHits) . '). Route to Barangay VAWC Desk / PNP WCPD.';
        }

        $pnpHits = self::findKeywords($combined, self::PNP_GRAVE_KEYWORDS);
        if ($pnpHits !== []) {
            $matched = array_merge($matched, $pnpHits);
            if ($route === 'KP_ELIGIBLE') {
                $route = 'PNP';
            }
            $kpEligible = false;
            $severity = 'critical';
            $reasons[] = 'Detected grave/non-KP offense indicators (' . implode(', ', $pnpHits) . '). Refer directly to PNP / Prosecutor.';
        }

        $bcpcHits = self::findKeywords($combined, self::BCPC_KEYWORDS);
        $hasMinorParty = !empty($data['has_minor_party']) || !empty($data['involves_minor']);
        if ($bcpcHits !== [] || $hasMinorParty) {
            $matched = array_merge($matched, $bcpcHits);
            if ($route === 'KP_ELIGIBLE') {
                $route = 'BCPC';
                $severity = 'warning';
                $kpEligible = false;
            }
            $reasons[] = 'Minor or child-protection indicators detected. Coordinate with Barangay Council for the Protection of Children (BCPC).';
        }

        $amount = self::extractAmount($data, $combined);
        $theftHits = self::findKeywords($combined, self::THEFT_KEYWORDS);
        if ($theftHits !== [] && $amount !== null && $amount > self::THEFT_KP_LIMIT) {
            $matched = array_merge($matched, $theftHits);
            if ($route === 'KP_ELIGIBLE') {
                $route = 'PNP';
            }
            $kpEligible = false;
            $severity = 'critical';
            $reasons[] = sprintf(
                'Theft/estafa claim (₱%s) exceeds the ₱%s barangay desk threshold. Refer to PNP.',
                number_format($amount, 2),
                number_format(self::THEFT_KP_LIMIT, 2)
            );
        } elseif ($caseType === 'civil' && $amount !== null && $amount > self::CIVIL_CLAIM_LIMIT) {
            if ($route === 'KP_ELIGIBLE') {
                $route = 'OVER_LIMIT';
                $severity = 'warning';
            }
            $reasons[] = sprintf(
                'Civil monetary claim (₱%s) exceeds the ₱%s threshold; verify Small Claims / MTC jurisdiction.',
                number_format($amount, 2),
                number_format(self::CIVIL_CLAIM_LIMIT, 2)
            );
        }

        $distinctExternal = [];
        if (!empty($data['is_repeat_offense_vawc']) || $vawcHits !== []) {
            $distinctExternal[] = 'VAWC';
        }
        if ($pnpHits !== [] || ($theftHits !== [] && $amount !== null && $amount > self::THEFT_KP_LIMIT)) {
            $distinctExternal[] = 'PNP';
        }
        if ($bcpcHits !== [] || $hasMinorParty) {
            $distinctExternal[] = 'BCPC';
        }
        $ambiguous = count($distinctExternal) > 1;
        if ($ambiguous) {
            $reasons[] = 'Multiple conflicting statutory routing indicators detected (' . implode(', ', $distinctExternal) . '); held in exception queue for Lupon/LISO verification.';
        }

        $queueAssignment = self::resolveQueue($route, $ambiguous, $data);

        return [
            'route' => $route,
            'kp_eligible' => $kpEligible,
            'severity' => $severity,
            'reasons' => $reasons,
            'matched_keywords' => array_values(array_unique($matched)),
            'detected_amount' => $amount,
            'rule_version' => self::RULE_VERSION,
            'ambiguous' => $ambiguous,
            'assigned_queue' => $queueAssignment['queue'],
            'assigned_queue_label' => $queueAssignment['label'],
            'external_referral_status' => $queueAssignment['external_referral_status'],
        ];
    }

    /**
     * Resolves the internal Barangay Tumana receiving queue and external referral state.
     * Creating an external referral record is intentionally distinct from external dispatch.
     *
     * @param array<string, mixed> $data
     * @return array{queue: string, label: string, external_referral_status: string}
     */
    public static function resolveQueue(string $route, bool $ambiguous = false, array $data = []): array
    {
        $isWalkInAccepted = !empty($data['is_walk_in_accepted']);
        $classifierConfidence = (string) ($data['classifier_confidence'] ?? '');

        if ($ambiguous || ($route === 'KP_ELIGIBLE' && $classifierConfidence === 'Low')) {
            $queue = 'REVIEW_EXCEPTION_QUEUE';
            $externalStatus = 'Pending Exception Review';
        } else {
            $queue = match ($route) {
                'VAWC' => 'VAWC_REFERRAL_DESK',
                'BCPC' => 'BCPC_REFERRAL_DESK',
                'PNP' => 'PNP_PROSECUTOR_REFERRAL',
                'OVER_LIMIT' => 'REVIEW_EXCEPTION_QUEUE',
                default => $isWalkInAccepted ? 'LUPON_PAANYAYA_PREP' : 'LISO_SCREENING',
            };
            $externalStatus = in_array($route, ['VAWC', 'BCPC', 'PNP'], true)
                ? 'Referral Created (Pending Manual Transmittal)'
                : 'Not Applicable';
        }

        return [
            'queue' => $queue,
            'label' => self::APPROVED_QUEUES[$queue] ?? $queue,
            'external_referral_status' => $externalStatus,
        ];
    }

    /**
     * @param array<int, string> $keywords
     * @return array<int, string>
     */
    private static function findKeywords(string $haystack, array $keywords): array
    {
        if ($haystack === '') {
            return [];
        }
        $hits = [];
        foreach ($keywords as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $haystack) === 1) {
                $hits[] = $keyword;
            }
        }
        return $hits;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractAmount(array $data, string $text): ?float
    {
        if (isset($data['claim_amount']) && is_numeric($data['claim_amount'])) {
            return (float) $data['claim_amount'];
        }
        if (preg_match('/(?:₱|php|p)\s*([0-9]{1,3}(?:,[0-9]{3})+|[0-9]+)(?:\.[0-9]{1,2})?/i', $text, $matches)) {
            $raw = str_replace(',', '', $matches[1]);
            if (is_numeric($raw)) {
                return (float) $raw;
            }
        }
        return null;
    }
}
