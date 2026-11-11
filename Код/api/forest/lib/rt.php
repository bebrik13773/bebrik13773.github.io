<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: подписанные токены и квитанции для связки PHP ↔ realtime-сервер (РТ-02).
 *
 * PHP и realtime (РТ) никогда не звонят друг другу: InfinityFree пускает к PHP только браузеры. Данные возит браузер игрока:
 *   - PHP выдаёт токен входа (typ «tok», подписывает PHP, проверяет РТ) в ответах state и sync (поле rt);
 *   - РТ выдаёт квитанции (typ «rcp», подписывает РТ, проверяет PHP), браузер кладёт их в sync (поле receipts).
 * Формат: base64url(JSON).base64url(подпись), подпись берётся от первой части как от строки.
 * Алгоритм: ed25519 (нужен sodium; у каждой стороны только свой секретный и чужой открытый ключ) или hmac (SHA-256, два ключа).
 * Алгоритм берётся из настроек сервера, а не из токена, ключ выбирается по kid (поэтому возможна ротация ключей).
 * Чистые функции (подпись, проверка, разбор ключей) не трогают БД; идемпотентность квитанций по rid хранит forest_rt_receipts.
 * Зеркало на Node: realtime/lib/tokens.js и realtime/lib/proto.js; золотые векторы: games/wild-forest/tests/vectors/rt.json.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/formulas.php';

const WF_RT_PROTO = 1;

/** base64url без заполнения. */
function wf_rt_b64u_enc(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** base64url → бинарная строка или null (строго: только допустимые символы, без заполнения). */
function wf_rt_b64u_dec(string $text): ?string
{
    if ($text === '' || preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1 || strlen($text) % 4 === 1) {
        return null;
    }
    $bin = base64_decode(strtr($text, '-_', '+/') . str_repeat('=', (4 - strlen($text) % 4) % 4), true);

    return $bin === false ? null : $bin;
}

/** Идентификатор ключа: короткая строка из латиницы, цифр, точки, дефиса и подчёркивания. */
function wf_rt_kid_valid($kid): bool
{
    return is_string($kid) && preg_match('/^[A-Za-z0-9_.-]{1,16}$/', $kid) === 1;
}

/** «kid:ключ,kid2:ключ2» (ключи в base64url) → [kid => бинарный ключ]. Мусор отбрасывается. */
function wf_rt_keyring_parse(string $text): array
{
    $ring = [];
    foreach (explode(',', $text) as $item) {
        $pos = strpos($item, ':');
        if ($pos === false) {
            continue;
        }
        $kid = trim(substr($item, 0, $pos));
        $key = wf_rt_b64u_dec(trim(substr($item, $pos + 1)));
        if (wf_rt_kid_valid($kid) && $key !== null) {
            $ring[$kid] = $key;
        }
    }

    return $ring;
}

/** Алгоритм поддерживается этим сервером: ed25519 требует sodium, hmac работает всегда. */
function wf_rt_alg_supported(string $alg): bool
{
    if ($alg === 'ed25519') {
        return function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_verify_detached');
    }

    return $alg === 'hmac';
}

/** Подпись строки. Ключ ed25519: 64 байта (формат sodium) или 32 байта (зерно). Ключ hmac: не короче 32 байт. null при ошибке. */
function wf_rt_sign_raw(string $alg, string $key, string $msg): ?string
{
    if (!wf_rt_alg_supported($alg)) {
        return null;
    }
    if ($alg === 'ed25519') {
        try {
            if (strlen($key) === 32) {
                $key = sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($key));
            }
            if (strlen($key) !== 64) {
                return null;
            }

            return sodium_crypto_sign_detached($msg, $key);
        } catch (Throwable $e) {
            return null;
        }
    }
    if (strlen($key) < 32) {
        return null;
    }

    return hash_hmac('sha256', $msg, $key, true);
}

/** Проверка подписи. Ключ ed25519: открытый, 32 байта. Ключ hmac: не короче 32 байт. */
function wf_rt_verify_raw(string $alg, string $key, string $msg, string $sig): bool
{
    if (!wf_rt_alg_supported($alg)) {
        return false;
    }
    if ($alg === 'ed25519') {
        if (strlen($key) !== 32 || strlen($sig) !== 64) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $msg, $key);
        } catch (Throwable $e) {
            return false;
        }
    }
    if (strlen($key) < 32 || strlen($sig) !== 32) {
        return false;
    }

    return hash_equals(hash_hmac('sha256', $msg, $key, true), $sig);
}

