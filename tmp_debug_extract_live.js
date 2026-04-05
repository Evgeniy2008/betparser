const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

const BETPARSER_CACHE_DIR = process.env.BETPARSER_CACHE_DIR || 'D:\\BetparserCache';
const HTTP_PROXY = process.env.HTTP_PROXY || process.env.BETPARSER_PROXY || 'http://pEStQExmT_0:Ze9TmZ656Eed@rsg-42385.sp1.ovh:11001';

// Normalize proxy URL to handle special characters in credentials
function normalizeProxyUrl(proxyUrl) {
  if (!proxyUrl) return '';
  try {
    const url = new URL(proxyUrl);
    if (url.username && url.password) {
      // Encode username and password
      const encodedUser = encodeURIComponent(url.username);
      const encodedPass = encodeURIComponent(url.password);
      return `${url.protocol}//${encodedUser}:${encodedPass}@${url.host}`;
    }
    return proxyUrl;
  } catch (_) {
    return proxyUrl;
  }
}

const NORMALIZED_PROXY = normalizeProxyUrl(HTTP_PROXY);
const PROFILE_ROOT = path.join(path.resolve(BETPARSER_CACHE_DIR), 'profiles');
const profileDir = path.join(PROFILE_ROOT, `debug-extract-live-${process.pid}-${Date.now()}`);
fs.mkdirSync(profileDir, { recursive: true });

const CHROME_NO_CACHE_ARGS = [
  '--disable-cache',
  '--disable-application-cache',
  '--disk-cache-size=0',
  '--media-cache-size=0',
  '--aggressive-cache-discard',
];

function isFootballLikeSport(sport) {
  return sport === 'football' || sport === 'football_live';
}

