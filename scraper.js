/**
 * Betparser scraper (Node + Puppeteer)
 *
 * Requirements covered:
 * - Prematch: https://24-parik.club/uk/football
 * - Live:     https://24-parik.club/uk/football/live
 * - Progressive output for speed:
 *   1) write fast partial (first ~2 pages)
 *   2) continue full scroll and overwrite with full data
 * - Single-run and daemon modes
 */

const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');
const os = require('os');

const EVENT_LINK_SELECTOR = 'a[href*="/events/"]';
const PINNACLE_BASE = 'https://www.pinnacle.com';
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

const CONFIG = {
  urls: {
    prematch: 'https://24-parik.club/en/football',
    live: 'https://24-parik.club/en/football/live',
  },
  pinnacleUrls: {
    prematch: 'https://www.pinnacle.com/en/soccer/matchups/highlights/',
    prematchAll: 'https://www.pinnacle.com/en/soccer/matchups/',
    live: 'https://www.pinnacle.com/en/soccer/matchups/live/',
  },
  files: {
    prematch: path.join(__dirname, 'data', 'matches.json'),
    live: path.join(__dirname, 'data', 'matches_live.json'),
    pinnacleAll: path.join(__dirname, 'data', 'pinnacle_matches.json'),
    state: path.join(__dirname, 'data', 'scraper-state.json'),
    lock: path.join(__dirname, 'data', 'scraper.lock'),
  },
  proxy: process.env.UA_PROXY || '',
  headless: true,
  daemon: false,
  intervalMinutes: Math.max(1, Number(process.env.SCRAPER_INTERVAL_MIN || 2)),
  pinnacleMaxLeagues: Math.max(0, Number(process.env.PINNACLE_MAX_LEAGUES || 0)),

  initialLoadTimeout: 90000,
  scrollStep: 1400,
  scrollPause: 1100,
  maxScrolls: 500,
  noNewMatchesLimit: 8,

  // First fast publish target: 2 pages x 30 rows
  fastTargetMatches: 60,
};

const args = process.argv.slice(2);
if (args.includes('--debug')) CONFIG.headless = false;
if (args.includes('--daemon')) CONFIG.daemon = true;

const intervalArg = args.find((a) => a.startsWith('--interval'));
if (intervalArg) {
  const val = intervalArg.includes('=') ? intervalArg.split('=')[1] : args[args.indexOf('--interval') + 1];
  const parsed = Number(val);
  if (!Number.isNaN(parsed) && parsed > 0) CONFIG.intervalMinutes = parsed;
}

const proxyArg = args.find((a) => a.startsWith('--proxy'));
if (proxyArg) {
  CONFIG.proxy = proxyArg.includes('=') ? proxyArg.split('=')[1] : args[args.indexOf('--proxy') + 1];
}

