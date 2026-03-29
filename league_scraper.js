const puppeteer = require('puppeteer');
const fs = require('fs');
const path = require('path');

// Parsing configuration
const CONFIG = {
  dataDir: path.join(__dirname, 'data'),
  headless: true,
  timeout: 45000,
};

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
      // Small backoff to avoid hammering the target / browser cleanup races
      await sleep(1200 * attempt);
    }
  }
  throw lastErr;
}

// Parse task.txt to extract leagues
function parseLeaguesFromTaskFile() {
  const taskFile = path.join(__dirname, 'task.txt');
  const content = fs.readFileSync(taskFile, 'utf-8');
  
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
    } else if (line.trim() && !line.startsWith('Вот')) {
      if (currentLeague && currentLeague.parik24 && currentLeague.pinnacle) {
        currentLeague = null;
      }
      if (!currentLeague || (currentLeague.parik24 && currentLeague.pinnacle)) {
        currentLeague = { name: line.trim(), parik24: null, pinnacle: null };
      }
    }
  }
  
  return leagues;
}


// Fetch and render page with Puppeteer
async function fetchPageWithPuppeteer(browser, url) {
  let page;
  try {
    page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });
    
    console.log(`    [navigate] ${url}`);
    await page.goto(url, { waitUntil: 'networkidle2', timeout: CONFIG.timeout });
    
    // Wait for content to load
    await new Promise(r => setTimeout(r, 3000));
    
    // Scroll to load more content
    await page.evaluate(() => {
      window.scrollBy(0, window.innerHeight);
    });
    await new Promise(r => setTimeout(r, 2000));
    
    const html = await page.content();
    await page.close();
    return html;
  } catch (err) {
    if (page) await page.close();
    console.error(`    [error] ${err.message}`);
    return null;
  }
}

// Parse Parik24 page with extracted text method
async function parseParik24WithPuppeteer(browser, url) {
  let page;
  try {
    page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });
    
    await page.goto(url, { waitUntil: 'networkidle2', timeout: CONFIG.timeout });
    await new Promise(r => setTimeout(r, 2000));
    
    // Scroll to load more
    for (let i = 0; i < 3; i++) {
      await page.evaluate(() => window.scrollBy(0, 500));
      await new Promise(r => setTimeout(r, 1000));
    }
    
    // Extract match data using real selectors from working scraper
    const matches = await page.evaluate(() => {
      const matches = [];
      const cards = document.querySelectorAll('a[href*="/events/"]');
      
      for (const card of cards) {
        try {
          // Get team names using real selectors
          const nameEls = card.querySelectorAll('[class*="name_"][class*="competitor"], [class*="name-horizontal"]');
          if (nameEls.length < 2) continue;
          
          const home = nameEls[0]?.textContent?.trim() || '';
          const away = nameEls[1]?.textContent?.trim() || '';
          if (!home || !away || home.length < 2 || away.length < 2) continue;
          
          // Get match link
          const href = card.getAttribute('href') || '';
          const url = href.startsWith('http') ? href : `https://24-parik.club${href}`;
          
          // Get odds from outcome buttons
          const outcomeButtons = card.querySelectorAll('[class*="wrapper__aeiCE"], [class*="wrapper__noCZB"], [class*="total__"]');
          const odds = [];
          
          for (let i = 0; i < Math.min(3, outcomeButtons.length); i++) {
            const oddText = outcomeButtons[i]?.textContent?.trim();
            const oddNum = parseFloat(oddText);
            if (!isNaN(oddNum) && oddNum > 0) {
              odds.push(oddNum.toString());
            }
          }
          
          if (odds.length === 3) {
            matches.push({
              home,
              away,
              p1: odds[0],
              x: odds[1],
              p2: odds[2],
              link: url,
            });
          }
        } catch (e) {
          // Skip on error
        }
      }
      
      return matches;
    });
    
    try { await page.close(); } catch {}
    return matches;
  } catch (err) {
    try { if (page) await page.close(); } catch {}
    console.error(`    [parik24 error] ${err.message}`);
    return [];
  }
}

