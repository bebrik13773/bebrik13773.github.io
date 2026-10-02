# DEPLOY.AI.md — развёртывание кликера (версия для ИИ-агентов)

> Это версия для ИИ-агентов. Версия для людей: [DEPLOY.md](DEPLOY.md). Факты в обоих файлах одинаковые.

## 0. Обязательные правила (прочитай до начала)

1. Проект под лицензией [LICENSE.txt](../LICENSE.txt). **Нельзя** развернуть неизменённую копию. Публичный деплой разрешён только после «сильной переработки»: новое название, новый персонаж, ВСЕ графика/скины/иконки/звуки заменены, заметно другой UI и тексты, убраны ссылки на оригинал.
2. Не используй графику, звуки и тексты оригинала в переработанной версии.
3. Перед началом спроси у пользователя: новое название, новый персонаж, домен, данные хостинга. Не придумывай сам.
4. Не коммить в публичный репозиторий реальные пароли, токены и готовые `db_config.php` / `ai_config.php`.
5. Оставь `LICENSE.txt` и добавь упоминание, что проект вдохновлён «Бобёр Кликер» (github.com/bebrik13773/bebrik13773.github.io).

## 1. Требования

- PHP с расширениями `mysqli`, `curl`, `mbstring`, `openssl`; MySQL/MariaDB.
- Домен (лучше HTTPS), FTP-доступ к хостингу.
- Опционально: ключ OpenAI-совместимого API (RouterAI), Telegram-бот.

## 2. Структура

- Весь деплоимый код: `Код/` (содержимое → корень хостинга).
- Клиент: `Код/pages/clicker/index.html` (монолит HTML+CSS+JS).
- API и бизнес-логика: `Код/api/**`, главный файл `Код/api/bootstrap/db.php`.
- Админка: `Код/admin/index.php`.
- Деплой: `.github/workflows/main.yml` (FTP, `main` → `htdocs/`, прочие ветки → `htdocs/test/`).
- Карта кода: `AGENTS.md`.

## 3. Чек-лист

### 3.1 Переработка (до публикации)

- [ ] Переименовать проект и персонажа: `Код/pages/clicker/index.html`, `Код/index.html`, `Код/*.html` (страницы ошибок).
- [ ] Заменить графику: `Код/assets/skins/`, `Код/assets/fly/`, `Код/pages/*.png|jpg`.
- [ ] Заменить звуки: `Код/assets/sounds/`.
- [ ] Переписать каталог скинов: `Код/data/skin-catalog.json`.
- [ ] Переписать промпты ИИ: `Код/api/ai-assistant/prompts/*.md`.
- [ ] Изменить цвета и компоновку: `Код/assets/css/`, стили в `index.html`.

### 3.2 Поиск привязок к оригиналу

```bash
grep -rn "bober-api.gt.tc\|bebrik13773" Код .github --include=*.php --include=*.js --include=*.html --include=*.yml
```

Заменить (минимум):

- `Код/pages/clicker/index.html`: `CONFIG.owner`, `CONFIG.repo` (проверка версии через GitHub API), ссылка в подвале.
- `Код/api/bootstrap/db.php`: массив `$allowedHosts` (проверка Origin/Referer; без своего домена API вернёт 403).
- `Код/index.html`: редирект на домен оригинала.
- `.github/workflows/main.yml`: URL в сводке деплоя.

### 3.3 База данных

- Создать пустую БД и пользователя с полными правами на неё.
- Таблицы создаются автоматически при первых запросах (schema backfill в `db.php`), вручную их не создавать.

### 3.4 Конфиги

- `Код/config/db_config.php.example` → `Код/config/db_config.php`: `db_host`, `db_user`, `db_pass`, `db_name`, `admin_password_hash` (или `admin_initial_password`).
- Хэш пароля: `php -r "echo password_hash('PASSWORD', PASSWORD_DEFAULT);"`
- Опционально `Код/config/ai_config.php.example` → `Код/config/ai_config.php`: `ai_api_key`, `ai_base_url`, `ai_model`, `tg_bot_token`, `tg_chat_id`, `dm_encryption_key`.
- Обязательно задать свой `dm_encryption_key` (иначе используется общий резервный ключ из кода).
- Альтернатива файлам — переменные окружения: `BOBER_DB_HOST`, `BOBER_DB_USER`, `BOBER_DB_PASS`, `BOBER_DB_NAME`, `BOBER_ADMIN_PASSWORD_HASH`, `BOBER_ADMIN_INITIAL_PASSWORD`, `BOBER_DM_ENCRYPTION_KEY`, `BOBER_TG_BOT_TOKEN`.

### 3.5 Деплой

Вариант A — вручную: залить содержимое `Код/` в корень сайта по FTP.

Вариант B — GitHub Actions. Секреты репозитория:

- FTP: `server`, `username`, `password`
- БД: `BOBER_DB_HOST`, `BOBER_DB_USER`, `BOBER_DB_PASS`, `BOBER_DB_NAME`
- Админка: `BOBER_ADMIN_PASSWORD_HASH` или `BOBER_ADMIN_INITIAL_PASSWORD`
- Опционально: `BOBER_AI_API_KEY`, `BOBER_TG_BOT_TOKEN`, `BOBER_TG_CHAT_ID`, `BOBER_DM_ENCRYPTION_KEY`

Пуш в `main` запускает workflow (генерирует конфиги из секретов и заливает `Код/`).

### 3.6 Проверка

- [ ] `https://<домен>/pages/clicker/` открывается и загружает игру.
- [ ] Регистрация, тапы, перезагрузка страницы — прогресс сохранился.
- [ ] `https://<домен>/admin/` — вход паролем администратора работает.
- [ ] В консоли браузера нет ответов 403 «чужой источник» (значит `$allowedHosts` исправлен).

## 4. Типичные сбои

| Симптом | Причина |
|---|---|
| Пустая страница / 500 | Нет или неверен `db_config.php`, нет PHP-расширений |
| 403 «Запрос отклонён: чужой источник» | Домен не добавлен в `$allowedHosts` |
| Прогресс не сохраняется | Нет прав у пользователя БД, ошибки в ответах API |
| ИИ-помощник молчит | Пустой/неверный `ai_api_key` |
| Нет уведомлений в Telegram | Неверные `tg_bot_token`/`tg_chat_id`, бот не начал диалог |
| Постоянная перезагрузка страницы | Не исправлен `CONFIG.owner/repo` или не подставляется ID билда |

## 5. Дополнительно

- Android-приложение: [ANDROID_APP.md](ANDROID_APP.md)
- Автопубликация новостей: `.github/workflows/news.yml` + `scripts/publish-news-browser.js` (нужен Playwright, секреты и свой домен).
