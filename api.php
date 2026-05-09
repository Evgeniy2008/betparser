<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

const API_SPORTS_KEY = 'e0159591de2cf3d1b077fc4af39fbfef';
const VALID_BASE_SPORTS = ['football', 'basketball', 'tennis', 'icehockey', 'parik24'];
const API_RESPONSE_CACHE_SECONDS = 6;
const FOOTBALL_FIXTURES_CACHE_SECONDS = 1800;
const API_SPORTS_CONFIG = [
    'football' => [
        'baseUrl' => 'https://v3.football.api-sports.io',
        'fixturesEndpoint' => '/fixtures',
        'idPath' => ['fixture', 'id'],
        'oddsParam' => 'fixture',
        'oddsEndpoints' => ['/odds/live', '/odds'],
        'fixturesQueries' => [
            ['live' => 'all'],
        ],
    ],
    'basketball' => [
        'baseUrl' => 'https://v1.basketball.api-sports.io',
        'fixturesEndpoint' => '/games',
        'idPath' => ['id'],
        'oddsParam' => 'game',
        'oddsEndpoints' => ['/odds'],
        'fixturesQueries' => [
            ['date' => '__TODAY__'],
        ],
    ],
    'tennis' => [
        'baseUrl' => 'https://v1.tennis.api-sports.io',
        'fixturesEndpoint' => '/games',
        'idPath' => ['id'],
        'oddsParam' => 'game',
        'oddsEndpoints' => ['/odds'],
        'fixturesQueries' => [
            ['date' => '__TODAY__'],
        ],
    ],
    'icehockey' => [
        'baseUrl' => 'https://v1.hockey.api-sports.io',
        'fixturesEndpoint' => '/games',
        'idPath' => ['id'],
        'oddsParam' => 'game',
        'oddsEndpoints' => ['/odds'],
        'fixturesQueries' => [
            ['date' => '__TODAY__'],
        ],
    ],
];
const API_MAX_FIXTURES = 80;

const TARGET_REGIONS = 'eu';
const TARGET_MARKETS = 'h2h,spreads';

const MIN_VALID_ODD = 1.01;
const MAX_VALID_ODD = 100.0;
const PREFERRED_SECOND_KEY = 'betfair';

$sport = trim((string)($_GET['sport'] ?? 'football'));
if ($sport === '') {
    $sport = 'football';
}
if (!in_array($sport, VALID_BASE_SPORTS, true)) {
    $sport = 'football';
}

$response = fetch_events($sport);
http_response_code($response['ok'] ? 200 : 502);
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

function fetch_events(string $targetSport): array
{
    $cachedResponse = read_api_response_cache($targetSport, api_response_cache_ttl($targetSport));
    if (is_array($cachedResponse)) {
        return $cachedResponse;
    }

    if ($targetSport === 'football') {
        $eventsResponse = fetch_combined_live_football_events();
    } elseif ($targetSport === 'basketball') {
        $eventsResponse = fetch_combined_live_basketball_events();
    } elseif ($targetSport === 'tennis') {
        $eventsResponse = fetch_combined_live_tennis_events();
    } elseif ($targetSport === 'parik24') {
        $eventsResponse = fetch_parik24_live_events();
    } else {
        $eventsResponse = fetch_api_sports_live_events($targetSport);
    }

    if (!$eventsResponse['ok']) {
        $errorResponse = [
            'ok' => false,
            'sport' => $targetSport,
            'updated' => gmdate('c'),
            'events' => [],
            'error' => (string)($eventsResponse['error'] ?? 'API-Sports error'),
            'meta' => [
                'status' => (int)($eventsResponse['status'] ?? 0),
                'quota' => $eventsResponse['quota'] ?? [],
            ],
        ];

        write_api_response_cache($targetSport, $errorResponse);
        return $errorResponse;
    }

    $grouped = is_array($eventsResponse['events'] ?? null) ? $eventsResponse['events'] : [];

    $mergedSports = ['football', 'basketball', 'tennis'];
    $defaultSecond = in_array($targetSport, $mergedSports, true)
        ? ['key' => 'parik24', 'title' => 'Parik24', 'mode' => 'live']
        : detect_default_second_bookmaker($grouped);
    if (in_array($targetSport, array_merge($mergedSports, ['parik24']), true) && empty($defaultSecond['title'])) {
        $defaultSecond = [
            'key' => null,
            'title' => $targetSport === 'parik24' ? 'Parik24 Live' : 'Live Odds',
            'mode' => 'live',
        ];
    }

    $allEventsHavePinnacle = count($grouped) === count(array_filter($grouped, static function (array $event): bool {
        return !empty($event['hasPinnacle']);
    }));

    foreach ($grouped as &$event) {
        $event['defaultSecondKey'] = $defaultSecond['key'] && isset($event['seconds'][$defaultSecond['key']])
            ? $defaultSecond['key']
            : ($event['defaultSecondKey'] ?? null);
    }
    unset($event);

    $result = [
        'ok' => true,
        'sport' => $targetSport,
        'updated' => gmdate('c'),
        'events' => $grouped,
        'error' => null,
        'meta' => [
            'eventsTotal' => count($grouped),
            'rowsNormalized' => count($grouped),
            'quota' => $eventsResponse['quota'] ?? [],
            'source' => in_array($targetSport, ['football', 'basketball', 'tennis'], true)
                ? 'merged-live-parik24-pinnacle'
                : (in_array($targetSport, ['parik24'], true) ? 'parik24-live-ws' : 'api-sports-live'),
            'defaultSecondKey' => $defaultSecond['key'],
            'defaultSecondTitle' => $defaultSecond['title'],
            'defaultSecondMode' => $defaultSecond['mode'],
            'allEventsHavePinnacle' => $allEventsHavePinnacle,
            'fixturesFetched' => (int)($eventsResponse['fixturesFetched'] ?? 0),
            'oddsFetched' => (int)($eventsResponse['oddsFetched'] ?? 0),
        ],
    ];

    write_api_response_cache($targetSport, $result);
    return $result;
}

function api_response_cache_ttl(string $sport): int
{
    return in_array($sport, ['football', 'basketball', 'tennis', 'parik24'], true) ? API_RESPONSE_CACHE_SECONDS : 60;
}

function fetch_parik24_live_events(): array
{
    $path = __DIR__ . '/data/parik24_raw.json';
    if (!is_file($path)) {
        return [
            'ok' => false,
            'status' => 404,
            'error' => 'parik24_raw.json not found. Start tools/parik24_live_worker.js first.',
            'quota' => [],
        ];
    }

    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload)) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'parik24_raw.json is invalid.',
            'quota' => [],
        ];
    }

    $rows = is_array($payload['matches'] ?? null) ? $payload['matches'] : [];
    $events = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $home = trim((string)($row['home'] ?? ''));
        $away = trim((string)($row['away'] ?? ''));
        if ($home === '' || $away === '' || is_suspicious_live_matchup($home, $away)) {
            continue;
        }
        if (is_suspicious_live_league((string)($row['league'] ?? ''))) {
            continue;
        }

        $scoreRaw = trim((string)($row['score'] ?? ''));
        $score = ['home' => null, 'away' => null];
        if ($scoreRaw !== '' && preg_match('/^(\d+)\s*[:-]\s*(\d+)$/', $scoreRaw, $m)) {
            $score['home'] = (int)$m[1];
            $score['away'] = (int)$m[2];
        }

        $events[] = [
            'id' => (string)($row['eventId'] ?? md5($home . '|' . $away)),
            'sport' => 'football',
            'marketMode' => 'live-odds',
            'marketTitle' => 'Parik24 Live',
            'league' => (string)($row['league'] ?? 'Parik24'),
            'leagueLogo' => '',
            'home' => $home,
            'away' => $away,
            'homeLogo' => '',
            'awayLogo' => '',
            'time' => (string)($row['time'] ?? ''),
            'isLive' => true,
            'statusShort' => 'LIVE',
            'statusLong' => 'Live',
            'elapsed' => $row['elapsed'] ?? null,
            'liveSeconds' => '',
            'score' => $score,
            'stats' => [
                'bookmakersTotal' => 1,
                'validSecondsTotal' => 0,
                'pinnacleLastUpdate' => (string)($payload['updated'] ?? ''),
                'apiLastUpdate' => gmdate('c'),
                'marketsTotal' => 1,
                'referee' => '',
                'venueName' => '',
                'venueCity' => '',
            ],
            'pinnacle' => [
                'key' => 'parik24',
                'title' => 'Parik24',
                'link' => (string)($row['link'] ?? ''),
                'p1' => $row['p1'] ?? null,
                'x' => $row['x'] ?? null,
                'p2' => $row['p2'] ?? null,
            ],
            'hasPinnacle' => true,
            'seconds' => [],
            'bestSecondKey' => null,
            'bestMinFormula' => null,
            'defaultSecondKey' => null,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        $elapsedA = (int)($a['elapsed'] ?? 0);
        $elapsedB = (int)($b['elapsed'] ?? 0);
        if ($elapsedA !== $elapsedB) {
            return $elapsedB <=> $elapsedA;
        }

        return strcmp((string)($a['league'] ?? ''), (string)($b['league'] ?? ''));
    });

    return [
        'ok' => true,
        'status' => 200,
        'events' => $events,
        'quota' => [],
        'fixturesFetched' => count($events),
        'oddsFetched' => count($events),
    ];
}

function fetch_combined_live_football_events(): array
{
    return fetch_combined_live_sport_events(
        __DIR__ . '/data/parik24_raw.json',
        __DIR__ . '/data/pinnacle_raw.json',
        'football', 'Football'
    );
}

