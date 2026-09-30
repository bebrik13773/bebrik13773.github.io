# Android-приложение «Бобёр кликер»

Capacitor-обёртка (чистый WebView) вокруг `https://bober-api.gt.tc/pages/clicker/index.html`.
Без AppMint и вотермарки. Package: `com.bober.tap`.

## Где что лежит
- `android-app/` — весь Capacitor-проект (вне `Код/`, на FTP-деплой сайта не влияет)
  - `capacitor.config.json` — appId, `server.url`, `server.errorPath` (офлайн-экран), SplashScreen, LocalNotifications
  - `www/index.html` — заглушка (реальная игра грузится с сервера)
  - `www/offline.html` + `www/offline.jpg` — экран «Ой, связь потеряна!» при сетевой ошибке (автоповтор каждые 4 с + кнопка «Повторить»)
  - `android/app/src/main/java/com/bober/tap/MainActivity.java` — отключён кэш WebView (`LOAD_NO_CACHE`), выход двойным нажатием «назад»
  - `android/app/build.gradle` — `versionCode` / `versionName` (менять здесь при новом релизе)
  - `android/variables.gradle` — minSdk 24, compile/target SDK 35
  - `android/app/src/main/res/mipmap-*` — иконка (из `favicon.ico`), `res/drawable*/splash.png` — заставка
- `.github/workflows/android.yml` — сборка, подпись, публикация релиза

## Как собирается
Пуш в `main`, затронувший `android-app/**` или `android.yml`, либо ручной запуск (Actions → Build Android APK):
npm ci → cap sync → `./gradlew assembleRelease` → подпись apksigner → релиз.
Тег релиза: `apk-v<versionName>`. Старый релиз с тем же тегом удаляется и создаётся заново.

## Секреты репозитория (только имена)
`ANDROID_KEYSTORE_B64`, `KEYSTORE_PASSWORD`, `KEY_ALIAS` (= `bober`). Ключ подписи должен оставаться тем же,
иначе Android не обновит приложение поверх и Play Защита снова предупредит о неизвестном разработчике.

## Постоянная ссылка на актуальный APK
https://github.com/bebrik13773/bebrik13773.github.io/releases/latest/download/bober-clicker.apk

## Что намеренно не подключено
Firebase/push, PIN-блокировка, реклама, виджет. Из уведомлений только локальные (`@capacitor/local-notifications`).
