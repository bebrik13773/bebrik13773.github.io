<?php
/**
 * Дикий Лес: общие помощники API (ДЛ-03).
 * Единый формат ответа: успех {"ok":true,"data":{...}}, ошибка {"ok":false,"error":"<код>","message":"<текст>"}.
 */

require_once dirname(__DIR__, 2) . '/bootstrap/forest_db.php';

/** Коды ошибок и HTTP-статусы по умолчанию (см. docs/WILD_FOREST.md, раздел 2). */
function forest_error_catalog()
{
    return [
        'too_fast'     => [429, 'Слишком быстро. Притормози, бобёр.'],
        'too_far'      => [400, 'Слишком далеко.'],
        'no_energy'    => [400, 'Не хватает энергии.'],
        'no_tree'      => [404, 'Здесь нет такого дерева.'],
        'tree_gone'    => [409, 'Это дерево уже срублено.'],
        'bad_state'    => [409, 'Сейчас так сделать нельзя.'],
        'no_items'     => [409, 'Нужных вещей нет.'],
        'no_space'     => [409, 'Не хватает места.'],
        'rate_limited' => [429, 'Слишком частые запросы. Подожди немного.'],
        'forbidden'    => [403, 'Нет доступа.'],
        'outdated'     => [409, 'Игра обновилась, перезагрузи страницу.'],
        'unauthorized' => [401, 'Сессия не найдена. Войди в аккаунт заново.'],
        'bad_request'  => [400, 'Некорректный запрос.'],
        'db_quota'     => [503, 'Сервер перегружен, попробуй чуть позже.'],
        'server_error' => [500, 'Ошибка сервера.'],
    ];
}

function forest_json_out($data = [], $statusCode = 200)
{
    bober_json_response(['ok' => true, 'data' => $data], $statusCode);
}

function forest_error($code, $message = null, $extra = [], $statusCode = null)
{
    $catalog = forest_error_catalog();
    if (!isset($catalog[$code])) {
        $code = 'server_error';
    }
    $payload = [
        'ok' => false,
        'error' => $code,
        'message' => ($message !== null && $message !== '') ? $message : $catalog[$code][1],
    ];
    if (is_array($extra) && $extra) {
        $payload['extra'] = $extra;
    }
    bober_json_response($payload, $statusCode !== null ? (int) $statusCode : $catalog[$code][0]);
}

/** Превращает исключение в ответ с подходящим кодом (лимит БД хостинга отличаем от прочего). */
function forest_fail($error)
{
    if (bober_is_database_quota_exceeded_error($error)) {
        forest_error('db_quota', bober_database_quota_exceeded_message());
    }
    forest_error('server_error', bober_exception_message($error, 'Ошибка сервера.'));
}

function forest_require_method($method)
{
    $actual = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($actual !== strtoupper($method)) {
        header('Allow: ' . strtoupper($method));
        forest_error('bad_request', 'Метод не поддерживается.', [], 405);
    }
}

/** Читает JSON-тело с ограничением размера (config limits.max_body_bytes). Только для POST. */
function forest_json_in()
{
    $max = (int) forest_config('limits.max_body_bytes');
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($length > $max) {
        forest_error('bad_request', 'Слишком большой запрос.', [], 413);
    }

    bober_enforce_same_origin();

    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || $raw === '') {
        forest_error('bad_request', 'Пустой запрос.');
    }
    if (strlen($raw) > $max) {
        forest_error('bad_request', 'Слишком большой запрос.', [], 413);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        forest_error('bad_request', 'Некорректный JSON.');
    }

    return $data;
}

/**
 * Игрок из сессии кликера. Возвращает id пользователя или завершает запрос ошибкой.
 * $withAccessRules = false для «горячих» эндпоинтов (меньше запросов к БД, см. ДЛ-38):
 * проверка банов и отозванных сессий делается тогда реже.
 */
function forest_require_player($conn = null, $withAccessRules = true)
{
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        forest_error('unauthorized');
    }

    if ($withAccessRules) {
        if (!($conn instanceof mysqli)) {
            $conn = forest_db();
        }
        bober_enforce_runtime_access_rules($conn, $userId);
    }

    return (int) $userId;
}

/** Роль игрока в админке леса ('creative', 'moderator') или null. Один запрос по первичному ключу. */
function forest_admin_role($conn, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT `role` FROM `forest_admins` WHERE `player_id` = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($role);
    $found = $stmt->fetch();
    $stmt->close();

    return $found ? (string) $role : null;
}

function forest_is_admin($conn, $userId, $roles = null)
{
    $role = forest_admin_role($conn, $userId);
    if ($role === null) {
        return false;
    }

    return $roles === null || in_array($role, (array) $roles, true);
}

/** Игрок из сессии с правами администратора леса; иначе ответ forbidden. */
function forest_require_admin($conn, $roles = null)
{
    $userId = forest_require_player($conn, true);
    if (!forest_is_admin($conn, $userId, $roles)) {
        forest_error('forbidden');
    }

    return $userId;
}

