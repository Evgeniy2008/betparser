#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

function loadEnv() {
  const envPath = path.join(__dirname, '.env');
  if (!fs.existsSync(envPath)) return;
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && !process.env[m[1]]) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
}
loadEnv();

const API_KEY = process.env.OWLSINSIGHT_API_KEY;
if (!API_KEY) {
  console.error('Missing OWLSINSIGHT_API_KEY (set in .env or environment).');
  process.exit(1);
}

const BASE = 'https://api.owlsinsight.com';
const REALTIME_SPORTS = new Set(['soccer', 'tennis', 'nba', 'nhl', 'mlb']);

const argv = Object.fromEntries(
  process.argv.slice(2).map(a => {
    const m = a.match(/^--([^=]+)(?:=(.*))?$/);
    return m ? [m[1], m[2] ?? 'true'] : [a, 'true'];
  })
);

const SPORTS = (argv.sport === 'all'
  ? [...REALTIME_SPORTS]
  : (argv.sport || 'soccer').split(',').map(s => s.trim()).filter(Boolean)
).filter(s => REALTIME_SPORTS.has(s));

if (!SPORTS.length) {
  console.error(`No valid sports. Supported: ${[...REALTIME_SPORTS].join(', ')}`);
  process.exit(1);
}

const INTERVAL_MS = Math.max(2, parseInt(argv.interval || '5', 10)) * 1000;
const LIVE_ONLY = argv.live !== 'false';
const LEAGUE_FILTER = argv.league ? argv.league.toLowerCase() : null;
const SHOW_ALL_MARKETS = argv['all-markets'] === 'true';

const C = {
  reset: '\x1b[0m', dim: '\x1b[90m', bold: '\x1b[1m',
  cyan: '\x1b[36m', green: '\x1b[32m', yellow: '\x1b[33m', red: '\x1b[31m', magenta: '\x1b[35m',
};

function americanToDecimal(price) {
  if (typeof price !== 'number' || !isFinite(price)) return null;
  return +(price > 0 ? 1 + price / 100 : 1 + 100 / Math.abs(price)).toFixed(3);
}

function fmtPrice(price) {
  if (price == null) return `${C.dim}-${C.reset}`;
  const am = price > 0 ? `+${price}` : `${price}`;
  return `${C.green}${am}${C.reset} ${C.dim}(${americanToDecimal(price)})${C.reset}`;
}

function fmtKickoff(iso, isLive) {
  if (!iso) return '';
  const d = new Date(iso);
  const diffMin = Math.round((d - Date.now()) / 60000);
  const stamp = d.toISOString().replace('T', ' ').slice(0, 16) + 'Z';
  if (isLive) return `${stamp} ${C.red}LIVE${C.reset}`;
  if (diffMin >= 0 && diffMin < 180) return `${stamp} ${C.yellow}in ${diffMin}m${C.reset}`;
  return stamp;
}

async function getJSON(url) {
  const r = await fetch(url, {
    headers: { Authorization: `Bearer ${API_KEY}`, Accept: 'application/json' },
  });
  if (!r.ok) throw new Error(`HTTP ${r.status} ${r.statusText} — ${url}`);
  const j = await r.json();
  if (!j.success) throw new Error(`API: ${JSON.stringify(j).slice(0, 200)}`);
  return j;
}

async function fetchLive(sport) {
  const [ps, scores] = await Promise.allSettled([
    getJSON(`${BASE}/api/v1/${sport}/ps3838-realtime`),
    getJSON(`${BASE}/api/v1/${sport}/scores/live`),
  ]);
  const games = ps.status === 'fulfilled' ? ps.value.data || [] : [];
  const liveEvents = scores.status === 'fulfilled' ? scores.value.events || [] : [];
  return { games, liveEvents };
}

