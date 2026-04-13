<?php
const ODDS_API_KEY = '9623994920669bbd2f1c7d8d94181e0c';
const ODDS_API_HOST = 'https://api.the-odds-api.com';

$now = time();

echo "=== UPCOMING (checking for basketball) ===\n";
$url = ODDS_API_HOST . '/v4/sports/upcoming/odds/?apiKey=' . ODDS_API_KEY . '&regions=us&markets=h2h&oddsFormat=decimal&dateFormat=iso';
$raw = @file_get_contents($url);
$data = json_decode($raw, true);

if (is_array($data)) {
    echo "Total events: " . count($data) . "\n";
    $byType = [];
    $live = 0;
    foreach ($data as $evt) {
        $type = $evt['sport_key'] ?? 'unknown';
        $byType[$type] = ($byType[$type] ?? 0) + 1;
        $ts = strtotime($evt['commence_time'] ?? '');
        if ($ts > 0 && $ts <= $now) $live++;
    }
    echo "Live events: $live\n\n";
    echo "By type:\n";
    arsort($byType);  // Sort by count descending
    foreach ($byType as $type => $cnt) {
        $isLive = 0;
        foreach ($data as $evt) {
            if (($evt['sport_key'] ?? '') !== $type) continue;
            $ts = strtotime($evt['commence_time'] ?? '');
            if ($ts > 0 && $ts <= $now) $isLive++;
        }
        echo "  $type: $cnt (live: $isLive)\n";
    }
    
    echo "\n=== Basketball events (if any) ===\n";
    $basketballCount = 0;
    foreach ($data as $evt) {
        if (strpos($evt['sport_key'] ?? '', 'basketball') === false) continue;
        $basketballCount++;
        $ts = strtotime($evt['commence_time'] ?? '');
        $isLive = $ts > 0 && $ts <= $now ? 'YES' : 'NO';
        echo "  " . $evt['home_team'] . " vs " . $evt['away_team'] . " ({$evt['sport_key']}) - Live: $isLive\n";
        if ($basketballCount >= 5) break;
    }
    if ($basketballCount == 0) {
        echo "  No basketball events found\n";
    }
    
} else {
    echo "ERROR: " . $raw . "\n";
}
?>
