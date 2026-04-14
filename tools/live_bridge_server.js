
'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');

const HOST = String(process.env.BETPARSER_BRIDGE_HOST || '0.0.0.0').trim();
const PORT = Number(process.env.BETPARSER_BRIDGE_PORT || 8787);
const PULL_TOKEN = String(process.env.BETPARSER_PULL_TOKEN || '').trim();

const FEED_FILES = {
  parik24_football: path.resolve(__dirname, '../data/parik24_raw.json'),
  parik24_basketball: path.resolve(__dirname, '../data/parik24_basketball_raw.json'),
  parik24_tennis: path.resolve(__dirname, '../data/parik24_tennis_raw.json'),
  pinnacle_football: path.resolve(__dirname, '../data/pinnacle_raw.json'),
  pinnacle_basketball: path.resolve(__dirname, '../data/pinnacle_basketball_raw.json'),
  pinnacle_tennis: path.resolve(__dirname, '../data/pinnacle_tennis_raw.json'),
};

function json(res, code, payload) {
  res.writeHead(code, {
    'content-type': 'application/json; charset=utf-8',
    'cache-control': 'no-store, no-cache, must-revalidate, max-age=0',
    pragma: 'no-cache',
    expires: '0',
  });
  res.end(JSON.stringify(payload));
}

function isAuthorized(req, urlObj) {
  if (!PULL_TOKEN) return true;
  const headerToken = String(req.headers['x-betparser-pull-token'] || '').trim();
  const queryToken = String(urlObj.searchParams.get('token') || '').trim();
  return headerToken === PULL_TOKEN || queryToken === PULL_TOKEN;
}

function readFeedPayload(feed) {
  const file = FEED_FILES[feed];
  if (!file) return { ok: false, status: 400, error: 'Unknown feed' };
  if (!fs.existsSync(file)) {
    return { ok: false, status: 404, error: `Feed file not found: ${path.basename(file)}` };
  }

  try {
    const raw = fs.readFileSync(file, 'utf8');
    const parsed = JSON.parse(raw);
    if (!parsed || typeof parsed !== 'object') {
      return { ok: false, status: 500, error: 'Invalid feed payload' };
    }
    return {
      ok: true,
      status: 200,
      feed,
      payload: parsed,
      file: path.basename(file),
      servedAt: new Date().toISOString(),
    };
  } catch (err) {
    return { ok: false, status: 500, error: `Cannot read feed payload: ${err.message}` };
  }
}

const server = http.createServer((req, res) => {
  const urlObj = new URL(req.url || '/', `http://${req.headers.host || 'localhost'}`);
  const route = urlObj.pathname.replace(/\/+$/, '') || '/';

  if (req.method !== 'GET') {
    return json(res, 405, { ok: false, error: 'Method not allowed' });
  }

  if (!isAuthorized(req, urlObj)) {
    return json(res, 401, { ok: false, error: 'Unauthorized' });
  }

  if (route === '/health') {
    return json(res, 200, {
      ok: true,
      service: 'betparser-live-bridge',
      feeds: Object.keys(FEED_FILES),
      updated: new Date().toISOString(),
    });
  }

  if (route === '/feeds') {
    return json(res, 200, { ok: true, feeds: Object.keys(FEED_FILES) });
  }

  if (route.startsWith('/feed/')) {
    const feed = decodeURIComponent(route.slice('/feed/'.length));
    const result = readFeedPayload(feed);
    return json(res, result.status, result);
  }

  if (route === '/feed') {
    const feed = String(urlObj.searchParams.get('name') || '').trim();
    if (!feed) return json(res, 400, { ok: false, error: 'Query param name is required' });
    const result = readFeedPayload(feed);
    return json(res, result.status, result);
  }

  return json(res, 404, { ok: false, error: 'Not found' });
});

server.listen(PORT, HOST, () => {
  console.log(`[live-bridge] listening on http://${HOST}:${PORT}`);
  console.log(`[live-bridge] feeds: ${Object.keys(FEED_FILES).join(', ')}`);
  if (PULL_TOKEN) console.log('[live-bridge] token auth enabled');
});