function fetch_combined_live_basketball_events(): array
{
    return fetch_combined_live_sport_events(
        __DIR__ . '/data/parik24_basketball_raw.json',
        __DIR__ . '/data/pinnacle_basketball_raw.json',
        'basketball', 'Basketball'
    );
}

function fetch_combined_live_tennis_events(): array
{
    return fetch_combined_live_sport_events(
        __DIR__ . '/data/parik24_tennis_raw.json',
        __DIR__ . '/data/pinnacle_tennis_raw.json',
        'tennis', 'Tennis'
    );
}

function fetch_combined_live_sport_events(string $parikFile, string $pinFile, string $sport, string $sportTitle): array
{
    $parikLoad = load_live_feed_rows($parikFile, basename($parikFile) . ' not found.');
    if (!$parikLoad['ok']) {
        return $parikLoad;
    }

    $pinnacleLoad = load_live_feed_rows($pinFile, basename($pinFile) . ' not found.');
    if (!$pinnacleLoad['ok']) {
        return $pinnacleLoad;
    }

    $parikRows = is_array($parikLoad['rows'] ?? null) ? $parikLoad['rows'] : [];
    $pinnacleRows = is_array($pinnacleLoad['rows'] ?? null) ? $pinnacleLoad['rows'] : [];

    $parikDescriptors = [];
    foreach ($parikRows as $row) {
        $descriptor = build_live_feed_descriptor($row, 'parik24');
        if ($descriptor !== null) {
            $parikDescriptors[] = $descriptor;
        }
    }

    $pinnacleDescriptors = [];
    foreach ($pinnacleRows as $row) {
        $descriptor = build_live_feed_descriptor($row, 'pinnacle');
        if ($descriptor !== null) {
            $pinnacleDescriptors[] = $descriptor;
        }
    }

    $pairs = [];
    foreach ($pinnacleDescriptors as $pinIndex => $pin) {
        foreach ($parikDescriptors as $parikIndex => $parik) {
            $score = score_live_match_pair($parik, $pin);
            if ($score >= 30) {
                $pairs[] = [
                    'score' => $score,
                    'pin' => $pinIndex,
                    'parik' => $parikIndex,
                ];
            }
        }
    }

    usort($pairs, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    $usedParik = [];
    $usedPin = [];
    $mergedEvents = [];

    foreach ($pairs as $pair) {
        $pinIndex = (int)$pair['pin'];
        $parikIndex = (int)$pair['parik'];
        if (isset($usedParik[$parikIndex]) || isset($usedPin[$pinIndex])) {
            continue;
        }

        $parik = $parikDescriptors[$parikIndex];
        $pin   = $pinnacleDescriptors[$pinIndex];

        // Only emit events where BOTH bookmakers actually carry odds. The
        // matching score can crown a "best" pair where Parik24's row is
        // momentarily null (feed flicker) — without this guard such pairs
        // would surface as pinnacle-only rows in the UI.
        $pinHasOdds   = (($pin['p1']   ?? null) !== null) || (($pin['p2']   ?? null) !== null);
        $parikHasOdds = (($parik['p1'] ?? null) !== null) || (($parik['p2'] ?? null) !== null);
        if (!$pinHasOdds || !$parikHasOdds) {
            continue;
        }

        $usedParik[$parikIndex] = true;
        $usedPin[$pinIndex] = true;
        $mergedEvents[] = build_merged_live_event($parik, $pin, $parikLoad['updated'], $pinnacleLoad['updated'], $sport, $sportTitle);
    }

    // Only matches that exist in BOTH bookmakers (with real odds on both
    // sides) reach the UI — unmatched Pinnacle/Parik24 rows are excluded.

    usort($mergedEvents, static function (array $a, array $b): int {
        $elapsedA = (int)($a['elapsed'] ?? 0);
        $elapsedB = (int)($b['elapsed'] ?? 0);
        if ($elapsedA !== $elapsedB) {
            return $elapsedB <=> $elapsedA;
        }

        $hasPinA = !empty($a['hasPinnacle']);
        $hasPinB = !empty($b['hasPinnacle']);
        if ($hasPinA !== $hasPinB) {
            return $hasPinB <=> $hasPinA;
        }

        return strcmp((string)($a['league'] ?? ''), (string)($b['league'] ?? ''));
    });

    return [
        'ok' => true,
        'status' => 200,
        'events' => $mergedEvents,
        'quota' => [],
        'fixturesFetched' => count($mergedEvents),
        'oddsFetched' => count($mergedEvents),
    ];
}

function load_live_feed_rows(string $path, string $missingMessage): array
{
    if (!is_file($path)) {
        return [
            'ok' => false,
            'status' => 404,
            'error' => $missingMessage,
            'quota' => [],
        ];
    }

    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload)) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => basename($path) . ' is invalid.',
            'quota' => [],
        ];
    }

    return [
        'ok' => true,
        'status' => 200,
        'rows' => is_array($payload['matches'] ?? null) ? $payload['matches'] : [],
        'updated' => (string)($payload['updated'] ?? ''),
        'payload' => $payload,
    ];
}

function build_live_feed_descriptor(array $row, string $source): ?array
{
    $home = trim((string)($row['home'] ?? ''));
    $away = trim((string)($row['away'] ?? ''));
    if ($home === '' || $away === '' || is_suspicious_live_matchup($home, $away)) {
        return null;
    }

    $league = trim((string)($row['league'] ?? ''));
    if (is_suspicious_live_league($league)) {
        return null;
    }
    $link = trim((string)($row['link'] ?? ''));
    $slug = extract_live_feed_slug($link);
    $scoreRaw = trim((string)($row['score'] ?? ''));
    $score = parse_live_score_pair($scoreRaw);
    $elapsed = isset($row['elapsed']) && $row['elapsed'] !== '' ? (int)$row['elapsed'] : null;

    return [
        'source' => $source,
        'eventId' => (string)($row['eventId'] ?? md5($source . '|' . $home . '|' . $away)),
        'home' => $home,
        'away' => $away,
        'league' => $league,
        'link' => $link,
        'slug' => $slug,
        'scoreRaw' => $scoreRaw,
        'score' => $score,
        'elapsed' => $elapsed,
        'time' => (string)($row['time'] ?? ''),
        'status' => (string)($row['status'] ?? ($row['statusCode'] ?? '')),
        'p1' => $row['p1'] ?? null,
        'x' => $row['x'] ?? null,
        'p2' => $row['p2'] ?? null,
        'tokens' => tokenize_live_match_text(implode(' ', [$home, $away, $league, $slug])),
        'homeTokens' => tokenize_live_match_text($home),
        'awayTokens' => tokenize_live_match_text($away),
        'leagueTokens' => tokenize_live_match_text($league),
    ];
}

function build_merged_live_event(?array $parik, ?array $pin, string $parikUpdated, string $pinUpdated, string $sport = 'football', string $sportTitle = 'Football'): array
{
    $displayHome = trim((string)(($parik['home'] ?? '') !== '' ? $parik['home'] : ($pin['home'] ?? '')));
    $displayAway = trim((string)(($parik['away'] ?? '') !== '' ? $parik['away'] : ($pin['away'] ?? '')));
    $score = ($parik['scoreRaw'] ?? '') !== '' ? (string)$parik['scoreRaw'] : (string)($pin['scoreRaw'] ?? '');
    $scorePair = parse_live_score_pair($score);
    $scoreDetail = trim($score);
    $elapsed = $parik['elapsed'] ?? $pin['elapsed'] ?? null;
    $parikAvailable = $parik !== null && (($parik['p1'] ?? null) !== null || ($parik['x'] ?? null) !== null || ($parik['p2'] ?? null) !== null);
    $pinAvailable = $pin !== null && (($pin['p1'] ?? null) !== null || ($pin['x'] ?? null) !== null || ($pin['p2'] ?? null) !== null);

    $seconds = [];
    if ($parik !== null) {
        $seconds['parik24'] = [
            'key' => 'parik24',
            'title' => 'Parik24',
            'link' => (string)($parik['link'] ?? ''),
            'p1' => $parik['p1'] ?? null,
            'x' => $parik['x'] ?? null,
            'p2' => $parik['p2'] ?? null,
        ];
    }

    return [
        'id' => (string)($pin['eventId'] ?? ($parik['eventId'] ?? md5($displayHome . '|' . $displayAway))),
        'sport' => $sport,
        'marketMode' => 'live-odds',
        'marketTitle' => 'Live ' . $sportTitle,
        'league' => trim((string)(($pin['league'] ?? '') !== '' ? $pin['league'] : ($parik['league'] ?? $sportTitle))),
        'leagueLogo' => '',
        'home' => $displayHome,
        'away' => $displayAway,
        'homeLogo' => '',
        'awayLogo' => '',
        'time' => (string)($pin['time'] ?? ($parik['time'] ?? '')),
        'isLive' => true,
        'statusShort' => 'LIVE',
        'statusLong' => 'Live',
        'elapsed' => $elapsed,
        'liveSeconds' => '',
        'score' => [
            'home' => $scorePair['home'],
            'away' => $scorePair['away'],
        ],
        'scoreDetail' => $scoreDetail !== '' ? $scoreDetail : null,
        'stats' => [
            'bookmakersTotal' => ($pin !== null ? 1 : 0) + ($parik !== null ? 1 : 0),
            'validSecondsTotal' => $parik !== null ? 1 : 0,
            'pinnacleLastUpdate' => $pinUpdated,
            'apiLastUpdate' => gmdate('c'),
            'marketsTotal' => ($pin !== null ? 1 : 0) + ($parik !== null ? 1 : 0),
            'referee' => '',
            'venueName' => '',
            'venueCity' => '',
            'parik24LastUpdate' => $parikUpdated,
        ],
        'pinnacle' => [
            'key' => 'pinnacle',
            'title' => 'Pinnacle',
            'link' => (string)($pin['link'] ?? ''),
            'p1' => $pin['p1'] ?? null,
            'x' => $pin['x'] ?? null,
            'p2' => $pin['p2'] ?? null,
        ],
        'hasPinnacle' => $pinAvailable,
        'seconds' => $seconds,
        'bestSecondKey' => $parikAvailable ? 'parik24' : null,
        'bestMinFormula' => null,
        'defaultSecondKey' => $parikAvailable ? 'parik24' : null,
    ];
}

