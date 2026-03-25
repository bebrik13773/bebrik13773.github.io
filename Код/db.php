<?php

if (defined('BOBER_DB_BOOTSTRAP_LOADED')) {
    return;
}

define('BOBER_DB_BOOTSTRAP_LOADED', true);

function bober_load_config()
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $config = [
        'db_host' => null,
        'db_user' => null,
        'db_pass' => null,
        'db_name' => null,
        'admin_password_hash' => null,
        'admin_initial_password' => null,
    ];

    $configFile = __DIR__ . '/db_config.php';
    if (is_file($configFile)) {
        $loaded = require $configFile;
        if (is_array($loaded)) {
            $config = array_merge($config, array_intersect_key($loaded, $config));
        }
    }

    $envMap = [
        'db_host' => 'BOBER_DB_HOST',
        'db_user' => 'BOBER_DB_USER',
        'db_pass' => 'BOBER_DB_PASS',
        'db_name' => 'BOBER_DB_NAME',
        'admin_password_hash' => 'BOBER_ADMIN_PASSWORD_HASH',
        'admin_initial_password' => 'BOBER_ADMIN_INITIAL_PASSWORD',
    ];

    foreach ($envMap as $key => $envName) {
        $value = getenv($envName);
        if ($value !== false && $value !== '') {
            $config[$key] = $value;
        }
    }

    return $config;
}

function bober_json_response($payload, $statusCode = 200)
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function bober_exception_message($error, $fallback = 'Ошибка сервера.')
{
    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        $message = trim((string) $error->getMessage());
        if ($message !== '') {
            return $message;
        }
    }

    return $fallback;
}

function bober_read_json_request()
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

function bober_start_session()
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $lifetime = 30 * 24 * 60 * 60;
    $isSecure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    ini_set('session.gc_maxlifetime', (string) $lifetime);

    if (!headers_sent()) {
        session_name('BOBERSESSID');
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    session_start();
}

function bober_login_user($userId, $login = '')
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    bober_start_session();
    session_regenerate_id(true);

    $_SESSION['game_user_id'] = $userId;
    $_SESSION['game_login'] = trim((string) $login);
    $_SESSION['game_last_seen_at'] = time();
}

function bober_get_logged_in_user_id()
{
    bober_start_session();

    $userId = max(0, (int) ($_SESSION['game_user_id'] ?? 0));
    if ($userId < 1) {
        return null;
    }

    $_SESSION['game_last_seen_at'] = time();

    return $userId;
}

function bober_logout_user()
{
    bober_start_session();

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'] ?? '/',
            $params['domain'] ?? '',
            !empty($params['secure']),
            !empty($params['httponly'])
        );
    }

    session_destroy();
}

function bober_db_connect($withDatabase = true)
{
    $config = bober_load_config();

    foreach (['db_host', 'db_user', 'db_name'] as $key) {
        if (!isset($config[$key]) || $config[$key] === null || $config[$key] === '') {
            throw new RuntimeException('Параметры базы данных не настроены. Создайте `Код/db_config.php` или задайте переменные окружения `BOBER_DB_*`.');
        }
    }

    $dbPass = isset($config['db_pass']) && $config['db_pass'] !== null ? $config['db_pass'] : '';

    if ($withDatabase) {
        $conn = new mysqli($config['db_host'], $config['db_user'], $dbPass, $config['db_name']);
    } else {
        $conn = new mysqli($config['db_host'], $config['db_user'], $dbPass);
    }

    if ($conn->connect_error) {
        throw new RuntimeException('Не удалось подключиться к базе данных.');
    }

    if (!$conn->set_charset('utf8mb4')) {
        throw new RuntimeException('Не удалось установить кодировку базы данных.');
    }

    return $conn;
}

function bober_identifier_is_valid($identifier)
{
    return is_string($identifier) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) === 1;
}

function bober_require_identifier($identifier, $label = 'Идентификатор')
{
    if (!bober_identifier_is_valid($identifier)) {
        throw new InvalidArgumentException($label . ' содержит недопустимые символы.');
    }

    return $identifier;
}

function bober_default_skin_state()
{
    return [
        'skins/bober.png',
        true,
        false,
        true,
        false,
        false,
        false,
        false,
        false,
    ];
}

