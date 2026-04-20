<?php
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$validSports = ['football', 'basketball', 'tennis'];
$sportTab = 'football';
$requestedSport = trim((string)($_GET['sport'] ?? 'football'));
if (in_array($requestedSport, $validSports, true)) {
  $sportTab = $requestedSport;
}
$search = trim((string)($_GET['q'] ?? ''));
?><!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title>Betparser — Live Odds Scanner</title>
  <style>
    *{box-sizing:border-box}
    :root{
      --bg:#070b14;
      --panel:#111827;
      --panel-soft:#0c1422;
      --line:#23314a;
      --line-soft:#2c3e5f;
      --text:#e8eefc;
      --muted:#9fb0cf;
      --accent:#4f9cff;
      --accent-soft:#18283f;
      --danger:#ff7b95;
      --good:#42d392;
      --warn:#69b7ff;
    }
    html,body{margin:0;padding:0;overflow-x:hidden;color:var(--text);font-family:Segoe UI,Arial,sans-serif}
    body{min-height:100vh;background:radial-gradient(1200px 700px at 80% -10%,#1b2d4a 0%,transparent 55%),radial-gradient(900px 600px at -10% 20%,#18273e 0%,transparent 50%),var(--bg)}
    .wrap{max-width:1280px;margin:0 auto;padding:14px}
    .box{background:linear-gradient(180deg,rgba(255,255,255,.02),rgba(255,255,255,0) 26%),var(--panel);border:1px solid var(--line);border-radius:16px;padding:14px;margin-bottom:12px;min-width:0;box-shadow:0 10px 30px rgba(2,8,18,.35)}
    .hero{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
    .hero h1{margin:0 0 6px;font-size:26px;line-height:1.2}
    .hero p{margin:0;color:var(--muted)}
    .status-inline{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .live-dot{width:10px;height:10px;border-radius:50%;background:#ff5c7a;box-shadow:0 0 0 6px rgba(255,92,122,.14)}
    .tabs{display:flex;gap:8px;flex-wrap:wrap}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 14px;border-radius:12px;border:1px solid var(--line-soft);text-decoration:none;color:var(--text);background:var(--accent-soft);cursor:pointer;transition:background .2s ease,border-color .2s ease,transform .2s ease,box-shadow .2s ease;font-size:14px;min-height:44px}
    .btn:hover{border-color:#4f6b95;background:#1f3352;box-shadow:0 6px 18px rgba(0,0,0,.22)}
    .btn.primary{background:linear-gradient(180deg,#5ea8ff,#3d86e9);border-color:#6eb2ff;color:#fff}
    .btn:active{transform:translateY(1px)}
    .muted{color:var(--muted);font-size:13px}
    .controls-grid{display:grid;grid-template-columns:minmax(0,1.3fr) minmax(220px,.9fr) auto auto;gap:10px;align-items:end}
    .field{min-width:0}
    .field label{display:block;margin-bottom:6px;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.4px}
    .search,.select{width:100%;max-width:100%;padding:12px 14px;border-radius:12px;border:1px solid var(--line-soft);background:#0e192b;color:var(--text);min-height:46px;outline:none}
    .search:focus,.select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(42,132,234,.18)}
    .select{appearance:none;background-image:linear-gradient(45deg,transparent 50%,#9eb2d8 50%),linear-gradient(135deg,#9eb2d8 50%,transparent 50%);background-position:calc(100% - 18px) calc(50% - 3px),calc(100% - 12px) calc(50% - 3px);background-size:6px 6px,6px 6px;background-repeat:no-repeat;padding-right:34px}
    .status-box{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
    .status-box strong{font-size:14px}
    .status-error{color:var(--danger)}
    .table-wrap{overflow:auto;border-radius:14px}
    .table{width:100%;border-collapse:collapse;font-size:14px;min-width:920px}
    .table th,.table td{border-bottom:1px solid #1f2f48;padding:8px 8px;vertical-align:top}
    .table th{color:#9cb3d8;text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.4px}
    .table th.right,.table td.right{text-align:right}
    .table-row{cursor:pointer;transition:background .18s ease}
    .table-row:hover{background:#15233a}
    .teams{font-weight:700;font-size:15px;line-height:1.35;overflow-wrap:anywhere}
    .match-card{display:grid;gap:6px}
    .teams-stack{display:grid;gap:5px}
    .team-row{display:flex;align-items:center}
    .match-topline{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}
    .team-name{font-weight:700;font-size:13px;line-height:1.2;overflow-wrap:anywhere}
    .score-badge{display:inline-flex;align-items:center;justify-content:center;min-width:44px;padding:2px 7px;border-radius:999px;background:#172b46;border:1px solid #365b8d;font-weight:700;font-size:12px}
    .sub-meta{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
    .league-inline{display:inline-flex;align-items:center;gap:6px}
    .league{margin-top:4px;overflow-wrap:anywhere}
    .tag{display:inline-flex;align-items:center;padding:3px 8px;border-radius:999px;font-size:11px;border:1px solid #35557f;color:#b4c7e8;background:#12243b}
    .tag-live{color:#ffd4de;border-color:#b24b66;background:#3a1723}
    .z-good{color:var(--good);font-weight:700}
    .z-bad{color:var(--danger);font-weight:700}
    .z-mid{color:var(--warn);font-weight:700}
    .odd-list{display:flex;flex-wrap:wrap;gap:5px}
    .odd{display:inline-flex;align-items:center;justify-content:center;min-width:50px;text-align:center;padding:4px 7px;border-radius:8px;border:1px solid #334968;white-space:nowrap;font-size:12px}
    .odd-low{background:#123428;border-color:#2f6a4f;color:#88f0bc}
    .odd-mid{background:#142f4d;border-color:#33628e;color:#a9d4ff}
    .odd-high{background:#3e2a18;border-color:#815a2c;color:#ffd59b}
    .odd-null{background:#1a2334;border-color:#3a4d69;color:#8c9db8}
    .bookmaker-name{font-weight:700;margin-bottom:5px;overflow-wrap:anywhere;font-size:13px}
    .info-lines{display:grid;gap:3px}
    .info-line{font-size:12px;line-height:1.2}
    .empty-state{padding:32px 14px;text-align:center;color:var(--muted)}

    .modal-backdrop{position:fixed;inset:0;background:rgba(3,8,15,.78);display:none;align-items:center;justify-content:center;z-index:1000;padding:14px}
    .modal{width:min(960px,100%);max-height:min(88vh,900px);overflow:auto;background:#101a2c;border:1px solid #2d4367;border-radius:18px;box-shadow:0 24px 72px rgba(0,0,0,.52)}
    .modal-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;padding:14px 16px;border-bottom:1px solid #253a5c;background:#101a2c;position:sticky;top:0;z-index:2}
    .modal-title{font-weight:700;font-size:18px;line-height:1.35;overflow-wrap:anywhere}
    .modal-body{padding:14px;display:grid;gap:12px}
    .grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
    .card{background:var(--panel-soft);border:1px solid var(--line-soft);border-radius:14px;padding:12px;min-width:0}
    .card-title{font-weight:700;margin-bottom:8px;overflow-wrap:anywhere}
    .meta-list{display:grid;gap:6px}
    .formula-list{display:grid;gap:6px}
    .table-loader{padding:28px 12px;text-align:center;color:var(--muted)}

    @media (max-width:1040px){
      .controls-grid{grid-template-columns:1fr 1fr;align-items:stretch}
      .controls-grid .btn{width:100%}
      .table{min-width:860px}
      .grid{grid-template-columns:1fr}
    }

    @media (max-width:768px){
      .wrap{padding:10px}
      .box{padding:12px}
      .hero h1{font-size:22px}
      .status-inline{justify-content:flex-start}
      .tabs{flex-wrap:nowrap;overflow-x:auto;padding-bottom:4px;scrollbar-width:thin}
      .tabs .btn{white-space:nowrap;flex:0 0 auto}
      .tabs::-webkit-scrollbar{height:6px}
      .tabs::-webkit-scrollbar-thumb{background:#355389;border-radius:999px}
      .controls-grid{grid-template-columns:1fr}
      .table{min-width:0;border-collapse:separate;border-spacing:0 6px}
      .table thead{display:none}
      .table tbody{display:block}
      .table-row{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;column-gap:10px;background:#0f1a30;border:1px solid var(--line-soft);border-radius:12px;padding:8px}
      .table-row td{display:flex;flex-direction:column;align-items:flex-start;gap:4px;border:0;padding:2px;text-align:left;width:100%}
      .table-row td::before{content:attr(data-label);color:#98b6eb;font-size:11px;text-transform:uppercase;letter-spacing:.4px}
      .table-row td[data-label="Матч"]::before,
      .table-row td[data-label="Live"]::before{display:none}
      .table-row td:nth-child(2),
      .table-row td:nth-child(3),
      .table-row td:nth-child(4){display:none}
      .table-row td[data-label="Матч"]{grid-column:1/2;min-width:0}
      .table-row td[data-label="Live"]{grid-column:2/3;align-items:flex-end;justify-self:end;min-width:96px}
      .match-card{gap:3px;width:100%}
      .match-topline{gap:6px;justify-content:flex-start}
      .league-inline{display:inline-flex;font-size:11px;color:var(--muted)}
      .teams-stack{gap:2px}
      .team-name{font-size:14px;line-height:1.12}
      .score-badge{min-width:34px;padding:1px 5px;font-size:10px}
      .table-row td[data-label="Live"] .live-main{font-size:16px;font-weight:800;line-height:1}
      .table-row td[data-label="Live"] .live-nearone{display:inline;font-size:10px;line-height:1.05}
      .table-row td[data-label="Live"] .z-good,
      .table-row td[data-label="Live"] .z-mid,
      .table-row td[data-label="Live"] .z-bad{font-size:15px}
      .odd-list{width:100%}
      .status-box{align-items:flex-start}
      .modal-backdrop{padding:0;align-items:flex-end}
      .modal{width:100%;max-height:90vh;border-radius:18px 18px 0 0;border-bottom:0}
      .modal-head{padding:12px}
      .modal-body{padding:12px;padding-bottom:calc(12px + env(safe-area-inset-bottom))}
      .modal-title{font-size:16px}
      .btn,.search,.select{font-size:16px}
    }
  </style>
</head>
<body>
<div class="wrap">
  <div class="box hero">
    <div>
      <h1>Betparser: Live Sports (Pinnacle + Parik24)</h1>
      <p>Live-матчи и коэффициенты (football / basketball / tennis) объединяются из Pinnacle и Parik24 и обновляются автоматически.</p>
    </div>
  </div>

  <div class="box tabs" id="sportTabs">
    <a class="btn<?= $sportTab === 'football' ? ' primary' : '' ?>" data-sport-tab="football" href="?sport=football">⚽ Футбол</a>
    <a class="btn<?= $sportTab === 'basketball' ? ' primary' : '' ?>" data-sport-tab="basketball" href="?sport=basketball">🏀 Баскетбол</a>
    <a class="btn<?= $sportTab === 'tennis' ? ' primary' : '' ?>" data-sport-tab="tennis" href="?sport=tennis">🎾 Теннис</a>
  </div>

  <div class="box">
    <div class="controls-grid">
      <div class="field">
        <label for="searchInput">Поиск</label>
        <input id="searchInput" class="search" type="text" value="<?= htmlspecialchars($search) ?>" placeholder="Лига, команды или букмекер">
      </div>
      <div class="field">
        <label for="leagueFilterSelect">Лига</label>
        <select id="leagueFilterSelect" class="select">
          <option value="__all__">Все лиги</option>
        </select>
      </div>
      <button id="underOneBtn" class="btn" type="button">Фильтр &lt; 1</button>
      <button id="refreshBtn" class="btn" type="button">Обновить</button>
    </div>
  </div>

  <div class="box status-box" id="statusBox">
    <strong>Загрузка live-матчей…</strong>
    <span class="muted" id="statusMeta">Подключение к API…</span>
  </div>

  <div class="box table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Матч</th>
          <th>Статус</th>
          <th>Pinnacle</th>
          <th>Parik24</th>
          <th class="right">Live</th>
        </tr>
      </thead>
      <tbody id="eventsTableBody">
        <tr><td colspan="5" class="table-loader">Загрузка данных…</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div id="modalBackdrop" class="modal-backdrop" aria-hidden="true">
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-head">
      <div>
        <div id="modalTitle" class="modal-title">—</div>
        <div id="modalSub" class="muted">—</div>
      </div>
      <button id="modalClose" class="btn" type="button">Закрыть ✕</button>
    </div>
    <div class="modal-body">
      <div class="card">
        <label class="muted" for="secondSelect">Вторая БК</label>
        <select id="secondSelect" class="select"></select>
      </div>
      <div class="grid">
        <div class="card">
          <div id="pinName" class="card-title">Pinnacle</div>
          <div id="pinOdds" class="odd-list"></div>
        </div>
        <div class="card">
          <div id="secondName" class="card-title">Вторая БК</div>
          <div id="secondOdds" class="odd-list"></div>
        </div>
      </div>
      <div class="card">
        <div class="card-title">Формулы</div>
        <div id="formulaList" class="formula-list muted"></div>
      </div>
      <div class="card">
        <div class="card-title">Статистика API</div>
        <div id="matchStats" class="meta-list muted"></div>
      </div>
    </div>
  </div>
</div>

<script>
let EVENTS = [];
let EVENTS_MAP = {};
let currentSportTab = <?= json_encode($sportTab, JSON_UNESCAPED_UNICODE) ?>;
const VALID_SPORT_TABS = ['football', 'basketball', 'tennis'];
const LEAGUE_FILTER_SPORTS = ['football', 'basketball'];
const PREFERRED_SECOND_KEY = 'betfair';
const BEST_OPTION_KEY = '__best__';
const AUTO_REFRESH_INTERVAL_MS = 2000;
const LIVE_PULL_INTERVAL_MS = 5000;
let tableRows = [];
let dataRefreshInFlight = false;
let currentRequestController = null;
let refreshRequestSeq = 0;
let nextAllowedRefreshAt = 0;
let autoRefreshTimer = null;
let livePullTimer = null;
let livePullInFlight = false;
let currentModalEventId = null;
let currentModalSecondKey = null;
let currentDefaultSecondKey = PREFERRED_SECOND_KEY;
let currentDefaultSecondTitle = 'Betfair';
let currentDefaultSecondMode = 'preferred';
let allEventsHavePinnacle = true;
let selectedSecondBkKey = PREFERRED_SECOND_KEY;
let selectedLeague = '__all__';
let showOnlyUnderOne = false;
let leagueFilterSignature = '';

const searchInput = document.getElementById('searchInput');
const leagueFilterSelect = document.getElementById('leagueFilterSelect');
const secondBkSelect = document.getElementById('secondBkSelect');
const refreshBtn = document.getElementById('refreshBtn');
const sportTabs = Array.from(document.querySelectorAll('[data-sport-tab]'));

function num(v){
  const n = Number(String(v ?? '').replace(',', '.'));
  return Number.isFinite(n) && n > 0 ? n : null;
}

function escHtml(value){
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function oddChip(label, value){
  const safeValue = value === null || value === undefined || value === '' ? '—' : value;
  return `<span class="odd ${oddClassJs(value)}">${label} ${escHtml(safeValue)}</span>`;
}

function oddClassJs(value){
  const n = num(value);
  if (n === null) return 'odd-null';
  if (n < 2) return 'odd-low';
  if (n < 3.5) return 'odd-mid';
  return 'odd-high';
}

function zClassJs(value){
  if (value === null || value === undefined) return 'z-bad';
  return value < 1 ? 'z-good' : 'z-bad';
}

function formulaThresholdForSport(sport){
  return 1;
}

function formulaClassBySport(sport, value){
  if (value === null || value === undefined) return 'z-bad';
  return value < formulaThresholdForSport(sport) ? 'z-good' : 'z-bad';
}

function hasDrawForPair(second, pin){
  return num(second?.x) !== null && num(pin?.x) !== null;
}

function hasDrawForBlock(block){
  return num(block?.x) !== null;
}

function getSearchHaystack(event){
  return [
    event.league || '',
    event.home || '',
    event.away || '',
    event.pinnacle?.title || '',
    ...Object.values(event.seconds || {}).map((item) => item.title || ''),
  ].join(' ').toLowerCase();
}

function buildFormulas(sport, second, pin){
  const a1 = num(second?.p1), ax = num(second?.x), a2 = num(second?.p2);
  const b1 = num(pin?.p1), bx = num(pin?.x), b2 = num(pin?.p2);

  if (sport === 'football') {
    return [
      {label: '1/P1 Parik + 1/X Parik + 1/P2 Pinnacle', value: (a1 && ax && b2) ? (1 / a1 + 1 / ax + 1 / b2) : null},
      {label: '1/X Parik + 1/P2 Parik + 1/P1 Pinnacle', value: (ax && a2 && b1) ? (1 / ax + 1 / a2 + 1 / b1) : null},
      {label: '1/P1 Parik + 1/P2 Parik + 1/X Pinnacle', value: (a1 && a2 && bx) ? (1 / a1 + 1 / a2 + 1 / bx) : null},
      {label: '1/P1 Pinnacle + 1/X Pinnacle + 1/P2 Parik', value: (b1 && bx && a2) ? (1 / b1 + 1 / bx + 1 / a2) : null},
      {label: '1/X Pinnacle + 1/P2 Pinnacle + 1/P1 Parik', value: (bx && b2 && a1) ? (1 / bx + 1 / b2 + 1 / a1) : null},
      {label: '1/P1 Pinnacle + 1/P2 Pinnacle + 1/X Parik', value: (b1 && b2 && ax) ? (1 / b1 + 1 / b2 + 1 / ax) : null},
    ];
  }

  // Basketball / Tennis (2-way): user condition is value < 0
  return [
    {label: '1/P1 Parik + 1/P2 Pinnacle', value: (a1 && b2) ? (1 / a1 + 1 / b2) : null},
    {label: '1/P1 Pinnacle + 1/P2 Parik', value: (b1 && a2) ? (1 / b1 + 1 / a2) : null},
  ];
}

function hasComparableSeconds(event){
  return !event?.marketMode && Object.keys(event?.seconds || {}).length > 0;
}

function formatScore(event){
  // If a detailed score string exists (e.g. tennis "0-0 (4-3)"), use it
  if (event?.scoreDetail) return event.scoreDetail;
  const home = event?.score?.home;
  const away = event?.score?.away;
  if (home === null || home === undefined || away === null || away === undefined) return null;
  return `${home}:${away}`;
}

function formatStatusLabel(event){
  if (!event?.isLive) return event?.time || 'PREMATCH';
  return 'LIVE';
}

function minFormulaJs(sport, second, pin){
  const values = buildFormulas(sport, second, pin).map((item) => item.value).filter((value) => value !== null);
  return values.length ? Math.min(...values) : null;
}

function maxUnderOneFormulaJs(sport, second, pin){
  const values = buildFormulas(sport, second, pin)
    .map((item) => item.value)
    .filter((value) => value !== null && value < 1);
  return values.length ? Math.max(...values) : null;
}

function getDefaultSecondKeyForEvent(event){
  if (event.defaultSecondKey && event.seconds?.[event.defaultSecondKey]) return event.defaultSecondKey;
  if (event.seconds?.[currentDefaultSecondKey]) return currentDefaultSecondKey;
  if (event.seconds?.[PREFERRED_SECOND_KEY]) return PREFERRED_SECOND_KEY;
  if (event.bestSecondKey && event.seconds?.[event.bestSecondKey]) return event.bestSecondKey;
  return Object.keys(event.seconds || {})[0] || null;
}

function getSelectedSecondForEvent(event){
  if (selectedSecondBkKey === BEST_OPTION_KEY) {
    return event.bestSecondKey ? event.seconds?.[event.bestSecondKey] || null : null;
  }
  if (selectedSecondBkKey) {
    return event.seconds?.[selectedSecondBkKey] || null;
  }
  const fallbackKey = getDefaultSecondKeyForEvent(event);
  return fallbackKey ? event.seconds?.[fallbackKey] || null : null;
}

function getMinFormulaForEvent(event){
  if (selectedSecondBkKey === BEST_OPTION_KEY) {
    return event.bestMinFormula ?? null;
  }

  const second = getSelectedSecondForEvent(event);
  return second ? minFormulaJs(event.sport || 'football', second, event.pinnacle || {}) : null;
}

function getMaxUnderOneForEvent(event){
  const second = getSelectedSecondForEvent(event);
  return second ? maxUnderOneFormulaJs(event.sport || 'football', second, event.pinnacle || {}) : null;
}

function sortEvents(list){
  return [...list].sort((a, b) => {
    const aValue = getMinFormulaForEvent(a);
    const bValue = getMinFormulaForEvent(b);
    if (aValue === null && bValue === null) return 0;
    if (aValue === null) return 1;
    if (bValue === null) return -1;
    return aValue - bValue;
  });
}

function bindTableRows(){
  tableRows = Array.from(document.querySelectorAll('#eventsTableBody .table-row'));
  tableRows.forEach((row) => {
    row.addEventListener('click', () => renderModal(row.dataset.eventId));
  });
}

function rebuildSecondBkDropdown(){
  if (!secondBkSelect) return;

  const bookmakerMap = {};
  EVENTS.forEach((event) => {
    Object.values(event.seconds || {}).forEach((second) => {
      if (second?.key && !bookmakerMap[second.key]) {
        bookmakerMap[second.key] = second.title || second.key;
      }
    });
  });

  const previousValue = selectedSecondBkKey || currentDefaultSecondKey || PREFERRED_SECOND_KEY;
  secondBkSelect.innerHTML = '';

  if (!Object.keys(bookmakerMap).length) {
    const option = document.createElement('option');
    option.value = '';
    option.textContent = 'Live odds';
    secondBkSelect.appendChild(option);
    secondBkSelect.value = '';
    secondBkSelect.disabled = true;
    selectedSecondBkKey = '';
    return;
  }

  secondBkSelect.disabled = false;

  const defaultOption = document.createElement('option');
  defaultOption.value = currentDefaultSecondKey || PREFERRED_SECOND_KEY;
  const modeLabel = currentDefaultSecondMode === 'common'
    ? 'есть у всех матчей'
    : (currentDefaultSecondMode === 'fallback' ? 'макс. покрытие' : (currentDefaultSecondMode === 'live' ? 'live поток' : 'по умолчанию'));
  defaultOption.textContent = `${currentDefaultSecondTitle || bookmakerMap[currentDefaultSecondKey] || 'Вторая БК'} · ${modeLabel}`;
  secondBkSelect.appendChild(defaultOption);

  const bestOption = document.createElement('option');
  bestOption.value = BEST_OPTION_KEY;
  bestOption.textContent = 'Лучшая доступная БК';
  secondBkSelect.appendChild(bestOption);

  Object.entries(bookmakerMap)
    .filter(([key]) => key !== (currentDefaultSecondKey || PREFERRED_SECOND_KEY))
    .sort((a, b) => a[1].localeCompare(b[1], 'ru'))
    .forEach(([key, title]) => {
      const option = document.createElement('option');
      option.value = key;
      option.textContent = title;
      secondBkSelect.appendChild(option);
    });

  const effectiveDefaultKey = currentDefaultSecondKey || PREFERRED_SECOND_KEY;
  const hasPrevious = previousValue === effectiveDefaultKey || previousValue === BEST_OPTION_KEY || Object.prototype.hasOwnProperty.call(bookmakerMap, previousValue);
  selectedSecondBkKey = hasPrevious ? previousValue : effectiveDefaultKey;
  secondBkSelect.value = selectedSecondBkKey;
}

function rebuildLeagueFilter(){
  if (!leagueFilterSelect) return;

  if (!LEAGUE_FILTER_SPORTS.includes(currentSportTab)) {
    const disabledSignature = `disabled:${currentSportTab}`;
    if (leagueFilterSignature === disabledSignature && leagueFilterSelect.disabled && leagueFilterSelect.value === '__all__') {
      selectedLeague = '__all__';
      return;
    }

    selectedLeague = '__all__';
    leagueFilterSelect.innerHTML = '<option value="__all__">Все лиги</option>';
    leagueFilterSelect.value = '__all__';
    leagueFilterSelect.disabled = true;
    leagueFilterSignature = disabledSignature;
    return;
  }

  const leagues = Array.from(new Set(
    EVENTS
      .map((event) => String(event?.league || '').trim())
      .filter((league) => league !== '')
  )).sort((a, b) => a.localeCompare(b, 'ru'));

  const signature = `${currentSportTab}|${leagues.join('\u0001')}`;
  const hasSelected = selectedLeague === '__all__' || leagues.includes(selectedLeague);
  selectedLeague = hasSelected ? selectedLeague : '__all__';

  if (leagueFilterSignature === signature && !leagueFilterSelect.disabled) {
    if (leagueFilterSelect.value !== selectedLeague) {
      leagueFilterSelect.value = selectedLeague;
    }
    return;
  }

  const prevScrollTop = leagueFilterSelect.scrollTop;

  leagueFilterSelect.innerHTML = '';
  const allOption = document.createElement('option');
  allOption.value = '__all__';
  allOption.textContent = 'Все лиги';
  leagueFilterSelect.appendChild(allOption);

  leagues.forEach((league) => {
    const option = document.createElement('option');
    option.value = league;
    option.textContent = league;
    leagueFilterSelect.appendChild(option);
  });

  leagueFilterSelect.value = selectedLeague;
  leagueFilterSelect.disabled = false;
  leagueFilterSelect.scrollTop = prevScrollTop;
  leagueFilterSignature = signature;
}

function renderEventsTable(){
  const tbody = document.getElementById('eventsTableBody');
  if (!tbody) return;

  if (!EVENTS.length) {
    tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Нет live-матчей для выбранного спорта.</td></tr>';
    tableRows = [];
    return;
  }

  let displayEvents = sortEvents(EVENTS);

  // Скрыть вилки меньше 0.85
  displayEvents = displayEvents.filter((event) => {
    const value = getMinFormulaForEvent(event);
    return value === null || value >= 0.85;
  });

  if (showOnlyUnderOne) {
    displayEvents = displayEvents.filter((event) => {
      const value = getMinFormulaForEvent(event);
      return value !== null && value < formulaThresholdForSport(event.sport || 'football');
    });
  }

  if (LEAGUE_FILTER_SPORTS.includes(currentSportTab) && selectedLeague !== '__all__') {
    displayEvents = displayEvents.filter((event) => String(event?.league || '').trim() === selectedLeague);
  }

  if (!displayEvents.length) {
    tbody.innerHTML = `<tr><td colspan="5" class="empty-state">${showOnlyUnderOne ? 'Нет матчей, подходящих под порог формулы.' : 'Нет live-матчей.'}</td></tr>`;
    tableRows = [];
    return;
  }

  tbody.innerHTML = displayEvents.map((event) => {
    const second = getSelectedSecondForEvent(event);
    const formula = getMinFormulaForEvent(event);
    const maxUnderOne = getMaxUnderOneForEvent(event);
    const pin = event.pinnacle || {};
    const search = getSearchHaystack(event);
    const score = formatScore(event);
    const statusLabel = formatStatusLabel(event);
    const sportKey = event.sport || 'football';
    const liveClass = formulaClassBySport(sportKey, formula);
    const nearOneClass = maxUnderOne === null ? 'z-bad' : 'z-good';
    const liveCellHtml = formula === null
      ? '<span class="live-main z-bad">—</span>'
      : `<span class="live-main ${liveClass}">${Number(formula).toFixed(3)}</span>${maxUnderOne !== null ? ` <span class="live-nearone muted">| &lt;1:</span> <span class="live-nearone ${nearOneClass}">${Number(maxUnderOne).toFixed(3)}</span>` : ''}`;
    return `<tr class="table-row" data-event-id="${escHtml(event.id)}" data-search="${escHtml(search)}">
      <td data-label="Матч">
        <div class="match-card">
          <div class="match-topline">
            <span class="league-inline">${escHtml(event.league || '—')}</span>
            ${score ? `<div class="score-badge">${escHtml(score)}</div>` : ''}
          </div>
          <div class="teams-stack">
            <div class="team-row">
              <div class="team-name">${escHtml(event.home)}</div>
            </div>
            <div class="team-row">
              <div class="team-name">${escHtml(event.away)}</div>
            </div>
          </div>
        </div>
      </td>
      <td data-label="Статус">
        ${event.isLive
          ? `<span class="tag tag-live">${escHtml(statusLabel)}</span>`
          : `<span class="tag">${escHtml(statusLabel)}</span>`}
      </td>
      <td data-label="Pinnacle">
        <div class="bookmaker-name">${escHtml(pin.title || 'Pinnacle')}</div>
        <div class="odd-list">
          ${oddChip('P1', pin.p1)}
          ${hasDrawForBlock(pin) ? oddChip('X', pin.x) : ''}
          ${oddChip('P2', pin.p2)}
        </div>
      </td>
      <td data-label="Parik24">
        ${second ? `
          <div class="bookmaker-name">${escHtml(second.title || 'Parik24')}</div>
          <div class="odd-list">
            ${oddChip('P1', second.p1)}
            ${hasDrawForBlock(second) ? oddChip('X', second.x) : ''}
            ${oddChip('P2', second.p2)}
          </div>
        ` : (event.marketMode === 'live-odds'
          ? `<div class="info-lines muted"><div class="info-line">Нет совпадения</div><div class="info-line">Счёт ${escHtml(score || '—')}</div></div>`
          : `<div class="muted">${selectedSecondBkKey === currentDefaultSecondKey ? 'Эта БК недоступна для этого матча' : 'Нет доступной БК'}</div>`)}
      </td>
      <td class="right" data-label="Live">${liveCellHtml}</td>
    </tr>`;
  }).join('');

  bindTableRows();
  applyDynamicSearch();
}

function applyDynamicSearch(){
  const query = (searchInput?.value || '').trim().toLowerCase();
  tableRows.forEach((row) => {
    const haystack = row.dataset.search || '';
    row.style.display = !query || haystack.includes(query) ? '' : 'none';
  });
}

function showLoadingState(message = 'Загрузка данных…'){
  const tbody = document.getElementById('eventsTableBody');
  if (tbody) {
    tbody.innerHTML = `<tr><td colspan="5" class="table-loader">${escHtml(message)}</td></tr>`;
  }
}

function syncSportTabsUi(){
  sportTabs.forEach((tab) => {
    tab.classList.toggle('primary', tab.dataset.sportTab === currentSportTab);
  });
}

function setCurrentSportTab(nextSport, options = {}){
  const {pushHistory = true, showLoader = true} = options;
  if (!VALID_SPORT_TABS.includes(nextSport) || nextSport === currentSportTab) {
    return;
  }

  currentSportTab = nextSport;
  syncSportTabsUi();
  closeModal();

  if (pushHistory) {
    const url = new URL(window.location.href);
    url.searchParams.set('sport', nextSport);
    window.history.pushState({sport: nextSport}, '', url);
  }

  if (showLoader) {
    showLoadingState('Загрузка данных по выбранному спорту…');
  }

  refreshCurrentSportData(showLoader);
}

function getRemainingRefreshMs(){
  return Math.max(0, nextAllowedRefreshAt - Date.now());
}

function scheduleAutoRefresh(){
  clearTimeout(autoRefreshTimer);
  const remaining = getRemainingRefreshMs();
  const delay = remaining > 0 ? remaining : AUTO_REFRESH_INTERVAL_MS;
  autoRefreshTimer = setTimeout(() => refreshCurrentSportData(false), delay);
}

function queueRefreshAfterCooldown(showLoader = false){
  clearTimeout(autoRefreshTimer);
  const remaining = getRemainingRefreshMs();
  if (showLoader && remaining > 0) {
    showLoadingState(`Ожидание следующего запроса: ${Math.ceil(remaining / 1000)} сек…`);
  }
  autoRefreshTimer = setTimeout(() => refreshCurrentSportData(showLoader), remaining || 0);
}

function updateStatus(payload){
  const box = document.getElementById('statusBox');
  const meta = document.getElementById('statusMeta');
  if (!box || !meta) return;

  const total = Array.isArray(payload?.events) ? payload.events.length : EVENTS.length;
  const updated = payload?.updated || '—';
  const quota = payload?.meta?.quota?.['x-requests-remaining'];
  const totalEvents = payload?.meta?.eventsTotal;
  const refreshIn = Math.ceil(Math.max(0, getRemainingRefreshMs()) / 1000);

  box.querySelector('strong').textContent = 'Live-матчи';
  meta.innerHTML = `Матчей: ${total}${typeof totalEvents === 'number' ? ` · Событий: ${totalEvents}` : ''} · Источник: Pinnacle + Parik24 Live · Обновление каждые 2 сек${refreshIn > 0 ? ` · Следующий запрос через ${refreshIn} сек` : ''}${quota ? ` · Остаток запросов: ${escHtml(quota)}` : ''}`;
  meta.classList.remove('status-error');
}

function setStatusError(message){
  const box = document.getElementById('statusBox');
  const meta = document.getElementById('statusMeta');
  if (!box || !meta) return;
  box.querySelector('strong').textContent = 'Ошибка загрузки';
  meta.textContent = message;
  meta.classList.add('status-error');
}

function renderModal(eventId, preferredSecondKey = null){
  const event = EVENTS_MAP[eventId];
  if (!event) return;

  const seconds = Object.values(event.seconds || {});

  currentModalEventId = eventId;

  const score = formatScore(event);
  document.getElementById('modalTitle').textContent = `${event.home} — ${event.away}${score ? ` (${score})` : ''}`;
  document.getElementById('modalSub').textContent = `${event.league || '—'} • ${formatStatusLabel(event)}`;
  document.getElementById('pinName').textContent = event.pinnacle?.title || 'Pinnacle';
  document.getElementById('pinOdds').innerHTML = [
    oddChip('P1', event.pinnacle?.p1),
    ...(hasDrawForBlock(event.pinnacle) ? [oddChip('X', event.pinnacle?.x)] : []),
    oddChip('P2', event.pinnacle?.p2),
  ].join('');

  const select = document.getElementById('secondSelect');
  select.innerHTML = '';
  if (seconds.length) {
    seconds.forEach((second) => {
      const option = document.createElement('option');
      option.value = second.key;
      option.textContent = second.title;
      select.appendChild(option);
    });
  } else {
    const option = document.createElement('option');
    option.value = '__live__';
    option.textContent = 'Parik24';
    select.appendChild(option);
  }

  const fallbackKey = seconds.length
    ? (preferredSecondKey && event.seconds?.[preferredSecondKey]
      ? preferredSecondKey
      : (event.seconds?.[currentDefaultSecondKey] ? currentDefaultSecondKey : getDefaultSecondKeyForEvent(event)))
    : '__live__';

  select.disabled = !seconds.length;
  select.value = fallbackKey;
  currentModalSecondKey = fallbackKey;

  const applySecond = () => {
    const second = seconds.length ? event.seconds?.[select.value] : event.pinnacle;
    if (!second) return;

    currentModalSecondKey = select.value;
    document.getElementById('secondName').textContent = seconds.length ? (second.title || 'Parik24') : 'Parik24';
    document.getElementById('secondOdds').innerHTML = [
      oddChip('P1', second.p1),
      ...(hasDrawForBlock(second) ? [oddChip('X', second.x)] : []),
      oddChip('P2', second.p2),
    ].join('');

    document.getElementById('formulaList').innerHTML = buildFormulas(event.sport || 'football', second, event.pinnacle || {}).map((formula, index) => {
      if (formula.value === null) return `<div>${index + 1}. ${formula.label}: —</div>`;
      return `<div>${index + 1}. ${formula.label}: <span class="${formulaClassBySport(event.sport || 'football', formula.value)}">${formula.value.toFixed(3)}</span></div>`;
    }).join('');

    const stats = event.stats || {};
    document.getElementById('matchStats').innerHTML = [
      `<div>ID события: ${escHtml(event.id || '—')}</div>`,
      `<div>Всего БК в API: ${escHtml(stats.bookmakersTotal ?? 0)}</div>`,
      `<div>Подходящих вторых БК: ${escHtml(stats.validSecondsTotal ?? 0)}</div>`,
      `<div>Обновление Pinnacle: ${escHtml(stats.pinnacleLastUpdate || '—')}</div>`,
      `<div>Последнее обновление API: ${escHtml(stats.apiLastUpdate || '—')}</div>`,
      `<div>Время старта события: ${escHtml(event.time || '—')}</div>`,
      `<div>Рефери: ${escHtml(stats.referee || '—')}</div>`,
      `<div>Стадион: ${escHtml(stats.venueName || '—')}</div>`,
      `<div>Город: ${escHtml(stats.venueCity || '—')}</div>`,
      `<div>Live рынков: ${escHtml(stats.marketsTotal ?? 0)}</div>`,
    ].join('');
  };

  select.onchange = applySecond;
  applySecond();

  const backdrop = document.getElementById('modalBackdrop');
  backdrop.style.display = 'flex';
  backdrop.classList.add('is-open');
  backdrop.setAttribute('aria-hidden', 'false');
}

function closeModal(){
  currentModalEventId = null;
  currentModalSecondKey = null;
  const backdrop = document.getElementById('modalBackdrop');
  if (!backdrop) return;
  backdrop.style.display = 'none';
  backdrop.classList.remove('is-open');
  backdrop.setAttribute('aria-hidden', 'true');
}

function updateOpenModalIfNeeded(){
  const backdrop = document.getElementById('modalBackdrop');
  if (!currentModalEventId || !backdrop?.classList.contains('is-open')) return;
  if (!EVENTS_MAP[currentModalEventId]) {
    closeModal();
    return;
  }
  renderModal(currentModalEventId, currentModalSecondKey);
}

async function syncLiveFeedsOnServer(){
  if (livePullInFlight) return;
  livePullInFlight = true;
  try {
    await fetch(`pull_live_data.php?_=${Date.now()}`, {
      method: 'GET',
      cache: 'no-store',
      headers: {'Accept': 'application/json'},
    });
  } catch (_) {
    // silent: UI update cycle continues independently via api.php
  } finally {
    livePullInFlight = false;
  }
}

function startLivePullLoop(){
  if (livePullTimer) clearInterval(livePullTimer);
  syncLiveFeedsOnServer();
  livePullTimer = setInterval(syncLiveFeedsOnServer, LIVE_PULL_INTERVAL_MS);
}

async function refreshCurrentSportData(showLoader = false){
  if (getRemainingRefreshMs() > 0) {
    queueRefreshAfterCooldown(showLoader);
    return;
  }

  const requestSeq = ++refreshRequestSeq;
  nextAllowedRefreshAt = Date.now() + AUTO_REFRESH_INTERVAL_MS;

  if (currentRequestController) {
    currentRequestController.abort();
  }

  currentRequestController = new AbortController();
  dataRefreshInFlight = true;

  if (showLoader) {
    refreshBtn.disabled = true;
    refreshBtn.textContent = 'Загрузка…';
  }

  try {
    const response = await fetch(`api.php?sport=${encodeURIComponent(currentSportTab)}&_=${Date.now()}`, {
      cache: 'no-store',
      headers: {'Accept': 'application/json'},
      signal: currentRequestController.signal,
    });

    const data = await response.json();
    if (requestSeq !== refreshRequestSeq) return;

    if (!response.ok || !data?.ok) {
      throw new Error(data?.error || 'Не удалось получить данные из API');
    }

    EVENTS = Array.isArray(data.events) ? data.events : [];
    EVENTS_MAP = Object.fromEntries(EVENTS.map((event) => [event.id, event]));
    currentDefaultSecondKey = data?.meta?.defaultSecondKey || PREFERRED_SECOND_KEY;
    currentDefaultSecondTitle = data?.meta?.defaultSecondTitle || currentDefaultSecondTitle;
    currentDefaultSecondMode = data?.meta?.defaultSecondMode || 'preferred';
    allEventsHavePinnacle = data?.meta?.allEventsHavePinnacle !== false;

    if (!selectedSecondBkKey || selectedSecondBkKey === PREFERRED_SECOND_KEY || selectedSecondBkKey === currentDefaultSecondKey) {
      selectedSecondBkKey = currentDefaultSecondKey;
    }

    rebuildSecondBkDropdown();
    rebuildLeagueFilter();
    renderEventsTable();
    updateStatus(data);
    updateOpenModalIfNeeded();
  } catch (error) {
    if (error?.name === 'AbortError') {
      return;
    }

    setStatusError(error?.message || 'Ошибка сети');
    if (!EVENTS.length) {
      const tbody = document.getElementById('eventsTableBody');
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="5" class="empty-state">Не удалось загрузить данные. Попробуйте обновить страницу.</td></tr>';
      }
    }
  } finally {
    if (requestSeq === refreshRequestSeq) {
      dataRefreshInFlight = false;
      refreshBtn.disabled = false;
      refreshBtn.textContent = 'Обновить';
      currentRequestController = null;
      scheduleAutoRefresh();
    }
  }
}

sportTabs.forEach((tab) => {
  tab.addEventListener('click', (event) => {
    event.preventDefault();
    const nextSport = tab.dataset.sportTab || '';
    setCurrentSportTab(nextSport, {pushHistory: true, showLoader: true});
  });
});

window.addEventListener('popstate', () => {
  const params = new URLSearchParams(window.location.search);
  const nextSport = params.get('sport') || 'football';
  if (!VALID_SPORT_TABS.includes(nextSport)) {
    return;
  }

  if (nextSport === currentSportTab) {
    syncSportTabsUi();
    return;
  }

  currentSportTab = nextSport;
  syncSportTabsUi();
  closeModal();
  showLoadingState('Загрузка данных по выбранному спорту…');
  refreshCurrentSportData(true);
});

secondBkSelect?.addEventListener('change', (event) => {
  selectedSecondBkKey = event.target.value || currentDefaultSecondKey || PREFERRED_SECOND_KEY;
  renderEventsTable();
  updateOpenModalIfNeeded();
});

leagueFilterSelect?.addEventListener('change', (event) => {
  selectedLeague = event.target.value || '__all__';
  closeModal();
  renderEventsTable();
});

searchInput?.addEventListener('input', applyDynamicSearch);

document.getElementById('underOneBtn')?.addEventListener('click', (event) => {
  showOnlyUnderOne = !showOnlyUnderOne;
  event.currentTarget.classList.toggle('primary', showOnlyUnderOne);
  renderEventsTable();
});

refreshBtn?.addEventListener('click', () => {
  refreshCurrentSportData(true);
});

document.getElementById('modalClose')?.addEventListener('click', closeModal);
document.getElementById('modalBackdrop')?.addEventListener('click', (event) => {
  if (event.target.id === 'modalBackdrop') closeModal();
});
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeModal();
});

rebuildSecondBkDropdown();
rebuildLeagueFilter();
syncSportTabsUi();
startLivePullLoop();
refreshCurrentSportData(true);
</script>
</body>
</html>
