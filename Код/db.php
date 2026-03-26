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

    $lifetime = bober_session_lifetime_seconds();
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

function bober_session_lifetime_seconds()
{
    return 30 * 24 * 60 * 60;
}

function bober_current_session_id()
{
    bober_start_session();

    return (string) session_id();
}

function bober_session_hash($sessionId)
{
    $sessionId = trim((string) $sessionId);
    if ($sessionId === '') {
        return '';
    }

    return hash('sha256', $sessionId);
}

function bober_current_session_hash()
{
    return bober_session_hash(bober_current_session_id());
}

function bober_login_user($userId, $login = '')
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    bober_start_session();
    $previousSessionHash = bober_session_hash(session_id());
    session_regenerate_id(true);
    $currentSessionHash = bober_session_hash(session_id());

    $_SESSION['game_user_id'] = $userId;
    $_SESSION['game_login'] = trim((string) $login);
    $_SESSION['game_last_seen_at'] = time();

    return [
        'previousSessionHash' => $previousSessionHash,
        'currentSessionHash' => $currentSessionHash,
    ];
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

function bober_destroy_php_session()
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

function bober_logout_user($options = [])
{
    $skipSessionRevoke = !empty($options['skip_session_revoke']);

    if (!$skipSessionRevoke) {
        $sessionHash = bober_current_session_hash();
        if ($sessionHash !== '') {
            $logoutConn = $options['conn'] ?? null;
            $shouldCloseLogoutConn = false;

            try {
                if (!($logoutConn instanceof mysqli)) {
                    $logoutConn = bober_db_connect();
                    bober_ensure_security_schema($logoutConn);
                    $shouldCloseLogoutConn = true;
                }

                bober_revoke_game_session_by_hash(
                    $logoutConn,
                    $sessionHash,
                    trim((string) ($options['reason'] ?? 'logout'))
                );
            } catch (Throwable $logoutError) {
                // Logout should still complete even if session tracking cannot be updated.
            } finally {
                if ($shouldCloseLogoutConn && isset($logoutConn) && $logoutConn instanceof mysqli) {
                    $logoutConn->close();
                }
            }
        }
    }

    bober_destroy_php_session();
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
        'version' => 2,
        'equippedSkinId' => 'classic',
        'ownedSkinIds' => ['classic', 'standard'],
    ];
}

