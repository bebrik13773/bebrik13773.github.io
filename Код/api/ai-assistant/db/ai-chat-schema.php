<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';

/**
 * Создаёт/обновляет таблицы ИИ-ассистента: сессии чата, сообщения (короткая
 * память в рамках сессии, без вечной истории), жалобы на игроков, rate-limit.
 */
function bober_ai_ensure_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    $createSessionsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_message_at` TIMESTAMP NULL DEFAULT NULL,
    KEY `idx_ai_chat_sessions_user` (`user_id`, `last_message_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createSessionsSql)) {
        throw new RuntimeException('Не удалось создать таблицу сессий ИИ-чата.');
    }

    if (!bober_index_exists($conn, 'ai_chat_sessions', 'idx_ai_chat_sessions_user') && !$conn->query("CREATE INDEX `idx_ai_chat_sessions_user` ON `ai_chat_sessions` (`user_id`, `last_message_at`)")) {
        throw new RuntimeException('Не удалось создать индекс сессий ИИ-чата.');
    }

    $createMessagesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `session_id` BIGINT UNSIGNED NOT NULL,
    `role` VARCHAR(16) NOT NULL,
    `content` LONGTEXT NOT NULL,
    `tool_name` VARCHAR(64) NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ai_chat_messages_session` (`session_id`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createMessagesSql)) {
        throw new RuntimeException('Не удалось создать таблицу сообщений ИИ-чата.');
    }

    if (!bober_index_exists($conn, 'ai_chat_messages', 'idx_ai_chat_messages_session') && !$conn->query("CREATE INDEX `idx_ai_chat_messages_session` ON `ai_chat_messages` (`session_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс сообщений ИИ-чата.');
    }

    $createReportsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `player_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `reporter_user_id` INT NOT NULL,
    `reported_user_id` INT NULL DEFAULT NULL,
    `reported_name_raw` VARCHAR(255) NOT NULL DEFAULT '',
    `description` LONGTEXT NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'new',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_player_reports_status` (`status`, `created_at`),
    KEY `idx_player_reports_reporter` (`reporter_user_id`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createReportsSql)) {
        throw new RuntimeException('Не удалось создать таблицу жалоб на игроков.');
    }

    if (!bober_index_exists($conn, 'player_reports', 'idx_player_reports_status') && !$conn->query("CREATE INDEX `idx_player_reports_status` ON `player_reports` (`status`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс жалоб по статусу.');
    }

    if (!bober_index_exists($conn, 'player_reports', 'idx_player_reports_reporter') && !$conn->query("CREATE INDEX `idx_player_reports_reporter` ON `player_reports` (`reporter_user_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс жалоб по репортеру.');
    }

    // Общий rate-limit на сообщения чата (окно — 1 час, скользящее по last_reset)
    $createRateLimitSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_rate_limit` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `messages_this_hour` INT NOT NULL DEFAULT 0,
    `hour_window_started_at` TIMESTAMP NULL DEFAULT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createRateLimitSql)) {
        throw new RuntimeException('Не удалось создать таблицу rate-limit ИИ-чата.');
    }

    // Отдельный, более жёсткий rate-limit на "опасные" действия из чата
    // (покупки апгрейдов, создание тикетов) — чтобы глюк диалога не привёл
    // к многократным списаниям/спаму тикетов.
    $createActionRateLimitSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_action_rate_limit` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `actions_this_hour` INT NOT NULL DEFAULT 0,
    `hour_window_started_at` TIMESTAMP NULL DEFAULT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createActionRateLimitSql)) {
        throw new RuntimeException('Не удалось создать таблицу rate-limit действий ИИ-чата.');
    }

    $schemaEnsured = true;
}

/**
 * Проверяет и инкрементирует общий rate-limit на сообщения чата.
 * Возвращает true, если сообщение разрешено, false — если лимит исчерпан.
 */
function bober_ai_check_and_bump_rate_limit($conn, $userId, $limitPerHour = 15)
{
    return bober_ai_check_and_bump_generic_rate_limit($conn, 'ai_chat_rate_limit', 'messages_this_hour', $userId, $limitPerHour);
}

/**
 * Проверяет и инкрементирует более жёсткий rate-limit на "действия"
 * (покупки, тикеты) внутри чата.
 */
function bober_ai_check_and_bump_action_rate_limit($conn, $userId, $limitPerHour = 5)
{
    return bober_ai_check_and_bump_generic_rate_limit($conn, 'ai_chat_action_rate_limit', 'actions_this_hour', $userId, $limitPerHour);
}

