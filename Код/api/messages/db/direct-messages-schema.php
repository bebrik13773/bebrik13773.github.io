<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';

/**
 * Создаёт/обновляет таблицы личных переписок между игроками: треды
 * (conversations) с двумя участниками, сообщения внутри треда,
 * rate-limit на отправку и блокировки/жалобы.
 *
 * Пара участников в `player_conversations` всегда хранится нормализованной
 * (user_low_id < user_high_id), чтобы для двух игроков существовал ровно
 * один тред независимо от того, кто написал первым.
 */
function bober_dm_ensure_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    $createConversationsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `player_conversations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_low_id` INT NOT NULL,
    `user_high_id` INT NOT NULL,
    `last_message_at` TIMESTAMP NULL DEFAULT NULL,
    `last_message_preview` VARCHAR(200) NOT NULL DEFAULT '',
    `unread_by_low` INT NOT NULL DEFAULT 0,
    `unread_by_high` INT NOT NULL DEFAULT 0,
    `blocked_by_low` TINYINT(1) NOT NULL DEFAULT 0,
    `blocked_by_high` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_player_conversations_pair` (`user_low_id`, `user_high_id`),
    KEY `idx_player_conversations_low` (`user_low_id`, `last_message_at`),
    KEY `idx_player_conversations_high` (`user_high_id`, `last_message_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createConversationsSql)) {
        throw new RuntimeException('Не удалось создать таблицу личных переписок.');
    }

    if (!bober_index_exists($conn, 'player_conversations', 'idx_player_conversations_low') && !$conn->query("CREATE INDEX `idx_player_conversations_low` ON `player_conversations` (`user_low_id`, `last_message_at`)")) {
        throw new RuntimeException('Не удалось создать индекс переписок по первому участнику.');
    }

    if (!bober_index_exists($conn, 'player_conversations', 'idx_player_conversations_high') && !$conn->query("CREATE INDEX `idx_player_conversations_high` ON `player_conversations` (`user_high_id`, `last_message_at`)")) {
        throw new RuntimeException('Не удалось создать индекс переписок по второму участнику.');
    }

    $createMessagesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `player_direct_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `sender_id` INT NOT NULL,
    `message_text` VARCHAR(2000) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_player_direct_messages_conversation` (`conversation_id`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createMessagesSql)) {
        throw new RuntimeException('Не удалось создать таблицу сообщений личных переписок.');
    }

    if (!bober_index_exists($conn, 'player_direct_messages', 'idx_player_direct_messages_conversation') && !$conn->query("CREATE INDEX `idx_player_direct_messages_conversation` ON `player_direct_messages` (`conversation_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс сообщений личных переписок.');
    }

    // Rate-limit на отправку личных сообщений — короткое (минутное) окно,
    // в отличие от часового окна ai_chat_rate_limit: спам между игроками
    // происходит намного "плотнее", чем спам в чат с ИИ.
    $createRateLimitSql = <<<SQL
CREATE TABLE IF NOT EXISTS `player_message_rate_limit` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `messages_this_window` INT NOT NULL DEFAULT 0,
    `window_started_at` TIMESTAMP NULL DEFAULT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createRateLimitSql)) {
        throw new RuntimeException('Не удалось создать таблицу rate-limit личных переписок.');
    }

    // Жалобы на конкретное сообщение личной переписки. Отдельная таблица
    // от общей `player_reports` (жалоба "на игрока вообще" из ИИ-чата) —
    // здесь жалоба всегда привязана к переписке и конкретному сообщению.
    $createMessageReportsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `player_message_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `reporter_user_id` INT NOT NULL,
    `reported_user_id` INT NOT NULL,
    `conversation_id` BIGINT UNSIGNED NOT NULL,
    `message_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `reason` VARCHAR(500) NOT NULL DEFAULT '',
    `status` VARCHAR(16) NOT NULL DEFAULT 'new',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_player_message_reports_status` (`status`, `created_at`),
    KEY `idx_player_message_reports_conversation` (`conversation_id`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createMessageReportsSql)) {
        throw new RuntimeException('Не удалось создать таблицу жалоб на сообщения переписок.');
    }

    if (!bober_index_exists($conn, 'player_message_reports', 'idx_player_message_reports_status') && !$conn->query("CREATE INDEX `idx_player_message_reports_status` ON `player_message_reports` (`status`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс жалоб на сообщения по статусу.');
    }

    if (!bober_index_exists($conn, 'player_message_reports', 'idx_player_message_reports_conversation') && !$conn->query("CREATE INDEX `idx_player_message_reports_conversation` ON `player_message_reports` (`conversation_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс жалоб на сообщения по переписке.');
    }

    $schemaEnsured = true;
}

