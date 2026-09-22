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
 */

function bober_news_log($message)
{
    fwrite(STDERR, '[generate-news] ' . $message . "\n");
}

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
        if (count($parts) === 2) {
            $commits[] = ['hash' => $parts[0], 'subject' => $parts[1]];
        }
    }

    return $commits;
}

function bober_news_call_ai(array $commits)
{
    $apiKey = (string) (getenv('BOBER_AI_API_KEY') ?: '');
    $baseUrl = rtrim((string) (getenv('BOBER_AI_BASE_URL') ?: 'https://routerai.ru/api/v1'), '/');
    // Точная модель по умолчанию совпадает с моделью в GitHub Actions.
    $model = (string) (getenv('BOBER_AI_MODEL') ?: 'deepseek/deepseek-v4-flash-0731');

    if ($apiKey === '' || $baseUrl === '' || $model === '') {
        throw new RuntimeException('AI не настроен: нет ключа/base_url/модели.');
    }

    $commitLines = array_map(function ($commit) {
        return '- ' . $commit['subject'] . ' (' . $commit['hash'] . ')';
    }, $commits);

    $systemPrompt = <<<PROMPT
Ты — редактор новостей для игры "Бобёр Кликер 2". Напиши одну дружелюбную новость
для игроков на основе списка коммитов. Не используй технический жаргон.
Если интересной новости нет, верни skip=true.

Верни только валидный JSON:
{
  "skip": false,
  "title": "Короткий заголовок до 60 символов",
  "body_game": "Текст в обычном Markdown",
  "body_telegram": "Текст в Telegram MarkdownV2"
}
PROMPT;

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => "Коммиты за последние сутки:\n" . implode("\n", $commitLines)],
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
        $message = is_array($decoded['error'] ?? null)
            ? (string) ($decoded['error']['message'] ?? 'Неизвестная ошибка AI-сервиса.')
            : 'Ошибка AI-сервиса (код ' . $httpCode . ').';
        throw new RuntimeException($message);
    }

    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        throw new RuntimeException('AI-сервис вернул пустой ответ.');
    }

    $cleaned = trim($content);
    $cleaned = preg_replace('/^```(?:json)?\s*/i', '', $cleaned);
    $cleaned = preg_replace('/\s*```$/', '', $cleaned);
    $news = json_decode($cleaned, true);
    if (!is_array($news)) {
        throw new RuntimeException('Не удалось распарсить JSON-ответ AI: ' . $cleaned);
    }

    return $news;
}

function bober_news_publish_to_game($baseUrl, $adminPassword, $title, $bodyMarkdown)
{
    $ch = curl_init(rtrim($baseUrl, '/') . '/api/announcements/update.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'adminPassword' => $adminPassword,
            'title' => $title,
            'body' => $bodyMarkdown,
            'bodyFormat' => 'markdown',
            'isPublished' => true,
        ], JSON_UNESCAPED_UNICODE),
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

function bober_news_main()
{
    $repoPath = getenv('NEWS_REPO_PATH') ?: getcwd();
    $lookbackHours = (int) (getenv('NEWS_LOOKBACK_HOURS') ?: 24);
    $siteBaseUrl = (string) (getenv('NEWS_SITE_BASE_URL') ?: '');
    $adminPassword = (string) (getenv('NEWS_ADMIN_PASSWORD') ?: '');

    if ($siteBaseUrl === '' || $adminPassword === '') {
        throw new RuntimeException('Не заданы NEWS_SITE_BASE_URL / NEWS_ADMIN_PASSWORD.');
    }

    $commits = bober_news_collect_commits($repoPath, $lookbackHours);
    if (count($commits) < 1) {
        bober_news_log('Коммитов за период нет — новость не нужна, завершаюсь.');
        return;
    }

    $news = bober_news_call_ai($commits);
    if (!empty($news['skip'])) {
        bober_news_log('AI решил, что писать не о чем — пропускаю публикацию.');
        return;
    }

    $title = trim((string) ($news['title'] ?? ''));
    $bodyGame = trim((string) ($news['body_game'] ?? ''));
    if ($title === '' || $bodyGame === '') {
        throw new RuntimeException('AI вернул неполный ответ (нет title/body_game).');
    }

    $newsId = bober_news_publish_to_game($siteBaseUrl, $adminPassword, $title, $bodyGame);
    bober_news_log("Новость опубликована в игре, id={$newsId}.");
}

try {
    bober_news_main();
} catch (Throwable $error) {
    bober_news_log('ОШИБКА: ' . $error->getMessage());
    exit(1);
}
