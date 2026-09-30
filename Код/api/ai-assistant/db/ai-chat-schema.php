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

    // Индивидуальные настройки доступа/лимитов/платности для конкретного игрока.
    // Отсутствие строки для user_id означает "все дефолты из ai_chat_global_settings".
    // is_paid_unlocked здесь означает "бесплатный доступ для этого игрока даже
    // при включённом глобальном платном режиме" (индивидуальное исключение).
    $createUserSettingsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_user_settings` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `is_blocked` TINYINT(1) NOT NULL DEFAULT 0,
    `is_paid_unlocked` TINYINT(1) NOT NULL DEFAULT 0,
    `custom_message_limit_per_hour` INT NULL DEFAULT NULL,
    `custom_action_limit_per_hour` INT NULL DEFAULT NULL,
    `admin_note` VARCHAR(500) NOT NULL DEFAULT '',
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserSettingsSql)) {
        throw new RuntimeException('Не удалось создать таблицу настроек доступа к ИИ-чату.');
    }

    // Старые инсталляции могли создать таблицу ещё с колонкой paid_until —
    // на новых серверах её просто не будет, поэтому проверяем перед удалением.
    if (bober_column_exists($conn, 'ai_chat_user_settings', 'paid_until')) {
        $conn->query('ALTER TABLE `ai_chat_user_settings` DROP COLUMN `paid_until`');
    }

    // Глобальные дефолтные лимиты и режим доступа (free-for-all / paid-only).
    // paid_price_coins — базовая цена ОДНОГО сообщения чата в монетах (до
    // умножения на экономический индекс игрока). Хранится одной строкой
    // с id=1 — упрощённый key-value для одного набора настроек.
    $createGlobalSettingsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_global_settings` (
    `id` TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    `access_mode` VARCHAR(16) NOT NULL DEFAULT 'free',
    `default_message_limit_per_hour` INT NOT NULL DEFAULT 15,
    `default_action_limit_per_hour` INT NOT NULL DEFAULT 5,
    `paid_price_coins` INT NOT NULL DEFAULT 300,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createGlobalSettingsSql)) {
        throw new RuntimeException('Не удалось создать таблицу глобальных настроек ИИ-чата.');
    }

    if (bober_column_exists($conn, 'ai_chat_global_settings', 'paid_duration_days')) {
        $conn->query('ALTER TABLE `ai_chat_global_settings` DROP COLUMN `paid_duration_days`');
    }

    $conn->query('INSERT IGNORE INTO `ai_chat_global_settings` (`id`) VALUES (1)');

    // Лог списаний монет за сообщения ИИ-чата — для админки/статистики.
    $createChargesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ai_chat_charges` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `session_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `amount_coins` INT NOT NULL DEFAULT 0,
    `balance_after` INT NOT NULL DEFAULT 0,
    `economy_index` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_ai_chat_charges_user` (`user_id`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createChargesSql)) {
        throw new RuntimeException('Не удалось создать таблицу списаний ИИ-чата.');
    }

    if (!bober_index_exists($conn, 'ai_chat_charges', 'idx_ai_chat_charges_user') && !$conn->query("CREATE INDEX `idx_ai_chat_charges_user` ON `ai_chat_charges` (`user_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс списаний ИИ-чата.');
    }

    $schemaEnsured = true;
}

/**
 * Возвращает глобальные настройки ИИ-чата (режим доступа, дефолтные лимиты,
 * цена и длительность платного доступа). Всегда одна строка (id=1).
 */
