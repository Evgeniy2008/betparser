/**
 * Pinnacle Live Worker (OwlsInsight PS3838 feed)
 *
 * Polls https://api.owlsinsight.com every POLL_MS ms for live Pinnacle/PS3838
 * odds across Football (soccer), Basketball (nba) and Tennis (tennis) and
 * writes the same output shape the old Arcadia worker used, so the existing
 * merge logic with Parik24 keeps working unchanged.
 *
 * Output files (unchanged paths):
 *   data/pinnacle_raw.json
 *   data/pinnacle_basketball_raw.json
 *   data/pinnacle_tennis_raw.json
 *
 * Required env (.env):
 *   OWLSINSIGHT_API_KEY=...
 * Optional:
 *   BETPARSER_PUSH_URL, BETPARSER_PUSH_TOKEN — forward every snapshot remotely
 */

'use strict';

const fs   = require('fs');
const path = require('path');

// ── .env loader (no dotenv dep) ───────────────────────────────────────────
(function loadEnv() {
  const envPath = path.resolve(__dirname, '../.env');
  if (!fs.existsSync(envPath)) return;
  for (const line of fs.readFileSync(envPath, 'utf8').split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/);
    if (m && !process.env[m[1]]) process.env[m[1]] = m[2].replace(/^["']|["']$/g, '');
  }
})();

const API_BASE   = 'https://api.owlsinsight.com';
const API_KEY    = String(process.env.OWLSINSIGHT_API_KEY || '').trim();
const PUSH_URL   = String(process.env.BETPARSER_PUSH_URL   || '').trim();
const PUSH_TOKEN = String(process.env.BETPARSER_PUSH_TOKEN || '').trim();

if (!API_KEY) {
  console.error('Missing OWLSINSIGHT_API_KEY. Put it in .env (at project root).');
  process.exit(1);
}

const POLL_MS        = 5000;
const MAX_BACKOFF_MS = 60000;

// Map: owlsinsight sport id  →  our internal sport naming + output file
const SPORTS = [
  { key: 'soccer', name: 'football',   hasDraw: true,  outFile: path.resolve(__dirname, '../data/pinnacle_raw.json')            },
  { key: 'nba',    name: 'basketball', hasDraw: false, outFile: path.resolve(__dirname, '../data/pinnacle_basketball_raw.json') },
  { key: 'tennis', name: 'tennis',     hasDraw: false, outFile: path.resolve(__dirname, '../data/pinnacle_tennis_raw.json')     },
];

const state = {};
for (const s of SPORTS) state[s.key] = { consecutiveErrors: 0, lastGoodOutput: null, inFlight: false, timer: null };

function log(...args) {
  console.log(new Date().toISOString().replace('T', ' ').slice(0, 19), ...args);
}

// ── Odds conversion ───────────────────────────────────────────────────────
function americanToDecimal(value) {
  const american = Number(value);
  if (!Number.isFinite(american) || american === 0) return null;
  const decimal = american > 0 ? (1 + american / 100) : (1 - 100 / american);
  return Number(decimal.toFixed(3));
}

// ── HTTP helpers ──────────────────────────────────────────────────────────
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function fetchJsonOnce(url) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 20000);
  try {
    const res = await fetch(url, {
      method: 'GET',
      signal: controller.signal,
      headers: {
        'authorization': `Bearer ${API_KEY}`,
        'accept': 'application/json',
        'user-agent': 'Betparser/1.0 (owlsinsight-client)',
      },
    });
    if (!res.ok) {
      const body = await res.text().catch(() => '');
      const err = new Error(`HTTP ${res.status} – ${url.replace(API_BASE, '')} ${body.slice(0, 200)}`);
      err.httpStatus = res.status;
      throw err;
    }
    const json = await res.json();
    if (json && json.success === false) throw new Error(`API error: ${JSON.stringify(json).slice(0, 200)}`);
    return json;
  } finally {
    clearTimeout(timeout);
  }
}

async function fetchJson(url, retries = 2) {
  for (let attempt = 0; ; attempt++) {
    try { return await fetchJsonOnce(url); }
    catch (err) {
      const retriable = err.httpStatus === 429 || (err.httpStatus >= 500 && err.httpStatus < 600) || err.code === 'ECONNRESET' || err.code === 'UND_ERR_CONNECT_TIMEOUT' || err.name === 'AbortError';
      if (retriable && attempt < retries) { await sleep(400 * (attempt + 1) + Math.random() * 300); continue; }
      throw err;
    }
  }
}

