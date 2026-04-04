<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

// Sport tab filter (declared early so $dataFile can depend on it)
$sportTab = in_array($_GET['sport'] ?? '', ['football', 'basketball', 'tennis', 'live']) ? $_GET['sport'] : 'football';

$dataFile = ($sportTab === 'live')
  ? __DIR__ . '/data/live_matches.json'
  : __DIR__ . '/data/merged_matches.json';

$matches = [];
$updated = null;
$error = null;

if (file_exists($dataFile)) {
  $json = json_decode(file_get_contents($dataFile), true);
  if (is_array($json)) {
    $allMatches = $json['matches'] ?? [];
    $updated = $json['updated'] ?? null;
    // Filter by sport tab
    $matches = array_values(array_filter($allMatches, static function($m) use ($sportTab) {
      return ($m['sport'] ?? 'football') === $sportTab;
    }));
  }
}

if (empty($matches)) {
    $error = ($sportTab === 'live')
      ? 'Файл live_matches.json пока пустой или не найден. Запустите скрапер для сбора лайв матчей.'
      : 'Файл merged_matches.json пока пустой или не найден.';
}

$search = trim($_GET['q'] ?? '');
if ($search !== '') {
    $needle = mb_strtolower($search);
    $matches = array_values(array_filter($matches, static function ($m) use ($needle) {
        $haystack = implode(' ', [
            $m['league'] ?? '',
            $m['home'] ?? '',
            $m['away'] ?? '',
        ]);

        return str_contains(mb_strtolower($haystack), $needle);
    }));
}

// Hide matches with incomplete odds (football: P1/X/P2 on both sides, basketball/tennis: P1/P2 on both sides)
function hasRequiredOdds($m): bool {
  $sport = $m['sport'] ?? 'football';
  $parik = $m['parik24'] ?? null;
  $pin = $m['pinnacle'] ?? null;
  if (!is_array($parik) || !is_array($pin)) return false;

  $isValid = static function($value): bool {
    return is_numeric($value) && floatval($value) > 0;
  };

  if ($sport === 'basketball' || $sport === 'tennis') {
    return $isValid($parik['p1'] ?? null)
      && $isValid($parik['p2'] ?? null)
      && $isValid($pin['p1'] ?? null)
      && $isValid($pin['p2'] ?? null);
  }

  return $isValid($parik['p1'] ?? null)
    && $isValid($parik['x'] ?? null)
    && $isValid($parik['p2'] ?? null)
    && $isValid($pin['p1'] ?? null)
    && $isValid($pin['x'] ?? null)
    && $isValid($pin['p2'] ?? null);
}

$matches = array_values(array_filter($matches, 'hasRequiredOdds'));

// Сортировка по максимальному значению формулы по убыванию
function maxFormula($m) {
  $sport = $m['sport'] ?? 'football';
  $parik = $m['parik24'] ?? null;
  $pinnacle = $m['pinnacle'] ?? null;

  $parikP1 = is_numeric($parik['p1'] ?? null) ? floatval($parik['p1']) : null;
  $parikX  = is_numeric($parik['x']  ?? null) ? floatval($parik['x'])  : null;
  $parikP2 = is_numeric($parik['p2'] ?? null) ? floatval($parik['p2']) : null;
  $pinP1   = is_numeric($pinnacle['p1'] ?? null) ? floatval($pinnacle['p1']) : null;
  $pinP2   = is_numeric($pinnacle['p2'] ?? null) ? floatval($pinnacle['p2']) : null;
  $pinX    = is_numeric($pinnacle['x']  ?? null) ? floatval($pinnacle['x'])  : null;

  if ($sport === 'basketball' || $sport === 'tennis') {
    // Two-way sports: only P1/P2, no draw.
    $z1 = ($parikP1 && $pinP2) ? (1/$parikP1 + 1/$pinP2) : null;
    $z2 = ($parikP2 && $pinP1) ? (1/$parikP2 + 1/$pinP1) : null;
    $vals = array_filter([$z1, $z2], fn($v) => $v !== null);
    return $vals ? max($vals) : 0;
  }

  // Football: full 3-way formulas
  $zWin  = ($parikP1 && $pinP2) ? (1/$parikP1 + 1/$pinP2) : null;
  $zDraw = ($parikX  && $pinX)  ? (1/$parikX  + 1/$pinX)  : null;
  $vals = array_filter([$zWin, $zDraw], fn($v) => $v !== null);
  return $vals ? max($vals) : 0;
}

usort($matches, static function ($a, $b) {
  return maxFormula($b) <=> maxFormula($a);
});

$grouped = [];
foreach ($matches as $match) {
    $league = trim((string)($match['league'] ?? 'Без лиги'));
    if ($league === '') {
        $league = 'Без лиги';
    }
    $grouped[$league][] = $match;
}

function fmtDate(?string $iso): string {
    if (!$iso) return '—';
    try {
        $dt = new DateTime($iso, new DateTimeZone('UTC'));
        $dt->setTimezone(new DateTimeZone('Europe/Kiev'));
        return $dt->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $iso;
    }
}

function oddsClass($v): string {
    if ($v === null || $v === '') return 'odds-null';
    $f = (float)str_replace(',', '.', (string)$v);
    if ($f < 2) return 'odds-low';
    if ($f < 3.5) return 'odds-mid';
    return 'odds-high';
}

function oddsValue($source, string $key): ?string {
  if (!is_array($source)) return null;
  $value = $source[$key] ?? null;
  return ($value === null || $value === '') ? null : (string)$value;
}

