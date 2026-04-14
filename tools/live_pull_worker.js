
'use strict';

const fs = require('fs');
const path = require('path');

const SOURCE_BASE_URL = String(process.env.BETPARSER_SOURCE_URL || 'https://snecked-lucio-unskinned.ngrok-free.dev').trim();
const SOURCE_TOKEN = String(process.env.BETPARSER_PULL_TOKEN || '').trim();
const PULL_INTERVAL_MS = Number(process.env.BETPARSER_PULL_INTERVAL_MS || 8000);

const FEED_FILES = {
  parik24_football: path.resolve(__dirname, '../data/parik24_raw.json'),
  parik24_basketball: path.resolve(__dirname, '../data/parik24_basketball_raw.json'),
  parik24_tennis: path.resolve(__dirname, '../data/parik24_tennis_raw.json'),
  pinnacle_football: path.resolve(__dirname, '../data/pinnacle_raw.json'),
  pinnacle_basketball: path.resolve(__dirname, '../data/pinnacle_basketball_raw.json'),
  pinnacle_tennis: path.resolve(__dirname, '../data/pinnacle_tennis_raw.json'),
};

if (!SOURCE_BASE_URL) {
  console.error('BETPARSER_SOURCE_URL is required');
  process.exit(1);
}

function log(...args) {
  console.log(new Date().toISOString().replace('T', ' ').slice(0, 19), ...args);
}

function ensureDir(filePath) {
  const dir = path.dirname(filePath);
  fs.mkdirSync(dir, { recursive: true });
}

async function pullOne(feed, targetFile) {
  const url = `${SOURCE_BASE_URL.replace(/\/+$/, '')}/feed/${encodeURIComponent(feed)}`;
  const headers = {
    accept: 'application/json',
    ...(SOURCE_TOKEN ? { 'x-betparser-pull-token': SOURCE_TOKEN } : {}),
  };

  const res = await fetch(url, { method: 'GET', headers });
  const text = await res.text();
  let data = null;
  try { data = JSON.parse(text); } catch (e) { /* noop */ }

  if (!res.ok || !data?.ok || !data?.payload || typeof data.payload !== 'object') {
    const msg = data?.error || text.slice(0, 160) || `HTTP ${res.status}`;
    throw new Error(`[${feed}] pull failed: HTTP ${res.status} ${msg}`);
  }

  ensureDir(targetFile);
  fs.writeFileSync(targetFile, JSON.stringify(data.payload, null, 2), 'utf8');
  const total = Number(data.payload?.total || 0);
  return { feed, total };
}

async function runCycle() {
  const entries = Object.entries(FEED_FILES);
  const results = [];

  for (const [feed, file] of entries) {
    try {
      const result = await pullOne(feed, file);
      results.push(result);
    } catch (err) {
      log('✗', err.message);
    }
  }

  if (results.length) {
    const summary = results.map((x) => `${x.feed}:${x.total}`).join(', ');
    log(`✓ pulled ${results.length}/${entries.length} feeds | ${summary}`);
  }
}

(async () => {
  log('Live pull worker starting...');
  log(`source: ${SOURCE_BASE_URL}`);
  log(`interval: ${PULL_INTERVAL_MS}ms`);

  await runCycle();
  setInterval(() => {
    runCycle().catch((err) => log('cycle error:', err.message));
  }, PULL_INTERVAL_MS);
})();
