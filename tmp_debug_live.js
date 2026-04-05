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
const profileDir = path.join(PROFILE_ROOT, `debug-live-${process.pid}-${Date.now()}`);
fs.mkdirSync(profileDir, { recursive: true });

const CHROME_NO_CACHE_ARGS = [
  '--disable-cache',
  '--disable-application-cache',
  '--disk-cache-size=0',
  '--media-cache-size=0',
  '--aggressive-cache-discard',
];

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

  const page = await browser.newPage();
  await page.setViewport({ width: 1280, height: 800 });

  const url = process.argv[2];
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 45000 });
  await new Promise((resolve) => setTimeout(resolve, 2500));

  for (let i = 0; i < 5; i++) {
    await page.evaluate(() => window.scrollBy(0, 900));
    await new Promise((resolve) => setTimeout(resolve, 700));
  }

  const info = await page.evaluate(() => ({
    title: document.title,
    url: location.href,
    readyState: document.readyState,
    eventAnchors: document.querySelectorAll('a[href*="/events/"]').length,
    mainMarkets: document.querySelectorAll('[data-onboarding="event-card-main-market"]').length,
    tournaments: document.querySelectorAll('[data-onboarding^="tournament-"]').length,
    rows: document.querySelectorAll('div[class*="row-"]').length,
    moneylines: document.querySelectorAll('div[data-test-id="moneyline"]').length,
    textSample: document.body.innerText.slice(0, 2000),
  }));

  console.log(JSON.stringify(info, null, 2));
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