/**
 * Нормализует пару участников так, чтобы user_low_id < user_high_id.
 * Возвращает [$lowId, $highId] или бросает исключение при некорректных id.
 */
function bober_dm_normalize_pair($userIdA, $userIdB)
{
    $userIdA = max(0, (int) $userIdA);
    $userIdB = max(0, (int) $userIdB);

    if ($userIdA < 1 || $userIdB < 1) {
        throw new InvalidArgumentException('Некорректные идентификаторы участников переписки.');
    }

    if ($userIdA === $userIdB) {
        throw new InvalidArgumentException('Нельзя написать самому себе.');
    }

    return $userIdA < $userIdB ? [$userIdA, $userIdB] : [$userIdB, $userIdA];
}

/**
 * Лёгкий поиск игрока по логину для начала переписки — возвращает только
 * id и логин (без полного публичного профиля со скинами/достижениями).
 * Не пускает писать самому себе; исключает служебный логин "test".
 */
function bober_dm_find_user_by_login($conn, $login, $excludeUserId = 0)
{
    $login = trim((string) $login);
    if ($login === '') {
        throw new InvalidArgumentException('Введите логин игрока.');
    }

    $stmt = $conn->prepare(
        'SELECT id, login FROM users
         WHERE LOWER(TRIM(login)) = LOWER(TRIM(?))
            AND login IS NOT NULL AND login <> \'\'
            AND LOWER(TRIM(login)) <> \'test\'
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить поиск игрока.');
    }
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Игрок с таким логином не найден.');
    }

    $foundUserId = (int) $row['id'];
    if ($excludeUserId > 0 && $foundUserId === (int) $excludeUserId) {
        throw new RuntimeException('Нельзя написать самому себе.');
    }

    return [
        'userId' => $foundUserId,
        'login' => (string) $row['login'],
    ];
}

/**
 * Находит или создаёт тред переписки между двумя игроками.
 * Возвращает массив с id, парой участников и текущим состоянием блокировок.
 */
function bober_dm_get_or_create_conversation($conn, $userIdA, $userIdB)
{
    [$lowId, $highId] = bober_dm_normalize_pair($userIdA, $userIdB);

    $stmt = $conn->prepare('SELECT id, user_low_id, user_high_id, blocked_by_low, blocked_by_high FROM player_conversations WHERE user_low_id = ? AND user_high_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти переписку.');
    }
    $stmt->bind_param('ii', $lowId, $highId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (is_array($row)) {
        return [
            'id' => (int) $row['id'],
            'userLowId' => (int) $row['user_low_id'],
            'userHighId' => (int) $row['user_high_id'],
            'blockedByLow' => (bool) $row['blocked_by_low'],
            'blockedByHigh' => (bool) $row['blocked_by_high'],
        ];
    }

    $insertStmt = $conn->prepare('INSERT INTO player_conversations (user_low_id, user_high_id) VALUES (?, ?)');
    if (!$insertStmt) {
        throw new RuntimeException('Не удалось создать переписку.');
    }
    $insertStmt->bind_param('ii', $lowId, $highId);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        throw new RuntimeException('Не удалось создать переписку.');
    }
    $newId = (int) $conn->insert_id;
    $insertStmt->close();

    return [
        'id' => $newId,
        'userLowId' => $lowId,
        'userHighId' => $highId,
        'blockedByLow' => false,
        'blockedByHigh' => false,
    ];
}

/**
 * Возвращает id собеседника в переписке относительно заданного пользователя.
 */
function bober_dm_other_user_id(array $conversation, $userId)
{
    $userId = (int) $userId;
    if ($conversation['userLowId'] === $userId) {
        return (int) $conversation['userHighId'];
    }
    if ($conversation['userHighId'] === $userId) {
        return (int) $conversation['userLowId'];
    }

    throw new InvalidArgumentException('Пользователь не является участником переписки.');
}

/**
 * Проверяет, заблокирован ли получатель отправителем в этой переписке
 * (то есть сообщение не дойдёт) — мягкая блокировка: блокирующий просто
 * перестаёт получать новые сообщения, история остаётся видна обоим.
 */
function bober_dm_is_blocked_for_sender(array $conversation, $senderId, $recipientId)
{
    $senderId = (int) $senderId;
    $recipientId = (int) $recipientId;

    if ($conversation['userLowId'] === $recipientId) {
        return (bool) $conversation['blockedByLow'];
    }
    if ($conversation['userHighId'] === $recipientId) {
        return (bool) $conversation['blockedByHigh'];
    }

    return false;
}

