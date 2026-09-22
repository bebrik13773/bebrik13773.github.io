<?php

/**
 * Автопост новостей "Бобёр Кликер 2".
 *
 * Берёт коммиты за последние N часов (через `git log`), просит RouterAI/DeepSeek
 * сгенерировать дружелюбную новость на их основе (два варианта текста — для
 * внутриигровой ленты в Markdown и для Telegram-канала в MarkdownV2), затем:
 *   1) публикует новость в игре ЧЕРЕЗ HTTP-запрос к существующему эндпоинту
 *      `api/announcements/update.php` (с админ-паролем) — а не напрямую в БД,
 *      потому что бесплатные хостинги (в т.ч. текущий) обычно НЕ разрешают
 *      подключение к MySQL снаружи (только localhost на самом хостинге), а
 *      GitHub Actions runner — внешний по отношению к хостингу;
 *   2) постит текст в Telegram-канал через уже настроенного бота.
 *
 * Запускается из GitHub Actions (cron + workflow_dispatch), не на хостинге —
 * никаких background-процессов на самом игровом сервере не появляется.
 *
 * Если за период не было ни одного коммита — скрипт тихо завершается без поста
 * (чтобы не спамить пустыми новостями).
 *
 * Требуемые переменные окружения:
 *   NEWS_SITE_BASE_URL — базовый URL сайта, напр. https://bober-api.gt.tc
 *   NEWS_ADMIN_PASSWORD — админ-пароль (plaintext) для api/announcements/update.php
 *   BOBER_AI_API_KEY, (опц.) BOBER_AI_BASE_URL, BOBER_AI_MODEL — RouterAI
 *   BOBER_TG_BOT_TOKEN, BOBER_TG_CHAT_ID — Telegram-бот и канал
 *   NEWS_LOOKBACK_HOURS — опционально, по умолчанию 24
 *   NEWS_REPO_PATH — опционально, путь к репозиторию для `git log` (по умолчанию — CWD)
 */

function bober_news_log($message)
{
    fwrite(STDERR, '[generate-news] ' . $message . "\n");
}

/**
 * Собирает лог коммитов за последние $hours часов в компактном виде
 * "хэш | тема коммита" (без тела — чтобы не раздувать промпт и не тащить
 * служебные детали). Мержи и коммиты автодеплоя (author=Claude с пустой темой)
 * не фильтруем отдельно — модель сама разберётся, что достойно новости.
 */
function bober_news_collect_commits($repoPath, $hours)
{
    $since = escapeshellarg('-' . (int) $hours . ' hours');
    $cmd = sprintf(
        'git -C %s log --since=%s --pretty=format:%s --no-merges',
        escapeshellarg($repoPath),
        $since,
        escapeshellarg('%h|%s')
    );

    $output = [];
    $exitCode = 0;
    exec($cmd . ' 2>&1', $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('git log завершился с ошибкой: ' . implode("\n", $output));
    }

    $commits = [];
    foreach ($output as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        $commits[] = ['hash' => $parts[0], 'subject' => $parts[1]];
    }

    return $commits;
}

/**
 * Вызывает RouterAI (тот же паттерн, что и ai-assistant.php), но без tools —
 * просим строго JSON-объект с готовыми текстами.
 */
