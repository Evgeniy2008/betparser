'use strict';
const WebSocket = require('ws');
const msgpack = require('@msgpack/msgpack');

const WS_URL = 'wss://parik-24.one/direct-feed/feed?brand=PRJ4&X-Api-Key=507aa81f-4c27-4e37-9410-21dfb81e9efe';
const RECORD_SEPARATOR = '\x1e';

let invId = 0;
function writeVarInt(n) {
  const b = []; do { let x = n & 0x7f; n >>>= 7; if (n > 0) x |= 0x80; b.push(x); } while (n > 0);
  return Buffer.from(b);
}
function encodeInv(target, args) {
  const payload = Buffer.from(msgpack.encode([1, {}, String(++invId), target, args]));
  return Buffer.concat([writeVarInt(payload.length), payload]);
}

// Argument combinations to try
const ATTEMPTS = [
  ['F', null],
  ['F', { locale: 'uk-PRJ4', channel: 'MOBILE_WEB', brand: 'PRJ4', currency: 'UAH' }],
  [{ sportCode: 'F', locale: 'uk-PRJ4', channel: 'MOBILE_WEB', brand: 'PRJ4', currency: 'UAH' }],
  ['F', { locale: 'uk', brand: 'PRJ4' }],
  ['F', 2],   // 2 = "Live" stage?
  ['F', 'uk-PRJ4'],
  [null, 'F'],
];

let attemptIndex = 0;

const ws = new WebSocket(WS_URL, {
  headers: {
    'Origin': 'https://parik-24.one',
    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  },
});

function tryNext() {
  if (attemptIndex >= ATTEMPTS.length) {
    console.log('All attempts done – closing');
    ws.close();
    return;
  }
  const args = ATTEMPTS[attemptIndex];
  console.log(`\nAttempt #${attemptIndex + 1}: GetLiveEventsBySport(${JSON.stringify(args)})`);
  ws.send(encodeInv('GetLiveEventsBySport', args));
  attemptIndex++;
}

ws.on('open', () => {
  ws.send(JSON.stringify({ protocol: 'messagepack', version: 1 }) + RECORD_SEPARATOR);
});

ws.on('message', (data) => {
  const buf = Buffer.isBuffer(data) ? data : Buffer.from(data);
  const firstByte = buf[0], lastByte = buf[buf.length - 1];

  // JSON text-as-binary frame
  if ((firstByte === 0x7b || firstByte === 0x5b) || lastByte === 0x1e) {
    const text = buf.toString('utf8').replace(/\x1e/g, '').trim();
    if (text.length > 0 && (text[0] === '{' || text[0] === '[')) {
      try {
        const json = JSON.parse(text);
        if (!json.error) {
          console.log('✓ Handshake OK');
          tryNext();
        } else {
          console.log('Handshake ERROR:', json.error);
        }
        return;
      } catch {}
    }
  }

  // Decode msgpack
  let pos = 0;
  while (pos < buf.length) {
    let result = 0, shift = 0;
    while (pos < buf.length) {
      const b = buf[pos++]; result |= (b & 0x7f) << shift; shift += 7;
      if ((b & 0x80) === 0) break;
    }
    const len = result;
    if (len === 0 || pos + len > buf.length) break;
    const frame = buf.slice(pos, pos + len); pos += len;
    try {
      const decoded = msgpack.decode(frame);
      if (!Array.isArray(decoded)) continue;
      const type = decoded[0];
      if (type === 6) continue;
      if (type === 3) {
        const err = decoded[3], result2 = decoded[4];
        if (err) {
          console.log(`  ✗ ERROR inv="${decoded[2]}": ${String(err).slice(0, 200)}`);
          setTimeout(tryNext, 200);
        } else {
          console.log(`  ✓ SUCCESS inv="${decoded[2]}" result:`, JSON.stringify(result2).slice(0, 400));
          setTimeout(() => { ws.close(); }, 1000);
        }
      } else {
        console.log(`  MSG type=${type}`, JSON.stringify(decoded).slice(0, 200));
      }
    } catch (e) {
      console.log('  decode err:', e.message);
    }
  }
});

ws.on('close', (code) => { console.log('CLOSE', code); process.exit(0); });
ws.on('error', (e) => { console.log('ERR', e.message); process.exit(1); });
setTimeout(() => { console.log('TIMEOUT'); ws.close(); }, 20000);