function score_live_match_pair(array $parik, array $pin): float
{
    $parikHome = $parik['homeTokens'] ?? [];
    $parikAway = $parik['awayTokens'] ?? [];
    $pinHome   = $pin['homeTokens']   ?? [];
    $pinAway   = $pin['awayTokens']   ?? [];
    if (!$parikHome || !$parikAway || !$pinHome || !$pinAway) {
        return 0.0;
    }

    // Reject senior↔youth and men↔women cross-pairs. If one feed labels the
    // match as U-19 / women / reserves and the other doesn't, they are not
    // the same fixture even when team names are identical.
    if (live_pair_category_mismatch($parik, $pin)) {
        return 0.0;
    }

    // Require BOTH home and away to overlap. Try direct (parikHome↔pinHome,
    // parikAway↔pinAway) and reversed (in case feeds disagree on which side
    // is "home"). Without this guard a single shared word like "division"
    // pulled from the league name could pair completely unrelated matches.
    $direct  = team_pair_match_score($parikHome, $pinHome, $parikAway, $pinAway);
    $reverse = team_pair_match_score($parikHome, $pinAway, $parikAway, $pinHome);
    $teamScore = max($direct, $reverse);
    if ($teamScore <= 0) {
        return 0.0;
    }

    $score = $teamScore;

    // ── League bonus (small — leagues across feeds often differ) ─────────
    $leagueCommon = count(array_unique(array_intersect($parik['leagueTokens'] ?? [], $pin['leagueTokens'] ?? [])));
    $score += min(12, $leagueCommon * 4);

    // ── Score match bonus / penalty ───────────────────────────────────────
    $scoreParik = (string)($parik['scoreRaw'] ?? '');
    $scorePin   = (string)($pin['scoreRaw']   ?? '');
    if ($scoreParik !== '' && $scorePin !== '') {
        $pairParik = parse_live_score_pair($scoreParik);
        $pairPin   = parse_live_score_pair($scorePin);
        if ($pairParik['home'] !== null && $pairPin['home'] !== null) {
            if ($pairParik['home'] === $pairPin['home'] && $pairParik['away'] === $pairPin['away']) {
                $score += 35;
            } else {
                $score -= 15;
            }
        }
    }

    // ── Elapsed proximity bonus / penalty ────────────────────────────────
    $elapsedParik = $parik['elapsed'] ?? null;
    $elapsedPin   = $pin['elapsed']   ?? null;
    if ($elapsedParik !== null && $elapsedPin !== null) {
        $diff = abs((int)$elapsedParik - (int)$elapsedPin);
        if ($diff <= 3) {
            $score += 20;
        } elseif ($diff <= 7) {
            $score += 15;
        } elseif ($diff <= 12) {
            $score += 8;
        } elseif ($diff <= 20) {
            $score += 3;
        } else {
            $score -= min(18, $diff - 20);
        }
    }

    return $score;
}

/**
 * True when one descriptor is tagged with a category token (U-19, U-21,
 * women, reserves…) and the other is not — i.e. one feed has the senior
 * Eyupspor and the other has Eyupspor U-19. Such pairs share team names
 * but are different fixtures.
 */
function live_pair_category_mismatch(array $a, array $b): bool
{
    $cats = ['u15','u16','u17','u18','u19','u20','u21','u22','u23','women','reserves'];
    $tokensA = array_merge($a['homeTokens'] ?? [], $a['awayTokens'] ?? [], $a['leagueTokens'] ?? []);
    $tokensB = array_merge($b['homeTokens'] ?? [], $b['awayTokens'] ?? [], $b['leagueTokens'] ?? []);
    foreach ($cats as $c) {
        $inA = in_array($c, $tokensA, true);
        $inB = in_array($c, $tokensB, true);
        if ($inA !== $inB) {
            return true;
        }
    }
    return false;
}

/**
 * Score how well a pair of (home, away) token sets matches another pair.
 * Returns 0 unless both sides have at least one shared token (exact or
 * close fuzzy match). The score reflects the strength of those overlaps.
 */
function team_pair_match_score(array $aHome, array $bHome, array $aAway, array $bAway): float
{
    $homeMatch = team_token_overlap($aHome, $bHome);
    $awayMatch = team_token_overlap($aAway, $bAway);
    if ($homeMatch['score'] <= 0 || $awayMatch['score'] <= 0) {
        return 0.0;
    }
    return $homeMatch['score'] + $awayMatch['score'];
}

/**
 * Compute overlap between two single-team token sets.
 * Returns ['score' => float] — 0 if no signal, otherwise a positive number
 * weighted by exact matches (preferring long, identity-bearing tokens) and
 * a small fuzzy-match contribution.
 */
function team_token_overlap(array $a, array $b): array
{
    if (!$a || !$b) {
        return ['score' => 0.0];
    }

    $exact = array_values(array_intersect(array_unique($a), array_unique($b)));
    $score = 0.0;
    $sigCount = 0;
    foreach ($exact as $t) {
        $len = strlen($t);
        if ($len >= 6) { $score += 22; $sigCount++; }
        elseif ($len >= 4) { $score += 14; $sigCount++; }
        else { $score += 6; }
    }

    // Fuzzy fallback only if we have NO good exact match yet — substring or
    // close Levenshtein on long tokens. Capped low so it cannot dominate.
    if ($sigCount === 0) {
        $bestFuzzy = 0.0;
        foreach ($a as $tp) {
            if (strlen($tp) < 5) continue;
            foreach ($b as $tq) {
                if (strlen($tq) < 5) continue;
                if (in_array($tp, $exact, true)) continue;
                if (str_contains($tq, $tp) || str_contains($tp, $tq)) {
                    $bestFuzzy = max($bestFuzzy, min(strlen($tp), strlen($tq)) >= 6 ? 18 : 10);
                    continue;
                }
                $shorter = min(strlen($tp), strlen($tq));
                $maxDist = max(1, (int)floor($shorter * 0.30));
                if (abs(strlen($tp) - strlen($tq)) <= $maxDist) {
                    $dist = levenshtein($tp, $tq);
                    if ($dist <= $maxDist) {
                        $bestFuzzy = max($bestFuzzy, ($shorter - $dist) * 3);
                    }
                }
            }
        }
        $score += min(15, $bestFuzzy);
    }

    return ['score' => $score];
}

function parse_live_score_pair(string $scoreRaw): array
{
    $score = ['home' => null, 'away' => null];
    // Match "X-Y" or "X-Y (details)" — extract first pair of digits
    if ($scoreRaw !== '' && preg_match('/^(\d+)\s*[:-]\s*(\d+)/', $scoreRaw, $m)) {
        $score['home'] = (int)$m[1];
        $score['away'] = (int)$m[2];
    }
    return $score;
}

function is_suspicious_live_matchup(string $home, string $away): bool
{
    return is_suspicious_live_team_name($home) || is_suspicious_live_team_name($away);
}

function is_suspicious_live_team_name(string $name): bool
{
    $name = trim($name);
    if ($name === '') {
        return true;
    }

    if (preg_match('/\b(home|away)\s*cl\b/iu', $name)) {
        return true;
    }

    if (preg_match('/[\p{L}\p{N})\]]\s*\+\s*[\p{L}\p{N}(\[]/u', $name)) {
        return true;
    }

    if (preg_match('/\(\s*\d+\s*[:\-]\s*\d+\s*\)\s*$/u', $name)) {
        return true;
    }

    // Virtual / replays / eSports marker suffix, e.g. "(V)", "(replays)",
    // "(Stasyan)", "(Bob)", "(llulle)", "(Cira)" — these are NOT real live
    // matches and must not be paired with real Pinnacle events.
    if (preg_match('/\((?:V|replays|stasyan|bob|llulle|cira|virtual|esports)\)\s*$/iu', $name)) {
        return true;
    }

    return false;
}

function is_suspicious_live_league(string $league): bool
{
    if ($league === '') {
        return false;
    }
    return (bool)preg_match('/\b(virtual|replays|e[\s-]?football|e[\s-]?sports|esportsbattle|cyber|fifa\s*\d|vsl)\b/iu', $league);
}

function extract_live_feed_slug(string $link): string
{
    if ($link === '') {
        return '';
    }
    $path = (string)(parse_url($link, PHP_URL_PATH) ?? $link);
    $path = trim($path, '/');
    if ($path === '') {
        return '';
    }
    $parts = explode('/', $path);
    return (string)end($parts);
}

function tokenize_live_match_text(string $text): array
{
    $text = normalize_live_match_text($text);
    if ($text === '') {
        return [];
    }

    $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($parts)) {
        return [];
    }

    $stop = [
        'fc', 'cf', 'sc', 'fk', 'bk', 'sk', 'club', 'team', 'match', 'matches', 'live', 'time', 'half',
        'first', 'second', 'regular', 'women', 'man', 'the', 'de', 'la', 'el', 'of', 'and', 'at', 'to',
        'v', 'vs', 'esports', 'virtual', 'league', 'liga', 'cup'
    ];
    $result = [];
    foreach ($parts as $token) {
        if ($token === '' || in_array($token, $stop, true)) {
            continue;
        }
        if (strlen($token) < 2 && !preg_match('/^u\d+$/', $token)) {
            continue;
        }
        if ($token === 'utd') {
            $token = 'united';
        } elseif ($token === 'st') {
            $token = 'saint';
        }
        $result[$token] = true;
    }
    return array_keys($result);
}

