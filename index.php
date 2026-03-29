<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$dataFile = __DIR__ . '/data/merged_matches.json';

$matches = [];
$updated = null;
$error = null;

if (file_exists($dataFile)) {
  $json = json_decode(file_get_contents($dataFile), true);
  if (is_array($json)) {
    $matches = $json['matches'] ?? [];
    $updated = $json['updated'] ?? null;
    // Фильтрация: скрывать матчи, где не хватает хотя бы одного из коэффициентов (П1, Х, П2) для любого источника
    $matches = array_values(array_filter($matches, function($m) {
      $parik = $m['parik24'] ?? null;
      $pinnacle = $m['pinnacle'] ?? null;
      $hasAllParik = isset($parik['p1'], $parik['x'], $parik['p2']) && $parik['p1'] !== null && $parik['x'] !== null && $parik['p2'] !== null && $parik['p1'] !== '' && $parik['x'] !== '' && $parik['p2'] !== '';
      $hasAllPinnacle = isset($pinnacle['p1'], $pinnacle['x'], $pinnacle['p2']) && $pinnacle['p1'] !== null && $pinnacle['x'] !== null && $pinnacle['p2'] !== null && $pinnacle['p1'] !== '' && $pinnacle['x'] !== '' && $pinnacle['p2'] !== '';
      return $hasAllParik || $hasAllPinnacle;
    }));
  }
}

