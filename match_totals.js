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

function isValidEventUrl(url) {
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== 'https:') return false;
    if (parsed.hostname !== '24-parik.club') return false;
    return parsed.pathname.startsWith('/uk/events/');
  } catch {
    return false;
  }
}

async function scrapeTotals(url, proxy) {
  const launchOptions = {
    headless: true,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--lang=uk-UA,uk',
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
    await page.setExtraHTTPHeaders({
      'Accept-Language': 'uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
    });

    await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 });
    await page.waitForSelector('[data-id="market-item"], [data-anchor*="marketType"]', { timeout: 30000 });
    await sleep(1200);

    const extracted = await page.evaluate(() => {
      const normalize = (value) => String(value || '').replace(/\s+/g, ' ').trim();

      const safeAnchorToObject = (anchorValue) => {
        if (!anchorValue || !anchorValue.startsWith('outcome_')) return null;
        try {
          let jsonStr = anchorValue.slice('outcome_'.length);
          // Decode HTML entities in the JSON string
          jsonStr = jsonStr
            .replace(/&quot;/g, '"')
            .replace(/&amp;/g, '&')
            .replace(/&#039;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>');
          return JSON.parse(jsonStr);
        } catch {
          return null;
        }
      };

      const marketItems = Array.from(document.querySelectorAll('[data-id="market-item"]'));
      const totalMarkets = [];

      marketItems.forEach((marketItem) => {
        const titleCandidateEls = Array.from(marketItem.querySelectorAll('[data-testid="modulor-typography"]'));
        const marketTitle = normalize(
          titleCandidateEls
            .map((el) => normalize(el.textContent))
            .find((txt) => txt && txt.length <= 80)
        );

        const rowsByLine = new Map();

        const outcomes = Array.from(marketItem.querySelectorAll('[data-anchor^="outcome_"]'));
        outcomes.forEach((outcomeEl) => {
          const parsed = safeAnchorToObject(outcomeEl.getAttribute('data-anchor'));
          if (!parsed) return;
          if (Number(parsed.marketType) !== 5) return;

          const line = normalize((parsed.values && parsed.values[0]) || '');
          if (!line) return;

          const odd = normalize(outcomeEl.querySelector('[data-id="odds-value"]')?.textContent || '');
          if (!odd || odd === '—') return;

          if (!rowsByLine.has(line)) {
            rowsByLine.set(line, { line, over: null, under: null });
          }

          const row = rowsByLine.get(line);
          const outcomeType = Number(parsed.outcomeType);

          if (outcomeType === 4) {
            row.over = odd;
          } else if (outcomeType === 5) {
            row.under = odd;
          }
        });

        const rows = Array.from(rowsByLine.values()).filter((row) => row.over || row.under);
        if (!rows.length) return;

        rows.sort((a, b) => {
          const fa = Number(String(a.line).replace(',', '.'));
          const fb = Number(String(b.line).replace(',', '.'));
          if (Number.isNaN(fa) || Number.isNaN(fb)) {
            return String(a.line).localeCompare(String(b.line), 'uk');
          }
          return fa - fb;
        });

        totalMarkets.push({
          title: marketTitle || 'Тотал',
          rows,
        });
      });

      const preferred = totalMarkets.find((m) => /тотал|total/i.test(m.title)) || totalMarkets[0] || null;

      return {
        marketTitle: preferred ? preferred.title : null,
        totals: preferred ? preferred.rows : [],
      };
    });

    return extracted;
  } finally {
    await browser.close();
  }
}

(async () => {
  const { href, proxy } = parseArgs();

  if (!isValidEventUrl(href)) {
    process.stdout.write(JSON.stringify({ ok: false, error: 'Invalid event URL' }));
    process.exit(1);
    return;
  }

  try {
    const data = await scrapeTotals(href, proxy);
    process.stdout.write(JSON.stringify({ ok: true, href, ...data }));
    process.exit(0);
  } catch (error) {
    process.stdout.write(JSON.stringify({ ok: false, error: error?.message || String(error) }));
    process.exit(1);
  }
})();