function bober_default_skin_json()
{
    return json_encode(bober_default_skin_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bober_normalize_skin_json($skin)
{
    $decoded = null;

    if (is_array($skin)) {
        $decoded = $skin;
    } elseif (is_string($skin) && trim($skin) !== '') {
        $candidate = json_decode($skin, true);
        if (is_array($candidate)) {
            $decoded = $candidate;
        }
    }

    $defaults = bober_default_skin_state();

    if (!is_array($decoded)) {
        $decoded = $defaults;
    }

    $normalized = $defaults;

    foreach ($decoded as $index => $value) {
        if ($index === 0) {
            $normalized[0] = is_string($value) && $value !== '' ? $value : $defaults[0];
            continue;
        }

        if (is_numeric($index)) {
            $normalized[(int) $index] = (bool) $value;
        }
    }

    ksort($normalized);

    return json_encode(array_values($normalized), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bober_game_login_pattern()
{
    return '/^[A-Za-z0-9_-]{3,10}$/';
}

function bober_is_valid_game_login($login)
{
    return is_string($login) && preg_match(bober_game_login_pattern(), $login) === 1;
}

function bober_require_game_login($login)
{
    $login = trim((string) $login);

    if (!bober_is_valid_game_login($login)) {
        throw new InvalidArgumentException('Логин должен быть длиной от 3 до 10 символов и содержать только английские буквы, цифры, "-" и "_".');
    }

    return $login;
}

function bober_column_exists($conn, $table, $column)
{
    bober_require_identifier($table, 'Имя таблицы');

    $escapedColumn = $conn->real_escape_string($column);
    $result = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$escapedColumn}'");

    if ($result === false) {
        throw new RuntimeException('Не удалось прочитать структуру таблицы.');
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

function bober_index_exists($conn, $table, $indexName)
{
    bober_require_identifier($table, 'Имя таблицы');

    $escapedIndex = $conn->real_escape_string($indexName);
    $result = $conn->query("SHOW INDEX FROM `{$table}` WHERE Key_name = '{$escapedIndex}'");

    if ($result === false) {
        throw new RuntimeException('Не удалось прочитать индексы таблицы.');
    }

    $exists = $result->num_rows > 0;
    $result->free();

    return $exists;
}

function bober_ensure_game_schema($conn)
{
    $createUsersSql = <<<SQL
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `login` VARCHAR(100) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `plus` INT NOT NULL DEFAULT 1,
    `skin` LONGTEXT NULL,
    `energy` INT NOT NULL DEFAULT 5000,
    `last_energy_update` BIGINT NOT NULL DEFAULT 0,
    `ENERGY_MAX` INT NOT NULL DEFAULT 5000,
    `score` BIGINT NOT NULL DEFAULT 0,
    `upgrade_tap_small_count` INT NOT NULL DEFAULT 0,
    `upgrade_tap_big_count` INT NOT NULL DEFAULT 0,
    `upgrade_energy_count` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUsersSql)) {
        throw new RuntimeException('Не удалось создать таблицу пользователей.');
    }

    $alterStatements = [
        'login' => "ALTER TABLE `users` ADD COLUMN `login` VARCHAR(100) NOT NULL DEFAULT '' AFTER `id`",
        'password' => "ALTER TABLE `users` ADD COLUMN `password` VARCHAR(255) NOT NULL DEFAULT '' AFTER `login`",
        'plus' => "ALTER TABLE `users` ADD COLUMN `plus` INT NOT NULL DEFAULT 1 AFTER `password`",
        'skin' => "ALTER TABLE `users` ADD COLUMN `skin` LONGTEXT NULL AFTER `plus`",
        'energy' => "ALTER TABLE `users` ADD COLUMN `energy` INT NOT NULL DEFAULT 5000 AFTER `skin`",
        'last_energy_update' => "ALTER TABLE `users` ADD COLUMN `last_energy_update` BIGINT NOT NULL DEFAULT 0 AFTER `energy`",
        'ENERGY_MAX' => "ALTER TABLE `users` ADD COLUMN `ENERGY_MAX` INT NOT NULL DEFAULT 5000 AFTER `last_energy_update`",
        'score' => "ALTER TABLE `users` ADD COLUMN `score` BIGINT NOT NULL DEFAULT 0 AFTER `ENERGY_MAX`",
        'upgrade_tap_small_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_tap_small_count` INT NOT NULL DEFAULT 0 AFTER `score`",
        'upgrade_tap_big_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_tap_big_count` INT NOT NULL DEFAULT 0 AFTER `upgrade_tap_small_count`",
        'upgrade_energy_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_energy_count` INT NOT NULL DEFAULT 0 AFTER `upgrade_tap_big_count`",
        'created_at' => "ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `upgrade_energy_count`",
        'updated_at' => "ALTER TABLE `users` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($alterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'users', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру таблицы пользователей.');
        }
    }

    if (bober_column_exists($conn, 'users', 'username')) {
        $conn->query("UPDATE `users` SET `login` = `username` WHERE (`login` IS NULL OR `login` = '') AND `username` IS NOT NULL AND `username` <> ''");
    }

    $defaultSkin = $conn->real_escape_string(bober_default_skin_json());

    $normalizationQueries = [
        "UPDATE `users` SET `login` = CONCAT('legacy_user_', `id`) WHERE `login` IS NULL OR `login` = ''",
        "UPDATE `users` SET `skin` = '{$defaultSkin}' WHERE `skin` IS NULL OR `skin` = ''",
        "UPDATE `users` SET `plus` = 1 WHERE `plus` IS NULL OR `plus` < 1",
        "UPDATE `users` SET `ENERGY_MAX` = 5000 WHERE `ENERGY_MAX` IS NULL OR `ENERGY_MAX` < 1",
        "UPDATE `users` SET `energy` = `ENERGY_MAX` WHERE `energy` IS NULL OR `energy` < 0",
        "UPDATE `users` SET `last_energy_update` = 0 WHERE `last_energy_update` IS NULL",
        "UPDATE `users` SET `score` = 0 WHERE `score` IS NULL",
        "UPDATE `users` SET `upgrade_tap_small_count` = 0 WHERE `upgrade_tap_small_count` IS NULL OR `upgrade_tap_small_count` < 0",
        "UPDATE `users` SET `upgrade_tap_big_count` = 0 WHERE `upgrade_tap_big_count` IS NULL OR `upgrade_tap_big_count` < 0",
        "UPDATE `users` SET `upgrade_energy_count` = 0 WHERE `upgrade_energy_count` IS NULL OR `upgrade_energy_count` < 0",
    ];

    foreach ($normalizationQueries as $sql) {
        if (!$conn->query($sql)) {
            throw new RuntimeException('Не удалось нормализовать данные пользователей.');
        }
    }

    if (!bober_index_exists($conn, 'users', 'idx_users_login') && !$conn->query("CREATE INDEX `idx_users_login` ON `users` (`login`)")) {
        throw new RuntimeException('Не удалось создать индекс для логина.');
    }

    if (!bober_index_exists($conn, 'users', 'idx_users_score') && !$conn->query("CREATE INDEX `idx_users_score` ON `users` (`score`)")) {
        throw new RuntimeException('Не удалось создать индекс для рейтинга.');
    }
}

function bober_configured_admin_password_hash()
{
    $config = bober_load_config();

    if (!empty($config['admin_password_hash'])) {
        return $config['admin_password_hash'];
    }

    if (!empty($config['admin_initial_password'])) {
        return password_hash($config['admin_initial_password'], PASSWORD_DEFAULT);
    }

    return null;
}

function bober_admin_is_default_password_hash($hash)
{
    return is_string($hash) && $hash !== '' && password_verify('Gosha123', $hash);
}

function bober_ensure_admin_schema($conn)
{
    $createPassSql = <<<SQL
CREATE TABLE IF NOT EXISTS `pass` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `password_hash` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createPassSql)) {
        throw new RuntimeException('Не удалось создать таблицу админ-доступа.');
    }

    $createAuditSql = <<<SQL
CREATE TABLE IF NOT EXISTS `admin_audit_log` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `admin_name` VARCHAR(100) NOT NULL DEFAULT 'admin',
    `action_type` VARCHAR(100) NOT NULL,
    `target_table` VARCHAR(100) NULL,
    `query_text` LONGTEXT NULL,
    `affected_rows` INT NOT NULL DEFAULT 0,
    `meta_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    $conn->query($createAuditSql);

    $configuredHash = bober_configured_admin_password_hash();
    $result = $conn->query("SELECT `id`, `password_hash` FROM `pass` ORDER BY `id` ASC LIMIT 1");

    if ($result === false) {
        throw new RuntimeException('Не удалось прочитать админ-доступ.');
    }

    $row = $result->fetch_assoc();
    $result->free();

    if (!$row) {
        if ($configuredHash === null) {
            return;
        }

        $stmt = $conn->prepare("INSERT INTO `pass` (`password_hash`) VALUES (?)");
        if (!$stmt) {
            throw new RuntimeException('Не удалось сохранить пароль администратора.');
        }

        $stmt->bind_param('s', $configuredHash);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось сохранить пароль администратора.');
        }

        $stmt->close();
        return;
    }

    if (bober_admin_is_default_password_hash($row['password_hash']) && $configuredHash !== null) {
        $stmt = $conn->prepare("UPDATE `pass` SET `password_hash` = ? WHERE `id` = ?");
        if (!$stmt) {
            throw new RuntimeException('Не удалось обновить пароль администратора.');
        }

        $adminId = (int) $row['id'];
        $stmt->bind_param('si', $configuredHash, $adminId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось обновить пароль администратора.');
        }

        $stmt->close();
    }
}

function bober_fetch_admin_password_hash($conn)
{
    bober_ensure_admin_schema($conn);

    $result = $conn->query("SELECT `password_hash` FROM `pass` ORDER BY `id` ASC LIMIT 1");
    if ($result === false) {
        throw new RuntimeException('Не удалось получить пароль администратора.');
    }

    $row = $result->fetch_assoc();
    $result->free();

    return $row ? $row['password_hash'] : null;
}

function bober_admin_log_action($conn, $actionType, $details = [])
{
    if (!($conn instanceof mysqli)) {
        return;
    }

    $actionType = trim((string) $actionType);
    if ($actionType === '') {
        return;
    }

    $adminName = trim((string) ($details['admin_name'] ?? 'admin'));
    if ($adminName === '') {
        $adminName = 'admin';
    }

    $targetTable = isset($details['target_table']) ? trim((string) $details['target_table']) : null;
    $queryText = isset($details['query_text']) ? trim((string) $details['query_text']) : null;
    $affectedRows = max(0, (int) ($details['affected_rows'] ?? 0));
    $meta = is_array($details['meta'] ?? null) ? $details['meta'] : [];

    $meta['ip'] = $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $meta['user_agent'] = $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare('INSERT INTO `admin_audit_log` (`admin_name`, `action_type`, `target_table`, `query_text`, `affected_rows`, `meta_json`) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('ssssis', $adminName, $actionType, $targetTable, $queryText, $affectedRows, $metaJson);
    $stmt->execute();
    $stmt->close();
}

function bober_ensure_security_schema($conn)
{
    $createUserBansSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_bans` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'autoclicker',
    `reason` VARCHAR(255) NOT NULL DEFAULT '',
    `duration_days` INT NOT NULL DEFAULT 5,
    `is_repeat` TINYINT(1) NOT NULL DEFAULT 0,
    `detected_by` VARCHAR(50) NOT NULL DEFAULT 'system',
    `ban_until` DATETIME NOT NULL,
    `lifted_at` DATETIME NULL DEFAULT NULL,
    `meta_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserBansSql)) {
        throw new RuntimeException('Не удалось создать таблицу банов.');
    }

    if (!bober_index_exists($conn, 'user_bans', 'idx_user_bans_user_id') && !$conn->query("CREATE INDEX `idx_user_bans_user_id` ON `user_bans` (`user_id`)")) {
        throw new RuntimeException('Не удалось создать индекс user_bans.user_id.');
    }

    if (!bober_index_exists($conn, 'user_bans', 'idx_user_bans_active') && !$conn->query("CREATE INDEX `idx_user_bans_active` ON `user_bans` (`user_id`, `ban_until`, `lifted_at`)")) {
        throw new RuntimeException('Не удалось создать индекс активности банов.');
    }

    if (!bober_index_exists($conn, 'user_bans', 'idx_user_bans_source') && !$conn->query("CREATE INDEX `idx_user_bans_source` ON `user_bans` (`source`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс источника банов.');
    }

    $createUserIpHistorySql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_ip_history` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `ip_address` VARCHAR(64) NOT NULL,
    `login_count` INT NOT NULL DEFAULT 1,
    `first_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserIpHistorySql)) {
        throw new RuntimeException('Не удалось создать таблицу истории IP.');
    }

    if (!bober_index_exists($conn, 'user_ip_history', 'uniq_user_ip_history_user_ip') && !$conn->query("CREATE UNIQUE INDEX `uniq_user_ip_history_user_ip` ON `user_ip_history` (`user_id`, `ip_address`)")) {
        throw new RuntimeException('Не удалось создать уникальный индекс истории IP.');
    }

    if (!bober_index_exists($conn, 'user_ip_history', 'idx_user_ip_history_ip') && !$conn->query("CREATE INDEX `idx_user_ip_history_ip` ON `user_ip_history` (`ip_address`)")) {
        throw new RuntimeException('Не удалось создать индекс истории IP по адресу.');
    }

    if (!bober_index_exists($conn, 'user_ip_history', 'idx_user_ip_history_user') && !$conn->query("CREATE INDEX `idx_user_ip_history_user` ON `user_ip_history` (`user_id`, `last_seen_at`)")) {
        throw new RuntimeException('Не удалось создать индекс истории IP по пользователю.');
    }

    $createIpBansSql = <<<SQL
CREATE TABLE IF NOT EXISTS `ip_bans` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(64) NOT NULL,
    `source_user_id` INT NOT NULL,
    `source_user_ban_id` BIGINT NULL DEFAULT NULL,
    `reason` VARCHAR(255) NOT NULL DEFAULT '',
    `ban_until` DATETIME NOT NULL,
    `lifted_at` DATETIME NULL DEFAULT NULL,
    `meta_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createIpBansSql)) {
        throw new RuntimeException('Не удалось создать таблицу банов по IP.');
    }

    if (!bober_index_exists($conn, 'ip_bans', 'idx_ip_bans_ip_active') && !$conn->query("CREATE INDEX `idx_ip_bans_ip_active` ON `ip_bans` (`ip_address`, `ban_until`, `lifted_at`)")) {
        throw new RuntimeException('Не удалось создать индекс активности банов по IP.');
    }

    if (!bober_index_exists($conn, 'ip_bans', 'idx_ip_bans_source_user') && !$conn->query("CREATE INDEX `idx_ip_bans_source_user` ON `ip_bans` (`source_user_id`, `ban_until`, `lifted_at`)")) {
        throw new RuntimeException('Не удалось создать индекс банов по исходному пользователю.');
    }

    if (!bober_index_exists($conn, 'ip_bans', 'idx_ip_bans_source_ban') && !$conn->query("CREATE INDEX `idx_ip_bans_source_ban` ON `ip_bans` (`source_user_ban_id`)")) {
        throw new RuntimeException('Не удалось создать индекс банов по исходному бану.');
    }
}

