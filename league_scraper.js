const puppeteer = require('puppeteer');
const axios = require('axios');
const fs = require('fs');
const path = require('path');

const SCRAPER_MODE = process.env.SCRAPER_MODE || 'file';
const POST_URL = process.env.POST_URL || '';
const SCRAPER_SCOPE = String(process.env.SCRAPER_SCOPE || 'full').toLowerCase();
const BETPARSER_CACHE_DIR = process.env.BETPARSER_CACHE_DIR || path.join(__dirname, '.cache');
const PREMATCH_CONCURRENCY = Math.max(1, Number.parseInt(process.env.PREMATCH_CONCURRENCY || '3', 10) || 3);

const CACHE_ROOT = path.resolve(BETPARSER_CACHE_DIR);
const PUPPETEER_CACHE_DIR = process.env.PUPPETEER_CACHE_DIR
  ? path.resolve(process.env.PUPPETEER_CACHE_DIR)
  : '';
const TEMP_DIR = path.join(CACHE_ROOT, 'temp');
const PROFILE_ROOT = path.join(CACHE_ROOT, 'profiles');

for (const dir of [CACHE_ROOT, TEMP_DIR, PROFILE_ROOT, ...(PUPPETEER_CACHE_DIR ? [PUPPETEER_CACHE_DIR] : [])]) {
  fs.mkdirSync(dir, { recursive: true });
}

process.env.BETPARSER_CACHE_DIR = CACHE_ROOT;
if (PUPPETEER_CACHE_DIR) {
  process.env.PUPPETEER_CACHE_DIR = PUPPETEER_CACHE_DIR;
} else {
  delete process.env.PUPPETEER_CACHE_DIR;
}
process.env.TEMP = TEMP_DIR;
process.env.TMP = TEMP_DIR;

// Parsing configuration
const isLiveOnly = SCRAPER_SCOPE === 'live-only';
const CONFIG = {
  dataDir: path.join(__dirname, 'data'),
  headless: true,
  timeout: isLiveOnly ? 15000 : 45000,
  browserRefreshEveryLeagues: isLiveOnly ? 1 : 4, // Live: refresh more often
  profileRoot: PROFILE_ROOT,
  maxScrolls: isLiveOnly ? 10 : 140,
  scrollLevel: isLiveOnly ? 2 : 5,    // Live: less aggressive scroll
};

