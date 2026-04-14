'use strict';
const WebSocket = require('ws');
const msgpack   = require('@msgpack/msgpack');
const fs        = require('fs');
const path      = require('path');

const WS_URL  = 'wss://24-parik.one/direct-feed/feed?brand=PRJ4&X-Api-Key=507aa81f-4c27-4e37-9410-21dfb81e9efe';
const HUB_CTX = ['uk-PRJ4', 'MOBILE_WEB', 'PRJ4', '', 'UAH'];

let invId = 0;
const pendingInv = new Map();
let handshakeDone = false;
let collected = [];

function writeVarInt(n) {
  const b = [];
  do { let x = n & 0x7f; n >>>= 7; if (n > 0) x |= 0x80; b.push(x); } while (n > 0);
  return Buffer.from(b);
}
function readVarInt(buf, start) {
  let result = 0, shift = 0, pos = start || 0;
  while (pos < buf.length) {
    const b = buf[pos++]; result |= (b & 0x7f) << shift; shift += 7;
    if ((b & 0x80) === 0) break;
  }
  return { value: result, nextPos: pos };
}
function* decodeBuffer(buf) {
  let pos = 0;
  while (pos < buf.length) {
    const { value: len, nextPos } = readVarInt(buf, pos);
    if (len === 0) { pos = nextPos; continue; }
    if (nextPos + len > buf.length) break;
    try { yield msgpack.decode(buf.slice(nextPos, nextPos + len)); } catch {}
    pos = nextPos + len;
  }
}
function encodeStreamInv(target, args) {
  const id = String(++invId);
  const payload = Buffer.from(msgpack.encode([4, {}, id, target, args]));
  pendingInv.set(id, target);
  return Buffer.concat([writeVarInt(payload.length), payload]);
}

const ws = new WebSocket(WS_URL, { headers: { 'Origin': 'https://24-parik.one' } });

// known event IDs from parik24_raw.json - grab first 3 so we get market data quickly
let testEventIds = null;

ws.on('open', () => {
  console.log('open → handshake');
  ws.send(JSON.stringify({ protocol: 'messagepack', version: 1 }) + '\x1e');
});

ws.on('message', (data) => {
  const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
  if (buf[0] === 0x7b || buf[buf.length - 1] === 0x1e) {
    if (!handshakeDone) {
      handshakeDone = true;
      console.log('handshake OK');
      // Subscribe to events first to collect event IDs
      ws.send(encodeStreamInv('GetLiveEventsBySport', ['F', HUB_CTX]));
    }
    return;
  }
  if (!handshakeDone) return;

  for (const msg of decodeBuffer(buf)) {
    if (!Array.isArray(msg)) continue;
    const [type, , iid, item] = msg;

    if (type === 2) {
      const target = pendingInv.get(String(iid));
      if (target === 'GetLiveEventsBySport') {
        if (!Array.isArray(item) || !Array.isArray(item[1])) continue;
        const ids = item[1]
          .filter(ev => Array.isArray(ev) && ev.length >= 2)
          .map(ev => String(ev[1]));
        if (ids.length && !testEventIds) {
          testEventIds = ids.slice(0, 5);
          console.log('Got event IDs:', testEventIds);
          // Now request markets
          ws.send(encodeStreamInv('GetMainMarketsByProfileAndEventIds', [
            'pro_main_period', testEventIds, 4, 1, HUB_CTX
          ]));
        }
      }

      if (target === 'GetMainMarketsByProfileAndEventIds') {
        if (!Array.isArray(item) || !Array.isArray(item[1])) continue;
        for (const mkt of item[1]) {
          if (collected.length < 3) {
            collected.push(mkt);
            console.log('=== MARKET ITEM ===');
            console.log(JSON.stringify(mkt, (k,v) => typeof v === 'bigint' ? Number(v) : v, 2).slice(0, 3000));
          }
        }
        if (collected.length >= 3) {
          ws.close();
        }
      }
    }
  }
});

ws.on('close', () => {
  const outPath = path.resolve(__dirname, '../data/debug_markets.json');
  fs.writeFileSync(outPath, JSON.stringify(collected, (k,v) => typeof v === 'bigint' ? Number(v) : v, 2));
  console.log('Saved to', outPath);
  process.exit(0);
});
ws.on('error', e => console.error(e.message));

setTimeout(() => { console.log('timeout'); ws.close(); }, 30000);
