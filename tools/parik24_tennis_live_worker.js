/**
 * Parik24 Live WebSocket Worker – Tennis
 *
 * Subscribes to live tennis events from Parik24 and writes to data/parik24_tennis_raw.json
 * Run: node tools/parik24_tennis_live_worker.js
 */

'use strict';

const WebSocket = require('ws');
const msgpack   = require('@msgpack/msgpack');
const fs        = require('fs');
const path      = require('path');

const WS_URL =
  'wss://24-parik.one/direct-feed/feed?brand=PRJ4&X-Api-Key=507aa81f-4c27-4e37-9410-21dfb81e9efe';

const HUB_CTX = ['en-PRJ4', 'MOBILE_WEB', 'PRJ4', '', 'USD'];

// Parik24 sport code for tennis
const SPORT_CODE = 'T';
const SPORT_NAME = 'tennis';
const SPORT_LINK_PREFIX = '/en/tennis/events/';

const OUT_FILE           = path.resolve(__dirname, '../data/parik24_tennis_raw.json');
const RECONNECT_DELAY_MS = 4000;
const PING_INTERVAL_MS   = 20000;
const MARKETS_REFRESH_MS = 5000;
const PUSH_URL           = String(process.env.BETPARSER_PUSH_URL || '').trim();
const PUSH_TOKEN         = String(process.env.BETPARSER_PUSH_TOKEN || '').trim();

const eventsMap = new Map();
const seenEventIds = new Set();
const pendingRecoveryEventIds = new Set();

let ws            = null;
let invIdCounter  = 0;
let handshakeDone = false;
let pingTimer     = null;
let marketsTimer  = null;

const pendingInv = new Map();

// ══ SignalR framing ══════════════════════════
function writeVarInt(n) {
  const b = [];
  do {
    let x = n & 0x7f;
    n >>>= 7;
    if (n > 0) x |= 0x80;
    b.push(x);
  } while (n > 0);
  return Buffer.from(b);
}

function readVarInt(buf, start) {
  let result = 0, shift = 0, pos = start || 0;
  while (pos < buf.length) {
    const b = buf[pos++];
    result |= (b & 0x7f) << shift;
    shift += 7;
    if ((b & 0x80) === 0) break;
  }
  return { value: result, nextPos: pos };
}

function encodeStreamInv(target, args) {
  const id      = String(++invIdCounter);
  const payload = Buffer.from(msgpack.encode([4, {}, id, target, args]));
  const frame   = Buffer.concat([writeVarInt(payload.length), payload]);
  pendingInv.set(id, target);
  return frame;
}

function encodePing() {
  const payload = Buffer.from(msgpack.encode([6]));
  return Buffer.concat([writeVarInt(payload.length), payload]);
}

function* decodeBuffer(buf) {
  let pos = 0;
  while (pos < buf.length) {
    const { value: len, nextPos } = readVarInt(buf, pos);
    if (len === 0) { pos = nextPos; continue; }
    if (nextPos + len > buf.length) break;
    try { yield msgpack.decode(buf.slice(nextPos, nextPos + len)); } catch { /* skip */ }
    pos = nextPos + len;
  }
}

// ══ Commands ════════════════════════════════
function subscribeEvents() {
  ws.send(encodeStreamInv('GetLiveEventsBySport', [SPORT_CODE, HUB_CTX]));
  log(`→ GetLiveEventsBySport [${SPORT_CODE}] subscribed`);
}

function requestMarkets(batchEventIds) {
  if (!batchEventIds.length) return;
  for (let i = 0; i < batchEventIds.length; i += 20) {
    const batch = batchEventIds.slice(i, i + 20);
    ws.send(encodeStreamInv('GetMainMarketsByProfileAndEventIds', [
      'pro_main_period', batch, 4, 1, HUB_CTX,
    ]));
  }
}

function requestRichEvents(batchEventIds) {
  if (!batchEventIds.length) return;
  for (let i = 0; i < batchEventIds.length; i += 20) {
    const batch = batchEventIds.slice(i, i + 20);
    ws.send(encodeStreamInv('GetRichEventsByIds', [batch, HUB_CTX]));
  }
}

// ══ Message handler ══════════════════════════
function handleMessage(raw) {
  const buf = Buffer.isBuffer(raw) ? raw : Buffer.from(raw);

  if (buf[0] === 0x7b || buf[0] === 0x5b || buf[buf.length - 1] === 0x1e) {
    const text = buf.toString('utf8').replace(/\x1e/g, '').trim();
    if (text && (text[0] === '{' || text[0] === '[')) {
      try {
        const json = JSON.parse(text);
        if (json.error) { log('✗ Handshake error:', json.error); return; }
        if (!handshakeDone) {
          handshakeDone = true;
          log('✓ Handshake OK');
          subscribeEvents();
        }
        return;
      } catch { /* fall through */ }
    }
  }

  if (!handshakeDone) return;

  for (const msg of decodeBuffer(buf)) {
    if (!Array.isArray(msg) || msg.length < 1) continue;
    const type = msg[0];
    if (type === 6) continue;
    if (type === 2) {
      const invId  = String(msg[2]);
      const item   = msg[3];
      const target = pendingInv.get(invId);
      processStreamItem(target, item, invId);
    }
  }
}