function log(msg) {
  const ts = new Date().toISOString().slice(11, 19);
  console.log(`[${ts}] ${msg}`);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function uniqueBy(items, keyFn) {
  const seen = new Set();
  const out = [];
  for (const item of items) {
    const key = keyFn(item);
    if (seen.has(key)) continue;
    seen.add(key);
    out.push(item);
  }
  return out;
}

const TEAM_ALIASES = [
  [/\bkorea republic\b/g, 'south korea'],
  [/\brepublic of korea\b/g, 'south korea'],
  [/\bivory coast\b/g, 'cote d ivoire'],
  [/\bczechia\b/g, 'czech republic'],
  [/\buae\b/g, 'united arab emirates'],
  [/\busa\b/g, 'united states'],
  [/\buk\b/g, 'united kingdom'],
  [/\bholland\b/g, 'netherlands'],
  [/\binter\b/g, 'internazionale'],
  [/\bman utd\b/g, 'manchester united'],
  [/\bman united\b/g, 'manchester united'],
  [/\bman city\b/g, 'manchester city'],
  [/\bpsg\b/g, 'paris saint germain'],
];

function normalizeTeamName(name) {
  let value = String(name || '')
    .toLowerCase()
    .normalize('NFKD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\([^)]*\)/g, ' ')
    .replace(/\b(u\s*[- ]?\d{1,2}|under\s*\d{1,2}|match|women|woman|ladies|fc|cf|sc|afc|fk|ac)\b/g, ' ')
    .replace(/[^a-z0-9]+/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();

  for (const [pattern, replacement] of TEAM_ALIASES) {
    value = value.replace(pattern, replacement);
  }

  return value.replace(/\s+/g, ' ').trim();
}

function teamSimilarity(a, b) {
  const ta = new Set(normalizeTeamName(a).split(' ').filter(Boolean));
  const tb = new Set(normalizeTeamName(b).split(' ').filter(Boolean));
  if (!ta.size || !tb.size) return 0;

  let intersection = 0;
  for (const token of ta) if (tb.has(token)) intersection += 1;
  const union = new Set([...ta, ...tb]).size;
  return union ? (intersection / union) : 0;
}

function levenshteinDistance(a, b) {
  const s = String(a || '');
  const t = String(b || '');
  const rows = s.length + 1;
  const cols = t.length + 1;
  const dp = Array.from({ length: rows }, () => new Array(cols).fill(0));

  for (let i = 0; i < rows; i += 1) dp[i][0] = i;
  for (let j = 0; j < cols; j += 1) dp[0][j] = j;

  for (let i = 1; i < rows; i += 1) {
    for (let j = 1; j < cols; j += 1) {
      const cost = s[i - 1] === t[j - 1] ? 0 : 1;
      dp[i][j] = Math.min(
        dp[i - 1][j] + 1,
        dp[i][j - 1] + 1,
        dp[i - 1][j - 1] + cost
      );
    }
  }

  return dp[s.length][t.length];
}

function similarityByLevenshtein(a, b) {
  const s = normalizeTeamName(a);
  const t = normalizeTeamName(b);
  if (!s || !t) return 0;
  const maxLen = Math.max(s.length, t.length);
  if (!maxLen) return 0;
  return 1 - (levenshteinDistance(s, t) / maxLen);
}

function similarityByBigrams(a, b) {
  const s = normalizeTeamName(a).replace(/\s+/g, '');
  const t = normalizeTeamName(b).replace(/\s+/g, '');
  if (s.length < 2 || t.length < 2) return 0;

  const sBigrams = [];
  const tBigrams = [];
  for (let i = 0; i < s.length - 1; i += 1) sBigrams.push(s.slice(i, i + 2));
  for (let i = 0; i < t.length - 1; i += 1) tBigrams.push(t.slice(i, i + 2));

  const sMap = new Map();
  for (const g of sBigrams) sMap.set(g, (sMap.get(g) || 0) + 1);
  let inter = 0;
  for (const g of tBigrams) {
    const count = sMap.get(g) || 0;
    if (count > 0) {
      inter += 1;
      sMap.set(g, count - 1);
    }
  }

  return (2 * inter) / (sBigrams.length + tBigrams.length);
}

function compareTeamPhrases(a, b) {
  const token = teamSimilarity(a, b);
  const lev = similarityByLevenshtein(a, b);
  const bigram = similarityByBigrams(a, b);
  return (token * 0.45) + (lev * 0.35) + (bigram * 0.20);
}

function pairMatchScore(parikHome, parikAway, pinHome, pinAway) {
  const homeScore = compareTeamPhrases(parikHome, pinHome);
  const awayScore = compareTeamPhrases(parikAway, pinAway);
  return {
    score: homeScore + awayScore,
    minSide: Math.min(homeScore, awayScore),
  };
}

function extractTimeOfDay(text) {
  const value = String(text || '');
  const match = value.match(/\b([01]?\d|2[0-3]):([0-5]\d)\b/);
  return match ? `${match[1].padStart(2, '0')}:${match[2]}` : '';
}

function mergeParikWithPinnacle(parikMatches, pinnacleMatches) {
  const byExact = new Map();
  for (const pm of pinnacleMatches) {
    const h = normalizeTeamName(pm.home);
    const a = normalizeTeamName(pm.away);
    if (!h || !a) continue;

    const key = `${h}__${a}`;
    if (!byExact.has(key)) byExact.set(key, []);
    byExact.get(key).push(pm);
  }

  const used = new Set();
  let matched = 0;

  const merged = parikMatches.map((m) => {
    const h = normalizeTeamName(m.home);
    const a = normalizeTeamName(m.away);
    const key = `${h}__${a}`;
    const reverseKey = `${a}__${h}`;

    const usedKey = (candidate) => candidate.href || `${normalizeTeamName(candidate.home)}__${normalizeTeamName(candidate.away)}__${candidate.time || ''}`;

    let pin = (byExact.get(key) || []).find((candidate) => !used.has(usedKey(candidate)));
    let reversed = false;

    if (!pin) {
      pin = (byExact.get(reverseKey) || []).find((candidate) => !used.has(usedKey(candidate)));
      reversed = !!pin;
    }

    if (!pin) {
      let best = null;
      let bestScore = 0;
      let bestReversed = false;

      for (const candidate of pinnacleMatches) {
        if (used.has(usedKey(candidate))) continue;

        const directPair = pairMatchScore(m.home, m.away, candidate.home, candidate.away);
        const reversePair = pairMatchScore(m.home, m.away, candidate.away, candidate.home);
        const directScore = directPair.score;
        const revScore = reversePair.score;
        const minSide = Math.max(directPair.minSide, reversePair.minSide);
        const parikTime = extractTimeOfDay(m.time);
        const pinTime = extractTimeOfDay(candidate.time);
        const timeBonus = parikTime && pinTime && parikTime === pinTime ? 0.35 : 0;
        const score = Math.max(directScore, revScore) + timeBonus;

        if (score > bestScore && minSide >= 0.35) {
          bestScore = score;
          best = candidate;
          bestReversed = revScore > directScore;
        }
      }

      if (best && bestScore >= 1.05) {
        pin = best;
        reversed = bestReversed;
      }
    }

    if (!pin) {
      return {
        ...m,
        oddsPinnacle: { p1: null, x: null, p2: null },
      };
    }

    used.add(usedKey(pin));
    matched += 1;

    return {
      ...m,
      oddsPinnacle: reversed
        ? { p1: pin.odds?.p2 ?? null, x: pin.odds?.x ?? null, p2: pin.odds?.p1 ?? null }
        : { p1: pin.odds?.p1 ?? null, x: pin.odds?.x ?? null, p2: pin.odds?.p2 ?? null },
      pinnacle: {
        href: pin.href,
        league: pin.league,
        home: pin.home,
        away: pin.away,
      },
    };
  });

  return { merged, matched };
}

function ensureDataDir() {
  const dataDir = path.join(__dirname, 'data');
  if (!fs.existsSync(dataDir)) fs.mkdirSync(dataDir, { recursive: true });
}

function readJsonSafe(filePath, fallback = {}) {
  try {
    if (!fs.existsSync(filePath)) return fallback;
    return JSON.parse(fs.readFileSync(filePath, 'utf8'));
  } catch {
    return fallback;
  }
}

function writeJsonAtomic(filePath, data) {
  ensureDataDir();
  const tmp = `${filePath}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
  fs.renameSync(tmp, filePath);
}

function writeState(patch) {
  const prev = readJsonSafe(CONFIG.files.state, {});
  const next = {
    ...prev,
    ...patch,
    updatedAt: new Date().toISOString(),
  };
  writeJsonAtomic(CONFIG.files.state, next);
}

function processExistsByPid(pid) {
  if (!pid || Number.isNaN(Number(pid))) return false;
  try {
    process.kill(Number(pid), 0);
    return true;
  } catch {
    return false;
  }
}

function acquireLock() {
  ensureDataDir();

  if (fs.existsSync(CONFIG.files.lock)) {
    const lock = readJsonSafe(CONFIG.files.lock, {});
    const startedAt = new Date(lock.startedAt || 0).getTime();
    const isStale = !startedAt || (Date.now() - startedAt) > 1000 * 60 * 45;

    if (processExistsByPid(lock.pid) && !isStale) {
      return false;
    }

    try { fs.unlinkSync(CONFIG.files.lock); } catch {}
  }

  fs.writeFileSync(CONFIG.files.lock, JSON.stringify({
    pid: process.pid,
    startedAt: new Date().toISOString(),
    daemon: CONFIG.daemon,
  }, null, 2), 'utf8');

  return true;
}

function releaseLock() {
  try {
    if (!fs.existsSync(CONFIG.files.lock)) return;
    const lock = readJsonSafe(CONFIG.files.lock, {});
    if (!lock.pid || Number(lock.pid) === process.pid) {
      fs.unlinkSync(CONFIG.files.lock);
    }
  } catch {}
}

function saveStream(stream, phase, matches, cycleId) {
  const filePath = CONFIG.files[stream];
  const payload = {
    stream,
    phase,
    cycleId,
    updated: new Date().toISOString(),
    total: matches.length,
    matches,
  };

  writeJsonAtomic(filePath, payload);
  writeState({
    status: 'running',
    cycleId,
    [`${stream}Phase`]: phase,
    [`${stream}Updated`]: payload.updated,
    [`${stream}Total`]: payload.total,
  });
}

async function newConfiguredPage(browser) {
  const page = await browser.newPage();

  await page.setUserAgent(
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36'
  );

  await page.setViewport({ width: 1440, height: 900 });
  await page.setExtraHTTPHeaders({
    'Accept-Language': 'uk-UA,uk;q=0.9,en-US;q=0.8,en;q=0.7',
  });

  return page;
}

async function tryAcceptPinnacleCookies(page) {
  try {
    await page.evaluate(() => {
      const buttons = Array.from(document.querySelectorAll('button'));
      const acceptBtn = buttons.find((button) => /accept/i.test((button.textContent || '').trim()));
      if (acceptBtn) acceptBtn.click();
    });
  } catch {}
}

async function waitForPinnacleReady(page, timeoutMs = 45000) {
  await page.waitForFunction(
    () => {
      const hasLeague = !!document.querySelector('a.rowLink-rtJhYcYkm5[href*="/matchups/"]');
      const hasMoneyline = !!document.querySelector('div[data-test-id="moneyline"] button[class*="market-btn"] span[class*="price-"]');
      return hasLeague || hasMoneyline;
    },
    { timeout: timeoutMs }
  );
}

async function scrollPinnaclePage(page, maxScrolls = 20) {
  let prevRows = 0;
  let stagnant = 0;

  for (let i = 0; i < maxScrolls; i += 1) {
    await page.evaluate(() => window.scrollBy(0, Math.max(900, Math.floor(window.innerHeight * 0.9))));
    await sleep(450);

    const rows = await page.evaluate(() => document.querySelectorAll('div[class*="row-k9"]').length);
    if (rows === prevRows) {
      stagnant += 1;
      if (stagnant >= 4) break;
    } else {
      stagnant = 0;
    }
    prevRows = rows;
  }

  await page.evaluate(() => window.scrollTo(0, 0));
}

async function extractMatches(page) {
  return page.evaluate(() => {
    const cards = document.querySelectorAll('a[href*="/events/"]');
    const seen = new Set();
    const results = [];

    cards.forEach((card) => {
      const hrefRaw = card.getAttribute('href') || '';
      if (!hrefRaw || seen.has(hrefRaw)) return;
      seen.add(hrefRaw);

      let league = '';
      const leagueEl =
        card.closest('[class*="section"]')?.querySelector('[class*="label"]') ||
        card.parentElement?.closest('div')?.previousElementSibling?.querySelector('[class*="label"]') ||
        card.querySelector('[class*="label"]');
      if (leagueEl) league = leagueEl.textContent.trim();

      const nameEls = card.querySelectorAll('[class*="name_"][class*="competitor"], [class*="name-horizontal"]');
      const names = Array.from(nameEls).map((el) => el.textContent.trim()).filter(Boolean);
      const home = names[0] || '';
      const away = names[1] || '';

      const timeEl = card.querySelector('[class*="time_"], [class*="time-status"] time, time');
      const dateEl = card.querySelector('[class*="date_"], [class*="time-status-date"]');
      const matchTime = timeEl ? timeEl.textContent.trim() : '';
      const matchDate = dateEl ? dateEl.textContent.trim() : '';

      const isLive = !!card.querySelector('[class*="live"]');

      const outcomeButtons = card.querySelectorAll('[class*="wrapper__aeiCE"], [class*="wrapper__noCZB"], [class*="total__"]');
      const odds = {};

      outcomeButtons.forEach((btn) => {
        const nameEl = btn.querySelector('[data-id="outcome-name-value"]');
        const valueEl = btn.querySelector('[data-id="odds-value"]');
        if (!nameEl || !valueEl) return;

        const name = nameEl.textContent.trim();
        const value = valueEl.textContent.trim();
        if (name && value && value !== '—') {
          odds[name] = value;
        }
      });

      const p1 = odds['П1'] || odds['1'] || odds['W1'] || null;
      const x = odds['Х'] || odds['X'] || odds['Draw'] || null;
      const p2 = odds['П2'] || odds['2'] || odds['W2'] || null;

      if (!home && !away) return;

      results.push({
        href: hrefRaw.startsWith('http') ? hrefRaw : `https://24-parik.club${hrefRaw}`,
        league,
        home,
        away,
        date: matchDate,
        time: matchTime,
        isLive,
        odds: { p1, x, p2 },
        _raw: odds,
      });
    });

    return results;
  });
}