// ── Live scores indexing (team name tokens → home/away score + clock) ────
function normTeam(s) {
  return String(s || '')
    .toLowerCase()
    .replace(/[.,'()·•]/g, '')
    .replace(/\bfc\b|\bcf\b|\bsc\b|\bac\b|\bfk\b|\bsk\b/g, '')
    .replace(/\s+/g, ' ')
    .trim();
}
function toTokens(s) {
  return new Set(normTeam(s).split(' ').filter((w) => w.length >= 3));
}

function buildLiveIndex(liveEvents) {
  const byDate = new Map();
  for (const e of liveEvents || []) {
    if (e?.status?.state !== 'in') continue;
    const homeName = e?.home?.team?.displayName;
    const awayName = e?.away?.team?.displayName;
    if (!homeName || !awayName) continue;
    const date = String(e.startTime || '').slice(0, 10);
    const entry = {
      homeName, awayName,
      homeTokens: toTokens(homeName),
      awayTokens: toTokens(awayName),
      homeScore: e.home?.score,
      awayScore: e.away?.score,
      clock: e?.status?.displayClock || e?.status?.detail || '',
      periodDescription: e?.status?.type?.shortDetail || e?.status?.type?.detail || '',
    };
    if (!byDate.has(date)) byDate.set(date, []);
    byDate.get(date).push(entry);
  }
  return byDate;
}

function findLiveEntry(liveIndex, homeTeam, awayTeam, date) {
  const ht = toTokens(homeTeam);
  const at = toTokens(awayTeam);
  if (!ht.size || !at.size) return null;

  const dates = date ? [date] : [...liveIndex.keys()];
  let best = null, bestScore = 0, swap = false;
  for (const d of dates) {
    for (const e of liveIndex.get(d) || []) {
      const sameHome  = [...ht].filter((t) => e.homeTokens.has(t)).length;
      const sameAway  = [...at].filter((t) => e.awayTokens.has(t)).length;
      const crossHome = [...ht].filter((t) => e.awayTokens.has(t)).length;
      const crossAway = [...at].filter((t) => e.homeTokens.has(t)).length;
      const direct  = sameHome && sameAway ? sameHome + sameAway : 0;
      const reverse = crossHome && crossAway ? crossHome + crossAway : 0;
      const score = Math.max(direct, reverse);
      if (score > bestScore) { bestScore = score; best = e; swap = reverse > direct; }
    }
  }
  if (!best) return null;
  return {
    homeScore: swap ? best.awayScore : best.homeScore,
    awayScore: swap ? best.homeScore : best.awayScore,
    clock: best.clock,
    periodDescription: best.periodDescription,
  };
}

// ── Extract Pinnacle/PS3838 h2h market from a game ────────────────────────
function pickPinnacleH2H(game) {
  if (!game || !Array.isArray(game.bookmakers)) return null;
  const bm = game.bookmakers.find((b) => b?.key === 'pinnacle' || b?.key === 'ps3838')
          || game.bookmakers[0];
  if (!bm || !Array.isArray(bm.markets)) return null;
  const h2h = bm.markets.find((m) => m?.key === 'h2h');
  if (!h2h || !Array.isArray(h2h.outcomes) || !h2h.outcomes.length) return null;
  return h2h;
}

function outcomeByName(outcomes, name) {
  if (!Array.isArray(outcomes)) return null;
  const target = String(name || '').trim().toLowerCase();
  if (!target) return null;
  return outcomes.find((o) => String(o?.name || '').trim().toLowerCase() === target) || null;
}

// ── Sport-specific score formatting ───────────────────────────────────────
function formatScore(sportName, entry) {
  if (!entry) return '';
  const h = entry.homeScore, a = entry.awayScore;
  if (h == null || a == null) return '';
  return `${h}-${a}`;
}

function formatElapsed(sportName, entry) {
  if (!entry) return null;
  const clock = (entry.clock || '').trim();
  const period = (entry.periodDescription || '').trim();
  // Prefer precise clock value; fall back to period description, else null.
  if (clock) return clock;
  if (period) return period;
  return null;
}

// ── Build a unified match row from game + live score entry ────────────────
function buildRow(sport, game, liveEntry) {
  const h2h = pickPinnacleH2H(game);
  if (!h2h) return null;

  const home = String(game.home_team || '').trim();
  const away = String(game.away_team || '').trim();
  if (!home || !away) return null;

  const homeO = outcomeByName(h2h.outcomes, home);
  const awayO = outcomeByName(h2h.outcomes, away);
  const drawO = sport.hasDraw ? outcomeByName(h2h.outcomes, 'Draw') : null;

  const p1 = homeO ? americanToDecimal(homeO.price) : null;
  const p2 = awayO ? americanToDecimal(awayO.price) : null;
  const x  = drawO ? americanToDecimal(drawO.price) : null;

  return {
    eventId: String(game.id ?? game.gameId ?? game.uuid ?? `${home}|${away}|${game.commence_time ?? ''}`),
    home,
    away,
    league: String(game.league || ''),
    sport:  sport.name,
    elapsed: formatElapsed(sport.name, liveEntry),
    status: String(game.status || ''),
    score: formatScore(sport.name, liveEntry),
    link: '',
    p1,
    x:  sport.hasDraw ? x : null,
    p2,
    time: String(game.commence_time || ''),
  };
}

// ── Merge two raw feeds (pinnacle /realtime + /ps3838-realtime) by event id.
// Same event can appear on both feeds (top-tier games sometimes do). Prefer
// the variant that has an h2h market on its bookmaker; otherwise keep the
// first occurrence.
function mergeOddsFeeds(primary, secondary) {
  const byId = new Map();
  const keyOf = (g) => String(g?.id ?? g?.gameId ?? g?.uuid ?? `${g?.home_team || ''}|${g?.away_team || ''}|${g?.commence_time || ''}`);
  const hasH2h = (g) => {
    if (!g || !Array.isArray(g.bookmakers)) return false;
    const bm = g.bookmakers.find((b) => b?.key === 'pinnacle' || b?.key === 'ps3838') || g.bookmakers[0];
    if (!bm || !Array.isArray(bm.markets)) return false;
    return bm.markets.some((m) => m?.key === 'h2h' && Array.isArray(m.outcomes) && m.outcomes.length);
  };
  for (const g of [...(primary || []), ...(secondary || [])]) {
    if (!g) continue;
    const k = keyOf(g);
    const prev = byId.get(k);
    if (!prev) { byId.set(k, g); continue; }
    if (!hasH2h(prev) && hasH2h(g)) byId.set(k, g);
  }
  return [...byId.values()];
}

// ── Dedup: prefer row that actually has odds ──────────────────────────────
function dedupeRows(rows) {
  const byKey = new Map();
  for (const r of rows) {
    const key = `${r.league}|${r.home}|${r.away}`.toLowerCase();
    const prev = byKey.get(key);
    if (!prev) { byKey.set(key, r); continue; }
    const prevHas = prev.p1 != null || prev.p2 != null;
    const currHas = r.p1 != null || r.p2 != null;
    if (currHas && !prevHas) byKey.set(key, r);
  }
  return [...byKey.values()];
}

// ── Remote push (unchanged contract) ──────────────────────────────────────
async function pushOutputRemote(feed, payload) {
  if (!PUSH_URL) return;
  try {
    const res = await fetch(PUSH_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        ...(PUSH_TOKEN ? { 'x-betparser-token': PUSH_TOKEN } : {}),
      },
      body: JSON.stringify({ feed, payload }),
    });
    if (!res.ok) {
      const body = await res.text().catch(() => '');
      log(`[${feed}] remote push failed: HTTP ${res.status} ${body.slice(0, 180)}`);
    }
  } catch (err) {
    log(`[${feed}] remote push error: ${err.message}`);
  }
}