// ══ StreamItem processors ═══════════════════
function processStreamItem(target, item, invId) {
  if (!Array.isArray(item) || item.length < 2) return;
  const data = item[1];
  if (!Array.isArray(data)) return;

  if (target === 'GetLiveEventsBySport') {
    let changed = false;
    for (const ev of data) {
      if (parseEventTuple(ev, target)) changed = true;
    }
    if (changed) flushFile();
    return;
  }

  if (target === 'GetMainMarketsByProfileAndEventIds') {
    let changed = false;
    for (const mkt of data) {
      if (parseMarketTuple(mkt)) changed = true;
    }
    if (changed) flushFile();
    return;
  }

  if (target === 'GetRichEventsByIds') {
    let changed = false;
    for (const ev of data) {
      if (parseEventTuple(ev, target)) changed = true;
    }
    if (changed) flushFile();
    return;
  }

  log(`[stream invId=${invId} target=${target}] items=${data.length}`);
}

function normalizeTeamName(value) {
  return String(value ?? '').trim();
}

function isSuspiciousTeamName(value) {
  const name = normalizeTeamName(value);
  if (!name) return true;
  if (/\b(home|away)\s*cl\b/i.test(name)) return true;
  if (/[\p{L}\p{N})\]]\s*\+\s*[\p{L}\p{N}(\[]/u.test(name)) return true;
  if (/\(\s*\d+\s*[:\-]\s*\d+\s*\)\s*$/u.test(name)) return true;
  return false;
}

function isValidMatchTeams(home, away) {
  const homeName = normalizeTeamName(home);
  const awayName = normalizeTeamName(away);
  if (!homeName || !awayName) return false;
  if (isSuspiciousTeamName(homeName) || isSuspiciousTeamName(awayName)) return false;
  return true;
}

function extractLeagueName(data, fallback = '') {
  if (!Array.isArray(data)) return fallback;
  const country = String(data[19] ?? '').trim();
  const league = String(data[20] ?? '').trim();
  const value = [country, league].filter(Boolean).join(' - ');
  return value || fallback;
}

function parseEventTuple(ev, sourceTarget = '') {
  if (!Array.isArray(ev) || ev.length < 3) return false;
  const eventId = String(ev[1]);
  const d       = ev[2];
  seenEventIds.add(eventId);
  if (!Array.isArray(d)) return false;

  const teams = d[10];
  if (!Array.isArray(teams) || teams.length < 2) return false;
  if (!Array.isArray(teams[0]) || !Array.isArray(teams[1])) return false;

  const home = teams[0][1];
  const away = teams[1][1];
  if (typeof home !== 'string' || typeof away !== 'string') return false;
  if (!isValidMatchTeams(home, away)) {
    if (sourceTarget === 'GetLiveEventsBySport' && !pendingRecoveryEventIds.has(eventId)) {
      pendingRecoveryEventIds.add(eventId);
      log(`⚠ suspicious live card queued for rich recovery: eventId=${eventId}`);
    }
    return false;
  }

  if (pendingRecoveryEventIds.has(eventId)) {
    pendingRecoveryEventIds.delete(eventId);
    log(`✓ recovered event via rich details: eventId=${eventId} ${home} vs ${away}`);
  }

  const statusCode = d[5];
  const elapsed    = d[15] != null ? String(d[15]) : '';
  const scoreStr   = extractScore(d[11]);
  const slug       = d[17] ?? '';

  const existing = eventsMap.get(eventId) ?? {};
  eventsMap.set(eventId, {
    ...existing,
    eventId,
    home,
    away,
    league:     extractLeagueName(d, existing.league ?? ''),
    sport:      SPORT_NAME,
    statusCode,
    elapsed,
    score:      scoreStr || existing.score || '',
    link:       slug ? `${SPORT_LINK_PREFIX}${slug}` : '',
    time:       new Date().toISOString(),
    p1:         existing.p1 ?? null,
    x:          null, // no draw in tennis
    p2:         existing.p2 ?? null,
  });
  return true;
}

function extractScore(scoreBlock) {
  if (!Array.isArray(scoreBlock)) return '';
  const records = scoreBlock[3];
  if (!Array.isArray(records)) return '';
  for (const r of records) {
    if (Array.isArray(r) && r[0] === 1 && r[1] === 4 && typeof r[3] === 'string') return r[3];
  }
  for (const r of records) {
    if (Array.isArray(r) && r[0] === 1 && typeof r[3] === 'string') return r[3];
  }
  return '';
}

