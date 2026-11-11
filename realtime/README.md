# Realtime-сервер «Дикий Лес» (РТ-01)

Отдельный Node.js-процесс для позиций, эмодзи, чата и событий. **Ничего не решает** и **никогда не ходит в PHP** (InfinityFree пускает только браузеры). Источник правды — PHP + MySQL. Схема: `Код/docs/WILD_FOREST_PLAN.md`, раздел 15.

Папка лежит в корне репозитория, а не в `Код/`, чтобы FTP-деплой не заливал её на InfinityFree.

## Что есть сейчас (РТ-01, РТ-02)
- `GET /health` — JSON: `build` (SHA коммита), `uptime_s`, `connections`, `rss_mb`, `tls`.
- WebSocket на том же порту: при подключении приходит `{"t":"hello",...}`, на `{"t":"ping","c":N}` отвечает `pong`.
- Принимает только `Origin` игры (`https://bober-api.gt.tc`) и `localhost`; лимит соединений 150; сообщение до 4 КБ.
- **РТ-02: подписи.** Клиент после `hello` шлёт `{"t":"auth","tok":"..."}` с токеном входа от PHP. Сервер проверяет подпись (Ed25519 или HMAC), тип, срок и окно времени, отвечает `auth_ok` или `err` (`bad_token`, `expired`, `outdated`). Без входа за 10 секунд соединение закрывается, второе соединение того же игрока заменяет первое, токен без продления перестаёт работать через срок + 60 с. Квитанции для PHP подписывает `rt.receipt(...)` (понадобится в РТ-03). Формат и коды: `lib/proto.js`, `lib/tokens.js`, раздел 25 в `Код/docs/WILD_FOREST.md`.
- Лимит памяти `--max-old-space-size=120`, обрыв «тихих» соединений пингом раз в 25 с.

## Переменные окружения
| Переменная | По умолчанию | Смысл |
|---|---|---|
| `PORT` | 8080 | порт (платформа обычно задаёт сама) |
| `BUILD_SHA` | dev | SHA коммита (в Docker — `--build-arg BUILD_SHA=...`) |
| `WF_ORIGINS` | игра + localhost | разрешённые Origin через запятую |
| `WF_MAX_CONN` | 150 | потолок соединений |
| `WF_TLS_CERT`, `WF_TLS_KEY` | пусто | пути к сертификату и ключу: тогда сервер сам отдаёт HTTPS/wss |
| `WF_RT_ALG` | ed25519 | алгоритм подписи: `ed25519` или `hmac` |
| `WF_RT_VERIFY_KEYS` | пусто | ключи проверки токенов PHP: `kid:ключ,kid2:ключ2` (base64url) |
| `WF_RT_SIGN_KID`, `WF_RT_SIGN_KEY` | пусто | ключ, которым РТ подписывает квитанции |
| `WF_AUTH_TIMEOUT_MS` | 10000 | сколько ждать токен после подключения |

## Ключи (делается один раз)
```bash
cd realtime
node tools/gen-keys.mjs            # Ed25519 (если на хостинге нет sodium: node tools/gen-keys.mjs --hmac)
```
Скрипт печатает два набора: (1) секреты GitHub `BOBER_RT_*` для PHP, (2) переменные `WF_RT_*` для `realtime/.env` на VPS. Ключи в репозиторий не попадают: workflow сам пишет `Код/config/rt_config.php` из секретов. Проверить связку: открыть `tools/forest-selftest.php` (раздел «Realtime»), скопировать напечатанный там токен и выполнить `WF_RT_ALG=... WF_RT_VERIFY_KEYS=p1:... node tools/check-token.mjs ТОКЕН`.

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