function bober_default_skin_json()
{
    return json_encode(bober_default_skin_state(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bober_skin_catalog_file_path()
{
    return __DIR__ . '/skin-catalog.json';
}

function bober_builtin_skin_catalog()
{
    return [
        'classic' => [
            'id' => 'classic',
            'name' => 'Просто бобер',
            'price' => 0,
            'image' => 'skins/bober.png',
            'available' => true,
            'rarity' => 'common',
            'category' => 'classic',
            'issue_mode' => 'starter',
        ],
        'paper' => [
            'id' => 'paper',
            'name' => 'Бумажный бобер',
            'price' => 5000,
            'image' => 'skins/bumazny-bober.jpg',
            'available' => true,
            'rarity' => 'common',
            'category' => 'fun',
            'issue_mode' => 'shop',
        ],
        'standard' => [
            'id' => 'standard',
            'name' => 'Стандартный бобер',
            'price' => 0,
            'image' => 'skins/matvey-new-bober.jpg',
            'available' => true,
            'rarity' => 'common',
            'category' => 'classic',
            'issue_mode' => 'starter',
        ],
        'strawberry' => [
            'id' => 'strawberry',
            'name' => 'Клубничный йогурт бобер',
            'price' => 15000,
            'image' => 'skins/klub-smz-bober.jpg',
            'available' => true,
            'rarity' => 'uncommon',
            'category' => 'food',
            'issue_mode' => 'shop',
        ],
        'sock' => [
            'id' => 'sock',
            'name' => 'Носок бобер',
            'price' => 30000,
            'image' => 'skins/nosok-bober.jpg',
            'available' => true,
            'rarity' => 'epic',
            'category' => 'fun',
            'issue_mode' => 'shop',
        ],
        'chocolate' => [
            'id' => 'chocolate',
            'name' => 'Шоколад бобер',
            'price' => 50000,
            'image' => 'skins/Shok-upok-bober.jpg',
            'available' => true,
            'rarity' => 'rare',
            'category' => 'food',
            'issue_mode' => 'shop',
        ],
        'strange' => [
            'id' => 'strange',
            'name' => 'Странный бобер',
            'price' => 30000,
            'image' => 'skins/strany-bober.jpg',
            'available' => true,
            'rarity' => 'rare',
            'category' => 'mystic',
            'issue_mode' => 'shop',
        ],
        'dev' => [
            'id' => 'dev',
            'name' => 'Dev бобер',
            'price' => 90000,
            'image' => 'skins/dev.png',
            'available' => false,
            'rarity' => 'admin',
            'category' => 'admin',
            'issue_mode' => 'grant_only',
        ],
    ];
}

function bober_skin_rarity_values()
{
    return ['common', 'uncommon', 'rare', 'epic', 'legendary', 'admin'];
}

function bober_skin_category_values()
{
    return ['classic', 'food', 'fun', 'mystic', 'event', 'nature', 'neon', 'seasonal', 'pixel', 'admin', 'other'];
}

function bober_skin_issue_mode_values()
{
    return ['shop', 'grant_only', 'starter'];
}

function bober_normalize_skin_rarity($value)
{
    $value = strtolower(trim((string) $value));
    return in_array($value, bober_skin_rarity_values(), true) ? $value : 'common';
}

function bober_normalize_skin_category($value)
{
    $value = strtolower(trim((string) $value));
    return in_array($value, bober_skin_category_values(), true) ? $value : 'other';
}

function bober_normalize_skin_issue_mode($value, $rawItem = null)
{
    $value = strtolower(trim((string) $value));
    if (in_array($value, bober_skin_issue_mode_values(), true)) {
        return $value;
    }

    if (is_array($rawItem)) {
        if (!empty($rawItem['default_owned'])) {
            return 'starter';
        }

        if (!empty($rawItem['grant_only'])) {
            return 'grant_only';
        }
    }

    return 'shop';
}

function bober_normalize_skin_catalog_item($rawItem)
{
    if (!is_array($rawItem)) {
        return null;
    }

    $id = trim((string) ($rawItem['id'] ?? ''));
    $image = trim((string) ($rawItem['image'] ?? ''));
    $name = trim((string) ($rawItem['name'] ?? ''));

    if ($id === '' || $image === '') {
        return null;
    }

    $issueMode = bober_normalize_skin_issue_mode($rawItem['issue_mode'] ?? ($rawItem['issueMode'] ?? ''), $rawItem);

    return [
        'id' => $id,
        'name' => $name !== '' ? $name : $id,
        'price' => max(0, (int) ($rawItem['price'] ?? 0)),
        'image' => $image,
        'default_owned' => $issueMode === 'starter',
        'available' => array_key_exists('available', $rawItem) ? (bool) $rawItem['available'] : true,
        'rarity' => bober_normalize_skin_rarity($rawItem['rarity'] ?? ''),
        'category' => bober_normalize_skin_category($rawItem['category'] ?? ''),
        'issue_mode' => $issueMode,
        'grant_only' => $issueMode === 'grant_only',
    ];
}

function bober_skin_catalog_is_pinned_last($item)
{
    if (!is_array($item)) {
        return false;
    }

    return trim((string) ($item['id'] ?? '')) === 'dev';
}

function bober_order_skin_catalog_items(array $catalogItems)
{
    $regularItems = [];
    $pinnedLastItems = [];

    foreach ($catalogItems as $item) {
        if (bober_skin_catalog_is_pinned_last($item)) {
            $pinnedLastItems[] = $item;
            continue;
        }

        $regularItems[] = $item;
    }

    return array_values(array_merge($regularItems, $pinnedLastItems));
}

function bober_skin_catalog()
{
    $catalogFile = bober_skin_catalog_file_path();
    if (is_file($catalogFile)) {
        $rawCatalog = file_get_contents($catalogFile);
        if ($rawCatalog !== false && trim($rawCatalog) !== '') {
            $decodedCatalog = json_decode($rawCatalog, true);
            if (is_array($decodedCatalog)) {
                $catalog = [];
                $items = array_values($decodedCatalog);
                foreach ($items as $item) {
                    $normalizedItem = bober_normalize_skin_catalog_item($item);
                    if ($normalizedItem !== null) {
                        $catalog[$normalizedItem['id']] = $normalizedItem;
                    }
                }

                if (!empty($catalog)) {
                    return $catalog;
                }
            }
        }
    }

    return bober_builtin_skin_catalog();
}

function bober_skin_catalog_list()
{
    return bober_order_skin_catalog_items(array_values(bober_skin_catalog()));
}

function bober_store_skin_catalog(array $catalogItems)
{
    $normalizedItems = [];
    foreach ($catalogItems as $item) {
        $normalizedItem = bober_normalize_skin_catalog_item($item);
        if ($normalizedItem !== null) {
            $normalizedItems[] = $normalizedItem;
        }
    }

    if (count($normalizedItems) === 0) {
        throw new RuntimeException('Каталог скинов пуст и не может быть сохранен.');
    }

    $normalizedItems = bober_order_skin_catalog_items($normalizedItems);

    $catalogFile = bober_skin_catalog_file_path();
    $encodedCatalog = json_encode(
        array_values($normalizedItems),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!is_string($encodedCatalog) || $encodedCatalog === '') {
        throw new RuntimeException('Не удалось подготовить каталог скинов к сохранению.');
    }

    if (file_put_contents($catalogFile, $encodedCatalog . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить каталог скинов.');
    }
}

function bober_skin_id_by_image($imagePath)
{
    $imagePath = trim((string) $imagePath);
    if ($imagePath === '') {
        return null;
    }

    foreach (bober_skin_catalog() as $skinConfig) {
        if (($skinConfig['image'] ?? '') === $imagePath) {
            return (string) $skinConfig['id'];
        }
    }

    return null;
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
    $catalog = bober_skin_catalog();
    $defaultOwnedSkinIds = [];
    foreach ($catalog as $skinId => $skinConfig) {
        if (!empty($skinConfig['default_owned'])) {
            $defaultOwnedSkinIds[$skinId] = true;
        }
    }

    if (!is_array($decoded)) {
        $decoded = $defaults;
    }

    if (array_key_exists(0, $decoded)) {
        $ownedSkinIds = $defaultOwnedSkinIds;
        $catalogItems = array_values($catalog);

        foreach ($catalogItems as $index => $skinConfig) {
            if (!empty($decoded[$index + 1])) {
                $ownedSkinIds[(string) $skinConfig['id']] = true;
            }
        }

        $equippedSkinId = bober_skin_id_by_image($decoded[0] ?? '') ?: (string) $defaults['equippedSkinId'];
        if (!isset($ownedSkinIds[$equippedSkinId])) {
            $ownedSkinIds[$equippedSkinId] = true;
        }

        return json_encode([
            'version' => 2,
            'equippedSkinId' => $equippedSkinId,
            'ownedSkinIds' => array_values(array_keys($ownedSkinIds)),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $ownedSkinIds = $defaultOwnedSkinIds;
    $rawOwnedSkinIds = [];

    if (isset($decoded['ownedSkinIds']) && is_array($decoded['ownedSkinIds'])) {
        $rawOwnedSkinIds = $decoded['ownedSkinIds'];
    } elseif (isset($decoded['owned']) && is_array($decoded['owned'])) {
        $rawOwnedSkinIds = $decoded['owned'];
    }

    foreach ($rawOwnedSkinIds as $skinId) {
        $skinId = trim((string) $skinId);
        if ($skinId !== '') {
            $ownedSkinIds[$skinId] = true;
        }
    }

    $equippedSkinId = trim((string) ($decoded['equippedSkinId'] ?? ''));
    if ($equippedSkinId === '' && isset($decoded['equipped'])) {
        $equippedCandidate = trim((string) $decoded['equipped']);
        if ($equippedCandidate !== '') {
            $equippedSkinId = isset($catalog[$equippedCandidate]) ? $equippedCandidate : (bober_skin_id_by_image($equippedCandidate) ?: '');
        }
    }

    if ($equippedSkinId === '' || !isset($ownedSkinIds[$equippedSkinId])) {
        $equippedSkinId = (string) $defaults['equippedSkinId'];
    }

    return json_encode([
        'version' => 2,
        'equippedSkinId' => $equippedSkinId,
        'ownedSkinIds' => array_values(array_keys($ownedSkinIds)),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function bober_decode_skin_state($skin)
{
    $normalizedJson = bober_normalize_skin_json($skin);
    $decoded = json_decode($normalizedJson, true);

    if (!is_array($decoded)) {
        $decoded = bober_default_skin_state();
    }

    if (!isset($decoded['ownedSkinIds']) || !is_array($decoded['ownedSkinIds'])) {
        $decoded['ownedSkinIds'] = bober_default_skin_state()['ownedSkinIds'];
    }

    $decoded['equippedSkinId'] = trim((string) ($decoded['equippedSkinId'] ?? bober_default_skin_state()['equippedSkinId']));

    return [
        'version' => 2,
        'equippedSkinId' => $decoded['equippedSkinId'] !== '' ? $decoded['equippedSkinId'] : bober_default_skin_state()['equippedSkinId'],
        'ownedSkinIds' => array_values(array_unique(array_map('strval', $decoded['ownedSkinIds']))),
    ];
}

function bober_encode_skin_state(array $skinState)
{
    return bober_normalize_skin_json($skinState);
}

function bober_grant_skin_to_user($conn, $userId, $skinId, $equip = false)
{
    $userId = max(0, (int) $userId);
    $skinId = trim((string) $skinId);

    if ($userId < 1 || $skinId === '') {
        throw new InvalidArgumentException('Некорректные данные для выдачи скина.');
    }

    $catalog = bober_skin_catalog();
    if (!isset($catalog[$skinId])) {
        throw new InvalidArgumentException('Скин не найден в каталоге.');
    }

    $stmt = $conn->prepare('SELECT `skin` FROM `users` WHERE `id` = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить выдачу скина.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить текущие скины пользователя.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Пользователь не найден.');
    }

    $skinState = bober_decode_skin_state($row['skin'] ?? '');
    if (!in_array($skinId, $skinState['ownedSkinIds'], true)) {
        $skinState['ownedSkinIds'][] = $skinId;
    }

    if ($equip) {
        $skinState['equippedSkinId'] = $skinId;
    }

    $encodedSkin = bober_encode_skin_state($skinState);
    $updateStmt = $conn->prepare('UPDATE `users` SET `skin` = ? WHERE `id` = ? LIMIT 1');
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось подготовить сохранение скина пользователя.');
    }

    $updateStmt->bind_param('si', $encodedSkin, $userId);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        throw new RuntimeException('Не удалось сохранить выданный скин.');
    }
    $updateStmt->close();

    return $skinState;
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
    `upgrade_tap_huge_count` INT NOT NULL DEFAULT 0,
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
        'upgrade_tap_huge_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_tap_huge_count` INT NOT NULL DEFAULT 0 AFTER `upgrade_energy_count`",
        'created_at' => "ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `upgrade_tap_huge_count`",
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
        "UPDATE `users` SET `upgrade_tap_huge_count` = 0 WHERE `upgrade_tap_huge_count` IS NULL OR `upgrade_tap_huge_count` < 0",
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

    $createRuntimeCacheSql = <<<SQL
CREATE TABLE IF NOT EXISTS `runtime_cache` (
    `cache_key` VARCHAR(120) PRIMARY KEY,
    `payload_json` LONGTEXT NULL,
    `expires_at` DATETIME NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createRuntimeCacheSql)) {
        throw new RuntimeException('Не удалось создать таблицу runtime-кэша.');
    }

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

function bober_runtime_cache_fetch($conn, $cacheKey)
{
    if (!($conn instanceof mysqli)) {
        return null;
    }

    bober_ensure_admin_schema($conn);

    $cacheKey = trim((string) $cacheKey);
    if ($cacheKey === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT `payload_json`, `updated_at`, `expires_at` FROM `runtime_cache` WHERE `cache_key` = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $cacheKey);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!$row) {
        return null;
    }

    $expiresAt = isset($row['expires_at']) ? trim((string) $row['expires_at']) : '';
    if ($expiresAt !== '') {
        $expiresAtTs = strtotime($expiresAt);
        if ($expiresAtTs !== false && $expiresAtTs <= time()) {
            return null;
        }
    }

    $payload = [];
    if (!empty($row['payload_json']) && is_string($row['payload_json'])) {
        $decoded = json_decode($row['payload_json'], true);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    }

    return [
        'payload' => $payload,
        'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        'expiresAt' => $expiresAt !== '' ? $expiresAt : null,
    ];
}

function bober_runtime_cache_store($conn, $cacheKey, $payload, $ttlSeconds = 60)
{
    if (!($conn instanceof mysqli)) {
        return false;
    }

    bober_ensure_admin_schema($conn);

    $cacheKey = trim((string) $cacheKey);
    if ($cacheKey === '') {
        return false;
    }

    $ttlSeconds = max(1, (int) $ttlSeconds);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payloadJson === false) {
        return false;
    }

    $expiresAt = date('Y-m-d H:i:s', time() + $ttlSeconds);
    $stmt = $conn->prepare('INSERT INTO `runtime_cache` (`cache_key`, `payload_json`, `expires_at`) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE `payload_json` = VALUES(`payload_json`), `expires_at` = VALUES(`expires_at`), `updated_at` = CURRENT_TIMESTAMP');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('sss', $cacheKey, $payloadJson, $expiresAt);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function bober_runtime_cache_delete($conn, $cacheKey)
{
    if (!($conn instanceof mysqli)) {
        return false;
    }

    bober_ensure_admin_schema($conn);

    $cacheKey = trim((string) $cacheKey);
    if ($cacheKey === '') {
        return false;
    }

    $stmt = $conn->prepare('DELETE FROM `runtime_cache` WHERE `cache_key` = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $cacheKey);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function bober_runtime_cache_purge_prefix($conn, $prefix)
{
    if (!($conn instanceof mysqli)) {
        return 0;
    }

    bober_ensure_admin_schema($conn);

    $prefix = trim((string) $prefix);
    if ($prefix === '') {
        return 0;
    }

    $likePrefix = $prefix . '%';
    $stmt = $conn->prepare('DELETE FROM `runtime_cache` WHERE `cache_key` LIKE ?');
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('s', $likePrefix);
    if (!$stmt->execute()) {
        $stmt->close();
        return 0;
    }

    $affected = (int) $stmt->affected_rows;
    $stmt->close();

    return max(0, $affected);
}

function bober_collect_admin_dashboard_stats($conn)
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Нет подключения к базе данных для статистики админки.');
    }

    bober_ensure_project_schema($conn);

    $tablesResult = $conn->query('SHOW TABLES');
    if ($tablesResult === false) {
        throw new RuntimeException('Не удалось получить список таблиц.');
    }

    $tables = [];
    while ($row = $tablesResult->fetch_array()) {
        if (!empty($row[0])) {
            $tables[] = bober_require_identifier($row[0], 'Имя таблицы');
        }
    }
    $tablesResult->free();

    $totalRows = 0;
    foreach ($tables as $tableName) {
        $countResult = $conn->query("SELECT COUNT(*) AS total FROM `{$tableName}`");
        if ($countResult instanceof mysqli_result) {
            $countRow = $countResult->fetch_assoc();
            $totalRows += (int) ($countRow['total'] ?? 0);
            $countResult->free();
        }
    }

    $activeBans = 0;
    $flyPending = 0;
    $usersCount = 0;

    $activeBansResult = $conn->query("SELECT COUNT(*) AS total FROM `user_bans` WHERE `lifted_at` IS NULL AND `ban_until` > CURRENT_TIMESTAMP");
    if ($activeBansResult instanceof mysqli_result) {
        $activeBans = (int) ($activeBansResult->fetch_assoc()['total'] ?? 0);
        $activeBansResult->free();
    }

    $flyPendingResult = $conn->query("SELECT COALESCE(SUM(`pending_transfer_score`), 0) AS total FROM `fly_beaver_progress`");
    if ($flyPendingResult instanceof mysqli_result) {
        $flyPending = (int) ($flyPendingResult->fetch_assoc()['total'] ?? 0);
        $flyPendingResult->free();
    }

    $usersResult = $conn->query("SELECT COUNT(*) AS total FROM `users`");
    if ($usersResult instanceof mysqli_result) {
        $usersCount = (int) ($usersResult->fetch_assoc()['total'] ?? 0);
        $usersResult->free();
    }

    return [
        'table_count' => count($tables),
        'total_rows' => $totalRows,
        'active_bans' => $activeBans,
        'fly_pending_score' => $flyPending,
        'users_count' => $usersCount,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function bober_fetch_admin_dashboard_stats($conn, $options = [])
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Нет подключения к базе данных для статистики админки.');
    }

    $forceRefresh = !empty($options['force_refresh']);
    $ttlSeconds = max(1, (int) ($options['ttl_seconds'] ?? 60));

    if (!$forceRefresh) {
        $cached = bober_runtime_cache_fetch($conn, 'admin_dashboard_stats');
        if (is_array($cached) && is_array($cached['payload'] ?? null)) {
            return array_merge($cached['payload'], [
                'cached' => true,
                'cache_updated_at' => $cached['updatedAt'] ?? null,
                'cache_expires_at' => $cached['expiresAt'] ?? null,
            ]);
        }
    }

    $stats = bober_collect_admin_dashboard_stats($conn);
    bober_runtime_cache_store($conn, 'admin_dashboard_stats', $stats, $ttlSeconds);

    return array_merge($stats, [
        'cached' => false,
        'cache_updated_at' => $stats['generated_at'],
        'cache_expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
    ]);
}

function bober_collect_admin_users_overview($conn, $options = [])
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Нет подключения к базе данных для списка аккаунтов.');
    }

    bober_ensure_project_schema($conn);

    $search = trim((string) ($options['search'] ?? ''));
    $sort = trim((string) ($options['sort'] ?? 'activity_desc'));
    $filter = trim((string) ($options['filter'] ?? 'all'));
    $searchLike = '%' . $search . '%';
    $sortMap = [
        'activity_desc' => '`is_banned` DESC, `last_activity_at` DESC, `u`.`score` DESC, `u`.`id` DESC',
        'score_desc' => '`is_banned` DESC, `u`.`score` DESC, `last_activity_at` DESC, `u`.`id` DESC',
        'score_asc' => '`is_banned` DESC, `u`.`score` ASC, `last_activity_at` DESC, `u`.`id` DESC',
        'created_desc' => '`is_banned` DESC, `u`.`created_at` DESC, `u`.`id` DESC',
        'created_asc' => '`is_banned` DESC, `u`.`created_at` ASC, `u`.`id` ASC',
        'login_asc' => '`is_banned` DESC, `u`.`login` ASC, `u`.`id` ASC',
    ];
    $filterSqlMap = [
        'all' => '',
        'banned' => ' AND EXISTS(SELECT 1 FROM `user_bans` `ubf` WHERE `ubf`.`user_id` = `u`.`id` AND `ubf`.`lifted_at` IS NULL AND `ubf`.`ban_until` > CURRENT_TIMESTAMP)',
        'active' => ' AND NOT EXISTS(SELECT 1 FROM `user_bans` `ubf` WHERE `ubf`.`user_id` = `u`.`id` AND `ubf`.`lifted_at` IS NULL AND `ubf`.`ban_until` > CURRENT_TIMESTAMP)',
        'has_sessions' => ' AND EXISTS(SELECT 1 FROM `user_sessions` `usf` WHERE `usf`.`user_id` = `u`.`id` AND `usf`.`revoked_at` IS NULL)',
    ];
    $orderBySql = $sortMap[$sort] ?? $sortMap['activity_desc'];
    $sort = isset($sortMap[$sort]) ? $sort : 'activity_desc';
    $filter = isset($filterSqlMap[$filter]) ? $filter : 'all';
    $filterSql = $filterSqlMap[$filter];

    $sql = <<<SQL
SELECT
    `u`.`id`,
    `u`.`login`,
    `u`.`score`,
    `u`.`plus`,
    `u`.`energy`,
    `u`.`ENERGY_MAX`,
    `u`.`created_at`,
    `u`.`updated_at`,
    GREATEST(
        COALESCE(`u`.`updated_at`, '1970-01-01 00:00:00'),
        COALESCE(`f`.`last_played_at`, '1970-01-01 00:00:00'),
        COALESCE((
            SELECT MAX(`iph`.`last_seen_at`)
            FROM `user_ip_history` `iph`
            WHERE `iph`.`user_id` = `u`.`id`
        ), '1970-01-01 00:00:00')
    ) AS `last_activity_at`,
    COALESCE(`f`.`best_score`, 0) AS `fly_best_score`,
    COALESCE(`f`.`pending_transfer_score`, 0) AS `fly_pending_score`,
    (
        SELECT COUNT(*)
        FROM `user_sessions` `us`
        WHERE `us`.`user_id` = `u`.`id`
          AND `us`.`revoked_at` IS NULL
    ) AS `active_session_count`,
    EXISTS(
        SELECT 1
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        LIMIT 1
    ) AS `is_banned`,
    (
        SELECT `ub2`.`ban_until`
        FROM `user_bans` `ub2`
        WHERE `ub2`.`user_id` = `u`.`id`
          AND `ub2`.`lifted_at` IS NULL
          AND `ub2`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub2`.`ban_until` DESC
        LIMIT 1
    ) AS `ban_until`
FROM `users` `u`
LEFT JOIN `fly_beaver_progress` `f` ON `f`.`user_id` = `u`.`id`
WHERE (`u`.`login` LIKE ? OR CAST(`u`.`id` AS CHAR) LIKE ?)
{$filterSql}
ORDER BY {$orderBySql}
LIMIT 200
SQL;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить список пользователей.');
    }

    $stmt->bind_param('ss', $searchLike, $searchLike);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось загрузить список пользователей.');
    }

    $result = $stmt->get_result();
    $users = [];

    while ($row = $result->fetch_assoc()) {
        $users[] = [
            'id' => (int) ($row['id'] ?? 0),
            'login' => (string) ($row['login'] ?? ''),
            'score' => max(0, (int) ($row['score'] ?? 0)),
            'plus' => max(1, (int) ($row['plus'] ?? 1)),
            'energy' => max(0, (int) ($row['energy'] ?? 0)),
            'energyMax' => max(1, (int) ($row['ENERGY_MAX'] ?? 1)),
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
            'lastActivityAt' => isset($row['last_activity_at']) ? (string) $row['last_activity_at'] : null,
            'flyBestScore' => max(0, (int) ($row['fly_best_score'] ?? 0)),
            'flyPendingScore' => max(0, (int) ($row['fly_pending_score'] ?? 0)),
            'activeSessionCount' => max(0, (int) ($row['active_session_count'] ?? 0)),
            'isBanned' => (int) ($row['is_banned'] ?? 0) === 1,
            'banUntil' => isset($row['ban_until']) ? (string) $row['ban_until'] : null,
        ];
    }

    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $countSql = 'SELECT COUNT(*) AS total FROM `users` `u` WHERE (`u`.`login` LIKE ? OR CAST(`u`.`id` AS CHAR) LIKE ?)' . $filterSql;
    $countStmt = $conn->prepare($countSql);
    if (!$countStmt) {
        throw new RuntimeException('Не удалось получить число пользователей.');
    }

    $countStmt->bind_param('ss', $searchLike, $searchLike);
    if (!$countStmt->execute()) {
        $countStmt->close();
        throw new RuntimeException('Не удалось получить число пользователей.');
    }

    $countResult = $countStmt->get_result();
    $countRow = $countResult ? $countResult->fetch_assoc() : null;
    $total = (int) ($countRow['total'] ?? 0);
    if ($countResult instanceof mysqli_result) {
        $countResult->free();
    }
    $countStmt->close();

    return [
        'users' => $users,
        'returned' => count($users),
        'total' => $total,
        'search' => $search,
        'sort' => $sort,
        'filter' => $filter,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function bober_fetch_admin_users_overview($conn, $options = [])
{
    $search = trim((string) ($options['search'] ?? ''));
    $sort = trim((string) ($options['sort'] ?? 'activity_desc'));
    $filter = trim((string) ($options['filter'] ?? 'all'));
    $forceRefresh = !empty($options['force_refresh']);
    $ttlSeconds = max(5, (int) ($options['ttl_seconds'] ?? 20));
    $cacheKey = 'admin_accounts_overview_' . hash('sha256', json_encode([
        'search' => $search,
        'sort' => $sort,
        'filter' => $filter,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if (!$forceRefresh) {
        $cached = bober_runtime_cache_fetch($conn, $cacheKey);
        if (is_array($cached) && is_array($cached['payload'] ?? null)) {
            return array_merge($cached['payload'], [
                'cached' => true,
                'cache_updated_at' => $cached['updatedAt'] ?? null,
                'cache_expires_at' => $cached['expiresAt'] ?? null,
            ]);
        }
    }

    $data = bober_collect_admin_users_overview($conn, [
        'search' => $search,
        'sort' => $sort,
        'filter' => $filter,
    ]);
    bober_runtime_cache_store($conn, $cacheKey, $data, $ttlSeconds);

    return array_merge($data, [
        'cached' => false,
        'cache_updated_at' => $data['generated_at'],
        'cache_expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
    ]);
}

function bober_collect_admin_maintenance_snapshot($conn)
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Нет подключения к базе данных для экрана обслуживания.');
    }

    bober_ensure_project_schema($conn);

    $snapshot = [
        'activeClientLogRows' => 0,
        'archivedClientLogRows' => 0,
        'oldestActiveClientLogAt' => null,
        'oldestArchivedClientLogAt' => null,
        'newestActiveClientLogAt' => null,
        'newestArchivedClientLogAt' => null,
        'adminCacheKeys' => 0,
        'adminCacheLastUpdateAt' => null,
        'generated_at' => date('Y-m-d H:i:s'),
    ];

    $activeResult = $conn->query('SELECT COUNT(*) AS total, MIN(`received_at`) AS oldest_at, MAX(`received_at`) AS newest_at FROM `user_client_event_log`');
    if ($activeResult instanceof mysqli_result) {
        $row = $activeResult->fetch_assoc();
        $snapshot['activeClientLogRows'] = max(0, (int) ($row['total'] ?? 0));
        $snapshot['oldestActiveClientLogAt'] = isset($row['oldest_at']) ? (string) $row['oldest_at'] : null;
        $snapshot['newestActiveClientLogAt'] = isset($row['newest_at']) ? (string) $row['newest_at'] : null;
        $activeResult->free();
    }

    $archiveResult = $conn->query('SELECT COUNT(*) AS total, MIN(`received_at`) AS oldest_at, MAX(`received_at`) AS newest_at FROM `user_client_event_log_archive`');
    if ($archiveResult instanceof mysqli_result) {
        $row = $archiveResult->fetch_assoc();
        $snapshot['archivedClientLogRows'] = max(0, (int) ($row['total'] ?? 0));
        $snapshot['oldestArchivedClientLogAt'] = isset($row['oldest_at']) ? (string) $row['oldest_at'] : null;
        $snapshot['newestArchivedClientLogAt'] = isset($row['newest_at']) ? (string) $row['newest_at'] : null;
        $archiveResult->free();
    }

    $cacheResult = $conn->query("SELECT COUNT(*) AS total, MAX(`updated_at`) AS latest_update FROM `runtime_cache` WHERE `cache_key` LIKE 'admin_%'");
    if ($cacheResult instanceof mysqli_result) {
        $row = $cacheResult->fetch_assoc();
        $snapshot['adminCacheKeys'] = max(0, (int) ($row['total'] ?? 0));
        $snapshot['adminCacheLastUpdateAt'] = isset($row['latest_update']) ? (string) $row['latest_update'] : null;
        $cacheResult->free();
    }

    return $snapshot;
}

function bober_fetch_admin_maintenance_snapshot($conn, $options = [])
{
    $forceRefresh = !empty($options['force_refresh']);
    $ttlSeconds = max(5, (int) ($options['ttl_seconds'] ?? 45));

    if (!$forceRefresh) {
        $cached = bober_runtime_cache_fetch($conn, 'admin_maintenance_snapshot');
        if (is_array($cached) && is_array($cached['payload'] ?? null)) {
            return array_merge($cached['payload'], [
                'cached' => true,
                'cache_updated_at' => $cached['updatedAt'] ?? null,
                'cache_expires_at' => $cached['expiresAt'] ?? null,
            ]);
        }
    }

    $snapshot = bober_collect_admin_maintenance_snapshot($conn);
    bober_runtime_cache_store($conn, 'admin_maintenance_snapshot', $snapshot, $ttlSeconds);

    return array_merge($snapshot, [
        'cached' => false,
        'cache_updated_at' => $snapshot['generated_at'],
        'cache_expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
    ]);
}

function bober_log_user_activity($conn, $userId, $actionType, $details = [])
{
    if (!($conn instanceof mysqli)) {
        return false;
    }

    $userId = max(0, (int) $userId);
    $actionType = trim((string) $actionType);
    if ($userId < 1 || $actionType === '') {
        return false;
    }

    try {
        bober_ensure_security_schema($conn);
    } catch (Throwable $error) {
        return false;
    }

    $actionGroup = trim((string) ($details['action_group'] ?? 'general'));
    if ($actionGroup === '') {
        $actionGroup = 'general';
    }

    $source = trim((string) ($details['source'] ?? 'runtime'));
    if ($source === '') {
        $source = 'runtime';
    }

    $description = isset($details['description']) ? trim((string) $details['description']) : null;
    $loginSnapshot = isset($details['login']) ? trim((string) $details['login']) : '';
    $sessionHash = trim((string) ($details['session_hash'] ?? bober_current_session_hash()));
    $scoreDelta = (int) ($details['score_delta'] ?? 0);
    $coinsDelta = (int) ($details['coins_delta'] ?? 0);
    $meta = is_array($details['meta'] ?? null) ? $details['meta'] : [];
    $meta['ip'] = $meta['ip'] ?? bober_get_client_ip();
    $meta['user_agent'] = $meta['user_agent'] ?? bober_get_client_user_agent();
    $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ipAddress = trim((string) ($meta['ip'] ?? ''));
    $userAgent = trim((string) ($meta['user_agent'] ?? ''));

    $stmt = $conn->prepare('INSERT INTO `user_activity_log` (`user_id`, `login_snapshot`, `session_hash`, `action_group`, `action_type`, `source`, `description`, `score_delta`, `coins_delta`, `meta_json`, `ip_address`, `user_agent`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('issssssiisss', $userId, $loginSnapshot, $sessionHash, $actionGroup, $actionType, $source, $description, $scoreDelta, $coinsDelta, $metaJson, $ipAddress, $userAgent);
    $success = $stmt->execute();
    $stmt->close();

    return $success;
}

function bober_limit_text_value($value, $maxLength)
{
    $value = trim((string) $value);
    $maxLength = max(0, (int) $maxLength);

    if ($maxLength === 0 || $value === '') {
        return $maxLength === 0 ? '' : $value;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }

    return substr($value, 0, $maxLength);
}

function bober_bind_dynamic_params($stmt, $types, array &$values)
{
    $types = (string) $types;
    if ($types === '') {
        return true;
    }

    $bindArgs = [$types];
    foreach (array_keys($values) as $index) {
        $bindArgs[] = &$values[$index];
    }

    return call_user_func_array([$stmt, 'bind_param'], $bindArgs);
}

function bober_normalize_client_log_event($rawEvent, $defaults = [])
{
    if (!is_array($rawEvent)) {
        return null;
    }

    $eventUid = bober_limit_text_value($rawEvent['eventUid'] ?? $rawEvent['event_uid'] ?? '', 128);
    $actionType = bober_limit_text_value($rawEvent['type'] ?? $rawEvent['actionType'] ?? $rawEvent['action_type'] ?? '', 100);
    if ($eventUid === '' || $actionType === '') {
        return null;
    }

    $payload = is_array($rawEvent['payload'] ?? null) ? $rawEvent['payload'] : [];
    $description = bober_limit_text_value(
        $rawEvent['description']
            ?? $payload['description']
            ?? $payload['message']
            ?? '',
        255
    );

    return [
        'eventUid' => $eventUid,
        'batchId' => bober_limit_text_value($rawEvent['batchId'] ?? $rawEvent['batch_id'] ?? ($defaults['batchId'] ?? ''), 100),
        'deviceId' => bober_limit_text_value($rawEvent['deviceId'] ?? $rawEvent['device_id'] ?? ($defaults['deviceId'] ?? ''), 100),
        'clientSessionId' => bober_limit_text_value($rawEvent['clientSessionId'] ?? $rawEvent['client_session_id'] ?? ($defaults['clientSessionId'] ?? ''), 100),
        'sequenceNo' => max(0, (int) ($rawEvent['sequence'] ?? $rawEvent['sequenceNo'] ?? $rawEvent['sequence_no'] ?? 0)),
        'clientTs' => max(0, (int) ($rawEvent['clientTs'] ?? $rawEvent['client_ts'] ?? 0)),
        'page' => bober_limit_text_value($rawEvent['page'] ?? 'main_clicker', 64) ?: 'main_clicker',
        'actionGroup' => bober_limit_text_value($rawEvent['group'] ?? $rawEvent['actionGroup'] ?? $rawEvent['action_group'] ?? 'general', 50) ?: 'general',
        'actionType' => $actionType,
        'source' => bober_limit_text_value($rawEvent['source'] ?? 'client', 50) ?: 'client',
        'description' => $description !== '' ? $description : null,
        'scoreSnapshot' => max(0, (int) ($rawEvent['scoreSnapshot'] ?? $rawEvent['score_snapshot'] ?? 0)),
        'energySnapshot' => max(0, (int) ($rawEvent['energySnapshot'] ?? $rawEvent['energy_snapshot'] ?? 0)),
        'plusSnapshot' => max(0, (int) ($rawEvent['plusSnapshot'] ?? $rawEvent['plus_snapshot'] ?? 0)),
        'payload' => $payload,
    ];
}

function bober_store_client_log_batch($conn, $userId, $rawBatch, $options = [])
{
    if (!($conn instanceof mysqli)) {
        return [
            'received' => 0,
            'accepted' => 0,
            'inserted' => 0,
            'duplicates' => 0,
        ];
    }

    $userId = max(0, (int) $userId);
    if ($userId < 1 || !is_array($rawBatch)) {
        return [
            'received' => 0,
            'accepted' => 0,
            'inserted' => 0,
            'duplicates' => 0,
        ];
    }

    $events = is_array($rawBatch['events'] ?? null) ? array_values($rawBatch['events']) : [];
    if (count($events) === 0) {
        return [
            'received' => 0,
            'accepted' => 0,
            'inserted' => 0,
            'duplicates' => 0,
        ];
    }

    bober_ensure_security_schema($conn);

    $batchDefaults = [
        'batchId' => bober_limit_text_value($rawBatch['batchId'] ?? $rawBatch['batch_id'] ?? '', 100),
        'deviceId' => bober_limit_text_value($rawBatch['deviceId'] ?? $rawBatch['device_id'] ?? '', 100),
        'clientSessionId' => bober_limit_text_value($rawBatch['clientSessionId'] ?? $rawBatch['client_session_id'] ?? '', 100),
    ];
    $loginSnapshot = bober_limit_text_value($options['login'] ?? ($_SESSION['game_login'] ?? ''), 100);
    $ipAddress = bober_limit_text_value($options['ip_address'] ?? bober_get_client_ip(), 64);
    $userAgent = bober_limit_text_value($options['user_agent'] ?? bober_get_client_user_agent(), 255);

    $stmt = $conn->prepare(
        'INSERT IGNORE INTO `user_client_event_log`
        (`user_id`, `login_snapshot`, `event_uid`, `batch_id`, `device_id`, `client_session_id`, `sequence_no`, `client_ts`, `page`, `action_group`, `action_type`, `source`, `description`, `score_snapshot`, `energy_snapshot`, `plus_snapshot`, `payload_json`, `ip_address`, `user_agent`)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сохранение подробного клиентского лога.');
    }

    $received = count($events);
    $accepted = 0;
    $inserted = 0;

    foreach ($events as $rawEvent) {
        $event = bober_normalize_client_log_event($rawEvent, $batchDefaults);
        if ($event === null) {
            continue;
        }

        $payloadJson = json_encode($event['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson)) {
            $payloadJson = '{}';
        }

        $sequenceNo = (int) $event['sequenceNo'];
        $clientTs = (int) $event['clientTs'];
        $scoreSnapshot = (int) $event['scoreSnapshot'];
        $energySnapshot = (int) $event['energySnapshot'];
        $plusSnapshot = (int) $event['plusSnapshot'];
        $description = $event['description'];
        $page = $event['page'];
        $actionGroup = $event['actionGroup'];
        $actionType = $event['actionType'];
        $source = $event['source'];
        $eventUid = $event['eventUid'];
        $batchId = $event['batchId'];
        $deviceId = $event['deviceId'];
        $clientSessionId = $event['clientSessionId'];

        $stmt->bind_param(
            'isssssiisssssiiisss',
            $userId,
            $loginSnapshot,
            $eventUid,
            $batchId,
            $deviceId,
            $clientSessionId,
            $sequenceNo,
            $clientTs,
            $page,
            $actionGroup,
            $actionType,
            $source,
            $description,
            $scoreSnapshot,
            $energySnapshot,
            $plusSnapshot,
            $payloadJson,
            $ipAddress,
            $userAgent
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось сохранить подробный клиентский лог.');
        }

        $accepted++;
        if ((int) $stmt->affected_rows > 0) {
            $inserted++;
        }
    }

    $stmt->close();

    return [
        'received' => $received,
        'accepted' => $accepted,
        'inserted' => $inserted,
        'duplicates' => max(0, $accepted - $inserted),
    ];
}

function bober_fetch_user_client_event_log($conn, $userId, $options = [])
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'items' => [],
            'hasMore' => false,
            'limit' => 0,
        ];
    }

    $group = bober_limit_text_value($options['group'] ?? 'all', 50);
    $type = bober_limit_text_value($options['type'] ?? '', 100);
    $source = bober_limit_text_value($options['source'] ?? 'all', 50);
    $search = trim((string) ($options['search'] ?? ''));
    $limit = max(10, min(5000, (int) ($options['limit'] ?? 200)));
    $queryLimit = $limit + 1;

    $sql = 'SELECT `id`, `login_snapshot`, `event_uid`, `batch_id`, `device_id`, `client_session_id`, `sequence_no`, `client_ts`, `page`, `action_group`, `action_type`, `source`, `description`, `score_snapshot`, `energy_snapshot`, `plus_snapshot`, `payload_json`, `ip_address`, `user_agent`, `received_at`
        FROM `user_client_event_log`
        WHERE `user_id` = ?';
    $types = 'i';
    $params = [$userId];

    if ($group !== '' && $group !== 'all') {
        $sql .= ' AND `action_group` = ?';
        $types .= 's';
        $params[] = $group;
    }

    if ($type !== '' && $type !== 'all') {
        $sql .= ' AND `action_type` = ?';
        $types .= 's';
        $params[] = $type;
    }

    if ($source !== '' && $source !== 'all') {
        $sql .= ' AND `source` = ?';
        $types .= 's';
        $params[] = $source;
    }

    if ($search !== '') {
        $likeSearch = '%' . $search . '%';
        $sql .= ' AND (
            `action_type` LIKE ?
            OR `description` LIKE ?
            OR `page` LIKE ?
            OR `payload_json` LIKE ?
            OR `ip_address` LIKE ?
            OR `user_agent` LIKE ?
            OR `device_id` LIKE ?
            OR `client_session_id` LIKE ?
        )';
        $types .= 'ssssssss';
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
        $params[] = $likeSearch;
    }

    $sql .= ' ORDER BY `received_at` DESC, `id` DESC LIMIT ?';
    $types .= 'i';
    $params[] = $queryLimit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение подробного клиентского лога.');
    }

    if (!bober_bind_dynamic_params($stmt, $types, $params)) {
        $stmt->close();
        throw new RuntimeException('Не удалось привязать параметры подробного клиентского лога.');
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось загрузить подробный клиентский лог.');
    }

    $result = $stmt->get_result();
    $items = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $payload = [];
            if (!empty($row['payload_json'])) {
                $decodedPayload = json_decode((string) $row['payload_json'], true);
                if (is_array($decodedPayload)) {
                    $payload = $decodedPayload;
                }
            }

            $items[] = [
                'id' => (int) ($row['id'] ?? 0),
                'login' => (string) ($row['login_snapshot'] ?? ''),
                'eventUid' => (string) ($row['event_uid'] ?? ''),
                'batchId' => (string) ($row['batch_id'] ?? ''),
                'deviceId' => (string) ($row['device_id'] ?? ''),
                'clientSessionId' => (string) ($row['client_session_id'] ?? ''),
                'sequence' => max(0, (int) ($row['sequence_no'] ?? 0)),
                'clientTs' => max(0, (int) ($row['client_ts'] ?? 0)),
                'page' => (string) ($row['page'] ?? 'main_clicker'),
                'group' => (string) ($row['action_group'] ?? 'general'),
                'type' => (string) ($row['action_type'] ?? ''),
                'source' => (string) ($row['source'] ?? 'client'),
                'description' => (string) ($row['description'] ?? ''),
                'scoreSnapshot' => max(0, (int) ($row['score_snapshot'] ?? 0)),
                'energySnapshot' => max(0, (int) ($row['energy_snapshot'] ?? 0)),
                'plusSnapshot' => max(0, (int) ($row['plus_snapshot'] ?? 0)),
                'ipAddress' => (string) ($row['ip_address'] ?? ''),
                'userAgent' => (string) ($row['user_agent'] ?? ''),
                'receivedAt' => isset($row['received_at']) ? (string) $row['received_at'] : null,
                'payload' => $payload,
            ];
        }
        $result->free();
    }

    $stmt->close();

    $hasMore = count($items) > $limit;
    if ($hasMore) {
        $items = array_slice($items, 0, $limit);
    }

    return [
        'items' => $items,
        'hasMore' => $hasMore,
        'limit' => $limit,
    ];
}

function bober_fetch_cached_user_client_event_log($conn, $userId, $options = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'items' => [],
            'hasMore' => false,
            'limit' => 0,
            'cached' => false,
            'cache_updated_at' => null,
            'cache_expires_at' => null,
        ];
    }

    $normalizedOptions = [
        'group' => bober_limit_text_value($options['group'] ?? 'all', 50),
        'type' => bober_limit_text_value($options['type'] ?? 'all', 100),
        'source' => bober_limit_text_value($options['source'] ?? 'all', 50),
        'search' => trim((string) ($options['search'] ?? '')),
        'limit' => max(10, min(5000, (int) ($options['limit'] ?? 200))),
    ];
    $forceRefresh = !empty($options['force_refresh']);
    $ttlSeconds = max(5, (int) ($options['ttl_seconds'] ?? 15));
    $cacheKey = 'admin_user_client_log_' . $userId . '_' . hash('sha256', json_encode($normalizedOptions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    if (!$forceRefresh) {
        $cached = bober_runtime_cache_fetch($conn, $cacheKey);
        if (is_array($cached) && is_array($cached['payload'] ?? null)) {
            return array_merge($cached['payload'], [
                'cached' => true,
                'cache_updated_at' => $cached['updatedAt'] ?? null,
                'cache_expires_at' => $cached['expiresAt'] ?? null,
            ]);
        }
    }

    $data = bober_fetch_user_client_event_log($conn, $userId, $normalizedOptions);
    $data['generated_at'] = date('Y-m-d H:i:s');
    bober_runtime_cache_store($conn, $cacheKey, $data, $ttlSeconds);

    return array_merge($data, [
        'cached' => false,
        'cache_updated_at' => $data['generated_at'],
        'cache_expires_at' => date('Y-m-d H:i:s', time() + $ttlSeconds),
    ]);
}

function bober_archive_user_client_event_log($conn, $options = [])
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Нет подключения к базе данных для архивации forensic-логов.');
    }

    bober_ensure_security_schema($conn);

    $olderThanDays = max(1, (int) ($options['older_than_days'] ?? 30));
    $limit = max(50, min(5000, (int) ($options['limit'] ?? 1000)));
    $archiveReason = bober_limit_text_value($options['archive_reason'] ?? 'retention_policy', 100);
    $cutoffDate = date('Y-m-d H:i:s', time() - ($olderThanDays * 24 * 60 * 60));

    $selectStmt = $conn->prepare(
        'SELECT `id`, `user_id`, `login_snapshot`, `event_uid`, `batch_id`, `device_id`, `client_session_id`, `sequence_no`, `client_ts`, `page`, `action_group`, `action_type`, `source`, `description`, `score_snapshot`, `energy_snapshot`, `plus_snapshot`, `payload_json`, `ip_address`, `user_agent`, `received_at`
         FROM `user_client_event_log`
         WHERE `received_at` < ?
         ORDER BY `id` ASC
         LIMIT ?'
    );
    if (!$selectStmt) {
        throw new RuntimeException('Не удалось подготовить архивную выборку forensic-логов.');
    }

    $selectStmt->bind_param('si', $cutoffDate, $limit);
    if (!$selectStmt->execute()) {
        $selectStmt->close();
        throw new RuntimeException('Не удалось выбрать forensic-логи для архивации.');
    }

    $result = $selectStmt->get_result();
    $rows = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $selectStmt->close();

    if (count($rows) === 0) {
        return [
            'selected' => 0,
            'archived' => 0,
            'deleted' => 0,
            'cutoff_date' => $cutoffDate,
            'older_than_days' => $olderThanDays,
            'limit' => $limit,
        ];
    }

    $conn->begin_transaction();

    try {
        $insertStmt = $conn->prepare(
            'INSERT IGNORE INTO `user_client_event_log_archive`
            (`user_id`, `login_snapshot`, `event_uid`, `batch_id`, `device_id`, `client_session_id`, `sequence_no`, `client_ts`, `page`, `action_group`, `action_type`, `source`, `description`, `score_snapshot`, `energy_snapshot`, `plus_snapshot`, `payload_json`, `ip_address`, `user_agent`, `received_at`, `archive_reason`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось подготовить запись forensic-архива.');
        }

        $archived = 0;
        $selectedIds = [];

        foreach ($rows as $row) {
            $selectedIds[] = (int) ($row['id'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);
            $loginSnapshot = (string) ($row['login_snapshot'] ?? '');
            $eventUid = (string) ($row['event_uid'] ?? '');
            $batchId = (string) ($row['batch_id'] ?? '');
            $deviceId = (string) ($row['device_id'] ?? '');
            $clientSessionId = (string) ($row['client_session_id'] ?? '');
            $sequenceNo = (int) ($row['sequence_no'] ?? 0);
            $clientTs = (int) ($row['client_ts'] ?? 0);
            $page = (string) ($row['page'] ?? 'main_clicker');
            $actionGroup = (string) ($row['action_group'] ?? 'general');
            $actionType = (string) ($row['action_type'] ?? '');
            $source = (string) ($row['source'] ?? 'client');
            $description = isset($row['description']) ? (string) $row['description'] : null;
            $scoreSnapshot = (int) ($row['score_snapshot'] ?? 0);
            $energySnapshot = (int) ($row['energy_snapshot'] ?? 0);
            $plusSnapshot = (int) ($row['plus_snapshot'] ?? 0);
            $payloadJson = isset($row['payload_json']) ? (string) $row['payload_json'] : null;
            $ipAddress = isset($row['ip_address']) ? (string) $row['ip_address'] : null;
            $userAgent = isset($row['user_agent']) ? (string) $row['user_agent'] : null;
            $receivedAt = isset($row['received_at']) ? (string) $row['received_at'] : null;

            $insertStmt->bind_param(
                'isssssiisssssiiisssss',
                $userId,
                $loginSnapshot,
                $eventUid,
                $batchId,
                $deviceId,
                $clientSessionId,
                $sequenceNo,
                $clientTs,
                $page,
                $actionGroup,
                $actionType,
                $source,
                $description,
                $scoreSnapshot,
                $energySnapshot,
                $plusSnapshot,
                $payloadJson,
                $ipAddress,
                $userAgent,
                $receivedAt,
                $archiveReason
            );

            if (!$insertStmt->execute()) {
                $insertStmt->close();
                throw new RuntimeException('Не удалось сохранить forensic-лог в архив.');
            }

            if ((int) $insertStmt->affected_rows > 0) {
                $archived++;
            }
        }

        $insertStmt->close();

        $deleteIds = array_values(array_filter($selectedIds, function ($value) {
            return (int) $value > 0;
        }));
        $deleted = 0;

        if (count($deleteIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($deleteIds), '?'));
            $deleteSql = 'DELETE FROM `user_client_event_log` WHERE `id` IN (' . $placeholders . ')';
            $deleteStmt = $conn->prepare($deleteSql);
            if (!$deleteStmt) {
                throw new RuntimeException('Не удалось подготовить очистку активного forensic-лога после архивации.');
            }

            $deleteTypes = str_repeat('i', count($deleteIds));
            if (!bober_bind_dynamic_params($deleteStmt, $deleteTypes, $deleteIds)) {
                $deleteStmt->close();
                throw new RuntimeException('Не удалось привязать идентификаторы forensic-логов для удаления.');
            }

            if (!$deleteStmt->execute()) {
                $deleteStmt->close();
                throw new RuntimeException('Не удалось очистить активный forensic-лог после архивации.');
            }

            $deleted = max(0, (int) $deleteStmt->affected_rows);
            $deleteStmt->close();
        }

        $conn->commit();

        return [
            'selected' => count($rows),
            'archived' => $archived,
            'deleted' => $deleted,
            'cutoff_date' => $cutoffDate,
            'older_than_days' => $olderThanDays,
            'limit' => $limit,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}

function bober_export_forensic_log_dump($conn, $options = [])
{
    if (!($conn instanceof mysqli)) {
        throw new RuntimeException('Нет подключения к базе данных для forensic-выгрузки.');
    }

    bober_ensure_security_schema($conn);

    $source = trim((string) ($options['source'] ?? 'all'));
    $userId = max(0, (int) ($options['user_id'] ?? 0));
    $search = trim((string) ($options['search'] ?? ''));
    $limit = max(100, min(10000, (int) ($options['limit'] ?? 2000)));
    $tableNames = [];

    if ($source === 'active') {
        $tableNames = ['user_client_event_log'];
    } elseif ($source === 'archive') {
        $tableNames = ['user_client_event_log_archive'];
    } else {
        $tableNames = ['user_client_event_log', 'user_client_event_log_archive'];
        $source = 'all';
    }

    $items = [];

    foreach ($tableNames as $tableName) {
        $safeTable = bober_require_identifier($tableName, 'Таблица forensic-выгрузки');
        $sql = 'SELECT `id`, `user_id`, `login_snapshot`, `event_uid`, `batch_id`, `device_id`, `client_session_id`, `sequence_no`, `client_ts`, `page`, `action_group`, `action_type`, `source`, `description`, `score_snapshot`, `energy_snapshot`, `plus_snapshot`, `payload_json`, `ip_address`, `user_agent`, `received_at`'
            . ($safeTable === 'user_client_event_log_archive' ? ', `archived_at`, `archive_reason`' : ", NULL AS `archived_at`, '' AS `archive_reason`")
            . ' FROM `' . $safeTable . '` WHERE 1=1';
        $types = '';
        $params = [];

        if ($userId > 0) {
            $sql .= ' AND `user_id` = ?';
            $types .= 'i';
            $params[] = $userId;
        }

        if ($search !== '') {
            $likeSearch = '%' . $search . '%';
            $sql .= ' AND (`action_type` LIKE ? OR `description` LIKE ? OR `payload_json` LIKE ? OR `login_snapshot` LIKE ? OR `ip_address` LIKE ? OR `device_id` LIKE ?)';
            $types .= 'ssssss';
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
            $params[] = $likeSearch;
        }

        $sql .= ' ORDER BY `received_at` DESC, `id` DESC LIMIT ?';
        $types .= 'i';
        $params[] = $limit;

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить forensic-выгрузку.');
        }

        if (!bober_bind_dynamic_params($stmt, $types, $params)) {
            $stmt->close();
            throw new RuntimeException('Не удалось привязать параметры forensic-выгрузки.');
        }

        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось выполнить forensic-выгрузку.');
        }

        $result = $stmt->get_result();
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                $payload = [];
                if (!empty($row['payload_json'])) {
                    $decodedPayload = json_decode((string) $row['payload_json'], true);
                    if (is_array($decodedPayload)) {
                        $payload = $decodedPayload;
                    }
                }

                $items[] = [
                    'table' => $safeTable,
                    'id' => (int) ($row['id'] ?? 0),
                    'userId' => max(0, (int) ($row['user_id'] ?? 0)),
                    'loginSnapshot' => (string) ($row['login_snapshot'] ?? ''),
                    'eventUid' => (string) ($row['event_uid'] ?? ''),
                    'batchId' => (string) ($row['batch_id'] ?? ''),
                    'deviceId' => (string) ($row['device_id'] ?? ''),
                    'clientSessionId' => (string) ($row['client_session_id'] ?? ''),
                    'sequence' => (int) ($row['sequence_no'] ?? 0),
                    'clientTs' => (int) ($row['client_ts'] ?? 0),
                    'page' => (string) ($row['page'] ?? ''),
                    'group' => (string) ($row['action_group'] ?? ''),
                    'type' => (string) ($row['action_type'] ?? ''),
                    'source' => (string) ($row['source'] ?? ''),
                    'description' => (string) ($row['description'] ?? ''),
                    'scoreSnapshot' => (int) ($row['score_snapshot'] ?? 0),
                    'energySnapshot' => (int) ($row['energy_snapshot'] ?? 0),
                    'plusSnapshot' => (int) ($row['plus_snapshot'] ?? 0),
                    'payload' => $payload,
                    'ipAddress' => (string) ($row['ip_address'] ?? ''),
                    'userAgent' => (string) ($row['user_agent'] ?? ''),
                    'receivedAt' => isset($row['received_at']) ? (string) $row['received_at'] : null,
                    'archivedAt' => isset($row['archived_at']) ? (string) $row['archived_at'] : null,
                    'archiveReason' => (string) ($row['archive_reason'] ?? ''),
                ];
            }
            $result->free();
        }
        $stmt->close();
    }

    usort($items, function ($left, $right) {
        $leftTs = strtotime((string) ($left['receivedAt'] ?? '')) ?: 0;
        $rightTs = strtotime((string) ($right['receivedAt'] ?? '')) ?: 0;
        if ($leftTs !== $rightTs) {
            return $rightTs <=> $leftTs;
        }

        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
    });

    if (count($items) > $limit) {
        $items = array_slice($items, 0, $limit);
    }

    return [
        'items' => $items,
        'source' => $source,
        'userId' => $userId,
        'search' => $search,
        'limit' => $limit,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
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

    $createUserSessionsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_sessions` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `session_hash` CHAR(64) NOT NULL,
    `login_snapshot` VARCHAR(100) NOT NULL DEFAULT '',
    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `last_activity_source` VARCHAR(50) NOT NULL DEFAULT 'runtime',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `revoked_at` TIMESTAMP NULL DEFAULT NULL,
    `revoked_reason` VARCHAR(255) NULL DEFAULT NULL,
    `meta_json` LONGTEXT NULL
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserSessionsSql)) {
        throw new RuntimeException('Не удалось создать таблицу игровых сессий.');
    }

    $sessionAlterStatements = [
        'login_snapshot' => "ALTER TABLE `user_sessions` ADD COLUMN `login_snapshot` VARCHAR(100) NOT NULL DEFAULT '' AFTER `session_hash`",
        'ip_address' => "ALTER TABLE `user_sessions` ADD COLUMN `ip_address` VARCHAR(64) NULL DEFAULT NULL AFTER `login_snapshot`",
        'user_agent' => "ALTER TABLE `user_sessions` ADD COLUMN `user_agent` VARCHAR(255) NULL DEFAULT NULL AFTER `ip_address`",
        'last_activity_source' => "ALTER TABLE `user_sessions` ADD COLUMN `last_activity_source` VARCHAR(50) NOT NULL DEFAULT 'runtime' AFTER `user_agent`",
        'last_seen_at' => "ALTER TABLE `user_sessions` ADD COLUMN `last_seen_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `created_at`",
        'revoked_at' => "ALTER TABLE `user_sessions` ADD COLUMN `revoked_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_seen_at`",
        'revoked_reason' => "ALTER TABLE `user_sessions` ADD COLUMN `revoked_reason` VARCHAR(255) NULL DEFAULT NULL AFTER `revoked_at`",
        'meta_json' => "ALTER TABLE `user_sessions` ADD COLUMN `meta_json` LONGTEXT NULL AFTER `revoked_reason`",
    ];

    foreach ($sessionAlterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'user_sessions', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру игровых сессий.');
        }
    }

    if (!bober_index_exists($conn, 'user_sessions', 'uniq_user_sessions_hash') && !$conn->query("CREATE UNIQUE INDEX `uniq_user_sessions_hash` ON `user_sessions` (`session_hash`)")) {
        throw new RuntimeException('Не удалось создать уникальный индекс игровых сессий.');
    }

    if (!bober_index_exists($conn, 'user_sessions', 'idx_user_sessions_user_active') && !$conn->query("CREATE INDEX `idx_user_sessions_user_active` ON `user_sessions` (`user_id`, `revoked_at`, `last_seen_at`)")) {
        throw new RuntimeException('Не удалось создать индекс активных игровых сессий.');
    }

    if (!bober_index_exists($conn, 'user_sessions', 'idx_user_sessions_last_seen') && !$conn->query("CREATE INDEX `idx_user_sessions_last_seen` ON `user_sessions` (`last_seen_at`)")) {
        throw new RuntimeException('Не удалось создать индекс актуальности игровых сессий.');
    }

    $createUserActivityLogSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_activity_log` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `login_snapshot` VARCHAR(100) NOT NULL DEFAULT '',
    `session_hash` CHAR(64) NULL DEFAULT NULL,
    `action_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `action_type` VARCHAR(100) NOT NULL,
    `source` VARCHAR(50) NOT NULL DEFAULT 'runtime',
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `score_delta` BIGINT NOT NULL DEFAULT 0,
    `coins_delta` BIGINT NOT NULL DEFAULT 0,
    `meta_json` LONGTEXT NULL,
    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserActivityLogSql)) {
        throw new RuntimeException('Не удалось создать таблицу истории действий игроков.');
    }

    if (!bober_index_exists($conn, 'user_activity_log', 'idx_user_activity_user_created') && !$conn->query("CREATE INDEX `idx_user_activity_user_created` ON `user_activity_log` (`user_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс истории действий по пользователю.');
    }

    if (!bober_index_exists($conn, 'user_activity_log', 'idx_user_activity_group_created') && !$conn->query("CREATE INDEX `idx_user_activity_group_created` ON `user_activity_log` (`action_group`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс истории действий по группе.');
    }

    if (!bober_index_exists($conn, 'user_activity_log', 'idx_user_activity_action_created') && !$conn->query("CREATE INDEX `idx_user_activity_action_created` ON `user_activity_log` (`action_type`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс истории действий по типу.');
    }

    $createUserClientEventLogSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_client_event_log` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `login_snapshot` VARCHAR(100) NOT NULL DEFAULT '',
    `event_uid` VARCHAR(128) NOT NULL,
    `batch_id` VARCHAR(100) NOT NULL DEFAULT '',
    `device_id` VARCHAR(100) NOT NULL DEFAULT '',
    `client_session_id` VARCHAR(100) NOT NULL DEFAULT '',
    `sequence_no` BIGINT NOT NULL DEFAULT 0,
    `client_ts` BIGINT NOT NULL DEFAULT 0,
    `page` VARCHAR(64) NOT NULL DEFAULT 'main_clicker',
    `action_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `action_type` VARCHAR(100) NOT NULL DEFAULT '',
    `source` VARCHAR(50) NOT NULL DEFAULT 'client',
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `score_snapshot` BIGINT NOT NULL DEFAULT 0,
    `energy_snapshot` BIGINT NOT NULL DEFAULT 0,
    `plus_snapshot` BIGINT NOT NULL DEFAULT 0,
    `payload_json` LONGTEXT NULL,
    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `received_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserClientEventLogSql)) {
        throw new RuntimeException('Не удалось создать таблицу подробного клиентского лога.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log', 'uniq_user_client_event_uid') && !$conn->query("CREATE UNIQUE INDEX `uniq_user_client_event_uid` ON `user_client_event_log` (`event_uid`)")) {
        throw new RuntimeException('Не удалось создать уникальный индекс подробного клиентского лога.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log', 'idx_user_client_event_user_received') && !$conn->query("CREATE INDEX `idx_user_client_event_user_received` ON `user_client_event_log` (`user_id`, `received_at`)")) {
        throw new RuntimeException('Не удалось создать индекс подробного клиентского лога по пользователю.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log', 'idx_user_client_event_user_client_ts') && !$conn->query("CREATE INDEX `idx_user_client_event_user_client_ts` ON `user_client_event_log` (`user_id`, `client_ts`)")) {
        throw new RuntimeException('Не удалось создать индекс подробного клиентского лога по времени клиента.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log', 'idx_user_client_event_group_received') && !$conn->query("CREATE INDEX `idx_user_client_event_group_received` ON `user_client_event_log` (`user_id`, `action_group`, `received_at`)")) {
        throw new RuntimeException('Не удалось создать индекс подробного клиентского лога по группе.');
    }

    $createUserClientEventLogArchiveSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_client_event_log_archive` (
    `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `login_snapshot` VARCHAR(100) NOT NULL DEFAULT '',
    `event_uid` VARCHAR(128) NOT NULL,
    `batch_id` VARCHAR(100) NOT NULL DEFAULT '',
    `device_id` VARCHAR(100) NOT NULL DEFAULT '',
    `client_session_id` VARCHAR(100) NOT NULL DEFAULT '',
    `sequence_no` BIGINT NOT NULL DEFAULT 0,
    `client_ts` BIGINT NOT NULL DEFAULT 0,
    `page` VARCHAR(64) NOT NULL DEFAULT 'main_clicker',
    `action_group` VARCHAR(50) NOT NULL DEFAULT 'general',
    `action_type` VARCHAR(100) NOT NULL DEFAULT '',
    `source` VARCHAR(50) NOT NULL DEFAULT 'client',
    `description` VARCHAR(255) NULL DEFAULT NULL,
    `score_snapshot` BIGINT NOT NULL DEFAULT 0,
    `energy_snapshot` BIGINT NOT NULL DEFAULT 0,
    `plus_snapshot` BIGINT NOT NULL DEFAULT 0,
    `payload_json` LONGTEXT NULL,
    `ip_address` VARCHAR(64) NULL DEFAULT NULL,
    `user_agent` VARCHAR(255) NULL DEFAULT NULL,
    `received_at` TIMESTAMP NULL DEFAULT NULL,
    `archived_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `archive_reason` VARCHAR(100) NOT NULL DEFAULT 'retention_policy'
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createUserClientEventLogArchiveSql)) {
        throw new RuntimeException('Не удалось создать архив подробного клиентского лога.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log_archive', 'uniq_user_client_event_archive_uid') && !$conn->query("CREATE UNIQUE INDEX `uniq_user_client_event_archive_uid` ON `user_client_event_log_archive` (`event_uid`)")) {
        throw new RuntimeException('Не удалось создать уникальный индекс архива клиентского лога.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log_archive', 'idx_user_client_event_archive_user_received') && !$conn->query("CREATE INDEX `idx_user_client_event_archive_user_received` ON `user_client_event_log_archive` (`user_id`, `received_at`)")) {
        throw new RuntimeException('Не удалось создать индекс архива клиентского лога по пользователю.');
    }

    if (!bober_index_exists($conn, 'user_client_event_log_archive', 'idx_user_client_event_archive_archived_at') && !$conn->query("CREATE INDEX `idx_user_client_event_archive_archived_at` ON `user_client_event_log_archive` (`archived_at`)")) {
        throw new RuntimeException('Не удалось создать индекс архива клиентского лога по дате архивации.');
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

    if (!empty($ban['isPermanent'])) {
        return 'Аккаунт заблокирован бессрочно.';
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

    $meta = [];
    if (!empty($row['meta_json']) && is_string($row['meta_json'])) {
        $decodedMeta = json_decode($row['meta_json'], true);
        if (is_array($decodedMeta)) {
            $meta = $decodedMeta;
        }
    }

    $ban = [
        'id' => max(0, (int) ($row['id'] ?? 0)),
        'userId' => max(0, (int) ($row['user_id'] ?? $row['userId'] ?? 0)),
        'source' => trim((string) ($row['source'] ?? 'autoclicker')),
        'reason' => trim((string) ($row['reason'] ?? 'Подозрение на автокликер')),
        'durationDays' => max(0, (int) ($row['duration_days'] ?? $row['durationDays'] ?? 1)),
        'isRepeat' => (int) ($row['is_repeat'] ?? $row['isRepeat'] ?? 0) === 1,
        'detectedBy' => trim((string) ($row['detected_by'] ?? $row['detectedBy'] ?? 'system')),
        'banUntil' => trim((string) ($row['ban_until'] ?? $row['banUntil'] ?? '')),
        'createdAt' => trim((string) ($row['created_at'] ?? $row['createdAt'] ?? '')),
        'liftedAt' => isset($row['lifted_at']) ? (string) $row['lifted_at'] : ($row['liftedAt'] ?? null),
        'ipAddress' => trim((string) ($meta['ip'] ?? $row['ip_address'] ?? $row['ipAddress'] ?? '')),
        'userAgent' => trim((string) ($meta['user_agent'] ?? $row['user_agent'] ?? $row['userAgent'] ?? '')),
    ];
    $ban['isPermanent'] = $ban['durationDays'] === 0 || strtotime($ban['banUntil']) >= strtotime('2099-01-01 00:00:00');

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

function bober_get_client_user_agent()
{
    return trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}

function bober_cleanup_stale_game_sessions($conn)
{
    static $lastCleanupAt = 0;

    $now = time();
    if (($now - $lastCleanupAt) < 300) {
        return;
    }

    bober_ensure_security_schema($conn);

    $cutoff = gmdate('Y-m-d H:i:s', $now - bober_session_lifetime_seconds() - 86400);
    $stmt = $conn->prepare('DELETE FROM user_sessions WHERE last_seen_at < ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить очистку старых игровых сессий.');
    }

    $stmt->bind_param('s', $cutoff);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось очистить старые игровые сессии.');
    }

    $stmt->close();
    $lastCleanupAt = $now;
}

function bober_session_platform_label($userAgent)
{
    $userAgent = trim((string) $userAgent);

    if ($userAgent === '') {
        return 'Неизвестная платформа';
    }

    $platformMap = [
        '/iPad/i' => 'iPadOS',
        '/iPhone/i' => 'iOS',
        '/Android/i' => 'Android',
        '/Windows/i' => 'Windows',
        '/Mac OS X|Macintosh/i' => 'macOS',
        '/Linux/i' => 'Linux',
    ];

    foreach ($platformMap as $pattern => $label) {
        if (preg_match($pattern, $userAgent) === 1) {
            return $label;
        }
    }

    return 'Неизвестная платформа';
}

function bober_session_browser_label($userAgent)
{
    $userAgent = trim((string) $userAgent);

    if ($userAgent === '') {
        return 'Неизвестный браузер';
    }

    $browserMap = [
        '/YaBrowser/i' => 'Yandex Browser',
        '/SamsungBrowser/i' => 'Samsung Internet',
        '/Edg\//i' => 'Microsoft Edge',
        '/OPR\//i' => 'Opera',
        '/Chrome\//i' => 'Google Chrome',
        '/Firefox\//i' => 'Mozilla Firefox',
        '/Safari\//i' => 'Safari',
    ];

    foreach ($browserMap as $pattern => $label) {
        if (preg_match($pattern, $userAgent) === 1) {
            return $label;
        }
    }

    return 'Неизвестный браузер';
}

function bober_session_device_label($userAgent)
{
    $userAgent = trim((string) $userAgent);
    $platform = bober_session_platform_label($userAgent);

    if ($userAgent !== '' && preg_match('/iPad|Tablet/i', $userAgent) === 1) {
        return 'Планшет ' . $platform;
    }

    if ($userAgent !== '' && preg_match('/Android|iPhone|Mobile|webOS|BlackBerry|IEMobile|Opera Mini/i', $userAgent) === 1) {
        return 'Телефон ' . $platform;
    }

    return 'ПК ' . $platform;
}

function bober_normalize_game_session_row($row, $currentSessionHash = '')
{
    if (!is_array($row)) {
        return null;
    }

    $sessionHash = trim((string) ($row['session_hash'] ?? ''));
    $userAgent = trim((string) ($row['user_agent'] ?? $row['userAgent'] ?? ''));

    return [
        'sessionId' => max(0, (int) ($row['id'] ?? $row['session_id'] ?? $row['sessionId'] ?? 0)),
        'userId' => max(0, (int) ($row['user_id'] ?? $row['userId'] ?? 0)),
        'login' => trim((string) ($row['login_snapshot'] ?? $row['login'] ?? '')),
        'deviceLabel' => bober_session_device_label($userAgent),
        'platformLabel' => bober_session_platform_label($userAgent),
        'browserLabel' => bober_session_browser_label($userAgent),
        'userAgent' => $userAgent,
        'ipAddress' => trim((string) ($row['ip_address'] ?? $row['ipAddress'] ?? '')),
        'lastActivitySource' => trim((string) ($row['last_activity_source'] ?? $row['lastActivitySource'] ?? 'runtime')),
        'createdAt' => trim((string) ($row['created_at'] ?? $row['createdAt'] ?? '')),
        'lastSeenAt' => trim((string) ($row['last_seen_at'] ?? $row['lastSeenAt'] ?? '')),
        'revokedAt' => trim((string) ($row['revoked_at'] ?? $row['revokedAt'] ?? '')),
        'isCurrent' => $currentSessionHash !== '' && $sessionHash !== '' && hash_equals($currentSessionHash, $sessionHash),
    ];
}

function bober_fetch_game_session_row_by_hash($conn, $sessionHash)
{
    bober_ensure_security_schema($conn);

    $sessionHash = trim((string) $sessionHash);
    if ($sessionHash === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT id, user_id, session_hash, login_snapshot, ip_address, user_agent, last_activity_source, created_at, last_seen_at, revoked_at, revoked_reason, meta_json FROM user_sessions WHERE session_hash = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение игровой сессии.');
    }

    $stmt->bind_param('s', $sessionHash);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить игровую сессию.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $row ?: null;
}

function bober_sync_current_game_session($conn, $userId, $login = '', $options = [])
{
    bober_ensure_security_schema($conn);
    bober_cleanup_stale_game_sessions($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя для игровой сессии.');
    }

    $sessionHash = bober_current_session_hash();
    if ($sessionHash === '') {
        return null;
    }

    $loginSnapshot = trim((string) $login);
    if ($loginSnapshot === '') {
        $loginSnapshot = trim((string) ($_SESSION['game_login'] ?? ''));
    }

    $ipAddress = trim((string) bober_get_client_ip());
    if ($ipAddress === '' || filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
        $ipAddress = null;
    }

    $userAgent = bober_get_client_user_agent();
    if ($userAgent === '') {
        $userAgent = null;
    }

    $activitySource = trim((string) ($options['source'] ?? 'runtime'));
    if ($activitySource === '') {
        $activitySource = 'runtime';
    }

    $metaJson = json_encode([
        'ip' => $ipAddress,
        'user_agent' => $userAgent,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $conn->prepare('INSERT INTO user_sessions (user_id, session_hash, login_snapshot, ip_address, user_agent, last_activity_source, last_seen_at, revoked_at, revoked_reason, meta_json) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, NULL, NULL, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), login_snapshot = VALUES(login_snapshot), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), last_activity_source = VALUES(last_activity_source), last_seen_at = CURRENT_TIMESTAMP, revoked_at = NULL, revoked_reason = NULL, meta_json = VALUES(meta_json)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сохранение игровой сессии.');
    }

    $stmt->bind_param('issssss', $userId, $sessionHash, $loginSnapshot, $ipAddress, $userAgent, $activitySource, $metaJson);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сохранить игровую сессию.');
    }
    $stmt->close();

    return bober_fetch_game_session_row_by_hash($conn, $sessionHash);
}

function bober_fetch_user_active_game_sessions($conn, $userId, $options = [])
{
    bober_ensure_security_schema($conn);
    bober_cleanup_stale_game_sessions($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    $excludeSessionHash = trim((string) ($options['exclude_session_hash'] ?? ''));
    $currentSessionHash = trim((string) ($options['current_session_hash'] ?? bober_current_session_hash()));

    $sql = 'SELECT id, user_id, session_hash, login_snapshot, ip_address, user_agent, last_activity_source, created_at, last_seen_at, revoked_at, revoked_reason, meta_json FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL';
    if ($excludeSessionHash !== '') {
        $sql .= ' AND session_hash <> ?';
    }
    $sql .= ' ORDER BY last_seen_at DESC, created_at DESC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение списка игровых сессий.');
    }

    if ($excludeSessionHash !== '') {
        $stmt->bind_param('is', $userId, $excludeSessionHash);
    } else {
        $stmt->bind_param('i', $userId);
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить список игровых сессий.');
    }

    $result = $stmt->get_result();
    $sessions = [];
    while ($result && ($row = $result->fetch_assoc())) {
        $normalized = bober_normalize_game_session_row($row, $currentSessionHash);
        if ($normalized !== null) {
            $sessions[] = $normalized;
        }
    }

    if ($result) {
        $result->free();
    }
    $stmt->close();

    return $sessions;
}

function bober_revoke_game_session_by_hash($conn, $sessionHash, $reason = 'manual')
{
    bober_ensure_security_schema($conn);

    $sessionHash = trim((string) $sessionHash);
    if ($sessionHash === '') {
        return false;
    }

    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'manual';
    }

    $stmt = $conn->prepare('UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE session_hash = ? AND revoked_at IS NULL');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить завершение игровой сессии.');
    }

    $stmt->bind_param('ss', $reason, $sessionHash);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось завершить игровую сессию.');
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows > 0;
}