// JSON endpoint for live auto-refresh (fetched by JS every 20s)
if ($sportTab === 'live' && ($_GET['format'] ?? '') === 'json') {
  header('Content-Type: application/json; charset=utf-8');
  $processed = [];
  foreach ($matches as $m) {
    $parik    = $m['parik24']  ?? null;
    $pinnacle = $m['pinnacle'] ?? null;
    $sport    = $m['sport']    ?? 'football';
    $p1  = oddsValue($parik,    'p1');
    $x   = oddsValue($parik,    'x');
    $p2  = oddsValue($parik,    'p2');
    $pp1 = oddsValue($pinnacle, 'p1');
    $px  = oddsValue($pinnacle, 'x');
    $pp2 = oddsValue($pinnacle, 'p2');
    $league = trim((string)($m['league'] ?? 'Без лиги'));
    if ($league === '') $league = 'Без лиги';
    $zWin  = ($p1 && $pp2) ? (1/(float)$p1 + 1/(float)$pp2) : null;
    $zDraw = ($x  && $px)  ? (1/(float)$x  + 1/(float)$px)  : null;
    $zVals = array_filter([$zWin, $zDraw], fn($v) => $v !== null);
    $minZ  = $zVals ? min($zVals) : null;
    $cellClass = '';
    if ($minZ !== null) {
      if ($minZ < 0.8) $cellClass = 'cell-green';
      elseif ($minZ < 1) $cellClass = 'cell-blue';
      else $cellClass = 'cell-red';
    }
    $processed[] = [
      'home'     => (string)($m['home'] ?? ''),
      'away'     => (string)($m['away'] ?? ''),
      'league'   => $league,
      'sport'    => $sport,
      'parikUrl' => (string)($m['parik24']['link']  ?? ''),
      'pinnUrl'  => (string)($m['pinnacle']['link'] ?? ''),
      'parikP1'  => (string)($p1  ?? ''),
      'parikX'   => (string)($x   ?? ''),
      'parikP2'  => (string)($p2  ?? ''),
      'pinP1'    => (string)($pp1 ?? ''),
      'pinX'     => (string)($px  ?? ''),
      'pinP2'    => (string)($pp2 ?? ''),
      'cellClass'=> $cellClass,
    ];
  }
  echo json_encode(['ok' => true, 'matches' => $processed, 'updated' => $updated], JSON_UNESCAPED_UNICODE);
  exit;
}
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Betparser - Парсер коэффициентов ставок</title>
<style>
  :root {
    --bg-0: #060b16;
    --bg-1: #0a1222;
    --bg-2: #0f1a2f;
    --surface: rgba(18, 28, 49, 0.86);
    --surface-hover: rgba(26, 38, 66, 0.95);
    --border: #243454;
    --text: #e9f0ff;
    --muted: #9fb2d7;
    --primary: #39b8ff;
    --primary-2: #5de2d8;
    --ok: #63f39d;
    --warn: #ffb156;
    --danger: #ff7f7f;
    --shadow: 0 14px 42px rgba(3, 8, 22, 0.45);
  }

  * { box-sizing: border-box; }

  body {
    margin: 0;
    min-height: 100vh;
    font-family: Inter, 'Segoe UI', Roboto, Arial, sans-serif;
    color: var(--text);
    background:
      radial-gradient(1200px 420px at 10% -10%, rgba(57, 184, 255, 0.22), transparent 55%),
      radial-gradient(900px 400px at 95% -20%, rgba(93, 226, 216, 0.16), transparent 58%),
      linear-gradient(160deg, var(--bg-0) 0%, var(--bg-1) 48%, #08111f 100%);
  }

  .header,
  .toolbar,
  .stats,
  .content,
  .alert {
    width: min(1280px, calc(100% - 28px));
    margin-left: auto;
    margin-right: auto;
  }

  .header {
    margin-top: 16px;
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 14px;
    border-radius: 18px;
    border: 1px solid var(--border);
    background: linear-gradient(135deg, rgba(15, 26, 47, 0.92), rgba(10, 18, 34, 0.92));
    box-shadow: var(--shadow);
    backdrop-filter: blur(8px);
  }

  .brand {
    display: flex;
    flex-direction: column;
    gap: 5px;
  }

  .brand-sub {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    letter-spacing: .2px;
  }

  .header-spacer {
    flex: 1;
  }

  .sport-tabs {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .header h1 {
    margin: 0;
    font-size: clamp(22px, 2.3vw, 34px);
    font-weight: 900;
    letter-spacing: .4px;
    color: #cde9ff;
  }

  .header h1 span {
    background: linear-gradient(90deg, var(--primary), var(--primary-2));
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
  }

  .header a.btn {
    text-decoration: none;
  }

  .toolbar {
    margin-top: 14px;
    padding: 14px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: var(--surface);
    box-shadow: var(--shadow);
    flex-wrap: wrap;
  }

  .filter-toggle {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #d7e5ff;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    user-select: none;
    padding: 8px 10px;
    border-radius: 10px;
    background: rgba(9, 17, 31, .55);
    border: 1px solid #27406a;
  }

  .filter-toggle input {
    width: 16px;
    height: 16px;
    accent-color: #2da4f0;
  }

  .search {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: min(540px, 100%);
    flex: 1;
    background: rgba(7, 14, 27, .72);
    border: 1px solid #2b426f;
    border-radius: 12px;
    padding: 10px 12px;
  }

  .search input {
    border: 0;
    outline: none;
    width: 100%;
    color: var(--text);
    background: transparent;
    font-size: 15px;
  }

  .search input::placeholder {
    color: #7d93bd;
  }

  .btn {
    border: 1px solid #2a4068;
    border-radius: 11px;
    background: linear-gradient(180deg, #183155, #132748);
    color: #d8e6ff;
    padding: 9px 14px;
    font-size: 14px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    cursor: pointer;
    transition: .2s ease;
  }

  .btn:hover {
    transform: translateY(-1px);
    border-color: #3f629c;
    background: linear-gradient(180deg, #1f3a63, #153057);
  }

  .btn.primary {
    border-color: #2f85bf;
    color: #f6fcff;
    background: linear-gradient(180deg, #1d8cd1, #1774bd);
    box-shadow: 0 8px 20px rgba(31, 139, 206, 0.28);
  }

  .btn.reset {
    background: linear-gradient(180deg, #2a1f39, #21162f);
    border-color: #493261;
    color: #cdb9e5;
  }

  .stats {
    margin-top: 12px;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: rgba(13, 22, 39, .84);
    color: #a8bce4;
    display: flex;
    gap: 18px;
    flex-wrap: wrap;
    box-shadow: var(--shadow);
  }

  .stats .num {
    color: #f2f8ff;
    font-weight: 900;
  }

  .alert {
    margin-top: 14px;
    padding: 14px;
    border-radius: 14px;
    border: 1px solid #6f2d3e;
    background: linear-gradient(180deg, #391723, #2a121b);
    color: #ffc8d3;
  }

  .content {
    margin-top: 16px;
    margin-bottom: 30px;
    display: flex;
    flex-direction: column;
    gap: 14px;
  }

  .leagues-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .matches-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
  }

  .match-block {
    position: relative;
    border-radius: 16px;
    border: 1px solid var(--border);
    background: linear-gradient(160deg, rgba(23, 37, 62, .94), rgba(13, 22, 39, .94));
    box-shadow: var(--shadow);
    padding: 12px;
    cursor: pointer;
    transition: .2s ease;
    overflow: hidden;
  }

  .match-block::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(280px 90px at 8% 0%, rgba(57, 184, 255, .15), transparent 70%);
    pointer-events: none;
  }

  .match-block:hover {
    transform: translateY(-3px);
    border-color: #3f5f93;
    background: linear-gradient(160deg, rgba(28, 44, 73, .98), rgba(14, 24, 43, .98));
  }

  .match-block-header {
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
  }

  .match-time {
    font-size: 12px;
    font-weight: 800;
    color: #b9d7ff;
    background: rgba(7, 14, 25, .72);
    border: 1px solid #2f4a78;
    border-radius: 999px;
    padding: 4px 10px;
    white-space: nowrap;
  }

  .match-time-live {
    color: #fecaca;
    border-color: #ef4444;
    background: rgba(127, 29, 29, .45);
    animation: liveBlink 1s ease-in-out infinite;
  }

  @keyframes liveBlink {
    0%, 100% { opacity: 1; }
    50% { opacity: .35; }
  }

  .match-league {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    border: 1px solid #2f4875;
    background: rgba(10, 20, 36, .88);
    color: #9fc1f2;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: .25px;
    text-transform: uppercase;
    padding: 5px 10px;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .match-btn {
    position: relative;
    z-index: 1;
    border: 0;
    background: transparent;
    color: var(--text);
    width: 100%;
    text-align: left;
    padding: 0;
    display: grid;
    gap: 8px;
  }

  .team {
    display: block;
    border-radius: 10px;
    padding: 8px 10px;
    background: rgba(9, 17, 32, .66);
    border: 1px solid rgba(56, 88, 138, .55);
    font-size: 15px;
    font-weight: 800;
    line-height: 1.3;
  }

  .vs {
    text-align: center;
    color: #7e96c3;
    font-size: 11px;
    letter-spacing: .5px;
    text-transform: uppercase;
    font-weight: 800;
  }

  .cell-green { border-left: 4px solid var(--ok); }
  .cell-blue { border-left: 4px solid var(--primary); }
  .cell-red { border-left: 4px solid var(--danger); }

  .odd {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 66px;
    border-radius: 12px;
    padding: 8px 10px;
    border: 1px solid #253d68;
    background: #101d36;
  }

  .odd .l {
    color: #9db4dd;
    font-size: 10px;
    font-weight: 800;
  }

  .odd .v {
    font-size: 15px;
    font-weight: 900;
  }

  .odd.odds-low { background: #123125; border-color: #245239; }
  .odd.odds-low .v { color: var(--ok); }
  .odd.odds-mid { background: #122a4a; border-color: #245186; }
  .odd.odds-mid .v { color: #78bcff; }
  .odd.odds-high { background: #3a2716; border-color: #6f4d24; }
  .odd.odds-high .v { color: var(--warn); }
  .odd.odds-null { background: #1a2438; border-color: #29354f; }
  .odd.odds-null .v { color: #64748b; }

  .empty {
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 42px 18px;
    text-align: center;
    color: #9fb2d8;
    background: rgba(14, 22, 38, .9);
  }

  #noMatchesMessage {
    border-radius: 12px !important;
    border: 1px dashed #33558e;
    background: rgba(15, 27, 48, .75) !important;
    color: #c8daf8 !important;
  }

  .no-matches {
    display: none;
    padding: 20px 15px;
    text-align: center;
    margin-top: 14px;
  }

  #matchModalBackdrop {
    backdrop-filter: blur(7px);
    background: rgba(3, 7, 15, .72) !important;
  }

  #matchModalBackdrop .modal {
    background: linear-gradient(165deg, rgba(16, 26, 45, .97), rgba(9, 16, 30, .97)) !important;
    border: 1px solid #2a416b !important;
    border-radius: 16px !important;
    box-shadow: 0 22px 65px rgba(0, 0, 0, 0.52) !important;
  }

  #matchModalBackdrop .modal > div:first-child {
    border-bottom: 1px solid #223a62 !important;
  }

  .bookmaker-links {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .bookmaker-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    border-radius: 12px;
    border: 1px solid #2a4d7f;
    background: linear-gradient(180deg, #17345a, #132b4b);
    color: #e9f5ff;
    font-weight: 800;
    font-size: 14px;
    padding: 10px 12px;
    transition: .2s ease;
  }

  .bookmaker-link:hover {
    transform: translateY(-1px);
    border-color: #3f6ba6;
    background: linear-gradient(180deg, #1d416f, #16345a);
  }

  .bookmaker-link.disabled {
    pointer-events: none;
    opacity: .55;
    border-color: #33405c;
    background: linear-gradient(180deg, #202a3c, #1a2233);
    color: #a7b2c8;
  }

  #modalFormulaBlock span {
    border: 1px solid rgba(78, 112, 170, .45);
  }

  @media (max-width: 1100px) {
    .header,
    .toolbar,
    .stats,
    .content,
    .alert {
      width: min(1280px, calc(100% - 20px));
    }

    .matches-list {
      grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    }
  }

  @media (max-width: 760px) {
    .header {
      flex-wrap: wrap;
      padding: 13px;
    }

    .header > div:last-child {
      width: 100%;
      justify-content: stretch;
      display: grid !important;
      grid-template-columns: repeat(3, minmax(0, 1fr));
      gap: 7px !important;
    }

    .brand-sub {
      font-size: 12px;
    }

    .toolbar {
      flex-direction: column;
      align-items: stretch;
      gap: 10px;
    }

    .search {
      min-width: 100%;
      max-width: 100%;
    }

    .matches-list {
      grid-template-columns: 1fr;
      gap: 10px;
    }

    #matchModalBackdrop .modal {
      width: 96vw !important;
      border-radius: 14px !important;
    }
  }

  @media (max-width: 520px) {
    .header,
    .toolbar,
    .stats,
    .content,
    .alert {
      width: calc(100% - 14px);
    }

    .header h1 {
      font-size: 20px;
    }

    .brand-sub {
      font-size: 11px;
    }

    .btn {
      padding: 9px 10px;
      font-size: 13px;
    }

    .team {
      font-size: 14px;
    }

    .match-league {
      font-size: 10px;
    }
  }
</style>
</head>
<body>
  <header class="header">
    <div class="brand">
      <h1><?= $sportTab === 'basketball' ? '🏀' : ($sportTab === 'tennis' ? '🎾' : ($sportTab === 'live' ? '🔴' : '⚽')) ?> Bet<span>parser</span></h1>
      <p class="brand-sub">Сканер линий и быстрый поиск валуйных ситуаций</p>
    </div>
    <div class="header-spacer"></div>
    <nav class="sport-tabs">
      <a href="?sport=football" class="btn<?= $sportTab === 'football' ? ' primary' : '' ?>" style="text-decoration:none;">⚽ Футбол</a>
      <a href="?sport=basketball" class="btn<?= $sportTab === 'basketball' ? ' primary' : '' ?>" style="text-decoration:none;">🏀 Баскетбол</a>
      <a href="?sport=tennis" class="btn<?= $sportTab === 'tennis' ? ' primary' : '' ?>" style="text-decoration:none;">🎾 Теннис</a>
      <a href="?sport=live" class="btn<?= $sportTab === 'live' ? ' primary' : '' ?>" style="text-decoration:none;">🔴 Лайв</a>
    </nav>
  </header>

    <form class="toolbar" method="get" action="" onsubmit="return false;">
      <div class="search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-3.5-3.5"/></svg>
        <input type="text" id="searchInput" autocomplete="off" placeholder="Поиск" />
      </div>
      <label class="filter-toggle">
        <input type="checkbox" id="lessThanOneFilter"> <span>меньше 1</span>
      </label>
      <a class="btn reset" href="?" id="resetBtn" style="display:none">Сброс</a>
    </form>

  <div class="stats">
    <div>Лиг: <span class="num"><?= count($grouped) ?></span></div>
    <div>Матчей: <span class="num" id="matchCount"><?= count($matches) ?></span></div>
    <?php if ($search !== ''): ?>
      <div>Фильтр: <span class="num"><?= htmlspecialchars($search) ?></span></div>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <div class="alert"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <main class="content">
    <?php if (!empty($matches)): ?>
      <div class="leagues-filter" id="leaguesFilter" style="margin-bottom:20px;display:flex;flex-wrap:wrap;gap:8px;"></div>

      <div class="matches-list" id="matchesList">
        <?php $globalIndex = 1; ?>
        <?php foreach ($matches as $match): ?>
          <?php
            $parik = $match['parik24'] ?? null;
            $pinnacle = $match['pinnacle'] ?? null;
            $parikP1 = oddsValue($parik, 'p1');
            $parikX = oddsValue($parik, 'x');
            $parikP2 = oddsValue($parik, 'p2');
            $pinP1 = oddsValue($pinnacle, 'p1');
            $pinX = oddsValue($pinnacle, 'x');
            $pinP2 = oddsValue($pinnacle, 'p2');
            $league = trim((string)($match['league'] ?? 'Без лиги'));
            if ($league === '') $league = 'Без лиги';
            $matchSport = $match['sport'] ?? 'football';
            if ($matchSport === 'basketball' || $matchSport === 'tennis') {
              // Two-way sports: 2 formulas with P1/P2 only
              $z1 = (is_numeric($parikP1) && is_numeric($pinP2) && $parikP1 > 0 && $pinP2 > 0) ? (1/floatval($parikP1) + 1/floatval($pinP2)) : null;
              $z2 = (is_numeric($parikP2) && is_numeric($pinP1) && $parikP2 > 0 && $pinP1 > 0) ? (1/floatval($parikP2) + 1/floatval($pinP1)) : null;
              $zVals = array_filter([$z1, $z2], fn($v) => $v !== null);
            } else {
              $zWin = (is_numeric($parikP1) && is_numeric($pinP2) && $parikP1 > 0 && $pinP2 > 0) ? (1/floatval($parikP1) + 1/floatval($pinP2)) : null;
              $zDraw = (is_numeric($parikX) && is_numeric($pinX) && $parikX > 0 && $pinX > 0) ? (1/floatval($parikX) + 1/floatval($pinX)) : null;
              $zVals = array_filter([$zWin, $zDraw], fn($v) => $v !== null);
            }
            $minZ = $zVals ? min($zVals) : null;
            $cellClass = '';
            if ($minZ !== null) {
              if ($minZ < 0.8) $cellClass = 'cell-green';
              elseif ($minZ < 1) $cellClass = 'cell-blue';
              else $cellClass = 'cell-red';
            }
            $isLiveMatch = ($matchSport === 'live');
            if ($isLiveMatch) {
              $matchTime = 'LIVE';
            } else {
              $matchTime = trim((string)($match['time'] ?? ''));
              if ($matchTime === '') {
                $matchTime = trim((string)($match['parik24']['time'] ?? ''));
              }
              if ($matchTime === '') {
                $matchTime = trim((string)($match['pinnacle']['time'] ?? ''));
              }
            }
            if ($matchTime === '') {
              $matchTime = '—';
            }
            $timeClass = ($isLiveMatch && $matchTime !== '—') ? 'match-time match-time-live' : 'match-time';
          ?>
          <div class="match-block <?= $cellClass ?> js-open-match"
            data-home="<?= htmlspecialchars($match['home'] ?? '') ?>"
            data-away="<?= htmlspecialchars($match['away'] ?? '') ?>"
            data-league="<?= htmlspecialchars($league) ?>"
            data-sport="<?= htmlspecialchars($matchSport) ?>"
            data-parik-url="<?= htmlspecialchars((string)($match['parik24']['link'] ?? '')) ?>"
            data-pinn-url="<?= htmlspecialchars((string)($match['pinnacle']['link'] ?? '')) ?>"
            data-time="<?= htmlspecialchars((string)($matchTime ?? '')) ?>"
            data-parik-time="<?= htmlspecialchars((string)($match['parik24']['time'] ?? '')) ?>"
            data-pinn-time="<?= htmlspecialchars((string)($match['pinnacle']['time'] ?? '')) ?>"
            data-parik-p1="<?= htmlspecialchars((string)($parikP1 ?? '')) ?>"
            data-parik-x="<?= htmlspecialchars((string)($parikX ?? '')) ?>"
            data-parik-p2="<?= htmlspecialchars((string)($parikP2 ?? '')) ?>"
            data-pin-p1="<?= htmlspecialchars((string)($pinP1 ?? '')) ?>"
            data-pin-x="<?= htmlspecialchars((string)($pinX ?? '')) ?>"
            data-pin-p2="<?= htmlspecialchars((string)($pinP2 ?? '')) ?>"
            tabindex="0"
            role="button"
            aria-label="Открыть детали матча"
          >
            <div class="match-block-header">
              <span class="match-league"><?= htmlspecialchars($league) ?></span>
              <span class="<?= $timeClass ?>"><?= htmlspecialchars($matchTime) ?></span>
            </div>
            <div class="match-btn">
              <span class="team"><?= htmlspecialchars($match['home'] ?? '—') ?></span>
              <div class="vs">против</div>
              <span class="team"><?= htmlspecialchars($match['away'] ?? '—') ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div id="noMatchesMessage" class="no-matches">Нет матча ниже 1</div>
    <?php else: ?>
      <div class="league-card empty">
        <div style="font-size:44px;margin-bottom:10px">⚽</div>
        <div>Ничего не найдено<?= $search !== '' ? ' по запросу «' . htmlspecialchars($search) . '»' : '' ?>.</div>
      </div>
    <?php endif; ?>
  </main>



  <div id="matchModalBackdrop" class="modal-backdrop" aria-hidden="true" style="position:fixed;inset:0;background:rgba(0,0,0,0.6);display:none;align-items:center;justify-content:center;z-index:9999;">
    <div class="modal" role="dialog" aria-modal="true" style="width:900px;max-width:95vw;background:#0f172a;border:1px solid #24304d;border-radius:14px;box-shadow:0 10px 50px rgba(0,0,0,0.6);">
      <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid #1e293b;">
        <div>
          <div id="matchModalTitle" style="font-weight:800;font-size:18px;color:#e5e7eb;">—</div>
          <div id="matchModalSub" style="font-size:13px;color:#94a3b8;margin-top:4px;">—</div>
        </div>
        <button id="matchModalClose" class="btn" type="button">Закрыть</button>
      </div>
      <div style="padding:14px 16px;display:flex;gap:16px;flex-direction:column;max-height:80vh;overflow:auto;">
        <div id="modalBookmakerLinks" class="bookmaker-links"></div>
        <div style="display:flex;gap:16px;align-items:stretch;">
          <div style="flex:1;display:flex;gap:8px;align-items:center;justify-content:center;background:#0b1224;border:1px solid #1e293b;border-radius:10px;padding:10px;">
            <div style="font-size:12px;color:#94a3b8;font-weight:700;min-width:70px;text-align:right;">Parik24</div>
            <div id="modalParik" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
          </div>
          <div style="flex:1;display:flex;gap:8px;align-items:center;justify-content:center;background:#0b1224;border:1px solid #1e293b;border-radius:10px;padding:10px;">
            <div style="font-size:12px;color:#94a3b8;font-weight:700;min-width:70px;text-align:right;">Pinnacle</div>
            <div id="modalPinnacle" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
          </div>
        </div>
        <div id="totalsContainer" style="background:#0b1224;border:1px solid #1e293b;border-radius:10px;padding:12px;">
          <div id="totalsTitle" style="font-weight:800;color:#bfdbfe;margin-bottom:10px;">Тоталы</div>
          <div id="totalsContent" style="overflow:auto;">
            <div style="color:#94a3b8;">Загрузка...</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // --- Фильтры лиг и фильтр формул ---
    document.addEventListener('DOMContentLoaded', function() {
      // Новый фильтр по лигам для .match-block
      let matchBlocks = Array.from(document.querySelectorAll('.match-block'));
      // Собираем уникальные лиги из .match-league
      const leaguesSet = new Set();
      matchBlocks.forEach(block => {
        const leagueSpan = block.querySelector('.match-league');
        if (leagueSpan) leaguesSet.add(leagueSpan.textContent.trim());
      });
      const leagues = Array.from(leaguesSet);
      const filterWrap = document.getElementById('leaguesFilter');
      let selectedLeague = '';
      let searchValue = '';

      function renderButtons() {
        if (!filterWrap) return;
        filterWrap.innerHTML = '';
        const allBtn = document.createElement('button');
        allBtn.textContent = 'Все';
        allBtn.className = 'btn' + (!selectedLeague ? ' primary' : '');
        allBtn.onclick = () => { selectedLeague = ''; filterBlocks(); updateButtonStyles(); };
        filterWrap.appendChild(allBtn);
        leagues.forEach(league => {
          const btn = document.createElement('button');
          btn.textContent = league;
          btn.className = 'btn' + (selectedLeague === league ? ' primary' : '');
          btn.onclick = () => { selectedLeague = league; filterBlocks(); updateButtonStyles(); };
          filterWrap.appendChild(btn);
        });
      }

      function updateButtonStyles() {
        if (!filterWrap) return;
        const btns = filterWrap.querySelectorAll('button');
        btns.forEach(btn => {
          if (btn.textContent === (selectedLeague || 'Все')) {
            btn.classList.add('primary');
          } else {
            btn.classList.remove('primary');
          }
        });
      }

      function safeNum(val) {
        const n = parseFloat((val || '').replace(',', '.'));
        return (!isNaN(n) && n > 0) ? n : null;
      }

      function computeFormulas(block) {
        const sport = block.dataset.sport || 'football';
        const parikP1 = block.dataset.parikP1;
        const parikX = block.dataset.parikX;
        const parikP2 = block.dataset.parikP2;
        const pinP1 = block.dataset.pinP1;
        const pinX = block.dataset.pinX;
        const pinP2 = block.dataset.pinP2;
        const p1o = pinP2;
        const p2o = pinP1;

        if (sport === 'basketball' || sport === 'tennis') {
          // Two-way sports: no draw, only 2 formulas
          return [
            {
              name: '1/(П1 parik24) + 1/(П2 pinnacle)',
              value: (safeNum(parikP1) && safeNum(p2o)) ? (1/safeNum(parikP1) + 1/safeNum(p2o)) : null
            },
            {
              name: '1/(П2 parik24) + 1/(П1 pinnacle)',
              value: (safeNum(parikP2) && safeNum(p1o)) ? (1/safeNum(parikP2) + 1/safeNum(p1o)) : null
            }
          ];
        }

        // Football: 6 formulas (3-way)
        return [
          {
            name: '1/(П1 parik24) + 1/(X parik24) + 1/(П2 pinnacle)',
            value: (safeNum(parikP1) && safeNum(parikX) && safeNum(pinP2)) ? (1/safeNum(parikP1) + 1/safeNum(parikX) + 1/safeNum(pinP2)) : null
          },
          {
            name: '1/(X parik24) + 1/(П2 parik24) + 1/(П1 pinnacle)',
            value: (safeNum(parikX) && safeNum(parikP2) && safeNum(pinP1)) ? (1/safeNum(parikX) + 1/safeNum(parikP2) + 1/safeNum(pinP1)) : null
          },
          {
            name: '1/(П1 parik24) + 1/(П2 parik24) + 1/(X pinnacle)',
            value: (safeNum(parikP1) && safeNum(parikP2) && safeNum(pinX)) ? (1/safeNum(parikP1) + 1/safeNum(parikP2) + 1/safeNum(pinX)) : null
          },
          {
            name: '1/(П1 pinnacle) + 1/(X pinnacle) + 1/(П2 parik24)',
            value: (safeNum(pinP1) && safeNum(pinX) && safeNum(parikP2)) ? (1/safeNum(pinP1) + 1/safeNum(pinX) + 1/safeNum(parikP2)) : null
          },
          {
            name: '1/(X pinnacle) + 1/(П2 pinnacle) + 1/(П1 parik24)',
            value: (safeNum(pinX) && safeNum(pinP2) && safeNum(parikP1)) ? (1/safeNum(pinX) + 1/safeNum(pinP2) + 1/safeNum(parikP1)) : null
          },
          {
            name: '1/(П1 pinnacle) + 1/(П2 pinnacle) + 1/(X parik24)',
            value: (safeNum(pinP1) && safeNum(pinP2) && safeNum(parikX)) ? (1/safeNum(pinP1) + 1/safeNum(pinP2) + 1/safeNum(parikX)) : null
          }
        ];
      }

      function hasAllOdds(block) {
        const sport = block.dataset.sport || 'football';
        if (sport === 'basketball' || sport === 'tennis') {
          return [
            block.dataset.parikP1,
            block.dataset.parikP2,
            block.dataset.pinP1,
            block.dataset.pinP2
          ].every(val => safeNum(val) !== null);
        }
        return [
          block.dataset.parikP1,
          block.dataset.parikX,
          block.dataset.parikP2,
          block.dataset.pinP1,
          block.dataset.pinX,
          block.dataset.pinP2
        ].every(val => safeNum(val) !== null);
      }

      const lessThanOneCheckbox = document.getElementById('lessThanOneFilter');

      function hasAnyFormulaBelowOne(block) {
        if (!hasAllOdds(block)) return false;
        const formulas = computeFormulas(block);
        return formulas.some(f => f.value < 1);
      }

      function filterBlocks() {
        let visibleCount = 0;
        matchBlocks.forEach(block => {
          const league = block.querySelector('.match-league')?.textContent.trim() || 'Без лиги';
          const text = block.textContent.toLowerCase();
          const showLeague = !selectedLeague || league === selectedLeague;
          const showSearch = !searchValue || text.includes(searchValue);
          const showLessThanOne = !lessThanOneCheckbox || !lessThanOneCheckbox.checked || hasAnyFormulaBelowOne(block);
          const visible = showLeague && showSearch && showLessThanOne;
          block.style.display = visible ? '' : 'none';
          if (visible) visibleCount += 1;
        });
        const noMatchesMessage = document.getElementById('noMatchesMessage');
        if (noMatchesMessage) {
          if (visibleCount === 0 && lessThanOneCheckbox && lessThanOneCheckbox.checked) {
            noMatchesMessage.textContent = 'Нет матча ниже 1';
            noMatchesMessage.style.display = '';
          } else {
            noMatchesMessage.style.display = 'none';
          }
        }
      }

      function logAllFormulas() {
        matchBlocks.forEach(block => {
          if (!hasAllOdds(block)) return;
          const formulas = computeFormulas(block);
          formulas.forEach((formula, index) => {
            console.log(`${index + 1}: ${formula.value !== null ? formula.value.toFixed(3) : 'нет данных'}`);
          });
        });
      }

      renderButtons();
      filterBlocks();
      logAllFormulas();

      // Expose reinit so live polling can call it after re-render
      window._reinitMatchList = function() {
        matchBlocks = Array.from(document.querySelectorAll('.match-block'));
        const newLeagues = [];
        const seen = new Set();
        matchBlocks.forEach(b => {
          const t = b.querySelector('.match-league')?.textContent.trim();
          if (t && !seen.has(t)) { seen.add(t); newLeagues.push(t); }
        });
        leagues.length = 0;
        newLeagues.forEach(l => leagues.push(l));
        renderButtons();
        filterBlocks();
      };

      // --- Динамический поиск и фильтр меньше 1 ---
      const searchInput = document.getElementById('searchInput');
      searchInput.addEventListener('input', function() {
        searchValue = (searchInput.value || '').toLowerCase();
        filterBlocks();
      });
      if (lessThanOneCheckbox) {
        lessThanOneCheckbox.addEventListener('change', function() {
          filterBlocks();
        });
      }
    });
    // Скрыть строки, где есть хотя бы один прочерк среди коэффициентов
    // Скрыть строки, где есть хотя бы один прочерк среди коэффициентов
    document.addEventListener('DOMContentLoaded', function() {
      const allRows = Array.from(document.querySelectorAll('tbody tr'));
      allRows.forEach(function(row) {
        const odds = Array.from(row.querySelectorAll('.odds .v'));
        if (odds.some(cell => cell.textContent.trim() === '—')) {
          row.style.display = 'none';
        }
      });
    });
    function escapeHtml(text) {
      return String(text ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function oddClass(val) {
      if (!val || val === '—') return 'odds-null';
      const num = Number(String(val).replace(',', '.'));
      if (Number.isNaN(num)) return 'odds-null';
      if (num < 2) return 'odds-low';
      if (num < 3.5) return 'odds-mid';
      return 'odds-high';
    }

    function oddBox(label, value) {
      const val = value && value !== '' ? value : '—';
      return `<div class="odd ${oddClass(val)}"><span class="l">${escapeHtml(label)}</span><span class="v">${escapeHtml(val)}</span></div>`;
    }



    function openMatchModal(button) {
      const backdrop = document.getElementById('matchModalBackdrop');
      const title = document.getElementById('matchModalTitle');
      const sub = document.getElementById('matchModalSub');
      const parik = document.getElementById('modalParik');
      const pinnacle = document.getElementById('modalPinnacle');
      const totalsTitle = document.getElementById('totalsTitle');
      const totalsContent = document.getElementById('totalsContent');

      const home = button.dataset.home || '—';
      const away = button.dataset.away || '—';
      const league = button.dataset.league || '—';
      const parikUrl = button.dataset.parikUrl || '';
      const pinnUrl = button.dataset.pinnUrl || '';
      const sport = button.dataset.sport || 'football';
      const matchTime = sport === 'live'
        ? 'LIVE'
        : (button.dataset.time || button.dataset.parikTime || button.dataset.pinnTime || '—');

      title.textContent = `${home} против ${away}`;
      if (sport === 'live' && matchTime !== '—') {
        sub.innerHTML = `${escapeHtml(league)} • <span class="match-time-live" style="padding:2px 8px;border-radius:999px;display:inline-block;">${escapeHtml(matchTime)}</span>`;
      } else {
        sub.textContent = `${league} • ${matchTime}`;
      }

      const linksWrap = document.getElementById('modalBookmakerLinks');
      const parikLinkClass = parikUrl ? 'bookmaker-link' : 'bookmaker-link disabled';
      const pinnLinkClass = pinnUrl ? 'bookmaker-link' : 'bookmaker-link disabled';
      const parikHref = parikUrl ? escapeHtml(parikUrl) : '#';
      const pinnHref = pinnUrl ? escapeHtml(pinnUrl) : '#';
      linksWrap.innerHTML = [
        `<a class="${parikLinkClass}" href="${parikHref}" target="_blank" rel="noopener noreferrer">Открыть матч в Parik24</a>`,
        `<a class="${pinnLinkClass}" href="${pinnHref}" target="_blank" rel="noopener noreferrer">Открыть матч в Pinnacle</a>`
      ].join('');


      // Коэффициенты Parik
      const parikP1 = button.dataset.parikP1;
      const parikX = button.dataset.parikX;
      const parikP2 = button.dataset.parikP2;
      // Коэффициенты Pinnacle
      const pinP1 = button.dataset.pinP1;
      const pinX = button.dataset.pinX;
      const pinP2 = button.dataset.pinP2;

      const p1o = pinP2;
      const p2o = pinP1;

      if (sport === 'basketball') {
        // Two-way sports: no draw column
        parik.innerHTML = [oddBox('П1', parikP1), oddBox('П2', parikP2)].join('');
        pinnacle.innerHTML = [oddBox('П1', p1o), oddBox('П2', p2o)].join('');
      } else if (sport === 'tennis') {
          parik.innerHTML = [oddBox('П1', parikP1), oddBox('П2', parikP2)].join('');
          pinnacle.innerHTML = [oddBox('П1', pinP1), oddBox('П2', pinP2)].join('');
      }else {
        parik.innerHTML = [oddBox('П1', parikP1), oddBox('Х', parikX), oddBox('П2', parikP2)].join('');
        pinnacle.innerHTML = [oddBox('П1', pinP1), oddBox('Х', pinX), oddBox('П2', pinP2)].join('');
      }

      // --- Формулы (2 для two-way sports, 6 для футбола) ---
      function safeNum(val) {
        const n = parseFloat((val || '').replace(',', '.'));
        return (!isNaN(n) && n > 0) ? n : null;
      }

      let formulas;
      if (sport === 'basketball') {
        formulas = [
          {
            label: '1/(П1 parik24) + 1/(П2 pinnacle)',
            value: (safeNum(parikP1) && safeNum(p1o)) ? (1/safeNum(parikP1) + 1/safeNum(p2o)) : null
          },
          {
            label: '1/(П2 parik24) + 1/(П1 pinnacle)',
            value: (safeNum(parikP2) && safeNum(p2o)) ? (1/safeNum(parikP2) + 1/safeNum(p1o)) : null
          }
        ];
      } else if (sport === 'tennis') {
        formulas = [
          {
            label: '1/(П1 parik24) + 1/(П2 pinnacle)',
            value: (safeNum(parikP1) && safeNum(pinP2)) ? (1/safeNum(parikP1) + 1/safeNum(pinP2)) : null
          },
          {
            label: '1/(П2 parik24) + 1/(П1 pinnacle)',
            value: (safeNum(parikP2) && safeNum(pinP1)) ? (1/safeNum(parikP2) + 1/safeNum(pinP1)) : null
          }
        ];
      } else {
        formulas = [
          {
            label: '1/(П1 parik24) + 1/(X parik24) + 1/(П2 pinnacle)',
            value: (safeNum(parikP1) && safeNum(parikX) && safeNum(pinP2)) ? (1/safeNum(parikP1) + 1/safeNum(parikX) + 1/safeNum(pinP2)) : null
          },
          {
            label: '1/(X parik24) + 1/(П2 parik24) + 1/(П1 pinnacle)',
            value: (safeNum(parikX) && safeNum(parikP2) && safeNum(pinP1)) ? (1/safeNum(parikX) + 1/safeNum(parikP2) + 1/safeNum(pinP1)) : null
          },
          {
            label: '1/(П1 parik24) + 1/(П2 parik24) + 1/(X pinnacle)',
            value: (safeNum(parikP1) && safeNum(parikP2) && safeNum(pinX)) ? (1/safeNum(parikP1) + 1/safeNum(parikP2) + 1/safeNum(pinX)) : null
          },
          {
            label: '1/(П1 pinnacle) + 1/(X pinnacle) + 1/(П2 parik24)',
            value: (safeNum(pinP1) && safeNum(pinX) && safeNum(parikP2)) ? (1/safeNum(pinP1) + 1/safeNum(pinX) + 1/safeNum(parikP2)) : null
          },
          {
            label: '1/(X pinnacle) + 1/(П2 pinnacle) + 1/(П1 parik24)',
            value: (safeNum(pinX) && safeNum(pinP2) && safeNum(parikP1)) ? (1/safeNum(pinX) + 1/safeNum(pinP2) + 1/safeNum(parikP1)) : null
          },
          {
            label: '1/(П1 pinnacle) + 1/(П2 pinnacle) + 1/(X parik24)',
            value: (safeNum(pinP1) && safeNum(pinP2) && safeNum(parikX)) ? (1/safeNum(pinP1) + 1/safeNum(pinP2) + 1/safeNum(parikX)) : null
          }
        ];
      }

      formulas.map((f, idx) => {
          console.log(`Формула ${idx+1}: ${f.label} = ${f.value !== null ? f.value.toFixed(3) : '—'}`);
      });

      // Удалить предыдущий блок формулы, если есть
      const prevFormula = document.getElementById('modalFormulaBlock');
      if (prevFormula) prevFormula.remove();
      // Вставить формулы под коэффициентами, перед тоталами
      const formulaBlock = document.createElement('div');
      formulaBlock.id = 'modalFormulaBlock';
      formulaBlock.style = 'margin: 12px 0 12px 0; color: #bfdbfe; font-weight: 700; font-size: 15px; display: flex; flex-direction: column; gap: 2px; align-items: flex-end; text-align: right;';
      formulaBlock.innerHTML = formulas.map((f, idx) => {
        if (f.value === null) return `<span style='color:#64748b;'>${idx+1}. ${f.label}: <b>—</b></span>`;
        let color = f.value < 1 ? '#4ade80' : '#fecaca';
        let bg = f.value < 1 ? '#083c1c' : 'transparent';
        let status = f.value < 1 ? 'Подходит' : 'Не подходит';
        return `<span style='background:${bg};color:${color};padding:2px 6px;border-radius:6px;'>${idx+1}. ${f.label}: <b>${f.value.toFixed(3)}</b> <span style='font-size:12px;font-weight:400;'>${status}</span></span>`;
      }).join('');
      // Найти контейнер для тоталов и вставить формулу перед ним
      const totalsContainer = document.getElementById('totalsContainer');
      if (totalsContainer) {
        totalsContainer.parentNode.insertBefore(formulaBlock, totalsContainer);
      }

      // Totals only for football
      if (sport !== 'football') {
        totalsContainer.style.display = 'none';
      } else {
        totalsContainer.style.display = '';
        totalsTitle.textContent = 'Тоталы';
        totalsContent.innerHTML = `<div style="color:#94a3b8;">Загрузка...</div>`;
      }

      backdrop.classList.add('show');
      backdrop.style.display = 'flex';
      backdrop.setAttribute('aria-hidden', 'false');

      // Load totals (only overlapping lines across both sites)
      if (sport === 'football' && parikUrl && pinnUrl) {
        fetch('https://snecked-lucio-unskinned.ngrok-free.dev/totals', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ parikUrl, pinnUrl })
        })
          .then(r => r.json())
          .then(json => {
            console.log('Тоталы обеих сайтов:', json);
            if (!json || !json.ok) {
              const msg = (json && json.error) ? json.error : 'Ошибка загрузки тоталов';
              totalsContent.innerHTML = `<div style=\"color:#fecaca;\">${escapeHtml(msg)}</div>`;
              return;
            }
            const parikTotals = (json.parik && Array.isArray(json.parik.totals)) ? json.parik.totals : [];
            const pinnTotals = (json.pinn && Array.isArray(json.pinn.totals)) ? json.pinn.totals : [];
            // Сопоставить по line
            const pinnMap = {};
            pinnTotals.forEach(t => { if (t.line) pinnMap[t.line] = t; });
            const rows = [];
            parikTotals.forEach(p => {
              if (p.line && pinnMap[p.line]) {
                const pin = pinnMap[p.line];
                const x = parseFloat((p.over || '').replace(',', '.'));
                const y = parseFloat((pin.under || '').replace(',', '.'));
                let z = null;
                if (!isNaN(x) && x > 0 && !isNaN(y) && y > 0) {
                  z = 1/x + 1/y;
                }
                rows.push({
                  line: p.line,
                  parikOver: p.over,
                  pinnUnder: pin.under,
                  z: z
                });
              }
            });
            if (!rows.length) {
              totalsContent.innerHTML = `<div style=\"color:#94a3b8;\">Совпадающих тоталов не найдено</div>`;
              return;
            }
            const html = [
              '<table style=\"width:100%;border-collapse:separate;border-spacing:0 6px;\">',
              '<thead>',
              '<tr style=\"color:#94a3b8;font-size:12px;text-transform:uppercase;letter-spacing:.5px;\">',
              '<th style=\"text-align:left;padding:6px 8px;\">Линия</th>',
              '<th style=\"text-align:center;padding:6px 8px;\">Parik Over</th>',
              '<th style=\"text-align:center;padding:6px 8px;\">Pin Under</th>',
              '<th style=\"text-align:center;padding:6px 8px;\">z = 1/x + 1/y</th>',
              '<th style=\"text-align:center;padding:6px 8px;\">Статус</th>',
              '</tr>',
              '</thead>',
              '<tbody>'
            ];
            rows.forEach(r => {
              let status = '', color = '';
              if (r.z !== null) {
                if (r.z < 1) {
                  status = 'Подходит';
                  color = 'background:#083c1c;color:#4ade80;font-weight:700;';
                } else {
                  status = 'Не подходит';
                  color = 'background:#3c1a1a;color:#fecaca;font-weight:700;';
                }
              } else {
                status = 'Нет данных';
                color = 'background:#1b2335;color:#475569;';
              }
              html.push(`<tr style=\"${color}\">`);
              html.push(`<td style=\"padding:6px 8px;\">${escapeHtml(r.line)}</td>`);
              html.push(`<td style=\"padding:6px 8px;text-align:center;\">${escapeHtml(r.parikOver)}</td>`);
              html.push(`<td style=\"padding:6px 8px;text-align:center;\">${escapeHtml(r.pinnUnder)}</td>`);
              html.push(`<td style=\"padding:6px 8px;text-align:center;\">${r.z !== null ? r.z.toFixed(3) : '—'}</td>`);
              html.push(`<td style=\"padding:6px 8px;text-align:center;\">${status}</td>`);
              html.push('</tr>');
            });
            html.push('</tbody></table>');
            totalsContent.innerHTML = html.join('');
          })
          .catch(() => {
            totalsContent.innerHTML = `<div style=\"color:#fecaca;\">Ошибка сети при загрузке тоталов</div>`;
          });
      } else {
        totalsContent.innerHTML = `<div style=\"color:#94a3b8;\">Ссылка на матчи отсутствует</div>`;
      }
    }

    (function bindModal() {
      const backdrop = document.getElementById('matchModalBackdrop');
      const closeBtn = document.getElementById('matchModalClose');

      // Use event delegation so live re-renders don't need re-binding
      const matchesList = document.getElementById('matchesList');
      if (matchesList) {
        matchesList.addEventListener('click', (e) => {
          const block = e.target.closest('.js-open-match');
          if (block) openMatchModal(block);
        });
        matchesList.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            const block = e.target.closest('.js-open-match');
            if (block) openMatchModal(block);
          }
        });
      }

      closeBtn.addEventListener('click', () => {
        backdrop.classList.remove('show');
        backdrop.style.display = 'none';
        backdrop.setAttribute('aria-hidden', 'true');
      });

      backdrop.addEventListener('click', (e) => {
        if (e.target === backdrop) {
          backdrop.classList.remove('show');
          backdrop.style.display = 'none';
          backdrop.setAttribute('aria-hidden', 'true');
        }
      });

      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && backdrop.classList.contains('show')) {
          backdrop.classList.remove('show');
          backdrop.style.display = 'none';
          backdrop.setAttribute('aria-hidden', 'true');
        }
      });
    })();

    // --- Live auto-refresh every 20 seconds ---
    (function setupLivePolling() {
      const IS_LIVE = <?= json_encode($sportTab === 'live') ?>;
      if (!IS_LIVE) return;

      const matchesList  = document.getElementById('matchesList');

      function buildCardHtml(m) {
        const timeClass = 'match-time match-time-live';
        return [
          `<div class="match-block ${escapeHtml(m.cellClass)} js-open-match"`,
          ` data-home="${escapeHtml(m.home)}"`,
          ` data-away="${escapeHtml(m.away)}"`,
          ` data-league="${escapeHtml(m.league)}"`,
          ` data-sport="${escapeHtml(m.sport)}"`,
          ` data-parik-url="${escapeHtml(m.parikUrl)}"`,
          ` data-pinn-url="${escapeHtml(m.pinnUrl)}"`,
          ` data-time="LIVE"`,
          ` data-parik-time="" data-pinn-time=""`,
          ` data-parik-p1="${escapeHtml(m.parikP1)}"`,
          ` data-parik-x="${escapeHtml(m.parikX)}"`,
          ` data-parik-p2="${escapeHtml(m.parikP2)}"`,
          ` data-pin-p1="${escapeHtml(m.pinP1)}"`,
          ` data-pin-x="${escapeHtml(m.pinX)}"`,
          ` data-pin-p2="${escapeHtml(m.pinP2)}"`,
          ` tabindex="0" role="button" aria-label="Открыть детали матча">`,
          `<div class="match-block-header">`,
          `<span class="match-league">${escapeHtml(m.league)}</span>`,
          `<span class="${timeClass}">LIVE</span>`,
          `</div>`,
          `<div class="match-btn">`,
          `<span class="team">${escapeHtml(m.home)}</span>`,
          `<div class="vs">против</div>`,
          `<span class="team">${escapeHtml(m.away)}</span>`,
          `</div>`,
          `</div>`,
        ].join('');
      }

      async function fetchAndUpdate() {
        try {
          const url = `?sport=live&format=json&_=${Date.now()}`;
          const resp = await fetch(url);
          if (!resp.ok) return;
          const data = await resp.json();
          if (!data.ok || !Array.isArray(data.matches)) return;

          if (matchesList) {
            matchesList.innerHTML = data.matches.map(buildCardHtml).join('');
          }

          // Update match count
          const countEl = document.getElementById('matchCount');
          if (countEl) countEl.textContent = data.matches.length;

          // Reinit filters now that DOM changed
          if (typeof window._reinitMatchList === 'function') {
            window._reinitMatchList();
          }
        } catch (e) {
          // Ignore network errors silently
        }
      }

      fetchAndUpdate();
      setInterval(fetchAndUpdate, 20000);
    })();
  </script>
</body>
</html>