/**
 * Собрать и подписать токен. Основные поля (v, kid, typ, pid, iat, exp) нельзя перекрыть дополнительными ($extra).
 * Возвращает null, если подпись или кодирование не удались (например, нет sodium или в тексте битый UTF-8).
 */
function wf_rt_make_token(string $alg, string $kid, string $key, string $typ, int $pid, array $extra, int $now, int $ttl): ?string
{
    $payload = [
        'v' => WF_RT_PROTO, 'kid' => $kid, 'typ' => $typ, 'pid' => $pid, 'iat' => $now, 'exp' => $now + $ttl,
    ] + $extra;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return null;
    }
    $part = wf_rt_b64u_enc($json);
    $sig = wf_rt_sign_raw($alg, $key, $part);

    return $sig === null ? null : $part . '.' . wf_rt_b64u_enc($sig);
}

/**
 * Проверка токена. $keys: [kid => ключ]. Возвращает ['ok' => true, 'payload' => [...]] или ['ok' => false, 'error' => код].
 * Порядок: формат, подпись, версия, тип, поля, окно времени. Коды: bad_token, expired, outdated.
 */
function wf_rt_verify_token(string $token, string $alg, array $keys, int $now, ?string $typ = null): array
{
    $bad = static function (string $error): array {
        return ['ok' => false, 'error' => $error];
    };
    $maxAge = (int) forest_config('rt.max_age_s');
    $skew = (int) forest_config('rt.skew_s');

    $len = strlen($token);
    if ($len === 0 || $len > (int) forest_config('rt.token_max_len')) {
        return $bad('bad_token');
    }
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
        return $bad('bad_token');
    }
    $raw = wf_rt_b64u_dec($parts[0]);
    $sig = wf_rt_b64u_dec($parts[1]);
    if ($raw === null || $sig === null) {
        return $bad('bad_token');
    }
    $p = json_decode($raw, true, 8);
    if (!is_array($p) || (count($p) > 0 && array_keys($p) === range(0, count($p) - 1))) {
        return $bad('bad_token'); // не объект
    }
    $kid = $p['kid'] ?? null;
    if (!wf_rt_kid_valid($kid) || !isset($keys[$kid]) || !is_string($keys[$kid])) {
        return $bad('bad_token');
    }
    if (!wf_rt_verify_raw($alg, $keys[$kid], $parts[0], $sig)) {
        return $bad('bad_token');
    }

    $v = $p['v'] ?? null;
    if ($v !== WF_RT_PROTO) {
        return $bad(is_int($v) && $v > WF_RT_PROTO ? 'outdated' : 'bad_token');
    }
    if (!isset($p['typ']) || !is_string($p['typ']) || ($typ !== null && $p['typ'] !== $typ)) {
        return $bad('bad_token');
    }
    $pid = $p['pid'] ?? null;
    $iat = $p['iat'] ?? null;
    $exp = $p['exp'] ?? null;
    if (!is_int($pid) || $pid < 0 || !is_int($iat) || !is_int($exp)) {
        return $bad('bad_token');
    }
    if ($iat > $now + $skew) {
        return $bad('bad_token'); // из будущего: часы или подделка
    }
    if ($exp - $iat > $maxAge) {
        return $bad('bad_token'); // срок длиннее допустимого
    }
    if ($exp < $now || $now - $iat > $maxAge) {
        return $bad('expired');
    }

    return ['ok' => true, 'payload' => $p];
}

