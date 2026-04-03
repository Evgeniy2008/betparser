const puppeteer = require('puppeteer');

(async () => {
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage'],
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
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
