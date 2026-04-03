<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Empty request body']);
    exit;
}

$data = json_decode($body, true);
if ($data === null || !isset($data['matches'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload or missing matches field']);
    exit;
}

$target = strtolower(trim((string)($data['target'] ?? 'merged')));
$targetFile = ($target === 'live')
    ? (__DIR__ . '/data/live_matches.json')
    : (__DIR__ . '/data/merged_matches.json');
$targetName = ($target === 'live') ? 'live_matches.json' : 'merged_matches.json';

unset($data['target']);

$json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to encode JSON']);
    exit;
}

$result = @file_put_contents($targetFile, $json);
if ($result === false) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => "Unable to write {$targetName}"]);
    exit;
}

echo json_encode(['ok' => true, 'message' => "{$targetName} updated", 'matches' => count($data['matches'])]);