async function extractPinnacleSnapshot(page, expectedMode) {
  return page.evaluate((mode, baseUrl) => {
    const toAbsolute = (href) => {
      if (!href) return '';
      if (href.startsWith('http')) return href;
      return `${baseUrl}${href}`;
    };

    const root = mode === 'live'
      ? (document.querySelector('[data-test-id="LiveContainer"][data-mode="live"]') || document)
      : (document.querySelector('[data-test-id="HighlightsContainer"][data-mode="highlights"]') || document);

    const leagueLinks = Array.from(root.querySelectorAll('a[href*="/en/soccer/"][href*="/matchups/"]'))
      .map((a) => toAbsolute(a.getAttribute('href')))
      .filter((href) => href && !href.includes('/matchups/live'));

    const moneylineBlocks = Array.from(root.querySelectorAll('div[data-test-id="moneyline"]'));
    const matches = [];

    moneylineBlocks.forEach((moneyline) => {
      const row = moneyline.closest('div[class*="row-k9"]') || moneyline.parentElement;
      if (!row) return;

      const nameEls = row.querySelectorAll('[class*="matchupMetadata"] > div[class*="gameInfoLabel"] > span[class*="gameInfoLabel"]');
      const names = Array.from(nameEls).map((el) => (el.textContent || '').trim()).filter(Boolean);
      if (names.length < 2) return;

      const eventHref = row.querySelector('a[href*="/en/soccer/"]:not([href*="/matchups/"])')?.getAttribute('href') || '';
      const dateTimeText = (row.querySelector('[class*="matchupDate"]')?.textContent || '').replace(/\s+/g, ' ').trim();

      const prices = Array.from(row.querySelectorAll('div[data-test-id="moneyline"] button[class*="market-btn"] span[class*="price-"]'))
        .map((el) => (el.textContent || '').trim())
        .filter(Boolean);
      if (prices.length < 3) return;

      let league = '';
      let cursor = row.previousElementSibling;
      while (cursor) {
        const link = cursor.querySelector('a[href*="/en/soccer/"][href*="/matchups/"]');
        if (link) {
          league = (link.textContent || '').replace(/\s+/g, ' ').trim();
          break;
        }
        cursor = cursor.previousElementSibling;
      }

      matches.push({
        href: toAbsolute(eventHref),
        league,
        home: names[0] || '',
        away: names[1] || '',
        date: '',
        time: dateTimeText,
        isLive: mode === 'live' || !!row.querySelector('[class*="live-"]'),
        odds: {
          p1: prices[0] || null,
          x: prices[1] || null,
          p2: prices[2] || null,
        },
      });
    });

    return {
      leagueLinks: Array.from(new Set(leagueLinks)),
      matches,
    };
  }, expectedMode, PINNACLE_BASE);
}