function bober_news_call_ai(array $commits)
{
    $apiKey = (string) (getenv('BOBER_AI_API_KEY') ?: '');
    $baseUrl = rtrim((string) (getenv('BOBER_AI_BASE_URL') ?: 'https://routerai.ru/api/v1'), '/');
    $model = (string) (getenv('BOBER_AI_MODEL') ?: '~deepseek/deepseek-v4-flash-latest');

    if ($apiKey === '' || $baseUrl === '' || $model === '') {
        throw new RuntimeException('AI не настроен: нет ключа/base_url/модели.');
    }

    $commitLines = array_map(function ($commit) {
        return '- ' . $commit['subject'] . ' (' . $commit['hash'] . ')';
    }, $commits);

    $systemPrompt = <<<PROMPT
Ты — редактор новостей для игры "Бобёр Кликер 2" (браузерный кликер, маленькое
и дружелюбное коммьюнити игроков, которые лично знакомы с разработчиком).

Тебе дают список технических коммитов за день (сообщения коммитов на русском,
с префиксами типа fix:/feat:/ui:/perf:/tune: — это внутренний рабочий жаргон
разработчика, НЕ для игроков). Твоя задача — написать ОДНУ дружелюбную новость
для игроков по мотивам этих изменений: что нового, что улучшено, что починено.
Разработчика зовут Бобёр — можно писать от лица команды игры, тепло и просто,
без канцелярита и без технического жаргона (никаких "рефакторинг", "API",
"эндпоинт", "баг в коде" — говори по-человечески: "почистили", "починили",
"стало быстрее"). Пропускай чисто технические/инфраструктурные коммиты
(например, чистку репозитория, правки CI), если по ним нечего сказать игроку.
Если из всех коммитов реально не набирается ничего интересного для игрока —
верни пустую строку в поле "skip" = true.

Нужно вернуть СТРОГО JSON без markdown-обёртки (без \`\`\`), со следующими полями:
{
  "skip": false,
  "title": "Короткий заголовок новости (до 60 символов)",
  "body_game": "Текст новости в Markdown для внутриигровой ленты...",
  "body_telegram": "Тот же текст новости, адаптированный под Telegram MarkdownV2..."
}

Правила для "body_game" (обычный Markdown, рендерится в игре):
- Разрешено: **жирный**, *курсив*, `код`, ~~зачёркнутый~~, заголовки через
  #/##/###, списки через "- " или "1. ", цитаты через "> ", ссылки [текст](url),
  разделитель "---".
- Пиши живо, можно 1-2 уместных эмодзи, но не перебарщивай.
- Длина: 2-5 коротких абзацев или список изменений.

Правила для "body_telegram" (СТРОГО Telegram MarkdownV2 — это другой синтаксис!):
- Жирный: *текст* (одна звёздочка, НЕ две!)
- Курсив: _текст_
- Зачёркнутый: ~текст~
- Код: `текст`
- Ссылки: [текст](url)
- КРИТИЧЕСКИ ВАЖНО: в MarkdownV2 обязательно экранируются символом \ вне
  code/pre блоков следующие спецсимволы, если они встречаются как обычная
  пунктуация (не как часть разметки):
  _ * [ ] ( ) ~ ` > # + - = | { } . !
  Например, обычная точка в конце предложения должна быть "\\." а не ".".
  Восклицательный знак — "\\!" а не "!". Дефис в середине текста — "\\-".
  Если сомневаешься, экранируй — недоэкранированный текст Telegram отклонит
  целиком с ошибкой parse entities.
- В Telegram нет поддержки заголовков (#) и настоящих маркированных списков —
  используй эмодзи-буллиты (например "• текст" или "🔸 текст") вместо "- текст",
  и *жирный* вместо # для акцентов.
- Короче, чем body_game — телеграм любит компактность: 1-3 абзаца.

Отвечай ТОЛЬКО валидным JSON-объектом, ничего до и после него.
PROMPT;

    $userPrompt = "Коммиты за последние сутки:\n" . implode("\n", $commitLines);

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
        'max_tokens' => 1500,
        'temperature' => 0.7,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Не удалось связаться с AI-сервисом: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('AI-сервис вернул некорректный JSON-конверт.');
    }

    if ($httpCode >= 400) {
        $errorMessage = is_array($decoded['error'] ?? null)
            ? (string) ($decoded['error']['message'] ?? 'Неизвестная ошибка AI-сервиса.')
            : 'Ошибка AI-сервиса (код ' . $httpCode . ').';
        throw new RuntimeException($errorMessage);
    }

    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('AI-сервис вернул пустой ответ.');
    }

    // На случай если модель всё же обернула ответ в ```json ... ``` — снимаем обёртку.
    $cleaned = trim($content);
    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
    $cleaned = preg_replace('/\s*```$/', '', $cleaned);

    $news = json_decode($cleaned, true);
    if (!is_array($news)) {
        throw new RuntimeException('Не удалось распарсить JSON-ответ AI: ' . $cleaned);
    }

    return $news;
}

/**
 * Публикует новость через HTTP, используя существующий эндпоинт
 * api/announcements/update.php (тот же, что дёргает ручная админка) —
 * без прямого подключения к MySQL, которое с внешнего runner'а скорее
 * всего недоступно на этом хостинге.
 */
