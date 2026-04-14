/**
 * Parik24 Live WebSocket Worker  (FINAL – correct SignalR MessagePack protocol)
 *
 * Protocol facts captured from real browser session:
 *  - Type 4  (StreamInvocation) for all client→server calls
 *  - Hub context : 5-element array ['en-PRJ4','MOBILE_WEB','PRJ4','','USD']
 *  - Server responds with type 2 (StreamItem): [2, {}, invId, [isBatch, [items]]]
 *  - Odds stored as integer × 100  (195 → 1.95)
 *  - Outcome types: 0=P1, 1=X, 3=P2
 *
 * Output: data/parik24_raw.json
 * Run  : node tools/parik24_live_worker.js
 */

'use strict';

const WebSocket = require('ws');
const msgpack   = require('@msgpack/msgpack');
const fs        = require('fs');
const path      = require('path');

// ══════════════════════════════════════════════
//  CONFIG
// ══════════════════════════════════════════════
const WS_URL =
  'wss://parik-24.one/direct-feed/feed?brand=PRJ4&X-Api-Key=507aa81f-4c27-4e37-9410-21dfb81e9efe';

// Hub context – exact 5-element array the browser sends
const HUB_CTX = ['en-PRJ4', 'MOBILE_WEB', 'PRJ4', '', 'USD'];

const OUT_FILE           = path.resolve(__dirname, '../data/parik24_raw.json');
const RECONNECT_DELAY_MS = 4000;
const PING_INTERVAL_MS   = 20000;
const MARKETS_REFRESH_MS = 5000;
const PUSH_URL           = String(process.env.BETPARSER_PUSH_URL || '').trim();
const PUSH_TOKEN         = String(process.env.BETPARSER_PUSH_TOKEN || '').trim();

// ══════════════════════════════════════════════
//  STATE
// ══════════════════════════════════════════════
/** eventId (string) → { eventId, home, away, league, sport, elapsed,
 *                        statusCode, score, link, p1, x, p2, time } */
const eventsMap = new Map();

let ws            = null;
let invIdCounter  = 0;
let handshakeDone = false;
let pingTimer     = null;
let marketsTimer  = null;

/** invId (string) → target method name */
const pendingInv = new Map();

// ══════════════════════════════════════════════
//  SignalR MessagePack framing
// ══════════════════════════════════════════════
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

/**
 * Encode a StreamInvocation (type=4):  [4, {}, invId, target, args]
 * Returns a prefixed binary buffer ready to send via ws.send().
 */
function encodeStreamInv(target, args) {
  const id      = String(++invIdCounter);
  const payload = Buffer.from(msgpack.encode([4, {}, id, target, args]));
  const frame   = Buffer.concat([writeVarInt(payload.length), payload]);
  pendingInv.set(id, target);
  return frame;
}

/** Encode a Ping (type=6) */
function encodePing() {
  const payload = Buffer.from(msgpack.encode([6]));
  return Buffer.concat([writeVarInt(payload.length), payload]);
}

/** Iterate over all SignalR MessagePack messages in a buffer. */
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

// ══════════════════════════════════════════════
//  Commands
// ══════════════════════════════════════════════
function subscribeEvents() {
  ws.send(encodeStreamInv('GetLiveEventsBySport', ['F', HUB_CTX]));
  log('→ GetLiveEventsBySport subscribed');
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

// ══════════════════════════════════════════════
//  Message handler
// ══════════════════════════════════════════════
function handleMessage(raw) {
  const buf = Buffer.isBuffer(raw) ? raw : Buffer.from(raw);

  // ── Detect JSON/text frame (handshake reply arrives as binary JSON)  ──
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
      } catch { /* fall through to msgpack */ }
    }
  }

  if (!handshakeDone) return;

  for (const msg of decodeBuffer(buf)) {
    if (!Array.isArray(msg) || msg.length < 1) continue;
    const type = msg[0];

    if (type === 6) continue; // server ping

    if (type === 2) {
      // StreamItem: [2, headers, invId, item]
      const invId  = String(msg[2]);
      const item   = msg[3];
      const target = pendingInv.get(invId);
      processStreamItem(target, item, invId);
      continue;
    }

    if (type === 3) {
      // Completion: [3, headers, invId, error?, result?]
      const err = msg[3];
      if (err && typeof err === 'string') {
        log(`✗ inv="${msg[2]}" completed with error:`, err.slice(0, 200));
      }
      continue;
    }
  }
}

