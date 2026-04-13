import { chromium } from 'playwright';

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({
  userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
});

const seen = new Set();
const reqs = [];
const wsInfo = [];

page.on('request', (req) => {
  const type = req.resourceType();
  if (type === 'xhr' || type === 'fetch') {
    const key = `${req.method()} ${req.url()}`;
    if (!seen.has(key)) {
      seen.add(key);
      reqs.push({
        type,
        method: req.method(),
        url: req.url(),
        postData: req.postData() ? req.postData().slice(0, 500) : '',
      });
      console.log('REQ', type.toUpperCase(), req.method(), req.url());
    }
  }
});

page.on('websocket', (ws) => {
  const item = { url: ws.url(), sent: [], received: [] };
  wsInfo.push(item);
  console.log('WS', ws.url());

  ws.on('framesent', (ev) => {
    const payload = String(ev.payload || '');
    if (item.sent.length < 5) item.sent.push(payload.slice(0, 400));
    console.log('WS>S', payload.slice(0, 200));
  });

  ws.on('framereceived', (ev) => {
    const payload = String(ev.payload || '');
    if (item.received.length < 8) item.received.push(payload.slice(0, 400));
    console.log('WS<R', payload.slice(0, 200));
  });
});

page.on('response', async (res) => {
  const req = res.request();
  const type = req.resourceType();
  if ((type === 'xhr' || type === 'fetch') && res.status() < 400) {
    const ct = (res.headers()['content-type'] || '').toLowerCase();
    if (ct.includes('application/json')) {
      try {
        const text = await res.text();
        console.log('JSON', res.status(), req.method(), req.url(), text.slice(0, 300).replace(/\s+/g, ' '));
      } catch {
        // ignore
      }
    }
  }
});

await page.goto('https://parik-24.one/uk/football/live', {
  waitUntil: 'domcontentloaded',
  timeout: 120000,
});

await page.waitForTimeout(15000);
console.log('---SUMMARY---');
console.log(JSON.stringify({ reqs, wsInfo }, null, 2));
await browser.close();
