<?php
$_GET['sport'] = 'football';
$_GET['format'] = 'json';

$sportTab = in_array($_GET['sport'] ?? '', ['football', 'basketball', 'tennis'], true) ? $_GET['sport'] : 'football';
$search = trim((string)($_GET['q'] ?? ''));

$cacheFile = __DIR__ . '/data/merged_matches.json';
$payload = is_file($cacheFile) ? json_decode((string)file_get_contents($cacheFile), true) : null;
$allRows = is_array($payload['matches'] ?? null) ? $payload['matches'] : [];

echo "=== TESTING UI LOGIC ===\n";
echo "Cache file exists: " . (is_file($cacheFile) ? 'YES' : 'NO') . "\n";
echo "Total rows in cache: " . count($allRows) . "\n\n";

// Test filtering
foreach (['football', 'basketball', 'tennis'] as $sport) {
    $rowsBySport = array_values(array_filter($allRows, static function (array $row) use ($sport): bool {
        $baseSport = (string)($row['sport_base'] ?? '');
        $isLive = !empty($row['isLive']) || ($row['phase'] ?? '') === 'live';
        
        // Only include live matches
        if (!$isLive) return false;
        // Filter by selected sport
        return $baseSport === $sport;
    }));
    
    echo "Sport: $sport\n";
    echo "  Live matches: " . count($rowsBySport) . "\n";
    if (count($rowsBySport) > 0) {
        echo "  Sample: " . $rowsBySport[0]['home'] . " vs " . $rowsBySport[0]['away'] . " (" . $rowsBySport[0]['league'] . ")\n";
    }
    echo "\n";
}
?>