/**
 * Проверяет и инкрементирует rate-limit на отправку личных сообщений.
 * Короткое скользящее окно (по умолчанию 60 секунд), возвращает true,
 * если сообщение разрешено, false — если лимит окна исчерпан.
 */
function bober_dm_check_and_bump_rate_limit($conn, $userId, $limitPerWindow = 8, $windowSeconds = 60)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }
    $limitPerWindow = max(1, (int) $limitPerWindow);
    $windowSeconds = max(1, (int) $windowSeconds);

    $stmt = $conn->prepare('SELECT messages_this_window AS cnt, window_started_at FROM player_message_rate_limit WHERE user_id = ? LIMIT 1 FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить проверку лимита сообщений.');
    }
    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось выполнить проверку лимита сообщений.');
    }
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $now = time();
    $windowStart = isset($row['window_started_at']) ? strtotime((string) $row['window_started_at']) : false;
    $windowExpired = ($windowStart === false) || (($now - $windowStart) >= $windowSeconds);

    if (!is_array($row)) {
        $insertStmt = $conn->prepare('INSERT INTO player_message_rate_limit (user_id, messages_this_window, window_started_at) VALUES (?, 1, NOW())');
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось создать запись лимита сообщений.');
        }
        $insertStmt->bind_param('i', $userId);
        $insertStmt->execute();
        $insertStmt->close();

        return true;
    }

    if ($windowExpired) {
        $resetStmt = $conn->prepare('UPDATE player_message_rate_limit SET messages_this_window = 1, window_started_at = NOW() WHERE user_id = ?');
        if (!$resetStmt) {
            throw new RuntimeException('Не удалось сбросить лимит сообщений.');
        }
        $resetStmt->bind_param('i', $userId);
        $resetStmt->execute();
        $resetStmt->close();

        return true;
    }

    $currentCount = (int) $row['cnt'];
    if ($currentCount >= $limitPerWindow) {
        return false;
    }

    $bumpStmt = $conn->prepare('UPDATE player_message_rate_limit SET messages_this_window = messages_this_window + 1 WHERE user_id = ?');
    if (!$bumpStmt) {
        throw new RuntimeException('Не удалось обновить лимит сообщений.');
    }
    $bumpStmt->bind_param('i', $userId);
    $bumpStmt->execute();
    $bumpStmt->close();

    return true;
}

/**
 * Отправляет сообщение в переписку. Бросает исключение, если отправитель
 * заблокирован получателем. Обновляет превью, unread-счётчики и время.
 */
function bober_dm_send_message($conn, $senderId, $recipientId, $messageText)
{
    $senderId = max(0, (int) $senderId);
    $messageText = trim((string) $messageText);
    if ($messageText === '') {
        throw new InvalidArgumentException('Сообщение не может быть пустым.');
    }
    if (mb_strlen($messageText) > 2000) {
        $messageText = mb_substr($messageText, 0, 2000);
    }

    $conversation = bober_dm_get_or_create_conversation($conn, $senderId, $recipientId);

    if (bober_dm_is_blocked_for_sender($conversation, $senderId, $recipientId)) {
        throw new RuntimeException('Этот игрок ограничил вам возможность писать ему.');
    }

    $insertStmt = $conn->prepare('INSERT INTO player_direct_messages (conversation_id, sender_id, message_text) VALUES (?, ?, ?)');
    if (!$insertStmt) {
        throw new RuntimeException('Не удалось отправить сообщение.');
    }
    $conversationId = $conversation['id'];
    $insertStmt->bind_param('iis', $conversationId, $senderId, $messageText);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        throw new RuntimeException('Не удалось отправить сообщение.');
    }
    $newMessageId = (int) $conn->insert_id;
    $insertStmt->close();

    $preview = mb_substr($messageText, 0, 180);
    $isSenderLow = ($conversation['userLowId'] === $senderId);

    $updateSql = $isSenderLow
        ? 'UPDATE player_conversations SET last_message_at = NOW(), last_message_preview = ?, unread_by_high = unread_by_high + 1 WHERE id = ?'
        : 'UPDATE player_conversations SET last_message_at = NOW(), last_message_preview = ?, unread_by_low = unread_by_low + 1 WHERE id = ?';

    $updateStmt = $conn->prepare($updateSql);
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось обновить переписку.');
    }
    $updateStmt->bind_param('si', $preview, $conversationId);
    $updateStmt->execute();
    $updateStmt->close();

    return [
        'messageId' => $newMessageId,
        'conversationId' => (int) $conversationId,
    ];
}

