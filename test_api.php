<?php
const ODDS_API_KEY = '9623994920669bbd2f1c7d8d94181e0c';
const ODDS_API_HOST = 'https://api.the-odds-api.com';

$now = time();

// Check basketball
echo "=== BASKETBALL ===\n";
$url = ODDS_API_HOST . '/v4/sports/basketball_nba/odds/?apiKey=' . ODDS_API_KEY . '&regions=us&markets=h2h&oddsFormat=decimal&dateFormat=iso';
$raw = @file_get_contents($url);
$data = json_decode($raw, true);
if (is_array($data)) {
    echo "Total events: " . count($data) . "\n";
    $live = 0;
    foreach ($data as $evt) {
        $ts = strtotime($evt['commence_time'] ?? '');
        if ($ts > 0 && $ts <= $now) $live++;
    }
    echo "Live events: $live\n";
    if (count($data) > 0) {
        echo "First event: " . $data[0]['home_team'] . " vs " . $data[0]['away_team'];
        echo " (commence: " . $data[0]['commence_time'] . ")\n";
    }
} else {
    echo "ERROR: " . $raw . "\n";
}

echo "\n=== TENNIS ATP ===\n";
$url = ODDS_API_HOST . '/v4/sports/tennis_atp/odds/?apiKey=' . ODDS_API_KEY . '&regions=us&markets=h2h&oddsFormat=decimal&dateFormat=iso';
$raw = @file_get_contents($url);
$data = json_decode($raw, true);
if (is_array($data)) {
    echo "Total events: " . count($data) . "\n";
    $live = 0;
    foreach ($data as $evt) {
        $ts = strtotime($evt['commence_time'] ?? '');
        if ($ts > 0 && $ts <= $now) $live++;
    }
    echo "Live events: $live\n";
    if (count($data) > 0) {
        echo "First event: " . $data[0]['home_team'] . " vs " . $data[0]['away_team'];
        echo " (commence: " . $data[0]['commence_time'] . ")\n";
    }
} else {
    echo "ERROR: " . $raw . "\n";
}

echo "\n=== FOOTBALL (soccer_eng_premier_league) ===\n";
$url = ODDS_API_HOST . '/v4/sports/soccer_eng_premier_league/odds/?apiKey=' . ODDS_API_KEY . '&regions=us&markets=h2h&oddsFormat=decimal&dateFormat=iso';
$raw = @file_get_contents($url);
$data = json_decode($raw, true);
if (is_array($data)) {
    echo "Total events: " . count($data) . "\n";
    $live = 0;
    foreach ($data as $evt) {
        $ts = strtotime($evt['commence_time'] ?? '');
        if ($ts > 0 && $ts <= $now) $live++;
    }
    echo "Live events: $live\n";
    if (count($data) > 0) {
        echo "First event: " . $data[0]['home_team'] . " vs " . $data[0]['away_team'];
        echo " (commence: " . $data[0]['commence_time'] . ")\n";
    }
} else {
    echo "ERROR: " . $raw . "\n";
}

echo "\n=== UPCOMING (all sports) ===\n";
$url = ODDS_API_HOST . '/v4/sports/upcoming/odds/?apiKey=' . ODDS_API_KEY . '&regions=us&markets=h2h&oddsFormat=decimal&dateFormat=iso';
$raw = @file_get_contents($url);
$data = json_decode($raw, true);
if (is_array($data)) {
    echo "Total events: " . count($data) . "\n";
    $live = 0;
    $byType = [];
    foreach ($data as $evt) {
        $type = $evt['sport_key'] ?? 'unknown';
        $byType[$type] = ($byType[$type] ?? 0) + 1;
        $ts = strtotime($evt['commence_time'] ?? '');
        if ($ts > 0 && $ts <= $now) $live++;
    }
    echo "Live events: $live\n";
    echo "By type:\n";
    foreach ($byType as $type => $cnt) {
        echo "  $type: $cnt\n";
    }
} else {
    echo "ERROR: " . $raw . "\n";
}
?>