function normalize_live_match_text(string $text): string
{
    $text = mb_strtolower($text, 'UTF-8');

    $phrases = [
        'англія' => 'england', 'англия' => 'england', 'італія' => 'italy', 'италия' => 'italy',
        'іспанія' => 'spain', 'испания' => 'spain', 'німеччина' => 'germany', 'германия' => 'germany',
        'франція' => 'france', 'франция' => 'france', 'шотландія' => 'scotland', 'шотландия' => 'scotland',
        'північна македонія' => 'north macedonia', 'северная македония' => 'north macedonia',
        'україна' => 'ukraine', 'украина' => 'ukraine', 'болгарія' => 'bulgaria', 'болгария' => 'bulgaria',
        'туреччина' => 'turkey', 'турция' => 'turkey', 'косово' => 'kosovo', 'єгипет' => 'egypt',
        'египет' => 'egypt', 'норвегія' => 'norway', 'норвегия' => 'norway', 'польща' => 'poland',
        'польша' => 'poland', 'румунія' => 'romania', 'румыния' => 'romania', 'сербія' => 'serbia',
        'сербия' => 'serbia', 'хорватія' => 'croatia', 'хорватия' => 'croatia', 'бразилія' => 'brazil',
        'бразилия' => 'brazil', 'аргентина' => 'argentina', 'португалія' => 'portugal', 'португалия' => 'portugal',
        'нідерланди' => 'netherlands', 'нидерланды' => 'netherlands', 'бельгія' => 'belgium', 'бельгия' => 'belgium',
        'швеція' => 'sweden', 'швеция' => 'sweden', 'данія' => 'denmark', 'дания' => 'denmark',
        'австрія' => 'austria', 'австрия' => 'austria', 'швейцарія' => 'switzerland', 'швейцария' => 'switzerland',
    ];
    $text = str_replace(array_keys($phrases), array_values($phrases), $text);

    $text = preg_replace('/\(([^)]*)\)/u', ' $1 ', $text);
    $text = str_replace(['-u-', 'u-', 'u '], [' u', 'u', 'u'], $text);
    $text = preg_replace('/\bunder\s*(\d{1,2})\b/i', ' u$1 ', $text);
    $text = preg_replace('/\bu\s*-?\s*(\d{1,2})\b/i', ' u$1 ', $text);
    $text = preg_replace('/\b(жен|жін|women|woman|ladies|female)\b/u', ' women ', $text);

    $cyr = [
        'а'=>'a','б'=>'b','в'=>'v','г'=>'g','ґ'=>'g','д'=>'d','е'=>'e','ё'=>'e','є'=>'e','ж'=>'zh','з'=>'z',
        'и'=>'i','і'=>'i','ї'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
        'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts','ч'=>'ch','ш'=>'sh','щ'=>'shch','ы'=>'y',
        'э'=>'e','ю'=>'yu','я'=>'ya','ь'=>'','ъ'=>''
    ];
    $text = strtr($text, $cyr);

    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($converted) && $converted !== '') {
            $text = $converted;
        }
    }

    $text = preg_replace('/[^a-z0-9]+/i', ' ', $text);

    // Team-name aliases — expand short forms so feeds that say "PSG" pair with
    // feeds that say "Paris Saint-Germain". Replacement happens BEFORE
    // tokenization so the resulting tokens are identical on both sides.
    // Only unambiguous mappings live here; "Atletico", "Inter", "Real" alone
    // are intentionally NOT aliased because several different clubs share them.
    [$aliasPatterns, $aliasReplacements] = live_team_alias_rules();
    $text = preg_replace($aliasPatterns, $aliasReplacements, $text);

    $text = preg_replace('/\s+/', ' ', trim((string)$text));
    return $text;
}

function live_team_alias_rules(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $map = [
        '/\bpsg\b/'                          => 'paris saint germain',
        '/\bparis\s+sg\b/'                   => 'paris saint germain',
        '/\bparis\s+saint\s*-?\s*germain\b/' => 'paris saint germain',
        '/\bman\s+city\b/'                   => 'manchester city',
        '/\bman\s+(?:utd|united|u)\b/'       => 'manchester united',
        '/\bbvb\b/'                          => 'borussia dortmund',
        '/\bbayern\s+m(?:unich|unchen)?\b/'  => 'bayern munich',
        '/\bspurs\b/'                        => 'tottenham hotspur',
        '/\bwolves\b/'                       => 'wolverhampton wanderers',
        '/\binternazionale\b/'               => 'inter milan',
        '/\binter\s+milano\b/'               => 'inter milan',
        '/\bnyc\s*fc\b/'                     => 'new york city',
        '/\blafc\b/'                         => 'los angeles fc',
    ];
    $cached = [array_keys($map), array_values($map)];
    return $cached;
}

function read_api_response_cache(string $sport, int $ttl): ?array
{
    $path = api_response_cache_path($sport);
    if (!is_file($path)) {
        return null;
    }

    $payload = json_decode((string)file_get_contents($path), true);
    if (!is_array($payload)) {
        return null;
    }

    $fetchedAt = (int)($payload['fetchedAt'] ?? 0);
    if ($fetchedAt <= 0 || (time() - $fetchedAt) >= $ttl) {
        return null;
    }

    return is_array($payload['response'] ?? null) ? $payload['response'] : null;
}

function write_api_response_cache(string $sport, array $response): void
{
    $path = api_response_cache_path($sport);
    @file_put_contents($path, json_encode([
        'fetchedAt' => time(),
        'response' => $response,
    ], JSON_UNESCAPED_UNICODE));
}

function api_response_cache_path(string $sport): string
{
    return __DIR__ . '/data/api-cache-' . preg_replace('/[^a-z0-9_-]+/i', '-', $sport) . '.json';
}

function fetch_events_for_requested_sport(string $targetSport): array
{
    $sportsData = read_json_file(SPORTS_CACHE_FILE);
    if ($sportsData === null) {
        return [
            'ok' => false,
            'status' => 500,
            'error' => 'sports.json not found or invalid. Fill sports.json first.',
            'quota' => [],
        ];
    }

    $sportKeys = [];
    foreach ($sportsData as $sportItem) {
        if (!is_array($sportItem)) {
            continue;
        }

        $sportKey = trim((string)($sportItem['key'] ?? ''));
        if ($sportKey === '') {
            continue;
        }

        if (!matches_requested_sport($sportKey, $targetSport)) {
            continue;
        }

        if (!empty($sportItem['has_outrights'])) {
            continue;
        }

        if (array_key_exists('active', $sportItem) && empty($sportItem['active'])) {
            continue;
        }

        $sportKeys[] = $sportKey;
    }

    $allEvents = [];
    $latestQuota = [];
    $requested = count($sportKeys);
    $fetched = 0;

    $commenceTimeFrom = gmdate('Y-m-d\T00:00:00\Z');
    $commenceTimeToFootball = gmdate('Y-m-d\T00:00:00\Z', strtotime('+1 day'));

    foreach ($sportKeys as $sportKey) {
        $isFootballRequest = ($targetSport === 'football') || str_starts_with($sportKey, 'soccer_');

        $query = [
            'apiKey' => ODDS_API_KEY,
            'regions' => TARGET_REGIONS,
            'markets' => TARGET_MARKETS,
            'dateFormat' => 'iso',
            'oddsFormat' => 'decimal',
            'commenceTimeFrom' => $commenceTimeFrom,
            'includeLinks' => 'true',
            'includeSids' => 'true',
            'includeBetLimits' => 'true',
            'includeRotationNumbers' => 'true',
        ];

        if ($isFootballRequest) {
            $query['commenceTimeTo'] = $commenceTimeToFootball;
        }

        $oddsResponse = api_get(sprintf(SPORT_ODDS_ENDPOINT_TEMPLATE, rawurlencode($sportKey)), $query);
        if (!$oddsResponse['ok']) {
            continue;
        }

        $fetched++;
        $latestQuota = $oddsResponse['quota'] ?? $latestQuota;

        foreach (($oddsResponse['data'] ?? []) as $event) {
            if (is_array($event)) {
                $allEvents[] = $event;
            }
        }
    }

    return [
        'ok' => true,
        'status' => 200,
        'events' => $allEvents,
        'quota' => $latestQuota,
        'sportKeysRequested' => $requested,
        'sportKeysFetched' => $fetched,
        'sportsTotal' => count($sportsData),
        'commenceTimeFrom' => $commenceTimeFrom,
        'commenceTimeToFootball' => $commenceTimeToFootball,
    ];
}

function read_json_file(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }

    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : null;
}

function filter_events_by_requested_sport(array $events, string $targetSport): array
{
    $filtered = [];
    $isFootballMode = $targetSport === 'football' || str_starts_with($targetSport, 'soccer_');
    $now = time();

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $sportKey = (string)($event['sport_key'] ?? '');
        if (!matches_requested_sport($sportKey, $targetSport)) {
            continue;
        }

        if ($isFootballMode) {
            $commenceTs = strtotime((string)($event['commence_time'] ?? '')) ?: 0;
            if ($commenceTs <= 0 || $commenceTs > $now) {
                continue;
            }
        }

        $filtered[] = $event;
    }

    return $filtered;
}