async function scrapePinnacleStream(browser, stream, cycleId) {
  const mode = stream === 'live' ? 'live' : 'prematch';
  const mainUrls = mode === 'prematch'
    ? [CONFIG.pinnacleUrls.prematch, CONFIG.pinnacleUrls.prematchAll]
    : [CONFIG.pinnacleUrls.live];
  const page = await newConfiguredPage(browser);

  writeState({ status: 'running', cycleId, currentTask: `pinnacle:${stream}:open` });

  let allMatches = [];
  let leagueLinksAll = [];

  for (const mainUrl of mainUrls) {
    log(`[pinnacle:${stream}] Open ${mainUrl}`);
    try {
      await page.goto(mainUrl, { waitUntil: 'domcontentloaded', timeout: CONFIG.initialLoadTimeout });
      await tryAcceptPinnacleCookies(page);
      await waitForPinnacleReady(page, 60000);
      await scrollPinnaclePage(page, 24);
      const mainSnapshot = await extractPinnacleSnapshot(page, mode);
      allMatches.push(...mainSnapshot.matches);
      leagueLinksAll.push(...mainSnapshot.leagueLinks);
    } catch (err) {
      log(`[pinnacle:${stream}] Main page unavailable: ${err?.message || String(err)}`);
    }
  }

  const leagueUrls = Array.from(new Set(leagueLinksAll
    .map((url) => url.split('#')[0])
    .map((url) => `${url}#all`)));

  const selectedLeagueUrls = CONFIG.pinnacleMaxLeagues > 0
    ? leagueUrls.slice(0, CONFIG.pinnacleMaxLeagues)
    : leagueUrls;

  for (let i = 0; i < selectedLeagueUrls.length; i += 1) {
    const leagueUrl = selectedLeagueUrls[i];
    try {
      writeState({ currentTask: `pinnacle:${stream}:league:${i + 1}/${selectedLeagueUrls.length}` });
      await page.goto(leagueUrl, { waitUntil: 'domcontentloaded', timeout: CONFIG.initialLoadTimeout });
      await tryAcceptPinnacleCookies(page);
      await waitForPinnacleReady(page, 45000);
      await scrollPinnaclePage(page, 28);
      const snapshot = await extractPinnacleSnapshot(page, mode);
      allMatches.push(...snapshot.matches);
    } catch {
      log(`[pinnacle:${stream}] Skip league page: ${leagueUrl}`);
    }
  }

  await page.close();

  const deduped = uniqueBy(
    allMatches.filter((m) => m.home && m.away),
    (m) => `${m.href}|${m.home}|${m.away}`
  );

  log(`[pinnacle:${stream}] Collected: ${deduped.length} matches from ${selectedLeagueUrls.length}/${leagueUrls.length} leagues`);
  return deduped;
}