function bober_ensure_fly_beaver_schema($conn)
{
    $createFlyProgressSql = <<<SQL
CREATE TABLE IF NOT EXISTS `fly_beaver_progress` (
    `user_id` INT PRIMARY KEY,
    `best_score` INT NOT NULL DEFAULT 0,
    `last_score` INT NOT NULL DEFAULT 0,
    `last_level` INT NOT NULL DEFAULT 1,
    `games_played` INT NOT NULL DEFAULT 0,
    `total_score` BIGINT NOT NULL DEFAULT 0,
    `pending_transfer_score` BIGINT NOT NULL DEFAULT 0,
    `transferred_total_score` BIGINT NOT NULL DEFAULT 0,
    `transfer_window_started_at` TIMESTAMP NULL DEFAULT NULL,
    `transfer_window_coins` BIGINT NOT NULL DEFAULT 0,
    `last_played_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createFlyProgressSql)) {
        throw new RuntimeException('Не удалось создать таблицу прогресса fly-beaver.');
    }

    $flyProgressAlterStatements = [
        'transfer_window_started_at' => "ALTER TABLE `fly_beaver_progress` ADD COLUMN `transfer_window_started_at` TIMESTAMP NULL DEFAULT NULL AFTER `transferred_total_score`",
        'transfer_window_coins' => "ALTER TABLE `fly_beaver_progress` ADD COLUMN `transfer_window_coins` BIGINT NOT NULL DEFAULT 0 AFTER `transfer_window_started_at`",
    ];

    foreach ($flyProgressAlterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'fly_beaver_progress', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру прогресса fly-beaver.');
        }
    }

    $createFlyRunsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `fly_beaver_runs` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `run_token` VARCHAR(80) NOT NULL,
    `score` INT NOT NULL DEFAULT 0,
    `level` INT NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createFlyRunsSql)) {
        throw new RuntimeException('Не удалось создать таблицу запусков fly-beaver.');
    }

    if (!bober_index_exists($conn, 'fly_beaver_runs', 'uniq_fly_beaver_user_run') && !$conn->query("CREATE UNIQUE INDEX `uniq_fly_beaver_user_run` ON `fly_beaver_runs` (`user_id`, `run_token`)")) {
        throw new RuntimeException('Не удалось создать уникальный индекс запусков fly-beaver.');
    }

    if (!bober_index_exists($conn, 'fly_beaver_runs', 'idx_fly_beaver_runs_created') && !$conn->query("CREATE INDEX `idx_fly_beaver_runs_created` ON `fly_beaver_runs` (`user_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс истории fly-beaver.');
    }
}