// Parse Pinnacle page with extracted text
async function parsePinnacleWithPuppeteer(browser, url) {
  let page;
  try {
    page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });
    
    await page.goto(url, { waitUntil: 'domcontentloaded', timeout: CONFIG.timeout });
    await page.waitForSelector('div[data-test-id="moneyline"]', { timeout: 20000 }).catch(() => null);

    // Scroll until row count stabilizes (similar to the older stable scraper)
    let prevRows = 0;
    let stagnant = 0;
    for (let i = 0; i < 18; i++) {
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
    
    const matches = await page.evaluate(() => {
      const matches = [];
      
      const toAbsolute = (href) => {
        if (!href) return '';
        if (href.startsWith('http')) return href;
        // Pinnacle links are already absolute-ish enough for our use; normalize using the current origin.
        return `${window.location.origin}${href}`;
      };

      const moneylineBlocks = Array.from(document.querySelectorAll('div[data-test-id="moneyline"]'));
      
      for (const moneyline of moneylineBlocks) {
        try {
          // Find parent row container
          const row = moneyline.closest('div[class*="row-k9"]') || moneyline.parentElement;
          if (!row) continue;
          
          // Get team names from matchupMetadata
          const nameEls = row.querySelectorAll('[class*="matchupMetadata"] > div[class*="gameInfoLabel"] > span[class*="gameInfoLabel"]');
          const names = Array.from(nameEls).map(el => (el.textContent || '').trim()).filter(Boolean);
          if (names.length < 2) continue;
          
          const home = names[0].replace(/\s*\(match\)\s*$/i, '').trim();
          const away = names[1].replace(/\s*\(match\)\s*$/i, '').trim();
          if (!home || !away || home.length < 2 || away.length < 2) continue;
          
          const eventHref = row
            .querySelector('a[href*="/en/soccer/"]:not([href*="/matchups/"])')
            ?.getAttribute('href') || '';
          const url = eventHref ? toAbsolute(eventHref) : '';
          
          // Get prices/odds from moneyline buttons
          const prices = Array.from(row.querySelectorAll('div[data-test-id="moneyline"] button[class*="market-btn"] span[class*="price-"]'))
            .map(el => (el.textContent || '').trim())
            .filter(Boolean);
          
          if (prices.length < 3) continue;
          
          const odds = [];
          for (let i = 0; i < Math.min(3, prices.length); i++) {
            const priceNum = parseFloat(prices[i]);
            if (!isNaN(priceNum) && priceNum > 0) {
              odds.push(priceNum.toString());
            }
          }
          
          if (odds.length === 3) {
            matches.push({
              home,
              away,
              p1: odds[0],
              x: odds[1],
              p2: odds[2],
              link: url,
            });
          }
        } catch (e) {
          // Skip on error
        }
      }
      
      return matches;
    });
    
    try { await page.close(); } catch {}
    return matches;
  } catch (err) {
    try { if (page) await page.close(); } catch {}
    console.error(`    [pinnacle error] ${err.message}`);
    return [];
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

// Main scraping function
async function scrapeLeagues() {
  console.log('[league_scraper] Starting...');
  
  const leagues = parseLeaguesFromTaskFile();
  console.log(`[league_scraper] Found ${leagues.length} leagues`);

  // Use unique user data dir to reduce races with dev-chrome profiles between runs.
  const runId = `${Date.now()}_${Math.random().toString(16).slice(2)}`;
  const userDataDir = path.join(CONFIG.dataDir, `puppeteer_user_data_${runId}`);

  const browser = await puppeteer.launch({ 
    headless: CONFIG.headless,
    userDataDir,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
  });
  
  const parikAllMatches = [];
  const pinnacleAllMatches = [];
  
  for (const league of leagues) {
    console.log(`\n[${league.name}] Processing...`);
    
    // Fetch Parik24
    console.log(`  [parik24]`);
    const parikMatches = await withRetry(
      () => parseParik24WithPuppeteer(browser, league.parik24),
      2,
      `parik24:${league.name}`
    );
    console.log(`  [parik24] Found ${parikMatches.length} matches`);
    parikAllMatches.push(...parikMatches.map(m => ({ ...m, league: league.name })));
    
    // Fetch Pinnacle
    console.log(`  [pinnacle]`);
    const pinnacleMatches = await withRetry(
      () => parsePinnacleWithPuppeteer(browser, league.pinnacle),
      2,
      `pinnacle:${league.name}`
    );
    console.log(`  [pinnacle] Found ${pinnacleMatches.length} matches`);
    pinnacleAllMatches.push(...pinnacleMatches.map(m => ({ ...m, league: league.name })));
    
    // Brief pause
    await new Promise(r => setTimeout(r, 3000));
  }
  
  try { await browser.close(); } catch {}
  
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
      
      const score = findMatchScore(parik.home, parik.away, pinnacle.home, pinnacle.away);
      
      if (score > bestScore) {
        bestScore = score;
        bestMatch = { index: i, score };
      }
    }
    
    if (bestMatch) {
      matched.add(bestMatch.index);
      const pin = pinnacleAllMatches[bestMatch.index];
      merged.push({
        league: parik.league,
        home: parik.home,
        away: parik.away,
        parik24: { p1: parik.p1, x: parik.x, p2: parik.p2, link: parik.link || null },
        pinnacle: { p1: pin.p1, x: pin.x, p2: pin.p2, link: pin.link || null },
        matchScore: bestMatch.score,
      });
      console.log(`  ✓ ${parik.league}: ${parik.home} vs ${parik.away} (score: ${bestMatch.score.toFixed(2)})`);
    } else {
      merged.push({
        league: parik.league,
        home: parik.home,
        away: parik.away,
        parik24: { p1: parik.p1, x: parik.x, p2: parik.p2, link: parik.link || null },
        pinnacle: null,
        matchScore: 0,
      });
    }
  }
  
  fs.writeFileSync(
    path.join(CONFIG.dataDir, 'merged_matches.json'),
    JSON.stringify({ updated: new Date().toISOString(), matches: merged }, null, 2),
    'utf-8'
  );
  console.log(`\n[save] Merged: ${merged.length} matches → merged_matches.json`);
  console.log('[done]');
}

// Run
scrapeLeagues().catch(err => {
  console.error('[ERROR]', err);
  process.exit(1);
});