function createBrowserProfileDir(prefix = 'browser') {
  const dir = path.join(
    CONFIG.profileRoot,
    `${prefix}-${process.pid}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  );
  fs.mkdirSync(dir, { recursive: true });
  return dir;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function withRetry(fn, retries = 2, label = '') {
  let lastErr = null;
  for (let attempt = 1; attempt <= retries + 1; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastErr = err;
      console.warn(`    [retry${attempt}/${retries + 1}${label ? `:${label}` : ''}] ${err?.message || String(err)}`);
      await sleep(1200 * attempt);
    }
  }
  throw lastErr;
}

// Parse a leagues config file (task.txt or basketball.txt)
function parseLeaguesFromFile(filename, sport) {
  const filePath = path.join(__dirname, filename);
  const content = fs.readFileSync(filePath, 'utf-8');

  const leagues = [];
  const lines = content.split('\n').filter(line => line.trim());

  let currentLeague = null;
  for (const line of lines) {
    if (line.startsWith('http')) {
      if (currentLeague && !currentLeague.parik24) {
        currentLeague.parik24 = line.trim();
      } else if (currentLeague && !currentLeague.pinnacle) {
        currentLeague.pinnacle = line.trim();
        leagues.push(currentLeague);
        currentLeague = null;
      }
    } else if (line.trim() && !line.startsWith('Вот') && !/\.html?$/i.test(line.trim())) {
      if (currentLeague && currentLeague.parik24 && currentLeague.pinnacle) {
        currentLeague = null;
      }
      if (!currentLeague || (currentLeague.parik24 && currentLeague.pinnacle)) {
        currentLeague = { name: line.trim(), parik24: null, pinnacle: null, sport };
      }
    }
  }

  return leagues;
}

function parseTennisSources() {
  const namedLeagues = parseLeaguesFromFile('tennis.txt', 'tennis');
  if (namedLeagues.length > 0) {
    return namedLeagues;
  }
  return parseSimpleSportFile('tennis.txt', 'tennis', 'Tennis');
}

// Parse simple sport file with only URLs (e.g. tennis.txt)
function parseSimpleSportFile(filename, sport, baseName) {
  const filePath = path.join(__dirname, filename);
  const content = fs.readFileSync(filePath, 'utf-8');
  const urls = content
    .split('\n')
    .map(line => line.trim())
    .filter(line => /^https?:\/\//i.test(line));

  const leagues = [];
  for (let i = 0; i + 1 < urls.length; i += 2) {
    leagues.push({
      name: urls.length > 2 ? `${baseName} ${Math.floor(i / 2) + 1}` : baseName,
      parik24: urls[i],
      pinnacle: urls[i + 1],
      sport,
    });
  }
  return leagues;
}

// Backwards-compat wrapper
function parseLeaguesFromTaskFile() {
  return parseLeaguesFromFile('task.txt', 'football');
}

// Parse Parik24 page — football (3 odds: P1/X/P2) or two-way sports (2 odds: P1/P2)
async function parseParik24WithPuppeteer(browser, url, sport = 'football') {
  const isTwoWay = sport === 'basketball' || sport === 'tennis';
  let page;
  try {
    page = await browser.newPage();
    
    await page.setViewport({ width: 1280, height: 800 });
    await page.goto(url, { waitUntil: 'networkidle2', timeout: CONFIG.timeout });
    if (!isLiveOnly) {
      await page.waitForSelector('a[href*="/events/"]', { timeout: 20000 }).catch(() => null);
      await sleep(1200);
    }

    const extractMatches = async () => page.evaluate((isTwoWay) => {
      const results = [];
      const cards = document.querySelectorAll('a[href*="/events/"]');

      for (const card of cards) {
        try {
          const nameEls = card.querySelectorAll('[class*="name_"][class*="competitor"], [class*="name-horizontal"]');
          if (nameEls.length < 2) continue;

          const home = nameEls[0]?.textContent?.trim() || '';
          const away = nameEls[1]?.textContent?.trim() || '';
          if (!home || !away || home.length < 2 || away.length < 2) continue;

          const timeTextRaw = Array.from(card.querySelectorAll('[class*="time-status"], [class*="date"], [class*="time"], [class*="matchupDate"], time'))
            .map(el => (el.textContent || '').trim())
            .filter(Boolean)
            .join(' ');
          const timeMatch = timeTextRaw.match(/\b\d{1,2}:\d{2}\b/);
          const matchTime = timeMatch ? timeMatch[0] : null;

          const href = card.getAttribute('href') || '';
          const link = href.startsWith('http') ? href : `https://24-parik.club${href}`;

          const odds = [];
          const needed = isTwoWay ? 2 : 3;

          if (isTwoWay) {
            const mainMarket = card.querySelector('[data-onboarding="event-card-main-market"]') || card;
            const outcomeButtons = Array.from(mainMarket.querySelectorAll('div[class*="wrapper__aeiCE"], div[class*="wrapper__noCZB"], button[class*="market-btn"]'));

            const byLabel = new Map();
            for (const btn of outcomeButtons) {
              const label = (btn.querySelector('[data-id="outcome-name-value"]')?.textContent || '').trim();
              const oddText = (btn.querySelector('[data-id="odds-value"]')?.textContent || btn.querySelector('span[class*="price-"]')?.textContent || '').trim();
              const oddNum = parseFloat(oddText);
              if ((label === '1' || label === '2') && !isNaN(oddNum) && oddNum > 0) {
                byLabel.set(label, oddNum.toString());
              }
            }

            if (byLabel.has('1') && byLabel.has('2')) {
              odds.push(byLabel.get('1'));
              odds.push(byLabel.get('2'));
            } else {
              const fallbackOdds = Array.from(mainMarket.querySelectorAll('[data-id="odds-value"], span[class*="price-"]'))
                .map(el => parseFloat((el.textContent || '').trim()))
                .filter(v => !isNaN(v) && v > 0)
                .map(v => v.toString());
              if (fallbackOdds.length >= 2) {
                odds.push(fallbackOdds[0]);
                odds.push(fallbackOdds[fallbackOdds.length - 1]);
              }
            }
          } else {
            const outcomeButtons = card.querySelectorAll('[class*="wrapper__aeiCE"], [class*="wrapper__noCZB"], [class*="total__"]');
            for (let i = 0; i < Math.min(needed, outcomeButtons.length); i++) {
              const oddText = outcomeButtons[i]?.textContent?.trim();
              const oddNum = parseFloat(oddText);
              if (!isNaN(oddNum) && oddNum > 0) {
                odds.push(oddNum.toString());
              }
            }
          }

          if (odds.length === needed) {
            if (isTwoWay) {
              results.push({ home, away, p1: odds[0], x: null, p2: odds[1], link, time: matchTime });
            } else {
              results.push({ home, away, p1: odds[0], x: odds[1], p2: odds[2], link, time: matchTime });
            }
          }
        } catch (e) {
          // Skip on error
        }
      }

      return results;
    }, isTwoWay, isLiveOnly);

    // Deep scroll until lazy-loaded lists stop growing (window + nested scroll containers).
    const dedup = new Map();
    let stagnantRounds = 0;
    let prevVisibleCards = 0;
    const maxScrollIterations = isLiveOnly ? CONFIG.maxScrolls : CONFIG.maxScrolls;

    for (let i = 0; i < maxScrollIterations; i++) {
      const chunk = await extractMatches();
      for (const match of chunk) {
        if (!match?.link) continue;
        if (!dedup.has(match.link)) {
          dedup.set(match.link, match);
        }
      }

      const scrollState = await page.evaluate((scrollLevel) => {
        const root = document.scrollingElement || document.documentElement || document.body;
        const rootBefore = root ? root.scrollTop : 0;
        const rootMaxTop = root ? Math.max(0, root.scrollHeight - root.clientHeight) : 0;
        const rootStep = root ? Math.max(scrollLevel === 2 ? 300 : 800, Math.floor(root.clientHeight * (scrollLevel === 2 ? 0.6 : 0.95))) : 0;

        if (root) {
          root.scrollTop = Math.min(rootMaxTop, rootBefore + rootStep);
          window.scrollBy(0, rootStep);
        }

        const scrollCandidates = Array.from(document.querySelectorAll('div, main, section'))
          .filter((el) => {
            const styles = window.getComputedStyle(el);
            const overflowY = styles.overflowY || '';
            const canScrollByStyle = overflowY.includes('auto') || overflowY.includes('scroll');
            const canScrollBySize = (el.scrollHeight - el.clientHeight) > 140;
            if (!canScrollBySize) return false;
            if (canScrollByStyle) return true;

            const cls = `${el.className || ''}`.toLowerCase();
            return cls.includes('scroll') || cls.includes('list') || cls.includes('event') || cls.includes('container');
          })
          .sort((a, b) => (b.scrollHeight - b.clientHeight) - (a.scrollHeight - a.clientHeight))
          .slice(0, 4);

        let movedInner = false;
        let reachedBottomInner = true;
        for (const scroller of scrollCandidates) {
          const before = scroller.scrollTop;
          const maxTop = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
          const step = Math.max(600, Math.floor(scroller.clientHeight * 0.9));
          const nextTop = Math.min(maxTop, before + step);
          scroller.scrollTop = nextTop;
          scroller.dispatchEvent(new Event('scroll', { bubbles: true }));
          if (nextTop > before) movedInner = true;
          if (nextTop < maxTop) reachedBottomInner = false;
        }

        const visibleCards = document.querySelectorAll('a[href*="/events/"]').length;
        const reachedBottomRoot = !root || root.scrollTop >= rootMaxTop;

        return {
          visibleCards,
          reachedBottom: reachedBottomRoot && reachedBottomInner,
          moved: movedInner || (root && root.scrollTop > rootBefore),
        };
      }, CONFIG.scrollLevel);

      await sleep(isLiveOnly ? 200 : 500);

      const beforeSize = dedup.size;
      const afterChunk = await extractMatches();
      for (const match of afterChunk) {
        if (!match?.link) continue;
        if (!dedup.has(match.link)) {
          dedup.set(match.link, match);
        }
      }

      const grew = dedup.size > beforeSize || scrollState.visibleCards > prevVisibleCards;
      if (!grew) stagnantRounds += 1;
      else stagnantRounds = 0;
      prevVisibleCards = Math.max(prevVisibleCards, scrollState.visibleCards || 0);

      if (scrollState.reachedBottom && stagnantRounds >= (isLiveOnly ? 2 : 7)) {
        break;
      }
    }

    const matches = Array.from(dedup.values());

    try { await page.close(); } catch {}
    return matches;
  } catch (err) {
    try { if (page) await page.close(); } catch {}
    console.error(`    [parik24 error] ${err.message}`);
    throw err;
  }
}

