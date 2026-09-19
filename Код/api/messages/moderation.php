<?php

/**
 * Базовая модерация текста личных сообщений между игроками: замена мата
 * и грубых оскорблений на вежливые аналоги. Не блокирует отправку —
 * просто подменяет отдельные слова, чтобы не считывать намерение или
 * контекст (это не полноценный анти-мат движок, а мягкий фильтр).
 *
 * Работает по границам слов (с учётом русских окончаний через \S*),
 * регистронезависимо, сохраняя первую букву регистра результата.
 *
 * Список слов управляется из админки и хранится в таблице
 * `dm_moderation_words` (см. Код/api/messages/db/direct-messages-schema.php —
 * bober_dm_admin_fetch_moderation_words и соседние функции). Здесь список
 * подгружается из БД и кэшируется на время одного запроса.
 */

/**
 * Подгружает активные (enabled=1) слова автомодерации из БД, в порядке
 * sort_order. Если соединение недоступно или таблицы ещё нет — возвращает
 * пустой список (модерация просто ничего не заменит, а не упадёт).
 */
function bober_moderation_word_map($conn = null)
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $map = [];
    if ($conn instanceof mysqli) {
        $result = @$conn->query('SELECT pattern, replacement FROM dm_moderation_words WHERE enabled = 1 ORDER BY sort_order ASC, id ASC');
        while ($result && ($row = $result->fetch_assoc())) {
            $map[(string) $row['pattern']] = (string) $row['replacement'];
        }
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    }

    $cached = $map;
    return $cached;
}

/**
 * Заменяет грубые/матерные слова на вежливые аналоги в тексте сообщения.
 * Возвращает изменённый текст; если замен не было, возвращает исходный.
 * $conn нужен, чтобы подгрузить актуальный список слов из БД при первом
 * вызове в рамках запроса.
 */
function bober_moderate_text($text, $conn = null)
{
    $text = (string) $text;
    if ($text === '') {
        return $text;
    }

    static $compiled = null;
    if ($compiled === null) {
        $compiled = [];
        foreach (bober_moderation_word_map($conn) as $pattern => $replacement) {
            $compiled[] = [
                'regex' => '/(?<![a-zа-яё0-9])(' . $pattern . ')(?![a-zа-яё0-9])/iu',
                'replacement' => $replacement,
            ];
        }
    }

    foreach ($compiled as $entry) {
        $text = preg_replace_callback($entry['regex'], function ($matches) use ($entry) {
            $original = $matches[1];
            $replacement = $entry['replacement'];

            // Сохраняем регистр первой буквы, чтобы "Сука" -> "Вот незадача", а не "вот незадача".
            if ($original !== '' && mb_strtoupper(mb_substr($original, 0, 1)) === mb_substr($original, 0, 1)) {
                $replacement = mb_strtoupper(mb_substr($replacement, 0, 1)) . mb_substr($replacement, 1);
            }

            return $replacement;
        }, $text);
    }

    return $text;
}
