/**
 * Pinnacle Live Worker – Multi-Sport (v2)
 *
 * Polls Pinnacle Arcadia guest API for live matchups + moneyline markets.
 * Handles Football (29), Basketball (4), and Tennis (33) in one process.
 *
 * Key improvements over v1:
 *  - Fetches markets per-league instead of per-sport (18KB vs 18MB)
 *  - Filters out "(Games)" matchups in tennis/basketball
 *  - Extracts live scores from parent.participants[].state
 *  - Sport-specific score formatting (sets/games for tennis, quarters for basketball)
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
const PUSH_URL = String(process.env.BETPARSER_PUSH_URL || '').trim();
const PUSH_TOKEN = String(process.env.BETPARSER_PUSH_TOKEN || '').trim();

const POLL_MS        = 6000;   // poll every 6s for fresher odds
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

// ══════════════════════════════════════════════
//  American → Decimal odds conversion
// ══════════════════════════════════════════════
function americanToDecimal(value) {
  const american = Number(value);
  if (!Number.isFinite(american) || american === 0) return null;
  const decimal = american > 0
    ? (1 + american / 100)
    : (1 - 100 / american);      // equivalent to 1 + 100/|american|
  return Number(decimal.toFixed(3));
}

// ══════════════════════════════════════════════
//  Team / participant extraction
// ══════════════════════════════════════════════
function extractTeams(matchup) {
  const participants = Array.isArray(matchup?.participants) ? matchup.participants : [];
  const home = participants.find((p) => p?.alignment === 'home');
  const away = participants.find((p) => p?.alignment === 'away');
  const homeName = String(home?.name ?? '').trim();
  const awayName = String(away?.name ?? '').trim();
  if (!homeName || !awayName) return null;
  return { home: homeName, away: awayName };
}

// ══════════════════════════════════════════════
//  Score extraction from parent.participants[].state
// ══════════════════════════════════════════════
function extractScore(matchup, sportName) {
  const parent = matchup?.parent;
  if (!parent) return '';

  const parentParts = Array.isArray(parent.participants) ? parent.participants : [];
  const homeP = parentParts.find((p) => p?.alignment === 'home');
  const awayP = parentParts.find((p) => p?.alignment === 'away');
  if (!homeP?.state || !awayP?.state) return '';

  if (sportName === 'football') {
    // Football: parent.participants[].state.score
    const hs = homeP.state.score;
    const as = awayP.state.score;
    if (hs != null && as != null) return `${hs}-${as}`;
    return '';
  }

  if (sportName === 'tennis') {
    // Tennis: setsWon + gamesBySet array + current points
    const homeSets = homeP.state.setsWon ?? 0;
    const awaySets = awayP.state.setsWon ?? 0;
    const homeGames = Array.isArray(homeP.state.gamesBySet) ? homeP.state.gamesBySet : [];
    const awayGames = Array.isArray(awayP.state.gamesBySet) ? awayP.state.gamesBySet : [];
    const currentSet = (parent.state?.set ?? 1) - 1; // 0-indexed

    // Build "sets (games)" format: "1-0 (6-4, 3-2)"
    const setParts = [];
    for (let i = 0; i <= currentSet && i < homeGames.length; i++) {
      setParts.push(`${homeGames[i]}-${awayGames[i]}`);
    }
    const gamesStr = setParts.length > 0 ? ` (${setParts.join(', ')})` : '';
    return `${homeSets}-${awaySets}${gamesStr}`;
  }

  if (sportName === 'basketball') {
    // Basketball: parent.participants[].state.score (total points)
    const hs = homeP.state.score;
    const as = awayP.state.score;
    if (hs != null && as != null) return `${hs}-${as}`;
    // Fallback: try points per period
    const homePoints = Array.isArray(homeP.state.pointsByPeriod) ? homeP.state.pointsByPeriod : [];
    const awayPoints = Array.isArray(awayP.state.pointsByPeriod) ? awayP.state.pointsByPeriod : [];
    const hTotal = homePoints.reduce((s, v) => s + (v || 0), 0);
    const aTotal = awayPoints.reduce((s, v) => s + (v || 0), 0);
    if (hTotal > 0 || aTotal > 0) return `${hTotal}-${aTotal}`;
    return '';
  }

  return '';
}

// ══════════════════════════════════════════════
//  Elapsed / period extraction
// ══════════════════════════════════════════════
function extractElapsed(matchup, sportName) {
  const parent = matchup?.parent;

  if (sportName === 'football') {
    // Try state.minutes on the matchup itself or from parent
    const mins = matchup?.state?.minutes ?? parent?.state?.minutes ?? null;
    if (mins != null) return mins;
    // May not be available from this API – return null
    return null;
  }

  if (sportName === 'tennis') {
    // Return current set number as progress indicator
    const set = parent?.state?.set;
    return set != null ? `Set ${set}` : null;
  }

  if (sportName === 'basketball') {
    const period = parent?.state?.period ?? matchup?.state?.period;
    return period != null ? `Q${period}` : null;
  }

  return null;
}

// ══════════════════════════════════════════════
//  Filter out duplicate "(Games)" type matchups
// ══════════════════════════════════════════════
function isGamesMatchup(matchup) {
  // Tennis/Basketball have "(Games)" variants for game-level spreads
  if (matchup.units === 'Games') return true;
  const parts = Array.isArray(matchup.participants) ? matchup.participants : [];
  return parts.some((p) => /\(Games\)/i.test(p?.name ?? ''));
}

// ══════════════════════════════════════════════
//  Moneyline market picking
// ══════════════════════════════════════════════
function pickMainMoneylineMarket(markets, matchupId) {
  const rows = markets.filter((m) =>
    Number(m?.matchupId) === Number(matchupId) &&
    m?.type === 'moneyline' &&
    Number(m?.period) === 0
  );
  if (!rows.length) return null;
  return (
    rows.find((m) => m?.status === 'open' && m?.isAlternate === false) ??
    rows.find((m) => m?.status === 'open') ??
    rows[0]
  );
}

function priceByDesignation(market, key) {
  const prices = Array.isArray(market?.prices) ? market.prices : [];
  const row = prices.find((p) => p?.designation === key);
  if (!row || !Number.isFinite(Number(row.price))) return null;
  return Number(row.price);
}

// ══════════════════════════════════════════════
//  HTTP helpers
// ══════════════════════════════════════════════
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function fetchJsonOnce(url) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 25000);
  try {
    const response = await fetch(url, {
      method: 'GET',
      signal: controller.signal,
      headers: {
        'accept': 'application/json',
        'accept-encoding': 'gzip, deflate, br',
        'x-api-key': API_KEY,
        'origin': 'https://www.pinnacle.com',
        'referer': 'https://www.pinnacle.com/',
        'user-agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
      },
    });
    if (!response.ok) {
      const body = await response.text().catch(() => '');
      const err = new Error(`HTTP ${response.status} – ${url.split('0.1')[1] ?? url}  ${body.slice(0, 200)}`);
      err.httpStatus = response.status;
      throw err;
    }
    return response.json();
  } finally {
    clearTimeout(timeout);
  }
}

/** Fetch JSON with automatic retry on 403/429/5xx (up to `retries` attempts). */
async function fetchJson(url, retries = 2) {
  for (let attempt = 0; ; attempt++) {
    try {
      return await fetchJsonOnce(url);
    } catch (err) {
      const retriable = err.httpStatus === 403 || err.httpStatus === 429 || (err.httpStatus >= 500 && err.httpStatus < 600) || err.code === 'ECONNRESET' || err.code === 'UND_ERR_CONNECT_TIMEOUT';
      if (retriable && attempt < retries) {
        const delay = 400 * (attempt + 1) + Math.random() * 300;
        await sleep(delay);
        continue;
      }
      throw err;
    }
  }
}

