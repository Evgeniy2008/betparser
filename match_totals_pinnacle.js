const puppeteer = require('puppeteer');

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function parseArgs() {
  const [, , hrefArg, ...rest] = process.argv;
  const opts = { href: hrefArg || '', proxy: process.env.UA_PROXY || '' };

  for (let i = 0; i < rest.length; i += 1) {
    const arg = rest[i];
    if (arg.startsWith('--proxy=')) {
      opts.proxy = arg.slice('--proxy='.length);
    } else if (arg === '--proxy') {
      opts.proxy = rest[i + 1] || '';
      i += 1;
    }
  }

  return opts;
}

function isValidPinnacleEventUrl(url) {
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== 'https:') return false;
    if (!/pinnacle\.com$/i.test(parsed.hostname)) return false;
    return /\/en\/(soccer|football)\//.test(parsed.pathname);
  } catch {
    return false;
  }
}

async function scrapePinnacleTeamTotals(url, proxy) {
  const launchOptions = {
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--window-size=1366,900',
    ],
  };
  if (proxy) {
    launchOptions.args.push(`--proxy-server=${proxy}`);
  }

  const browser = await puppeteer.launch(launchOptions);
  try {
    const page = await browser.newPage();
    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );
    await page.setViewport({ width: 1366, height: 900 });

    await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 });

    // Give React time to render markets
    await sleep(2000);
    // Try to click "Team Totals" filter if exists
    try {
      const teamTotalsBtn = await page.$('button#team_total, button[id*="team_total"]');
      if (teamTotalsBtn) {
        await teamTotalsBtn.click();
        await sleep(1200);
      }
    } catch {}

    // Expand markets if there are collapsed accordions
    try {
      const expanders = await page.$$('[data-test-id*="market"] button[aria-expanded="false"]');
      for (const btn of expanders) {
        try { await btn.click(); await sleep(150); } catch {}
      }
    } catch {}

    const extracted = await page.evaluate(() => {
      const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();

      // Try to detect team names from header/title
      const teamLabels = [];
      const hdrTeams = Array.from(document.querySelectorAll('[class*="header"], [class*="matchup"] span'))
        .map((el) => norm(el.textContent))
        .filter(Boolean)
        .filter((t) => /[a-z]/i.test(t))
        .slice(0, 2);
      if (hdrTeams.length >= 2) {
        teamLabels.push(hdrTeams[0], hdrTeams[1]);
      }

      // Collect team total markets by scanning for headings that include "Team Total" or contain team names
      const marketRoots = Array.from(document.querySelectorAll('[data-test-id], section, div'))
        .filter((el) => {
          const txt = norm(el.textContent).toLowerCase();
          return /team total|totals? - .*team|player team totals?/i.test(txt) ||
                 (teamLabels.length === 2 && (txt.includes(teamLabels[0].toLowerCase() + ' total') || txt.includes(teamLabels[1].toLowerCase() + ' total')));
        });

      // Fallback to any market blocks that have Over/Under rows and a numeric line
      const guessMarkets = (els) => {
        const rows = [];
        els.forEach((root) => {
          const rowLike = Array.from(root.querySelectorAll('div, li')).filter((r) => {
            const txt = norm(r.textContent).toLowerCase();
            return /over|under/.test(txt) && /(\d+([.,]\d+)?)/.test(txt);
          });
          rowLike.forEach((r) => {
            const parts = norm(r.textContent);
            // Try to split into Over/Under with odds and line
            // We'll pick the numeric closest to typical .5 steps as line
            const nums = (parts.match(/\d+([.,]\d+)?/g) || []).map((s) => s.replace(',', '.'));
            if (nums.length >= 3) {
              // Heuristic: first numeric that is <= 10 is line
              const lineIdx = nums.findIndex((n) => parseFloat(n) <= 15);
              if (lineIdx >= 0) {
                const line = nums[lineIdx];
                // The remaining two larger numbers are prices
                const odds = nums.filter((_, i) => i !== lineIdx).map((n) => parseFloat(n)).filter((n) => n > 1.01);
                if (odds.length >= 2) {
                  // Determine which is Over vs Under based on text order
                  const overFirst = /over/i.test(parts.split(/\d/)[0] || '');
                  const over = odds[0];
                  const under = odds[1];
                  rows.push({ line, over: String(over), under: String(under), raw: parts });
                }
              }
            }
          });
        });
        return rows;
      };

      // Build per team maps; we cannot reliably split teams without explicit headings,
      // so we return a generic list and the consumer can decide which team to use.
      const rows = guessMarkets(marketRoots);

      // Sort lines numerically if possible
      rows.sort((a, b) => {
        const fa = Number(String(a.line).replace(',', '.'));
        const fb = Number(String(b.line).replace(',', '.'));
        if (Number.isNaN(fa) || Number.isNaN(fb)) {
          return String(a.line).localeCompare(String(b.line), 'en');
        }
        return fa - fb;
      });

      return {
        marketTitle: 'Team Totals',
        totals: rows,
        teams: teamLabels,
      };
    });

    return extracted;
  } finally {
    await browser.close();
  }
}

(async () => {
  const { href, proxy } = parseArgs();
  if (!isValidPinnacleEventUrl(href)) {
    process.stdout.write(JSON.stringify({ ok: false, error: 'Invalid Pinnacle event URL' }));
    process.exit(1);
    return;
  }
  try {
    const data = await scrapePinnacleTeamTotals(href, proxy);
    process.stdout.write(JSON.stringify({ ok: true, href, ...data }));
    process.exit(0);
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: error?.message || String(error) }));
    process.exit(1);
  }
})();

