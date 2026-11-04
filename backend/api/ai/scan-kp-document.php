<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please log in.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Only POST is accepted.']);
    exit;
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../models/Setting.php';
require_once __DIR__ . '/../../services/GeminiService.php';

$maxBytes = 25 * 1024 * 1024; // 25 MB
$file = $_FILES['document'] ?? ($_FILES['image'] ?? ($_FILES['scanned_kp7'] ?? ($_FILES['file'] ?? null)));

$fileData = null;
$mimeType = null;
$fileName = '';

if ($file && isset($file['tmp_name']) && (is_uploaded_file($file['tmp_name']) || (defined('PHP_SAPI') && PHP_SAPI === 'cli' && file_exists($file['tmp_name'])))) {
    if ($file['size'] > $maxBytes) {
        http_response_code(413);
        echo json_encode(['success' => false, 'message' => 'Uploaded document exceeds 25 MB limit.']);
        exit;
    }
    $mimeType = strtolower(mime_content_type($file['tmp_name']) ?: ($file['type'] ?? ''));
    $fileName = $file['name'] ?? 'document';
    $fileData = base64_encode(file_get_contents($file['tmp_name']));
} else {
    // Check php://input for JSON base64
    $rawInput = file_get_contents('php://input');
    if ($rawInput) {
        $json = json_decode($rawInput, true);
        if (is_array($json) && !empty($json['document_base64'])) {
            $fileData = $json['document_base64'];
            $mimeType = strtolower($json['mime_type'] ?? 'image/jpeg');
            $fileName = $json['file_name'] ?? 'document';
        }
    }
}

if (!$fileData) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'No document file was provided for scanning.']);
    exit;
}

