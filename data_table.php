<?php
// data_table.php

header('Content-Type: application/json; charset=UTF-8');

$allowedClasses = ["Play","Nursery","Kg","1","2","3","4","5","6","7","8","9","10"];
$baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'table_data' . DIRECTORY_SEPARATOR;

// Ensure folder exists
if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
}

function jsonResponse($success, $message = '', $extra = []) {
    $payload = array_merge([
        'success' => $success,
        'message' => $message
    ], $extra);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

// Read JSON body or fallback to POST
$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST; // fallback
}

$action = isset($input['action']) ? trim($input['action']) : '';
$className = isset($input['className']) ? trim($input['className']) : '';

if (!$action) {
    jsonResponse(false, 'Invalid action.');
}
if (!$className || !in_array($className, $allowedClasses, true)) {
    jsonResponse(false, 'অবৈধ ক্লাস নির্বাচিত হয়েছে।');
}

$filePath = $baseDir . $className . '.txt';

switch ($action) {
    case 'save':
        // If file exists, don't overwrite
        if (file_exists($filePath)) {
            jsonResponse(false, 'দয়া করে আগে ডেটা ডিলিট করুন।');
        }
        $tableData = isset($input['tableData']) ? $input['tableData'] : null;
        if (!$tableData || !is_array($tableData)) {
            jsonResponse(false, 'ডেটা পাওয়া যায়নি।');
        }

        // Validate structure
        $headers = isset($tableData['headers']) && is_array($tableData['headers']) ? $tableData['headers'] : [];
        $rows    = isset($tableData['rows']) && is_array($tableData['rows']) ? $tableData['rows'] : [];

        $toSave = [
            'headers' => $headers,
            'rows'    => $rows
        ];

        $json = json_encode($toSave, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            jsonResponse(false, 'JSON এনকোডিংয়ে সমস্যা হয়েছে।');
        }

        $written = @file_put_contents($filePath, $json);
        if ($written === false) {
            jsonResponse(false, 'ফাইলে লিখতে ব্যর্থ। ফোল্ডারের পারমিশন চেক করুন।');
        }

        jsonResponse(true, 'ডেটা সফলভাবে সেভ হয়েছে।');
        break;

    case 'read':
        if (!file_exists($filePath)) {
            jsonResponse(false, 'এখনো কোনো রেজাল্ট আনা হয়নি 🙂।');
        }
        $content = @file_get_contents($filePath);
        if ($content === false) {
            jsonResponse(false, 'ফাইল পড়তে সমস্যা হয়েছে।');
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            // In case file content is not valid JSON
            jsonResponse(true, '', ['data' => ['headers' => [], 'rows' => []]]);
        }
        jsonResponse(true, '', ['data' => $decoded]);
        break;

    case 'delete':
        if (!file_exists($filePath)) {
            jsonResponse(false, 'ডিলিট করার মতো কোনো ফাইল পাওয়া যায়নি।');
        }
        $ok = @unlink($filePath);
        if (!$ok) {
            jsonResponse(false, 'ফাইল ডিলিট করতে সমস্যা হয়েছে। পারমিশন চেক করুন।');
        }
        jsonResponse(true, 'ফাইল ডিলিট করা হয়েছে।');
        break;

    default:
        jsonResponse(false, 'Unsupported action.');
}