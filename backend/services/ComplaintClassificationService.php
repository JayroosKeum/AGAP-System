<?php

declare(strict_types=1);

final class ComplaintClassificationService
{
    /** @var array<string, bool> */
    private static array $columnCache = [];

    /**
     * Exact/prefix rules for standardized Katarungang Pambarangay categories -> Criminal.
     * Any standardized KP category not matched here (and without LTIA-CRIM / RPC article cues)
     * defaults to Civil.
     */
    private const CRIMINAL_PREFIXES = [
        'abandonment of a minor',
        'abandonment of persons in danger',
        'acts of lasciviousness',
        'alarms and scandals',
        'alteration of boundary marks',
        'anti-fencing law',
        'assistance to suicide',
        'bouncing checks',
        'coercions',
        'dispensing of abortives',
        'estafa and swindling',
        'false certificates',
        'false testimony',
        'fictitious name',
        'grave scandal',
        'illegal use of uniforms',
        'imprudence and negligence',
        'incriminating innocent persons',
        'inducing minor to abandon home',
        'less serious physical injuries',
        'malicious mischief',
        'occupation of real property',
        'physical injuries in a tumultuous affray',
        'premature marriages',
        'price control violations',
        'removal, sale or pledge of mortgaged property',
        'responsibility of participants in a duel',
        'safe spaces act',
        'seizure of correspondence',
        'simple seduction',
        'slander and defamation',
        'slight physical injuries',
        'theft and other thefts',
        'threatening to publish',
        'threats',
        'trespass to dwelling',
        'unjust vexation',
        'unlawful arrest',
        'violation of local ordinances',
    ];

    private const CIVIL_PREFIXES = [
        'agrarian disputes',
        'boundary dispute',
        'breach of contract',
        'collection of debts',
        'damages and reimbursement',
        'easement and right-of-way',
        'family and domestic relations',
        'lease and rental disputes',
        'neighborhood nuisance',
        'non-payment of debt',
        'specific performance of obligation',
        'others',
        'iba pa',
    ];

    public static function hasColumn(PDO $db, string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (isset(self::$columnCache[$key])) {
            return self::$columnCache[$key];
        }
        try {
            $stmt = $db->prepare(
                'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
            );
            $stmt->execute([$table, $column]);
            self::$columnCache[$key] = (bool) $stmt->fetchColumn();
        } catch (Throwable) {
            self::$columnCache[$key] = false;
        }
        return self::$columnCache[$key];
    }

    public static function isOtherCategoryName(string $categoryName): bool
    {
        return stripos($categoryName, 'Others') !== false || stripos($categoryName, 'Iba pa') !== false;
    }