function normalize_events(array $events): array
{
    $rows = [];
    $now = time();

    foreach ($events as $event) {
        if (!is_array($event)) {
            continue;
        }

        $sportKey = (string)($event['sport_key'] ?? '');
        $baseSport = resolve_base_sport($sportKey);
        if ($baseSport === null) {
            continue;
        }

        $home = trim((string)($event['home_team'] ?? ''));
        $away = trim((string)($event['away_team'] ?? ''));
        if ($home === '' || $away === '') {
            continue;
        }

        $commence = (string)($event['commence_time'] ?? '');
        $commenceTs = strtotime($commence) ?: 0;
        $isLive = $commenceTs > 0 && $commenceTs <= $now;

        $bookmakers = is_array($event['bookmakers'] ?? null) ? $event['bookmakers'] : [];
        if (!$bookmakers) {
            continue;
        }

        $primaryBookmaker = null;
        $primaryData = null;
        $pinnacleLastUpdate = null;
        $apiLastUpdateTs = 0;

        foreach ($bookmakers as $bookmaker) {
            $bookmakerTs = strtotime((string)($bookmaker['last_update'] ?? '')) ?: 0;
            if ($bookmakerTs > $apiLastUpdateTs) {
                $apiLastUpdateTs = $bookmakerTs;
            }

            $markets = is_array($bookmaker['markets'] ?? null) ? $bookmaker['markets'] : [];
            foreach ($markets as $market) {
                $marketTs = strtotime((string)($market['last_update'] ?? '')) ?: 0;
                if ($marketTs > $apiLastUpdateTs) {
                    $apiLastUpdateTs = $marketTs;
                }
            }
        }

        foreach ($bookmakers as $bookmaker) {
            if (($bookmaker['key'] ?? '') !== 'pinnacle') {
                continue;
            }

            $market = pick_h2h_market($bookmaker);
            if (!$market) {
                continue;
            }

            $odds = outcome_map($market['outcomes'] ?? []);
            $resolved = market_values($odds, $home, $away, $baseSport);
            if (!$resolved) {
                continue;
            }

            $primaryBookmaker = $bookmaker;
            $primaryData = $resolved;
            $pinnacleLastUpdate = (string)($market['last_update'] ?? ($bookmaker['last_update'] ?? ''));
            break;
        }

        if (!$primaryBookmaker || !$primaryData) {
            continue;
        }

        $validSecondsTotal = 0;

        foreach ($bookmakers as $bookmaker) {
            $key = (string)($bookmaker['key'] ?? '');
            if ($key === '' || $key === 'pinnacle') {
                continue;
            }

            $market = pick_h2h_market($bookmaker);
            if (!$market) {
                continue;
            }

            $odds = outcome_map($market['outcomes'] ?? []);
            $second = market_values($odds, $home, $away, $baseSport);
            if (!$second) {
                continue;
            }

            $validSecondsTotal++;
            $rows[] = [
                'id' => (string)($event['id'] ?? ''),
                'sport_key' => $sportKey,
                'sport_base' => $baseSport,
                'sport' => $baseSport,
                'phase' => $isLive ? 'live' : 'prematch',
                'league' => (string)($event['sport_title'] ?? $sportKey),
                'home' => $home,
                'away' => $away,
                'time' => $commence,
                'isLive' => $isLive,
                'stats' => [
                    'bookmakersTotal' => count($bookmakers),
                    'validSecondsTotal' => 0,
                    'pinnacleLastUpdate' => $pinnacleLastUpdate,
                    'apiLastUpdate' => $apiLastUpdateTs > 0 ? gmdate('c', $apiLastUpdateTs) : '',
                ],
                'pinnacle' => [
                    'key' => 'pinnacle',
                    'title' => (string)($primaryBookmaker['title'] ?? 'Pinnacle'),
                    'link' => extract_bookmaker_link($primaryBookmaker),
                    'p1' => $primaryData['p1'],
                    'x' => $primaryData['x'],
                    'p2' => $primaryData['p2'],
                ],
                'second' => [
                    'key' => $key,
                    'title' => (string)($bookmaker['title'] ?? $key),
                    'link' => extract_bookmaker_link($bookmaker),
                    'p1' => $second['p1'],
                    'x' => $second['x'],
                    'p2' => $second['p2'],
                ],
            ];
        }

        if ($validSecondsTotal > 0) {
            for ($i = count($rows) - 1; $i >= 0; $i--) {
                if (($rows[$i]['id'] ?? '') !== (string)($event['id'] ?? '')) {
                    break;
                }
                $rows[$i]['stats']['validSecondsTotal'] = $validSecondsTotal;
            }
        }
    }

    return $rows;
}

function group_events(array $rows): array
{
    $grouped = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $eventId = trim((string)($row['id'] ?? ''));
        if ($eventId === '') {
            $eventId = md5(implode('|', [
                (string)($row['sport_key'] ?? ''),
                (string)($row['home'] ?? ''),
                (string)($row['away'] ?? ''),
                (string)($row['time'] ?? ''),
            ]));
        }

        if (!isset($grouped[$eventId])) {
            $grouped[$eventId] = [
                'id' => $eventId,
                'sport' => (string)($row['sport'] ?? 'football'),
                'league' => (string)($row['league'] ?? ''),
                'home' => (string)($row['home'] ?? ''),
                'away' => (string)($row['away'] ?? ''),
                'time' => (string)($row['time'] ?? ''),
                'isLive' => !empty($row['isLive']),
                'stats' => [
                    'bookmakersTotal' => (int)($row['stats']['bookmakersTotal'] ?? 0),
                    'validSecondsTotal' => (int)($row['stats']['validSecondsTotal'] ?? 0),
                    'pinnacleLastUpdate' => (string)($row['stats']['pinnacleLastUpdate'] ?? ''),
                    'apiLastUpdate' => (string)($row['stats']['apiLastUpdate'] ?? ''),
                ],
                'pinnacle' => [
                    'key' => (string)($row['pinnacle']['key'] ?? 'pinnacle'),
                    'title' => (string)($row['pinnacle']['title'] ?? 'Pinnacle'),
                    'link' => (string)($row['pinnacle']['link'] ?? ''),
                    'p1' => $row['pinnacle']['p1'] ?? null,
                    'x' => $row['pinnacle']['x'] ?? null,
                    'p2' => $row['pinnacle']['p2'] ?? null,
                ],
                'hasPinnacle' => (string)($row['pinnacle']['key'] ?? '') === 'pinnacle'
                    && to_float($row['pinnacle']['p1'] ?? null) !== null
                    && to_float($row['pinnacle']['p2'] ?? null) !== null,
                'seconds' => [],
                'bestSecondKey' => null,
                'bestMinFormula' => null,
                'defaultSecondKey' => null,
            ];
        }

        $secondKey = (string)($row['second']['key'] ?? '');
        if ($secondKey === '') {
            continue;
        }

        $grouped[$eventId]['seconds'][$secondKey] = [
            'key' => $secondKey,
            'title' => (string)($row['second']['title'] ?? $secondKey),
            'link' => (string)($row['second']['link'] ?? ''),
            'p1' => $row['second']['p1'] ?? null,
            'x' => $row['second']['x'] ?? null,
            'p2' => $row['second']['p2'] ?? null,
        ];
    }

    foreach ($grouped as &$event) {
        $bestKey = null;
        $bestValue = null;

        foreach ($event['seconds'] as $secondKey => $second) {
            $formula = min_formula_for_pair($event['sport'], $second, $event['pinnacle']);
            if ($formula === null) {
                continue;
            }

            if ($bestValue === null || $formula < $bestValue) {
                $bestValue = $formula;
                $bestKey = $secondKey;
            }
        }

        $defaultSecondKey = isset($event['seconds'][PREFERRED_SECOND_KEY])
            ? PREFERRED_SECOND_KEY
            : ($bestKey ?: array_key_first($event['seconds']));

        $event['bestSecondKey'] = $bestKey;
        $event['bestMinFormula'] = $bestValue;
        $event['defaultSecondKey'] = $defaultSecondKey;
        ksort($event['seconds']);
    }
    unset($event);

    $grouped = array_values(array_filter($grouped, static function (array $event): bool {
        return !empty($event['seconds']) && !empty($event['hasPinnacle']);
    }));

    usort($grouped, static function (array $a, array $b): int {
        $va = $a['bestMinFormula'];
        $vb = $b['bestMinFormula'];
        if ($va === null && $vb === null) return 0;
        if ($va === null) return 1;
        if ($vb === null) return -1;
        return $va <=> $vb;
    });

    return $grouped;
}

function detect_default_second_bookmaker(array $events): array
{
    if (!$events) {
        return [
            'key' => PREFERRED_SECOND_KEY,
            'title' => 'Betfair',
            'mode' => 'preferred',
        ];
    }

    $intersection = null;
    $titles = [];
    $coverage = [];

    foreach ($events as $event) {
        $seconds = is_array($event['seconds'] ?? null) ? $event['seconds'] : [];
        $keys = array_keys($seconds);

        if ($intersection === null) {
            $intersection = $keys;
        } else {
            $intersection = array_values(array_intersect($intersection, $keys));
        }

        foreach ($seconds as $key => $second) {
            $coverage[$key] = ($coverage[$key] ?? 0) + 1;
            if (!isset($titles[$key])) {
                $titles[$key] = (string)($second['title'] ?? $key);
            }
        }
    }

    $intersection = array_values(array_unique($intersection ?? []));
    if ($intersection) {
        $selectedKey = in_array(PREFERRED_SECOND_KEY, $intersection, true)
            ? PREFERRED_SECOND_KEY
            : pick_first_sorted_bookmaker($intersection, $titles);

        return [
            'key' => $selectedKey,
            'title' => $titles[$selectedKey] ?? $selectedKey,
            'mode' => 'common',
        ];
    }

    if (!$coverage) {
        return [
            'key' => null,
            'title' => null,
            'mode' => 'none',
        ];
    }

    arsort($coverage);
    $topCoverage = reset($coverage);
    $topKeys = array_keys(array_filter($coverage, static fn(int $count): bool => $count === $topCoverage));
    $selectedKey = in_array(PREFERRED_SECOND_KEY, $topKeys, true)
        ? PREFERRED_SECOND_KEY
        : pick_first_sorted_bookmaker($topKeys, $titles);

    return [
        'key' => $selectedKey,
        'title' => $titles[$selectedKey] ?? $selectedKey,
        'mode' => 'fallback',
    ];
}