// ══════════════════════════════════════════════
//  StreamItem processors
// ══════════════════════════════════════════════
function processStreamItem(target, item, invId) {
  if (!Array.isArray(item) || item.length < 2) return;
  // item = [isBatch(bool), [records...]]
  const data = item[1];
  if (!Array.isArray(data)) return;

  if (target === 'GetLiveEventsBySport') {
    let changed = false;
    for (const ev of data) {
      if (parseEventTuple(ev)) changed = true;
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

  // Unknown stream – log for debugging
  log(`[stream invId=${invId} target=${target}] items=${data.length}`);
}

/**
 * Event tuple structure (from browser capture):
 *   ev = [isUpdate, eventId, eventData]
 *   eventData indices:
 *     [4]  = start timestamp (ms)
 *     [5]  = statusCode  (2 = live in progress)
 *     [6]  = "Home - Away" title
 *     [10] = [[homeId, homeName, ...], [awayId, awayName, ...]]
 *     [11] = scoreBlock
 *     [15] = elapsed minutes
 *     [17] = event slug (for link)
 */
function parseEventTuple(ev) {
  if (!Array.isArray(ev) || ev.length < 3) return false;
  const eventId = String(ev[1]);
  const d       = ev[2];
  if (!Array.isArray(d)) return false;

  const teams = d[10];
  if (!Array.isArray(teams) || teams.length < 2) return false;
  if (!Array.isArray(teams[0]) || !Array.isArray(teams[1])) return false;

  const home = teams[0][1];
  const away = teams[1][1];
  if (typeof home !== 'string' || typeof away !== 'string') return false;

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
    league:     existing.league ?? '',
    sport:      'football',
    statusCode,
    elapsed,
    score:      scoreStr || existing.score || '',
    link:       slug ? `/en/events/${slug}` : '',
    time:       new Date().toISOString(),
    p1:         existing.p1 ?? null,
    x:          existing.x  ?? null,
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

/**
 * Market tuple structure (from browser capture):
 *   mkt = [isUpdate, eventIdTuple, [marketBlock, ...]]
 *   eventIdTuple = [eventId, period, marketType, ...]
 *   marketType  : 2 = 1X2
 *
 *   marketData[0] = handicapGroup = [[[outcomes]], marketId, ...]
 *   outcome = [[type, []], odd_x100, isSuspended, ...]
 *     type: 0=P1  1=X  3=P2
 */
function parseMarketTuple(mkt) {
  if (!Array.isArray(mkt) || mkt.length < 3) return false;
  const evTuple    = mkt[1];
  const marketData = mkt[2];
  if (!Array.isArray(evTuple) || !Array.isArray(marketData)) return false;

  const eventId    = String(evTuple[0]);
  const marketType = evTuple[2];

  if (marketType !== 2) return false; // Only 1X2

  const event = eventsMap.get(eventId);
  if (!event) return false;

  // Structure (confirmed from live capture):
  //   marketData = [ handicapGroupsList, marketId, sortIndex, ... ]
  //   handicapGroupsList = [ [[[handicapVals]], outcomesArr, bool, null, int], ... ]
  //   outcomesArr = [ [[type,[]], odd_x100, suspended, ...], ... ]
  const handicapGroupsList = marketData[0];
  if (!Array.isArray(handicapGroupsList) || !handicapGroupsList.length) return false;
  const firstGroup = handicapGroupsList[0];
  if (!Array.isArray(firstGroup) || firstGroup.length < 2) return false;
  const outcomes = firstGroup[1]; // the actual outcomes array
  if (!Array.isArray(outcomes)) return false;

  let p1 = null, x = null, p2 = null;

  for (const oc of outcomes) {
    if (!Array.isArray(oc)) continue;
    // outcome = [[type, []], odd_x100, suspended, ...]
    const ocHead  = oc[0];
    const ocType  = Array.isArray(ocHead) ? ocHead[0] : ocHead;
    const rawOdd  = oc[1];
    if (typeof rawOdd !== 'number') continue;
    const odd = rawOdd / 100;
    if      (ocType === 0) p1 = odd;
    else if (ocType === 1) x  = odd;
    else if (ocType === 3) p2 = odd;
  }

  if (p1 !== null || x !== null || p2 !== null) {
    eventsMap.set(eventId, {
      ...event,
      p1: p1 ?? event.p1,
      x:  x  ?? event.x,
      p2: p2 ?? event.p2,
    });
    return true;
  }
  return false;
}

// ══════════════════════════════════════════════
//  File output
// ══════════════════════════════════════════════
function flushFile() {
  const liveEvents = [...eventsMap.values()].filter(e => e.home && e.away);
  const output = {
    updated: new Date().toISOString(),
    source:  'parik24-live-ws',
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
      x:          e.x  !== null ? Number(e.x.toFixed(2))  : null,
      p2:         e.p2 !== null ? Number(e.p2.toFixed(2)) : null,
      time:       e.time,
    })),
  };
  try {
    fs.writeFileSync(OUT_FILE, JSON.stringify(output, null, 2), 'utf8');
    pushOutputRemote('parik24_football', output);
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

// ══════════════════════════════════════════════
//  WebSocket lifecycle
// ══════════════════════════════════════════════
function connect() {
  log('Connecting…');
  handshakeDone = false;
  invIdCounter  = 0;
  pendingInv.clear();

  ws = new WebSocket(WS_URL, {
    headers: {
      'Origin':     'https://parik-24.one',
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
      const ids = [...eventsMap.keys()];
      if (ids.length) {
        requestMarkets(ids);
        log(`→ Markets refresh for ${ids.length} events`);
      }
    }, MARKETS_REFRESH_MS);
  });

  ws.on('message', (data) => handleMessage(data));

  ws.on('close', (code, reason) => {
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

// ══════════════════════════════════════════════
//  ENTRY
// ══════════════════════════════════════════════
log('Parik24 Live Worker starting');
log('Output:', OUT_FILE);
connect();