async function scrapeStream(browser, stream, cycleId) {
  const url = CONFIG.urls[stream];
  const page = await newConfiguredPage(browser);

  log(`[${stream}] Open ${url}`);
  writeState({ status: 'running', cycleId, currentTask: `${stream}:open`, currentStream: stream });

  await page.goto(url, { waitUntil: 'networkidle2', timeout: CONFIG.initialLoadTimeout });

  try {
    await page.waitForSelector(`${EVENT_LINK_SELECTOR}, [data-id="odds-value"]`, {
      timeout: CONFIG.initialLoadTimeout,
    });
  } catch {
    log(`[${stream}] Warning: cards not detected quickly`);
  }

  await sleep(1600);

  let prevCount = 0;
  let noNewCount = 0;
  let scrolls = 0;
  let fastWritten = false;

  while (scrolls < CONFIG.maxScrolls) {
    await page.evaluate((step) => window.scrollBy(0, step), CONFIG.scrollStep);
    await sleep(CONFIG.scrollPause);

    const currentCount = await page.evaluate(
      () => document.querySelectorAll('a[href*="/events/"]').length
    );

    if (!fastWritten && currentCount > 0 && (currentCount >= CONFIG.fastTargetMatches || scrolls >= 3)) {
      const fastMatches = await extractMatches(page);
      saveStream(stream, 'partial', fastMatches.slice(0, Math.max(CONFIG.fastTargetMatches, fastMatches.length)), cycleId);
      fastWritten = true;
      log(`[${stream}] Fast publish: ${fastMatches.length} matches`);
      writeState({ currentTask: `${stream}:partial-written` });
    }

    log(`[${stream}] Scroll ${scrolls + 1}: ${currentCount}`);

    if (currentCount === prevCount) {
      noNewCount += 1;
      if (noNewCount >= CONFIG.noNewMatchesLimit) break;
    } else {
      noNewCount = 0;
    }

    prevCount = currentCount;
    scrolls += 1;
  }

  writeState({ currentTask: `${stream}:extract-final` });
  const fullMatches = await extractMatches(page);
  saveStream(stream, 'full', fullMatches, cycleId);

  await page.close();
  log(`[${stream}] Final publish: ${fullMatches.length} matches`);

  return fullMatches;
}