function bober_ensure_gameplay_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    bober_ensure_game_schema($conn);
    bober_ensure_security_schema($conn);
    bober_ensure_fly_beaver_schema($conn);

    $schemaEnsured = true;
}

function bober_ensure_project_schema($conn)
{
    static $projectSchemaEnsured = false;

    if ($projectSchemaEnsured) {
        return;
    }

    bober_ensure_gameplay_schema($conn);
    bober_ensure_admin_schema($conn);

    $projectSchemaEnsured = true;
}

function bober_default_fly_beaver_progress()
{
    return [
        'bestScore' => 0,
        'lastScore' => 0,
        'lastLevel' => 1,
        'gamesPlayed' => 0,
        'totalScore' => 0,
        'pendingTransferScore' => 0,
        'transferredTotalScore' => 0,
        'lastPlayedAt' => null,
    ];
}

function bober_normalize_fly_beaver_progress_row($row)
{
    $defaults = bober_default_fly_beaver_progress();

    if (!is_array($row)) {
        return $defaults;
    }

    return [
        'bestScore' => max(0, (int) ($row['best_score'] ?? $row['bestScore'] ?? 0)),
        'lastScore' => max(0, (int) ($row['last_score'] ?? $row['lastScore'] ?? 0)),
        'lastLevel' => max(1, (int) ($row['last_level'] ?? $row['lastLevel'] ?? 1)),
        'gamesPlayed' => max(0, (int) ($row['games_played'] ?? $row['gamesPlayed'] ?? 0)),
        'totalScore' => max(0, (int) ($row['total_score'] ?? $row['totalScore'] ?? 0)),
        'pendingTransferScore' => max(0, (int) ($row['pending_transfer_score'] ?? $row['pendingTransferScore'] ?? 0)),
        'transferredTotalScore' => max(0, (int) ($row['transferred_total_score'] ?? $row['transferredTotalScore'] ?? 0)),
        'lastPlayedAt' => isset($row['last_played_at']) ? (string) $row['last_played_at'] : ($row['lastPlayedAt'] ?? null),
    ];
}

