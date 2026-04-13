<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$validSports = ['football', 'basketball', 'tennis', 'icehockey'];
$sport = trim((string)($_GET['sport'] ?? 'football'));
if ($sport === '' || !in_array($sport, $validSports, true)) {
    $sport = 'football';
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$dir = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
$apiPath = ($dir === '' ? '' : $dir) . '/api.php';
$url = $scheme . '://' . $host . $apiPath . '?sport=' . rawurlencode($sport) . '&_=' . time();

$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 25,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ],
]);

$raw = @file_get_contents($url, false, $context);
if ($raw === false) {
    http_response_code(502);
    echo json_encode([
        'ok' => false,
        'sport' => $sport,
        'error' => 'Failed to proxy request to api.php',
        'events' => [],
        'meta' => [
            'source' => 'fetch-proxy',
            'url' => $url,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$status = 200;
foreach (($http_response_header ?? []) as $line) {
    if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/i', $line, $matches)) {
        $status = (int)$matches[1];
        break;
    }
}

http_response_code($status >= 200 && $status < 600 ? $status : 200);
echo $raw;