function bober_revoke_game_session_by_id($conn, $userId, $sessionId, $reason = 'manual')
{
    bober_ensure_security_schema($conn);

    $userId = max(0, (int) $userId);
    $sessionId = max(0, (int) $sessionId);
    if ($userId < 1 || $sessionId < 1) {
        return false;
    }

    $reason = trim((string) $reason);
    if ($reason === '') {
        $reason = 'manual';
    }

    $stmt = $conn->prepare('UPDATE user_sessions SET revoked_at = CURRENT_TIMESTAMP, revoked_reason = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить завершение выбранной игровой сессии.');
    }

    $stmt->bind_param('sii', $reason, $sessionId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось завершить выбранную игровую сессию.');
    }

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows > 0;
}

function bober_build_session_conflict_payload(array $sessions, $message = '')
{
    return [
        'success' => false,
        'message' => $message !== ''
            ? $message
            : 'Аккаунт уже открыт на другом устройстве. Завершите одну из активных сессий, чтобы войти здесь.',
        'sessionConflict' => true,
        'sessions' => array_values($sessions),
        'maxActiveSessions' => 1,
        'incomingSession' => [
            'deviceLabel' => bober_session_device_label(bober_get_client_user_agent()),
            'platformLabel' => bober_session_platform_label(bober_get_client_user_agent()),
            'browserLabel' => bober_session_browser_label(bober_get_client_user_agent()),
            'ipAddress' => trim((string) bober_get_client_ip()),
        ],
    ];
}