// Parse Pinnacle page — football (3 odds: P1/X/P2) or two-way sports (2 odds: P1/P2)
async function parsePinnacleWithPuppeteer(browser, url, sport = 'football') {
  const isTwoWay = sport === 'basketball' || sport === 'tennis';
  const sportSlug = sport === 'basketball'
    ? '/en/basketball/'
    : sport === 'tennis'
      ? '/en/tennis/'
      : '/en/soccer/';
  const sportPath = sport === 'basketball'
    ? '/basketball/'
    : sport === 'tennis'
      ? '/tennis/'
      : '/soccer/';
  const tennisTournamentToken = sport === 'tennis'
    ? (() => {
        const m = String(url || '').match(/\/tennis\/([^/]+)\/matchups/i);
        if (!m) return '';
        let token = m[1].toLowerCase();
        token = token
          .replace(/-(r\d+|qf|sf|f|quarterfinals?|semifinals?|final)$/i, '')
          .replace(/-(r\d+|qf|sf|f|quarterfinals?|semifinals?|final)$/i, '');
        return token;
      })()
    : '';
  let page;
  try {
    page = await browser.newPage();
    
    await page.setViewport({ width: 1280, height: 800 });

    await page.goto(url, { waitUntil: isLiveOnly ? 'networkidle2' : 'networkidle2', timeout: CONFIG.timeout });
    const selectorTimeout = isLiveOnly ? 8000 : 20000;
    await page.waitForSelector('div[data-test-id="moneyline"]', { timeout: selectorTimeout }).catch(() => null);

    const extractRenderedRows = async () => page.evaluate((isTwoWay, sportPath, sportName, includeUnavailableForTennis, tennisTournamentToken) => {
      const results = [];
      const seenLinks = new Set();

      const toAbsolute = (href) => {
        if (!href) return '';
        if (href.startsWith('http')) return href;
        return `${window.location.origin}${href}`;
      };

      const moneylineBlocks = Array.from(document.querySelectorAll('div[data-test-id="moneyline"]'));
      for (const moneyline of moneylineBlocks) {
        try {
          const row = moneyline.closest('div[class*="row-"]') || moneyline.parentElement;
          if (!row) continue;

          const eventHref = row
            .querySelector(
              sportName === 'tennis'
                ? `a[href*="${sportPath}"]`
                : `a[href*="${sportPath}"]:not([href*="/matchups/"])`
            )
            ?.getAttribute('href') || '';
          if (!eventHref) continue;

          if (sportName === 'tennis' && tennisTournamentToken) {
            const hrefLower = eventHref.toLowerCase();
            if (!hrefLower.includes(`/${tennisTournamentToken}`)) {
              continue;
            }
          }

          const exactNameEls = row.querySelectorAll('[class*="matchupMetadata"] > div[class*="gameInfoLabel"] > span[class*="gameInfoLabel"]');
          let names = Array.from(exactNameEls).map(el => (el.textContent || '').trim()).filter(Boolean);
          if (names.length < 2) {
            const fallbackEls = row.querySelectorAll('[class*="matchupMetadata"] [class*="gameInfoLabel"]');
            const fallbackNames = Array.from(fallbackEls)
              .map(el => (el.textContent || '').trim())
              .filter(Boolean)
              .filter(text => !/^\d{1,2}:\d{2}$/.test(text));
            const compact = [];
            for (const text of fallbackNames) {
              if (!compact.length || compact[compact.length - 1] !== text) compact.push(text);
            }
            names = compact;
          }
          if (names.length < 2) continue;

          const homeRaw = names[0];
          const awayRaw = names[1];

          if (sportName === 'tennis') {
            if (/\(games\)\s*$/i.test(homeRaw) || /\(games\)\s*$/i.test(awayRaw)) {
              continue;
            }
          }

          const home = homeRaw
            .replace(/\s*\(match\)\s*$/i, '')
            .replace(/\s*\(sets\)\s*$/i, '')
            .trim();
          const away = awayRaw
            .replace(/\s*\(match\)\s*$/i, '')
            .replace(/\s*\(sets\)\s*$/i, '')
            .trim();
          if (!home || !away || home.length < 2 || away.length < 2) continue;

          const rowTimeRaw = [
            row.querySelector('[class*="matchupDate"]')?.textContent || '',
            row.querySelector('time')?.textContent || '',
            row.querySelector('[class*="time-status"]')?.textContent || '',
          ].join(' ');
          const rowTimeMatch = rowTimeRaw.match(/\b\d{1,2}:\d{2}\b/);
          const matchTime = rowTimeMatch ? rowTimeMatch[0] : null;

          const needed = isTwoWay ? 2 : 3;
          const buttons = Array.from(moneyline.querySelectorAll('button[class*="market-btn"]'))
            .filter(btn => !btn.className.includes('disabled-'));
          if (buttons.length < needed) continue;

          const candidateButtons = isTwoWay && sportName === 'basketball' && buttons.length >= 3
            ? [buttons[0], buttons[buttons.length - 1]]
            : buttons.slice(0, needed);

          const odds = [];
          for (const button of candidateButtons) {
            const priceText = (button.querySelector('span[class*="price-"]')?.textContent || '').trim();
            const priceNum = parseFloat(priceText);
            if (!isNaN(priceNum) && priceNum > 0) {
              odds.push(priceNum.toString());
            }
          }
          if (odds.length !== needed) {
            if (includeUnavailableForTennis && isTwoWay) {
              results.push({ home, away, p1: null, x: null, p2: null, link: toAbsolute(eventHref), time: matchTime });
            }
            continue;
          }

          const link = toAbsolute(eventHref);
          if (seenLinks.has(link)) continue;
          seenLinks.add(link);
          if (isTwoWay) {
            results.push({ home, away, p1: odds[0], x: null, p2: odds[1], link, time: matchTime });
          } else {
            results.push({ home, away, p1: odds[0], x: odds[1], p2: odds[2], link, time: matchTime });
          }
        } catch (e) {
          // Skip row parse errors
        }
      }

      // Tennis fallback: some rows may miss moneyline test-id, extract directly from event rows.
      if (sportName === 'tennis') {
        const eventAnchors = Array.from(document.querySelectorAll(
          sportName === 'tennis'
            ? `a[href*="${sportPath}"]`
            : `a[href*="${sportPath}"]:not([href*="/matchups/"])`
        ));
        for (const anchor of eventAnchors) {
          try {
            const href = anchor.getAttribute('href') || '';
            if (!href) continue;
            if (tennisTournamentToken) {
              const hrefLower = href.toLowerCase();
              if (!hrefLower.includes(`/${tennisTournamentToken}`)) continue;
            }
            const link = toAbsolute(href);
            if (seenLinks.has(link)) continue;

            const row = anchor.closest('div[class*="row-"]') || anchor.closest('article') || anchor.parentElement;
            if (!row) continue;

            const nameEls = row.querySelectorAll('[class*="matchupMetadata"] [class*="gameInfoLabel"], [class*="participant"], [class*="competitor"]');
            const rawNames = Array.from(nameEls)
              .map(el => (el.textContent || '').trim())
              .filter(Boolean)
              .filter(text => !/^\d{1,2}:\d{2}$/.test(text))
              .filter(text => !/^(set|sets|match)$/i.test(text));
            const compact = [];
            for (const text of rawNames) {
              if (!compact.length || compact[compact.length - 1] !== text) compact.push(text);
            }
            if (compact.length < 2) continue;

            const home = compact[0]
              .replace(/\s*\(match\)\s*$/i, '')
              .replace(/\s*\(sets\)\s*$/i, '')
              .replace(/\s*\(games\)\s*$/i, '')
              .trim();
            const away = compact[1]
              .replace(/\s*\(match\)\s*$/i, '')
              .replace(/\s*\(sets\)\s*$/i, '')
              .replace(/\s*\(games\)\s*$/i, '')
              .trim();
            if (!home || !away || home.length < 2 || away.length < 2) continue;

            const rowTimeRaw = [
              row.querySelector('[class*="matchupDate"]')?.textContent || '',
              row.querySelector('time')?.textContent || '',
              row.querySelector('[class*="time-status"]')?.textContent || '',
            ].join(' ');
            const rowTimeMatch = rowTimeRaw.match(/\b\d{1,2}:\d{2}\b/);
            const matchTime = rowTimeMatch ? rowTimeMatch[0] : null;

            const priceEls = Array.from(row.querySelectorAll('span[class*="price-"]'));
            const odds = priceEls
              .map(el => parseFloat((el.textContent || '').trim()))
              .filter(v => !isNaN(v) && v > 0)
              .slice(0, 2)
              .map(v => v.toString());

            seenLinks.add(link);
            if (odds.length >= 2) {
              results.push({ home, away, p1: odds[0], x: null, p2: odds[1], link, time: matchTime });
            } else if (includeUnavailableForTennis) {
              results.push({ home, away, p1: null, x: null, p2: null, link, time: matchTime });
            }
          } catch (e) {
            // Skip fallback row parse errors
          }
        }
      }

      return results;
    }, isTwoWay, sportPath, sport, sport === 'tennis', tennisTournamentToken);

    const dedup = new Map();
    const hasUsableOdds = (match) => {
      const p1 = parseFloat(match?.p1);
      const p2 = parseFloat(match?.p2);
      return Number.isFinite(p1) && p1 > 0 && Number.isFinite(p2) && p2 > 0;
    };
    const upsertMatch = (match) => {
      if (!match?.link) return;
      const prev = dedup.get(match.link);
      if (!prev) {
        dedup.set(match.link, match);
        return;
      }
      if (!hasUsableOdds(prev) && hasUsableOdds(match)) {
        dedup.set(match.link, match);
      }
    };

    if (sport === 'tennis') {
      // Tennis page uses virtualized list inside scroll container.
      await page.evaluate(() => {
        const scrollers = Array.from(document.querySelectorAll('div[class*="list-"][class*="scrollbar"], div[class*="scrollContainer"], div[class*="scrollbar"]'))
          .filter(el => (el.scrollHeight - el.clientHeight) > 120);
        const scroller = scrollers.sort((a, b) => (b.scrollHeight - b.clientHeight) - (a.scrollHeight - a.clientHeight))[0];
        if (scroller) {
          scroller.scrollTop = 0;
          scroller.dispatchEvent(new Event('scroll', { bubbles: true }));
        }
      });

      let stagnant = 0;
      const maxTennisIter = isLiveOnly ? 3 : 140;
      for (let i = 0; i < maxTennisIter; i++) {
        const beforeSize = dedup.size;
        const chunk = await extractRenderedRows();
        for (const match of chunk) {
          upsertMatch(match);
        }

        const info = await page.evaluate(() => {
          const scrollers = Array.from(document.querySelectorAll('div[class*="list-"][class*="scrollbar"], div[class*="scrollContainer"], div[class*="scrollbar"]'))
            .filter(el => (el.scrollHeight - el.clientHeight) > 120)
            .sort((a, b) => (b.scrollHeight - b.clientHeight) - (a.scrollHeight - a.clientHeight));
          const scroller = scrollers[0] || document.scrollingElement || document.documentElement;
          if (!scroller) return { done: true };

          const maxTop = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
          const step = Math.max(180, Math.floor(scroller.clientHeight * 0.7));
          const nextTop = Math.min(maxTop, scroller.scrollTop + step);
          const done = nextTop >= maxTop;
          scroller.scrollTop = nextTop;
          scroller.dispatchEvent(new Event('scroll', { bubbles: true }));
          window.scrollBy(0, 160);
          return { done, top: nextTop, maxTop };
        });

        await sleep(isLiveOnly ? 200 : 260);

        if (dedup.size === beforeSize) stagnant += 1;
        else stagnant = 0;

        const stagnantThreshold = isLiveOnly ? 1 : 8;
        if (info.done && stagnant >= stagnantThreshold) {
          const finalChunk = await extractRenderedRows();
          for (const match of finalChunk) {
            upsertMatch(match);
          }
          break;
        }
      }

      if (dedup.size === 0) {
        const fallbackUrl = 'https://www.pinnacle.com/en/tennis/matchups/#period:0';
        await page.goto(fallbackUrl, { waitUntil: 'networkidle2', timeout: CONFIG.timeout }).catch(() => null);
        await sleep(400);

        const fallbackChunk = await extractRenderedRows();
        for (const match of fallbackChunk) {
          upsertMatch(match);
        }
      }
    } else {
      // Non-tennis pages can use regular window scrolling.
      let prevRows = 0;
      let stagnant = 0;
      const maxFootballIter = isLiveOnly ? 3 : 18;
      for (let i = 0; i < maxFootballIter; i++) {
        await page.evaluate(() => window.scrollBy(0, Math.max(900, Math.floor(window.innerHeight * 0.9))));
        await sleep(isLiveOnly ? 200 : 380);

        const chunk = await extractRenderedRows();
        for (const match of chunk) {
          upsertMatch(match);
        }

        const rows = chunk.length;
        if (rows === prevRows) {
          stagnant += 1;
          const stagnantThreshold = isLiveOnly ? 1 : 4; // Live: stop early
          if (stagnant >= stagnantThreshold) break;
        } else {
          stagnant = 0;
        }
        prevRows = rows;
      }
    }

    const matches = Array.from(dedup.values());

    try { await page.close(); } catch {}
    return matches;
  } catch (err) {
    try { if (page) await page.close(); } catch {}
    console.error(`    [pinnacle error] ${err.message}`);
    throw err;
  }
}