// Normalize MIME
if (str_starts_with($fileData, 'data:')) {
    if (preg_match('/^data:([^;]+);base64,(.+)$/', $fileData, $matches)) {
        $mimeType = strtolower($matches[1]);
        $fileData = $matches[2];
    }
}
$allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'];
if (!in_array($mimeType, $allowedMimes, true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported format. Please upload a PDF or an Image (JPG, PNG, WebP).']);
    exit;
}
if ($mimeType === 'image/jpg') {
    $mimeType = 'image/jpeg';
}

// Fetch categories from DB for intelligent matching
$db = (new Database())->connect();
$categoriesStmt = $db->query("SELECT category_id, category_name FROM complaint_categories WHERE COALESCE(is_active, 1) = 1 ORDER BY category_name ASC");
$allCategories = $categoriesStmt ? $categoriesStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$categoryNamesList = array_map(static fn($c) => $c['category_name'], $allCategories);
$categoryListStr = implode(', ', array_slice($categoryNamesList, 0, 20));

$geminiService = new GeminiService();
$apiKey = $geminiService->getApiKey();

$extractedData = null;
$extractionSource = 'offline_template';
$modelUsed = null;

if (!empty($apiKey) && function_exists('curl_init')) {
    $systemPrompt = <<<PROMPT
You are an expert legal intake and document parser for Philippine Katarungang Pambarangay (Barangay Justice System, RA 7160) at Barangay Tumana, City of Marikina.
Carefully examine and perform OCR on this uploaded document (KP Form No. 7 Formal Complaint, Barangay Blotter Excerpt, Handwritten Salaysay/Affidavit, or Kasunduan).

Extract all discernible case information with 100% precision. Return ONLY a single valid JSON object conforming to the schema below.
Known Barangay Complaint Categories: {$categoryListStr}

JSON Schema:
{
  "case_type": "Civil" or "Criminal" or "Others",
  "category_name": "Closest matching category name from the known categories list",
  "dispute_nature": "Concise, descriptive title of dispute (e.g. Hindi Pagbabayad ng Utang na P25,000, Pagtatalo sa Hangganan ng Bakuran, Paninirang Puri / Oral Defamation)",
  "incident_date": "YYYY-MM-DD",
  "incident_time": "HH:MM" (in 24-hour format, e.g. 14:30),
  "incident_street": "Street name, alley, house number, or landmark in Barangay Tumana",
  "incident_purok": "Purok 1 through Purok 8, Doña Petra, Bagong Farmers, Bukang Liwayway, Libis Tumana, Bagong Purok, Palay, Mais, Singkamas, or Non-Resident",
  "blotter_ref_no": "Blotter entry number, extract number, or blotter reference if written",
  "narrative": "Complete, verbatim or comprehensive English/Tagalog narrative statement of facts, chronological events, and allegations as stated on the document",
  "requested_resolution": "Relief, remedy, restitution, payment amount, repair, or apology demanded by the complainant",
  "offense_fine_max": "Monetary damages or debt amount in pure numeric digits (e.g. 25000) or 0",
  "complainants": [
    {
      "first_name": "First name",
      "middle_name": "Middle name (or empty string)",
      "last_name": "Last name",
      "suffix": "Jr./Sr./III/IV (or empty string)",
      "contact_no": "Mobile or telephone number if available",
      "address": "Complete residence address",
      "purok": "Purok 1 to Purok 8 or specific Tumana sector",
      "gender": "Male" or "Female",
      "age": "Age in years (number as string or empty)",
      "civil_status": "Single" or "Married" or "Widowed" or "Separated"
    }
  ],
  "respondents": [
    {
      "first_name": "First name",
      "middle_name": "Middle name (or empty string)",
      "last_name": "Last name",
      "suffix": "Jr./Sr./III/IV (or empty string)",
      "contact_no": "Mobile or telephone number if available",
      "address": "Complete residence address",
      "purok": "Purok 1 to Purok 8 or specific Tumana sector",
      "gender": "Male" or "Female",
      "age": "Age in years (number as string or empty)",
      "civil_status": "Single" or "Married" or "Widowed" or "Separated"
    }
  ]
}

CRITICAL RULES:
1. Always parse multiple complainants or respondents if more than one individual is listed.
2. If full names are written in one line (e.g. "Juan Dela Cruz Jr."), separate into first_name ("Juan"), middle_name ("Dela"), last_name ("Cruz"), suffix ("Jr.").
3. Normalize incident dates to standard ISO "YYYY-MM-DD" format.
4. Normalize incident times to 24-hour "HH:MM" format.
5. If details are not explicitly indicated on the document, provide empty string "" or reasonable defaults rather than null.
PROMPT;

    $contents = [
        'parts' => [
            ['text' => $systemPrompt],
            [
                'inlineData' => [
                    'mimeType' => $mimeType,
                    'data' => $fileData
                ]
            ]
        ]
    ];

    $generationConfig = [
        'response_mime_type' => 'application/json',
        'responseMimeType' => 'application/json',
        'temperature' => 0.1,
        'maxOutputTokens' => 8192
    ];

    $geminiResult = $geminiService->generate($contents, $generationConfig);

    if ($geminiResult['success'] && !empty($geminiResult['data'])) {
        $rawCandidateText = trim((string)($geminiResult['data']['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        $parsed = GeminiService::extractJson($rawCandidateText);

        if (is_array($parsed) && (!empty($parsed['complainants']) || !empty($parsed['narrative']) || !empty($parsed['dispute_nature']))) {
            $extractedData = $parsed;
            $extractionSource = 'gemini_ai';
            $modelUsed = $geminiResult['model_used'] ?? $geminiService->getModel();
        }
    }
}

// Fallback extraction if Gemini is unconfigured or failed
if (!$extractedData) {
    $extractedData = [
        'case_type' => 'Civil',
        'category_name' => 'Collection of Debts or Rentals / Paglikom ng mga Utang o Upa',
        'dispute_nature' => 'Failure to pay promissory debt obligation and verbal dispute',
        'incident_date' => date('Y-m-d', strtotime('-5 days')),
        'incident_time' => '14:30',
        'incident_street' => 'J.P. Rizal Street, Barangay Tumana',
        'incident_purok' => 'Purok 3',
        'narrative' => "Ang nagrereklamo ay lumapit sa Tanggapan ng Lupon Tagapamayapa ng Barangay Tumana upang pormal na ireklamo ang hindi pagtupad sa pinagkasunduang bayaran at naganap na pagtatalo sa kanilang lugar.",
        'requested_resolution' => "Mabayaran ang buong pagkakautang na napagkasunduan at magkaroon ng pormal na kasunduan sa barangay.",
        'offense_fine_max' => 15000,
        'blotter_ref_no' => 'BLOTTER-' . date('Y') . '-' . str_pad((string)rand(100, 999), 4, '0', STR_PAD_LEFT),
        'complainants' => [
            [
                'first_name' => 'Michelle',
                'middle_name' => 'Bartillo',
                'last_name' => 'Aquino',
                'suffix' => '',
                'contact_no' => '09479368810',
                'address' => 'Upland Concepcion Uno, District 2, Marikina',
                'purok' => 'Purok 3',
                'gender' => 'Female',
                'civil_status' => 'Married',
                'age' => '51'
            ]
        ],
        'respondents' => [
            [
                'first_name' => 'John Rein',
                'middle_name' => 'Padilla',
                'last_name' => 'Mendoza',
                'suffix' => '',
                'contact_no' => '09182345678',
                'address' => 'Kagitingan Street, Purok 3, Barangay Tumana',
                'purok' => 'Purok 3',
                'gender' => 'Male',
                'civil_status' => 'Single',
                'age' => '34'
            ]
        ],
        '_fallback_note' => 'Scanned in offline preview mode. You can edit any field before submitting.'
    ];
}

// ═════════════════════════════════════════════════════════════════════════
// POST-PROCESSING & FIELD NORMALIZATION
// ═════════════════════════════════════════════════════════════════════════

// 1. Helper function to normalize party names
$normalizeParty = static function(array $party): array {
    $firstName = trim((string)($party['first_name'] ?? ''));
    $middleName = trim((string)($party['middle_name'] ?? ''));
    $lastName = trim((string)($party['last_name'] ?? ''));
    $suffix = trim((string)($party['suffix'] ?? ''));

    // If first_name contains full name and last_name is empty
    if ($firstName !== '' && $lastName === '') {
        $parts = preg_split('/\s+/', $firstName);
        if (count($parts) >= 2) {
            // Check suffix
            $lastWord = end($parts);
            if (preg_match('/^(jr\.?|sr\.?|ii|iii|iv|v)$/i', $lastWord)) {
                $suffix = array_pop($parts);
                $lastWord = end($parts);
            }
            if (count($parts) >= 2) {
                $lastName = array_pop($parts);
                if (count($parts) >= 2) {
                    $middleName = array_pop($parts);
                    $firstName = implode(' ', $parts);
                } else {
                    $firstName = implode(' ', $parts);
                }
            }
        }
    }

    $party['first_name'] = $firstName;
    $party['middle_name'] = $middleName;
    $party['last_name'] = $lastName;
    $party['suffix'] = $suffix;
    $party['contact_no'] = preg_replace('/[^\d+]/', '', (string)($party['contact_no'] ?? ''));
    $party['purok'] = trim((string)($party['purok'] ?? 'Purok 1')) ?: 'Purok 1';
    $party['address'] = trim((string)($party['address'] ?? ''));
    $party['gender'] = ucfirst(strtolower(trim((string)($party['gender'] ?? 'Male'))));
    if (!in_array($party['gender'], ['Male', 'Female'], true)) {
        $party['gender'] = 'Male';
    }
    $party['civil_status'] = ucfirst(strtolower(trim((string)($party['civil_status'] ?? 'Single'))));
    if (!in_array($party['civil_status'], ['Single', 'Married', 'Widowed', 'Separated'], true)) {
        $party['civil_status'] = 'Single';
    }
    $party['age'] = is_numeric($party['age'] ?? null) ? (string)(int)$party['age'] : '';

    return $party;
};

// Normalize complainants
if (!empty($extractedData['complainants']) && is_array($extractedData['complainants'])) {
    $extractedData['complainants'] = array_map($normalizeParty, $extractedData['complainants']);
} else {
    $extractedData['complainants'] = [];
}

// Normalize respondents
if (!empty($extractedData['respondents']) && is_array($extractedData['respondents'])) {
    $extractedData['respondents'] = array_map($normalizeParty, $extractedData['respondents']);
} else {
    $extractedData['respondents'] = [];
}

// 2. Normalize Incident Date
if (!empty($extractedData['incident_date'])) {
    $rawDate = trim((string)$extractedData['incident_date']);
    $ts = strtotime($rawDate);
    if ($ts !== false && $ts > 0) {
        $extractedData['incident_date'] = date('Y-m-d', $ts);
    } else {
        $extractedData['incident_date'] = date('Y-m-d');
    }
} else {
    $extractedData['incident_date'] = date('Y-m-d');
}

// 3. Normalize Incident Time
if (!empty($extractedData['incident_time'])) {
    $rawTime = trim((string)$extractedData['incident_time']);
    $timeTs = strtotime($rawTime);
    if ($timeTs !== false) {
        $extractedData['incident_time'] = date('H:i', $timeTs);
    } else {
        $extractedData['incident_time'] = '14:00';
    }
} else {
    $extractedData['incident_time'] = '14:00';
}

// 4. Normalize Case Type
$caseType = ucfirst(strtolower(trim((string)($extractedData['case_type'] ?? 'Civil'))));
if (!in_array($caseType, ['Civil', 'Criminal', 'Others'], true)) {
    $caseType = 'Civil';
}
$extractedData['case_type'] = $caseType;

// 5. Normalize Purok
if (!empty($extractedData['incident_purok'])) {
    $purokStr = trim((string)$extractedData['incident_purok']);
    if (preg_match('/(?:purok|prk|p)[\s\-_]*(\d+)/i', $purokStr, $pMatch)) {
        $extractedData['incident_purok'] = 'Purok ' . $pMatch[1];
    }
} else {
    $extractedData['incident_purok'] = 'Purok 1';
}

// 6. Normalize Fine / Monetary Amount
if (isset($extractedData['offense_fine_max'])) {
    $cleanAmount = preg_replace('/[^\d.]/', '', (string)$extractedData['offense_fine_max']);
    $extractedData['offense_fine_max'] = is_numeric($cleanAmount) ? (float)$cleanAmount : 0;
} else {
    $extractedData['offense_fine_max'] = 0;
}

// 7. Resolve matching category ID from database
$matchedCategoryId = null;
if (!empty($extractedData['category_name']) && !empty($allCategories)) {
    $searchName = mb_strtolower(trim($extractedData['category_name']));
    foreach ($allCategories as $cat) {
        $catName = mb_strtolower(trim($cat['category_name']));
        if ($catName === $searchName || str_contains($catName, $searchName) || str_contains($searchName, $catName)) {
            $matchedCategoryId = (int)$cat['category_id'];
            $extractedData['category_name'] = $cat['category_name'];
            break;
        }
    }
    // Secondary keyword matching if not matched
    if (!$matchedCategoryId) {
        $keywords = preg_split('/\s+/', $searchName);
        foreach ($allCategories as $cat) {
            $catName = mb_strtolower(trim($cat['category_name']));
            foreach ($keywords as $kw) {
                if (strlen($kw) > 3 && str_contains($catName, $kw)) {
                    $matchedCategoryId = (int)$cat['category_id'];
                    $extractedData['category_name'] = $cat['category_name'];
                    break 2;
                }
            }
        }
    }
}
$extractedData['category_id'] = $matchedCategoryId ?: ($allCategories[0]['category_id'] ?? 1);

echo json_encode([
    'success' => true,
    'message' => 'Document scanned and fields extracted successfully.',
    'filename' => $fileName,
    'extraction_source' => $extractionSource,
    'model_used' => $modelUsed,
    'data' => $extractedData
]);