function bober_ai_get_global_settings($conn)
{
    $result = $conn->query('SELECT access_mode, default_message_limit_per_hour, default_action_limit_per_hour, paid_price_coins FROM ai_chat_global_settings WHERE id = 1 LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }

    if (!is_array($row)) {
        return [
            'accessMode' => 'free',
            'defaultMessageLimitPerHour' => 15,
            'defaultActionLimitPerHour' => 5,
            'paidPriceCoins' => 300,
        ];
    }

    return [
        'accessMode' => (string) $row['access_mode'],
        'defaultMessageLimitPerHour' => max(1, (int) $row['default_message_limit_per_hour']),
        'defaultActionLimitPerHour' => max(1, (int) $row['default_action_limit_per_hour']),
        'paidPriceCoins' => max(0, (int) $row['paid_price_coins']),
    ];
}

function bober_ai_update_global_settings($conn, array $settings)
{
    $accessMode = in_array($settings['accessMode'] ?? '', ['free', 'paid_only'], true) ? $settings['accessMode'] : 'free';
    $messageLimit = max(1, (int) ($settings['defaultMessageLimitPerHour'] ?? 15));
    $actionLimit = max(1, (int) ($settings['defaultActionLimitPerHour'] ?? 5));
    $priceCoins = max(0, (int) ($settings['paidPriceCoins'] ?? 300));

    $stmt = $conn->prepare('UPDATE ai_chat_global_settings SET access_mode = ?, default_message_limit_per_hour = ?, default_action_limit_per_hour = ?, paid_price_coins = ? WHERE id = 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось обновить глобальные настройки ИИ-чата.');
    }
    $stmt->bind_param('siii', $accessMode, $messageLimit, $actionLimit, $priceCoins);
    $stmt->execute();
    $stmt->close();

    return bober_ai_get_global_settings($conn);
}

/**
 * Возвращает индивидуальные настройки доступа игрока (или null, если для
 * него не задано никаких персональных отклонений от глобальных дефолтов).
 */
function bober_ai_get_user_settings($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return null;
    }

    $stmt = $conn->prepare('SELECT user_id, is_blocked, is_paid_unlocked, custom_message_limit_per_hour, custom_action_limit_per_hour, admin_note FROM ai_chat_user_settings WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        return null;
    }

    return [
        'userId' => (int) $row['user_id'],
        'isBlocked' => (bool) $row['is_blocked'],
        // "Освобождён от платы" — индивидуальное исключение: бесплатный доступ
        // для этого игрока, даже когда глобально включён платный режим.
        'isPaidUnlocked' => (bool) $row['is_paid_unlocked'],
        'customMessageLimitPerHour' => $row['custom_message_limit_per_hour'] !== null ? (int) $row['custom_message_limit_per_hour'] : null,
        'customActionLimitPerHour' => $row['custom_action_limit_per_hour'] !== null ? (int) $row['custom_action_limit_per_hour'] : null,
        'adminNote' => (string) $row['admin_note'],
    ];
}

/**
 * Сохраняет (создаёт или обновляет) индивидуальные настройки игрока.
 * Используется из админки.
 */
function bober_ai_set_user_settings($conn, $userId, array $settings)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $isBlocked = !empty($settings['isBlocked']) ? 1 : 0;
    $isPaidUnlocked = !empty($settings['isPaidUnlocked']) ? 1 : 0;
    $customMessageLimit = isset($settings['customMessageLimitPerHour']) && $settings['customMessageLimitPerHour'] !== null
        ? max(0, (int) $settings['customMessageLimitPerHour'])
        : null;
    $customActionLimit = isset($settings['customActionLimitPerHour']) && $settings['customActionLimitPerHour'] !== null
        ? max(0, (int) $settings['customActionLimitPerHour'])
        : null;
    $adminNote = mb_substr(trim((string) ($settings['adminNote'] ?? '')), 0, 500);

    $stmt = $conn->prepare(<<<SQL
INSERT INTO ai_chat_user_settings
    (user_id, is_blocked, is_paid_unlocked, custom_message_limit_per_hour, custom_action_limit_per_hour, admin_note)
VALUES (?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    is_blocked = VALUES(is_blocked),
    is_paid_unlocked = VALUES(is_paid_unlocked),
    custom_message_limit_per_hour = VALUES(custom_message_limit_per_hour),
    custom_action_limit_per_hour = VALUES(custom_action_limit_per_hour),
    admin_note = VALUES(admin_note)
SQL
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось сохранить настройки доступа к ИИ-чату.');
    }
    $stmt->bind_param('iiiiis', $userId, $isBlocked, $isPaidUnlocked, $customMessageLimit, $customActionLimit, $adminNote);
    $stmt->execute();
    $stmt->close();

    return bober_ai_get_user_settings($conn, $userId);
}