function bober_ensure_fly_progress_row($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $stmt = $conn->prepare('INSERT IGNORE INTO fly_beaver_progress (user_id) VALUES (?)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить создание строки прогресса fly-beaver.');
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->close();
}

function bober_fetch_fly_beaver_progress($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return bober_default_fly_beaver_progress();
    }

    bober_ensure_fly_progress_row($conn, $userId);

    $stmt = $conn->prepare('SELECT best_score, last_score, last_level, games_played, total_score, pending_transfer_score, transferred_total_score, last_played_at FROM fly_beaver_progress WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение прогресса fly-beaver.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить прогресс fly-beaver.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return bober_normalize_fly_beaver_progress_row($row);
}

function bober_build_ban_message($ban)
{
    if (!is_array($ban)) {
        return 'Аккаунт временно заблокирован.';
    }

    $banUntil = trim((string) ($ban['banUntil'] ?? ''));
    if ($banUntil === '') {
        return 'Аккаунт временно заблокирован.';
    }

    return 'Аккаунт временно заблокирован до ' . $banUntil . '.';
}

function bober_normalize_ban_row($row)
{
    if (!is_array($row)) {
        return null;
    }

    $ban = [
        'id' => max(0, (int) ($row['id'] ?? 0)),
        'userId' => max(0, (int) ($row['user_id'] ?? $row['userId'] ?? 0)),
        'source' => trim((string) ($row['source'] ?? 'autoclicker')),
        'reason' => trim((string) ($row['reason'] ?? 'Подозрение на автокликер')),
        'durationDays' => max(1, (int) ($row['duration_days'] ?? $row['durationDays'] ?? 1)),
        'isRepeat' => (int) ($row['is_repeat'] ?? $row['isRepeat'] ?? 0) === 1,
        'detectedBy' => trim((string) ($row['detected_by'] ?? $row['detectedBy'] ?? 'system')),
        'banUntil' => trim((string) ($row['ban_until'] ?? $row['banUntil'] ?? '')),
        'createdAt' => trim((string) ($row['created_at'] ?? $row['createdAt'] ?? '')),
        'liftedAt' => isset($row['lifted_at']) ? (string) $row['lifted_at'] : ($row['liftedAt'] ?? null),
    ];

    $ban['message'] = bober_build_ban_message($ban);

    return $ban;
}