function bober_build_session_ended_payload($terminatedSession = null, $message = '')
{
    return [
        'success' => false,
        'message' => $message !== ''
            ? $message
            : 'Эта игровая сессия была завершена на другом устройстве. Войдите заново.',
        'sessionEnded' => true,
        'terminatedSession' => is_array($terminatedSession) ? $terminatedSession : null,
    ];
}

function bober_validate_current_game_session($conn, $userId, $options = [])
{
    bober_ensure_security_schema($conn);
    bober_cleanup_stale_game_sessions($conn);

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'ok' => false,
            'payload' => bober_build_session_ended_payload(null, 'Сессия не найдена. Войдите заново.'),
        ];
    }

    $sessionHash = bober_current_session_hash();
    if ($sessionHash === '') {
        return [
            'ok' => false,
            'payload' => bober_build_session_ended_payload(null, 'Сессия не найдена. Войдите заново.'),
        ];
    }

    $currentRow = bober_fetch_game_session_row_by_hash($conn, $sessionHash);
    if (is_array($currentRow) && !empty($currentRow['revoked_at'])) {
        return [
            'ok' => false,
            'payload' => bober_build_session_ended_payload(
                bober_normalize_game_session_row($currentRow, $sessionHash)
            ),
        ];
    }

    if (is_array($currentRow) && max(0, (int) ($currentRow['user_id'] ?? 0)) !== $userId) {
        bober_revoke_game_session_by_hash($conn, $sessionHash, 'session_user_mismatch');
    }

    $loginSnapshot = trim((string) ($options['login'] ?? ($_SESSION['game_login'] ?? '')));
    $syncedRow = bober_sync_current_game_session($conn, $userId, $loginSnapshot, [
        'source' => $options['source'] ?? 'runtime',
    ]);

    return [
        'ok' => true,
        'session' => $syncedRow ? bober_normalize_game_session_row($syncedRow, $sessionHash) : null,
    ];
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

    if (!empty($ban['isPermanent'])) {
        return 'С этого IP бессрочно ограничены вход в аккаунты и регистрация новых профилей.';
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
        'userAgent' => trim((string) ($row['user_agent'] ?? $row['userAgent'] ?? '')),
    ];
    $ban['isPermanent'] = strtotime($ban['banUntil']) >= strtotime('2099-01-01 00:00:00');

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

    $stmt = $conn->prepare('SELECT id, user_id, source, reason, duration_days, is_repeat, detected_by, ban_until, created_at, lifted_at, meta_json FROM user_bans WHERE user_id = ? AND lifted_at IS NULL AND ban_until > CURRENT_TIMESTAMP ORDER BY ban_until DESC LIMIT 1');
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