function parseMarketTuple(mkt) {
  if (!Array.isArray(mkt) || mkt.length < 3) return false;
  const evTuple    = mkt[1];
  const marketData = mkt[2];
  if (!Array.isArray(evTuple) || !Array.isArray(marketData)) return false;

  const eventId = String(evTuple[0]);
  const event   = eventsMap.get(eventId);
  if (!event) return false;

  const handicapGroupsList = marketData[0];
  if (!Array.isArray(handicapGroupsList) || !handicapGroupsList.length) return false;
  const firstGroup = handicapGroupsList[0];
  if (!Array.isArray(firstGroup) || firstGroup.length < 2) return false;
  const outcomes = firstGroup[1];
  if (!Array.isArray(outcomes)) return false;

  let p1 = null, p2 = null;

  for (const oc of outcomes) {
    if (!Array.isArray(oc)) continue;
    const ocHead = oc[0];
    const ocType = Array.isArray(ocHead) ? ocHead[0] : ocHead;
    const rawOdd = oc[1];
    if (typeof rawOdd !== 'number') continue;
    const odd = rawOdd / 100;
    if      (ocType === 0) p1 = odd;
    else if (ocType === 3) p2 = odd;
  }

  if (p1 !== null || p2 !== null) {
    eventsMap.set(eventId, {
      ...event,
      p1: p1 ?? event.p1,
      p2: p2 ?? event.p2,
    });
    return true;
  }
  return false;
}

// ══ File output ══════════════════════════════
function flushFile() {
  const liveEvents = [...eventsMap.values()].filter(e => e.home && e.away);
  const output = {
    updated: new Date().toISOString(),
    source:  `parik24-${SPORT_NAME}-live-ws`,
    total:   liveEvents.length,
    matches: liveEvents.map(e => ({
      eventId:    e.eventId,
      home:       e.home,
      away:       e.away,
      league:     e.league,
      sport:      e.sport,
      elapsed:    e.elapsed,
      score:      e.score,
      statusCode: e.statusCode,
      link:       e.link,
      p1:         e.p1 !== null ? Number(e.p1.toFixed(2)) : null,
      x:          null,
      p2:         e.p2 !== null ? Number(e.p2.toFixed(2)) : null,
      time:       e.time,
    })),
  };
  try {
    fs.writeFileSync(OUT_FILE, JSON.stringify(output, null, 2), 'utf8');
    pushOutputRemote('parik24_tennis', output);
  } catch (err) {
    log('✗ writeFileSync error:', err.message);
  }
}

function pushOutputRemote(feed, payload) {
  if (!PUSH_URL) return;
  fetch(PUSH_URL, {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      ...(PUSH_TOKEN ? { 'x-betparser-token': PUSH_TOKEN } : {}),
    },
    body: JSON.stringify({ feed, payload }),
  })
    .then(async (res) => {
      if (!res.ok) {
        const text = await res.text().catch(() => '');
        log(`✗ remote push failed [${feed}] HTTP ${res.status}: ${text.slice(0, 160)}`);
      }
    })
    .catch((err) => {
      log(`✗ remote push error [${feed}]:`, err.message);
    });
}

// ══ WebSocket lifecycle ══════════════════════
function connect() {
  log('Connecting…');
  handshakeDone = false;
  invIdCounter  = 0;
  pendingInv.clear();

  ws = new WebSocket(WS_URL, {
    headers: {
      'Origin':     'https://24-parik.one',
      'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    },
  });

  ws.on('open', () => {
    log('WS open → sending handshake');
    ws.send(JSON.stringify({ protocol: 'messagepack', version: 1 }) + '\x1e');

    pingTimer = setInterval(() => {
      if (ws.readyState === WebSocket.OPEN) ws.send(encodePing());
    }, PING_INTERVAL_MS);

    marketsTimer = setInterval(() => {
      if (ws.readyState !== WebSocket.OPEN) return;
      const ids = [...seenEventIds];
      if (ids.length) {
        requestRichEvents(ids);
        requestMarkets(ids);
        log(`→ Refresh details/markets for ${ids.length} events`);
      }
    }, MARKETS_REFRESH_MS);
  });

  ws.on('message', (data) => handleMessage(data));

  ws.on('close', (code) => {
    log(`WS closed (${code}) — reconnecting in ${RECONNECT_DELAY_MS}ms`);
    cleanup();
    setTimeout(connect, RECONNECT_DELAY_MS);
  });

  ws.on('error', (err) => {
    log('WS error:', err.message);
  });
}

function cleanup() {
  if (pingTimer)    { clearInterval(pingTimer);   pingTimer = null; }
  if (marketsTimer) { clearInterval(marketsTimer); marketsTimer = null; }
}

function log(...args) {
  console.log(new Date().toISOString().replace('T', ' ').slice(0, 19), ...args);
}

log(`Parik24 ${SPORT_NAME} Live Worker starting`);
log('Output:', OUT_FILE);
connect();
