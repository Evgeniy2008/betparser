<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Totals functionality disabled
respond(true, null, [
    'totals' => [],
    'marketTitle' => 'Тотали вимкнені',
]);

function is_valid_event_url(string $url): bool {
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
        return false;
    }

    if (strtolower($parts['scheme']) !== 'https') return false;
    if (strtolower($parts['host']) !== '24-parik.club') return false;
    return str_starts_with($parts['path'], '/uk/events/');
}

function respond(bool $ok, ?string $error = null, array $extra = []): void {
    $payload = array_merge([
        'ok' => $ok,
        'error' => $error,
    ], $extra);

    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