/** Выдать или сменить роль. $grantedBy: 'admin' (панель) или логин. Возвращает true. */
function forest_admin_grant($conn, $userId, $role, $grantedBy = 'admin')
{
    if (!in_array($role, forest_config('admin.roles'), true)) {
        throw new InvalidArgumentException('Неизвестная роль.');
    }
    $userId = (int) $userId;
    $grantedBy = substr((string) $grantedBy, 0, 64);
    $now = time();
    $stmt = $conn->prepare('INSERT INTO `forest_admins` (`player_id`, `role`, `granted_by`, `granted_at`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `role` = VALUES(`role`), `granted_by` = VALUES(`granted_by`), `granted_at` = VALUES(`granted_at`)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось выдать права.');
    }
    $stmt->bind_param('issi', $userId, $role, $grantedBy, $now);
    $ok = $stmt->execute();
    $stmt->close();

    return (bool) $ok;
}

function forest_admin_revoke($conn, $userId)
{
    $userId = (int) $userId;
    $stmt = $conn->prepare('DELETE FROM `forest_admins` WHERE `player_id` = ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось забрать права.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $affected = $stmt->affected_rows;
    $stmt->close();

    return $affected > 0;
}

/** Список администраторов леса с логинами игроков. */
function forest_admin_list($conn)
{
    $rows = [];
    $res = $conn->query('SELECT a.`player_id`, a.`role`, a.`granted_by`, a.`granted_at`, u.`login` FROM `forest_admins` a LEFT JOIN `users` u ON u.`id` = a.`player_id` ORDER BY a.`granted_at` DESC LIMIT 200');
    while ($res instanceof mysqli_result && ($row = $res->fetch_assoc())) {
        $rows[] = [
            'user_id' => (int) $row['player_id'],
            'login' => (string) ($row['login'] ?? ''),
            'role' => (string) $row['role'],
            'granted_by' => (string) $row['granted_by'],
            'granted_at' => (int) $row['granted_at'],
        ];
    }
    if ($res instanceof mysqli_result) {
        $res->free();
    }

    return $rows;
}

/**
 * Лимит частоты: не чаще одного раза в $minIntervalMs для ключа (например 'sync:42').
 * Возвращает true, если можно продолжать. Хранение: APCu, а если его нет — файл в каталоге кеша
 * (без запросов к БД, чтобы не тратить лимит хостинга).
 */
function forest_rate_limit($key, $minIntervalMs)
{
    $key = substr(hash('sha256', 'forest-rl|' . $key), 0, 32);
    $nowMs = (int) floor(microtime(true) * 1000);
    $minIntervalMs = max(0, (int) $minIntervalMs);

    if (function_exists('apcu_enabled') && apcu_enabled()) {
        $name = 'forest_rl_' . $key;
        $last = apcu_fetch($name, $found);
        if ($found && ($nowMs - (int) $last) < $minIntervalMs) {
            return false;
        }
        apcu_store($name, $nowMs, 60);

        return true;
    }

    $dir = bober_schema_guard_directory();
    if ($dir === '') {
        return true; // нет места для хранения: не блокируем игроков
    }
    $path = $dir . '/forest-rl-' . $key . '.tmp';
    $last = is_file($path) ? (int) @file_get_contents($path) : 0;
    if ($last > 0 && ($nowMs - $last) < $minIntervalMs) {
        return false;
    }
    @file_put_contents($path, (string) $nowMs, LOCK_EX);

    return true;
}

/** Грубая защита по IP: не больше $limit запросов за $windowSeconds (работает только с APCu). */
function forest_ip_guard($limit = 120, $windowSeconds = 10)
{
    if (!(function_exists('apcu_enabled') && apcu_enabled())) {
        return true;
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if ($ip === '') {
        return true;
    }
    $name = 'forest_ip_' . substr(hash('sha256', $ip), 0, 24);
    apcu_add($name, 0, max(1, (int) $windowSeconds));
    $hits = apcu_inc($name);

    return $hits === false || $hits <= (int) $limit;
}

/** Транзакция: callable получает соединение; при исключении откат и повтор исключения. */
function forest_tx($conn, callable $fn)
{
    $conn->begin_transaction();
    try {
        $result = $fn($conn);
        $conn->commit();

        return $result;
    } catch (Throwable $error) {
        try {
            $conn->rollback();
        } catch (Throwable $ignored) {
            // соединение могло оборваться
        }
        throw $error;
    }
}

/** Запись в журнал аномалий и действий админа (forest_log). */
function forest_log($conn, $playerId, $kind, $data = [])
{
    $stmt = $conn->prepare('INSERT INTO `forest_log` (`player_id`, `kind`, `data_json`, `ts`) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }
    $playerId = (int) $playerId;
    $kind = substr((string) $kind, 0, 32);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = time();
    $stmt->bind_param('issi', $playerId, $kind, $json, $now);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok;
}

/** Серверное время: секунды и миллисекунды. */
function forest_now_ms()
{
    return (int) floor(microtime(true) * 1000);
}
