const http = require('http');
const { execFile } = require('child_process');
const path = require('path');

const PARIK_SCRIPT = path.join(__dirname, 'match_totals.js');
const PINN_SCRIPT = path.join(__dirname, 'match_totals_pinnacle.js');

function runNodeScript(script, url) {
  return new Promise((resolve, reject) => {
    execFile('node', [script, url], { cwd: __dirname }, (error, stdout, stderr) => {
      if (error) {
        return reject(stderr || error.message);
      }
      try {
        const data = JSON.parse(stdout);
        resolve(data);
      } catch (e) {
        reject('Invalid JSON: ' + stdout);
      }
    });
  });
}

const server = http.createServer(async (req, res) => {
  // CORS preflight
  if (req.method === 'OPTIONS') {
    res.writeHead(204, {
      'Access-Control-Allow-Origin': '*',
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Access-Control-Max-Age': 86400
    });
    res.end();
    return;
  }
  // Основная логика
  res.setHeader('Access-Control-Allow-Origin', '*');
  if (req.method === 'POST' && req.url === '/totals') {
    let body = '';
    req.on('data', chunk => { body += chunk; });
    req.on('end', async () => {
      let parikUrl, pinnUrl;
      try {
        const data = JSON.parse(body);
        parikUrl = data.parikUrl;
        pinnUrl = data.pinnUrl;
      } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'Invalid JSON' }));
        return;
      }
      if (!parikUrl || !pinnUrl) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'parikUrl and pinnUrl are required' }));
        return;
      }
      try {
        const [parik, pinn] = await Promise.all([
          runNodeScript(PARIK_SCRIPT, parikUrl),
          runNodeScript(PINN_SCRIPT, pinnUrl)
        ]);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, parik, pinn }));
      } catch (err) {
        res.writeHead(500, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: String(err) }));
      }
    });
  } else {
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ ok: false, error: 'Not found' }));
  }
});

const PORT = 3031;
server.listen(PORT, () => {
  console.log('Simple Node.js totals server running on port', PORT);
});
