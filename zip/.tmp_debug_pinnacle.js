const puppeteer = require('puppeteer');
(async () => {
  const url = 'https://www.pinnacle.com/en/soccer/spain-copa-del-rey/atletico-madrid-vs-real-sociedad/1625468891/';
  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-dev-shm-usage', '--disable-gpu', '--window-size=1366,900']
  });
  try {
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    await page.goto(url, { waitUntil: 'networkidle2', timeout: 90000 });
    await page.waitForTimeout(3000);
    const data = await page.evaluate(() => {
      const rows = [];
      for (const el of document.querySelectorAll('[data-anchor^="outcome_"]')) {
        const anchor = el.getAttribute('data-anchor');
        if (!anchor) continue;
        rows.push({
          anchor: anchor.slice(0, 200),
          text: el.textContent.replace(/\s+/g, ' ').trim(),
          html: el.innerHTML.slice(0, 200),
        });
        if (rows.length >= 20) break;
      }
      return rows;
    });
    console.log(JSON.stringify(data, null, 2));
  } catch (err) {
    console.error(err);
  } finally {
    await browser.close();
  }
})();
