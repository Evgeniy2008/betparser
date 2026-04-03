<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$dataFile = __DIR__ . '/data/merged_matches.json';

$matches = [];
$updated = null;
$error = null;

// Sport tab filter
$sportTab = in_array($_GET['sport'] ?? '', ['football', 'basketball', 'tennis']) ? $_GET['sport'] : 'football';

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
    $error = 'Файл merged_matches.json пока пустой или не найден.';
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
    // Two-way sports: only P1/P2, no draw. Formula: 1/P1(parik)+1/P2(pinnacle) and 1/P2(parik)+1/P1(pinnacle)
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
?>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Betparser - Парсер коэффициентов ставок</title>
<style>
  body {
    font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
    background: linear-gradient(120deg, #101624 0%, #1a223a 100%);
    color: #e5e7eb;
    min-height: 100vh;
    margin: 0;
  }
  .content {
    max-width: 900px;
    margin: 0 auto;
    padding: 18px 6px 24px 6px;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .matches-list {
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    justify-content: flex-start;
  }
  .match-block {
    background: linear-gradient(120deg, #1a2a4a 60%, #223a5f 100%);
    border-radius: 14px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.10);
    padding: 8px 8px 8px 8px;
    min-width: 210px;
    max-width: 90%;
    width: 80%;
    flex: 0 0 210px;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: box-shadow 0.18s, border 0.18s, background 0.18s;
    border: 1.5px solid #223a5f;
    cursor: pointer;
    align-items: stretch;
    justify-content: center;
    min-height: 90px;
  }
  .match-block:hover {
    box-shadow: 0 6px 24px rgba(56,189,248,0.13);
    border: 1.5px solid #38bdf8;
    background: linear-gradient(120deg, #223a5f 60%, #1a2a4a 100%);
  }
  @media (max-width: 700px) {
    .matches-list {
      flex-direction: row;
      flex-wrap: wrap;
      gap: 10px;
      justify-content: center;
    }
    .match-block {
      width: 100%;
      flex: 0 0 100px;
      margin: 0 auto;
      border-radius: 10px;
      padding: 7px 2vw 7px 2vw;
    }
  }
  .match-block:hover {
    box-shadow: 0 8px 40px rgba(0,0,0,0.28);
    border: 2px solid #38bdf8;
  }
  .match-block-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 4px;
    gap: 4px;
  }
  .match-league {
    color: #7c8aa5;
    font-size: 11px;
    font-weight: 700;
    background: #0f172a;
    border-radius: 4px;
    padding: 1px 7px;
    margin-right: 4px;
  }
  .match-formula {
    font-size: 11px;
    color: #38bdf8;
    font-weight: 700;
    display: flex;
    flex-direction: column;
    gap: 1px;
    align-items: flex-end;
    text-align: right;
  }
  .match-btn {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    background: rgba(255,255,255,0.07);
    border: none;
    color: #e5e7eb;
    cursor: pointer;
    padding: 0;
    font-size: 16px;
    font-weight: 700;
    border-radius: 8px;
    transition: background 0.15s;
    margin-top: 0;
    box-shadow: none;
  }
  .match-btn:hover .team {
    text-decoration: underline;
    color: #38bdf8;
    background: rgba(56,189,248,0.07);
  }
  .match-btn .team {
    background: rgba(255,255,255,0.13);
    border-radius: 6px;
    padding: 2px 7px;
    margin: 0 2px;
    transition: background 0.15s;
  }
  .match-btn:hover .team {
    background: rgba(56,189,248,0.13);
  }
  .team {
    display: block;
    font-weight: 800;
    line-height: 1.35;
    font-size: 18px;
  }
  .vs {
    font-size: 13px;
    color: #64748b;
    margin: 0 8px;
    font-weight: 600;
  }
  .cell-green {
    border-left: 5px solid #96ff09;
  }
  .cell-blue {
    border-left: 5px solid #38bdf8;
  }
  .cell-red {
    border-left: 5px solid #ef4444;
  }
  thead th {
    padding: 14px 10px;
    background: #0f172a;
    color: #7c8aa5;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .7px;
    text-align: left;
    border-bottom: 2px solid #22305a;
    position: sticky;
    top: 0;
    z-index: 2;
  }
  tbody tr {
    border-bottom: 1px solid #1a2540;
    transition: background 0.18s;
  }
  tbody tr:hover {
    background: #18213a;
  }
  td {
    padding: 12px 10px;
    vertical-align: middle;
    font-size: 15px;
  }
  .col-num {
    width: 56px;
    text-align: right;
    color: #64748b;
    font-size: 13px;
  }
  .match-btn {
    display: block;
    width: 100%;
    background: none;
    border: none;
    text-align: left;
    color: #e5e7eb;
    cursor: pointer;
    padding: 0;
    font-size: 16px;
    font-weight: 700;
    border-radius: 8px;
    transition: background 0.15s;
  }
  .match-btn:hover .team {
    text-decoration: underline;
    color: #38bdf8;
  }
  .team {
    display: block;
    font-weight: 800;
    line-height: 1.35;
    font-size: 16px;
  }
  .vs {
    font-size: 12px;
    color: #64748b;
    margin: 2px 0;
  }
  .odds {
    text-align: center;
    min-width: 72px;
  }
  .odd {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    min-width: 58px;
    padding: 7px 10px;
    border-radius: 12px;
    font-size: 12px;
    background: #1e263b;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin: 0 auto;
  }
  .odd .l {
    font-size: 10px;
    color: #64748b;
    font-weight: 700;
  }
  .odd .v {
    font-size: 16px;
    font-weight: 800;
  }
  .odd.odds-low { background: #16281b; } .odd.odds-low .v { color: #4ade80; }
  .odd.odds-mid { background: #17263d; } .odd.odds-mid .v { color: #60a5fa; }
  .odd.odds-high { background: #2c1d18; } .odd.odds-high .v { color: #fb923c; }
  .odd.odds-null { background: #1b2335; } .odd.odds-null .v { color: #475569; }
  .cell-green { background: #19224e !important; color: #4ade80; font-weight: 800; }
  .cell-blue { background: #0a2540 !important; color: #60a5fa; font-weight: 800; }
  .cell-red { background: #3c1a1a !important; color: #fecaca; font-weight: 800; }
  .leagues-filter {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 18px;
    justify-content: flex-start;
  }
  .leagues-filter .btn {
    background: #1e293b;
    color: #cbd5e1;
    border: none;
    border-radius: 8px;
    padding: 8px 18px;
    font-size: 14px;
    font-weight: 700;
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
  }
  .leagues-filter .btn.primary {
    background: #2563eb;
    color: #fff;
  }
  .leagues-filter .btn:hover {
    background: #334155;
    color: #fff;
  }
  .toolbar {
    padding: 18px 0 10px 0;
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    border-bottom: 1px solid #1e293b;
    background: #0f172a;
    max-width: 1200px;
    margin: 0 auto;
  }
  .search {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #0b1020;
    border: 1px solid #26395f;
    border-radius: 10px;
    padding: 9px 12px;
    min-width: 280px;
    flex: 1;
    max-width: 520px;
  }
  .search input {
    background: none;
    border: none;
    outline: none;
    color: #e5e7eb;
    width: 100%;
    font-size: 16px;
  }
  .btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 16px;
    border-radius: 10px;
    border: none;
    cursor: pointer;
    font-weight: 700;
    font-size: 15px;
    background: #1e293b;
    color: #cbd5e1;
    transition: background 0.15s, color 0.15s;
  }
  .btn.primary {
    background: #2563eb;
    color: #fff;
  }
  .btn.reset {
    background: #1f2937;
    color: #cbd5e1;
  }
  .btn:hover {
    background: #334155;
    color: #fff;
  }
  .stats {
    padding: 12px 0 0 0;
    padding-left: 20px;
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
    color: #94a3b8;
    font-size: 15px;
    max-width: 1200px;
    margin: 0 auto 0 auto;
  }
  .stats .num {
    color: #e2e8f0;
    font-weight: 800;
  }
  .alert {
    margin: 0 auto 16px auto;
    padding: 14px 16px;
    border-radius: 12px;
    background: #3b1d1d;
    border: 1px solid #7f1d1d;
    color: #fecaca;
    max-width: 900px;
  }
  .empty {
    padding: 54px 20px;
    text-align: center;
    color: #94a3b8;
  }
  .header {
    padding: 24px 0 20px 0;
    padding-left: 20px;
    border-bottom: 1px solid #24304d;
    background: linear-gradient(180deg,#121a31 0%,#0f172a 100%);
    display: flex;
    gap: 14px;
    align-items: center;
    flex-wrap: wrap;
    max-width: 1200px;
    margin: 0 auto;
  }
  .header h1 {
    font-size: 32px;
    color: #7dd3fc;
    margin: 0 18px 0 0;
  }
  .header h1 span {
    color: #fbbf24;
  }
  .pill {
    background: #16213b;
    border: 1px solid #26395f;
    color: #bfdbfe;
    border-radius: 999px;
    padding: 7px 16px;
    font-size: 13px;
    font-weight: 700;
    margin-right: 8px;
  }
  .search {
    margin-left: 20px;
  }
  /* Модальное окно и адаптивность оставляем прежними */
  @media (max-width: 900px) {
    .content, .header, .toolbar, .stats { max-width: 100vw; padding-left: 2vw; padding-right: 2vw; }
    .header h1 { font-size: 22px; }
    .btn, .leagues-filter .btn { font-size: 15px; padding: 10px 12px; }
    .search input { font-size: 16px; }
    .odd { min-width: 40px; padding: 7px 4px; font-size: 13px; }
    .team { font-size: 14px; }
    .modal { width: 99vw; min-width: unset; }
  }
  @media (max-width: 600px) {
    .content, .header, .toolbar, .stats { padding-left: 0; padding-right: 0; }
    .header h1 { font-size: 16px; }
    .btn, .leagues-filter .btn { font-size: 13px; padding: 8px 8px; }
    .search input { font-size: 14px; }
    .odd { min-width: 32px; padding: 5px 2px; font-size: 11px; }
    .team { font-size: 12px; }
    .modal { width: 100vw; min-width: unset; }
    .matches-list {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }
  }
  @media (max-width: 480px) {
    body { font-size: 13px; }
    .content, .header, .toolbar, .stats { padding: 0 6px; max-width: 100vw; }
    .header { flex-direction: column; align-items: flex-start; gap: 7px; padding: 10px 0 8px 0; }
    .header h1 { font-size: 13px; margin: 0 0 0 0; }
    .pill { font-size: 11px; padding: 5px 8px; margin-right: 4px; }
    .toolbar { flex-direction: column; gap: 7px; padding: 8px 0 6px 0; }
    .search { min-width: 0; max-width: 100vw; padding: 7px 7px; font-size: 13px; }
    .search input { font-size: 13px; }
    .btn, .leagues-filter .btn { font-size: 13px; padding: 8px 8px; border-radius: 7px; }
    .leagues-filter { gap: 4px; margin-bottom: 8px; }
    .stats { font-size: 12px; gap: 7px; padding: 10px; }
    .stats .num { font-size: 13px; }
    .table-wrap { border-radius: 8px; padding: 4px 0; }
    .match-btn { font-size: 12px; border-radius: 7px; background: #172d51; padding: 3px; }
    .team { font-size: 12px; }
    .vs { font-size: 9px; }
    .modal { width: 99vw; min-width: unset; }
  }
</style>
</head>
<body>
  <header class="header">
    <h1><?= $sportTab === 'basketball' ? '🏀' : ($sportTab === 'tennis' ? '🎾' : '⚽') ?> Bet<span>parser</span></h1>
    <div style="flex:1"></div>
    <div style="display:flex;gap:8px;align-items:center;">
      <a href="?sport=football" class="btn<?= $sportTab === 'football' ? ' primary' : '' ?>" style="text-decoration:none;">⚽ Футбол</a>
      <a href="?sport=basketball" class="btn<?= $sportTab === 'basketball' ? ' primary' : '' ?>" style="text-decoration:none;">🏀 Баскетбол</a>
      <a href="?sport=tennis" class="btn<?= $sportTab === 'tennis' ? ' primary' : '' ?>" style="text-decoration:none;">🎾 Теннис</a>
    </div>
  </header>

    <form class="toolbar" method="get" action="" onsubmit="return false;">
      <div class="search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-3.5-3.5"/></svg>
        <input type="text" id="searchInput" autocomplete="off" placeholder="Поиск" />
      </div>
      <label style="display:flex;align-items:center;gap:8px;color:#e5e7eb;font-size:14px;cursor:pointer;">
        <input type="checkbox" id="lessThanOneFilter" style="width:16px;height:16px;"> <span>меньше 1</span>
      </label>
      <a class="btn reset" href="?" id="resetBtn" style="display:none">Сброс</a>
    </form>

  <div class="stats">
    <div>Лиг: <span class="num"><?= count($grouped) ?></span></div>
    <div>Матчей: <span class="num"><?= count($matches) ?></span></div>
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
          ?>
          <div class="match-block <?= $cellClass ?> js-open-match"
            data-home="<?= htmlspecialchars($match['home'] ?? '') ?>"
            data-away="<?= htmlspecialchars($match['away'] ?? '') ?>"
            data-league="<?= htmlspecialchars($league) ?>"
            data-sport="<?= htmlspecialchars($matchSport) ?>"
            data-parik-url="<?= htmlspecialchars((string)($match['parik24']['link'] ?? '')) ?>"
            data-pinn-url="<?= htmlspecialchars((string)($match['pinnacle']['link'] ?? '')) ?>"
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
            </div>
            <div class="match-btn">
              <span class="team"><?= htmlspecialchars($match['home'] ?? '—') ?></span>
              <div class="vs">против</div>
              <span class="team"><?= htmlspecialchars($match['away'] ?? '—') ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div id="noMatchesMessage" style="display:none;padding:20px 15px;border-radius:12px;background:#1b2639;color:#cbd5e1;text-align:center;margin-top:14px;">Нет матча ниже 1</div>
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
      const matchBlocks = Array.from(document.querySelectorAll('.match-block'));
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

        if (sport === 'basketball' || sport === 'tennis') {
          // Two-way sports: no draw, only 2 formulas
          return [
            {
              name: '1/(П1 parik24) + 1/(П2 pinnacle)',
              value: (safeNum(parikP1) && safeNum(pinP2)) ? (1/safeNum(parikP1) + 1/safeNum(pinP2)) : null
            },
            {
              name: '1/(П2 parik24) + 1/(П1 pinnacle)',
              value: (safeNum(parikP2) && safeNum(pinP1)) ? (1/safeNum(parikP2) + 1/safeNum(pinP1)) : null
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
        return formulas.some(f => f.value !== null && f.value < 1);
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

      title.textContent = `${home} против ${away}`;
      sub.textContent = league;


      // Коэффициенты Parik
      const sport = button.dataset.sport || 'football';
      const parikP1 = button.dataset.parikP1;
      const parikX = button.dataset.parikX;
      const parikP2 = button.dataset.parikP2;
      // Коэффициенты Pinnacle
      const pinP1 = button.dataset.pinP1;
      const pinX = button.dataset.pinX;
      const pinP2 = button.dataset.pinP2;

      if (sport === 'basketball' || sport === 'tennis') {
        // Two-way sports: no draw column
        parik.innerHTML = [oddBox('П1', parikP1), oddBox('П2', parikP2)].join('');
        pinnacle.innerHTML = [oddBox('П1', pinP1), oddBox('П2', pinP2)].join('');
      } else {
        parik.innerHTML = [oddBox('П1', parikP1), oddBox('Х', parikX), oddBox('П2', parikP2)].join('');
        pinnacle.innerHTML = [oddBox('П1', pinP1), oddBox('Х', pinX), oddBox('П2', pinP2)].join('');
      }

      // --- Формулы (2 для two-way sports, 6 для футбола) ---
      function safeNum(val) {
        const n = parseFloat((val || '').replace(',', '.'));
        return (!isNaN(n) && n > 0) ? n : null;
      }

      let formulas;
      if (sport === 'basketball' || sport === 'tennis') {
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
        fetch('http://localhost:3031/totals', {
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

      document.querySelectorAll('.js-open-match').forEach((block) => {
        block.addEventListener('click', () => openMatchModal(block));
        block.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            openMatchModal(block);
          }
        });
      });

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
  </script>
</body>
</html>