/** Настройки связки из Код/config/rt_config.php (пишет workflow из секретов GitHub) или из переменных окружения BOBER_RT_*. */
function wf_rt_settings(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $raw = [];
    $file = dirname(__DIR__, 3) . '/config/rt_config.php';
    if (is_file($file)) {
        $loaded = @include $file;
        if (is_array($loaded)) {
            $raw = $loaded;
        }
    }
    $pick = static function (string $key, string $env) use ($raw): string {
        $value = $raw[$key] ?? getenv($env);

        return is_string($value) ? trim($value) : '';
    };

    $alg = strtolower($pick('alg', 'BOBER_RT_ALG'));
    $signKid = $pick('sign_kid', 'BOBER_RT_SIGN_KID');
    $signKey = wf_rt_b64u_dec($pick('sign_key', 'BOBER_RT_SIGN_KEY')) ?? '';
    $verify = wf_rt_keyring_parse($pick('verify_keys', 'BOBER_RT_VERIFY_KEYS'));
    $url = $pick('url', 'BOBER_RT_URL');

    $problem = '';
    if ($alg === '' && $signKid === '' && $signKey === '' && $url === '') {
        $problem = 'не настроено';
    } elseif (!in_array($alg, ['ed25519', 'hmac'], true)) {
        $problem = 'неизвестный алгоритм';
    } elseif (!wf_rt_alg_supported($alg)) {
        $problem = 'на хостинге нет sodium: нужен hmac';
    } elseif (!wf_rt_kid_valid($signKid) || $signKey === '') {
        $problem = 'нет ключа подписи';
    } elseif (wf_rt_sign_raw($alg, $signKey, 'проверка') === null) {
        $problem = 'ключ подписи неверной длины';
    } elseif (preg_match('#^wss?://[^\s]+$#i', $url) !== 1) {
        $problem = 'нет адреса realtime-сервера';
    }

    $cfg = [
        'enabled' => $problem === '',
        'problem' => $problem,
        'alg' => $alg,
        'url' => $url,
        'sign_kid' => $signKid,
        'sign_key' => $signKey,
        'verify' => $verify,
    ];

    return $cfg;
}

/** Realtime настроен: ключи, алгоритм и адрес на месте. Если нет, игра работает как раньше, через обычный sync. */
function wf_rt_enabled(): bool
{
    return wf_rt_settings()['enabled'];
}

/** Поля токена входа (PHP → РТ): имя, деревня, уровень, скин, максимальная скорость, мут до, флаги. */
function wf_rt_login_extra(string $name, string $village, int $level, float $vmax, string $skin = 'default', int $muteUntil = 0, int $flags = 0): array
{
    return [
        'nm' => $name, 'vn' => $village, 'lv' => $level, 'sk' => $skin,
        'vmax' => round($vmax, 2), 'mu' => $muteUntil, 'fl' => $flags,
    ];
}

/**
 * Блок rt для ответа state и sync: свежий токен входа или null.
 * $clientExp: срок текущего токена у клиента (null: токена нет, выдать сразу). Новый токен выдаётся, когда осталось меньше token_refresh_s.
 * $capacity и $boots нужны для максимальной скорости (vmax считается без груза и по лучшей дороге).
 */
function wf_rt_client_block(array $row, int $now, ?int $clientExp, float $capacity, bool $boots, ?array $settings = null): ?array
{
    $cfg = $settings ?? wf_rt_settings();
    if (empty($cfg['enabled'])) {
        return null;
    }
    if ($clientExp !== null && $clientExp - $now >= (int) forest_config('rt.token_refresh_s')) {
        return null;
    }
    $bonus = forest_config('hero.road_bonus');
    $vmax = wf_hero_speed(0.0, $capacity, count($bonus) - 1, $boots);
    $level = (int) wf_level_from_xp((int) $row['xp'])['level'];
    $village = (string) $row['village_name'];
    $ttl = (int) forest_config('rt.token_ttl_s');
    $token = wf_rt_make_token(
        $cfg['alg'], $cfg['sign_kid'], $cfg['sign_key'], 'tok', (int) $row['player_id'],
        wf_rt_login_extra($village, $village, $level, $vmax), $now, $ttl
    );
    if ($token === null) {
        return null;
    }

    return ['token' => $token, 'exp' => $now + $ttl, 'url' => $cfg['url'], 'proto' => WF_RT_PROTO];
}

