<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';
require_once dirname(__DIR__) . '/moderation.php';

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
    `last_message_preview` TEXT NOT NULL,
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
    `message_text` TEXT NOT NULL,
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

    // Миграция для БД, созданных до включения шифрования: старые таблицы
    // имели VARCHAR-колонки, а шифротекст (base64 IV + base64 ciphertext)
    // заметно длиннее исходного текста и в них не влезет.
    $conn->query("ALTER TABLE `player_direct_messages` MODIFY COLUMN `message_text` TEXT NOT NULL");
    $conn->query("ALTER TABLE `player_conversations` MODIFY COLUMN `last_message_preview` TEXT NOT NULL");

    // Самоудаление сообщения игроком (не путать с админским удалением по
    // жалобе — это `bober_dm_admin_delete_reported_message`, физический
    // DELETE). Здесь мягкое удаление: текст остаётся в БД для возможного
    // аудита по будущей жалобе, но при чтении переписки отдаётся плейсхолдер
    // вместо расшифрованного текста — как отправителю, так и собеседнику.
    if (!bober_column_exists($conn, 'player_direct_messages', 'deleted_at')) {
        if (!$conn->query('ALTER TABLE `player_direct_messages` ADD COLUMN `deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `message_text`')) {
            throw new RuntimeException('Не удалось добавить колонку удаления сообщения.');
        }
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

    // Отметка о том, что сообщение из жалобы было удалено админом. Текст
    // самого сообщения при этом не читается и не сохраняется отдельно —
    // видно только то, что игрок сам процитировал в жалобе (см. get_dm_reports).
    if (!bober_column_exists($conn, 'player_message_reports', 'message_deleted_at')) {
        if (!$conn->query('ALTER TABLE `player_message_reports` ADD COLUMN `message_deleted_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`')) {
            throw new RuntimeException('Не удалось добавить колонку удаления сообщения в жалобах.');
        }
    }

    // Цитата сообщения на момент подачи жалобы — единственное место, где
    // расшифрованный текст вообще где-либо хранится, и то лишь то, что сам
    // игрок явно приложил к жалобе. Используется для отображения в очереди
    // модерации без общего доступа к переписке.
    if (!bober_column_exists($conn, 'player_message_reports', 'message_quote')) {
        if (!$conn->query('ALTER TABLE `player_message_reports` ADD COLUMN `message_quote` TEXT NULL DEFAULT NULL AFTER `reason`')) {
            throw new RuntimeException('Не удалось добавить колонку цитаты сообщения в жалобах.');
        }
    }

    // Админский мут — принципиально отдельная сущность от игрок-игрок
    // блокировки (`blocked_by_low/high`). scope: 'all' — запрет писать
    // всем, 'pair' — запрет писать конкретному собеседнику (target_user_id).
    $createAdminMutesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `player_dm_admin_mutes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `scope` VARCHAR(8) NOT NULL DEFAULT 'all',
    `target_user_id` INT NULL DEFAULT NULL,
    `reason` VARCHAR(500) NOT NULL DEFAULT '',
    `created_by` VARCHAR(64) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `expires_at` TIMESTAMP NULL DEFAULT NULL,
    KEY `idx_player_dm_admin_mutes_user` (`user_id`, `scope`, `target_user_id`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createAdminMutesSql)) {
        throw new RuntimeException('Не удалось создать таблицу админских мутов переписок.');
    }

    if (!bober_index_exists($conn, 'player_dm_admin_mutes', 'idx_player_dm_admin_mutes_user') && !$conn->query("CREATE INDEX `idx_player_dm_admin_mutes_user` ON `player_dm_admin_mutes` (`user_id`, `scope`, `target_user_id`)")) {
        throw new RuntimeException('Не удалось создать индекс админских мутов по пользователю.');
    }

    // Полная блокировка функции личных сообщений у аккаунта админом —
    // отдельный флаг на пользователе, а не в player_dm_admin_mutes, т.к.
    // это отключение фичи целиком, а не мут конкретной переписки.
    if (!bober_column_exists($conn, 'users', 'dm_disabled_at')) {
        if (!$conn->query('ALTER TABLE `users` ADD COLUMN `dm_disabled_at` TIMESTAMP NULL DEFAULT NULL')) {
            throw new RuntimeException('Не удалось добавить колонку отключения личных сообщений у пользователя.');
        }
    }

    // Список слов автомодерации (замена мата) — редактируется из админки,
    // структура повторяет prежний захардкоженный массив bober_moderation_word_map().
    $createModerationWordsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `dm_moderation_words` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `pattern` VARCHAR(255) NOT NULL,
    `replacement` VARCHAR(255) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_dm_moderation_words_pattern` (`pattern`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createModerationWordsSql)) {
        throw new RuntimeException('Не удалось создать таблицу слов автомодерации.');
    }

    bober_dm_seed_moderation_words_if_empty($conn);

    $schemaEnsured = true;
}

