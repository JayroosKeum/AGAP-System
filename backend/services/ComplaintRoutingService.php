<?php

declare(strict_types=1);

/**
 * Keyword- and threshold-based complaint routing engine for Barangay Tumana.
 * Evaluates intake narratives, categories, and claim amounts to flag external
 * referrals (VAWC, BCPC, PNP/Prosecutor) or civil claim thresholds.
 */
final class ComplaintRoutingService
{
    public const CIVIL_CLAIM_LIMIT = 200000.0;
    public const THEFT_KP_LIMIT = 5000.0;

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

        return [
            'route' => $route,
            'kp_eligible' => $kpEligible,
            'severity' => $severity,
            'reasons' => $reasons,
            'matched_keywords' => array_values(array_unique($matched)),
            'detected_amount' => $amount,
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
