'use strict';
const fs = require('fs');
const msgpack = require('@msgpack/msgpack');

const FRAMES_FILE = 'D:\\Сайты\\OpenServer\\domains\\Betparser\\data\\parik24_frames_raw.json';
const data = JSON.parse(fs.readFileSync(FRAMES_FILE));

function readVarInt(buf, pos) {
  let result = 0, shift = 0;
  while (pos < buf.length) {
    const b = buf[pos++];
    result |= (b & 0x7f) << shift; shift += 7;
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
    try { results.push(msgpack.decode(buf.slice(nextPos, nextPos + len))); } catch {}
    pos = nextPos + len;
  }
  return results;
}

// Build map of sent invIds to targets
const sentMap = {};
for (const f of data.sent) {
  if (f.type !== 'binary') continue;
  for (const frame of decodeFrames(f.hex)) {
    if (!Array.isArray(frame) || frame[0] !== 4) continue;
    sentMap[frame[2]] = { target: frame[3], args: frame[4] };
  }
}

// Decode all StreamItems
const streamItems = {};
for (const f of data.recv) {
  if (f.type !== 'binary') continue;
  for (const frame of decodeFrames(f.hex)) {
    if (!Array.isArray(frame) || frame[0] !== 2) continue;
    const invId = frame[2];
    const item = frame[3];
    if (!streamItems[invId]) streamItems[invId] = [];
    streamItems[invId].push(item);
  }
}

// Print markets response decoded
console.log('=== MARKETS (GetMainMarketsByProfileAndEventIds) ===');
for (const [invId, items] of Object.entries(streamItems)) {
  const req = sentMap[invId];
  if (!req || !req.target.includes('Market')) continue;
  console.log(`\ninvId="${invId}" target="${req.target}" eventIds=${JSON.stringify(req.args[1])}`);
  for (const item of items) {
    console.log('  raw:', JSON.stringify(item).slice(0, 1000));
  }
}

// Print events (GetLiveEventsBySport)
console.log('\n=== LIVE EVENTS (GetLiveEventsBySport) ===');
for (const [invId, items] of Object.entries(streamItems)) {
  const req = sentMap[invId];
  if (!req || req.target !== 'GetLiveEventsBySport') continue;
  for (const item of items) {
    if (!Array.isArray(item) || !Array.isArray(item[1])) continue;
    const [ok, events] = item;
    for (const ev of events.slice(0, 3)) {
      console.log('\nEVENT raw:', JSON.stringify(ev).slice(0, 600));
    }
  }
}

// Print RichEvents
console.log('\n=== RICH EVENTS (GetRichEventsByIds) ===');
for (const [invId, items] of Object.entries(streamItems)) {
  const req = sentMap[invId];
  if (!req || req.target !== 'GetRichEventsByIds') continue;
  for (const item of items.slice(0, 2)) {
    console.log('\nRICH EVENT raw:', JSON.stringify(item).slice(0, 800));
  }
  break; // just first one
}
