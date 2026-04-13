// Captures raw binary frames from Parik24 live WS
// and saves them for analysis
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

(async () => {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({
    userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/123.0.0.0 Safari/537.36',
  });

  const sentFrames = [];
  const recvFrames = [];

  page.on('websocket', (ws) => {
    if (!ws.url().includes('direct-feed')) return;
    console.log('direct-feed WS:', ws.url());

    ws.on('framesent', (ev) => {
      const payload = ev.payload;
      if (typeof payload === 'string') {
        console.log('SENT TEXT:', JSON.stringify(payload.slice(0, 100)));
        sentFrames.push({ type: 'text', data: payload });
      } else {
        // Binary
        const hex = Buffer.from(payload).toString('hex');
        console.log('SENT BIN hex:', hex.slice(0, 120));
        sentFrames.push({ type: 'binary', hex });
      }
    });

    ws.on('framereceived', (ev) => {
      const payload = ev.payload;
      if (typeof payload === 'string') {
        console.log('RECV TEXT:', JSON.stringify(payload.slice(0, 100)));
        recvFrames.push({ type: 'text', data: payload });
      } else {
        const hex = Buffer.from(payload).toString('hex');
        console.log('RECV BIN hex:', hex.slice(0, 200));
        recvFrames.push({ type: 'binary', hex });
      }
    });
  });

  await page.goto('https://parik-24.one/uk/football/live', {
    waitUntil: 'domcontentloaded',
    timeout: 60000,
  });
  await page.waitForTimeout(10000);
  await browser.close();

  const out = path.join(__dirname, '../data/parik24_frames_raw.json');
  fs.writeFileSync(out, JSON.stringify({ sent: sentFrames, recv: recvFrames }, null, 2));
  console.log(`\nSaved ${sentFrames.length} sent, ${recvFrames.length} recv frames to`, out);
})().catch(err => { console.error(err); process.exit(1); });
