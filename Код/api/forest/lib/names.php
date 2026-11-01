<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: генератор имён из двух слов (ДЛ-15). Зеркало games/wild-forest/src/shared/names.js.
 * Словари читаются из общего names.json; выбор слов тем же хешем, что у мира (wf_wg_hash), поэтому JS и PHP дают одно имя.
 */
require_once __DIR__ . '/worldgen.php';

const WF_NAME_TRIES = 16;
const WF_NAME_MAX_LEN = 24;

/** Данные names.json (кэшируются на время запроса). */
function wf_names_data(): array
{
    static $data = null;
    if ($data === null) {
        $path = dirname(__DIR__, 3) . '/games/wild-forest/src/shared/names.json';
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || !isset($data['kinds'])) {
            throw new RuntimeException('Не удалось прочитать names.json');
        }
    }

    return $data;
}

function wf_names_salt(string $kind): int
{
    $salts = ['village' => 11, 'character' => 22, 'mentor' => 33];
    if (!isset($salts[$kind])) {
        throw new InvalidArgumentException('Неизвестный вид имени: ' . $kind);
    }

    return $salts[$kind];
}

/** Подходит ли имя: длина, чёрный список подстрок и пар. */
function wf_names_allowed(array $data, string $text): bool
{
    if (mb_strlen($text) > WF_NAME_MAX_LEN || in_array($text, (array) ($data['bad_pairs'] ?? []), true)) {
        return false;
    }
    $low = mb_strtolower($text);
    foreach ((array) ($data['blacklist'] ?? []) as $bad) {
        if ($bad !== '' && mb_strpos($low, mb_strtolower((string) $bad)) !== false) {
            return false;
        }
    }

    return true;
}

/** Имя по виду ('village' | 'character' | 'mentor'), seed (целое >= 0) и номеру попытки. */
function wf_names_make(string $kind, int $seed, int $attempt = 0): string
{
    $data = wf_names_data();
    $dict = $data['kinds'][$kind] ?? null;
    if ($dict === null) {
        throw new InvalidArgumentException('Нет словаря ' . $kind);
    }
    $salt = wf_names_salt($kind);
    $gender = ['m' => 0, 'f' => 1, 'n' => 2];
    $text = '';
    for ($t = 0; $t < WF_NAME_TRIES; $t++) {
        $a = $attempt * WF_NAME_TRIES + $t;
        $adj = $dict['adj'][wf_wg_hash($seed, $a, 1, $salt) % count($dict['adj'])];
        $noun = $dict['noun'][wf_wg_hash($seed, $a, 2, $salt) % count($dict['noun'])];
        $text = $adj[$gender[$noun[1]]] . ' ' . $noun[0];
        if (wf_names_allowed($data, $text)) {
            return $text;
        }
    }

    return $text;
}

/** Первое имя, которого нет среди занятых ($taken: набор вида [имя => true]). Проверку занятости делает вызывающий через $isTaken. */
function wf_names_unique(string $kind, int $seed, callable $isTaken, int $maxAttempts = 50): string
{
    $last = '';
    for ($i = 0; $i < $maxAttempts; $i++) {
        $last = wf_names_make($kind, $seed, $i);
        if (!$isTaken($last)) {
            return $last;
        }
    }

    return $last . ' ' . ($maxAttempts + 1);
}
