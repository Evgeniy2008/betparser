/**
 * Pinnacle Live Worker – Multi-Sport
 *
 * Polls Pinnacle Arcadia guest API for live matchups + moneyline markets.
 * Handles Football (29), Basketball (4), and Tennis (33) in one process.
 *
 * Output files:
 *   data/pinnacle_raw.json
 *   data/pinnacle_basketball_raw.json
 *   data/pinnacle_tennis_raw.json
 *
 * Run: node tools/pinnacle_live_worker.js
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const API_BASE = 'https://guest.api.arcadia.pinnacle.com/0.1';
const API_KEY  = 'CmX2KcMrXuFmNg6YFbmTxE0y9CIrOi0R';
const PUSH_URL = String(process.env.BETPARSER_PUSH_URL || 'https://websitebets.bionrgg.com/push_live_data.php').trim();
const PUSH_TOKEN = String(process.env.BETPARSER_PUSH_TOKEN || '').trim();

const POLL_MS        = 8000;
const MAX_BACKOFF_MS = 60000;

const SPORTS = [
  { id: 29, name: 'football',   hasDraw: true,  outFile: path.resolve(__dirname, '../data/pinnacle_raw.json') },
  { id: 4,  name: 'basketball', hasDraw: false, outFile: path.resolve(__dirname, '../data/pinnacle_basketball_raw.json') },
  { id: 33, name: 'tennis',     hasDraw: false, outFile: path.resolve(__dirname, '../data/pinnacle_tennis_raw.json') },
];

const sportState = {};
for (const sport of SPORTS) {
  sportState[sport.id] = { consecutiveErrors: 0, lastGoodOutput: null, inFlight: false, timer: null };
}

function log(...args) {
  console.log(new Date().toISOString().replace('T', ' ').slice(0, 19), ...args);
}

function americanToDecimal(value) {
  const american = Number(value);
  if (!Number.isFinite(american) || american === 0) return null;
  const decimal = american > 0 ? (1 + american / 100) : (1 + 100 / Math.abs(american));
  return Number(decimal.toFixed(3));
}

function extractTeams(matchup) {
  const participants = Array.isArray(matchup?.participants) ? matchup.participants : [];
  const home = participants.find((p) => p?.alignment === 'home');
  const away = participants.find((p) => p?.alignment === 'away');
  const homeName = String(home?.name ?? '').trim();
  const awayName = String(away?.name ?? '').trim();
  if (!homeName || !awayName) return null;
  const homeScore = Number.isFinite(Number(home?.state?.score)) ? Number(home.state.score) : null;
  const awayScore = Number.isFinite(Number(away?.state?.score)) ? Number(away.state.score) : null;
  return { home: homeName, away: awayName, score: homeScore !== null && awayScore !== null ? `${homeScore}-${awayScore}` : '' };
}

function pickMainMoneylineMarket(markets, matchupId) {
  const rows = markets.filter((m) => Number(m?.matchupId) === Number(matchupId) && m?.type === 'moneyline' && Number(m?.period) === 0);
  if (!rows.length) return null;
  return rows.find((m) => m?.status === 'open' && m?.isAlternate === false) ?? rows.find((m) => m?.status === 'open') ?? rows[0];
}

function priceByDesignation(market, key) {
  const prices = Array.isArray(market?.prices) ? market.prices : [];
  const row = prices.find((p) => p?.designation === key);
  if (!row || !Number.isFinite(Number(row.price))) return null;
  return Number(row.price);
}

async function fetchJson(url) {
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      'accept': 'application/json',
      'x-api-key': API_KEY,
      'origin': 'https://www.pinnacle.com',
      'referer': 'https://www.pinnacle.com/en/soccer/matchups/live/',
      'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    },
  });
  if (!response.ok) {
    const body = await response.text().catch(() => '');
    throw new Error(`HTTP ${response.status} for ${url}. ${body.slice(0, 200)}`);
  }
  return response.json();
}

function buildOutput(matchups, markets, sportName, hasDraw) {
  const liveMatchups = (Array.isArray(matchups) ? matchups : []).filter((m) => m && m.type === 'matchup' && m.isLive === true);
  const deduped = new Map();

  for (const matchup of liveMatchups) {
    const teams = extractTeams(matchup);
    if (!teams) continue;
    const market = pickMainMoneylineMarket(markets, matchup.id);
    if (!market) continue;

    const p1American = priceByDesignation(market, 'home');
    const xAmerican  = hasDraw ? priceByDesignation(market, 'draw') : null;
    const p2American = priceByDesignation(market, 'away');

    const row = {
      eventId: String(matchup.id),
      home: teams.home, away: teams.away,
      league: String(matchup?.league?.name ?? ''),
      sport: sportName,
      elapsed: matchup?.state?.minutes ?? null,
      status: String(matchup?.status ?? ''),
      score: teams.score, link: '',
      p1: americanToDecimal(p1American),
      x:  hasDraw ? americanToDecimal(xAmerican) : null,
      p2: americanToDecimal(p2American),
      time: String(matchup?.startTime ?? ''),
    };

    const dedupeKey = String(matchup?.parentId ?? `${row.league}|${row.home}|${row.away}`);
    const prev = deduped.get(dedupeKey);
    if (!prev || Number(row.elapsed ?? 0) >= Number(prev.elapsed ?? 0)) {
      deduped.set(dedupeKey, row);
    }
  }

  const result = [...deduped.values()].sort((a, b) => Number(b.elapsed ?? 0) - Number(a.elapsed ?? 0));
  return { updated: new Date().toISOString(), source: `pinnacle-${sportName}-live-arcadia`, total: result.length, matches: result };
}

function writeOutput(payload, outFile) {
  fs.writeFileSync(outFile, JSON.stringify(payload, null, 2), 'utf8');
}

async function pushOutputRemote(feed, payload) {
  if (!PUSH_URL) return;
  try {
    const response = await fetch(PUSH_URL, {
      method: 'POST',
      headers: {
        'content-type': 'application/json',
        ...(PUSH_TOKEN ? { 'x-betparser-token': PUSH_TOKEN } : {}),
      },
      body: JSON.stringify({ feed, payload }),
    });

    if (!response.ok) {
      const body = await response.text().catch(() => '');
      log(`[${feed}] remote push failed: HTTP ${response.status} ${body.slice(0, 180)}`);
    }
  } catch (err) {
    log(`[${feed}] remote push error: ${err.message}`);
  }
}

async function cycleSport(sport) {
  const state = sportState[sport.id];
  if (state.inFlight) return;
  state.inFlight = true;
  try {
    const [matchups, markets] = await Promise.all([
      fetchJson(`${API_BASE}/sports/${sport.id}/matchups`),
      fetchJson(`${API_BASE}/sports/${sport.id}/markets/straight`),
    ]);
    const output = buildOutput(matchups, markets, sport.name, sport.hasDraw);
    state.lastGoodOutput = output;
    state.consecutiveErrors = 0;
    writeOutput(output, sport.outFile);
    await pushOutputRemote(`pinnacle_${sport.name}`, output);
    log(`[${sport.name}] updated ${output.total} live matches`);
  } catch (err) {
    state.consecutiveErrors += 1;
    const backoff = Math.min(POLL_MS * (2 ** Math.min(state.consecutiveErrors, 4)), MAX_BACKOFF_MS);
    log(`[${sport.name}] error #${state.consecutiveErrors}: ${err.message}`);
    if (state.lastGoodOutput) {
      const baseSource = state.lastGoodOutput.source.replace(/-stale$/, '');
      const stalePayload = { ...state.lastGoodOutput, updated: new Date().toISOString(), source: `${baseSource}-stale` };
      writeOutput(stalePayload, sport.outFile);
      await pushOutputRemote(`pinnacle_${sport.name}`, stalePayload);
      log(`[${sport.name}] stale fallback, retry in ${backoff}ms`);
    }
    if (state.timer) { clearInterval(state.timer); state.timer = null; }
    setTimeout(() => {
      if (!state.timer) state.timer = setInterval(() => cycleSport(sport), POLL_MS);
      cycleSport(sport);
    }, backoff);
  } finally {
    state.inFlight = false;
  }
}

async function start() {
  log('Pinnacle Multi-Sport Live Worker starting');
  for (const sport of SPORTS) {
    if (fs.existsSync(sport.outFile)) {
      try {
        const existing = JSON.parse(fs.readFileSync(sport.outFile, 'utf8'));
        if (existing && Array.isArray(existing.matches)) {
          sportState[sport.id].lastGoodOutput = existing;
          log(`[${sport.name}] loaded snapshot: ${existing.matches.length} matches`);
        }
      } catch (e) { log(`[${sport.name}] snapshot load error:`, e.message); }
    }
  }
  for (let i = 0; i < SPORTS.length; i++) {
    const sport = SPORTS[i];
    setTimeout(async () => {
      await cycleSport(sport);
      sportState[sport.id].timer = setInterval(() => cycleSport(sport), POLL_MS);
    }, i * 800);
  }
}

process.on('SIGINT', () => {
  for (const sport of SPORTS) {
    if (sportState[sport.id].timer) clearInterval(sportState[sport.id].timer);
  }
  log('stopped');
  process.exit(0);
});

start();
