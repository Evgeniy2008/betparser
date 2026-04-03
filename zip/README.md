# Betparser — Football Odds Scraper

Парсинг матчів та коефіцієнтів (П1/Х/П2) з сайту **24-parik.club/uk/football**.

---

## Структура

```
Betparser/
├── index.php       ← PHP міні-сайт з пагінацією
├── fetch.php       ← Запуск парсера через браузер або CRON
├── scraper.js      ← Puppeteer скрапер (Node.js)
├── package.json    ← Node.js залежності
├── template.html   ← Структура сайту-донора (референс)
└── data/
    ├── matches.json   ← Закешовані дані (генерується парсером)
    ├── scraper.pid    ← PID запущеного процесу
    └── scraper.log    ← Лог останнього запуску
```

---

## Встановлення

### 1. Встановити Node.js
Завантажити з https://nodejs.org/ (рекомендовано LTS ≥ 18)

### 2. Встановити залежності
```bash
cd Betparser
npm install
```
Це встановить `puppeteer` (~170 MB включаючи вбудований Chromium).

---

## Запуск парсера

### Варіант A — через командний рядок (рекомендовано)
```bash
# Без проксі (якщо є VPN на Ukrainian IP):
node scraper.js

# Постійний фоновий режим (оновлення кожні 2 хв):
node scraper.js --daemon --interval=2

# З Ukrainian proxy (якщо VPN немає):
node scraper.js --proxy=http://user:pass@ua-proxy.example.com:8080
node scraper.js --proxy=socks5://proxy.example.com:1080

# Debug-режим (відкриває вікно браузера):
node scraper.js --debug
```

### Варіант B — через веб-інтерфейс
Відкрийте `http://betparser/` → прокрутіть вниз → панель "Оновлення даних".

Сайт також автоматично:
- перевіряє свіжість даних при кожному відкритті,
- запускає оновлення у фоні, якщо кеш застарів,
- перезавантажується, коли з'являються нові коефіцієнти.

---

## Ukrainian Proxy

Сайт **24-parik.club** доступний лише з IP-адрес України.

**Варіанти отримати Ukrainian proxy / IP:**

| Спосіб | Опис |
|--------|------|
| VPN з Ukrainian сервером | NordVPN, ProtonVPN, Surfshark — вибрати Ukraine |
| Proxy-сервіс | [proxyscrape.com](https://proxyscrape.com), [webshare.io](https://webshare.io) → фільтр Ukraine |
| SSH SOCKS5 | `ssh -D 1080 user@ukraine-server.com` → proxy `socks5://127.0.0.1:1080` |
| Мобільний інтернет (UA) | Через власний телефон з UAукраїнською SIM |

Після отримання proxy вкажіть його:
- В полі на сторінці, або  
- Через env-змінну: `UA_PROXY=http://... node scraper.js`

---

## CRON (автоматичне оновлення кожні 30 хв)

```cron
*/30 * * * * UA_PROXY=http://... /usr/bin/node /var/www/Betparser/scraper.js >> /var/www/Betparser/data/cron.log 2>&1
```

---

## Як це працює

1. **Puppeteer** відкриває `24-parik.club/uk/football` через Embedded Chromium
2. Чекає поки завантажиться React-додаток (спінер)
3. Скролить сторінку донизу, доки з'являються нові матчі
4. Витягує назви команд, ліги, дату, та коефіцієнти П1/Х/П2
5. Зберігає в `data/matches.json`
6. PHP `index.php` читає файл та показує з пагінацією

---

## Ліміти

- На сайті дуже багато матчів (може бути 1000+) — скрол займає 3–7 хвилин
- `maxScrolls: 300` — можна підняти в `scraper.js` при потребі
- Кеш вважається застарілим після 1 години (CACHE_TTL в index.php)
