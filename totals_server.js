const express = require('express');
const { execFile } = require('child_process');
const path = require('path');

const app = express();
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

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

app.post('/totals', async (req, res) => {
  const { parikUrl, pinnUrl } = req.body;
  if (!parikUrl || !pinnUrl) {
    return res.json({ ok: false, error: 'parikUrl and pinnUrl are required' });
  }
  try {
    const [parik, pinn] = await Promise.all([
      runNodeScript(PARIK_SCRIPT, parikUrl),
      runNodeScript(PINN_SCRIPT, pinnUrl)
    ]);
    res.json({ ok: true, parik, pinn });
  } catch (err) {
    res.json({ ok: false, error: String(err) });
  }
});

const PORT = 3030;
app.listen(PORT, () => {
  console.log('Totals server running on port', PORT);
});