/** Проверка полей квитанции. Возвращает код ошибки или null. $sessionPid: игрок, чей это sync (чужую квитанцию принимать нельзя). */
function wf_rt_receipt_check(array $p, int $sessionPid): ?string
{
    if (($p['pid'] ?? null) !== $sessionPid) {
        return 'bad_token';
    }
    $rid = $p['rid'] ?? null;
    if (!is_string($rid) || preg_match('/^[A-Za-z0-9_-]{8,64}$/', $rid) !== 1) {
        return 'bad_token';
    }
    $kind = $p['k'] ?? null;
    $d = $p['d'] ?? null;
    if (!is_array($d)) {
        return 'bad_token';
    }
    $json = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || strlen($json) > (int) forest_config('rt.receipt_data_bytes')) {
        return 'bad_token';
    }
    if ($kind === 'cp') {
        $limit = (float) forest_config('world.world_radius_m') * 1.01;
        foreach (['x', 'z'] as $axis) {
            $value = $d[$axis] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs((float) $value) > $limit) {
                return 'too_far';
            }
        }

        return null;
    }
    if ($kind === 'trade') {
        return isset($d['tid']) && is_string($d['tid']) && $d['tid'] !== '' ? null : 'bad_token';
    }

    return 'bad_token';
}

/** Запомнить квитанцию. Возвращает 'new' (первый раз) или 'dup' (повтор: игнорируется, идемпотентность по rid). */
function wf_rt_receipt_store($conn, array $p, int $now): string
{
    $rid = (string) $p['rid'];
    $pid = (int) $p['pid'];
    $kind = (string) $p['k'];
    $ts = (int) $p['iat'];
    $data = json_encode($p['d'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $data = is_string($data) ? $data : '{}';
    $stmt = $conn->prepare('INSERT IGNORE INTO `forest_rt_receipts` (`rid`, `pid`, `typ`, `ts`, `data_json`) VALUES (?, ?, ?, ?, ?)');
    $stmt->bind_param('sisis', $rid, $pid, $kind, $ts, $data);
    $stmt->execute();
    $fresh = $stmt->affected_rows === 1;
    $stmt->close();
    if ($fresh && mt_rand(1, max(1, (int) forest_config('rt.cleanup_chance'))) === 1) {
        wf_rt_cleanup($conn, $now);
    }

    return $fresh ? 'new' : 'dup';
}

/** Ленивая чистка: квитанции старше keep_receipts_s (2 суток) удаляются небольшими порциями. */
function wf_rt_cleanup($conn, int $now): void
{
    $before = $now - (int) forest_config('rt.keep_receipts_s');
    $batch = max(1, (int) forest_config('rt.cleanup_batch'));
    $stmt = $conn->prepare('DELETE FROM `forest_rt_receipts` WHERE `ts` < ? LIMIT ' . $batch);
    $stmt->bind_param('i', $before);
    $stmt->execute();
    $stmt->close();
}

/**
 * Принять квитанции из sync. Возвращает список результатов [rid, ok, error?, dup?, k?, d?].
 * Лишние сверх receipts_per_sync отклоняются с кодом rate. Если realtime не настроен, квитанции молча игнорируются (пустой список).
 */
function wf_rt_process_receipts($conn, int $pid, $list, int $now, ?array $settings = null): array
{
    $cfg = $settings ?? wf_rt_settings();
    if (empty($cfg['enabled']) || !is_array($list) || $list === []) {
        return [];
    }
    $max = (int) forest_config('rt.receipts_per_sync');
    $out = [];
    $i = 0;
    foreach ($list as $token) {
        $i++;
        if ($i > $max) {
            $out[] = ['ok' => false, 'error' => 'rate'];
            break;
        }
        if (!is_string($token)) {
            $out[] = ['ok' => false, 'error' => 'bad_token'];
            continue;
        }
        $res = wf_rt_verify_token($token, $cfg['alg'], $cfg['verify'], $now, 'rcp');
        if (!$res['ok']) {
            $out[] = ['ok' => false, 'error' => $res['error']];
            continue;
        }
        $p = $res['payload'];
        $err = wf_rt_receipt_check($p, $pid);
        if ($err !== null) {
            $out[] = ['rid' => is_string($p['rid'] ?? null) ? $p['rid'] : '', 'ok' => false, 'error' => $err];
            continue;
        }
        $state = wf_rt_receipt_store($conn, $p, $now);
        $out[] = ['rid' => $p['rid'], 'ok' => true, 'dup' => $state === 'dup', 'k' => $p['k'], 'd' => $p['d']];
    }

    return $out;
}