/**
 * Засевает таблицу слов автомодерации значениями из прежнего захардкоженного
 * массива — но только один раз, если таблица пуста (первый деплой после
 * миграции). После этого список полностью управляется из админки.
 */
function bober_dm_seed_moderation_words_if_empty($conn)
{
    $countResult = $conn->query('SELECT COUNT(*) AS cnt FROM dm_moderation_words');
    $countRow = $countResult ? $countResult->fetch_assoc() : null;
    if ($countResult instanceof mysqli_result) {
        $countResult->free();
    }
    if (!is_array($countRow) || (int) $countRow['cnt'] > 0) {
        return;
    }

    $defaults = [
        'бляд\S*' => 'блин',
        'бля' => 'блин',
        'сук[аи]\S*' => 'вот незадача',
        'хуй\S*' => 'ерунда',
        'хуе\S*' => 'ерунда',
        'хуё\S*' => 'ерунда',
        'пизд\S*' => 'фигня',
        'еба\S*' => 'блин',
        'ёб\S*' => 'блин',
        'выеб\S*' => 'обыграл',
        'долбо[её]б\S*' => 'чудак',
        'мудак\S*' => 'вредина',
        'муд[ои]л\S*' => 'вредина',
        'гандон\S*' => 'вредина',
        'ублюдок\S*' => 'негодник',
        'ублюдк\S*' => 'негодник',
        'уёб\S*' => 'негодник',
        'скотин\S*' => 'вредина',
        'сволоч\S*' => 'вредина',
        'дебил\S*' => 'чудак',
        'идиот\S*' => 'чудак',
        'кретин\S*' => 'чудак',
        'урод\S*' => 'непохожий на других',
        'тварь\S*' => 'вредина',
        'жоп\S*' => 'мягкое место',
        'залуп\S*' => 'ерунда',
        'придурок\S*' => 'чудак',
        'придурк\S*' => 'чудак',
        'говн\S*' => 'ерунда',
        'дерьм\S*' => 'ерунда',
        'хер\S*' => 'ерунда',
        'нахуй' => 'куда подальше',
        'похуй' => 'всё равно',
        'заебал\S*' => 'надоел',
        'наебал\S*' => 'обманул',
        'ебан\S*' => 'странный',
    ];

    $insertStmt = $conn->prepare('INSERT IGNORE INTO dm_moderation_words (pattern, replacement, sort_order) VALUES (?, ?, ?)');
    if (!$insertStmt) {
        return;
    }

    $order = 0;
    foreach ($defaults as $pattern => $replacement) {
        $insertStmt->bind_param('ssi', $pattern, $replacement, $order);
        $insertStmt->execute();
        $order++;
    }
    $insertStmt->close();
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

    if (bober_dm_admin_is_feature_disabled($conn, $senderId)) {
        throw new RuntimeException('Личные сообщения для вашего аккаунта отключены администрацией.');
    }

    if (bober_dm_admin_is_muted($conn, $senderId, $recipientId)) {
        throw new RuntimeException('Администрация ограничила вам возможность писать личные сообщения.');
    }

    $conversation = bober_dm_get_or_create_conversation($conn, $senderId, $recipientId);

    if (bober_dm_is_blocked_for_sender($conversation, $senderId, $recipientId)) {
        throw new RuntimeException('Этот игрок ограничил вам возможность писать ему.');
    }

    // Базовая модерация (замена мата) применяется до шифрования — работаем
    // с открытым текстом, шифруем уже смягчённый результат.
    $messageText = bober_moderate_text($messageText, $conn);

    $encryptedText = bober_encrypt_text($messageText);

    $insertStmt = $conn->prepare('INSERT INTO player_direct_messages (conversation_id, sender_id, message_text) VALUES (?, ?, ?)');
    if (!$insertStmt) {
        throw new RuntimeException('Не удалось отправить сообщение.');
    }
    $conversationId = $conversation['id'];
    $insertStmt->bind_param('iis', $conversationId, $senderId, $encryptedText);
    if (!$insertStmt->execute()) {
        $insertStmt->close();
        throw new RuntimeException('Не удалось отправить сообщение.');
    }
    $newMessageId = (int) $conn->insert_id;
    $insertStmt->close();

    $preview = bober_encrypt_text(mb_substr($messageText, 0, 180));
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
 * Текст-плейсхолдер, который видит собеседник вместо удалённого сообщения.
 */
const BOBER_DM_DELETED_MESSAGE_PLACEHOLDER = 'Сообщение удалено';

/**
 * Самоудаление игроком собственного сообщения (в любое время). Мягкое
 * удаление — ставим deleted_at, текст в БД не трогаем (остаётся для
 * возможного будущего разбора жалобы), но при чтении переписки отдаём
 * плейсхолдер вместо расшифрованного текста. Если удалённое сообщение было
 * последним в треде, обновляем и last_message_preview, чтобы список чатов
 * тоже показывал плейсхолдер, а не старый текст.
 */
function bober_dm_delete_own_message($conn, $userId, $conversationId, $messageId)
{
    $userId = max(0, (int) $userId);
    $conversationId = max(0, (int) $conversationId);
    $messageId = max(0, (int) $messageId);
    if ($conversationId < 1 || $messageId < 1) {
        throw new InvalidArgumentException('Некорректное сообщение.');
    }

    $stmt = $conn->prepare('SELECT id, conversation_id, sender_id, deleted_at FROM player_direct_messages WHERE id = ? AND conversation_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти сообщение.');
    }
    $stmt->bind_param('ii', $messageId, $conversationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Сообщение не найдено.');
    }
    if ((int) $row['sender_id'] !== $userId) {
        throw new RuntimeException('Можно удалять только свои сообщения.');
    }
    if ($row['deleted_at'] !== null) {
        // Уже удалено — тихо считаем успехом (идемпотентность).
        return;
    }

    $deleteStmt = $conn->prepare('UPDATE player_direct_messages SET deleted_at = NOW() WHERE id = ? AND conversation_id = ?');
    if (!$deleteStmt) {
        throw new RuntimeException('Не удалось удалить сообщение.');
    }
    $deleteStmt->bind_param('ii', $messageId, $conversationId);
    $deleteStmt->execute();
    $deleteStmt->close();

    // Если это было последнее сообщение треда — обновить превью в списке
    // чатов, иначе там ещё долго будет виден текст, которого уже нет.
    $lastIdStmt = $conn->prepare('SELECT id FROM player_direct_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 1');
    if ($lastIdStmt) {
        $lastIdStmt->bind_param('i', $conversationId);
        $lastIdStmt->execute();
        $lastIdResult = $lastIdStmt->get_result();
        $lastIdRow = $lastIdResult ? $lastIdResult->fetch_assoc() : null;
        if ($lastIdResult instanceof mysqli_result) {
            $lastIdResult->free();
        }
        $lastIdStmt->close();

        if (is_array($lastIdRow) && (int) $lastIdRow['id'] === $messageId) {
            $previewPlaceholder = bober_encrypt_text(BOBER_DM_DELETED_MESSAGE_PLACEHOLDER);
            $previewStmt = $conn->prepare('UPDATE player_conversations SET last_message_preview = ? WHERE id = ?');
            if ($previewStmt) {
                $previewStmt->bind_param('si', $previewPlaceholder, $conversationId);
                $previewStmt->execute();
                $previewStmt->close();
            }
        }
    }
}

