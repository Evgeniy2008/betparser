'use strict';

const BOT_TOKEN = process.env.TELEGRAM_BOT_TOKEN || '8568196793:AAHrD1X0NxS8LOBdFu1xhkyVteGitHRBgeg';
const WEBAPP_URL = process.env.TELEGRAM_WEBAPP_URL || 'https://websitebets.bionrgg.com';

if (!BOT_TOKEN) {
  console.error('TELEGRAM_BOT_TOKEN is required');
  process.exit(1);
}

const API_BASE = `https://api.telegram.org/bot${BOT_TOKEN}`;
let offset = 0;

async function tgApi(method, payload = {}) {
  const res = await fetch(`${API_BASE}/${method}`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify(payload),
  });

  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data?.ok) {
    throw new Error(`Telegram API ${method} failed: ${JSON.stringify(data).slice(0, 300)}`);
  }
  return data.result;
}

function webAppKeyboard() {
  return {
    keyboard: [[{ text: 'Открыть WebsiteBets', web_app: { url: WEBAPP_URL } }]],
    resize_keyboard: true,
    is_persistent: true,
  };
}

async function sendWebAppPrompt(chatId) {
  await tgApi('sendMessage', {
    chat_id: chatId,
    text: 'Нажмите кнопку ниже, чтобы открыть WebApp:',
    reply_markup: webAppKeyboard(),
  });
}

async function configureMenuButton() {
  try {
    await tgApi('setChatMenuButton', {
      menu_button: {
        type: 'web_app',
        text: 'Открыть WebsiteBets',
        web_app: { url: WEBAPP_URL },
      },
    });
    console.log('Menu button configured');
  } catch (err) {
    console.error('setChatMenuButton error:', err.message);
  }
}

function extractText(update) {
  return String(update?.message?.text || '').trim().toLowerCase();
}

async function handleUpdate(update) {
  const msg = update?.message;
  if (!msg?.chat?.id) return;

  const chatId = msg.chat.id;
  const text = extractText(update);

  if (text === '/start' || text === '/open' || text === 'open' || text === 'start') {
    await sendWebAppPrompt(chatId);
    return;
  }

  if (text === '/help') {
    await tgApi('sendMessage', {
      chat_id: chatId,
      text: 'Команды:\n/start — открыть WebApp\n/open — открыть WebApp',
    });
    return;
  }
}

async function pollLoop() {
  while (true) {
    try {
      const updates = await tgApi('getUpdates', {
        offset,
        timeout: 30,
        allowed_updates: ['message'],
      });

      for (const update of updates) {
        offset = update.update_id + 1;
        try {
          await handleUpdate(update);
        } catch (err) {
          console.error('handleUpdate error:', err.message);
        }
      }
    } catch (err) {
      console.error('poll error:', err.message);
      await new Promise((resolve) => setTimeout(resolve, 3000));
    }
  }
}

(async () => {
  console.log('Telegram WebApp bot starting...');
  console.log('WebApp URL:', WEBAPP_URL);
  await configureMenuButton();
  await pollLoop();
})();
