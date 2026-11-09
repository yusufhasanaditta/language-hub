<?php
define('B_ACADEMY', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

session_start_secure();
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

if (!csrf_verify()) {
    echo json_encode(['success' => false, 'error' => 'CSRF token validation failed.']);
    exit;
}

$apiKey = get_setting('gemini_api_key');
if (empty($apiKey)) {
    echo json_encode(['success' => false, 'error' => 'Gemini API key is not configured in settings.']);
    exit;
}

if (empty($_FILES['thumbnail']['tmp_name'])) {
    echo json_encode(['success' => false, 'error' => 'No thumbnail uploaded.']);
    exit;
}

$tmpName = $_FILES['thumbnail']['tmp_name'];
$mime = mime_content_type($tmpName);

// Validate MIME type
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid image format. Supported formats: JPG, PNG, WebP.']);
    exit;
}

// Convert image to base64
$imgData = file_get_contents($tmpName);
$base64 = base64_encode($imgData);

// Prepare request for Gemini API
$prompt = "You are an AI assistant for a Bangladeshi educational platform called Language Hub.
Based on the provided course thumbnail image, please extract and infer the following course details. 
Return the output strictly as a JSON object with the following keys, and nothing else (no markdown blocks, no extra text):
{
  \"title_bn\": \"Course Name in Bengali\",
  \"title_en\": \"Course Name in English\",
  \"short_desc\": \"A brief 1-2 line description of the course (in English or Bengali)\",
  \"description_bn\": \"A detailed description of the course (in Bengali). Use newlines for paragraphs.\",
  \"what_you_learn\": \"A list of things the student will learn. Separate items with newlines.\",
  \"requirements\": \"Prerequisites or requirements. Separate items with newlines.\",
  \"target_audience\": \"Who this course is for (e.g., HSC Students, SSC Candidates)\",
  \"price\": \"numeric price if visible, otherwise 0\"
}

If any detail is not obvious from the image, make an educated guess based on typical educational courses in Bangladesh. Ensure valid JSON format.";

$payload = [
    'contents' => [
        [
            'parts' => [
                ['text' => $prompt],
                [
                    'inlineData' => [
                        'mimeType' => $mime,
                        'data' => $base64
                    ]
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.4,
        'responseMimeType' => 'application/json'
    ]
];

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($apiKey);

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, APP_ENV === 'production'); // skip only on local XAMPP

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    echo json_encode(['success' => false, 'error' => 'cURL Error: ' . $error]);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['success' => false, 'error' => 'API Error (HTTP ' . $httpCode . '): ' . $response]);
    exit;
}

$data = json_decode($response, true);
if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
    $jsonText = $data['candidates'][0]['content']['parts'][0]['text'];
    
    // Attempt to decode the generated JSON
    $generatedData = json_decode($jsonText, true);
    if ($generatedData) {
        echo json_encode(['success' => true, 'data' => $generatedData]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to parse AI response as JSON.', 'raw' => $jsonText]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Unexpected API response structure.', 'raw' => $response]);
}