function bober_get_client_ip()
{
    $candidates = [];

    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }

    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        foreach (explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']) as $forwardedIp) {
            $candidates[] = trim($forwardedIp);
        }
    }

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }

    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);
        if ($candidate === '' || strlen($candidate) > 64) {
            continue;
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }
    }

    return null;
}

function bober_record_user_ip($conn, $userId, $ipAddress = null, $userAgent = null)
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $ipAddress = trim((string) ($ipAddress ?? bober_get_client_ip()));
    if ($ipAddress === '' || strlen($ipAddress) > 64 || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $userAgent = trim((string) ($userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($userAgent === '') {
        $userAgent = null;
    }

    $stmt = $conn->prepare('INSERT INTO user_ip_history (user_id, ip_address, login_count, first_seen_at, last_seen_at, last_user_agent) VALUES (?, ?, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP, ?) ON DUPLICATE KEY UPDATE login_count = login_count + 1, last_seen_at = CURRENT_TIMESTAMP, last_user_agent = VALUES(last_user_agent)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сохранение IP пользователя.');
    }

    $stmt->bind_param('iss', $userId, $ipAddress, $userAgent);
    $success = $stmt->execute();
    $stmt->close();

    if (!$success) {
        throw new RuntimeException('Не удалось сохранить IP пользователя.');
    }

    return true;
}

function bober_fetch_user_ip_addresses($conn, $userId)
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    $stmt = $conn->prepare('SELECT ip_address FROM user_ip_history WHERE user_id = ? ORDER BY last_seen_at DESC');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение IP пользователя.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить IP пользователя.');
    }

    $result = $stmt->get_result();
    $ips = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $ipAddress = trim((string) ($row['ip_address'] ?? ''));
        if ($ipAddress !== '') {
            $ips[] = $ipAddress;
        }
    }

    if ($result) {
        $result->free();
    }

    $stmt->close();

    return array_values(array_unique($ips));
}

function bober_build_ip_ban_message($ban)
{
    if (!is_array($ban)) {
        return 'С этого IP временно ограничен вход в аккаунты и регистрация новых профилей.';
    }

    $banUntil = trim((string) ($ban['banUntil'] ?? ''));
    if ($banUntil === '') {
        return 'С этого IP временно ограничен вход в аккаунты и регистрация новых профилей.';
    }

    return 'С этого IP вход в аккаунты и регистрация новых профилей временно ограничены до ' . $banUntil . '.';
}