function bober_news_publish_to_game($baseUrl, $adminPassword, $title, $bodyMarkdown)
{
    $url = rtrim($baseUrl, '/') . '/api/announcements/update.php';
    $payload = json_encode([
        'adminPassword' => $adminPassword,
        'title' => $title,
        'body' => $bodyMarkdown,
        'bodyFormat' => 'markdown',
        'isPublished' => true,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new RuntimeException('Не удалось связаться с сайтом игры: ' . $curlError);
    }

    $decoded = json_decode($responseBody, true);
    if ($httpCode >= 400 || !is_array($decoded) || empty($decoded['success'])) {
        $message = is_array($decoded) ? (string) ($decoded['message'] ?? '') : $responseBody;
        throw new RuntimeException('Сайт игры отклонил публикацию новости (код ' . $httpCode . '): ' . $message);
    }

    return (int) ($decoded['announcement']['id'] ?? 0);
}

/**
 * Постит текст в Telegram-канал с parse_mode=MarkdownV2.
 * Возвращает true/false — не бросает исключение, чтобы сбой Telegram не
 * отменял уже созданную внутриигровую новость.
 */
function bober_news_post_to_telegram($botToken, $chatId, $text)
{
    if ($botToken === '' || $chatId === '') {
        bober_news_log('Telegram не настроен (нет токена/chat_id) — пропускаю отправку.');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $botToken . '/sendMessage';
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'MarkdownV2',
        'disable_web_page_preview' => true,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);

    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        bober_news_log('Ошибка сети при отправке в Telegram: ' . $curlError);
        return false;
    }

    if ($httpCode >= 400) {
        bober_news_log('Telegram API вернул ошибку (код ' . $httpCode . '): ' . $responseBody);
        return false;
    }

    return true;
}

function bober_news_main()
{
    $repoPath = getenv('NEWS_REPO_PATH') ?: getcwd();
    $lookbackHours = (int) (getenv('NEWS_LOOKBACK_HOURS') ?: 24);
    $siteBaseUrl = (string) (getenv('NEWS_SITE_BASE_URL') ?: '');
    $adminPassword = (string) (getenv('NEWS_ADMIN_PASSWORD') ?: '');
    $botToken = (string) (getenv('BOBER_TG_BOT_TOKEN') ?: '');
    $chatId = (string) (getenv('BOBER_TG_CHAT_ID') ?: '');

    if ($siteBaseUrl === '' || $adminPassword === '') {
        throw new RuntimeException('Не заданы NEWS_SITE_BASE_URL / NEWS_ADMIN_PASSWORD.');
    }

    bober_news_log("Собираю коммиты за последние {$lookbackHours}ч из {$repoPath}...");
    $commits = bober_news_collect_commits($repoPath, $lookbackHours);

    if (count($commits) < 1) {
        bober_news_log('Коммитов за период нет — новость не нужна, завершаюсь.');
        return;
    }

    bober_news_log('Найдено коммитов: ' . count($commits));
    foreach ($commits as $commit) {
        bober_news_log('  ' . $commit['hash'] . ' ' . $commit['subject']);
    }

    bober_news_log('Прошу AI сгенерировать новость...');
    $news = bober_news_call_ai($commits);

    if (!empty($news['skip'])) {
        bober_news_log('AI решил, что писать не о чем — пропускаю публикацию.');
        return;
    }

    $title = trim((string) ($news['title'] ?? ''));
    $bodyGame = trim((string) ($news['body_game'] ?? ''));
    $bodyTelegram = trim((string) ($news['body_telegram'] ?? ''));

    if ($title === '' || $bodyGame === '') {
        throw new RuntimeException('AI вернул неполный ответ (нет title/body_game).');
    }

    bober_news_log('Заголовок: ' . $title);

    $newsId = bober_news_publish_to_game($siteBaseUrl, $adminPassword, $title, $bodyGame);
    bober_news_log("Новость опубликована в игре, id={$newsId}.");

    if ($bodyTelegram !== '') {
        $telegramText = "*" . bober_news_escape_markdown_v2($title) . "*\n\n" . $bodyTelegram;
        $posted = bober_news_post_to_telegram($botToken, $chatId, $telegramText);
        bober_news_log($posted ? 'Отправлено в Telegram.' : 'В Telegram НЕ отправлено (см. лог выше) — новость в игре при этом уже опубликована.');
    } else {
        bober_news_log('AI не дал текст для Telegram — пропускаю отправку туда.');
    }

    bober_news_log('Готово.');
}

/**
 * Экранирует спецсимволы MarkdownV2 для кусков текста, которые сама модель
 * не размечала (например, заголовок, который мы оборачиваем в *...* сами).
 */
function bober_news_escape_markdown_v2($text)
{
    $specialChars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
    $escaped = str_replace($specialChars, array_map(function ($char) {
        return '\\' . $char;
    }, $specialChars), $text);
    return $escaped;
}

try {
    bober_news_main();
} catch (Throwable $error) {
    bober_news_log('ОШИБКА: ' . $error->getMessage());
    exit(1);
}
