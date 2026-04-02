# FUTURE: статус и ближайшие пакеты

## Hero

Это публичный технический roadmap проекта. Он ориентирован на ближайшие релизные пакеты и отражает текущее состояние репозитория, а не старый wishlist или идеи "на когда-нибудь".

Ориентир этой версии: shipped-состояние проекта и следующие связные пакеты после коммитов до `e2887ed`.

## Что уже сделано

### Стабильность и sync

- обработан `db quota wait` и добавлен throttling синхронизации
- добавлены продовые smoke-checks и расширен health-check
- улучшен статус sync и сглажены фоновые переходы страницы
- укреплена prod route / challenge compatibility

### Профиль, квесты, поддержка

- rotating daily/weekly quests
- admin-managed quest catalog
- отдельная модалка квестов
- profile activity feed
- titles / frames / trophies
- скрытый архив тикетов поддержки
- fullscreen unread support alerts
- переключатель приватности публичного профиля

### UX и gameplay polish

- keyboard shortcuts
- shortcut до достижений
- purchase forecast / payback в магазине и апгрейдах
- baseline автооптимизация для мобильных и слабых устройств
- снижение false-positive по keyboard/mobile в античите

## Текущие технические ограничения

- бесплатный хостинг с лимитом БД `max_queries_per_hour` остается реальным production-constraint
- anti-bot challenge и совместимость через `i=1` все еще остаются runtime/deploy debt
- deploy path еще не полностью надежен, потому что FTP нестабилен и должен быть только fallback
- часть maintenance-работы все еще происходит в runtime-path и должна быть вынесена из hot request flow

## Следующие пакеты

### Пакет 1 — Убрать давление на базу и стабилизировать прод

- вынести тяжелые schema/maintenance-проверки из обычных request-path
- снизить hot-path SQL-нагрузку в snapshot/support/profile flow
- добавить automated post-deploy verify на базе smoke-скрипта
- добавить видимый operational status для DB quota и degraded mode

### Пакет 2 — Late-game для основного кликера

- добавить мягкий prestige/reset-lite loop
- добавить короткие boosts / streaks / mini-events без слома баланса
- расширить видимость экономики в UI там, где ее сейчас не хватает

### Пакет 3 — Fly-Beaver как отдельный контур прогресса

- добавить daily challenge и больше run variety
- добавить run history или last-run breakdown
- улучшить onboarding и читаемость на маленьких экранах

## Дальше, но не сейчас

- achievements/secrets chains и сезонные архивы
- более богатый публичный профиль и shareable player page
- инструменты модерации достижений и top-skins в админке
- централизованный monitoring, backups и deploy rollback path