if (empty($matches)) {
    $error = 'Файл merged_matches.json поки порожній або не знайдений.';
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

// Сортировка по максимальному значению формулы (П1+П2 или X+X) по убыванию
function maxFormula($m) {
  $parik = $m['parik24'] ?? null;
  $pinnacle = $m['pinnacle'] ?? null;

  $parikP1 = is_numeric($parik['p1'] ?? null) ? floatval($parik['p1']) : null;
  $parikX = is_numeric($parik['x'] ?? null) ? floatval($parik['x']) : null;
  $pinP2 = is_numeric($pinnacle['p2'] ?? null) ? floatval($pinnacle['p2']) : null;
  $pinX = is_numeric($pinnacle['x'] ?? null) ? floatval($pinnacle['x']) : null;

  $zWin = ($parikP1 && $pinP2) ? (1/$parikP1 + 1/$pinP2) : null;
  $zDraw = ($parikX && $pinX) ? (1/$parikX + 1/$pinX) : null;

  return max(array_filter([$zWin, $zDraw], fn($v) => $v !== null), 0);
}

usort($matches, static function ($a, $b) {
  return maxFormula($b) <=> maxFormula($a);
});

$grouped = [];
foreach ($matches as $match) {
    $league = trim((string)($match['league'] ?? 'Без ліги'));
    if ($league === '') {
        $league = 'Без ліги';
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
    max-width: 1200px;
    margin: 0 auto;
    padding: 32px 16px 48px 16px;
    display: flex;
    flex-direction: column;
    gap: 24px;
  }
  .matches-list {
    display: flex;
    flex-wrap: wrap;
    gap: 24px;
    justify-content: flex-start;
  }
  .match-block {
    background: #00245f;
    border-radius: 18px;
    box-shadow: 0 4px 32px rgba(0,0,0,0.18);
    padding: 18px 22px 18px 22px;
    min-width: 260px;
    max-width: 340px;
    flex: 1 1 120px;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: box-shadow 0.18s, border 0.18s;
    border: 2px solid transparent;
  }
  .match-block:hover {
    box-shadow: 0 8px 40px rgba(0,0,0,0.28);
    border: 2px solid #38bdf8;
  }
  .match-block-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 12px;
  }
  .match-league {
    color: #7c8aa5;
    font-size: 13px;
    font-weight: 700;
    background: #0f172a;
    border-radius: 6px;
    padding: 2px 10px;
    margin-right: 8px;
  }
  .match-formula {
    font-size: 13px;
    color: #38bdf8;
    font-weight: 700;
    display: flex;
    flex-direction: column;
    gap: 2px;
    align-items: flex-end;
    text-align: right;
  }
  .match-btn {
    display: flex;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    background: none;
    border: none;
    color: #e5e7eb;
    cursor: pointer;
    padding: 0;
    font-size: 18px;
    font-weight: 700;
    border-radius: 8px;
    transition: background 0.15s;
    margin-top: 8px;
  }
  .match-btn:hover .team {
    text-decoration: underline;
    color: #38bdf8;
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
    table { min-width: 600px; font-size: 16px; }
    .header h1 { font-size: 24px; }
    .btn, .leagues-filter .btn { font-size: 18px; padding: 14px 16px; }
    .search input { font-size: 18px; }
    .odd { min-width: 60px; padding: 10px 8px; font-size: 16px; }
    .team { font-size: 18px; }
    .modal { width: 99vw; min-width: unset; }
  }
  @media (max-width: 600px) {
    .content, .header, .toolbar, .stats { padding-left: 0; padding-right: 0; }
    table { min-width: 420px; font-size: 15px; }
    .header h1 { font-size: 18px; }
    .btn, .leagues-filter .btn { font-size: 15px; padding: 10px 10px; }
    .search input { font-size: 16px; }
    .odd { min-width: 44px; padding: 7px 4px; font-size: 13px; }
    .team { font-size: 15px; }
    .modal { width: 100vw; min-width: unset; }
    .matches-list {
      display: flex;
      flex-direction: column;
      align-items: center;
    }
  }
  @media (max-width: 480px) {
    body { font-size: 15px; }
    .content, .header, .toolbar, .stats { padding: 0 14px; max-width: 100vw; }
    .header { flex-direction: column; align-items: flex-start; gap: 10px; padding: 18px 0 12px 0; }
    .header h1 { font-size: 18px; margin: 0 0 0 0; }
    .pill { font-size: 12px; padding: 6px 12px; margin-right: 6px; }
    .toolbar { flex-direction: column; gap: 10px; padding: 12px 0 8px 0; }
    .search { min-width: 0; max-width: 100vw; padding: 10px 10px; font-size: 15px; }
    .search input { font-size: 15px; }
    .btn, .leagues-filter .btn { font-size: 15px; padding: 12px 12px; border-radius: 9px; }
    .leagues-filter { gap: 6px; margin-bottom: 14px; }
    .stats { font-size: 14px; gap: 12px; padding: 20px; }
    .stats .num { font-size: 15px; }
    .table-wrap { border-radius: 12px; padding: 8px 0; }
    table { min-width: 480px; font-size: 15px; }
    thead th { padding: 10px 6px; font-size: 13px; }
    td { padding: 10px 6px; font-size: 15px; }
    .col-num { width: 36px; font-size: 13px; }
    .odd { min-width: 38px; padding: 7px 4px; font-size: 13px; border-radius: 8px; }
    .odd .l { font-size: 10px; }
    .odd .v { font-size: 15px; }
    .match-btn { font-size: 14px; border-radius: 8px; background: #172d51; padding: 5px; }
    .team { font-size: 14px; }
    .vs { font-size: 11px; }
    .modal { width: 99vw; min-width: unset; }
    /* Скрыть неважные столбцы на очень маленьких экранах */
    th.odds, td.odds { min-width: 0; }
    th.odds:nth-child(4), th.odds:nth-child(5), th.odds:nth-child(6),
    th.odds:nth-child(7), th.odds:nth-child(8), th.odds:nth-child(9) {
      display: none;
    }
    td.odds:nth-child(4), td.odds:nth-child(5), td.odds:nth-child(6),
    td.odds:nth-child(7), td.odds:nth-child(8), td.odds:nth-child(9) {
      display: none;
    }
    /* Оставить только номер, лигу, матч и расчет формулы */
  }
  @media (max-width: 375px) {
    table { min-width: 340px; font-size: 11px; }
    .header h1 { font-size: 13px; }
    .btn, .leagues-filter .btn { font-size: 11px; padding: 6px 6px; }
    .team { font-size: 10px; }
    .odd { min-width: 24px; font-size: 9px; }
    .modal { width: 100vw; }
  }
    /* Скрыть коэффициенты на мобильных, оставить только формулу */
    @media (max-width: 700px) {
      .desktop-only { display: none !important; }
      .formula-main { font-size: 15px; font-weight: 700; color: #fbbf24; text-align: right; }
      .odds:not(.desktop-only) { text-align: right; }
    }
    @media (max-width: 480px) {
      .formula-main { font-size: 13px; }
    }
  .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
  table { font-size: 18px; }
    @media (max-width: 700px) {
    .matches-list {
      flex-direction: column;
      gap: 16px;
      align-items: center;
    }
    .match-block {
      min-width: unset;
      max-width: unset;
      width: 90vw;
      box-sizing: border-box;
      padding: 14px 6vw 14px 6vw;
    }
    .match-block-header {
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
    }
    .match-btn {
      flex-direction: column;
      align-items: flex-start;
      font-size: 16px;
    }
    .team {
      font-size: 16px;
    }
    .vs {
      margin: 4px 0;
    }
  }
</style>
</head>
<body>
  <header class="header">
    <h1>⚽ Bet<span>parser</span></h1>
    <div style="flex:1"></div>
    <!-- Источник и обновлено убраны по требованию -->
  </header>

    <form class="toolbar" method="get" action="" onsubmit="return false;">
      <div class="search">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-3.5-3.5"/></svg>
        <input type="text" id="searchInput" autocomplete="off" placeholder="Пошук по лізі або командам" />
      </div>
      <a class="btn reset" href="?" id="resetBtn" style="display:none">Сбросить</a>
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
      <div class="formula-filter-wrap" style="margin-bottom:20px;display:flex;align-items:center;gap:12px;">
        <label for="formulaRange" style="font-size:15px;color:#7c8aa5;">Фильтр формулы (от 0 до 1):</label>
        <input type="range" id="formulaRange" min="0" max="1" step="0.001" value="1" style="width:180px;">
        <input type="number" id="formulaValue" min="0" max="1" step="0.001" value="1" style="width:70px;background:#151c2e;color:#e5e7eb;border:1px solid #22305a;border-radius:6px;padding:2px 6px;">
      </div>
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
            $league = trim((string)($match['league'] ?? 'Без ліги'));
            if ($league === '') $league = 'Без ліги';
            $zWin = (is_numeric($parikP1) && is_numeric($pinP2) && $parikP1 > 0 && $pinP2 > 0) ? (1/floatval($parikP1) + 1/floatval($pinP2)) : null;
            $zDraw = (is_numeric($parikX) && is_numeric($pinX) && $parikX > 0 && $pinX > 0) ? (1/floatval($parikX) + 1/floatval($pinX)) : null;
            $zVals = array_filter([$zWin, $zDraw], fn($v) => $v !== null);
            $minZ = $zVals ? min($zVals) : null;
            $cellClass = '';
            if ($minZ !== null) {
              if ($minZ < 0.8) $cellClass = 'cell-green';
              elseif ($minZ < 1) $cellClass = 'cell-blue';
              else $cellClass = 'cell-red';
            }
          ?>
          <?php if ($minZ !== null): ?>
          <div class="match-block <?= $cellClass ?>" data-minz="<?= number_format($minZ, 3, '.', '') ?>">
            <div class="match-block-header">
              <span class="match-league"><?= htmlspecialchars($league) ?></span>
              <span class="match-formula">
                <?php if ($zWin !== null): ?>
                  <span title="1/П1 + 1/П2">1/П1+1/П2: <b><?= number_format($zWin, 3) ?></b></span>
                <?php endif; ?>
                <?php if ($zDraw !== null): ?>
                  <span title="1/Х + 1/Х">1/Х+1/Х: <b><?= number_format($zDraw, 3) ?></b></span>
                <?php endif; ?>
              </span>
            </div>
            <button
              type="button"
              class="match-btn js-open-match"
              data-home="<?= htmlspecialchars($match['home'] ?? '') ?>"
              data-away="<?= htmlspecialchars($match['away'] ?? '') ?>"
              data-league="<?= htmlspecialchars($league) ?>"
              data-parik-url="<?= htmlspecialchars((string)($match['parik24']['link'] ?? '')) ?>"
              data-pinn-url="<?= htmlspecialchars((string)($match['pinnacle']['link'] ?? '')) ?>"
              data-parik-p1="<?= htmlspecialchars((string)($parikP1 ?? '')) ?>"
              data-parik-x="<?= htmlspecialchars((string)($parikX ?? '')) ?>"
              data-parik-p2="<?= htmlspecialchars((string)($parikP2 ?? '')) ?>"
              data-pin-p1="<?= htmlspecialchars((string)($pinP1 ?? '')) ?>"
              data-pin-x="<?= htmlspecialchars((string)($pinX ?? '')) ?>"
              data-pin-p2="<?= htmlspecialchars((string)($pinP2 ?? '')) ?>"
            >
              <span class="team"><?= htmlspecialchars($match['home'] ?? '—') ?></span>
              <div class="vs">vs</div>
              <span class="team"><?= htmlspecialchars($match['away'] ?? '—') ?></span>
            </button>
          </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
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
          <div id="totalsTitle" style="font-weight:800;color:#bfdbfe;margin-bottom:10px;">Тотали</div>
          <div id="totalsContent" style="overflow:auto;">
            <div style="color:#94a3b8;">Завантаження...</div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // --- Лиги-фильтры и фильтр формулы ---
    document.addEventListener('DOMContentLoaded', function() {
      const rows = Array.from(document.querySelectorAll('tbody tr'));
      const leagues = <?php echo json_encode(array_keys($grouped), JSON_UNESCAPED_UNICODE); ?>;
      const filterWrap = document.getElementById('leaguesFilter');
      let selectedLeague = '';
      let searchValue = '';

      function renderButtons() {
        filterWrap.innerHTML = '';
        const allBtn = document.createElement('button');
        allBtn.textContent = 'Все';
        allBtn.className = 'btn' + (!selectedLeague ? ' primary' : '');
        allBtn.onclick = () => { selectedLeague = ''; filterRows(); updateButtonStyles(); };
        filterWrap.appendChild(allBtn);
        leagues.forEach(league => {
          const btn = document.createElement('button');
          btn.textContent = league;
          btn.className = 'btn' + (selectedLeague === league ? ' primary' : '');
          btn.onclick = () => { selectedLeague = league; filterRows(); updateButtonStyles(); };
          filterWrap.appendChild(btn);
        });
      }

      function updateButtonStyles() {
        const btns = filterWrap.querySelectorAll('button');
        btns.forEach(btn => {
          if (btn.textContent === (selectedLeague || 'Все')) {
            btn.classList.add('primary');
          } else {
            btn.classList.remove('primary');
          }
        });
      }

      function filterRows() {
        rows.forEach(row => {
          const l = row.querySelector('.match-btn').dataset.league || 'Без лиги';
          const text = row.textContent.toLowerCase();
          const showLeague = !selectedLeague || l === selectedLeague;
          const showSearch = !searchValue || text.includes(searchValue);
          row.style.display = (showLeague && showSearch) ? '' : 'none';
        });
      }

      renderButtons();
      filterRows();

      // --- Динамический поиск ---
      const searchInput = document.getElementById('searchInput');
      searchInput.addEventListener('input', function() {
        searchValue = (searchInput.value || '').toLowerCase();
        filterRows();
      });

      // Делегирование для модалки
      document.querySelector('tbody').addEventListener('click', function(e) {
        const btn = e.target.closest('.js-open-match');
        if (btn) openMatchModal(btn);
      });
      // --- Фильтр по формуле ---
      const formulaRange = document.getElementById('formulaRange');
      const formulaValue = document.getElementById('formulaValue');
      const matchesList = document.getElementById('matchesList');
      function filterByFormula() {
        const maxVal = parseFloat(formulaRange.value);
        formulaValue.value = maxVal;
        Array.from(matchesList.children).forEach(block => {
          const z = parseFloat(block.getAttribute('data-minz'));
          if (isNaN(z) || z > maxVal) {
            block.style.display = 'none';
          } else {
            block.style.display = '';
          }
        });
      }
      formulaRange.addEventListener('input', filterByFormula);
      formulaValue.addEventListener('input', function() {
        let v = parseFloat(formulaValue.value);
        if (isNaN(v) || v < 0) v = 0;
        if (v > 1) v = 1;
        formulaRange.value = v;
        filterByFormula();
      });
      filterByFormula();
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

      title.textContent = `${home} vs ${away}`;
      sub.textContent = league;


      // Коэффициенты Parik
      const parikP1 = button.dataset.parikP1;
      const parikX = button.dataset.parikX;
      const parikP2 = button.dataset.parikP2;
      // Коэффициенты Pinnacle
      const pinP1 = button.dataset.pinP1;
      const pinX = button.dataset.pinX;
      const pinP2 = button.dataset.pinP2;

      parik.innerHTML = [
        oddBox('П1', parikP1),
        oddBox('Х', parikX),
        oddBox('П2', parikP2)
      ].join('');

      pinnacle.innerHTML = [
        oddBox('П1', pinP1),
        oddBox('Х', pinX),
        oddBox('П2', pinP2)
      ].join('');

      // Формула расчета (1/П1+1/П2 и 1/Х+1/Х)
      function safeNum(val) {
        const n = parseFloat((val || '').replace(',', '.'));
        return (!isNaN(n) && n > 0) ? n : null;
      }
      const zWin = (safeNum(parikP1) && safeNum(pinP2)) ? (1/safeNum(parikP1) + 1/safeNum(pinP2)) : null;
      const zDraw = (safeNum(parikX) && safeNum(pinX)) ? (1/safeNum(parikX) + 1/safeNum(pinX)) : null;
      let formulaHtml = '';
      if (zWin !== null) {
        formulaHtml += `<span title=\"1/П1 + 1/П2\">1/П1+1/П2: <b>${zWin.toFixed(3)}</b></span>`;
      }
      if (zDraw !== null) {
        formulaHtml += `<span title=\"1/Х + 1/Х\">1/Х+1/Х: <b>${zDraw.toFixed(3)}</b></span>`;
      }
      // Удалить предыдущий блок формулы, если есть
      const prevFormula = document.getElementById('modalFormulaBlock');
      if (prevFormula) prevFormula.remove();
      // Вставить формулу под коэффициентами, перед тоталами
      const formulaBlock = document.createElement('div');
      formulaBlock.id = 'modalFormulaBlock';
      formulaBlock.style = 'margin: 12px 0 12px 0; color: #bfdbfe; font-weight: 700; font-size: 15px; display: flex; flex-direction: column; gap: 2px; align-items: flex-end; text-align: right;';
      formulaBlock.innerHTML = formulaHtml;
      // Найти контейнер для тоталов и вставить формулу перед ним
      const totalsContainer = document.getElementById('totalsContainer');
      if (totalsContainer && formulaHtml) {
        totalsContainer.parentNode.insertBefore(formulaBlock, totalsContainer);
      }

      // Reset totals UI
      totalsTitle.textContent = 'Тотали';
      totalsContent.innerHTML = `<div style="color:#94a3b8;">Завантаження...</div>`;

      backdrop.classList.add('show');
      backdrop.style.display = 'flex';
      backdrop.setAttribute('aria-hidden', 'false');

      // Load totals (only overlapping lines across both sites)
      if (parikUrl && pinnUrl) {
        fetch('https://chupserso.pagekite.me/totals', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ parikUrl, pinnUrl })
        })
          .then(r => r.json())
          .then(json => {
            console.log('Тоталы обеих сайтов:', json);
            if (!json || !json.ok) {
              const msg = (json && json.error) ? json.error : 'Помилка завантаження тоталів';
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
                if (r.z < 0) {
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
            totalsContent.innerHTML = `<div style=\"color:#fecaca;\">Помилка мережі при завантаженні тоталів</div>`;
          });
      } else {
        totalsContent.innerHTML = `<div style=\"color:#94a3b8;\">Посилання на матчі відсутні</div>`;
      }
    }

    (function bindModal() {
      const backdrop = document.getElementById('matchModalBackdrop');
      const closeBtn = document.getElementById('matchModalClose');

      document.querySelectorAll('.js-open-match').forEach((button) => {
        button.addEventListener('click', () => openMatchModal(button));
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