function pick_first_sorted_bookmaker(array $keys, array $titles): ?string
{
    if (!$keys) {
        return null;
    }

    usort($keys, static function (string $a, string $b) use ($titles): int {
        return (($titles[$a] ?? $a) <=> ($titles[$b] ?? $b));
    });

    return $keys[0] ?? null;
}

function min_formula_for_pair(string $sport, array $second, array $pinnacle): ?float
{
    $a1 = to_float($second['p1'] ?? null);
    $ax = to_float($second['x'] ?? null);
    $a2 = to_float($second['p2'] ?? null);
    $b1 = to_float($pinnacle['p1'] ?? null);
    $bx = to_float($pinnacle['x'] ?? null);
    $b2 = to_float($pinnacle['p2'] ?? null);

    $values = [];
    $hasDraw = $ax && $bx;

    if (!$hasDraw) {
        if ($a1 && $b2) $values[] = 1 / $a1 + 1 / $b2;
        if ($a2 && $b1) $values[] = 1 / $a2 + 1 / $b1;
        return $values ? min($values) : null;
    }

    if ($a1 && $ax && $b2) $values[] = 1 / $a1 + 1 / $ax + 1 / $b2;
    if ($ax && $a2 && $b1) $values[] = 1 / $ax + 1 / $a2 + 1 / $b1;
    if ($a1 && $a2 && $bx) $values[] = 1 / $a1 + 1 / $a2 + 1 / $bx;
    if ($b1 && $bx && $a2) $values[] = 1 / $b1 + 1 / $bx + 1 / $a2;
    if ($bx && $b2 && $a1) $values[] = 1 / $bx + 1 / $b2 + 1 / $a1;
    if ($b1 && $b2 && $ax) $values[] = 1 / $b1 + 1 / $b2 + 1 / $ax;

    return $values ? min($values) : null;
}

function map_sport(string $sportKey): ?string
{
    if (str_starts_with($sportKey, 'soccer_')) return 'football';
    if (str_starts_with($sportKey, 'basketball_')) return 'basketball';
    if (str_starts_with($sportKey, 'tennis_')) return 'tennis';
    if (str_starts_with($sportKey, 'icehockey_')) return 'icehockey';
    return null;
}

function resolve_base_sport(string $sportKey): ?string
{
    $mapped = map_sport($sportKey);
    if ($mapped !== null) {
        return $mapped;
    }

    $sportKey = trim($sportKey);
    if ($sportKey === '') {
        return null;
    }

    $parts = explode('_', $sportKey, 2);
    return $parts[0] !== '' ? $parts[0] : null;
}

function matches_requested_sport(string $sportKey, string $requestedSport): bool
{
    if ($requestedSport === '' || $requestedSport === 'all') {
        return true;
    }

    if ($requestedSport === $sportKey) {
        return true;
    }

    $base = resolve_base_sport($sportKey);
    return $base !== null && $base === $requestedSport;
}

function sport_has_draw(string $sport): bool
{
    return in_array($sport, ['football'], true);
}

function pick_h2h_market(array $bookmaker): ?array
{
    $markets = is_array($bookmaker['markets'] ?? null) ? $bookmaker['markets'] : [];
    foreach ($markets as $market) {
        if (($market['key'] ?? '') === 'h2h') {
            return $market;
        }
    }
    return null;
}

function outcome_map(array $outcomes): array
{
    $map = [];
    foreach ($outcomes as $item) {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') continue;

        $price = $item['price'] ?? null;
        if (!is_numeric($price)) continue;

        $map[mb_strtolower($name)] = (float)$price;
    }
    return $map;
}

function market_values(array $map, string $home, string $away, string $sport): ?array
{
    $homeKey = mb_strtolower($home);
    $awayKey = mb_strtolower($away);

    $p1 = $map[$homeKey] ?? null;
    $p2 = $map[$awayKey] ?? null;

    if (!is_numeric($p1) || !is_numeric($p2)) {
        return null;
    }

    $p1 = (float)$p1;
    $p2 = (float)$p2;

    if ($p1 < MIN_VALID_ODD || $p1 > MAX_VALID_ODD || $p2 < MIN_VALID_ODD || $p2 > MAX_VALID_ODD) {
        return null;
    }

    $draw = null;
    foreach (['draw', 'tie', 'x'] as $drawKey) {
        if (isset($map[$drawKey]) && is_numeric($map[$drawKey]) && $map[$drawKey] > 0) {
            $draw = (float)$map[$drawKey];
            break;
        }
    }

    if (sport_has_draw($sport) && $draw === null) {
        return null;
    }

    if ($draw !== null && ($draw < MIN_VALID_ODD || $draw > MAX_VALID_ODD)) {
        return null;
    }

    return [
        'p1' => $p1,
        'x' => $draw,
        'p2' => $p2,
    ];
}

function to_float($value): ?float
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }

    $number = (float)$value;
    return $number > 0 ? $number : null;
}

function extract_bookmaker_link(array $bookmaker): string
{
    if (is_string($bookmaker['link'] ?? null)) {
        return (string)$bookmaker['link'];
    }

    if (is_array($bookmaker['links'] ?? null)) {
        foreach ($bookmaker['links'] as $link) {
            if (is_string($link) && str_starts_with($link, 'http')) {
                return $link;
            }
            if (is_array($link) && is_string($link['url'] ?? null)) {
                return (string)$link['url'];
            }
        }
    }

    return '';
}

function api_get(string $endpoint, array $query): array
{
    $url = ODDS_API_HOST . $endpoint . '?' . http_build_query($query);
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = extract_http_status($headers);
    $quota = extract_quota_headers($headers);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'network error',
            'data' => null,
            'quota' => $quota,
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => trim(substr($raw, 0, 240)),
            'data' => null,
            'quota' => $quota,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'invalid json',
            'data' => null,
            'quota' => $quota,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'error' => null,
        'data' => $decoded,
        'quota' => $quota,
    ];
}

function extract_http_status(array $headers): int
{
    foreach ($headers as $headerLine) {
        if (preg_match('/^HTTP\/\d(?:\.\d)?\s+(\d+)/i', $headerLine, $matches)) {
            return (int)$matches[1];
        }
    }
    return 0;
}

function extract_quota_headers(array $headers): array
{
    $result = [
        'x-requests-remaining' => null,
        'x-requests-used' => null,
        'x-requests-last' => null,
    ];

    foreach ($headers as $line) {
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }

        $name = strtolower(trim(substr($line, 0, $pos)));
        $value = trim(substr($line, $pos + 1));

        if (array_key_exists($name, $result)) {
            $result[$name] = $value;
        }
    }

    return $result;
}