// Simple token-based similarity
const GENERIC_TEAM_TOKENS = new Set([
  'match', 'fc', 'cf', 'ac', 'sc', 'sv', 'fk', 'afc', 'if', 'bk', 'sk', 'tsg',
  'club', 'de', 'la', 'the', 'team', 'cd'
]);

function normalizeTeamName(name) {
  return String(name || '')
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\(match\)/gi, ' ')
    .replace(/\binternazionale\b/g, 'inter')
    .replace(/\bparis saint germain\b/g, 'psg')
    .replace(/\bmanchester united\b/g, 'man utd')
    .replace(/\bmanchester city\b/g, 'man city')
    .replace(/\bborussia monchengladbach\b/g, 'monchengladbach')
    .replace(/\bsaint\b/g, 'st')
    .replace(/[^a-z0-9\s-]/g, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function tokenizeTeamName(name) {
  return normalizeTeamName(name)
    .split(/\s+/)
    .map(token => token.trim())
    .filter(token => token && !GENERIC_TEAM_TOKENS.has(token))
    .filter(token => token.length > 2 || /^\d+$/.test(token));
}

function tokensCompatible(token, candidates) {
  return candidates.some(candidate => (
    candidate === token ||
    candidate.includes(token) ||
    token.includes(candidate)
  ));
}

function tokenSimilarity(a, b) {
  const tokensA = [...new Set(tokenizeTeamName(a))];
  const tokensB = [...new Set(tokenizeTeamName(b))];

  if (!tokensA.length || !tokensB.length) {
    return 0;
  }

  const sharedA = tokensA.filter(token => tokensCompatible(token, tokensB));
  const sharedB = tokensB.filter(token => tokensCompatible(token, tokensA));
  const directionalA = sharedA.length / tokensA.length;
  const directionalB = sharedB.length / tokensB.length;

  const union = new Set([...tokensA, ...tokensB]);
  const sharedCount = new Set([...sharedA, ...sharedB]).size;
  const jaccard = union.size > 0 ? sharedCount / union.size : 0;

  if (normalizeTeamName(a) === normalizeTeamName(b)) {
    return 1;
  }

  return (directionalA + directionalB + jaccard) / 3;
}

// Find match score between parik and pinnacle
function findMatchScore(homeP, awayP, homePin, awayPin) {
  const direct = (tokenSimilarity(homeP, homePin) + tokenSimilarity(awayP, awayPin)) / 2;
  const reversed = (tokenSimilarity(homeP, awayPin) + tokenSimilarity(awayP, homePin)) / 2;
  return Math.max(direct, reversed);
}

function findMatchAlignment(homeP, awayP, homePin, awayPin) {
  const direct = (tokenSimilarity(homeP, homePin) + tokenSimilarity(awayP, awayPin)) / 2;
  const reversed = (tokenSimilarity(homeP, awayPin) + tokenSimilarity(awayP, homePin)) / 2;
  if (reversed > direct) {
    return { score: reversed, reversed: true };
  }
  return { score: direct, reversed: false };
}

function alignPinnacleOdds(pin, reversed) {
  if (!pin || !reversed) return pin;
  return {
    ...pin,
    p1: pin.p2 ?? null,
    p2: pin.p1 ?? null,
  };
}

// Main scraping function
async function scrapeLeagues() {
  console.log('[league_scraper] Starting...');

  // Load football + basketball + tennis leagues
  const footballLeagues = parseLeaguesFromFile('task.txt', 'football');
  const basketballLeagues = parseLeaguesFromFile('basketball.txt', 'basketball');
  const tennisLeagues = parseTennisSources();
  const leagues = [...footballLeagues, ...basketballLeagues, ...tennisLeagues];
  console.log(`[league_scraper] Found ${footballLeagues.length} football + ${basketballLeagues.length} basketball + ${tennisLeagues.length} tennis = ${leagues.length} leagues`);

  const launchBrowser = async () => {
    return puppeteer.launch({
      headless: CONFIG.headless,
      userDataDir: createBrowserProfileDir('league'),
      args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
    });
  };

  // Recovery wrapper using a shared browser slot (either A or B)
  const makeBrowserRunner = (slot) => async (job, label) => withRetry(async () => {
    if (!slot.browser || !slot.browser.isConnected()) {
      slot.browser = await launchBrowser();
    }
    try {
      return await job(slot.browser);
    } catch (err) {
      const msg = err?.message || String(err);
      if (/connection closed|target closed|session closed|browser has disconnected|detached frame|navigating frame was detached|protocol error/i.test(msg)) {
        try { if (slot.browser) await slot.browser.close(); } catch {}
        slot.browser = null;
      }
      throw err;
    }
  }, 2, label);

  const parikAllMatches = [];
  const pinnacleAllMatches = [];
  const nonLiveConcurrency = Math.min(leagues.length, PREMATCH_CONCURRENCY);
  console.log(`[league_scraper] Prematch workers: ${nonLiveConcurrency}`);

  let nextLeagueIndex = 0;
  const pickNextLeague = () => {
    if (nextLeagueIndex >= leagues.length) return null;
    const league = leagues[nextLeagueIndex];
    nextLeagueIndex += 1;
    return league;
  };

  const runWorker = async (workerId) => {
    const slotA = { browser: null };
    const slotB = { browser: null };
    const runWithA = makeBrowserRunner(slotA);
    const runWithB = makeBrowserRunner(slotB);
    let processedByWorker = 0;

    while (true) {
      const league = pickNextLeague();
      if (!league) break;

      console.log(`\n[W${workerId}] [${league.name}] (${league.sport}) Processing...`);

      const [parikMatches, pinnacleMatches] = await Promise.all([
        runWithA(
          (b) => parseParik24WithPuppeteer(b, league.parik24, league.sport),
          `parik24:${league.name}`
        ).catch(() => []),
        runWithB(
          (b) => parsePinnacleWithPuppeteer(b, league.pinnacle, league.sport),
          `pinnacle:${league.name}`
        ).catch(() => []),
      ]);

      console.log(`  [W${workerId}] [parik24] Found ${parikMatches.length} matches`);
      console.log(`  [W${workerId}] [pinnacle] Found ${pinnacleMatches.length} matches`);

      parikAllMatches.push(...parikMatches.map(m => ({ ...m, league: league.name, sport: league.sport })));
      pinnacleAllMatches.push(...pinnacleMatches.map(m => ({ ...m, league: league.name, sport: league.sport })));

      processedByWorker += 1;
      if (processedByWorker % CONFIG.browserRefreshEveryLeagues === 0) {
        try { if (slotA.browser) await slotA.browser.close(); } catch {}
        slotA.browser = null;
        try { if (slotB.browser) await slotB.browser.close(); } catch {}
        slotB.browser = null;
      }

      await sleep(120);
    }

    try { if (slotA.browser) await slotA.browser.close(); } catch {}
    try { if (slotB.browser) await slotB.browser.close(); } catch {}
  };

  await Promise.all(Array.from({ length: nonLiveConcurrency }, (_, index) => runWorker(index + 1)));
  
  // Save raw data
  fs.writeFileSync(
    path.join(CONFIG.dataDir, 'parik24_raw.json'),
    JSON.stringify({ updated: new Date().toISOString(), matches: parikAllMatches }, null, 2),
    'utf-8'
  );
  console.log(`\n[save] Parik24: ${parikAllMatches.length} matches → parik24_raw.json`);
  
  fs.writeFileSync(
    path.join(CONFIG.dataDir, 'pinnacle_raw.json'),
    JSON.stringify({ updated: new Date().toISOString(), matches: pinnacleAllMatches }, null, 2),
    'utf-8'
  );
  console.log(`[save] Pinnacle: ${pinnacleAllMatches.length} matches → pinnacle_raw.json`);
  
  // Merge by fuzzy matching
  console.log('\n[merge] Starting matched pairing...');
  const merged = [];
  const matched = new Set();
  
  for (const parik of parikAllMatches) {
    let bestMatch = null;
    let bestScore = 0.5; // Min threshold
    
    for (let i = 0; i < pinnacleAllMatches.length; i++) {
      if (matched.has(i)) continue;
      
      const pinnacle = pinnacleAllMatches[i];
      if (pinnacle.league !== parik.league) continue;
      
      const alignment = findMatchAlignment(parik.home, parik.away, pinnacle.home, pinnacle.away);
      const score = alignment.score;
      
      if (score > bestScore) {
        bestScore = score;
        bestMatch = { index: i, score, reversed: alignment.reversed };
      }
    }
    
    if (bestMatch) {
      matched.add(bestMatch.index);
      const pinRaw = pinnacleAllMatches[bestMatch.index];
      const pin = alignPinnacleOdds(pinRaw, !!bestMatch.reversed);
      const sport = (parik.sport || 'football').toLowerCase();
      merged.push({
        league: parik.league,
        sport,
        home: parik.home,
        away: parik.away,
        time: parik.time || pin.time || null,
        parik24: { p1: parik.p1, x: parik.x, p2: parik.p2, link: parik.link || null, time: parik.time || null },
        pinnacle: { p1: pin.p1, x: pin.x, p2: pin.p2, link: pin.link || null, time: pin.time || null },
        matchScore: bestMatch.score,
      });
      console.log(`  ✓ ${parik.league}: ${parik.home} vs ${parik.away} (score: ${bestMatch.score.toFixed(2)})`);
    } else {
      const sport = (parik.sport || 'football').toLowerCase();
      merged.push({
        league: parik.league,
        sport,
        home: parik.home,
        away: parik.away,
        time: parik.time || null,
        parik24: { p1: parik.p1, x: parik.x, p2: parik.p2, link: parik.link || null, time: parik.time || null },
        pinnacle: null,
        matchScore: 0,
      });
    }
  }
  
  const mergedPayload = {
    updated: new Date().toISOString(),
    matches: merged,
  };

  if (SCRAPER_MODE === 'http' && POST_URL) {
    try {
      console.log(`\n[post] Sending merged payload to ${POST_URL}`);
      await axios.post(POST_URL, mergedPayload, {
        headers: {
          'Content-Type': 'application/json',
        },
        timeout: 60000,
      });
      console.log('[post] Successfully posted merged payload to PHP endpoint');
    } catch (err) {
      console.error('[post] Failed to send merged payload:', err.message || err);
      process.exit(1);
    }
  } else {
    fs.writeFileSync(
      path.join(CONFIG.dataDir, 'merged_matches.json'),
      JSON.stringify(mergedPayload, null, 2),
      'utf-8'
    );
    console.log(`\n[save] Merged: ${merged.length} matches → merged_matches.json`);
  }
  console.log('[done]');
}

// ─── Live Multi-Sport Scraper ────────────────────────────────────────────────
async function scrapeLiveMatches() {
  console.log('\n[live] Scraping live matches (football + basketball + tennis)...');

  const launchBrowser = async () => puppeteer.launch({
    headless: CONFIG.headless,
    userDataDir: createBrowserProfileDir('live'),
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });

  let browserA = null;
  let browserB = null;
  try {
    browserA = await launchBrowser();
    browserB = await launchBrowser();

    const liveSources = [
      {
        sport: 'football',
        title: 'Live Football',
        parikUrl: 'https://24-parik.club/en/football/live',
        pinnacleUrl: 'https://www.pinnacle.com/en/soccer/matchups/live/',
      },
      {
        sport: 'basketball',
        title: 'Live Basketball',
        parikUrl: 'https://24-parik.club/en/basketball/live',
        pinnacleUrl: 'https://www.pinnacle.com/en/basketball/matchups/live/',
      },
      {
        sport: 'tennis',
        title: 'Live Tennis',
        parikUrl: 'https://24-parik.club/en/tennis/live',
        pinnacleUrl: 'https://www.pinnacle.com/en/tennis/matchups/live/',
      },
    ];

    const merged = [];

    for (const source of liveSources) {
      const [parikMatches, pinnacleMatches] = await Promise.all([
        parseParik24WithPuppeteer(browserA, source.parikUrl, source.sport).catch((e) => {
          console.error(`[live/parik24/${source.sport}]`, e.message);
          return [];
        }),
        parsePinnacleWithPuppeteer(browserB, source.pinnacleUrl, source.sport).catch((e) => {
          console.error(`[live/pinnacle/${source.sport}]`, e.message);
          return [];
        }),
      ]);

      console.log(`  [live/parik24/${source.sport}] Found ${parikMatches.length} matches`);
      console.log(`  [live/pinnacle/${source.sport}] Found ${pinnacleMatches.length} matches`);

      const matched = new Set();
      for (const parik of parikMatches) {
        let bestMatch = null;
        let bestScore = 0.45;

        for (let i = 0; i < pinnacleMatches.length; i++) {
          if (matched.has(i)) continue;
          const alignment = findMatchAlignment(parik.home, parik.away, pinnacleMatches[i].home, pinnacleMatches[i].away);
          const score = alignment.score;
          if (score > bestScore) {
            bestScore = score;
            bestMatch = { index: i, score, reversed: alignment.reversed };
          }
        }

        if (bestMatch) {
          matched.add(bestMatch.index);
          const pinRaw = pinnacleMatches[bestMatch.index];
          const pin = alignPinnacleOdds(pinRaw, !!bestMatch.reversed);
          merged.push({
            league: source.title,
            sport: source.sport,
            phase: 'live',
            home: parik.home,
            away: parik.away,
            time: parik.time || pin.time || null,
            parik24: { p1: parik.p1, x: parik.x, p2: parik.p2, link: parik.link || null, time: parik.time || null },
            pinnacle: { p1: pin.p1, x: pin.x, p2: pin.p2, link: pin.link || null, time: pin.time || null },
            matchScore: bestMatch.score,
          });
        } else {
          merged.push({
            league: source.title,
            sport: source.sport,
            phase: 'live',
            home: parik.home,
            away: parik.away,
            time: parik.time || null,
            parik24: { p1: parik.p1, x: parik.x, p2: parik.p2, link: parik.link || null, time: parik.time || null },
            pinnacle: null,
            matchScore: 0,
          });
        }
      }
    }

    const livePayload = {
      updated: new Date().toISOString(),
      matches: merged,
    };

    if (SCRAPER_MODE === 'http' && POST_URL) {
      try {
        console.log(`  [live/post] Sending live payload to ${POST_URL}`);
        await axios.post(POST_URL, {
          ...livePayload,
          target: 'live',
        }, {
          headers: {
            'Content-Type': 'application/json',
          },
          timeout: 60000,
        });
        console.log(`[live/post] Successfully posted ${merged.length} live matches`);
      } catch (err) {
        console.error('[live/post] Failed to send live payload:', err.message || err);
      }
    } else {
      fs.writeFileSync(
        path.join(CONFIG.dataDir, 'live_matches.json'),
        JSON.stringify(livePayload, null, 2),
        'utf-8'
      );
      console.log(`[live] Saved ${merged.length} live matches → live_matches.json`);
    }
  } finally {
    try { if (browserA) await browserA.close(); } catch {}
    try { if (browserB) await browserB.close(); } catch {}
  }
}

// Run full scrape once, then keep live scraping continuously.
async function runAll() {
  const isLiveOnly = SCRAPER_SCOPE === 'live-only' || SCRAPER_SCOPE === 'live';

  if (!isLiveOnly) {
    await scrapeLeagues();
    return;
  }

  if (SCRAPER_MODE === 'http') {
    while (true) {
      try {
        await scrapeLiveMatches();
      } catch (err) {
        console.error('[live-loop] iteration failed:', err?.message || err);
      }
      await sleep(2000);
    }
  } else if (isLiveOnly) {
    while (true) {
      try {
        await scrapeLiveMatches();
      } catch (err) {
        console.error('[live-loop] iteration failed:', err?.message || err);
      }
      await sleep(3000);
    }
  } else {
    await scrapeLiveMatches();
  }
}

runAll().catch(err => {
  console.error('[ERROR]', err);
  process.exit(1);
});
