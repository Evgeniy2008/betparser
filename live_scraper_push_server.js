const { spawn, spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const DEFAULT_INTERVAL = 5000; // 5 seconds default for live updates
const DEFAULT_POST_URL = 'https://websitebets.bionrgg.com/update_merged.php';

const args = process.argv.slice(2);
const getArg = (name, fallback) => {
  const prefix = `--${name}=`;
  const arg = args.find((item) => item.startsWith(prefix));
  return arg ? arg.slice(prefix.length) : fallback;
};

const intervalRaw = Number(getArg('interval', DEFAULT_INTERVAL));
const intervalMs = Number.isFinite(intervalRaw) && intervalRaw >= 0 ? intervalRaw : DEFAULT_INTERVAL;
const rawPostUrl = getArg('post-url', process.env.POST_URL || DEFAULT_POST_URL);
const nodeScript = getArg('script', 'league_scraper.js');
const workingDir = path.resolve(__dirname);
const cacheDir = path.resolve(getArg('cache-dir', process.env.BETPARSER_CACHE_DIR || path.join(workingDir, '.cache')));
const tempDir = path.join(cacheDir, 'temp');
const puppeteerCacheDir = process.env.PUPPETEER_CACHE_DIR
  ? path.resolve(process.env.PUPPETEER_CACHE_DIR)
  : '';

for (const dir of [cacheDir, tempDir, ...(puppeteerCacheDir ? [puppeteerCacheDir] : [])]) {
  fs.mkdirSync(dir, { recursive: true });
}

process.env.BETPARSER_CACHE_DIR = cacheDir;
if (puppeteerCacheDir) {
  process.env.PUPPETEER_CACHE_DIR = puppeteerCacheDir;
} else {
  delete process.env.PUPPETEER_CACHE_DIR;
}
process.env.TEMP = tempDir;
process.env.TMP = tempDir;

function ensurePuppeteerChromeInstalled() {
  const envForCheck = { ...process.env };

  let hasBrowser = false;
  try {
    const puppeteer = require('puppeteer');
    const executable = puppeteer.executablePath();
    hasBrowser = !!executable && fs.existsSync(executable);
  } catch (_) {
    hasBrowser = false;
  }

  if (hasBrowser) return;

  console.log('[live_scraper_push_server] Chrome not found in cache, installing...');
  const npxCmd = process.platform === 'win32' ? 'npx.cmd' : 'npx';
  const install = spawnSync(npxCmd, ['puppeteer', 'browsers', 'install', 'chrome'], {
    cwd: workingDir,
    env: envForCheck,
    stdio: 'inherit',
  });

  if (install.status !== 0) {
    console.error('[live_scraper_push_server] Failed to install Puppeteer Chrome');
    process.exit(1);
  }
}

ensurePuppeteerChromeInstalled();

function resolvePostUrl(value) {
  const input = String(value || '').trim();
  if (!input) return '';

  try {
    return new URL(input).toString();
  } catch (_) {
    // Try resolve as relative URL.
  }

  const base = String(process.env.APP_BASE_URL || process.env.BASE_URL || 'http://localhost').trim();
  try {
    return new URL(input, base).toString();
  } catch (_) {
    return '';
  }
}

const postUrl = resolvePostUrl(rawPostUrl);

if (!postUrl) {
  console.error('Error: invalid post-url. Use absolute URL or set APP_BASE_URL for relative paths, e.g. --post-url=/update_merged.php');
  process.exit(1);
}

console.log('[live_scraper_push_server] Starting live match daemon');
console.log(`  script: ${nodeScript}`);
console.log(`  post-url: ${postUrl}`);
console.log(`  cache-dir: ${cacheDir}`);
console.log(`  puppeteer-cache-dir: ${puppeteerCacheDir || 'default user cache'}`);
console.log(`  proxy: ${process.env.HTTP_PROXY || process.env.BETPARSER_PROXY || 'none'}`);
console.log(`  update interval: ${intervalMs}ms (${(intervalMs / 1000).toFixed(1)}s)`);;
console.log('  mode: LIVE-ONLY with full match block rewrite every cycle');

let running = false;
let runCount = 0;

async function runScraper() {
  if (running) {
    console.log('[live_scraper_push_server] Scraper already running, skipping iteration');
    return;
  }

  running = true;
  runCount += 1;
  console.log(`\n[live_scraper_push_server] Run #${runCount} started at ${new Date().toISOString()}`);

  const child = spawn(process.execPath, [path.join(workingDir, nodeScript)], {
    cwd: workingDir,
    env: {
      ...process.env,
      SCRAPER_MODE: 'http',
      SCRAPER_SCOPE: 'live-only',
      BETPARSER_CACHE_DIR: cacheDir,
      TEMP: tempDir,
      TMP: tempDir,
      POST_URL: postUrl,
      ...(puppeteerCacheDir ? { PUPPETEER_CACHE_DIR: puppeteerCacheDir } : {}),
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  child.stdout.on('data', (chunk) => process.stdout.write(chunk));
  child.stderr.on('data', (chunk) => process.stderr.write(chunk));

  return new Promise((resolve, reject) => {
    child.on('close', (code) => {
      running = false;
      if (code === 0) {
        console.log(`✓ [${new Date().toISOString().slice(11, 19)}] Live matches updated successfully (Run #${runCount})`);
      } else {
        console.log(`✗ [${new Date().toISOString().slice(11, 19)}] Live matches update failed with code ${code} (Run #${runCount})`);
      }
      resolve(code);
    });

    child.on('error', (err) => {
      running = false;
      console.error('[live_scraper_push_server] Child process error:', err);
      reject(err);
    });
  });
}

async function startLoop() {
  await runScraper();
  if (intervalMs === 0) {
    setImmediate(startLoop);
    return;
  }
  setTimeout(startLoop, intervalMs);
}

startLoop();
