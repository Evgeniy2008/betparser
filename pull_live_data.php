<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$defaultSource = 'https://snecked-lucio-unskinned.ngrok-free.dev';
$sourceBaseUrl = trim((string)($_GET['source'] ?? getenv('BETPARSER_SOURCE_URL') ?: $defaultSource));
$pullToken = trim((string)($_GET['token'] ?? getenv('BETPARSER_PULL_TOKEN') ?: ''));

if ($sourceBaseUrl === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'source URL is required'], JSON_UNESCAPED_UNICODE);
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

function pullFeed(string $sourceBaseUrl, string $feed, string $token): array
{
    $url = rtrim($sourceBaseUrl, '/') . '/feed/' . rawurlencode($feed);

    $headers = [
        'Accept: application/json',
    ];
    if ($token !== '') {
        $headers[] = 'X-Betparser-Pull-Token: ' . $token;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers) . "\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/i', $line, $m)) {
            $status = (int)$m[1];
            break;
        }
    }

    if ($raw === false) {
        return ['ok' => false, 'status' => $status ?: 502, 'error' => 'cannot fetch feed'];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'status' => $status ?: 500, 'error' => 'invalid json from source'];
    }

    if (($decoded['ok'] ?? false) !== true || !isset($decoded['payload']) || !is_array($decoded['payload'])) {
        return [
            'ok' => false,
            'status' => $status ?: 502,
            'error' => (string)($decoded['error'] ?? 'invalid source response'),
        ];
    }

    return ['ok' => true, 'status' => $status ?: 200, 'payload' => $decoded['payload']];
}

$results = [];
$okCount = 0;

foreach ($feedToFile as $feed => $targetFile) {
    $pull = pullFeed($sourceBaseUrl, $feed, $pullToken);
    if (!$pull['ok']) {
        $results[$feed] = [
            'ok' => false,
            'status' => (int)($pull['status'] ?? 500),
            'error' => (string)($pull['error'] ?? 'pull failed'),
        ];
        continue;
    }

    $payload = $pull['payload'];
    $dir = dirname($targetFile);
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        $results[$feed] = [
            'ok' => false,
            'status' => 500,
            'error' => 'cannot create target directory',
        ];
        continue;
    }

    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($encoded === false || @file_put_contents($targetFile, $encoded) === false) {
        $results[$feed] = [
            'ok' => false,
            'status' => 500,
            'error' => 'cannot write target file',
        ];
        continue;
    }

    $okCount++;
    $results[$feed] = [
        'ok' => true,
        'status' => 200,
        'file' => basename($targetFile),
        'total' => (int)($payload['total'] ?? 0),
        'updated' => (string)($payload['updated'] ?? ''),
    ];
}

echo json_encode([
    'ok' => $okCount === count($feedToFile),
    'source' => $sourceBaseUrl,
    'updated' => gmdate('c'),
    'successCount' => $okCount,
    'totalFeeds' => count($feedToFile),
    'results' => $results,
], JSON_UNESCAPED_UNICODE);
