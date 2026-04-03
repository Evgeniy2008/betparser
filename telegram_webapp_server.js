const http = require('http');
const { URL } = require('url');

const BOT_TOKEN = '8622990340:AAFSGBYjYfSRTj3RgTUJLACas2YvHNvvx5s';
const WEBAPP_URL = 'https://websitebets.bionrgg.com/';
const PORT = Number(process.env.PORT || 3032);

if (!BOT_TOKEN) {
  console.error('[telegram_webapp_server] Missing TELEGRAM_BOT_TOKEN env');
  process.exit(1);
}

const API_BASE = `https://api.telegram.org/bot${BOT_TOKEN}`;

let isStopping = false;
let currentOffset = 0;

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function callTelegram(method, payload) {
  const response = await fetch(`${API_BASE}/${method}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload || {}),
  });

  if (!response.ok) {
    const text = await response.text();
    throw new Error(`HTTP ${response.status}: ${text}`);
  }

  const json = await response.json();
  if (!json.ok) {
    throw new Error(`Telegram API error: ${JSON.stringify(json)}`);
  }

  return json.result;
}

function makeWebAppKeyboard() {
  return {
    inline_keyboard: [[
      {
        text: 'Открыть сайт',
        web_app: { url: WEBAPP_URL },
      },
    ]],
  };
}

async function sendWelcome(chatId, firstName) {
  const safeName = firstName ? `, ${firstName}` : '';
  const text = `Привет${safeName}! Нажми кнопку ниже, чтобы открыть WebApp.`;

  await callTelegram('sendMessage', {
    chat_id: chatId,
    text,
    reply_markup: makeWebAppKeyboard(),
  });
}

async function handleMessage(message) {
  const chatId = message?.chat?.id;
  if (!chatId) return;

  const text = String(message?.text || '').trim();
  const firstName = message?.from?.first_name || '';

  if (text === '/start' || text === '/webapp' || text === '/menu') {
    await sendWelcome(chatId, firstName);
    return;
  }

  await callTelegram('sendMessage', {
    chat_id: chatId,
    text: 'Команды: /start или /webapp',
    reply_markup: makeWebAppKeyboard(),
  });
}

async function pollLoop() {
  console.log('[telegram_webapp_server] Polling started');

  while (!isStopping) {
    try {
      const updates = await callTelegram('getUpdates', {
        offset: currentOffset,
        timeout: 25,
        allowed_updates: ['message'],
      });

      for (const update of updates) {
        currentOffset = update.update_id + 1;
        if (update.message) {
          await handleMessage(update.message);
        }
      }
    } catch (err) {
      console.error('[telegram_webapp_server] Poll error:', err.message || err);
      await sleep(2000);
    }
  }
}

function startHealthServer() {
  const server = http.createServer((req, res) => {
    const reqUrl = new URL(req.url, `http://${req.headers.host || 'localhost'}`);

    if (reqUrl.pathname === '/health') {
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify({ ok: true, service: 'telegram_webapp_server' }));
      return;
    }

    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8' });
    res.end('telegram_webapp_server is running');
  });

  server.listen(PORT, () => {
    console.log(`[telegram_webapp_server] Health server: http://localhost:${PORT}/health`);
    console.log(`[telegram_webapp_server] WebApp URL: ${WEBAPP_URL}`);
  });

  return server;
}

const healthServer = startHealthServer();
pollLoop();

function shutdown() {
  if (isStopping) return;
  isStopping = true;
  console.log('[telegram_webapp_server] Stopping...');
  healthServer.close(() => process.exit(0));
}

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);