function bober_ai_check_and_bump_generic_rate_limit($conn, $table, $counterColumn, $userId, $limitPerHour)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $table = bober_require_identifier($table, 'Имя таблицы');
    $counterColumn = bober_require_identifier($counterColumn, 'Имя колонки счётчика');

    $stmt = $conn->prepare("SELECT `{$counterColumn}` AS cnt, `hour_window_started_at` FROM `{$table}` WHERE `user_id` = ? LIMIT 1 FOR UPDATE");
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить проверку лимита.');
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось выполнить проверку лимита.');
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $now = time();
    $windowStart = isset($row['hour_window_started_at']) ? strtotime((string) $row['hour_window_started_at']) : false;
    $windowExpired = ($windowStart === false) || (($now - $windowStart) >= 3600);

    if (!is_array($row)) {
        // Первая запись для пользователя
        $insertStmt = $conn->prepare("INSERT INTO `{$table}` (`user_id`, `{$counterColumn}`, `hour_window_started_at`) VALUES (?, 1, NOW())");
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось создать запись лимита.');
        }
        $insertStmt->bind_param('i', $userId);
        $insertStmt->execute();
        $insertStmt->close();
        return true;
    }

    if ($windowExpired) {
        $resetStmt = $conn->prepare("UPDATE `{$table}` SET `{$counterColumn}` = 1, `hour_window_started_at` = NOW() WHERE `user_id` = ?");
        if (!$resetStmt) {
            throw new RuntimeException('Не удалось сбросить окно лимита.');
        }
        $resetStmt->bind_param('i', $userId);
        $resetStmt->execute();
        $resetStmt->close();
        return true;
    }

    $currentCount = max(0, (int) ($row['cnt'] ?? 0));
    if ($currentCount >= $limitPerHour) {
        return false;
    }

    $bumpStmt = $conn->prepare("UPDATE `{$table}` SET `{$counterColumn}` = `{$counterColumn}` + 1 WHERE `user_id` = ?");
    if (!$bumpStmt) {
        throw new RuntimeException('Не удалось обновить счётчик лимита.');
    }
    $bumpStmt->bind_param('i', $userId);
    $bumpStmt->execute();
    $bumpStmt->close();

    return true;
}

/**
 * Находит (или создаёт) активную сессию чата для пользователя.
 * Сессия считается "активной", если последнее сообщение было не более
 * 30 минут назад — иначе открываем новую (короткая память, без раздувания).
 */
function bober_ai_get_or_create_session($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $stmt = $conn->prepare('SELECT id, last_message_at FROM ai_chat_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти сессию чата.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $isFresh = false;
    if (is_array($row) && !empty($row['last_message_at'])) {
        $lastMessageAt = strtotime((string) $row['last_message_at']);
        $isFresh = ($lastMessageAt !== false) && ((time() - $lastMessageAt) < 1800);
    }

    if (is_array($row) && $isFresh) {
        return (int) $row['id'];
    }

    $insertStmt = $conn->prepare('INSERT INTO ai_chat_sessions (user_id, started_at, last_message_at) VALUES (?, NOW(), NOW())');
    if (!$insertStmt) {
        throw new RuntimeException('Не удалось создать сессию чата.');
    }
    $insertStmt->bind_param('i', $userId);
    $insertStmt->execute();
    $newSessionId = $conn->insert_id;
    $insertStmt->close();

    return (int) $newSessionId;
}

function bober_ai_touch_session($conn, $sessionId)
{
    $sessionId = max(0, (int) $sessionId);
    $stmt = $conn->prepare('UPDATE ai_chat_sessions SET last_message_at = NOW() WHERE id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $sessionId);
        $stmt->execute();
        $stmt->close();
    }
}

function bober_ai_append_message($conn, $sessionId, $role, $content, $toolName = null)
{
    $sessionId = max(0, (int) $sessionId);
    $role = (string) $role;
    $content = (string) $content;

    $stmt = $conn->prepare('INSERT INTO ai_chat_messages (session_id, role, content, tool_name) VALUES (?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось сохранить сообщение чата.');
    }
    $stmt->bind_param('isss', $sessionId, $role, $content, $toolName);
    $stmt->execute();
    $stmt->close();
}

/**
 * Возвращает последние N сообщений сессии в хронологическом порядке —
 * это и есть "короткая память" в рамках одной сессии чата.
 */
function bober_ai_fetch_session_messages($conn, $sessionId, $limit = 20)
{
    $sessionId = max(0, (int) $sessionId);
    $limit = max(1, min(50, (int) $limit));

    $stmt = $conn->prepare('SELECT role, content, tool_name, created_at FROM ai_chat_messages WHERE session_id = ? ORDER BY id DESC LIMIT ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось получить сообщения сессии.');
    }
    $stmt->bind_param('ii', $sessionId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return array_reverse($rows);
}

function bober_ai_create_player_report($conn, $reporterUserId, $reportedUserId, $reportedNameRaw, $description)
{
    $reporterUserId = max(0, (int) $reporterUserId);
    if ($reporterUserId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор автора жалобы.');
    }

    $reportedUserIdValue = $reportedUserId !== null ? max(0, (int) $reportedUserId) : null;
    $reportedNameRaw = (string) $reportedNameRaw;
    $description = trim((string) $description);
    if ($description === '') {
        throw new InvalidArgumentException('Текст жалобы не может быть пустым.');
    }

    $stmt = $conn->prepare('INSERT INTO player_reports (reporter_user_id, reported_user_id, reported_name_raw, description, status) VALUES (?, ?, ?, ?, \'new\')');
    if (!$stmt) {
        throw new RuntimeException('Не удалось создать жалобу на игрока.');
    }
    $stmt->bind_param('iiss', $reporterUserId, $reportedUserIdValue, $reportedNameRaw, $description);
    $stmt->execute();
    $newReportId = $conn->insert_id;
    $stmt->close();

    return (int) $newReportId;
}
