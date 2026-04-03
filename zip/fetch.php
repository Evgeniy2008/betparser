<?php
/**
 * fetch.php
 *
 * Endpoints:
 * - GET  ?status=1
 *      Returns parser status + stream info (prematch/live)
 *
 * - GET  ?ensure=1&maxAge=120&force=1
 *      Starts scraper if needed (or always when force=1)
 *
 * - POST
 *      Manual launch (optional), supports proxy param
 */

define('PREMATCH_FILE', __DIR__ . '/data/matches.json');
define('LIVE_FILE',     __DIR__ . '/data/matches_live.json');
define('STATE_FILE',    __DIR__ . '/data/scraper-state.json');
define('LOCK_FILE',     __DIR__ . '/data/scraper.lock');
define('LOG_FILE',      __DIR__ . '/data/scraper.log');

define('NODE_CMD', 'node');
define('SCRAPER',  __DIR__ . '/scraper.js');

header('Content-Type: application/json; charset=utf-8');

if (isset($_GET['status'])) {
    $streams = [
        'prematch' => read_stream(PREMATCH_FILE),
        'live' => read_stream(LIVE_FILE),
    ];

    echo json_encode([
        'running' => is_scraper_running(),
        'streams' => $streams,
        'state' => read_json(STATE_FILE, []),
        'log' => tail_log(30),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['ensure'])) {
    $maxAge = max(10, (int)($_GET['maxAge'] ?? 120));
    $force = !empty($_GET['force']);
    $proxy = trim($_GET['proxy'] ?? $_COOKIE['ua_proxy'] ?? '');

    $streams = [
        'prematch' => read_stream(PREMATCH_FILE),
        'live' => read_stream(LIVE_FILE),
    ];

    $running = is_scraper_running();

    $prematchAge = $streams['prematch']['updated'] ? (time() - strtotime($streams['prematch']['updated'])) : PHP_INT_MAX;
    $liveAge = $streams['live']['updated'] ? (time() - strtotime($streams['live']['updated'])) : PHP_INT_MAX;
    $oldestAge = max($prematchAge, $liveAge);

    $started = false;
    if (!$running && ($force || $oldestAge >= $maxAge)) {
        $started = start_scraper($proxy);
        $running = $running || $started;
    }

    echo json_encode([
        'ok' => true,
        'running' => $running,
        'started' => $started,
        'force' => $force,
        'maxAge' => $maxAge,
        'oldestAgeSec' => $oldestAge,
        'streams' => $streams,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (is_scraper_running()) {
        echo json_encode(['ok' => true, 'alreadyRunning' => true, 'msg' => 'Парсер вже працює'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $proxy = trim($_POST['proxy'] ?? '');
    $ok = start_scraper($proxy);

    echo json_encode([
        'ok' => $ok,
        'msg' => $ok ? 'Парсер запущено' : 'Не вдалося запустити парсер',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unsupported request'], JSON_UNESCAPED_UNICODE);
exit;

function read_json(string $file, array $fallback = []): array {
    if (!file_exists($file)) return $fallback;
    $decoded = json_decode(file_get_contents($file), true);
    return is_array($decoded) ? $decoded : $fallback;
}

function read_stream(string $file): array {
    if (!file_exists($file)) {
        return [
            'total' => 0,
            'updated' => null,
            'phase' => 'none',
            'cycleId' => null,
        ];
    }

    $json = read_json($file, []);

    return [
        'total' => (int)($json['total'] ?? count($json['matches'] ?? [])),
        'updated' => $json['updated'] ?? null,
        'phase' => $json['phase'] ?? 'full',
        'cycleId' => $json['cycleId'] ?? null,
    ];
}

function tail_log(int $lines = 30): string {
    if (!file_exists(LOG_FILE)) return '';
    $content = trim(file_get_contents(LOG_FILE));
    if ($content === '') return '';
    $arr = explode("\n", $content);
    return implode("\n", array_slice($arr, -$lines));
}

function is_scraper_running(): bool {
    if (!file_exists(LOCK_FILE)) return false;

    $lock = read_json(LOCK_FILE, []);
    $pid = (int)($lock['pid'] ?? 0);
    $startedAt = strtotime($lock['startedAt'] ?? '') ?: 0;
    $stale = $startedAt > 0 ? ((time() - $startedAt) > 7200) : true;

    if ($pid <= 0 || $stale) {
        @unlink(LOCK_FILE);
        return false;
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" /FO CSV 2>NUL') ?? '';
        $alive = str_contains($out, ',' . $pid . ',') || str_contains($out, '"' . $pid . '"');
        if (!$alive) {
            @unlink(LOCK_FILE);
            return false;
        }
        return true;
    }

    $alive = file_exists('/proc/' . $pid);
    if (!$alive) {
        @unlink(LOCK_FILE);
        return false;
    }
    return true;
}

function start_scraper(string $proxy = ''): bool {
    $dataDir = __DIR__ . '/data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $proxyArg = '';
    if ($proxy !== '') {
        $proxy = preg_replace('/[^a-zA-Z0-9:\/\/@._%-]/', '', $proxy);
        if ($proxy !== '') {
            $proxyArg = ' --proxy=' . escapeshellarg($proxy);
        }
    }

    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $cmd = 'start /B "" ' . escapeshellarg(NODE_CMD) . ' ' . escapeshellarg(SCRAPER) . $proxyArg
             . ' > ' . escapeshellarg(LOG_FILE) . ' 2>&1';
        @pclose(@popen($cmd, 'r'));
        return true;
    }

    $cmd = NODE_CMD . ' ' . escapeshellarg(SCRAPER) . $proxyArg
         . ' > ' . escapeshellarg(LOG_FILE) . ' 2>&1 &';
    @shell_exec($cmd);
    return true;
}