function bober_normalize_ip_ban_row($row)
{
    if (!is_array($row)) {
        return null;
    }

    $ban = [
        'id' => max(0, (int) ($row['id'] ?? 0)),
        'ipAddress' => trim((string) ($row['ip_address'] ?? $row['ipAddress'] ?? '')),
        'sourceUserId' => max(0, (int) ($row['source_user_id'] ?? $row['sourceUserId'] ?? 0)),
        'sourceUserBanId' => max(0, (int) ($row['source_user_ban_id'] ?? $row['sourceUserBanId'] ?? 0)),
        'reason' => trim((string) ($row['reason'] ?? 'Блокировка по IP')),
        'banUntil' => trim((string) ($row['ban_until'] ?? $row['banUntil'] ?? '')),
        'createdAt' => trim((string) ($row['created_at'] ?? $row['createdAt'] ?? '')),
        'liftedAt' => isset($row['lifted_at']) ? (string) ($row['lifted_at']) : ($row['liftedAt'] ?? null),
    ];

    $ban['message'] = bober_build_ip_ban_message($ban);

    return $ban;
}

function bober_fetch_active_ip_ban($conn, $ipAddress = null)
{
    bober_ensure_security_schema($conn);

    $ipAddress = trim((string) ($ipAddress ?? bober_get_client_ip()));
    if ($ipAddress === '' || strlen($ipAddress) > 64 || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, ip_address, source_user_id, source_user_ban_id, reason, ban_until, created_at, lifted_at FROM ip_bans WHERE ip_address = ? AND lifted_at IS NULL AND ban_until > CURRENT_TIMESTAMP ORDER BY ban_until DESC LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить проверку IP-бана.');
    }

    $stmt->bind_param('s', $ipAddress);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось проверить IP-бан.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $row ? bober_normalize_ip_ban_row($row) : null;
}

function bober_upsert_ip_ban_for_address($conn, $ipAddress, $userId, $sourceUserBanId, $reason, $banUntil, $meta = [])
{
    $ipAddress = trim((string) $ipAddress);
    if ($ipAddress === '' || strlen($ipAddress) > 64 || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return false;
    }

    $sourceUserBanId = max(0, (int) $sourceUserBanId);
    $reason = trim((string) $reason);
    $banUntil = trim((string) $banUntil);

    if ($reason === '' || $banUntil === '') {
        return false;
    }

    $meta['ip_address'] = $ipAddress;
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $existingStmt = $conn->prepare('SELECT id, ban_until FROM ip_bans WHERE ip_address = ? AND source_user_id = ? AND lifted_at IS NULL ORDER BY ban_until DESC LIMIT 1');
    if (!$existingStmt) {
        throw new RuntimeException('Не удалось подготовить проверку существующего IP-бана.');
    }

    $existingStmt->bind_param('si', $ipAddress, $userId);
    if (!$existingStmt->execute()) {
        $existingStmt->close();
        throw new RuntimeException('Не удалось проверить существующий IP-бан.');
    }

    $existingResult = $existingStmt->get_result();
    $existingRow = $existingResult ? $existingResult->fetch_assoc() : null;
    if ($existingResult) {
        $existingResult->free();
    }
    $existingStmt->close();

    $targetBanUntilTimestamp = strtotime($banUntil);

    if ($existingRow) {
        $existingBanUntilTimestamp = strtotime((string) ($existingRow['ban_until'] ?? ''));
        if ($existingBanUntilTimestamp !== false && $targetBanUntilTimestamp !== false && $existingBanUntilTimestamp >= $targetBanUntilTimestamp) {
            return true;
        }

        $updateStmt = $conn->prepare('UPDATE ip_bans SET source_user_ban_id = ?, reason = ?, ban_until = ?, meta_json = ?, lifted_at = NULL WHERE id = ? LIMIT 1');
        if (!$updateStmt) {
            throw new RuntimeException('Не удалось подготовить обновление IP-бана.');
        }

        $banId = (int) $existingRow['id'];
        $updateStmt->bind_param('isssi', $sourceUserBanId, $reason, $banUntil, $metaJson, $banId);
        $success = $updateStmt->execute();
        $updateStmt->close();

        if (!$success) {
            throw new RuntimeException('Не удалось обновить IP-бан.');
        }

        return true;
    }

    $insertStmt = $conn->prepare('INSERT INTO ip_bans (ip_address, source_user_id, source_user_ban_id, reason, ban_until, meta_json) VALUES (?, ?, ?, ?, ?, ?)');
    if (!$insertStmt) {
        throw new RuntimeException('Не удалось подготовить создание IP-бана.');
    }

    $insertStmt->bind_param('siisss', $ipAddress, $userId, $sourceUserBanId, $reason, $banUntil, $metaJson);
    $success = $insertStmt->execute();
    $insertStmt->close();

    if (!$success) {
        throw new RuntimeException('Не удалось создать IP-бан.');
    }

    return true;
}

