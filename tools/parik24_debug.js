'use strict';
const WebSocket = require('ws');

const WS_URL = 'wss://parik-24.one/direct-feed/feed?brand=PRJ4&X-Api-Key=507aa81f-4c27-4e37-9410-21dfb81e9efe';
const RECORD_SEPARATOR = '\x1e';

const ws = new WebSocket(WS_URL, {
  headers: {
    'Origin': 'https://parik-24.one',
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  },
});

let msgCount = 0;

const msgpack = require('@msgpack/msgpack');
let invId = 0;
function writeVarInt(n) {
  const b = []; do { let x = n & 0x7f; n >>>= 7; if (n > 0) x |= 0x80; b.push(x); } while (n > 0);
  return Buffer.from(b);
}
function encodeInv(target, args) {
  const payload = Buffer.from(msgpack.encode([1, {}, String(++invId), target, args]));
  return Buffer.concat([writeVarInt(payload.length), payload]);
}

let handshakeDone = false;

ws.on('open', () => {
  console.log('OPEN');
  ws.send(JSON.stringify({ protocol: 'messagepack', version: 1 }) + RECORD_SEPARATOR);
});

ws.on('message', (data) => {
  msgCount++;
  const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
  const firstByte = buf[0];
  const lastByte  = buf[buf.length - 1];

  // JSON text-as-binary frame (handshake)
  if ((firstByte === 0x7b || firstByte === 0x5b) || lastByte === 0x1e) {
    const text = buf.toString('utf8').replace(/\x1e/g, '').trim();
    if (text.length > 0 && (text[0] === '{' || text[0] === '[')) {
      try {
        const json = JSON.parse(text);
        if (!handshakeDone && !json.error) {
          handshakeDone = true;
          console.log('✓ Handshake OK — subscribing to live football');
          ws.send(encodeInv('GetLiveEventsBySport', ['F', null, 'uk-PRJ4', 'MOBILE_WEB', 'PRJ4', null, 'UAH']));
          // Also GetRichEventsByIds and markets will follow once we know IDs
        }
        return;
      } catch {}
    }
  }

  // MessagePack binary response
  if (!handshakeDone) return;
  // Decode msgpack (varint-prefixed)
  let pos = 0;
  while (pos < buf.length) {
    let result = 0, shift = 0;
    while (pos < buf.length) {
      const b = buf[pos++];
      result |= (b & 0x7f) << shift;
      shift += 7;
      if ((b & 0x80) === 0) break;
    }
    const len = result;
    if (len === 0 || pos + len > buf.length) break;
    const frame = buf.slice(pos, pos + len);
    pos += len;
    try {
      const decoded = msgpack.decode(frame);
      if (Array.isArray(decoded)) {
        const type = decoded[0];
        if (type === 6) continue; // ping
        console.log(`MSG type=${type} inv="${decoded[2]}" target="${decoded[3] ?? ''}"`,
          JSON.stringify(decoded[4] ?? decoded[3] ?? decoded).slice(0, 300));
      }
    } catch (e) {
      console.log('MSG decode err:', e.message, 'hex:', frame.slice(0, 30).toString('hex'));
    }
  }
});

ws.on('close', (code, reason) => {
  console.log('CLOSE', code, reason.toString());
  process.exit(0);
});

ws.on('error', (e) => {
  console.log('ERR', e.message);
  process.exit(1);
});

setTimeout(() => { console.log('TIMEOUT close'); ws.close(); }, 12000);