function bober_enforce_runtime_access_rules($conn, $userId, $options = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $logoutUser = array_key_exists('logout_user', $options)
        ? (bool) $options['logout_user']
        : true;

    $sessionValidation = bober_validate_current_game_session($conn, $userId, [
        'source' => $options['source'] ?? 'runtime',
        'login' => $options['login'] ?? ($_SESSION['game_login'] ?? ''),
    ]);
    if (empty($sessionValidation['ok'])) {
        $payload = is_array($sessionValidation['payload'] ?? null)
            ? $sessionValidation['payload']
            : bober_build_session_ended_payload();

        $conn->close();
        if ($logoutUser) {
            bober_logout_user(['skip_session_revoke' => true]);
        }
        bober_json_response($payload, 409);
    }

    $activeIpBan = bober_fetch_active_ip_ban($conn);
    if ($activeIpBan !== null) {
        $conn->close();
        if ($logoutUser) {
            bober_logout_user();
        }
        bober_json_response([
            'success' => false,
            'message' => $activeIpBan['message'],
            'ipBan' => $activeIpBan,
        ], 403);
    }

    $activeBan = bober_fetch_active_user_ban($conn, $userId);
    if ($activeBan !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, $userId, $activeBan, [
            'detected_by' => 'runtime_guard',
            'include_current_ip' => true,
        ]);
        $conn->close();
        if ($logoutUser) {
            bober_logout_user();
        }
        bober_json_response([
            'success' => false,
            'message' => $activeBan['message'],
            'ban' => $activeBan,
        ], 403);
    }
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