function bober_propagate_user_ban_to_ip_bans($conn, $userId, $ban, $options = [])
{
    if (!is_array($ban)) {
        return [];
    }

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    $banUntil = trim((string) ($ban['banUntil'] ?? ''));
    if ($banUntil === '') {
        return [];
    }

    $includeCurrentIp = array_key_exists('include_current_ip', $options)
        ? (bool) $options['include_current_ip']
        : true;

    $ips = bober_fetch_user_ip_addresses($conn, $userId);
    if ($includeCurrentIp) {
        $currentIp = bober_get_client_ip();
        if (is_string($currentIp) && $currentIp !== '') {
            $ips[] = $currentIp;
        }
    }

    $ips = array_values(array_unique(array_filter($ips, static function ($ipAddress) {
        return is_string($ipAddress) && $ipAddress !== '';
    })));

    $reason = trim((string) ($ban['reason'] ?? 'Аккаунт временно заблокирован'));
    $sourceUserBanId = max(0, (int) ($ban['id'] ?? 0));
    $createdIpBans = [];

    foreach ($ips as $ipAddress) {
        bober_upsert_ip_ban_for_address($conn, $ipAddress, $userId, $sourceUserBanId, $reason, $banUntil, [
            'source' => 'user-ban-propagation',
            'reason' => $reason,
        ]);
        $createdIpBans[] = $ipAddress;
    }

    return $createdIpBans;
}

function bober_fetch_active_user_ban($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, user_id, source, reason, duration_days, is_repeat, detected_by, ban_until, created_at, lifted_at FROM user_bans WHERE user_id = ? AND lifted_at IS NULL AND ban_until > CURRENT_TIMESTAMP ORDER BY ban_until DESC LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить проверку бана.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось проверить бан пользователя.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $row ? bober_normalize_ban_row($row) : null;
}

function bober_lift_ip_bans_for_user($conn, $userId)
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return 0;
    }

    $stmt = $conn->prepare('UPDATE ip_bans SET lifted_at = CURRENT_TIMESTAMP WHERE source_user_id = ? AND lifted_at IS NULL AND ban_until > CURRENT_TIMESTAMP');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить снятие IP-банов.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось снять IP-баны.');
    }

    $affectedRows = (int) $stmt->affected_rows;
    $stmt->close();

    return max(0, $affectedRows);
}

function bober_lift_user_bans($conn, $userId)
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $stmt = $conn->prepare('UPDATE user_bans SET lifted_at = CURRENT_TIMESTAMP WHERE user_id = ? AND lifted_at IS NULL AND ban_until > CURRENT_TIMESTAMP');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить снятие банов пользователя.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось снять бан пользователя.');
    }

    $liftedUserBans = max(0, (int) $stmt->affected_rows);
    $stmt->close();

    $liftedIpBans = bober_lift_ip_bans_for_user($conn, $userId);

    return [
        'liftedUserBans' => $liftedUserBans,
        'liftedIpBans' => $liftedIpBans,
    ];
}

function bober_count_user_bans($conn, $userId, $source = 'autoclicker')
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return 0;
    }

    $source = trim((string) $source);
    if ($source === '') {
        $source = 'autoclicker';
    }

    $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_bans WHERE user_id = ? AND source = ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить подсчет банов.');
    }

    $stmt->bind_param('is', $userId, $source);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось подсчитать баны пользователя.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : ['total' => 0];
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return max(0, (int) ($row['total'] ?? 0));
}

function bober_issue_user_ban($conn, $userId, $reason, $details = [])
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $activeBan = bober_fetch_active_user_ban($conn, $userId);
    if ($activeBan !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, $userId, $activeBan, [
            'include_current_ip' => false,
        ]);
        return $activeBan;
    }

    $source = trim((string) ($details['source'] ?? 'autoclicker'));
    if ($source === '') {
        $source = 'autoclicker';
    }

    $detectedBy = trim((string) ($details['detected_by'] ?? 'client'));
    if ($detectedBy === '') {
        $detectedBy = 'client';
    }

    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'Подозрение на автокликер';
    }

    $previousBanCount = bober_count_user_bans($conn, $userId, $source);
    $durationDays = $previousBanCount > 0 ? 30 : 5;
    $isRepeat = $previousBanCount > 0 ? 1 : 0;
    $banUntil = date('Y-m-d H:i:s', time() + ($durationDays * 24 * 60 * 60));

    $meta = is_array($details['meta'] ?? null) ? $details['meta'] : [];
    $meta['ip'] = $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $meta['user_agent'] = $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare('INSERT INTO user_bans (user_id, source, reason, duration_days, is_repeat, detected_by, ban_until, meta_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить создание бана.');
    }

    $stmt->bind_param('issiisss', $userId, $source, $reason, $durationDays, $isRepeat, $detectedBy, $banUntil, $metaJson);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сохранить бан пользователя.');
    }
    $stmt->close();

    $ban = bober_fetch_active_user_ban($conn, $userId);
    if ($ban !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, $userId, $ban);
    }

    return $ban;
}
