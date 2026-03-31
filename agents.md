# agents.md

## Назначение

Этот репозиторий содержит сайт и игру `Бобер кликер` с мини-игрой `Летающий бобер`, облачной синхронизацией, системой достижений, поддержкой через тикеты и админкой.

Документ нужен как короткая рабочая инструкция для следующих правок, чтобы не ломать уже связанные между собой клиентские и серверные контуры.

## Карта репозитория

- `index.html`
  - корневая страница-обертка сайта.
- `Код/index.html`
  - главный клиент приложения;
  - кликер, магазин, профиль, настройки, лидерборды, поддержка, античит, achievements UI.
- `Код/fly-beaver.html`
  - мини-игра `Летающий бобер`;
  - облачное сохранение забегов, вывод награды, отдельный fullscreen unlock для достижений.
- `Код/shared-client.js`
  - общие клиентские утилиты;
  - определения достижений;
  - normalize/default для пользовательских настроек;
  - anti-cheat utils и shared helpers.
- `Код/db.php`
  - главный серверный слой;
  - schema backfill;
  - snapshot аккаунта;
  - achievements, support tickets, fly-beaver progress, топ-награды, каталог скинов.
- `Код/sync-state.php`
  - единый live/sync endpoint для главной страницы;
  - должен оставаться основным источником snapshot-данных.
- `Код/save-state.php`
  - сохранение основного прогресса кликера.
- `Код/fly-save-run.php`
  - сохранение забега `Летающего бобра`.
- `Код/fly-claim-reward.php`
  - перевод награды из `fly-beaver` в кликер.
- `Код/support-tickets.php`
  - пользовательский API центра поддержки.
- `Код/admin/index.php`
  - админка: аккаунты, скины, поддержка, логи, SQL, статистика.
- `Код/skin-catalog.json`
  - каталог скинов и часть их публичной конфигурации.
- `Код/FUTURE.html`
  - актуальная roadmap/status-страница.
- `Код/FUTURE.md`
  - короткий указатель на `FUTURE.html`.

## Ключевые контракты

### 1. Главный live-sync

- Основной live endpoint: `Код/sync-state.php`.
- Если нужна новая периодическая синхронизация на главной, сначала встраивать её в `sync-state.php`, а не плодить новый poll endpoint.
- `sync-state.php` уже отдаёт:
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
  - затем синхронизируются через `sync-state.php`;
  - более старый snapshot не должен перетирать более новую локальную правку.

Если меняется схема настроек, нужно править одновременно:

- `Код/shared-client.js`
  - `defaultUserSettings()`
  - `normalizeUserSettings()`
- `Код/db.php`
  - fetch/store user settings
- `Код/sync-state.php`
  - возврат `settings` и `settingsUpdatedAt`
- `Код/index.html`
  - UI настроек и local-first логика
- `Код/fly-beaver.html`
  - применение настроек на `load/pageshow/focus/storage`

### 3. Достижения

- Определения достижений живут в `Код/shared-client.js`.
- Награды и серверная логика unlock живут в `Код/db.php`.
- Unlock popup есть в двух местах:
  - `Код/index.html`
  - `Код/fly-beaver.html`

Если добавляется новое достижение, нужно обновить:

- `Код/shared-client.js`
  - title / description / icon
- `Код/db.php`
  - reward map;
  - snapshot-условия;
  - server unlock flow
- `Код/index.html`
  - профиль и popup очереди unlock
- `Код/fly-beaver.html`
  - очередь unlock во время забега

### 4. Поддержка и тикеты

- Пользовательский центр поддержки находится в `Код/index.html`.
- Админский раздел поддержки находится в `Код/admin/index.php`.
- Схема и backfill таблиц находятся в `Код/db.php`.
- API пользователя: `Код/support-tickets.php`.

Если меняется тикетный flow, нужно проверять оба контура:

- пользователь;
- админ;
- unread counters через `sync-state.php`.

### 5. Топовые награды и уникальные скины

- У кликера и `fly-beaver` есть эксклюзивные top-1 reward skins.
- В каждый момент должен существовать только один выданный экземпляр такого скина.
- Логика reconcile находится в `Код/db.php`.

Если меняется top-skin логика, нужно проверять:

- `Код/db.php`
- `Код/skin-catalog.json`
- `Код/index.html`
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

- `Код/skin-catalog.json`
- `Код/db.php`
- `Код/index.html`
- `Код/admin/index.php`

### Если меняется экономика `fly-beaver`

Проверять сразу:

- `Код/fly-save-run.php`
- `Код/fly-claim-reward.php`
- `Код/fly-beaver.html`
- `Код/index.html`
- `Код/db.php`

### Если меняется античит

Проверять отдельно:

- desktop mouse path;
- keyboard path;
- mobile touch/multitouch path;
- бан-экран;
- апелляцию через встроенную поддержку.

Главный клиентский античит сейчас находится в `Код/index.html`.

## Проверки перед коммитом

Минимум:

- `git diff --check`
- `git status --short`

Если `php` установлен:

- `php -l Код/db.php`
- `php -l Код/sync-state.php`
- и `php -l` для каждого измененного server endpoint

Ручная проверка после значимых правок:

- вход / регистрация;
- магазин и закрытие магазина;
- сохранение и применение настроек;
- поддержка и автообновление переписки;
- achievements popup на главной и в `fly-beaver`;
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