function fetch_api_sports_live_events(string $sport): array
{
    $config = API_SPORTS_CONFIG[$sport] ?? null;
    if (!is_array($config)) {
        return [
            'ok' => false,
            'status' => 400,
            'error' => 'Unsupported sport',
            'quota' => [],
        ];
    }

    $fixturesResp = null;
    foreach (build_api_sports_fixture_queries($config) as $query) {
        $candidateResp = api_sports_get(
            (string)$config['baseUrl'],
            (string)$config['fixturesEndpoint'],
            $query
        );

        if ($candidateResp['ok']) {
            $fixturesResp = $candidateResp;
            break;
        }

        if ($fixturesResp === null) {
            $fixturesResp = $candidateResp;
        }
    }

    if (!is_array($fixturesResp) || !$fixturesResp['ok']) {
        if ($sport === 'tennis') {
            return [
                'ok' => true,
                'status' => 200,
                'events' => [],
                'quota' => is_array($fixturesResp['quota'] ?? null) ? $fixturesResp['quota'] : [],
                'fixturesFetched' => 0,
                'oddsFetched' => 0,
            ];
        }

        return [
            'ok' => false,
            'status' => (int)($fixturesResp['status'] ?? 0),
            'error' => (string)($fixturesResp['error'] ?? 'Failed to fetch live fixtures'),
            'quota' => $fixturesResp['quota'] ?? [],
        ];
    }

    $fixturesRaw = is_array($fixturesResp['data']['response'] ?? null)
        ? $fixturesResp['data']['response']
        : [];

    if ($sport !== 'football') {
        $fixturesRaw = array_values(array_filter($fixturesRaw, static function (array $fixture) use ($sport): bool {
            return api_sports_fixture_is_live($fixture, $sport);
        }));
    }

    $fixturesRaw = array_slice($fixturesRaw, 0, API_MAX_FIXTURES);

    $events = [];
    $oddsFetched = 0;

    foreach ($fixturesRaw as $fixture) {
        if (!is_array($fixture)) {
            continue;
        }

        $eventId = extract_api_sports_event_id($fixture, (array)($config['idPath'] ?? []));
        if ($eventId === null) {
            continue;
        }

        $home = trim((string)(value_by_path($fixture, ['teams', 'home', 'name']) ?? ''));
        $away = trim((string)(value_by_path($fixture, ['teams', 'away', 'name']) ?? ''));
        if ($home === '' || $away === '') {
            continue;
        }

        $league = trim((string)(value_by_path($fixture, ['league', 'name']) ?? ''));
        $time = (string)(value_by_path($fixture, ['fixture', 'date'])
            ?? value_by_path($fixture, ['date'])
            ?? value_by_path($fixture, ['time'])
            ?? '');

        $oddsResp = fetch_api_sports_odds_for_event($config, $eventId);
        if (!$oddsResp['ok']) {
            continue;
        }

        $oddsFetched++;
        $bookmakers = extract_api_sports_bookmakers($oddsResp['data'] ?? [], $home, $away, $sport);
        if (count($bookmakers) === 0) {
            continue;
        }

        $primaryIndex = 0;
        foreach ($bookmakers as $idx => $bookmaker) {
            if (str_contains(mb_strtolower((string)$bookmaker['title']), 'pinnacle')) {
                $primaryIndex = $idx;
                break;
            }
        }

        $primary = $bookmakers[$primaryIndex];
        $seconds = [];
        $usedKeys = [];

        foreach ($bookmakers as $idx => $bookmaker) {
            if ($idx === $primaryIndex) {
                continue;
            }

            $key = (string)$bookmaker['key'];
            if ($key === '' || isset($usedKeys[$key])) {
                $suffix = 2;
                $baseKey = $key !== '' ? $key : 'bk';
                while (isset($usedKeys[$baseKey . '_' . $suffix])) {
                    $suffix++;
                }
                $key = $baseKey . '_' . $suffix;
            }

            $usedKeys[$key] = true;
            $seconds[$key] = [
                'key' => $key,
                'title' => (string)$bookmaker['title'],
                'link' => (string)$bookmaker['link'],
                'p1' => $bookmaker['p1'],
                'x' => $bookmaker['x'],
                'p2' => $bookmaker['p2'],
            ];
        }

        $bestKey = null;
        $bestValue = null;
        foreach ($seconds as $key => $second) {
            $formula = min_formula_for_pair($sport, $second, $primary);
            if ($formula === null) {
                continue;
            }
            if ($bestValue === null || $formula < $bestValue) {
                $bestValue = $formula;
                $bestKey = $key;
            }
        }

        $defaultSecondKey = isset($seconds[PREFERRED_SECOND_KEY])
            ? PREFERRED_SECOND_KEY
            : ($bestKey ?: array_key_first($seconds));

        $events[] = [
            'id' => (string)$eventId,
            'sport' => $sport,
            'league' => $league !== '' ? $league : mb_strtoupper($sport),
            'home' => $home,
            'away' => $away,
            'time' => $time,
            'isLive' => api_sports_fixture_is_live($fixture, $sport),
            'stats' => [
                'bookmakersTotal' => count($bookmakers),
                'validSecondsTotal' => count($seconds),
                'pinnacleLastUpdate' => '',
                'apiLastUpdate' => gmdate('c'),
            ],
            'pinnacle' => [
                'key' => 'pinnacle',
                'title' => (string)$primary['title'],
                'link' => (string)$primary['link'],
                'p1' => $primary['p1'],
                'x' => $primary['x'],
                'p2' => $primary['p2'],
            ],
            'hasPinnacle' => true,
            'seconds' => $seconds,
            'bestSecondKey' => $bestKey,
            'bestMinFormula' => $bestValue,
            'defaultSecondKey' => $defaultSecondKey,
        ];
    }

    return [
        'ok' => true,
        'status' => 200,
        'events' => $events,
        'quota' => $fixturesResp['quota'] ?? [],
        'fixturesFetched' => count($fixturesRaw),
        'oddsFetched' => $oddsFetched,
    ];
}

function fetch_api_sports_live_football_events(): array
{
    $config = API_SPORTS_CONFIG['football'];
    $fixturesResp = get_cached_football_fixtures_for_today();
    if (!$fixturesResp['ok']) {
        return $fixturesResp;
    }

    $fixturesRaw = is_array($fixturesResp['data']['response'] ?? null)
        ? $fixturesResp['data']['response']
        : [];

    $fixturesById = [];
    foreach ($fixturesRaw as $fixture) {
        if (!is_array($fixture)) {
            continue;
        }

        $fixtureId = extract_api_sports_event_id($fixture, (array)($config['idPath'] ?? []));
        if ($fixtureId === null) {
            continue;
        }

        $fixturesById[(string)$fixtureId] = $fixture;
    }

    $liveOddsResp = api_sports_get((string)$config['baseUrl'], '/odds/live', []);
    if (!$liveOddsResp['ok']) {
        return [
            'ok' => false,
            'status' => (int)($liveOddsResp['status'] ?? 0),
            'error' => (string)($liveOddsResp['error'] ?? 'Failed to fetch football live odds'),
            'quota' => $liveOddsResp['quota'] ?? [],
        ];
    }

    $liveOddsRows = is_array($liveOddsResp['data']['response'] ?? null)
        ? $liveOddsResp['data']['response']
        : [];

    $events = [];

    foreach ($liveOddsRows as $row) {
        if (!is_array($row)) {
            continue;
        }

        $fixtureId = (string)(value_by_path($row, ['fixture', 'id']) ?? '');
        if ($fixtureId === '' || !isset($fixturesById[$fixtureId])) {
            continue;
        }

        $fixture = $fixturesById[$fixtureId];
        $home = trim((string)(value_by_path($fixture, ['teams', 'home', 'name']) ?? ''));
        $away = trim((string)(value_by_path($fixture, ['teams', 'away', 'name']) ?? ''));
        if ($home === '' || $away === '') {
            continue;
        }

        $mainMarket = extract_api_sports_live_market_values($row, $home, $away, 'football');
        if ($mainMarket === null) {
            continue;
        }

        $scoreHome = value_by_path($row, ['teams', 'home', 'goals']);
        $scoreAway = value_by_path($row, ['teams', 'away', 'goals']);
        if (!is_numeric($scoreHome)) {
            $scoreHome = value_by_path($fixture, ['goals', 'home']);
        }
        if (!is_numeric($scoreAway)) {
            $scoreAway = value_by_path($fixture, ['goals', 'away']);
        }

        $events[] = [
            'id' => $fixtureId,
            'sport' => 'football',
            'marketMode' => 'live-odds',
            'marketTitle' => $mainMarket['label'],
            'league' => (string)(value_by_path($fixture, ['league', 'name']) ?? 'Football'),
            'leagueLogo' => (string)(value_by_path($fixture, ['league', 'logo']) ?? ''),
            'home' => $home,
            'away' => $away,
            'homeLogo' => (string)(value_by_path($fixture, ['teams', 'home', 'logo']) ?? ''),
            'awayLogo' => (string)(value_by_path($fixture, ['teams', 'away', 'logo']) ?? ''),
            'time' => (string)(value_by_path($fixture, ['fixture', 'date']) ?? ''),
            'isLive' => true,
            'statusShort' => (string)(value_by_path($row, ['fixture', 'status', 'short']) ?? value_by_path($fixture, ['fixture', 'status', 'short']) ?? 'LIVE'),
            'statusLong' => (string)(value_by_path($row, ['fixture', 'status', 'long']) ?? value_by_path($fixture, ['fixture', 'status', 'long']) ?? 'Live'),
            'elapsed' => value_by_path($row, ['fixture', 'status', 'elapsed']) ?? value_by_path($fixture, ['fixture', 'status', 'elapsed']),
            'liveSeconds' => (string)(value_by_path($row, ['fixture', 'status', 'seconds']) ?? ''),
            'score' => [
                'home' => is_numeric($scoreHome) ? (int)$scoreHome : null,
                'away' => is_numeric($scoreAway) ? (int)$scoreAway : null,
            ],
            'stats' => [
                'bookmakersTotal' => 1,
                'validSecondsTotal' => 0,
                'pinnacleLastUpdate' => (string)($row['update'] ?? ''),
                'apiLastUpdate' => gmdate('c'),
                'marketsTotal' => count(is_array($row['odds'] ?? null) ? $row['odds'] : []),
                'referee' => (string)(value_by_path($fixture, ['fixture', 'referee']) ?? ''),
                'venueName' => (string)(value_by_path($fixture, ['fixture', 'venue', 'name']) ?? ''),
                'venueCity' => (string)(value_by_path($fixture, ['fixture', 'venue', 'city']) ?? ''),
            ],
            'pinnacle' => [
                'key' => 'live_odds',
                'title' => 'Live Odds',
                'link' => '',
                'p1' => $mainMarket['p1'],
                'x' => $mainMarket['x'],
                'p2' => $mainMarket['p2'],
            ],
            'hasPinnacle' => true,
            'seconds' => [],
            'bestSecondKey' => null,
            'bestMinFormula' => null,
            'defaultSecondKey' => null,
        ];
    }

    usort($events, static function (array $a, array $b): int {
        $elapsedA = (int)($a['elapsed'] ?? 0);
        $elapsedB = (int)($b['elapsed'] ?? 0);
        if ($elapsedA !== $elapsedB) {
            return $elapsedB <=> $elapsedA;
        }

        return strcmp((string)($a['league'] ?? ''), (string)($b['league'] ?? ''));
    });

    return [
        'ok' => true,
        'status' => 200,
        'events' => $events,
        'quota' => $liveOddsResp['quota'] ?? ($fixturesResp['quota'] ?? []),
        'fixturesFetched' => count($fixturesById),
        'oddsFetched' => count($liveOddsRows),
    ];
}