/**
 * Очистка чата: помечает удалёнными ВСЕ сообщения переписки (доступно
 * любому из двух участников, не только отправителю каждого сообщения —
 * это отличает очистку от точечного самоудаления). Видно обеим сторонам,
 * как и одиночное удаление: собеседник увидит плейсхолдер на каждом
 * сообщении. Текст в БД не трогаем — та же логика мягкого удаления.
 */
function bober_dm_clear_conversation($conn, $userId, $conversationId)
{
    $userId = max(0, (int) $userId);
    $conversationId = max(0, (int) $conversationId);
    if ($conversationId < 1) {
        throw new InvalidArgumentException('Некорректная переписка.');
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
    if ((int) $row['user_low_id'] !== $userId && (int) $row['user_high_id'] !== $userId) {
        throw new RuntimeException('У вас нет доступа к этой переписке.');
    }

    $clearStmt = $conn->prepare('UPDATE player_direct_messages SET deleted_at = NOW() WHERE conversation_id = ? AND deleted_at IS NULL');
    if (!$clearStmt) {
        throw new RuntimeException('Не удалось очистить чат.');
    }
    $clearStmt->bind_param('i', $conversationId);
    $clearStmt->execute();
    $clearStmt->close();

    $previewPlaceholder = bober_encrypt_text(BOBER_DM_DELETED_MESSAGE_PLACEHOLDER);
    $previewStmt = $conn->prepare('UPDATE player_conversations SET last_message_preview = ? WHERE id = ?');
    if ($previewStmt) {
        $previewStmt->bind_param('si', $previewPlaceholder, $conversationId);
        $previewStmt->execute();
        $previewStmt->close();
    }
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
            'lastMessagePreview' => bober_decrypt_text((string) $row['last_message_preview']),
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

    $messagesStmt = $conn->prepare('SELECT id, sender_id, message_text, created_at, deleted_at FROM player_direct_messages WHERE conversation_id = ? ORDER BY id ASC');
    if (!$messagesStmt) {
        throw new RuntimeException('Не удалось получить сообщения переписки.');
    }
    $messagesStmt->bind_param('i', $conversationId);
    $messagesStmt->execute();
    $messagesResult = $messagesStmt->get_result();
    $messages = [];
    while ($messagesResult && ($msgRow = $messagesResult->fetch_assoc())) {
        $isDeleted = $msgRow['deleted_at'] !== null;
        $messages[] = [
            'id' => (int) $msgRow['id'],
            'senderId' => (int) $msgRow['sender_id'],
            'text' => $isDeleted ? BOBER_DM_DELETED_MESSAGE_PLACEHOLDER : bober_decrypt_text((string) $msgRow['message_text']),
            'createdAt' => $msgRow['created_at'],
            'deleted' => $isDeleted,
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

    // Цитата сохраняется здесь и только здесь: жалующийся сам раскрывает
    // текст конкретного сообщения, приложив его к жалобе. Это единственный
    // расшифрованный текст, который когда-либо попадает в поле зрения
    // админа — вся остальная переписка остаётся недоступной для чтения.
    $messageQuote = null;
    if ($messageId > 0) {
        $quoteStmt = $conn->prepare('SELECT message_text FROM player_direct_messages WHERE id = ? AND conversation_id = ? LIMIT 1');
        if ($quoteStmt) {
            $quoteStmt->bind_param('ii', $messageId, $conversationId);
            $quoteStmt->execute();
            $quoteResult = $quoteStmt->get_result();
            $quoteRow = $quoteResult ? $quoteResult->fetch_assoc() : null;
            if ($quoteResult instanceof mysqli_result) {
                $quoteResult->free();
            }
            $quoteStmt->close();
            if (is_array($quoteRow)) {
                $messageQuote = bober_decrypt_text((string) $quoteRow['message_text']);
                if (mb_strlen($messageQuote) > 2000) {
                    $messageQuote = mb_substr($messageQuote, 0, 2000);
                }
            }
        }
    }

    if ($messageId > 0) {
        $insertStmt = $conn->prepare('INSERT INTO player_message_reports (reporter_user_id, reported_user_id, conversation_id, message_id, reason, message_quote) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось создать жалобу.');
        }
        $insertStmt->bind_param('iiiiss', $reporterUserId, $reportedUserId, $conversationId, $messageId, $reason, $messageQuote);
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

/* =========================================================================
 * Ниже — функции для АДМИНКИ. Принципиальное ограничение: ни одна из них
 * не расшифровывает и не возвращает произвольный текст сообщений. Админ
 * видит только:
 *   - метаданные (кто, когда, сколько сообщений) в списке диалогов;
 *   - цитату из жалобы (message_quote), которую сам игрок раскрыл добровольно.
 * Полный текст переписки (`bober_dm_fetch_conversation_messages`) отсюда
 * НЕ вызывается и вызываться не должен.
 * ========================================================================= */

/**
 * Список активных диалогов для админки — без текста сообщений, только факт
 * переписки: участники, время последнего сообщения, счётчик сообщений.
 * Поддерживает поиск по логину одного из участников.
 */
function bober_dm_admin_fetch_conversations($conn, array $options = [])
{
    $limit = max(1, min(200, (int) ($options['limit'] ?? 100)));
    $search = trim((string) ($options['search'] ?? ''));

    $sql = "
        SELECT
            c.id,
            c.user_low_id,
            c.user_high_id,
            c.last_message_at,
            c.created_at,
            ulow.login AS low_login,
            uhigh.login AS high_login,
            (SELECT COUNT(*) FROM player_direct_messages m WHERE m.conversation_id = c.id) AS message_count
        FROM player_conversations c
        LEFT JOIN users ulow ON ulow.id = c.user_low_id
        LEFT JOIN users uhigh ON uhigh.id = c.user_high_id
    ";

    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= ' WHERE ulow.login LIKE ? OR uhigh.login LIKE ?';
        $likeTerm = '%' . $search . '%';
        $params[] = $likeTerm;
        $params[] = $likeTerm;
        $types .= 'ss';
    }

    $sql .= ' ORDER BY c.last_message_at DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить список переписок.');
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'conversationId' => (int) $row['id'],
            'userLowId' => (int) $row['user_low_id'],
            'userHighId' => (int) $row['user_high_id'],
            'lowLogin' => (string) ($row['low_login'] ?? '—'),
            'highLogin' => (string) ($row['high_login'] ?? '—'),
            'lastMessageAt' => $row['last_message_at'],
            'createdAt' => $row['created_at'],
            'messageCount' => (int) $row['message_count'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Очередь жалоб на сообщения переписок для админки. Возвращает цитату,
 * которую сам жалующийся приложил к жалобе (см. bober_dm_report_message) —
 * никакого дополнительного чтения переписки здесь не происходит.
 */
function bober_dm_admin_fetch_reports($conn, array $options = [])
{
    $limit = max(1, min(200, (int) ($options['limit'] ?? 100)));
    $status = trim((string) ($options['status'] ?? ''));

    $sql = "
        SELECT
            r.id,
            r.reporter_user_id,
            r.reported_user_id,
            r.conversation_id,
            r.message_id,
            r.reason,
            r.message_quote,
            r.status,
            r.message_deleted_at,
            r.created_at,
            reporter.login AS reporter_login,
            reported.login AS reported_login
        FROM player_message_reports r
        LEFT JOIN users reporter ON reporter.id = r.reporter_user_id
        LEFT JOIN users reported ON reported.id = r.reported_user_id
    ";

    $params = [];
    $types = '';
    if ($status !== '' && $status !== 'all') {
        $sql .= ' WHERE r.status = ?';
        $params[] = $status;
        $types .= 's';
    }

    $sql .= ' ORDER BY r.created_at DESC LIMIT ?';
    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить список жалоб.');
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'reportId' => (int) $row['id'],
            'reporterUserId' => (int) $row['reporter_user_id'],
            'reporterLogin' => (string) ($row['reporter_login'] ?? '—'),
            'reportedUserId' => (int) $row['reported_user_id'],
            'reportedLogin' => (string) ($row['reported_login'] ?? '—'),
            'conversationId' => (int) $row['conversation_id'],
            'messageId' => $row['message_id'] !== null ? (int) $row['message_id'] : null,
            'reason' => (string) $row['reason'],
            'messageQuote' => $row['message_quote'] !== null ? (string) $row['message_quote'] : null,
            'status' => (string) $row['status'],
            'messageDeletedAt' => $row['message_deleted_at'],
            'createdAt' => $row['created_at'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Удаляет сообщение, на которое пришла жалоба (только это сообщение — не
 * произвольное, admin не выбирает id вручную вне контекста жалобы).
 * Помечает жалобу как обработанную. Сама переписка/остальные сообщения
 * не читаются.
 */
function bober_dm_admin_delete_reported_message($conn, $reportId, $adminLabel = 'admin')
{
    $reportId = max(0, (int) $reportId);
    if ($reportId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор жалобы.');
    }

    $stmt = $conn->prepare('SELECT message_id, conversation_id FROM player_message_reports WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось найти жалобу.');
    }
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Жалоба не найдена.');
    }

    $messageId = $row['message_id'] !== null ? (int) $row['message_id'] : 0;
    if ($messageId > 0) {
        $deleteStmt = $conn->prepare('DELETE FROM player_direct_messages WHERE id = ? AND conversation_id = ?');
        if (!$deleteStmt) {
            throw new RuntimeException('Не удалось удалить сообщение.');
        }
        $conversationId = (int) $row['conversation_id'];
        $deleteStmt->bind_param('ii', $messageId, $conversationId);
        $deleteStmt->execute();
        $deleteStmt->close();
    }

    $updateStmt = $conn->prepare("UPDATE player_message_reports SET status = 'resolved', message_deleted_at = NOW() WHERE id = ?");
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось обновить жалобу.');
    }
    $updateStmt->bind_param('i', $reportId);
    $updateStmt->execute();
    $updateStmt->close();
}

/**
 * Отклоняет жалобу без удаления сообщения (например, если жалоба
 * необоснованна).
 */
function bober_dm_admin_dismiss_report($conn, $reportId)
{
    $reportId = max(0, (int) $reportId);
    if ($reportId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор жалобы.');
    }

    $updateStmt = $conn->prepare("UPDATE player_message_reports SET status = 'dismissed' WHERE id = ?");
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось обновить жалобу.');
    }
    $updateStmt->bind_param('i', $reportId);
    $updateStmt->execute();
    $updateStmt->close();
}

/**
 * Ставит админский мут игроку: 'all' — запрет писать кому-либо, 'pair' —
 * запрет писать конкретному собеседнику (targetUserId обязателен для pair).
 */
function bober_dm_admin_mute_user($conn, $userId, $scope, $targetUserId, $reason, $adminLabel = 'admin')
{
    $userId = max(0, (int) $userId);
    $scope = ($scope === 'pair') ? 'pair' : 'all';
    $targetUserId = max(0, (int) $targetUserId);
    $reason = trim((string) $reason);
    if (mb_strlen($reason) > 500) {
        $reason = mb_substr($reason, 0, 500);
    }

    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор игрока.');
    }
    if ($scope === 'pair' && $targetUserId < 1) {
        throw new InvalidArgumentException('Для мута по собеседнику нужно указать его id.');
    }

    $insertStmt = $conn->prepare('INSERT INTO player_dm_admin_mutes (user_id, scope, target_user_id, reason, created_by) VALUES (?, ?, ?, ?, ?)');
    if (!$insertStmt) {
        throw new RuntimeException('Не удалось создать мут.');
    }
    $targetParam = ($scope === 'pair') ? $targetUserId : null;
    $insertStmt->bind_param('isiss', $userId, $scope, $targetParam, $reason, $adminLabel);
    $insertStmt->execute();
    $newId = (int) $conn->insert_id;
    $insertStmt->close();

    return $newId;
}

/**
 * Снимает все админские муты игрока (опционально только по заданному scope).
 */
function bober_dm_admin_unmute_user($conn, $userId, $scope = null)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор игрока.');
    }

    if ($scope === 'all' || $scope === 'pair') {
        $stmt = $conn->prepare('DELETE FROM player_dm_admin_mutes WHERE user_id = ? AND scope = ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось снять мут.');
        }
        $stmt->bind_param('is', $userId, $scope);
    } else {
        $stmt = $conn->prepare('DELETE FROM player_dm_admin_mutes WHERE user_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось снять мут.');
        }
        $stmt->bind_param('i', $userId);
    }
    $stmt->execute();
    $stmt->close();
}