/**
 * Сводная точка правды: заблокирован ли доступ игрока к ИИ прямо сейчас,
 * какие лимиты сообщений/действий к нему применяются, и должен ли он
 * платить монетами за каждое сообщение (с учётом индивидуальных настроек
 * и глобального режима доступа).
 */
function bober_ai_resolve_access($conn, $userId)
{
    $globalSettings = bober_ai_get_global_settings($conn);
    $userSettings = bober_ai_get_user_settings($conn, $userId);

    if ($userSettings !== null && $userSettings['isBlocked']) {
        return [
            'allowed' => false,
            'reason' => 'blocked',
            'messageLimitPerHour' => 0,
            'actionLimitPerHour' => 0,
            'mustPay' => false,
        ];
    }

    // Индивидуальное исключение: этот игрок освобождён от платы админом,
    // даже если глобально включён платный режим.
    $isExemptFromPaying = $userSettings !== null && $userSettings['isPaidUnlocked'];
    $mustPay = ($globalSettings['accessMode'] === 'paid_only') && !$isExemptFromPaying;

    $messageLimit = $globalSettings['defaultMessageLimitPerHour'];
    $actionLimit = $globalSettings['defaultActionLimitPerHour'];

    if ($userSettings !== null) {
        if ($userSettings['customMessageLimitPerHour'] !== null) {
            $messageLimit = $userSettings['customMessageLimitPerHour'];
        }
        if ($userSettings['customActionLimitPerHour'] !== null) {
            $actionLimit = $userSettings['customActionLimitPerHour'];
        }
    }

    return [
        'allowed' => true,
        'reason' => 'ok',
        'messageLimitPerHour' => max(0, $messageLimit),
        'actionLimitPerHour' => max(0, $actionLimit),
        'mustPay' => $mustPay,
        'basePriceCoins' => $globalSettings['paidPriceCoins'],
    ];
}

/**
 * Списывает у игрока цену одного сообщения ИИ-чата (базовая цена, умноженная
 * на его текущий экономический индекс — как апгрейды/бустеры) и логирует
 * списание. Атомарно: блокирует строку пользователя, проверяет баланс,
 * списывает и коммитит в одной транзакции. Бросает исключение, если монет
 * не хватает — вызывающий код должен отменить отправку сообщения.
 * Возвращает ['charged' => int, 'balanceAfter' => int].
 */
function bober_ai_charge_message_fee($conn, $userId, $sessionId, $basePriceCoins)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $basePriceCoins = max(0, (int) $basePriceCoins);
    if ($basePriceCoins < 1) {
        return ['charged' => 0, 'balanceAfter' => null];
    }

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare('SELECT score FROM users WHERE id = ? LIMIT 1 FOR UPDATE');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить проверку баланса.');
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();

        if (!is_array($row)) {
            throw new RuntimeException('Пользователь не найден.');
        }

        $currentScore = max(0, (int) $row['score']);
        $economyProfile = bober_build_user_economy_profile(['score' => $currentScore]);
        $effectivePrice = bober_calculate_effective_purchase_price($basePriceCoins, $economyProfile, 'shop');

        if ($currentScore < $effectivePrice) {
            throw new RuntimeException('Не хватает монет для сообщения бобру.');
        }

        $nextScore = $currentScore - $effectivePrice;

        $updateStmt = $conn->prepare('UPDATE users SET score = ? WHERE id = ?');
        if (!$updateStmt) {
            throw new RuntimeException('Не удалось списать монеты.');
        }
        $updateStmt->bind_param('ii', $nextScore, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new RuntimeException('Не удалось списать монеты.');
        }
        $updateStmt->close();

        $logStmt = $conn->prepare('INSERT INTO ai_chat_charges (user_id, session_id, amount_coins, balance_after, economy_index) VALUES (?, ?, ?, ?, ?)');
        if ($logStmt) {
            $sessionIdValue = $sessionId !== null ? (int) $sessionId : null;
            $economyIndex = (int) ($economyProfile['index'] ?? 0);
            $logStmt->bind_param('iiiii', $userId, $sessionIdValue, $effectivePrice, $nextScore, $economyIndex);
            $logStmt->execute();
            $logStmt->close();
        }

        $conn->commit();

        return ['charged' => $effectivePrice, 'balanceAfter' => $nextScore];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