function writeOutput(payload, outFile) {
  fs.writeFileSync(outFile, JSON.stringify(payload, null, 2), 'utf8');
}

// ── One poll cycle per sport ──────────────────────────────────────────────
async function cycleSport(sport) {
  const st = state[sport.key];
  if (st.inFlight) return;
  st.inFlight = true;
  try {
    const t0 = Date.now();

    // Fetch BOTH Pinnacle feeds:
    //   /realtime         – top-tier coverage (EPL, La Liga, Bundesliga, Serie A,
    //                       Ligue 1, MLS, Champions League…). Source = "pinnacle".
    //   /ps3838-realtime  – wider second/third-tier coverage (women, U-19, lower
    //                       divisions). Source = "ps3838".
    // Same payload shape; we merge by event id and prefer a row that carries an
    // h2h market.
    const [pinJson, psJson, scoresJson] = await Promise.all([
      fetchJson(`${API_BASE}/api/v1/${sport.key}/realtime`).catch((err) => {
        log(`[${sport.name}] realtime warn: ${err.message}`);
        return { data: [] };
      }),
      fetchJson(`${API_BASE}/api/v1/${sport.key}/ps3838-realtime`).catch((err) => {
        log(`[${sport.name}] ps3838-realtime warn: ${err.message}`);
        return { data: [] };
      }),
      fetchJson(`${API_BASE}/api/v1/${sport.key}/scores/live`).catch((err) => {
        log(`[${sport.name}] scores/live warn: ${err.message}`);
        return { events: [] };
      }),
    ]);

    const pinGames = Array.isArray(pinJson?.data) ? pinJson.data : [];
    const psGames  = Array.isArray(psJson?.data)  ? psJson.data  : [];
    const games    = mergeOddsFeeds(pinGames, psGames);
    const liveEvents = Array.isArray(scoresJson?.events) ? scoresJson.events : [];
    const liveIdx = buildLiveIndex(liveEvents);

    const rows = [];
    for (const g of games) {
      if (g?.status !== 'live') continue;
      const date = String(g.commence_time || '').slice(0, 10);
      const liveEntry = findLiveEntry(liveIdx, g.home_team, g.away_team, date);
      const row = buildRow(sport, g, liveEntry);
      if (row) rows.push(row);
    }

    const result = dedupeRows(rows).sort((a, b) => {
      const aHas = (a.p1 != null || a.p2 != null) ? 1 : 0;
      const bHas = (b.p1 != null || b.p2 != null) ? 1 : 0;
      if (aHas !== bHas) return bHas - aHas;
      return (a.league || '').localeCompare(b.league || '');
    });

    const output = {
      updated: new Date().toISOString(),
      source:  `pinnacle-${sport.name}-live-owlsinsight`,
      total:   result.length,
      matches: result,
    };

    st.lastGoodOutput = output;
    st.consecutiveErrors = 0;
    writeOutput(output, sport.outFile);
    await pushOutputRemote(`pinnacle_${sport.name}`, output);
    log(`[${sport.name}] ${output.total} live matches (${Date.now() - t0}ms)`);
  } catch (err) {
    st.consecutiveErrors += 1;
    const backoff = Math.min(POLL_MS * (2 ** Math.min(st.consecutiveErrors, 4)), MAX_BACKOFF_MS);
    log(`[${sport.name}] error #${st.consecutiveErrors}: ${err.message}`);
    if (st.lastGoodOutput) {
      const baseSource = st.lastGoodOutput.source.replace(/-stale$/, '');
      const stale = { ...st.lastGoodOutput, updated: new Date().toISOString(), source: `${baseSource}-stale` };
      writeOutput(stale, sport.outFile);
      await pushOutputRemote(`pinnacle_${sport.name}`, stale);
      log(`[${sport.name}] stale fallback, retry in ${backoff}ms`);
    }
    if (st.timer) { clearInterval(st.timer); st.timer = null; }
    setTimeout(() => {
      if (!st.timer) st.timer = setInterval(() => cycleSport(sport), POLL_MS);
      cycleSport(sport);
    }, backoff);
  } finally {
    st.inFlight = false;
  }
}

// ── Entry ─────────────────────────────────────────────────────────────────
async function start() {
  log('Pinnacle Live Worker (owlsinsight) starting');
  log(`Poll interval: ${POLL_MS}ms | Sports: ${SPORTS.map((s) => `${s.name}=${s.key}`).join(', ')}`);
  for (const sport of SPORTS) {
    if (fs.existsSync(sport.outFile)) {
      try {
        const existing = JSON.parse(fs.readFileSync(sport.outFile, 'utf8'));
        if (existing && Array.isArray(existing.matches)) {
          state[sport.key].lastGoodOutput = existing;
          log(`[${sport.name}] loaded snapshot: ${existing.matches.length} matches`);
        }
      } catch (e) { log(`[${sport.name}] snapshot load error:`, e.message); }
    }
  }
  for (let i = 0; i < SPORTS.length; i++) {
    const sport = SPORTS[i];
    setTimeout(async () => {
      await cycleSport(sport);
      state[sport.key].timer = setInterval(() => cycleSport(sport), POLL_MS);
    }, i * 800);
  }
}

process.on('SIGINT', () => {
  for (const s of SPORTS) if (state[s.key].timer) clearInterval(state[s.key].timer);
  log('stopped');
  process.exit(0);
});

start();