/**
 * Список активных админских мутов игрока (для отображения в его профиле
 * в админке).
 */
function bober_dm_admin_fetch_mutes_for_user($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    $stmt = $conn->prepare('
        SELECT m.id, m.scope, m.target_user_id, m.reason, m.created_by, m.created_at, t.login AS target_login
        FROM player_dm_admin_mutes m
        LEFT JOIN users t ON t.id = m.target_user_id
        WHERE m.user_id = ?
        ORDER BY m.created_at DESC
    ');
    if (!$stmt) {
        throw new RuntimeException('Не удалось получить муты игрока.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'id' => (int) $row['id'],
            'scope' => (string) $row['scope'],
            'targetUserId' => $row['target_user_id'] !== null ? (int) $row['target_user_id'] : null,
            'targetLogin' => $row['target_login'] !== null ? (string) $row['target_login'] : null,
            'reason' => (string) $row['reason'],
            'createdBy' => (string) $row['created_by'],
            'createdAt' => $row['created_at'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Проверяет, замучен ли пользователь админом для отправки сообщения
 * конкретному получателю (учитывает и scope 'all', и 'pair'). Вызывается
 * из bober_dm_send_message перед отправкой.
 */
function bober_dm_admin_is_muted($conn, $userId, $recipientId)
{
    $userId = max(0, (int) $userId);
    $recipientId = max(0, (int) $recipientId);

    $stmt = $conn->prepare("SELECT scope, target_user_id FROM player_dm_admin_mutes WHERE user_id = ? AND (scope = 'all' OR (scope = 'pair' AND target_user_id = ?)) LIMIT 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('ii', $userId, $recipientId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row);
}

/**
 * Полностью включает/отключает функцию личных сообщений у аккаунта.
 */
function bober_dm_admin_set_feature_disabled($conn, $userId, $disabled)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор игрока.');
    }

    $sql = $disabled
        ? 'UPDATE users SET dm_disabled_at = NOW() WHERE id = ?'
        : 'UPDATE users SET dm_disabled_at = NULL WHERE id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось обновить доступ к личным сообщениям.');
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Проверяет, отключена ли функция личных сообщений у аккаунта админом.
 */
function bober_dm_admin_is_feature_disabled($conn, $userId)
{
    $userId = max(0, (int) $userId);
    $stmt = $conn->prepare('SELECT dm_disabled_at FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) && $row['dm_disabled_at'] !== null;
}

/**
 * Общая статистика по P2P-чатам для дашборда админки: кол-во активных
 * диалогов, сообщений за всё время, новых жалоб за период (по умолчанию 7 дней).
 */
function bober_dm_admin_fetch_stats($conn, $reportDays = 7)
{
    $reportDays = max(1, (int) $reportDays);

    $stats = [
        'totalConversations' => 0,
        'totalMessages' => 0,
        'reportsPeriod' => 0,
        'reportsPending' => 0,
        'reportDays' => $reportDays,
    ];

    $result = $conn->query('SELECT COUNT(*) AS cnt FROM player_conversations');
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stats['totalConversations'] = is_array($row) ? (int) $row['cnt'] : 0;

    $result = $conn->query('SELECT COUNT(*) AS cnt FROM player_direct_messages');
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stats['totalMessages'] = is_array($row) ? (int) $row['cnt'] : 0;

    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM player_message_reports WHERE created_at >= (NOW() - INTERVAL ? DAY)');
    if ($stmt) {
        $stmt->bind_param('i', $reportDays);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        $stats['reportsPeriod'] = is_array($row) ? (int) $row['cnt'] : 0;
    }

    $result = $conn->query("SELECT COUNT(*) AS cnt FROM player_message_reports WHERE status = 'new'");
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stats['reportsPending'] = is_array($row) ? (int) $row['cnt'] : 0;

    return $stats;
}

/**
 * Личная статистика игрока по его переписке (для отображения ему самому
 * в профиле) — сколько у него диалогов и сколько сообщений он отправил.
 */
function bober_dm_fetch_own_stats($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $stats = ['conversationCount' => 0, 'messagesSent' => 0];

    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM player_conversations WHERE user_low_id = ? OR user_high_id = ?');
    if ($stmt) {
        $stmt->bind_param('ii', $userId, $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        $stats['conversationCount'] = is_array($row) ? (int) $row['cnt'] : 0;
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM player_direct_messages WHERE sender_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
        $stats['messagesSent'] = is_array($row) ? (int) $row['cnt'] : 0;
    }

    return $stats;
}

/* ===================== Слова автомодерации (управление из админки) ===================== */

/**
 * Список всех слов автомодерации для админки (включая выключенные).
 */
function bober_dm_admin_fetch_moderation_words($conn)
{
    $result = $conn->query('SELECT id, pattern, replacement, enabled, sort_order, created_at FROM dm_moderation_words ORDER BY sort_order ASC, id ASC');
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'id' => (int) $row['id'],
            'pattern' => (string) $row['pattern'],
            'replacement' => (string) $row['replacement'],
            'enabled' => (bool) $row['enabled'],
            'sortOrder' => (int) $row['sort_order'],
            'createdAt' => $row['created_at'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $rows;
}

/**
 * Добавляет новое слово (паттерн) автомодерации. Паттерн — регулярка без
 * разделителей (тот же формат, что был в прежнем захардкоженном массиве),
 * например 'дебил\S*'. Простое слово без \S* тоже допустимо для точного
 * совпадения.
 */
function bober_dm_admin_add_moderation_word($conn, $pattern, $replacement)
{
    $pattern = trim((string) $pattern);
    $replacement = trim((string) $replacement);

    if ($pattern === '') {
        throw new InvalidArgumentException('Укажите слово или паттерн.');
    }
    if ($replacement === '') {
        throw new InvalidArgumentException('Укажите вежливый аналог замены.');
    }
    if (mb_strlen($pattern) > 255 || mb_strlen($replacement) > 255) {
        throw new InvalidArgumentException('Слишком длинное слово или замена.');
    }

    // Проверяем, что регулярка вообще валидна, чтобы не сломать
    // bober_moderate_text() при следующей отправке сообщения.
    if (@preg_match('/(' . $pattern . ')/iu', '') === false) {
        throw new InvalidArgumentException('Некорректный паттерн — проверьте синтаксис регулярного выражения.');
    }

    $maxOrderResult = $conn->query('SELECT COALESCE(MAX(sort_order), 0) AS maxOrder FROM dm_moderation_words');
    $maxOrderRow = $maxOrderResult ? $maxOrderResult->fetch_assoc() : null;
    if ($maxOrderResult instanceof mysqli_result) {
        $maxOrderResult->free();
    }
    $nextOrder = (is_array($maxOrderRow) ? (int) $maxOrderRow['maxOrder'] : 0) + 1;

    $stmt = $conn->prepare('INSERT INTO dm_moderation_words (pattern, replacement, sort_order) VALUES (?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось добавить слово.');
    }
    $stmt->bind_param('ssi', $pattern, $replacement, $nextOrder);
    if (!$stmt->execute()) {
        $stmt->close();
        if ($conn->errno === 1062) {
            throw new RuntimeException('Такое слово/паттерн уже есть в списке.');
        }
        throw new RuntimeException('Не удалось добавить слово.');
    }
    $newId = (int) $conn->insert_id;
    $stmt->close();

    return $newId;
}

/**
 * Включает/выключает слово без удаления (например, временно отключить
 * замену, если она вызывает ложные срабатывания).
 */
function bober_dm_admin_set_moderation_word_enabled($conn, $wordId, $enabled)
{
    $wordId = max(0, (int) $wordId);
    if ($wordId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор слова.');
    }

    $enabledValue = $enabled ? 1 : 0;
    $stmt = $conn->prepare('UPDATE dm_moderation_words SET enabled = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось обновить слово.');
    }
    $stmt->bind_param('ii', $enabledValue, $wordId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Удаляет слово автомодерации целиком.
 */
function bober_dm_admin_delete_moderation_word($conn, $wordId)
{
    $wordId = max(0, (int) $wordId);
    if ($wordId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор слова.');
    }

    $stmt = $conn->prepare('DELETE FROM dm_moderation_words WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось удалить слово.');
    }
    $stmt->bind_param('i', $wordId);
    $stmt->execute();
    $stmt->close();
}