function get_cached_football_fixtures_for_today(): array
{
    $cachePath = __DIR__ . '/data/football-fixtures-' . gmdate('Y-m-d') . '.json';
    if (is_file($cachePath)) {
        $payload = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($payload) && (time() - (int)($payload['fetchedAt'] ?? 0)) < FOOTBALL_FIXTURES_CACHE_SECONDS && is_array($payload['response'] ?? null)) {
            return [
                'ok' => true,
                'status' => 200,
                'data' => ['response' => $payload['response']],
                'quota' => is_array($payload['quota'] ?? null) ? $payload['quota'] : [],
            ];
        }
    }

    $resp = api_sports_get('https://v3.football.api-sports.io', '/fixtures', ['date' => gmdate('Y-m-d')]);
    if ($resp['ok']) {
        @file_put_contents($cachePath, json_encode([
            'fetchedAt' => time(),
            'quota' => $resp['quota'] ?? [],
            'response' => $resp['data']['response'] ?? [],
        ], JSON_UNESCAPED_UNICODE));
    }

    return $resp;
}

function fetch_api_sports_odds_for_event(array $config, string $eventId): array
{
    $endpoints = is_array($config['oddsEndpoints'] ?? null) ? $config['oddsEndpoints'] : ['/odds'];
    $param = (string)($config['oddsParam'] ?? 'fixture');

    foreach ($endpoints as $endpoint) {
        $resp = api_sports_get((string)$config['baseUrl'], (string)$endpoint, [$param => $eventId]);
        if ($resp['ok']) {
            return $resp;
        }
    }

    return [
        'ok' => false,
        'status' => 404,
        'error' => 'No odds found for event',
        'data' => null,
        'quota' => [],
    ];
}

function extract_api_sports_bookmakers(array $oddsData, string $home, string $away, string $sport): array
{
    $response = is_array($oddsData['response'] ?? null) ? $oddsData['response'] : [];
    $result = [];
    $used = [];

    foreach ($response as $row) {
        if (!is_array($row)) {
            continue;
        }

        $bookmakers = is_array($row['bookmakers'] ?? null) ? $row['bookmakers'] : [$row];
        foreach ($bookmakers as $bookmaker) {
            if (!is_array($bookmaker)) {
                continue;
            }

            $title = trim((string)($bookmaker['name'] ?? $bookmaker['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $values = extract_api_sports_h2h_values($bookmaker, $home, $away, $sport);
            if ($values === null) {
                continue;
            }

            $key = slugify_bookmaker($title);
            if (isset($used[$key])) {
                continue;
            }

            $used[$key] = true;
            $result[] = [
                'key' => $key,
                'title' => $title,
                'link' => '',
                'p1' => $values['p1'],
                'x' => $values['x'],
                'p2' => $values['p2'],
            ];
        }
    }

    return $result;
}

function extract_api_sports_h2h_values(array $bookmaker, string $home, string $away, string $sport): ?array
{
    $bets = is_array($bookmaker['bets'] ?? null) ? $bookmaker['bets'] : [];
    $targetBet = null;

    foreach ($bets as $bet) {
        if (!is_array($bet)) {
            continue;
        }
        $name = mb_strtolower((string)($bet['name'] ?? ''));
        $id = (string)($bet['id'] ?? '');
        if (
            str_contains($name, 'winner') ||
            str_contains($name, 'fulltime') ||
            str_contains($name, 'match result') ||
            in_array($id, ['1', '59'], true)
        ) {
            $targetBet = $bet;
            break;
        }
    }

    if (!is_array($targetBet)) {
        $targetBet = ['values' => $bookmaker['values'] ?? []];
    }

    $values = is_array($targetBet['values'] ?? null) ? $targetBet['values'] : [];
    if (!$values) {
        return null;
    }

    return extract_api_sports_1x2_values_from_list($values, $home, $away, $sport);
}

function extract_api_sports_live_market_values(array $row, string $home, string $away, string $sport): ?array
{
    $markets = is_array($row['odds'] ?? null) ? $row['odds'] : [];
    foreach ($markets as $market) {
        if (!is_array($market)) {
            continue;
        }

        $name = mb_strtolower((string)($market['name'] ?? ''));
        $id = (string)($market['id'] ?? '');
        if (
            str_contains($name, 'fulltime result') ||
            str_contains($name, 'match result') ||
            str_contains($name, '1x2') ||
            in_array($id, ['59', '1'], true)
        ) {
            $values = extract_api_sports_1x2_values_from_list(is_array($market['values'] ?? null) ? $market['values'] : [], $home, $away, $sport);
            if ($values !== null) {
                $values['label'] = (string)($market['name'] ?? 'Fulltime Result');
                return $values;
            }
        }
    }

    return null;
}

function extract_api_sports_1x2_values_from_list(array $values, string $home, string $away, string $sport): ?array
{
    if (!$values) {
        return null;
    }

    $homeNorm = mb_strtolower(trim($home));
    $awayNorm = mb_strtolower(trim($away));
    $p1 = null;
    $p2 = null;
    $draw = null;

    foreach ($values as $value) {
        if (!is_array($value)) {
            continue;
        }

        $label = mb_strtolower(trim((string)($value['value'] ?? $value['name'] ?? '')));
        $oddRaw = $value['odd'] ?? $value['price'] ?? null;
        if (!is_numeric($oddRaw)) {
            continue;
        }
        $odd = (float)$oddRaw;

        if ($label === '1' || $label === 'home' || $label === $homeNorm) {
            $p1 = $odd;
            continue;
        }
        if ($label === '2' || $label === 'away' || $label === $awayNorm) {
            $p2 = $odd;
            continue;
        }
        if ($label === 'x' || $label === 'draw' || $label === 'tie') {
            $draw = $odd;
        }
    }

    if (!is_numeric($p1) || !is_numeric($p2)) {
        return null;
    }
    if ($p1 < MIN_VALID_ODD || $p1 > MAX_VALID_ODD || $p2 < MIN_VALID_ODD || $p2 > MAX_VALID_ODD) {
        return null;
    }
    if (sport_has_draw($sport) && !is_numeric($draw)) {
        return null;
    }
    if (is_numeric($draw) && ($draw < MIN_VALID_ODD || $draw > MAX_VALID_ODD)) {
        $draw = null;
    }

    return [
        'p1' => (float)$p1,
        'x' => is_numeric($draw) ? (float)$draw : null,
        'p2' => (float)$p2,
    ];
}

function extract_api_sports_event_id(array $fixture, array $path): ?string
{
    if ($path) {
        $value = value_by_path($fixture, $path);
        if ($value !== null && $value !== '') {
            return (string)$value;
        }
    }

    foreach (['id', ['fixture', 'id'], ['game', 'id']] as $candidate) {
        $value = is_array($candidate) ? value_by_path($fixture, $candidate) : ($fixture[$candidate] ?? null);
        if ($value !== null && $value !== '') {
            return (string)$value;
        }
    }

    return null;
}

function build_api_sports_fixture_queries(array $config): array
{
    $queries = is_array($config['fixturesQueries'] ?? null) ? $config['fixturesQueries'] : [['live' => 'all']];
    $today = gmdate('Y-m-d');

    foreach ($queries as &$query) {
        if (!is_array($query)) {
            $query = [];
            continue;
        }

        foreach ($query as $key => $value) {
            if ($value === '__TODAY__') {
                $query[$key] = $today;
            }
        }
    }
    unset($query);

    return $queries;
}

function api_sports_fixture_is_live(array $fixture, string $sport): bool
{
    $statusShort = mb_strtolower(trim((string)(value_by_path($fixture, ['fixture', 'status', 'short'])
        ?? value_by_path($fixture, ['status', 'short'])
        ?? '')));

    if ($statusShort === '') {
        return false;
    }

    if ($sport === 'football') {
        return !in_array($statusShort, ['ns', 'tbd', 'pst', 'canc', 'abd', 'awd', 'wo', 'ft', 'aet', 'pen'], true);
    }

    if ($sport === 'basketball') {
        return !in_array($statusShort, ['ns', 'ft', 'aot', 'post', 'canc'], true);
    }

    if ($sport === 'icehockey') {
        return !in_array($statusShort, ['ns', 'ft', 'aot', 'ap', 'post', 'canc'], true);
    }

    if ($sport === 'tennis') {
        return !in_array($statusShort, ['ns', 'ft', 'fin', 'cancelled', 'canc', 'post'], true);
    }

    return false;
}

function value_by_path(array $input, array $path)
{
    $value = $input;
    foreach ($path as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return null;
        }
        $value = $value[$part];
    }
    return $value;
}

function slugify_bookmaker(string $name): string
{
    $slug = mb_strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/u', '_', $slug);
    $slug = trim((string)$slug, '_');
    return $slug !== '' ? $slug : 'bk';
}

function api_sports_get(string $baseUrl, string $endpoint, array $query): array
{
    $url = rtrim($baseUrl, '/') . $endpoint;
    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 18,
            'ignore_errors' => true,
            'header' =>
                "x-apisports-key: " . API_SPORTS_KEY . "\r\n" .
                "x-rapidapi-key: " . API_SPORTS_KEY . "\r\n" .
                "Accept: application/json\r\n",
        ],
    ]);

    $raw = @file_get_contents($url, false, $context);
    $headers = $http_response_header ?? [];
    $status = extract_http_status($headers);
    $quota = extract_quota_headers($headers);

    if ($raw === false) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'network error',
            'data' => null,
            'quota' => $quota,
        ];
    }

    if ($status < 200 || $status >= 300) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => trim(substr($raw, 0, 240)),
            'data' => null,
            'quota' => $quota,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => 'invalid json',
            'data' => null,
            'quota' => $quota,
        ];
    }

    if (isset($decoded['errors']) && !empty($decoded['errors'])) {
        return [
            'ok' => false,
            'status' => $status,
            'error' => is_string($decoded['errors']) ? $decoded['errors'] : json_encode($decoded['errors'], JSON_UNESCAPED_UNICODE),
            'data' => $decoded,
            'quota' => $quota,
        ];
    }

    return [
        'ok' => true,
        'status' => $status,
        'error' => null,
        'data' => $decoded,
        'quota' => $quota,
    ];
}