// ══════════════════════════════════════════════
//  Build output from matchups + markets
// ══════════════════════════════════════════════
function buildOutput(matchups, markets, sportName, hasDraw) {
  const allMatchups = Array.isArray(matchups) ? matchups : [];

  // Step 1: filter live matchups, exclude "(Games)" types
  const liveMatchups = allMatchups.filter((m) =>
    m &&
    m.type === 'matchup' &&
    m.isLive === true &&
    !isGamesMatchup(m)
  );

  const deduped = new Map();

  for (const matchup of liveMatchups) {
    const teams = extractTeams(matchup);
    if (!teams) continue;

    const market = pickMainMoneylineMarket(markets, matchup.id);

    const p1American = market ? priceByDesignation(market, 'home') : null;
    const xAmerican  = (market && hasDraw) ? priceByDesignation(market, 'draw') : null;
    const p2American = market ? priceByDesignation(market, 'away') : null;

    const score = extractScore(matchup, sportName);
    const elapsed = extractElapsed(matchup, sportName);

    const row = {
      eventId: String(matchup.id),
      home: teams.home,
      away: teams.away,
      league: String(matchup?.league?.name ?? ''),
      sport: sportName,
      elapsed,
      status: String(matchup?.status ?? ''),
      score,
      link: '',
      p1: americanToDecimal(p1American),
      x:  hasDraw ? americanToDecimal(xAmerican) : null,
      p2: americanToDecimal(p2American),
      time: String(matchup?.startTime ?? ''),
    };

    // Deduplicate by parentId — keep entry with better data (has odds > no odds)
    const dedupeKey = String(matchup?.parentId ?? `${row.league}|${row.home}|${row.away}`);
    const prev = deduped.get(dedupeKey);
    if (!prev) {
      deduped.set(dedupeKey, row);
    } else {
      // Prefer entry that has odds
      const prevHasOdds = prev.p1 != null || prev.p2 != null;
      const currHasOdds = row.p1 != null || row.p2 != null;
      if (currHasOdds && !prevHasOdds) {
        deduped.set(dedupeKey, row);
      }
    }
  }

  const result = [...deduped.values()].sort((a, b) => {
    // Sort by: has odds first, then by league
    const aHas = (a.p1 != null || a.p2 != null) ? 1 : 0;
    const bHas = (b.p1 != null || b.p2 != null) ? 1 : 0;
    if (aHas !== bHas) return bHas - aHas;
    return (a.league ?? '').localeCompare(b.league ?? '');
  });

  return {
    updated: new Date().toISOString(),
    source: `pinnacle-${sportName}-live-arcadia`,
    total: result.length,
    matches: result,
  };
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

// ══════════════════════════════════════════════
//  Optimised two-phase poll cycle
//  Phase 1: fetch matchups → extract live league IDs
//  Phase 2: fetch markets only for live leagues (tiny payloads)
// ══════════════════════════════════════════════
async function cycleSport(sport) {
  const state = sportState[sport.id];
  if (state.inFlight) return;
  state.inFlight = true;
  try {
    // Phase 1 – matchups (full sport list, gzipped ~1-3 MB transfer)
    const t0 = Date.now();
    const matchups = await fetchJson(`${API_BASE}/sports/${sport.id}/matchups`);
    const matchupMs = Date.now() - t0;

    // Filter live matchups (excluding "(Games)" types)
    const liveMatchups = (Array.isArray(matchups) ? matchups : []).filter((m) =>
      m && m.type === 'matchup' && m.isLive === true && !isGamesMatchup(m)
    );

    if (liveMatchups.length === 0) {
      // No live matches — write empty output
      const output = {
        updated: new Date().toISOString(),
        source: `pinnacle-${sport.name}-live-arcadia`,
        total: 0,
        matches: [],
      };
      state.lastGoodOutput = output;
      state.consecutiveErrors = 0;
      writeOutput(output, sport.outFile);
      await pushOutputRemote(`pinnacle_${sport.name}`, output);
      log(`[${sport.name}] 0 live matches (matchups: ${matchupMs}ms)`);
      return;
    }

    // Phase 2 – fetch markets per live league (much smaller payloads).
    //   • Sequential with small delay to avoid CDN rate-limiting / 403.
    //   • On failure, fall back to full sport-level market endpoint.
    const liveLeagueIds = [...new Set(liveMatchups.map((m) => m.league?.id).filter(Boolean))];
    const t1 = Date.now();
    let markets = [];
    const failedLeagueIds = [];

    // Small batches (3 at a time) with inter-batch pause
    const BATCH_SIZE = 3;
    for (let i = 0; i < liveLeagueIds.length; i += BATCH_SIZE) {
      if (i > 0) await sleep(120); // brief pause between batches
      const batch = liveLeagueIds.slice(i, i + BATCH_SIZE);
      const results = await Promise.all(
        batch.map((leagueId) =>
          fetchJson(`${API_BASE}/leagues/${leagueId}/markets/straight`).catch((err) => {
            failedLeagueIds.push(leagueId);
            return null; // mark as failed
          })
        )
      );
      for (const r of results) {
        if (Array.isArray(r)) markets.push(...r);
      }
    }

    // Fallback: if any league markets failed, fetch the full sport-level markets
    if (failedLeagueIds.length > 0) {
      log(`[${sport.name}] ${failedLeagueIds.length} league(s) failed → fallback to sport-level markets`);
      try {
        const sportMarkets = await fetchJson(`${API_BASE}/sports/${sport.id}/markets/straight`);
        if (Array.isArray(sportMarkets)) {
          // Only add markets for matchups from the failed leagues (avoid duplicates)
          const failedMatchupIds = new Set(
            liveMatchups
              .filter((m) => failedLeagueIds.includes(m.league?.id))
              .map((m) => m.id)
          );
          const extra = sportMarkets.filter((m) => failedMatchupIds.has(m.matchupId));
          markets.push(...extra);
          log(`[${sport.name}] fallback recovered ${extra.length} market entries for ${failedMatchupIds.size} matchups`);
        }
      } catch (fbErr) {
        log(`[${sport.name}] sport-level fallback also failed: ${fbErr.message}`);
      }
    }
    const marketMs = Date.now() - t1;

    const output = buildOutput(matchups, markets, sport.name, sport.hasDraw);
    state.lastGoodOutput = output;
    state.consecutiveErrors = 0;
    writeOutput(output, sport.outFile);
    await pushOutputRemote(`pinnacle_${sport.name}`, output);
    log(`[${sport.name}] ${output.total} live matches | leagues: ${liveLeagueIds.length} | matchups: ${matchupMs}ms, markets: ${marketMs}ms`);
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
  log('Pinnacle Multi-Sport Live Worker v2 starting');
  log(`Poll interval: ${POLL_MS}ms | Sports: ${SPORTS.map((s) => s.name).join(', ')}`);
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
