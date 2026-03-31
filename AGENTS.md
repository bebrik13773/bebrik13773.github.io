# AGENTS.md

## Назначение

Этот репозиторий содержит сайт и игру `Бобер кликер` с мини-игрой `Летающий бобер`, облачной синхронизацией, системой достижений, поддержкой через тикеты и админкой.

Документ нужен как короткая рабочая инструкция для следующих правок, чтобы не ломать уже связанные между собой клиентские и серверные контуры.

## Карта репозитория

- `Код/index.html`
  - compatibility redirect на основной кликер.
- `Код/pages/clicker/index.html`
  - главный клиент приложения;
  - кликер, магазин, профиль, настройки, лидерборды, поддержка, античит, achievements UI.
- `Код/games/fly-beaver/index.html`
  - мини-игра `Летающий бобер`;
  - облачное сохранение забегов, вывод награды, отдельный fullscreen unlock для достижений.
- `Код/assets/js/shared-client.js`
  - общие клиентские утилиты;
  - определения достижений;
  - normalize/default для пользовательских настроек;
  - anti-cheat utils и shared helpers.
- `Код/assets/`
  - основная директория статики;
  - `assets/js`, `assets/css`, `assets/skins`, `assets/sounds`, `assets/fly`, `assets/leaderboard` являются источником истины.
- `Код/api/bootstrap/db.php`
  - главный серверный слой;
  - schema backfill;
  - snapshot аккаунта;
  - achievements, support tickets, fly-beaver progress, топ-награды, каталог скинов.
- `Код/api/state/sync.php`
  - единый live/sync endpoint для главной страницы;
  - должен оставаться основным источником snapshot-данных.
- `Код/api/state/save.php`
  - сохранение основного прогресса кликера.
- `Код/api/fly/save-run.php`
  - сохранение забега `Летающего бобра`.
- `Код/api/fly/claim-reward.php`
  - перевод награды из `fly-beaver` в кликер.
- `Код/api/support/tickets.php`
  - пользовательский API центра поддержки.
- `Код/admin/index.php`
  - админка: аккаунты, скины, поддержка, логи, SQL, статистика.
- `Код/data/skin-catalog.json`
  - каталог скинов и часть их публичной конфигурации.
- `Код/docs/FUTURE.html`
  - актуальная roadmap/status-страница.
- `Код/docs/FUTURE.md`
  - markdown-указатель на roadmap.

Совместимость со старым кэшем сохранена:

- `Код/shared-client.js`
- `Код/styles.css`
- `Код/c.css`
- `Код/skins/`
- `Код/sounds/`
- `Код/fly-assets/`
- `Код/skin-catalog.json`

Это compatibility-зеркала. Новые правки нужно вносить в `Код/assets/...`, а не в корневые копии.
Каталог скинов редактируется в `Код/data/skin-catalog.json`, а не в корневом compatibility-файле.

## Ключевые контракты

### 1. Главный live-sync

- Основной live endpoint: `Код/api/state/sync.php`.
- Старый compatibility URL `Код/sync-state.php` оставлен как thin-wrapper.
- Если нужна новая периодическая синхронизация на главной, сначала встраивать её в `api/state/sync.php`, а не плодить новый poll endpoint.
- `api/state/sync.php` уже отдаёт:
  - account snapshot;
  - leaderboard summaries;
  - settings;
  - support summary;
  - achievement unlocks;
  - fly-beaver summary.

### 2. Настройки

- Источник истины для серверной версии настроек: `user_settings.updated_at`.
- В клиенте это приходит как `settingsUpdatedAt`.
- Настройки работают по local-first схеме:
  - локально применяются сразу;
  - затем синхронизируются через `api/state/sync.php`;
  - более старый snapshot не должен перетирать более новую локальную правку.

Если меняется схема настроек, нужно править одновременно:

- `Код/assets/js/shared-client.js`
  - `defaultUserSettings()`
  - `normalizeUserSettings()`
- `Код/api/bootstrap/db.php`
  - fetch/store user settings
- `Код/api/state/sync.php`
  - возврат `settings` и `settingsUpdatedAt`
- `Код/pages/clicker/index.html`
  - UI настроек и local-first логика
- `Код/games/fly-beaver/index.html`
  - применение настроек на `load/pageshow/focus/storage`