async function inspectParik(page) {
  return page.evaluate((sportName) => {
    const results = [];
    const cards = document.querySelectorAll('a[href*="/events/"]');
    const normSpace = (value) => String(value || '').replace(/\s+/g, ' ').trim();

    const extractLeague = (card) => {
      const directSelectors = [
        '[class*="champ-label"] [class*="label"]',
        '[class*="wrapper__bKvil"] [class*="label"]',
        '[class*="wrapper__tfMk3"] [class*="label__N7n59"]',
        '[class*="wrapper__tfMk3"] [class*="badge-container"] [class*="label"]',
        '[class*="event-header"] [class*="label"]'
      ];
      for (const selector of directSelectors) {
        const text = normSpace(card.querySelector(selector)?.textContent || '');
        if (text && text.length > 2 && !/^\d+$/.test(text) && !/^\d{1,2}:\d{2}$/.test(text)) {
          return text;
        }
      }
      const candidates = Array.from(card.querySelectorAll('[class*="champ-label"], [class*="wrapper__bKvil"], [class*="wrapper__tfMk3"], [class*="event-header"]'))
        .map(el => normSpace(el.textContent || ''))
        .filter(Boolean)
        .flatMap(text => text.split(/\s{2,}|\n+/).map(part => normSpace(part)))
        .filter(Boolean)
        .filter(text => text.length > 2)
        .filter(text => !/^\d+$/.test(text))
        .filter(text => !/^\d{1,2}:\d{2}$/.test(text))
        .filter(text => !/^(1|x|2)$/i.test(text))
        .filter(text => !/(^|\s)(over|under|live|match)$/i.test(text));
      return candidates[0] || null;
    };

    const extractLiveStatus = (card) => {
      const texts = Array.from(card.querySelectorAll('[class*="matchupDate"], [class*="time-status"], [class*="styles_time__"], [class*="live"], time'))
        .map(el => normSpace(el.textContent || ''))
        .filter(Boolean);
      for (const text of texts) {
        if (/\b\d{1,2}:\d{2}\b/.test(text)) {
          return text.match(/\b\d{1,2}:\d{2}\b/)[0];
        }
        if (/(half|period|quarter|set|live|break|overtime|extra time|\d+'|\d+\+\d+)/i.test(text)) {
          return text;
        }
      }
      return texts[0] || null;
    };

    const extractLabeledOdds = (root, labels) => {
      const found = new Map();
      const buttons = Array.from(root.querySelectorAll('div[class*="wrapper__aeiCE"], div[class*="wrapper__noCZB"], button[class*="market-btn"]'));
      for (const btn of buttons) {
        const label = normSpace(btn.querySelector('[data-id="outcome-name-value"]')?.textContent || '');
        const oddText = normSpace(btn.querySelector('[data-id="odds-value"]')?.textContent || btn.querySelector('span[class*="price-"]')?.textContent || '');
        const oddNum = parseFloat(oddText);
        if (labels.includes(label) && !isNaN(oddNum) && oddNum > 0) {
          found.set(label, oddNum.toString());
        }
      }
      if (labels.every(label => found.has(label))) {
        return labels.map(label => found.get(label));
      }
      return [];
    };

    for (const card of cards) {
      const nameEls = card.querySelectorAll('[class*="name_"][class*="competitor"], [class*="name-horizontal"]');
      if (nameEls.length < 2) continue;
      const home = nameEls[0]?.textContent?.trim() || '';
      const away = nameEls[1]?.textContent?.trim() || '';
      const league = extractLeague(card);
      const time = extractLiveStatus(card);
      const mainMarket = card.querySelector('[data-onboarding="event-card-main-market"]') || card.querySelector('[class*="main-markets"]') || card;
      const odds = extractLabeledOdds(mainMarket, ['1', 'X', '2']);
      if (home && away && odds.length === 3) {
        results.push({ home, away, league, time, odds });
      }
    }

    return { cards: cards.length, matches: results.length, sample: results.slice(0, 5) };
  }, 'football_live');
}

async function inspectPinnacle(page) {
  return page.evaluate((sportPath) => {
    const rows = Array.from(document.querySelectorAll('div[class*="row-"]'));
    let currentLeague = null;
    const results = [];
    for (const row of rows) {
      const leagueAnchor = row.querySelector('.metadata-ANkHTQFSEA a[href*="/matchups/"]:not([href*="-vs-"])');
      if (leagueAnchor) {
        currentLeague = (leagueAnchor.textContent || '').replace(/\s+/g, ' ').trim();
      }
      const eventAnchor = row.querySelector(`a[href*="${sportPath}"][href*="-vs-"]`);
      const moneyline = row.querySelector('div[data-test-id="moneyline"]');
      if (!eventAnchor || !moneyline) continue;
      const nameEls = eventAnchor.querySelectorAll('[class*="matchupMetadata"] > div[class*="gameInfoLabel"] > span[class*="gameInfoLabel"]');
      const names = Array.from(nameEls).map(el => (el.textContent || '').replace(/\s+/g, ' ').trim()).filter(Boolean);
      const prices = Array.from(moneyline.querySelectorAll('button[class*="market-btn"] span[class*="price-"]')).map(el => (el.textContent || '').trim()).filter(Boolean).slice(0, 3);
      if (names.length >= 2 && prices.length >= 3) {
        results.push({ league: currentLeague, home: names[0], away: names[1], prices, href: eventAnchor.getAttribute('href') });
      }
    }
    return { rows: rows.length, matches: results.length, sample: results.slice(0, 5) };
  }, '/soccer/');
}

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    userDataDir: profileDir,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      `--proxy-server=${NORMALIZED_PROXY}`,
      ...CHROME_NO_CACHE_ARGS,
    ],
  });

  const parik = await browser.newPage();
  await parik.goto('https://24-parik.club/en/football/live', { waitUntil: 'networkidle2', timeout: 45000 });
  await new Promise((resolve) => setTimeout(resolve, 2000));
  const parikInfo = await inspectParik(parik);
  console.log('PARIK');
  console.log(JSON.stringify(parikInfo, null, 2));
  await parik.close();

  const pinn = await browser.newPage();
  await pinn.goto('https://www.pinnacle.com/en/soccer/matchups/live/', { waitUntil: 'domcontentloaded', timeout: 45000 });
  await pinn.waitForSelector('div[data-test-id="moneyline"]', { timeout: 20000 }).catch(() => null);
  await new Promise((resolve) => setTimeout(resolve, 2000));
  const pinnInfo = await inspectPinnacle(pinn);
  console.log('PINNACLE');
  console.log(JSON.stringify(pinnInfo, null, 2));
  await pinn.close();

  await browser.close();
  try {
    fs.rmSync(profileDir, { recursive: true, force: true });
  } catch {}
})().catch((err) => {
  console.error(err);
  try {
    fs.rmSync(profileDir, { recursive: true, force: true });
  } catch {}
  process.exit(1);
});