/**
 * Список тредов переписки пользователя (для списка чатов), отсортирован
 * по времени последнего сообщения. Каждый элемент содержит собеседника,
 * превью, время и флаг непрочитанного/блокировки.
 */
function bober_dm_fetch_conversations_for_user($conn, $userId, array $options = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }
    $limit = max(1, min(100, (int) ($options['limit'] ?? 50)));

    $sql = "
        SELECT
            c.id,
            c.user_low_id,
            c.user_high_id,
            c.last_message_at,
            c.last_message_preview,
            c.unread_by_low,
            c.unread_by_high,
            c.blocked_by_low,
            c.blocked_by_high,
            other.login AS other_login
        FROM player_conversations c
        LEFT JOIN users other ON other.id = IF(c.user_low_id = ?, c.user_high_id, c.user_low_id)
        WHERE c.user_low_id = ? OR c.user_high_id = ?
        ORDER BY c.last_message_at DESC
        LIMIT ?
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить список переписок.');
    }
    $stmt->bind_param('iiii', $userId, $userId, $userId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $isLow = ((int) $row['user_low_id'] === $userId);
        $rows[] = [
            'conversationId' => (int) $row['id'],
            'otherUserId' => $isLow ? (int) $row['user_high_id'] : (int) $row['user_low_id'],
            'otherLogin' => (string) ($row['other_login'] ?? 'Игрок'),
            'lastMessageAt' => $row['last_message_at'],
            'lastMessagePreview' => (string) $row['last_message_preview'],
            'unreadCount' => $isLow ? (int) $row['unread_by_low'] : (int) $row['unread_by_high'],
            'blockedByMe' => $isLow ? (bool) $row['blocked_by_low'] : (bool) $row['blocked_by_high'],
            'blockedByOther' => $isLow ? (bool) $row['blocked_by_high'] : (bool) $row['blocked_by_low'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Полная переписка одного треда (сообщения по порядку) — с проверкой,
 * что запрашивающий пользователь действительно участник треда.
 */
function bober_dm_fetch_conversation_messages($conn, $userId, $conversationId)
{
    $userId = max(0, (int) $userId);
    $conversationId = max(0, (int) $conversationId);
    if ($conversationId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор переписки.');
    }

    $stmt = $conn->prepare('SELECT id, user_low_id, user_high_id, blocked_by_low, blocked_by_high FROM player_conversations WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти переписку.');
    }
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Переписка не найдена.');
    }

    $conversation = [
        'id' => (int) $row['id'],
        'userLowId' => (int) $row['user_low_id'],
        'userHighId' => (int) $row['user_high_id'],
        'blockedByLow' => (bool) $row['blocked_by_low'],
        'blockedByHigh' => (bool) $row['blocked_by_high'],
    ];

    if ($conversation['userLowId'] !== $userId && $conversation['userHighId'] !== $userId) {
        throw new RuntimeException('У вас нет доступа к этой переписке.');
    }

    $otherUserId = bober_dm_other_user_id($conversation, $userId);

    $loginStmt = $conn->prepare('SELECT login FROM users WHERE id = ? LIMIT 1');
    $otherLogin = 'Игрок';
    if ($loginStmt) {
        $loginStmt->bind_param('i', $otherUserId);
        $loginStmt->execute();
        $loginResult = $loginStmt->get_result();
        $loginRow = $loginResult ? $loginResult->fetch_assoc() : null;
        if ($loginResult instanceof mysqli_result) {
            $loginResult->free();
        }
        $loginStmt->close();
        if (is_array($loginRow)) {
            $otherLogin = (string) $loginRow['login'];
        }
    }

    $messagesStmt = $conn->prepare('SELECT id, sender_id, message_text, created_at FROM player_direct_messages WHERE conversation_id = ? ORDER BY id ASC');
    if (!$messagesStmt) {
        throw new RuntimeException('Не удалось получить сообщения переписки.');
    }
    $messagesStmt->bind_param('i', $conversationId);
    $messagesStmt->execute();
    $messagesResult = $messagesStmt->get_result();
    $messages = [];
    while ($messagesResult && ($msgRow = $messagesResult->fetch_assoc())) {
        $messages[] = [
            'id' => (int) $msgRow['id'],
            'senderId' => (int) $msgRow['sender_id'],
            'text' => (string) $msgRow['message_text'],
            'createdAt' => $msgRow['created_at'],
        ];
    }
    if ($messagesResult instanceof mysqli_result) {
        $messagesResult->free();
    }
    $messagesStmt->close();

    $isLow = ($conversation['userLowId'] === $userId);

    return [
        'conversationId' => $conversation['id'],
        'otherUserId' => $otherUserId,
        'otherLogin' => $otherLogin,
        'blockedByMe' => $isLow ? $conversation['blockedByLow'] : $conversation['blockedByHigh'],
        'blockedByOther' => $isLow ? $conversation['blockedByHigh'] : $conversation['blockedByLow'],
        'messages' => $messages,
    ];
}

/**
 * Отмечает переписку прочитанной для указанного пользователя (сбрасывает
 * его unread-счётчик в ноль).
 */
function bober_dm_mark_conversation_read($conn, $userId, $conversationId)
{
    $userId = max(0, (int) $userId);
    $conversationId = max(0, (int) $conversationId);
    if ($conversationId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор переписки.');
    }

    $stmt = $conn->prepare('SELECT user_low_id, user_high_id FROM player_conversations WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти переписку.');
    }
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return;
    }

    $isLow = ((int) $row['user_low_id'] === $userId);
    if (!$isLow && (int) $row['user_high_id'] !== $userId) {
        throw new RuntimeException('У вас нет доступа к этой переписке.');
    }

    $column = $isLow ? 'unread_by_low' : 'unread_by_high';
    $updateStmt = $conn->prepare("UPDATE player_conversations SET `{$column}` = 0 WHERE id = ?");
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось отметить переписку прочитанной.');
    }
    $updateStmt->bind_param('i', $conversationId);
    $updateStmt->execute();
    $updateStmt->close();
}

