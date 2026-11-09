# Realtime-сервер «Дикий Лес» (РТ-01)

Отдельный Node.js-процесс для позиций, эмодзи, чата и событий. **Ничего не решает** и **никогда не ходит в PHP** (InfinityFree пускает только браузеры). Источник правды — PHP + MySQL. Схема: `Код/docs/WILD_FOREST_PLAN.md`, раздел 15.

Папка лежит в корне репозитория, а не в `Код/`, чтобы FTP-деплой не заливал её на InfinityFree.

## Что есть сейчас (РТ-01)
- `GET /health` — JSON: `build` (SHA коммита), `uptime_s`, `connections`, `rss_mb`, `tls`.
- WebSocket на том же порту: при подключении приходит `{"t":"hello",...}`, на `{"t":"ping","c":N}` отвечает `pong`.
- Принимает только `Origin` игры (`https://bober-api.gt.tc`) и `localhost`; лимит соединений 150; сообщение до 4 КБ.
- Лимит памяти `--max-old-space-size=120`, обрыв «тихих» соединений пингом раз в 25 с.

## Переменные окружения
| Переменная | По умолчанию | Смысл |
|---|---|---|
| `PORT` | 8080 | порт (платформа обычно задаёт сама) |
| `BUILD_SHA` | dev | SHA коммита (в Docker — `--build-arg BUILD_SHA=...`) |
| `WF_ORIGINS` | игра + localhost | разрешённые Origin через запятую |
| `WF_MAX_CONN` | 150 | потолок соединений |
| `WF_TLS_CERT`, `WF_TLS_KEY` | пусто | пути к сертификату и ключу: тогда сервер сам отдаёт HTTPS/wss |

## Запуск и тест
```bash
cd realtime
npm install
npm test          # /health, hello, Origin, лимит соединений
npm start
```

## Деплой на VPS (основной способ)
Хостинг «приложений» не принимает прямые соединения IP:порт (только веб-хуки на платных тарифах), поэтому realtime живёт на **VPS** (около 200 ₽ в месяц, хватит 1  vCPU и 512 МБ–1 ГБ ОЗУ, Ubuntu 22.04/24.04 или Debian 12, открытые порты 80 и 443).

**TLS делает Caddy автоматически** (Let's Encrypt, продление само): сертификаты руками не нужны.

1. Бесплатный домен: на duckdns.org создать поддомен (например `bober-forest.duckdns.org`) и указать IP VPS.
2. На VPS от root одной командой:
   ```bash
   curl -fsSL https://raw.githubusercontent.com/bebrik13773/bebrik13773.github.io/main/realtime/deploy/setup.sh | bash -s -- bober-forest.duckdns.org
   ```
   Скрипт ставит Docker, firewall (22, 80, 443), файл подкачки при малой памяти, клонирует репозиторий в `/opt/wf` и запускает `docker compose up -d --build`.
3. Проверка: `https://ВАШ-ДОМЕН/health` отдаёт JSON.
4. Обновление после пуша: `bash /opt/wf/realtime/deploy/update.sh`.

Настройки лежат в `/opt/wf/realtime/.env` (пример: `deploy/env.example`).

Без Docker тоже можно: `npm ci --omit=dev && npm start` под systemd, а перед ним любой прокси с wss (Caddy, nginx). Встроенный TLS через `WF_TLS_CERT` и `WF_TLS_KEY` оставлен на крайний случай.

## Чек-лист владельца (ручная проверка)
- [x] VPS арендован, поддомен DuckDNS указывает на его IP.
- [x] `setup.sh` отработал, `https://ДОМЕН/health` отвечает JSON.
- [ ] На странице игры в консоли: `const w=new WebSocket('wss://ДОМЕН'); w.onmessage=e=>console.log(e.data)` → приходит `hello`.
- [ ] То же с телефона в мобильной сети (не по Wi-Fi).
- [x] После перезагрузки VPS сервис поднимается сам (`restart: unless-stopped`).

## Заполнить после аренды
- Провайдер и тариф: Aeza, Promo
- IP, ОС, ОЗУ: 109.120.155.195, Ubuntu 26.04, 4 gb
- Домен: https://bober-forest.duckdns.org/