function bober_count_user_bans($conn, $userId, $source = null)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return 0;
    }

    if ($source === null || trim((string) $source) === '') {
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_bans WHERE user_id = ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить подсчет банов.');
        }

        $stmt->bind_param('i', $userId);
    } else {
        $source = trim((string) $source);
        $stmt = $conn->prepare('SELECT COUNT(*) AS total FROM user_bans WHERE user_id = ? AND source = ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить подсчет банов.');
        }

        $stmt->bind_param('is', $userId, $source);
    }

    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить подсчет банов.');
    }
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

    $previousBanCount = bober_count_user_bans($conn, $userId, null);
    $defaultDurationDays = $previousBanCount > 0 ? 30 : 5;
    $isRepeat = $previousBanCount > 0 ? 1 : 0;
    $requestedDurationDays = isset($details['duration_days']) ? (int) $details['duration_days'] : null;
    $isPermanent = !empty($details['is_permanent']) || $previousBanCount >= 2;

    if ($isPermanent) {
        $durationDays = 0;
        $banUntil = '2099-12-31 23:59:59';
    } else {
        $durationDays = $requestedDurationDays !== null && $requestedDurationDays > 0
            ? $requestedDurationDays
            : $defaultDurationDays;
        $banUntil = date('Y-m-d H:i:s', time() + ($durationDays * 24 * 60 * 60));
    }

    $meta = is_array($details['meta'] ?? null) ? $details['meta'] : [];
    $meta['ip'] = $meta['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
    $meta['user_agent'] = $meta['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null);
    $meta['ban_stage'] = $meta['ban_stage'] ?? ($previousBanCount + 1);
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
