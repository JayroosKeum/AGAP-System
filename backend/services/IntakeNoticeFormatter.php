<?php

declare(strict_types=1);

/**
 * Formats standardized bilingual (Tagalog + English) notices when a submission
 * lacks required Katarungang Pambarangay details or verified complainant identity.
 */
final class IntakeNoticeFormatter
{
    /**
     * @param array<int, string> $missingFields
     * @return array{tagalog: string, english: string, combined: string, missing_fields: array<int, string>}
     */
    public static function formatInsufficientInfoNotice(array $missingFields = []): array
    {
        $cleaned = array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $missingFields
        ), static fn (string $item): bool => $item !== '')));

        $missingLabelTl = $cleaned !== []
            ? implode(', ', $cleaned)
            : 'pagkakakilanlan ng nagsusumbong, detalye ng inirereklamo (pangalan at tirahan sa Barangay Tumana), at kumpletong salaysay ng pangyayari';

        $missingLabelEn = $cleaned !== []
            ? implode(', ', $cleaned)
            : 'complainant identity, respondent details (full name and Barangay Tumana address), and complete incident narrative';

        $tagalog = "Hindi po maaaring iproseso ang ulat na ito sa ilalim ng Katarungang Pambarangay dahil kulang ang mga sumusunod na impormasyon: {$missingLabelTl}.\n\n"
            . "Maaaring pumili at gawin ang alinman sa mga sumusunod na hakbang:\n"
            . "1. Pumunta sa tanggapan ng BPSO sa Barangay Tumana Hall at ibigay ang hinihinging impormasyon kasama ang balidong pagkakakilanlan (ID); o\n"
            . "2. Gumawa o kumpletuhin ang inyong Resident Account sa AGAP Portal at isumite ang kumpletong reklamo online.";

        $english = "This report cannot be processed under Katarungang Pambarangay proceedings because the following required information is missing: {$missingLabelEn}.\n\n"
            . "Please choose one of the following steps to proceed:\n"
            . "1. Visit the Barangay Peace and Security Office (BPSO) desk at Barangay Tumana Hall and provide the required information and valid identification; or\n"
            . "2. Create or complete a Resident Account in the AGAP Portal and submit your complete complaint online.";

        return [
            'tagalog' => $tagalog,
            'english' => $english,
            'combined' => $tagalog . "\n\n---\n\n" . $english,
            'missing_fields' => $cleaned,
        ];
    }
}