async function runCycle() {
  const cycleId = new Date().toISOString();
  writeState({ status: 'running', cycleId, cycleStartedAt: cycleId, currentTask: 'launch-browser' });
  const profileDir = path.join(PROFILE_ROOT, `betparser-puppeteer-${process.pid}-${Date.now()}`);
  fs.mkdirSync(profileDir, { recursive: true });

  const launchOptions = {
    headless: CONFIG.headless,
    userDataDir: profileDir,
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
      '--disable-accelerated-2d-canvas',
      '--disable-gpu',
      '--lang=uk-UA,uk',
      '--window-size=1440,900',
    ],
  };

  if (CONFIG.proxy) {
    launchOptions.args.push(`--proxy-server=${CONFIG.proxy}`);
    writeState({ proxy: CONFIG.proxy });
    log(`Using proxy: ${CONFIG.proxy}`);
  } else {
    log('WARNING: no proxy configured (outside UA may fail)');
  }

  const browser = await puppeteer.launch(launchOptions);

  try {
    const prematchParik = await scrapeStream(browser, 'prematch', cycleId);
    const liveParik = await scrapeStream(browser, 'live', cycleId);

    let prematchPinnacle = [];
    let livePinnacle = [];

    writeState({ currentTask: 'pinnacle:prematch' });
    try {
      prematchPinnacle = await scrapePinnacleStream(browser, 'prematch', cycleId);
    } catch (err) {
      log(`[pinnacle:prematch] Failed: ${err?.message || String(err)}`);
    }

    const prematchMerged = mergeParikWithPinnacle(prematchParik, prematchPinnacle);
    saveStream('prematch', 'full', prematchMerged.merged, cycleId);

    writeState({ currentTask: 'pinnacle:live' });
    try {
      livePinnacle = await scrapePinnacleStream(browser, 'live', cycleId);
    } catch (err) {
      log(`[pinnacle:live] Failed: ${err?.message || String(err)}`);
    }

    const liveMerged = mergeParikWithPinnacle(liveParik, livePinnacle);
    saveStream('live', 'full', liveMerged.merged, cycleId);

    // Save all raw Pinnacle matches to a separate file for inspection
    const pinnacleAllOutput = {
      updatedAt: new Date().toISOString(),
      cycleId,
      prematch: prematchPinnacle,
      live: livePinnacle,
    };
    fs.writeFileSync(CONFIG.files.pinnacleAll, JSON.stringify(pinnacleAllOutput, null, 2), 'utf8');
    log(`[pinnacle] Saved all Pinnacle matches: prematch=${prematchPinnacle.length}, live=${livePinnacle.length} → data/pinnacle_matches.json`);

    writeState({
      status: 'idle',
      cycleId,
      cycleFinishedAt: new Date().toISOString(),
      currentTask: null,
      total: null,
      totalPrematch: prematchMerged.merged.length,
      totalLive: liveMerged.merged.length,
      totalMatchedPinnaclePrematch: prematchMerged.matched,
      totalMatchedPinnacleLive: liveMerged.matched,
      lastError: null,
      lastSuccessAt: new Date().toISOString(),
    });

    log(`Cycle done. Prematch=${prematchMerged.merged.length} (pin matched=${prematchMerged.matched}), Live=${liveMerged.merged.length} (pin matched=${liveMerged.matched})`);
    return {
      prematchTotal: prematchMerged.merged.length,
      liveTotal: liveMerged.merged.length,
    };
  } finally {
    await browser.close();
    try {
      fs.rmSync(profileDir, { recursive: true, force: true });
    } catch {}
  }
}

