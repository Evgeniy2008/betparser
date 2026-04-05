const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const BETPARSER_CACHE_DIR = process.env.BETPARSER_CACHE_DIR || 'D:\\BetparserCache';
const HTTP_PROXY = process.env.HTTP_PROXY || process.env.BETPARSER_PROXY || 'http://pEStQExmT_0:Ze9TmZ656Eed@rsg-42385.sp1.ovh:11001';

// Parse proxy URL — Chrome requires only host:port in --proxy-server, credentials via page.authenticate()
function parseProxyParts(proxyUrl) {
  if (!proxyUrl) return { host: '', user: '', pass: '' };
  try {
    const u = new URL(proxyUrl);
    return {
      host: u.host || '',
      user: u.username || '',
      pass: u.password || '',
    };
  } catch (_) {
    return { host: proxyUrl, user: '', pass: '' };
  }
}

const NORMALIZED_PROXY = HTTP_PROXY ? String(HTTP_PROXY).trim() : '';
const CACHE_ROOT = path.resolve(BETPARSER_CACHE_DIR);
const PUPPETEER_CACHE_DIR = path.join(CACHE_ROOT, 'puppeteer');
const TEMP_DIR = path.join(CACHE_ROOT, 'temp');
const PROFILE_ROOT = path.join(CACHE_ROOT, 'profiles');

for (const dir of [CACHE_ROOT, PUPPETEER_CACHE_DIR, TEMP_DIR, PROFILE_ROOT]) {
  fs.mkdirSync(dir, { recursive: true });
}

process.env.BETPARSER_CACHE_DIR = CACHE_ROOT;
process.env.PUPPETEER_CACHE_DIR = PUPPETEER_CACHE_DIR;
process.env.TEMP = TEMP_DIR;
process.env.TMP = TEMP_DIR;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function parseArgs() {
  const [, , hrefArg, ...rest] = process.argv;
  const opts = { href: hrefArg || '', proxy: process.env.UA_PROXY || NORMALIZED_PROXY };

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
  const proxyParts = parseProxyParts(proxy);
  const launchOptions = {
    headless: true,
    userDataDir: path.join(PROFILE_ROOT, `totals-pin-${process.pid}-${Date.now()}`),
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-gpu',
      '--window-size=1366,900',
    ],
  };
  if (proxyParts.host) {
    launchOptions.args.push(`--proxy-server=${proxyParts.host}`);
  }

  const browser = await puppeteer.launch(launchOptions);
  try {
    const page = await browser.newPage();
    if (proxyParts.user && proxyParts.pass) {
      await page.authenticate({ username: proxyParts.user, password: proxyParts.pass });
    }
    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
    );
    await page.setViewport({ width: 1366, height: 900 });

    await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 });

    // Клик по cookie-баннеру, если есть
    try {
      const cookieBtn = await page.$x("//button[contains(., 'Accept') or contains(., 'Принять')]");
      if (cookieBtn.length) {
        await cookieBtn[0].click();
        await sleep(1000);
      }
    } catch {}

    // Дождаться блока с тоталами
    await page.waitForSelector('span.titleText-BgvECQYfHf', {timeout: 20000});

    // Клик по "See more" внутри блока "Total – Match"
    await page.evaluate(() => {
      const totalBlock = Array.from(document.querySelectorAll('div.primary-Z9ojHlU8JP.marketGroup-wMlWprW2iC')).find(
        el => el.querySelector('span.titleText-BgvECQYfHf') && /total/i.test(el.querySelector('span.titleText-BgvECQYfHf').textContent)
      );
      if (totalBlock) {
        const seeMoreBtn = Array.from(totalBlock.querySelectorAll('button.button-VcnnvaBxJw')).find(
          btn => btn.textContent && btn.textContent.toLowerCase().includes('see more')
        );
        if (seeMoreBtn) {
          seeMoreBtn.click();
        }
      }
    });
    await sleep(1200);

    // Собрать тоталы из раскрытого блока
    const extracted = await page.evaluate(() => {
      const norm = (v) => String(v || '').replace(/\s+/g, ' ').trim();
      // Найти блок "Total – Match"
      const totalBlock = Array.from(document.querySelectorAll('div.primary-Z9ojHlU8JP.marketGroup-wMlWprW2iC')).find(
        el => el.querySelector('span.titleText-BgvECQYfHf') && /total/i.test(el.querySelector('span.titleText-BgvECQYfHf').textContent)
      );
      if (!totalBlock) return { marketTitle: null, totals: [], teams: [] };

      // Собрать все строки тоталов
      const rows = [];
      const buttonRows = totalBlock.querySelectorAll('div.buttonRow-zWMLOGu5YB');
      buttonRows.forEach(row => {
        const btns = row.querySelectorAll('button.market-btn');
        if (btns.length === 2) {
          const overBtn = btns[0];
          const underBtn = btns[1];
          const overLabel = overBtn.querySelector('span.label-GT4CkXEOFj')?.textContent || '';
          const underLabel = underBtn.querySelector('span.label-GT4CkXEOFj')?.textContent || '';
          const overPrice = overBtn.querySelector('span.price-r5BU0ynJha')?.textContent || '';
          const underPrice = underBtn.querySelector('span.price-r5BU0ynJha')?.textContent || '';
          // Извлечь линию тотала
          const lineMatch = overLabel.match(/Over\s*([0-9.]+)/i);
          const line = lineMatch ? lineMatch[1] : '';
          if (line && overPrice && underPrice) {
            rows.push({
              line,
              over: overPrice,
              under: underPrice
            });
          }
        }
      });
      const marketTitle = totalBlock.querySelector('span.titleText-BgvECQYfHf')?.textContent || null;
      return {
        marketTitle,
        totals: rows,
        teams: []
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

