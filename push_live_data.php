<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON']);
    exit;
}

$feed = trim((string)($data['feed'] ?? ''));
$payload = $data['payload'] ?? null;
if ($feed === '' || !is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'feed and payload are required']);
    exit;
}

$feedToFile = [
    'parik24_football' => __DIR__ . '/data/parik24_raw.json',
    'parik24_basketball' => __DIR__ . '/data/parik24_basketball_raw.json',
    'parik24_tennis' => __DIR__ . '/data/parik24_tennis_raw.json',
    'pinnacle_football' => __DIR__ . '/data/pinnacle_raw.json',
    'pinnacle_basketball' => __DIR__ . '/data/pinnacle_basketball_raw.json',
    'pinnacle_tennis' => __DIR__ . '/data/pinnacle_tennis_raw.json',
];

if (!isset($feedToFile[$feed])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown feed']);
    exit;
}

$expectedToken = (string)(getenv('BETPARSER_PUSH_TOKEN') ?: '');
$providedToken = (string)($_SERVER['HTTP_X_BETPARSER_TOKEN'] ?? ($data['token'] ?? ''));
if ($expectedToken !== '' && !hash_equals($expectedToken, $providedToken)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$targetFile = $feedToFile[$feed];
$dir = dirname($targetFile);
if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot create data directory']);
    exit;
}

$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
if ($encoded === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot encode payload']);
    exit;
}

if (@file_put_contents($targetFile, $encoded) === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Cannot write file']);
    exit;
}

echo json_encode([
    'ok' => true,
    'feed' => $feed,
    'file' => basename($targetFile),
    'updated' => gmdate('c'),
]);
