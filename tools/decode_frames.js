'use strict';
const fs = require('fs');
const msgpack = require('@msgpack/msgpack');

const FRAMES_FILE = 'D:\\Сайты\\OpenServer\\domains\\Betparser\\data\\parik24_frames_raw.json';
const data = JSON.parse(fs.readFileSync(FRAMES_FILE));

function readVarInt(buf, pos) {
  let result = 0, shift = 0;
  while (pos < buf.length) {
    const b = buf[pos++];
    result |= (b & 0x7f) << shift;
    shift += 7;
    if ((b & 0x80) === 0) break;
  }
  return { value: result, nextPos: pos };
}

function decodeFrames(hexStr) {
  const buf = Buffer.from(hexStr, 'hex');
  const results = [];
  let pos = 0;
  while (pos < buf.length) {
    const { value: len, nextPos } = readVarInt(buf, pos);
    if (len === 0) { pos = nextPos; continue; }
    if (nextPos + len > buf.length) break;
    const frame = buf.slice(nextPos, nextPos + len);
    pos = nextPos + len;
    try {
      results.push(msgpack.decode(frame));
    } catch (e) {
      results.push({ decodeError: e.message, hexSample: frame.slice(0, 20).toString('hex') });
    }
  }
  return results;
}

console.log('=== SENT FRAMES ===');
for (const f of data.sent) {
  if (f.type === 'text') {
    console.log('TEXT:', JSON.stringify(f.data.slice(0, 100)));
    continue;
  }
  const frames = decodeFrames(f.hex);
  for (const frame of frames) {
    if (!Array.isArray(frame)) { console.log('non-array:', frame); continue; }
    const [type, , invId, target, args] = frame;
    console.log(`\n[type=${type}] invId="${invId}" target="${target}"`);
    if (args !== undefined) {
      try {
        const argsStr = JSON.stringify(args).slice(0, 600);
        console.log('  args:', argsStr);
      } catch {
        console.log('  args: (non-json)');
      }
    }
  }
}

console.log('\n=== RECV FRAMES (type=2 StreamItem, first 5) ===');
let streamCount = 0;
for (const f of data.recv) {
  if (f.type === 'text') continue;
  const frames = decodeFrames(f.hex);
  for (const frame of frames) {
    if (!Array.isArray(frame)) continue;
    const [type, , invId, item] = frame;
    if (type !== 2) continue;
    streamCount++;
    if (streamCount > 5) continue;
    console.log(`\n[StreamItem] invId="${invId}"`);
    try {
      const s = JSON.stringify(item);
      console.log('  data:', s.slice(0, 500));
    } catch {
      console.log('  data: (non-json)', item);
    }
  }
}
console.log(`\nTotal StreamItems: ${streamCount}`);
