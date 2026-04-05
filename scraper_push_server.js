const { spawn, spawnSync } = require('child_process');
const path = require('path');
const fs = require('fs');

const DEFAULT_INTERVAL = 0; // by default restart immediately after finish
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
const cacheDir = path.resolve(getArg('cache-dir', process.env.BETPARSER_CACHE_DIR || 'D:\\BetparserCache'));
const tempDir = path.join(cacheDir, 'temp');
const puppeteerCacheDir = path.join(cacheDir, 'puppeteer');

for (const dir of [cacheDir, tempDir, puppeteerCacheDir]) {
  fs.mkdirSync(dir, { recursive: true });
}

function ensurePuppeteerChromeInstalled() {
  const envForCheck = {
    ...process.env,
    PUPPETEER_CACHE_DIR: puppeteerCacheDir,
    BETPARSER_CACHE_DIR: cacheDir,
    TEMP: tempDir,
    TMP: tempDir,
  };

  let hasBrowser = false;
  try {
    const puppeteer = require('puppeteer');
    const executable = puppeteer.executablePath();
    hasBrowser = !!executable && fs.existsSync(executable);
  } catch (_) {
    hasBrowser = false;
  }

  if (hasBrowser) return;

  console.log('[scraper_push_server] Chrome not found in cache, installing...');
  const npxCmd = process.platform === 'win32' ? 'npx.cmd' : 'npx';
  const install = spawnSync(npxCmd, ['puppeteer', 'browsers', 'install', 'chrome'], {
    cwd: workingDir,
    env: envForCheck,
    stdio: 'inherit',
  });

  if (install.status !== 0) {
    console.error('[scraper_push_server] Failed to install Puppeteer Chrome');
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
    // Continue and try resolving as relative URL.
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

console.log('[scraper_push_server] Starting daemon');
console.log(`  script: ${nodeScript}`);
console.log(`  post-url: ${postUrl}`);
console.log(`  cache-dir: ${cacheDir}`);
console.log(`  interval: ${intervalMs} ms (${intervalMs === 0 ? 'instant restart' : 'delayed restart'})`);

let running = false;
let runCount = 0;

async function runScraper() {
  if (running) {
    console.log('[scraper_push_server] Scraper already running, skipping iteration');
    return;
  }

  running = true;
  runCount += 1;
  console.log(`\n[scraper_push_server] Run #${runCount} started at ${new Date().toISOString()}`);

  const child = spawn(process.execPath, [path.join(workingDir, nodeScript)], {
    cwd: workingDir,
    env: {
      ...process.env,
      SCRAPER_MODE: 'http',
      BETPARSER_CACHE_DIR: cacheDir,
      PUPPETEER_CACHE_DIR: puppeteerCacheDir,
      TEMP: tempDir,
      TMP: tempDir,
      POST_URL: postUrl,
    },
    stdio: ['ignore', 'pipe', 'pipe'],
  });

  child.stdout.on('data', (chunk) => process.stdout.write(chunk));
  child.stderr.on('data', (chunk) => process.stderr.write(chunk));

  return new Promise((resolve, reject) => {
    child.on('close', (code) => {
      running = false;
      console.log(`[scraper_push_server] Run #${runCount} finished with code ${code}`);
      resolve(code);
    });

    child.on('error', (err) => {
      running = false;
      console.error('[scraper_push_server] Child process error:', err);
      reject(err);
    });
  });
}

// Start immediately and then restart after each finish
async function startLoop() {
  await runScraper();
  if (intervalMs === 0) {
    setImmediate(startLoop);
    return;
  }
  setTimeout(startLoop, intervalMs);
}

startLoop();