### 3. Достижения

- Определения достижений живут в `Код/assets/js/shared-client.js`.
- Награды и серверная логика unlock живут в `Код/api/bootstrap/db.php`.
- Unlock popup есть в двух местах:
  - `Код/pages/clicker/index.html`
  - `Код/games/fly-beaver/index.html`

Если добавляется новое достижение, нужно обновить:

- `Код/assets/js/shared-client.js`
  - title / description / icon
- `Код/api/bootstrap/db.php`
  - reward map;
  - snapshot-условия;
  - server unlock flow
- `Код/pages/clicker/index.html`
  - профиль и popup очереди unlock
- `Код/games/fly-beaver/index.html`
  - очередь unlock во время забега

### 4. Поддержка и тикеты

- Пользовательский центр поддержки находится в `Код/pages/clicker/index.html`.
- Админский раздел поддержки находится в `Код/admin/index.php`.
- Схема и backfill таблиц находятся в `Код/api/bootstrap/db.php`.
- API пользователя: `Код/api/support/tickets.php`.

Если меняется тикетный flow, нужно проверять оба контура:

- пользователь;
- админ;
- unread counters через `api/state/sync.php`.

### 5. Топовые награды и уникальные скины

- У кликера и `fly-beaver` есть эксклюзивные top-1 reward skins.
- В каждый момент должен существовать только один выданный экземпляр такого скина.
- Логика reconcile находится в `Код/api/bootstrap/db.php`.

Если меняется top-skin логика, нужно проверять:

- `Код/api/bootstrap/db.php`
- `Код/data/skin-catalog.json`
- `Код/pages/clicker/index.html`
- `Код/admin/index.php`

### 6. Магазин и картинки

- Для bitmap/image-heavy зон использовать безопасный рендер через `<img>`.
- Не возвращаться к паттерну `background-image + blur/filter` на том же слое, где рисуется изображение.
- Это особенно важно для:
  - карточек магазина;
  - preview скинов;
  - админских preview.

## Правила изменения связанных зон

### Если меняется каталог скинов

Обновлять сразу:

- `Код/data/skin-catalog.json`
- `Код/api/bootstrap/db.php`
- `Код/pages/clicker/index.html`
- `Код/admin/index.php`

### Если меняется экономика `fly-beaver`

Проверять сразу:

- `Код/api/fly/save-run.php`
- `Код/api/fly/claim-reward.php`
- `Код/games/fly-beaver/index.html`
- `Код/pages/clicker/index.html`
- `Код/api/bootstrap/db.php`

### Если меняется античит

Проверять отдельно:

- desktop mouse path;
- keyboard path;
- mobile touch/multitouch path;
- бан-экран;
- апелляцию через встроенную поддержку.

Главный клиентский античит сейчас находится в `Код/pages/clicker/index.html`.

## Проверки перед коммитом

Минимум:

- `git diff --check`
- `git status --short`

Если `php` установлен:

- `php -l Код/api/bootstrap/db.php`
- `php -l Код/api/state/sync.php`
- и `php -l` для каждого измененного server endpoint

Ручная проверка после значимых правок:

- вход / регистрация;
- магазин и закрытие магазина;
- сохранение и применение настроек;
- поддержка и автообновление переписки;
- achievements popup на главной и в `Код/games/fly-beaver/index.html`;
- сохранение забега и перевод награды `fly-beaver`;
- админка, если трогались тикеты, скины или логи.

## Деплой и секреты

Для PHP-деплоя workflow ожидает GitHub Secrets:

- `BOBER_DB_HOST`
- `BOBER_DB_USER`
- `BOBER_DB_PASS`
- `BOBER_DB_NAME`
- `BOBER_ADMIN_PASSWORD_HASH` или `BOBER_ADMIN_INITIAL_PASSWORD`

Источник: `README.md`.

## Практическое правило

Если правка затрагивает один из центральных контуров ниже, считать её сквозной, а не локальной:

- `settings`
- `sync-state`
- `achievements`
- `support tickets`
- `skin catalog`
- `fly-beaver cloud flow`
- `top reward skins`

Для таких правок почти всегда нужно обновлять и клиент, и сервер, и минимум один ручной сценарий проверки.