    public static function resolveCaseTypeForCategoryName(
        string $categoryName,
        ?string $dbDefault = null,
        ?string $ltiaCode = null
    ): string {
        $lower = mb_strtolower(trim($categoryName));
        if ($lower !== '') {
            foreach (self::CIVIL_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    // For explicit "Others", respect explicit DB default if Criminal, otherwise Civil.
                    if (($prefix === 'others' || $prefix === 'iba pa') && $dbDefault === 'Criminal') {
                        return 'Criminal';
                    }
                    return 'Civil';
                }
            }
            foreach (self::CRIMINAL_PREFIXES as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    return 'Criminal';
                }
            }
        }

        if ($dbDefault === 'Civil' || $dbDefault === 'Criminal') {
            return $dbDefault;
        }

        if ($ltiaCode !== null && stripos($ltiaCode, 'CRIM') !== false) {
            return 'Criminal';
        }

        if ($lower !== '' && preg_match('/\((art\.|arts\.|bp\s*22|pd\s*1612|ra\s*11313)/i', $lower)) {
            return 'Criminal';
        }

        return 'Civil';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getCategories(PDO $db): array
    {
        $hasDefaultCol = self::hasColumn($db, 'complaint_categories', 'default_case_type');
        $selectDefault = $hasDefaultCol ? 'default_case_type' : "'Civil' AS default_case_type";
        $sql = "
            SELECT category_id, category_name, tagalog_name, ltia_code, {$selectDefault}
            FROM complaint_categories
            WHERE COALESCE(is_active, 1) = 1
            ORDER BY
                CASE WHEN category_name LIKE 'Others%' OR category_name LIKE 'Iba pa%' THEN 1 ELSE 0 END ASC,
                category_name ASC
        ";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $name = (string) ($row['category_name'] ?? '');
            $row['category_id'] = (int) $row['category_id'];
            $row['is_other'] = self::isOtherCategoryName($name);
            $row['default_case_type'] = self::resolveCaseTypeForCategoryName(
                $name,
                $hasDefaultCol ? ($row['default_case_type'] ?? null) : null,
                $row['ltia_code'] ?? null
            );
        }
        unset($row);
        return $rows;
    }

    public static function resolveCaseTypeById(PDO $db, int $categoryId): ?string
    {
        if ($categoryId <= 0) {
            return null;
        }
        $hasDefaultCol = self::hasColumn($db, 'complaint_categories', 'default_case_type');
        $selectDefault = $hasDefaultCol ? 'default_case_type' : "'Civil' AS default_case_type";
        $stmt = $db->prepare("SELECT category_name, ltia_code, {$selectDefault} FROM complaint_categories WHERE category_id = ? LIMIT 1");
        $stmt->execute([$categoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return self::resolveCaseTypeForCategoryName(
            (string) ($row['category_name'] ?? ''),
            $hasDefaultCol ? ($row['default_case_type'] ?? null) : null,
            $row['ltia_code'] ?? null
        );
    }

    public static function resolveEffectiveCaseType(
        PDO $db,
        ?int $primaryCategoryId,
        ?int $secondaryCategoryId = null,
        ?string $requestedCaseType = null
    ): string {
        $primaryType = $primaryCategoryId ? self::resolveCaseTypeById($db, $primaryCategoryId) : null;
        $secondaryType = $secondaryCategoryId ? self::resolveCaseTypeById($db, $secondaryCategoryId) : null;
        if ($primaryType === 'Criminal' || $secondaryType === 'Criminal') {
            return 'Criminal';
        }
        if ($requestedCaseType === 'Criminal') {
            return 'Criminal';
        }
        if ($requestedCaseType === 'Civil') {
            return 'Civil';
        }
        return $primaryType ?? 'Civil';
    }

    /**
     * Local keyword/rule classifier against the active complaint_categories rows.
     */
    public static function suggestFromNarrative(PDO $db, string $narrative, string $requestedResolution = ''): array
    {
        $categories = self::getCategories($db);
        $text = mb_strtolower(trim($narrative . ' ' . $requestedResolution));

        $rules = [
            ['match' => 'abandonment of a minor', 'pattern' => '/\b(abandon\w*|naiwang\s*menor|iniwan\s*(ang\s*)?(bata|anak|menor)|pagpapabaya\s*sa\s*(bata|menor)|pagbabantay.*menor|menor\s*de\s*edad)\b/iu', 'reason' => 'Narrative describes abandonment, neglect, or non-support involving a minor.', 'title' => 'Abandonment / Pagpapabaya sa Menor de Edad'],
            ['match' => 'non-payment of debt', 'pattern' => '/\b(utang|umutang|pautang|hindi\s*(pa\s*)?(na)?bayad|hindi\s*(pa\s*)?nagbabayad|di\s*nagbayad|loan|debt|borrowed|singil|naniningil|promissory|pagkakautang)\b/iu', 'reason' => 'Narrative describes an unpaid loan or monetary debt.', 'title' => 'Hindi nabayarang utang / paniningil'],
            ['match' => 'collection of debts', 'pattern' => '/\b(koleksyon|paniningil|collection|installment|hulugan|overdue\s*payment)\b/iu', 'reason' => 'Narrative describes collection of overdue payments or obligations.', 'title' => 'Paglikom ng utang o bayarin'],
            ['match' => 'lease and rental', 'pattern' => '/\b(upa|upahan|paupahan|tenant|landlord|lessee|lessor|rent|rental|evict|papalayas)\b/iu', 'reason' => 'Narrative involves a landlord-tenant or rental/lease dispute.', 'title' => 'Alitan sa paupahan at upa'],
            ['match' => 'boundary dispute', 'pattern' => '/\b(hangganan|bakod|pader|boundary|encroach|lote|lupa|sakop|metro)\b/iu', 'reason' => 'Narrative describes boundary line or fence encroachment.', 'title' => 'Pagtatalo sa hangganan ng lupa at bakod'],
            ['match' => 'easement and right-of-way', 'pattern' => '/\b(right[\s\-]*of[\s\-]*way|daanan|eskinita|harang\s*sa\s*daan|daluyan|kanal|drainage|easement)\b/iu', 'reason' => 'Narrative describes blocked access, right-of-way, or drainage passage.', 'title' => 'Karapatan sa daanan o daluyan'],
            ['match' => 'slander and defamation', 'pattern' => '/\b(siniraan|chismis|tsismis|slander|defamation|paninirang[\-\s]?puri|mura|minura|insulto|binastos|pinahiya|oral\s*defamation)\b/iu', 'reason' => 'Narrative alleges verbal insults, gossip, or oral defamation.', 'title' => 'Paninirang-puri (Oral Defamation / Slander)'],
            ['match' => 'slight physical injuries', 'pattern' => '/\b(sinuntok|sinampal|sinakal|binugbog|tinulak|physical|assault|pananakit|sugat|bugbog|suntukan|kalmot|palo|hampas)\b/iu', 'reason' => 'Narrative alleges physical assault or slight physical injuries.', 'title' => 'Bahagyang pananakit sa katawan'],
            ['match' => 'less serious physical injuries', 'pattern' => '/\b(hospital|ospital|medical\s*certificate|10\s*(days|araw)|20\s*(days|araw)|30\s*(days|araw)|nabali|fracture)\b/iu', 'reason' => 'Narrative indicates physical injuries requiring extended medical recovery.', 'title' => 'Pananakit sa katawan na may pagpapagamot'],
            ['match' => 'threats', 'pattern' => '/\b(pinagbantaan|banta|threat|papatayin|tinakot|pananakot|sasaktan)\b/iu', 'reason' => 'Narrative alleges verbal or physical threats of harm.', 'title' => 'Pagbabanta o pananakot'],
            ['match' => 'unjust vexation', 'pattern' => '/\b(vexation|pang[\-\s]?iinis|panggigipit|harass|pangungulit|disturb|istorbo)\b/iu', 'reason' => 'Narrative alleges persistent harassment or unjust vexation.', 'title' => 'Hindi makatarungang pang-iinis o panggigipit'],
            ['match' => 'safe spaces act', 'pattern' => '/\b(catcall|catcalling|whistl|wolf[\-\s]?whistl|pagsipol|paninita|bawal\s*bastos|safe\s*spaces|11313)\b/iu', 'reason' => 'Narrative describes street/public harassment (catcalling or whistling).', 'title' => 'Safe Spaces Act (Catcalling / Paninita)'],
            ['match' => 'malicious mischief', 'pattern' => '/\b(nasira|sinira|binasag|damaged|vandal|basag|kotse|motor|bubong|bintana|pinutol|sinunog)\b/iu', 'reason' => 'Narrative alleges intentional damage or destruction of property.', 'title' => 'Paninira ng ari-arian (Malicious Mischief)'],
            ['match' => 'trespass to dwelling', 'pattern' => '/\b(pumasok\s*sa\s*(bahay|bakuran)|trespass|walang\s*pahintulot\s*na\s*pagpasok|pinasok)\b/iu', 'reason' => 'Narrative alleges unlawful entry into a residence or private premises.', 'title' => 'Ilegal na pagpasok sa bahay o bakuran'],
            ['match' => 'theft and other thefts', 'pattern' => '/\b(ninakaw|nakaw|theft|stolen|nawawala|tinangay|pagnanakaw)\b/iu', 'reason' => 'Narrative alleges theft or taking of personal property.', 'title' => 'Hinala ng pagnanakaw'],
            ['match' => 'estafa and swindling', 'pattern' => '/\b(estafa|scam|swindl|niloko|panlilinlang|panloloko|pekeng)\b/iu', 'reason' => 'Narrative alleges deceit, fraud, or swindling.', 'title' => 'Panlilinlang o Estafa'],
            ['match' => 'bouncing checks', 'pattern' => '/\b(bouncing\s*check|talbog\s*na\s*tseke|bp\s*22|closed\s*account|insufficient\s*funds|tseke)\b/iu', 'reason' => 'Narrative involves an unfunded or dishonored check.', 'title' => 'Pag-isyu ng talbog na tseke'],
            ['match' => 'neighborhood nuisance', 'pattern' => '/\b(videoke|karaoke|sound\s*system|loud\s*noise|speaker|maingay|ingay|nagpapatugtog|aso|pusa|alagang\s*hayop|tahol|kagat|kinagat|dumi|basura|amoy)\b/iu', 'reason' => 'Narrative describes neighborhood nuisance, loud noise, waste, or pet disturbances.', 'title' => 'Pambubulabog sa kapaligiran o alagang hayop'],
            ['match' => 'alarms and scandals', 'pattern' => '/\b(alarma|eskandalo|scandal|nagsisigaw|lasing|nagwawala|gulo\s*sa\s*daan)\b/iu', 'reason' => 'Narrative describes public disorder or scandalous disturbance.', 'title' => 'Panggugulo at alarma sa publiko'],
            ['match' => 'breach of contract', 'pattern' => '/\b(kontrata|kasunduan|contract|agreement|breach|hindi\s*tumupad|napagkasunduan)\b/iu', 'reason' => 'Narrative describes breach of a contract or written agreement.', 'title' => 'Paglabag sa kontrata o kasunduan'],
            ['match' => 'coercions', 'pattern' => '/\b(pinilit|pamimilit|coercion|sapilitan|tinutukan)\b/iu', 'reason' => 'Narrative alleges coercion or compelling someone against their will.', 'title' => 'Pamimilit o coercion'],
            ['match' => 'family and domestic relations', 'pattern' => '/\b(pamilya|asawa|magulang|kapatid|sustento|support|domestic|family)\b/iu', 'reason' => 'Narrative involves family or domestic support relations.', 'title' => 'Alitang pampamilya at tahanan'],
            ['match' => 'damages and reimbursement', 'pattern' => '/\b(bayad[\-\s]?pinsala|reimburse|danyos|compensation|damages)\b/iu', 'reason' => 'Narrative seeks monetary damages or reimbursement.', 'title' => 'Bayad-pinsala at pagbabalik ng halaga'],
        ];

        $matches = [];
        foreach ($rules as $rule) {
            if (preg_match($rule['pattern'], $text)) {
                foreach ($categories as $cat) {
                    if (str_contains(mb_strtolower($cat['category_name']), $rule['match'])) {
                        $matches[] = [
                            'category_id' => $cat['category_id'],
                            'category_name' => $cat['category_name'],
                            'case_type' => $cat['default_case_type'],
                            'confidence' => empty($matches) ? 'High' : 'Medium',
                            'reason' => $rule['reason'],
                            'title' => $rule['title'],
                        ];
                        break;
                    }
                }
            }
        }

        if (empty($matches)) {
            $fallback = null;
            foreach ($categories as $cat) {
                if ($cat['is_other']) {
                    $fallback = $cat;
                    break;
                }
            }
            $fallback ??= $categories[0] ?? ['category_id' => 1, 'category_name' => 'Others (Please Specify)', 'default_case_type' => 'Civil'];
            $matches[] = [
                'category_id' => (int) $fallback['category_id'],
                'category_name' => (string) $fallback['category_name'],
                'case_type' => (string) $fallback['default_case_type'],
                'confidence' => 'Low',
                'reason' => 'No specific offense keyword matched; please verify the KP category and Civil/Criminal classification.',
                'title' => '',
            ];
        }

        $primary = $matches[0];
        $alternatives = array_slice($matches, 1, 2);
        $secondary = count($matches) > 1 ? $matches[1] : null;
        $effectiveType = ($primary['case_type'] === 'Criminal' || ($secondary && $secondary['case_type'] === 'Criminal'))
            ? 'Criminal'
            : $primary['case_type'];

        return [
            'primary' => [
                'category_id' => $primary['category_id'],
                'category_name' => $primary['category_name'],
                'case_type' => $primary['case_type'],
                'confidence' => $primary['confidence'],
                'reason' => $primary['reason'],
            ],
            'alternatives' => array_map(static fn (array $m): array => [
                'category_id' => $m['category_id'],
                'category_name' => $m['category_name'],
                'case_type' => $m['case_type'],
                'confidence' => $m['confidence'],
                'reason' => $m['reason'],
            ], $alternatives),
            'secondary' => $secondary ? [
                'category_id' => $secondary['category_id'],
                'category_name' => $secondary['category_name'],
                'case_type' => $secondary['case_type'],
                'reason' => $secondary['reason'],
            ] : null,
            'case_type' => $effectiveType,
            'suggested_title' => $primary['title'],
            'jurisdiction_advisory' => $effectiveType === 'Criminal'
                ? 'Criminal KP classification detected: verify maximum statutory imprisonment (≤ 1 year) and private offended party under R.A. 7160 §§408(c)-(d).'
                : null,
        ];
    }
}