async function runSingle() {
  if (!acquireLock()) {
    log('Another scraper instance is running. Exit.');
    process.exit(2);
    return;
  }

  writeState({ mode: 'single', status: 'running', pid: process.pid, startedAt: new Date().toISOString() });

  try {
    await runCycle();
    releaseLock();
    process.exit(0);
  } catch (err) {
    writeState({ status: 'error', lastError: err.message || String(err), lastFailureAt: new Date().toISOString() });
    releaseLock();
    console.error('[ERROR]', err.message || String(err));
    process.exit(1);
  }
}

async function runDaemon() {
  if (!acquireLock()) {
    log('Another scraper instance is running. Exit.');
    process.exit(2);
    return;
  }

  writeState({ mode: 'daemon', status: 'running', pid: process.pid, startedAt: new Date().toISOString(), intervalMinutes: CONFIG.intervalMinutes });
  log(`Daemon mode. Interval=${CONFIG.intervalMinutes} min`);

  while (true) {
    try {
      await runCycle();
    } catch (err) {
      writeState({ status: 'error', lastError: err.message || String(err), lastFailureAt: new Date().toISOString() });
      console.error('[ERROR]', err.message || String(err));
    }

    writeState({ status: 'sleep', currentTask: null });
    await sleep(CONFIG.intervalMinutes * 60 * 1000);
  }
}

function gracefulStop() {
  writeState({ status: 'stopped', stoppedAt: new Date().toISOString(), currentTask: null });
  releaseLock();
  process.exit(0);
}

process.on('SIGINT', gracefulStop);
process.on('SIGTERM', gracefulStop);

if (CONFIG.daemon) {
  runDaemon().catch((err) => {
    writeState({ status: 'error', lastError: err.message || String(err), lastFailureAt: new Date().toISOString() });
    releaseLock();
    process.exit(1);
  });
} else {
  runSingle();
}