/**
 * Ставит/снимает мягкую блокировку собеседника в переписке: блокирующий
 * просто перестаёт получать новые сообщения, история остаётся видна обоим.
 */
function bober_dm_set_block($conn, $userId, $conversationId, $blocked)
{
    $userId = max(0, (int) $userId);
    $conversationId = max(0, (int) $conversationId);
    if ($conversationId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор переписки.');
    }

    $stmt = $conn->prepare('SELECT user_low_id, user_high_id FROM player_conversations WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти переписку.');
    }
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Переписка не найдена.');
    }

    $isLow = ((int) $row['user_low_id'] === $userId);
    if (!$isLow && (int) $row['user_high_id'] !== $userId) {
        throw new RuntimeException('У вас нет доступа к этой переписке.');
    }

    $column = $isLow ? 'blocked_by_low' : 'blocked_by_high';
    $blockedValue = $blocked ? 1 : 0;
    $updateStmt = $conn->prepare("UPDATE player_conversations SET `{$column}` = ? WHERE id = ?");
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось обновить блокировку.');
    }
    $updateStmt->bind_param('ii', $blockedValue, $conversationId);
    $updateStmt->execute();
    $updateStmt->close();
}

/**
 * Создаёт жалобу на сообщение в переписке.
 */
function bober_dm_report_message($conn, $reporterUserId, $conversationId, $messageId, $reason)
{
    $reporterUserId = max(0, (int) $reporterUserId);
    $conversationId = max(0, (int) $conversationId);
    $messageId = max(0, (int) $messageId);
    $reason = trim((string) $reason);
    if ($reason !== '' && mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    $stmt = $conn->prepare('SELECT user_low_id, user_high_id FROM player_conversations WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти переписку.');
    }
    $stmt->bind_param('i', $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Переписка не найдена.');
    }

    $conversation = [
        'userLowId' => (int) $row['user_low_id'],
        'userHighId' => (int) $row['user_high_id'],
    ];

    if ($conversation['userLowId'] !== $reporterUserId && $conversation['userHighId'] !== $reporterUserId) {
        throw new RuntimeException('У вас нет доступа к этой переписке.');
    }

    $reportedUserId = $conversation['userLowId'] === $reporterUserId
        ? $conversation['userHighId']
        : $conversation['userLowId'];

    if ($messageId > 0) {
        $insertStmt = $conn->prepare('INSERT INTO player_message_reports (reporter_user_id, reported_user_id, conversation_id, message_id, reason) VALUES (?, ?, ?, ?, ?)');
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось создать жалобу.');
        }
        $insertStmt->bind_param('iiiis', $reporterUserId, $reportedUserId, $conversationId, $messageId, $reason);
    } else {
        $insertStmt = $conn->prepare('INSERT INTO player_message_reports (reporter_user_id, reported_user_id, conversation_id, message_id, reason) VALUES (?, ?, ?, NULL, ?)');
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось создать жалобу.');
        }
        $insertStmt->bind_param('iiis', $reporterUserId, $reportedUserId, $conversationId, $reason);
    }
    $insertStmt->execute();
    $newReportId = (int) $conn->insert_id;
    $insertStmt->close();

    return $newReportId;
}