/**
 * Считает текущую эффективную цену одного сообщения для игрока (базовая
 * цена × его экономический множитель) — не списывает, только показывает.
 */
function bober_ai_calculate_message_price($conn, $userId, $basePriceCoins)
{
    $userId = max(0, (int) $userId);
    $basePriceCoins = max(0, (int) $basePriceCoins);
    if ($basePriceCoins < 1 || $userId < 1) {
        return 0;
    }

    $stmt = $conn->prepare('SELECT score FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return $basePriceCoins;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $score = is_array($row) ? max(0, (int) $row['score']) : 0;
    $economyProfile = bober_build_user_economy_profile(['score' => $score]);

    return bober_calculate_effective_purchase_price($basePriceCoins, $economyProfile, 'shop');
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
function bober_ai_get_or_create_session($conn, $userId, $forceNew = false)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    if (!$forceNew) {
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

/* ==================== Функции для админки ==================== */

/**
 * Список последних сессий чата (для админки): логин игрока, когда была
 * активна, сколько сообщений, короткий превью последнего сообщения.
 */
function bober_ai_admin_fetch_sessions($conn, array $options = [])
{
    $limit = max(1, min(200, (int) ($options['limit'] ?? 60)));
    $search = trim((string) ($options['search'] ?? ''));

    $whereClause = '';
    $params = [];
    $types = '';

    if ($search !== '') {
        $whereClause = 'WHERE u.login LIKE ?';
        $params[] = '%' . $search . '%';
        $types .= 's';
    }

    $sql = "
        SELECT
            s.id AS session_id,
            s.user_id,
            u.login,
            s.started_at,
            s.last_message_at,
            (SELECT COUNT(*) FROM ai_chat_messages m WHERE m.session_id = s.id) AS message_count,
            (
                SELECT m2.content FROM ai_chat_messages m2
                WHERE m2.session_id = s.id AND m2.role IN ('user', 'assistant')
                ORDER BY m2.id DESC LIMIT 1
            ) AS last_message_preview
        FROM ai_chat_sessions s
        LEFT JOIN users u ON u.id = s.user_id
        {$whereClause}
        ORDER BY s.last_message_at DESC
        LIMIT ?
    ";

    $params[] = $limit;
    $types .= 'i';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить запрос списка сессий ИИ-чата.');
    }
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $preview = (string) ($row['last_message_preview'] ?? '');
        $rows[] = [
            'sessionId' => (int) $row['session_id'],
            'userId' => (int) $row['user_id'],
            'login' => (string) ($row['login'] ?? ''),
            'startedAt' => $row['started_at'],
            'lastMessageAt' => $row['last_message_at'],
            'messageCount' => (int) $row['message_count'],
            'lastMessagePreview' => mb_substr($preview, 0, 140),
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Полная переписка одной сессии чата (для админки) — все сообщения по порядку.
 */
function bober_ai_admin_fetch_session_full($conn, $sessionId)
{
    $sessionId = max(0, (int) $sessionId);
    if ($sessionId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор сессии.');
    }

    $sessionStmt = $conn->prepare('SELECT s.id, s.user_id, u.login, s.started_at, s.last_message_at FROM ai_chat_sessions s LEFT JOIN users u ON u.id = s.user_id WHERE s.id = ? LIMIT 1');
    if (!$sessionStmt) {
        throw new RuntimeException('Не удалось найти сессию чата.');
    }
    $sessionStmt->bind_param('i', $sessionId);
    $sessionStmt->execute();
    $sessionResult = $sessionStmt->get_result();
    $sessionRow = $sessionResult ? $sessionResult->fetch_assoc() : null;
    if ($sessionResult instanceof mysqli_result) {
        $sessionResult->free();
    }
    $sessionStmt->close();

    if (!is_array($sessionRow)) {
        return null;
    }

    $messagesStmt = $conn->prepare('SELECT role, content, tool_name, created_at FROM ai_chat_messages WHERE session_id = ? ORDER BY id ASC');
    if (!$messagesStmt) {
        throw new RuntimeException('Не удалось получить сообщения сессии.');
    }
    $messagesStmt->bind_param('i', $sessionId);
    $messagesStmt->execute();
    $messagesResult = $messagesStmt->get_result();
    $messages = [];
    while ($messagesResult && ($row = $messagesResult->fetch_assoc())) {
        $messages[] = [
            'role' => (string) $row['role'],
            'content' => (string) $row['content'],
            'toolName' => $row['tool_name'],
            'createdAt' => $row['created_at'],
        ];
    }
    if ($messagesResult instanceof mysqli_result) {
        $messagesResult->free();
    }
    $messagesStmt->close();

    return [
        'sessionId' => (int) $sessionRow['id'],
        'userId' => (int) $sessionRow['user_id'],
        'login' => (string) ($sessionRow['login'] ?? ''),
        'startedAt' => $sessionRow['started_at'],
        'lastMessageAt' => $sessionRow['last_message_at'],
        'messages' => $messages,
    ];
}

/**
 * Список всех игроков с индивидуальными настройками ИИ (блок/платность/лимиты) —
 * для вкладки "Доступ и лимиты" в админке. Только те, у кого есть отклонение
 * от дефолтов (иначе список был бы равен списку всех пользователей).
 */
function bober_ai_admin_fetch_user_settings_list($conn, array $options = [])
{
    $limit = max(1, min(200, (int) ($options['limit'] ?? 100)));

    $stmt = $conn->prepare('
        SELECT s.user_id, u.login, s.is_blocked, s.is_paid_unlocked,
               s.custom_message_limit_per_hour, s.custom_action_limit_per_hour, s.admin_note, s.updated_at
        FROM ai_chat_user_settings s
        LEFT JOIN users u ON u.id = s.user_id
        ORDER BY s.updated_at DESC
        LIMIT ?
    ');
    if (!$stmt) {
        throw new RuntimeException('Не удалось получить список настроек доступа к ИИ.');
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $rows[] = [
            'userId' => (int) $row['user_id'],
            'login' => (string) ($row['login'] ?? ''),
            'isBlocked' => (bool) $row['is_blocked'],
            'isPaidUnlocked' => (bool) $row['is_paid_unlocked'],
            'customMessageLimitPerHour' => $row['custom_message_limit_per_hour'] !== null ? (int) $row['custom_message_limit_per_hour'] : null,
            'customActionLimitPerHour' => $row['custom_action_limit_per_hour'] !== null ? (int) $row['custom_action_limit_per_hour'] : null,
            'adminNote' => (string) $row['admin_note'],
            'updatedAt' => $row['updated_at'],
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $rows;
}

/**
 * Находит user_id по логину — используется в админке, чтобы найти игрока
 * перед изменением его настроек доступа к ИИ.
 */
function bober_ai_admin_find_user_id_by_login($conn, $login)
{
    $login = trim((string) $login);
    if ($login === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT id FROM users WHERE login = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('s', $login);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return is_array($row) ? (int) $row['id'] : null;
}