function normTeam(s) {
  return (s || '')
    .toLowerCase()
    .replace(/[.,'()·•]/g, '')
    .replace(/\bfc\b|\bcf\b|\bsc\b|\bac\b|\bfk\b|\bsk\b/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}

function tokens(s) {
  return new Set(normTeam(s).split(' ').filter(w => w.length >= 3));
}

function buildLiveIndex(liveEvents) {
  const byDate = new Map();
  for (const e of liveEvents) {
    if (e.status?.state !== 'in') continue;
    const homeName = e.home?.team?.displayName;
    const awayName = e.away?.team?.displayName;
    if (!homeName || !awayName) continue;
    const date = (e.startTime || '').slice(0, 10);
    const entry = {
      homeName, awayName,
      homeTokens: tokens(homeName),
      awayTokens: tokens(awayName),
      homeScore: e.home?.score,
      awayScore: e.away?.score,
      clock: e.status?.displayClock || e.status?.detail || '',
    };
    if (!byDate.has(date)) byDate.set(date, []);
    byDate.get(date).push(entry);
  }
  return byDate;
}

function findScore(liveIndex, homeTeam, awayTeam, date) {
  const ht = tokens(homeTeam), at = tokens(awayTeam);
  if (!ht.size || !at.size) return null;

  const candidates = [];
  const dates = date ? [date] : [...liveIndex.keys()];
  for (const d of dates) {
    for (const e of liveIndex.get(d) || []) candidates.push(e);
  }

  let best = null, bestScore = 0, swap = false;
  for (const e of candidates) {
    const sameHome = [...ht].filter(t => e.homeTokens.has(t)).length;
    const sameAway = [...at].filter(t => e.awayTokens.has(t)).length;
    const crossHome = [...ht].filter(t => e.awayTokens.has(t)).length;
    const crossAway = [...at].filter(t => e.homeTokens.has(t)).length;

    const direct = sameHome && sameAway ? sameHome + sameAway : 0;
    const reverse = crossHome && crossAway ? crossHome + crossAway : 0;
    const score = Math.max(direct, reverse);
    if (score > bestScore) {
      bestScore = score;
      best = e;
      swap = reverse > direct;
    }
  }
  if (!best) return null;
  return {
    homeScore: swap ? best.awayScore : best.homeScore,
    awayScore: swap ? best.homeScore : best.awayScore,
    clock: best.clock,
  };
}

function formatGame(sport, g, score) {
  const bm = g.bookmakers?.find(b => b.key === 'pinnacle' || b.key === 'ps3838') || g.bookmakers?.[0];
  if (!bm) return null;
  const markets = Object.fromEntries((bm.markets || []).map(m => [m.key, m]));
  const h2h = markets.h2h;
  if (!h2h || !(h2h.outcomes || []).length) return null;
  if (!g.home_team?.trim() || !g.away_team?.trim()) return null;
  if (LEAGUE_FILTER && !(g.league || '').toLowerCase().includes(LEAGUE_FILTER)) return null;

  const outcomes = h2h.outcomes;
  const homeO = outcomes.find(o => o.name === g.home_team);
  const awayO = outcomes.find(o => o.name === g.away_team);
  const draw = outcomes.find(o => o.name === 'Draw');

  const lines = [];
  const isLive = g.status === 'live';
  const scoreStr = score
    ? ` ${C.bold}${C.yellow}${score.homeScore ?? 0}:${score.awayScore ?? 0}${C.reset}${score.clock ? ` ${C.dim}${score.clock}${C.reset}` : ''}`
    : '';
  const league = g.league ? ` ${C.magenta}[${g.league}]${C.reset}` : '';

  lines.push(`${C.cyan}[${sport.toUpperCase()}]${C.reset}${league} ${fmtKickoff(g.commence_time, isLive)}`);
  lines.push(`  ${C.bold}${g.home_team}${C.reset} ${C.dim}vs${C.reset} ${C.bold}${g.away_team}${C.reset}${scoreStr}`);

  const p1 = homeO ? fmtPrice(homeO.price) : fmtPrice(null);
  const p2 = awayO ? fmtPrice(awayO.price) : fmtPrice(null);
  if (draw) {
    const px = fmtPrice(draw.price);
    lines.push(`    ${C.dim}П1${C.reset} ${p1}   ${C.dim}Х${C.reset} ${px}   ${C.dim}П2${C.reset} ${p2}`);
  } else {
    lines.push(`    ${C.dim}П1${C.reset} ${p1}   ${C.dim}П2${C.reset} ${p2}`);
  }

  if (SHOW_ALL_MARKETS) {
    if (markets.spreads) {
      const seg = (markets.spreads.outcomes || [])
        .map(x => `${x.name} ${x.point > 0 ? '+' : ''}${x.point} ${fmtPrice(x.price)}`)
        .join(' | ');
      lines.push(`    ${C.dim}spreads${C.reset} ${seg}`);
    }
    if (markets.totals) {
      const seg = (markets.totals.outcomes || [])
        .map(x => `${x.name} ${x.point} ${fmtPrice(x.price)}`)
        .join(' | ');
      lines.push(`    ${C.dim}totals ${C.reset} ${seg}`);
    }
  }
  return lines.join('\n');
}

async function tick() {
  const results = await Promise.allSettled(SPORTS.map(async s => [s, await fetchLive(s)]));
  process.stdout.write('\x1b[2J\x1b[H');

  const ts = new Date().toISOString();
  const mode = LIVE_ONLY ? `${C.red}LIVE${C.reset}` : 'all';
  console.log(`${C.dim}Owls Insight — Pinnacle/PS3838 odds | ${ts} | mode: ${mode}${C.dim} | sports: ${SPORTS.join(', ')} | refresh ${INTERVAL_MS / 1000}s${C.reset}\n`);

  let total = 0, errors = 0;
  const all = [];
  for (const r of results) {
    if (r.status === 'rejected') { console.log(`${C.red}! ${r.reason.message}${C.reset}`); errors++; continue; }
    const [sport, { games, liveEvents }] = r.value;
    const liveIdx = buildLiveIndex(liveEvents);
    for (const g of games) {
      if (LIVE_ONLY && g.status !== 'live') continue;
      const date = (g.commence_time || '').slice(0, 10);
      const score = findScore(liveIdx, g.home_team, g.away_team, date);
      all.push({ sport, g, score });
    }
  }

  all.sort((x, y) => {
    const lx = (x.g.league || 'zzz').localeCompare(y.g.league || 'zzz');
    if (lx) return lx;
    return new Date(x.g.commence_time) - new Date(y.g.commence_time);
  });

  for (const { sport, g, score } of all) {
    const block = formatGame(sport, g, score);
    if (!block) continue;
    total++;
    console.log(block + '\n');
  }

  console.log(`${C.dim}— ${total} matches, ${errors} errors, next refresh in ${INTERVAL_MS / 1000}s —${C.reset}`);
}

async function loop() {
  try { await tick(); }
  catch (e) { console.error(`${C.red}tick failed:${C.reset} ${e.message}`); }
  setTimeout(loop, INTERVAL_MS);
}

console.log(`${C.dim}Starting live odds stream… Ctrl+C to quit.${C.reset}`);
loop();
