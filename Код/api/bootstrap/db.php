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

    $configFile = dirname(__DIR__, 2) . '/config/db_config.php';
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
    $encodedPayload = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if ($encodedPayload === false) {
        $encodedPayload = json_encode(
            [
                'success' => false,
                'message' => 'Не удалось сформировать ответ сервера.',
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    if ($encodedPayload === false) {
        $encodedPayload = '{"success":false,"message":"Не удалось сформировать ответ сервера."}';
    }
    echo $encodedPayload;
    exit;
}

function bober_exception_message($error, $fallback = 'Ошибка сервера.')
{
    if (bober_is_database_quota_exceeded_error($error)) {
        return bober_database_quota_exceeded_message();
    }

    if ($error instanceof InvalidArgumentException || $error instanceof RuntimeException) {
        $message = trim((string) $error->getMessage());
        if ($message !== '') {
            return $message;
        }
    }

    return $fallback;
}

function bober_is_database_quota_exceeded_message_text($message)
{
    $message = strtolower(trim((string) $message));
    if ($message === '') {
        return false;
    }

    return strpos($message, 'max_queries_per_hour') !== false
        || (strpos($message, 'queries_per_hour') !== false && strpos($message, 'exceeded') !== false)
        || (strpos($message, 'resource') !== false && strpos($message, 'queries') !== false && strpos($message, 'exceeded') !== false)
        || strpos($message, 'почасовой лимит запросов') !== false
        || strpos($message, 'лимит запросов к базе данных') !== false
        || strpos($message, 'лимит запросов к бд') !== false;
}

function bober_is_database_quota_exceeded_error($error)
{
    if ($error instanceof Throwable) {
        return bober_is_database_quota_exceeded_message_text($error->getMessage());
    }

    return bober_is_database_quota_exceeded_message_text($error);
}

function bober_database_quota_exceeded_message()
{
    return 'База данных на бесплатном хостинге временно уперлась в почасовой лимит запросов.';
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
            throw new RuntimeException('Параметры базы данных не настроены. Создайте `Код/config/db_config.php` или задайте переменные окружения `BOBER_DB_*`.');
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

function bober_schema_guard_version()
{
    static $version = null;

    if ($version !== null) {
        return $version;
    }

    $version = substr(hash('sha256', __FILE__ . '|' . (string) @filemtime(__FILE__)), 0, 16);
    return $version;
}

function bober_schema_guard_directory()
{
    static $directory = null;

    if ($directory !== null) {
        return $directory;
    }

    $candidates = [
        dirname(__DIR__, 2) . '/cache/runtime',
        dirname(__DIR__, 2) . '/cache',
        sys_get_temp_dir(),
    ];

    foreach ($candidates as $candidate) {
        $candidate = rtrim((string) $candidate, '/\\');
        if ($candidate === '') {
            continue;
        }

        if (!is_dir($candidate)) {
            @mkdir($candidate, 0775, true);
        }

        if (is_dir($candidate) && is_writable($candidate)) {
            $directory = $candidate;
            return $directory;
        }
    }

    $directory = '';
    return $directory;
}

function bober_schema_guard_path($scope)
{
    $scope = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower(trim((string) $scope))) ?? '';
    if ($scope === '') {
        $scope = 'default';
    }

    $directory = bober_schema_guard_directory();
    if ($directory === '') {
        return '';
    }

    return $directory . '/bober-schema-' . $scope . '-' . bober_schema_guard_version() . '.lock';
}

function bober_schema_guard_is_fresh($scope, $ttlSeconds = 600)
{
    $path = bober_schema_guard_path($scope);
    if ($path === '' || !is_file($path)) {
        return false;
    }

    $modifiedAt = @filemtime($path);
    if (!is_int($modifiedAt) || $modifiedAt < 1) {
        return false;
    }

    return ($modifiedAt + max(30, (int) $ttlSeconds)) > time();
}

function bober_schema_guard_touch($scope)
{
    $path = bober_schema_guard_path($scope);
    if ($path === '') {
        return;
    }

    @file_put_contents($path, (string) time(), LOCK_EX);
    @touch($path);
}

function bober_file_cache_path($cacheKey)
{
    $cacheKey = preg_replace('/[^a-z0-9_-]+/i', '_', strtolower(trim((string) $cacheKey))) ?? '';
    if ($cacheKey === '') {
        return '';
    }

    $directory = bober_schema_guard_directory();
    if ($directory === '') {
        return '';
    }

    return $directory . '/bober-cache-' . $cacheKey . '-' . bober_schema_guard_version() . '.json';
}

function bober_file_cache_fetch($cacheKey, $ttlSeconds = 60)
{
    $path = bober_file_cache_path($cacheKey);
    if ($path === '' || !is_file($path)) {
        return null;
    }

    $modifiedAt = @filemtime($path);
    if (!is_int($modifiedAt) || $modifiedAt < 1) {
        return null;
    }

    if (($modifiedAt + max(1, (int) $ttlSeconds)) <= time()) {
        return null;
    }

    $payloadJson = @file_get_contents($path);
    if (!is_string($payloadJson) || trim($payloadJson) === '') {
        return null;
    }

    $decoded = json_decode($payloadJson, true);
    if (!is_array($decoded) || !array_key_exists('payload', $decoded)) {
        return null;
    }

    return $decoded['payload'];
}

function bober_file_cache_store($cacheKey, $payload)
{
    $path = bober_file_cache_path($cacheKey);
    if ($path === '') {
        return false;
    }

    $encoded = json_encode([
        'storedAt' => time(),
        'payload' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($encoded === false) {
        return false;
    }

    return @file_put_contents($path, $encoded, LOCK_EX) !== false;
}

function bober_file_cache_delete($cacheKey)
{
    $path = bober_file_cache_path($cacheKey);
    if ($path === '' || !is_file($path)) {
        return false;
    }

    return @unlink($path);
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

function bober_clicker_top_reward_skin_id()
{
    return 'top-1-bober-kliker';
}

function bober_clicker_top_reward_skin_defaults()
{
    return [
        'id' => bober_clicker_top_reward_skin_id(),
        'name' => 'Топ-1 бобер кликера',
        'price' => 0,
        'image' => '/assets/skins/top-1-bober-kliker.png',
        'available' => false,
        'rarity' => 'legendary',
        'category' => 'top',
        'issue_mode' => 'grant_only',
    ];
}

function bober_fly_beaver_top_reward_skin_id()
{
    return 'top-1-bober-fly-beaver';
}

function bober_fly_beaver_top_reward_skin_defaults()
{
    return [
        'id' => bober_fly_beaver_top_reward_skin_id(),
        'name' => 'Топ-1 летающий бобер',
        'price' => 0,
        'image' => '/assets/skins/top-1-letayuschiy-bober.png',
        'available' => false,
        'rarity' => 'legendary',
        'category' => 'top',
        'issue_mode' => 'grant_only',
    ];
}

function bober_skin_catalog_file_path()
{
    return dirname(__DIR__, 2) . '/data/skin-catalog.json';
}

function bober_builtin_skin_catalog()
{
    return [
        'classic' => [
            'id' => 'classic',
            'name' => 'Просто бобер',
            'price' => 0,
            'image' => '/assets/skins/bober.png',
            'available' => true,
            'rarity' => 'common',
            'category' => 'classic',
            'issue_mode' => 'starter',
        ],
        'paper' => [
            'id' => 'paper',
            'name' => 'Бумажный бобер',
            'price' => 5000,
            'image' => '/assets/skins/bumazny-bober.jpg',
            'available' => true,
            'rarity' => 'common',
            'category' => 'fun',
            'issue_mode' => 'shop',
        ],
        'standard' => [
            'id' => 'standard',
            'name' => 'Стандартный бобер',
            'price' => 0,
            'image' => '/assets/skins/matvey-new-bober.jpg',
            'available' => true,
            'rarity' => 'common',
            'category' => 'classic',
            'issue_mode' => 'starter',
        ],
        'strawberry' => [
            'id' => 'strawberry',
            'name' => 'Клубничный йогурт бобер',
            'price' => 15000,
            'image' => '/assets/skins/klub-smz-bober.jpg',
            'available' => true,
            'rarity' => 'uncommon',
            'category' => 'food',
            'issue_mode' => 'shop',
        ],
        'sock' => [
            'id' => 'sock',
            'name' => 'Носок бобер',
            'price' => 30000,
            'image' => '/assets/skins/nosok-bober.jpg',
            'available' => true,
            'rarity' => 'epic',
            'category' => 'fun',
            'issue_mode' => 'shop',
        ],
        'chocolate' => [
            'id' => 'chocolate',
            'name' => 'Шоколад бобер',
            'price' => 50000,
            'image' => '/assets/skins/Shok-upok-bober.jpg',
            'available' => true,
            'rarity' => 'rare',
            'category' => 'food',
            'issue_mode' => 'shop',
        ],
        'strange' => [
            'id' => 'strange',
            'name' => 'Странный бобер',
            'price' => 30000,
            'image' => '/assets/skins/strany-bober.jpg',
            'available' => true,
            'rarity' => 'rare',
            'category' => 'mystic',
            'issue_mode' => 'shop',
        ],
        'dev' => [
            'id' => 'dev',
            'name' => 'Dev бобер',
            'price' => 90000,
            'image' => '/assets/skins/dev.png',
            'available' => false,
            'rarity' => 'admin',
            'category' => 'admin',
            'issue_mode' => 'grant_only',
        ],
        bober_clicker_top_reward_skin_id() => bober_clicker_top_reward_skin_defaults(),
        bober_fly_beaver_top_reward_skin_id() => bober_fly_beaver_top_reward_skin_defaults(),
    ];
}

function bober_skin_rarity_values()
{
    return ['common', 'uncommon', 'rare', 'epic', 'legendary', 'admin'];
}

function bober_skin_category_values()
{
    return ['classic', 'top', 'food', 'fun', 'mystic', 'event', 'nature', 'neon', 'seasonal', 'pixel', 'space', 'cyber', 'royal', 'sport', 'retro', 'meme', 'admin', 'other'];
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

function bober_merge_required_skin_catalog_items(array $catalogItems)
{
    $catalogMap = [];
    foreach ($catalogItems as $item) {
        if (!is_array($item)) {
            continue;
        }

        $itemId = trim((string) ($item['id'] ?? ''));
        if ($itemId === '') {
            continue;
        }

        $catalogMap[$itemId] = $item;
    }

    $topSkinId = bober_clicker_top_reward_skin_id();
    $topSkinDefaults = bober_clicker_top_reward_skin_defaults();
    $existingTopSkin = is_array($catalogMap[$topSkinId] ?? null) ? $catalogMap[$topSkinId] : [];
    $catalogMap[$topSkinId] = array_merge($topSkinDefaults, $existingTopSkin, [
        'id' => $topSkinId,
        'price' => 0,
        'category' => 'top',
        'issue_mode' => 'grant_only',
        'default_owned' => false,
        'grant_only' => true,
    ]);

    $flyTopSkinId = bober_fly_beaver_top_reward_skin_id();
    $flyTopSkinDefaults = bober_fly_beaver_top_reward_skin_defaults();
    $existingFlyTopSkin = is_array($catalogMap[$flyTopSkinId] ?? null) ? $catalogMap[$flyTopSkinId] : [];
    $catalogMap[$flyTopSkinId] = array_merge($flyTopSkinDefaults, $existingFlyTopSkin, [
        'id' => $flyTopSkinId,
        'price' => 0,
        'category' => 'top',
        'issue_mode' => 'grant_only',
        'default_owned' => false,
        'grant_only' => true,
    ]);

    return array_values($catalogMap);
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

                $catalogItems = bober_merge_required_skin_catalog_items(array_values($catalog));
                $catalog = [];
                foreach ($catalogItems as $item) {
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
    $catalogItems = bober_merge_required_skin_catalog_items($catalogItems);
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

function bober_default_upgrade_counts()
{
    return [
        'tapSmall' => 0,
        'tapBig' => 0,
        'energy' => 0,
        'tapHuge' => 0,
        'regenBoost' => 0,
        'energyHuge' => 0,
        'clickRate' => 0,
    ];
}

function bober_normalize_upgrade_counts($rawCounts)
{
    $defaults = bober_default_upgrade_counts();
    $normalized = $defaults;

    if (!is_array($rawCounts)) {
        return $normalized;
    }

    foreach ($defaults as $key => $defaultValue) {
        $normalized[$key] = max(0, (int) ($rawCounts[$key] ?? $defaultValue));
    }

    return $normalized;
}

function bober_upgrade_base_costs()
{
    return [
        'tapSmall' => 5000,
        'tapBig' => 20000,
        'energy' => 10000,
        'tapHuge' => 2500000,
        'regenBoost' => 35000,
        'energyHuge' => 90000,
        'clickRate' => 1800000,
    ];
}

function bober_upgrade_shop_catalog()
{
    return [
        'tapSmall' => [
            'baseCost' => 5000,
            'actionType' => 'upgrade_tap_small_purchase',
            'description' => 'Куплено улучшение +1 к тапу.',
        ],
        'tapBig' => [
            'baseCost' => 20000,
            'actionType' => 'upgrade_tap_big_purchase',
            'description' => 'Куплено улучшение +5 к тапу.',
        ],
        'energy' => [
            'baseCost' => 10000,
            'actionType' => 'upgrade_energy_purchase',
            'description' => 'Куплено улучшение запаса энергии.',
        ],
        'tapHuge' => [
            'baseCost' => 2500000,
            'actionType' => 'upgrade_tap_huge_purchase',
            'description' => 'Куплено улучшение +100 к тапу.',
        ],
        'regenBoost' => [
            'baseCost' => 35000,
            'actionType' => 'upgrade_regen_boost_purchase',
            'description' => 'Куплено улучшение скорости восполнения энергии.',
        ],
        'energyHuge' => [
            'baseCost' => 90000,
            'actionType' => 'upgrade_energy_huge_purchase',
            'description' => 'Куплено улучшение +10000 к запасу энергии.',
        ],
        'clickRate' => [
            'baseCost' => 1800000,
            'actionType' => 'upgrade_click_rate_purchase',
            'description' => 'Куплено улучшение лимита засчитываемых кликов.',
        ],
    ];
}

function bober_calculate_plus_from_upgrade_counts(array $upgradeCounts)
{
    $normalized = bober_normalize_upgrade_counts($upgradeCounts);
    return 1
        + $normalized['tapSmall']
        + ($normalized['tapBig'] * 5)
        + ($normalized['tapHuge'] * 100);
}

function bober_calculate_energy_max_from_upgrade_counts(array $upgradeCounts)
{
    $normalized = bober_normalize_upgrade_counts($upgradeCounts);
    return 5000
        + ($normalized['energy'] * 1000)
        + ($normalized['energyHuge'] * 10000);
}

function bober_calculate_click_rate_limit_from_upgrade_counts(array $upgradeCounts)
{
    $normalized = bober_normalize_upgrade_counts($upgradeCounts);
    return 15 + ($normalized['clickRate'] * 3);
}

function bober_clamp_float($value, $min, $max)
{
    $resolvedValue = (float) $value;
    $resolvedMin = (float) $min;
    $resolvedMax = (float) $max;
    if ($resolvedValue < $resolvedMin) {
        return $resolvedMin;
    }
    if ($resolvedValue > $resolvedMax) {
        return $resolvedMax;
    }

    return $resolvedValue;
}

function bober_count_purchased_shop_skins(array $catalog, array $ownedSkinIds)
{
    $count = 0;
    foreach ($ownedSkinIds as $skinId) {
        $resolvedSkinId = trim((string) $skinId);
        if ($resolvedSkinId === '') {
            continue;
        }

        $skinConfig = is_array($catalog[$resolvedSkinId] ?? null) ? $catalog[$resolvedSkinId] : null;
        if (!$skinConfig) {
            continue;
        }

        $issueMode = bober_normalize_skin_issue_mode($skinConfig['issue_mode'] ?? ($skinConfig['issueMode'] ?? ''), $skinConfig);
        if ($issueMode === 'shop') {
            $count += 1;
        }
    }

    return $count;
}

function bober_build_user_economy_profile(array $state)
{
    $score = max(0, (int) ($state['score'] ?? 0));
    $plus = max(1, (int) ($state['plus'] ?? 1));
    $energyMax = max(5000, (int) ($state['energyMax'] ?? 5000));
    $flyBest = max(0, (int) ($state['flyBest'] ?? 0));
    $ownedSkinIds = array_values(array_unique(array_map('strval', (array) ($state['ownedSkinIds'] ?? []))));
    $catalog = is_array($state['catalog'] ?? null) ? $state['catalog'] : bober_skin_catalog();
    $upgradeCounts = bober_normalize_upgrade_counts($state['upgradeCounts'] ?? []);
    $totalUpgradePurchases = array_sum($upgradeCounts);
    $purchasedShopSkinCount = bober_count_purchased_shop_skins($catalog, $ownedSkinIds);

    $scoreFactor = bober_clamp_float((log10(max($score, 1)) - 6) / 4, 0, 1);
    $plusFactor = bober_clamp_float((log10(max($plus, 1)) - 2) / 3, 0, 1);
    $upgradeFactor = bober_clamp_float($totalUpgradePurchases / 140, 0, 1);
    $shopSkinFactor = bober_clamp_float($purchasedShopSkinCount / 28, 0, 1);
    $energyFactor = bober_clamp_float(($energyMax - 5000) / 45000, 0, 1);
    $flyFactor = bober_clamp_float((log10(max($flyBest, 1)) - 3) / 3, 0, 1);

    $factors = [
        [
            'key' => 'scoreFactor',
            'label' => 'Счет',
            'value' => $score,
            'valueLabel' => number_format($score, 0, '.', ' '),
            'normalized' => round($scoreFactor, 4),
            'contribution' => round($scoreFactor * 0.30 * 100, 2),
        ],
        [
            'key' => 'plusFactor',
            'label' => 'Плюс за клик',
            'value' => $plus,
            'valueLabel' => '+' . number_format($plus, 0, '.', ' '),
            'normalized' => round($plusFactor, 4),
            'contribution' => round($plusFactor * 0.22 * 100, 2),
        ],
        [
            'key' => 'upgradeFactor',
            'label' => 'Всего покупок улучшений',
            'value' => $totalUpgradePurchases,
            'valueLabel' => number_format($totalUpgradePurchases, 0, '.', ' '),
            'normalized' => round($upgradeFactor, 4),
            'contribution' => round($upgradeFactor * 0.22 * 100, 2),
        ],
        [
            'key' => 'shopSkinFactor',
            'label' => 'Купленных магазинных скинов',
            'value' => $purchasedShopSkinCount,
            'valueLabel' => number_format($purchasedShopSkinCount, 0, '.', ' '),
            'normalized' => round($shopSkinFactor, 4),
            'contribution' => round($shopSkinFactor * 0.16 * 100, 2),
        ],
        [
            'key' => 'energyFactor',
            'label' => 'Макс. энергия',
            'value' => $energyMax,
            'valueLabel' => number_format($energyMax, 0, '.', ' '),
            'normalized' => round($energyFactor, 4),
            'contribution' => round($energyFactor * 0.06 * 100, 2),
        ],
        [
            'key' => 'flyFactor',
            'label' => 'Рекорд fly-beaver',
            'value' => $flyBest,
            'valueLabel' => number_format($flyBest, 0, '.', ' '),
            'normalized' => round($flyFactor, 4),
            'contribution' => round($flyFactor * 0.04 * 100, 2),
        ],
    ];

    usort($factors, static function (array $left, array $right) {
        return ($right['contribution'] <=> $left['contribution']) ?: strcmp((string) $left['label'], (string) $right['label']);
    });

    $weightedSum = ($scoreFactor * 0.30)
        + ($plusFactor * 0.22)
        + ($upgradeFactor * 0.22)
        + ($shopSkinFactor * 0.16)
        + ($energyFactor * 0.06)
        + ($flyFactor * 0.04);
    $index = max(0, min(100, (int) round($weightedSum * 100)));

    if ($index <= 35) {
        $multiplier = 1.0;
    } elseif ($index <= 70) {
        $multiplier = 1 + (($index - 35) * 0.005);
    } else {
        $multiplier = 1.175 + (($index - 70) * 0.0035);
    }

    return [
        'index' => $index,
        'multiplier' => round(min(1.30, max(1.0, $multiplier)), 4),
        'factors' => $factors,
    ];
}

function bober_calculate_effective_purchase_price($basePrice, array $economyProfile, $issueMode = 'shop')
{
    $resolvedBasePrice = max(0, (int) $basePrice);
    $resolvedIssueMode = bober_normalize_skin_issue_mode($issueMode);
    if ($resolvedBasePrice < 1 || $resolvedIssueMode !== 'shop') {
        return $resolvedBasePrice;
    }

    $multiplier = max(1.0, (float) ($economyProfile['multiplier'] ?? 1.0));
    return max($resolvedBasePrice, (int) round($resolvedBasePrice * $multiplier));
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

function bober_table_exists($conn, $table)
{
    bober_require_identifier($table, 'Имя таблицы');

    $escapedTable = $conn->real_escape_string($table);
    $result = $conn->query("SHOW TABLES LIKE '{$escapedTable}'");

    if ($result === false) {
        throw new RuntimeException('Не удалось проверить существование таблицы.');
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
    `upgrade_regen_boost_count` INT NOT NULL DEFAULT 0,
    `upgrade_energy_huge_count` INT NOT NULL DEFAULT 0,
    `upgrade_click_rate_count` INT NOT NULL DEFAULT 0,
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
        'upgrade_regen_boost_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_regen_boost_count` INT NOT NULL DEFAULT 0 AFTER `upgrade_tap_huge_count`",
        'upgrade_energy_huge_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_energy_huge_count` INT NOT NULL DEFAULT 0 AFTER `upgrade_regen_boost_count`",
        'upgrade_click_rate_count' => "ALTER TABLE `users` ADD COLUMN `upgrade_click_rate_count` INT NOT NULL DEFAULT 0 AFTER `upgrade_energy_huge_count`",
        'created_at' => "ALTER TABLE `users` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `upgrade_click_rate_count`",
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
        "UPDATE `users` SET `upgrade_regen_boost_count` = 0 WHERE `upgrade_regen_boost_count` IS NULL OR `upgrade_regen_boost_count` < 0",
        "UPDATE `users` SET `upgrade_energy_huge_count` = 0 WHERE `upgrade_energy_huge_count` IS NULL OR `upgrade_energy_huge_count` < 0",
        "UPDATE `users` SET `upgrade_click_rate_count` = 0 WHERE `upgrade_click_rate_count` IS NULL OR `upgrade_click_rate_count` < 0",
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
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('admin')) {
        $schemaEnsured = true;
        return;
    }

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
            bober_schema_guard_touch('admin');
            $schemaEnsured = true;
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
        bober_schema_guard_touch('admin');
        $schemaEnsured = true;
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

    bober_schema_guard_touch('admin');
    $schemaEnsured = true;
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

function bober_get_profile_activity_action_types($publicOnly = false)
{
    $types = [
        'upgrade_tap_small_purchase',
        'upgrade_tap_big_purchase',
        'upgrade_energy_purchase',
        'upgrade_tap_huge_purchase',
        'upgrade_regen_boost_purchase',
        'upgrade_energy_huge_purchase',
        'upgrade_click_rate_purchase',
        'equip_skin',
        'unlock_skin',
        'achievement_unlock',
        'quest_complete',
        'fly_run_saved',
        'fly_reward_claim',
    ];

    if ($publicOnly) {
        return $types;
    }

    return $types;
}

function bober_format_activity_number($value)
{
    return number_format((float) ((int) $value), 0, '.', ' ');
}

function bober_describe_activity_skin_names(array $catalog, array $skinIds)
{
    $names = [];
    foreach ($skinIds as $skinId) {
        $resolvedId = trim((string) $skinId);
        if ($resolvedId === '') {
            continue;
        }

        $skinConfig = is_array($catalog[$resolvedId] ?? null) ? $catalog[$resolvedId] : null;
        if ($skinConfig) {
            $names[] = trim((string) ($skinConfig['name'] ?? $resolvedId));
        } else {
            $names[] = $resolvedId;
        }
    }

    $names = array_values(array_unique(array_filter(array_map('strval', $names), static function ($value) {
        return trim((string) $value) !== '';
    })));

    if (count($names) < 1) {
        return '';
    }

    if (count($names) === 1) {
        return $names[0];
    }

    $visibleNames = array_slice($names, 0, 3);
    $suffix = count($names) > count($visibleNames)
        ? ' и еще ' . (count($names) - count($visibleNames))
        : '';

    return implode(', ', $visibleNames) . $suffix;
}

function bober_build_profile_activity_item(array $row, array $catalog)
{
    $actionType = trim((string) ($row['action_type'] ?? ''));
    if ($actionType === '') {
        return null;
    }

    $actionGroup = trim((string) ($row['action_group'] ?? 'general'));
    $storedDescription = trim((string) ($row['description'] ?? ''));
    $meta = bober_decode_json_map((string) ($row['meta_json'] ?? ''), []);
    $title = $storedDescription !== '' ? $storedDescription : 'Событие аккаунта';
    $description = '';
    $icon = '✨';

    switch ($actionType) {
        case 'upgrade_tap_small_purchase':
            $icon = '⚒️';
            $title = 'Куплено улучшение +1 к тапу';
            $description = 'Текущий плюс за клик: +' . bober_format_activity_number($meta['next_plus'] ?? 0) . '.';
            break;
        case 'upgrade_tap_big_purchase':
            $icon = '⚙️';
            $title = 'Куплено улучшение +5 к тапу';
            $description = 'Текущий плюс за клик: +' . bober_format_activity_number($meta['next_plus'] ?? 0) . '.';
            break;
        case 'upgrade_energy_purchase':
            $icon = '🔋';
            $title = 'Увеличен запас энергии';
            $description = 'Новый максимум энергии: ' . bober_format_activity_number($meta['next_energy_max'] ?? 0) . '.';
            break;
        case 'upgrade_tap_huge_purchase':
            $icon = '💥';
            $title = 'Куплено улучшение +100 к тапу';
            $description = 'Текущий плюс за клик: +' . bober_format_activity_number($meta['next_plus'] ?? 0) . '.';
            break;
        case 'upgrade_regen_boost_purchase':
            $icon = '♻️';
            $title = 'Ускорено восстановление энергии';
            $description = 'Скорость регена усилена, бонус за покупку: +' . bober_format_activity_number($meta['regen_bonus_per_second'] ?? 0) . ' в сек.';
            break;
        case 'upgrade_energy_huge_purchase':
            $icon = '🪫';
            $title = 'Куплено большое улучшение энергии';
            $description = 'Новый максимум энергии: ' . bober_format_activity_number($meta['next_energy_max'] ?? 0) . '.';
            break;
        case 'upgrade_click_rate_purchase':
            $icon = '🖱️';
            $title = 'Увеличен лимит засчитываемых кликов';
            $description = 'Новый лимит: ' . bober_format_activity_number($meta['next_click_rate_limit'] ?? 0) . ' CPS.';
            break;
        case 'equip_skin':
            $icon = '🧥';
            $title = 'Сменен активный скин';
            $equippedSkinLabel = bober_describe_activity_skin_names($catalog, [$meta['next_skin_id'] ?? '']);
            $description = $equippedSkinLabel !== ''
                ? 'Теперь используется скин: ' . $equippedSkinLabel . '.'
                : 'Игрок сменил активный скин.';
            break;
        case 'unlock_skin':
            $icon = '🎁';
            $title = 'Получен новый скин';
            $unlockedSkinLabel = bober_describe_activity_skin_names($catalog, (array) ($meta['unlocked_skin_ids'] ?? []));
            $description = $unlockedSkinLabel !== ''
                ? 'Открыто: ' . $unlockedSkinLabel . '.'
                : 'Игрок получил новый скин.';
            break;
        case 'achievement_unlock':
            $icon = '🏅';
            $title = 'Открыто достижение';
            $description = trim((string) ($meta['title'] ?? ''));
            if ($description !== '' && (int) ($meta['reward_coins'] ?? 0) > 0) {
                $description .= ' • Награда: +' . bober_format_activity_number($meta['reward_coins']) . ' коинов.';
            } elseif ((int) ($meta['reward_coins'] ?? 0) > 0) {
                $description = 'Награда: +' . bober_format_activity_number($meta['reward_coins']) . ' коинов.';
            }
            break;
        case 'quest_complete':
            $icon = '📜';
            $title = 'Выполнен квест';
            $description = trim((string) ($meta['title'] ?? ''));
            if ($description !== '' && (int) ($meta['reward_coins'] ?? 0) > 0) {
                $description .= ' • Награда: +' . bober_format_activity_number($meta['reward_coins']) . ' коинов.';
            } elseif ((int) ($meta['reward_coins'] ?? 0) > 0) {
                $description = 'Награда: +' . bober_format_activity_number($meta['reward_coins']) . ' коинов.';
            }
            break;
        case 'fly_run_saved':
            $icon = '🪽';
            $title = 'Сохранен забег fly-beaver';
            $description = 'Счет забега: ' . bober_format_activity_number($meta['score'] ?? 0) . ', уровень: ' . bober_format_activity_number($meta['level'] ?? 0) . '.';
            break;
        case 'fly_reward_claim':
            $icon = '💰';
            $title = 'Выведена награда из fly-beaver';
            $description = 'Переведено в кликер: +' . bober_format_activity_number($meta['awarded_coins'] ?? 0) . ' коинов.';
            break;
    }

    if ($description === '' && $storedDescription !== '' && $storedDescription !== $title) {
        $description = $storedDescription;
    }

    return [
        'actionType' => $actionType,
        'actionGroup' => $actionGroup !== '' ? $actionGroup : 'general',
        'icon' => $icon,
        'title' => $title,
        'description' => $description,
        'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        'scoreDelta' => (int) ($row['score_delta'] ?? 0),
        'coinsDelta' => (int) ($row['coins_delta'] ?? 0),
    ];
}

function bober_fetch_user_activity_feed($conn, $userId, array $options = [])
{
    if (!($conn instanceof mysqli)) {
        return [];
    }

    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    $limit = max(1, min(20, (int) ($options['limit'] ?? 10)));
    $publicOnly = !empty($options['public_only']) || !empty($options['publicOnly']);
    $allowedActionTypes = bober_get_profile_activity_action_types($publicOnly);
    if (count($allowedActionTypes) < 1) {
        return [];
    }

    try {
        bober_ensure_security_schema($conn);
    } catch (Throwable $error) {
        return [];
    }

    $quotedTypes = array_map(static function ($value) use ($conn) {
        return '\'' . $conn->real_escape_string((string) $value) . '\'';
    }, $allowedActionTypes);

    $sql = 'SELECT `id`, `action_group`, `action_type`, `description`, `score_delta`, `coins_delta`, `meta_json`, `created_at`
        FROM `user_activity_log`
        WHERE `user_id` = ?
            AND `action_type` IN (' . implode(', ', $quotedTypes) . ')
        ORDER BY `created_at` DESC, `id` DESC
        LIMIT ' . $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        return [];
    }

    $result = $stmt->get_result();
    $catalog = bober_skin_catalog();
    $items = [];

    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $item = bober_build_profile_activity_item((array) $row, $catalog);
            if ($item !== null) {
                $items[] = $item;
            }
        }
        $result->free();
    }

    $stmt->close();
    return $items;
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
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('security')) {
        $schemaEnsured = true;
        return;
    }

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

    bober_schema_guard_touch('security');
    $schemaEnsured = true;
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

function bober_default_user_settings()
{
    return [
        'audio' => [
            'musicEnabled' => true,
            'effectsEnabled' => true,
            'flyVolume' => 100,
        ],
        'vibration' => [
            'enabled' => true,
            'intensity' => 'medium',
        ],
        'animations' => [
            'mode' => 'full',
        ],
        'notifications' => [
            'enabled' => true,
            'browserEnabled' => false,
        ],
        'effects' => [
            'quality' => 'high',
        ],
        'privacy' => [
            'publicProfile' => true,
        ],
        'performance' => [
            'autoOptimize' => true,
            'autoProfile' => '',
        ],
    ];
}

function bober_normalize_user_settings($settings)
{
    $defaults = bober_default_user_settings();
    $raw = is_array($settings) ? $settings : [];

    $musicEnabled = !array_key_exists('musicEnabled', (array) ($raw['audio'] ?? []))
        ? $defaults['audio']['musicEnabled']
        : !empty($raw['audio']['musicEnabled']);
    $effectsEnabled = !array_key_exists('effectsEnabled', (array) ($raw['audio'] ?? []))
        ? $defaults['audio']['effectsEnabled']
        : !empty($raw['audio']['effectsEnabled']);
    $flyVolume = max(0, min(100, (int) (($raw['audio']['flyVolume'] ?? $defaults['audio']['flyVolume']))));

    $vibrationEnabled = !array_key_exists('enabled', (array) ($raw['vibration'] ?? []))
        ? $defaults['vibration']['enabled']
        : !empty($raw['vibration']['enabled']);
    $vibrationIntensity = trim((string) ($raw['vibration']['intensity'] ?? $defaults['vibration']['intensity']));
    if (!in_array($vibrationIntensity, ['low', 'medium', 'high'], true)) {
        $vibrationIntensity = $defaults['vibration']['intensity'];
    }

    $animationsMode = trim((string) ($raw['animations']['mode'] ?? $defaults['animations']['mode']));
    if (!in_array($animationsMode, ['full', 'reduced', 'off'], true)) {
        $animationsMode = $defaults['animations']['mode'];
    }

    $notificationsEnabled = !array_key_exists('enabled', (array) ($raw['notifications'] ?? []))
        ? $defaults['notifications']['enabled']
        : !empty($raw['notifications']['enabled']);
    $browserNotificationsEnabled = !array_key_exists('browserEnabled', (array) ($raw['notifications'] ?? []))
        ? $defaults['notifications']['browserEnabled']
        : !empty($raw['notifications']['browserEnabled']);

    $effectsQuality = trim((string) ($raw['effects']['quality'] ?? $defaults['effects']['quality']));
    if (!in_array($effectsQuality, ['low', 'medium', 'high'], true)) {
        $effectsQuality = $defaults['effects']['quality'];
    }

    $publicProfile = !array_key_exists('publicProfile', (array) ($raw['privacy'] ?? []))
        ? $defaults['privacy']['publicProfile']
        : !empty($raw['privacy']['publicProfile']);

    $autoOptimize = !array_key_exists('autoOptimize', (array) ($raw['performance'] ?? []))
        ? $defaults['performance']['autoOptimize']
        : !empty($raw['performance']['autoOptimize']);
    $autoProfile = trim((string) ($raw['performance']['autoProfile'] ?? $defaults['performance']['autoProfile']));

    return [
        'audio' => [
            'musicEnabled' => $musicEnabled,
            'effectsEnabled' => $effectsEnabled,
            'flyVolume' => $flyVolume,
        ],
        'vibration' => [
            'enabled' => $vibrationEnabled,
            'intensity' => $vibrationIntensity,
        ],
        'animations' => [
            'mode' => $animationsMode,
        ],
        'notifications' => [
            'enabled' => $notificationsEnabled,
            'browserEnabled' => $browserNotificationsEnabled,
        ],
        'effects' => [
            'quality' => $effectsQuality,
        ],
        'privacy' => [
            'publicProfile' => $publicProfile,
        ],
        'performance' => [
            'autoOptimize' => $autoOptimize,
            'autoProfile' => $autoProfile,
        ],
    ];
}

function bober_is_user_profile_public($conn, $userId)
{
    $settings = bober_fetch_user_settings($conn, $userId);
    return !empty($settings['privacy']['publicProfile']);
}

function bober_ensure_user_settings_schema($conn)
{
    $createSettingsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_settings` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `settings_json` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createSettingsSql)) {
        throw new RuntimeException('Не удалось создать таблицу пользовательских настроек.');
    }
}

function bober_ensure_user_achievements_schema($conn)
{
    $createAchievementsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_achievements` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `achievement_key` VARCHAR(120) NOT NULL,
    `meta_json` LONGTEXT NULL,
    `unlocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_user_achievement` (`user_id`, `achievement_key`),
    KEY `idx_user_achievements_user` (`user_id`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createAchievementsSql)) {
        throw new RuntimeException('Не удалось создать таблицу достижений.');
    }

    $achievementColumnAlterMap = [
        'revoked_at' => "ALTER TABLE `user_achievements` ADD COLUMN `revoked_at` TIMESTAMP NULL DEFAULT NULL AFTER `unlocked_at`",
    ];

    foreach ($achievementColumnAlterMap as $columnName => $alterSql) {
        if (!bober_column_exists($conn, 'user_achievements', $columnName)) {
            if (!$conn->query($alterSql)) {
                throw new RuntimeException('Не удалось обновить таблицу достижений.');
            }
        }
    }
}

function bober_ensure_achievement_catalog_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('achievement_catalog')) {
        $schemaEnsured = true;
        return;
    }

    $createCatalogSql = <<<SQL
CREATE TABLE IF NOT EXISTS `achievement_catalog` (
    `achievement_key` VARCHAR(120) NOT NULL PRIMARY KEY,
    `title` VARCHAR(160) NULL,
    `description` VARCHAR(255) NULL,
    `icon` VARCHAR(32) NULL,
    `locked_title` VARCHAR(160) NULL,
    `locked_description` VARCHAR(255) NULL,
    `locked_icon` VARCHAR(32) NULL,
    `is_secret` TINYINT(1) NULL DEFAULT NULL,
    `reward_coins` INT NULL DEFAULT NULL,
    `is_active` TINYINT(1) NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createCatalogSql)) {
        throw new RuntimeException('Не удалось создать таблицу каталога достижений.');
    }

    bober_schema_guard_touch('achievement_catalog');
    $schemaEnsured = true;
}

function bober_ensure_user_achievement_overrides_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('user_achievement_overrides')) {
        $schemaEnsured = true;
        return;
    }

    $createOverridesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_achievement_overrides` (
    `user_id` INT NOT NULL,
    `achievement_key` VARCHAR(120) NOT NULL,
    `override_mode` VARCHAR(16) NOT NULL DEFAULT 'grant',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `achievement_key`),
    KEY `idx_user_achievement_overrides_user` (`user_id`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createOverridesSql)) {
        throw new RuntimeException('Не удалось создать таблицу ручных override достижений.');
    }

    bober_schema_guard_touch('user_achievement_overrides');
    $schemaEnsured = true;
}

function bober_ensure_user_quests_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('user_quests')) {
        $schemaEnsured = true;
        return;
    }

    $createQuestsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `user_quest_progress` (
    `user_id` INT NOT NULL PRIMARY KEY,
    `daily_period_key` VARCHAR(32) NOT NULL,
    `daily_roll_token` INT NOT NULL DEFAULT 0,
    `daily_counters_json` LONGTEXT NULL,
    `daily_claimed_json` LONGTEXT NULL,
    `daily_manual_keys_json` LONGTEXT NULL,
    `weekly_period_key` VARCHAR(32) NOT NULL,
    `weekly_roll_token` INT NOT NULL DEFAULT 0,
    `weekly_counters_json` LONGTEXT NULL,
    `weekly_claimed_json` LONGTEXT NULL,
    `weekly_manual_keys_json` LONGTEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createQuestsSql)) {
        throw new RuntimeException('Не удалось создать таблицу прогресса квестов.');
    }

    $questAlterStatements = [
        'daily_period_key' => "ALTER TABLE `user_quest_progress` ADD COLUMN `daily_period_key` VARCHAR(32) NOT NULL DEFAULT '' AFTER `user_id`",
        'daily_roll_token' => "ALTER TABLE `user_quest_progress` ADD COLUMN `daily_roll_token` INT NOT NULL DEFAULT 0 AFTER `daily_period_key`",
        'daily_counters_json' => "ALTER TABLE `user_quest_progress` ADD COLUMN `daily_counters_json` LONGTEXT NULL AFTER `daily_roll_token`",
        'daily_claimed_json' => "ALTER TABLE `user_quest_progress` ADD COLUMN `daily_claimed_json` LONGTEXT NULL AFTER `daily_counters_json`",
        'daily_manual_keys_json' => "ALTER TABLE `user_quest_progress` ADD COLUMN `daily_manual_keys_json` LONGTEXT NULL AFTER `daily_claimed_json`",
        'weekly_period_key' => "ALTER TABLE `user_quest_progress` ADD COLUMN `weekly_period_key` VARCHAR(32) NOT NULL DEFAULT '' AFTER `daily_manual_keys_json`",
        'weekly_roll_token' => "ALTER TABLE `user_quest_progress` ADD COLUMN `weekly_roll_token` INT NOT NULL DEFAULT 0 AFTER `weekly_period_key`",
        'weekly_counters_json' => "ALTER TABLE `user_quest_progress` ADD COLUMN `weekly_counters_json` LONGTEXT NULL AFTER `weekly_roll_token`",
        'weekly_claimed_json' => "ALTER TABLE `user_quest_progress` ADD COLUMN `weekly_claimed_json` LONGTEXT NULL AFTER `weekly_counters_json`",
        'weekly_manual_keys_json' => "ALTER TABLE `user_quest_progress` ADD COLUMN `weekly_manual_keys_json` LONGTEXT NULL AFTER `weekly_claimed_json`",
        'created_at' => "ALTER TABLE `user_quest_progress` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `weekly_manual_keys_json`",
        'updated_at' => "ALTER TABLE `user_quest_progress` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($questAlterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'user_quest_progress', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру прогресса квестов.');
        }
    }

    $questTemplatesTableExists = bober_table_exists($conn, 'quest_templates');
    $createQuestTemplatesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `quest_templates` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `quest_key` VARCHAR(120) NOT NULL,
    `scope` VARCHAR(16) NOT NULL,
    `title` VARCHAR(160) NOT NULL,
    `description` VARCHAR(255) NOT NULL DEFAULT '',
    `metric` VARCHAR(32) NOT NULL,
    `goal` INT NOT NULL DEFAULT 1,
    `reward_coins` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_quest_templates_key` (`quest_key`),
    KEY `idx_quest_templates_scope` (`scope`, `is_active`, `id`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createQuestTemplatesSql)) {
        throw new RuntimeException('Не удалось создать таблицу шаблонов квестов.');
    }

    $questTemplateAlterStatements = [
        'quest_key' => "ALTER TABLE `quest_templates` ADD COLUMN `quest_key` VARCHAR(120) NOT NULL DEFAULT '' AFTER `id`",
        'scope' => "ALTER TABLE `quest_templates` ADD COLUMN `scope` VARCHAR(16) NOT NULL DEFAULT 'daily' AFTER `quest_key`",
        'title' => "ALTER TABLE `quest_templates` ADD COLUMN `title` VARCHAR(160) NOT NULL DEFAULT '' AFTER `scope`",
        'description' => "ALTER TABLE `quest_templates` ADD COLUMN `description` VARCHAR(255) NOT NULL DEFAULT '' AFTER `title`",
        'metric' => "ALTER TABLE `quest_templates` ADD COLUMN `metric` VARCHAR(32) NOT NULL DEFAULT 'scoreGain' AFTER `description`",
        'goal' => "ALTER TABLE `quest_templates` ADD COLUMN `goal` INT NOT NULL DEFAULT 1 AFTER `metric`",
        'reward_coins' => "ALTER TABLE `quest_templates` ADD COLUMN `reward_coins` INT NOT NULL DEFAULT 0 AFTER `goal`",
        'is_active' => "ALTER TABLE `quest_templates` ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1 AFTER `reward_coins`",
        'created_at' => "ALTER TABLE `quest_templates` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `is_active`",
        'updated_at' => "ALTER TABLE `quest_templates` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
    ];

    foreach ($questTemplateAlterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'quest_templates', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру шаблонов квестов.');
        }
    }

    if (!bober_index_exists($conn, 'quest_templates', 'uniq_quest_templates_key') && !$conn->query("ALTER TABLE `quest_templates` ADD UNIQUE KEY `uniq_quest_templates_key` (`quest_key`)")) {
        throw new RuntimeException('Не удалось создать уникальный индекс шаблонов квестов.');
    }

    if (!bober_index_exists($conn, 'quest_templates', 'idx_quest_templates_scope') && !$conn->query("CREATE INDEX `idx_quest_templates_scope` ON `quest_templates` (`scope`, `is_active`, `id`)")) {
        throw new RuntimeException('Не удалось создать индекс шаблонов квестов по области.');
    }

    if (!$questTemplatesTableExists) {
        bober_seed_default_quest_templates($conn);
    }
    bober_sync_default_quest_templates($conn);

    bober_schema_guard_touch('user_quests');
    $schemaEnsured = true;
}

function bober_ensure_support_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    $createTicketsSql = <<<SQL
CREATE TABLE IF NOT EXISTS `support_tickets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `category` VARCHAR(64) NOT NULL,
    `subject` VARCHAR(180) NOT NULL,
    `status` VARCHAR(32) NOT NULL DEFAULT 'waiting_support',
    `unread_by_user` INT NOT NULL DEFAULT 0,
    `unread_by_admin` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `last_user_message_at` TIMESTAMP NULL DEFAULT NULL,
    `last_admin_message_at` TIMESTAMP NULL DEFAULT NULL,
    `archived_at` TIMESTAMP NULL DEFAULT NULL,
    `archive_reason` VARCHAR(64) NOT NULL DEFAULT '',
    KEY `idx_support_tickets_user` (`user_id`, `updated_at`),
    KEY `idx_support_tickets_status` (`status`, `updated_at`),
    KEY `idx_support_tickets_admin_unread` (`unread_by_admin`, `updated_at`),
    KEY `idx_support_tickets_user_unread` (`unread_by_user`, `updated_at`),
    KEY `idx_support_tickets_archived` (`archived_at`, `updated_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createTicketsSql)) {
        throw new RuntimeException('Не удалось создать таблицу тикетов поддержки.');
    }

    $supportTicketAlterStatements = [
        'user_id' => "ALTER TABLE `support_tickets` ADD COLUMN `user_id` INT NOT NULL DEFAULT 0 AFTER `id`",
        'category' => "ALTER TABLE `support_tickets` ADD COLUMN `category` VARCHAR(64) NOT NULL DEFAULT 'other' AFTER `user_id`",
        'subject' => "ALTER TABLE `support_tickets` ADD COLUMN `subject` VARCHAR(180) NOT NULL DEFAULT '' AFTER `category`",
        'status' => "ALTER TABLE `support_tickets` ADD COLUMN `status` VARCHAR(32) NOT NULL DEFAULT 'waiting_support' AFTER `subject`",
        'unread_by_user' => "ALTER TABLE `support_tickets` ADD COLUMN `unread_by_user` INT NOT NULL DEFAULT 0 AFTER `status`",
        'unread_by_admin' => "ALTER TABLE `support_tickets` ADD COLUMN `unread_by_admin` INT NOT NULL DEFAULT 0 AFTER `unread_by_user`",
        'created_at' => "ALTER TABLE `support_tickets` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `unread_by_admin`",
        'updated_at' => "ALTER TABLE `support_tickets` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`",
        'last_user_message_at' => "ALTER TABLE `support_tickets` ADD COLUMN `last_user_message_at` TIMESTAMP NULL DEFAULT NULL AFTER `updated_at`",
        'last_admin_message_at' => "ALTER TABLE `support_tickets` ADD COLUMN `last_admin_message_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_user_message_at`",
        'archived_at' => "ALTER TABLE `support_tickets` ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL AFTER `last_admin_message_at`",
        'archive_reason' => "ALTER TABLE `support_tickets` ADD COLUMN `archive_reason` VARCHAR(64) NOT NULL DEFAULT '' AFTER `archived_at`",
    ];

    foreach ($supportTicketAlterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'support_tickets', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру тикетов поддержки.');
        }
    }

    if (!bober_index_exists($conn, 'support_tickets', 'idx_support_tickets_user') && !$conn->query("CREATE INDEX `idx_support_tickets_user` ON `support_tickets` (`user_id`, `updated_at`)")) {
        throw new RuntimeException('Не удалось создать индекс тикетов по пользователю.');
    }

    if (!bober_index_exists($conn, 'support_tickets', 'idx_support_tickets_status') && !$conn->query("CREATE INDEX `idx_support_tickets_status` ON `support_tickets` (`status`, `updated_at`)")) {
        throw new RuntimeException('Не удалось создать индекс тикетов по статусу.');
    }

    if (!bober_index_exists($conn, 'support_tickets', 'idx_support_tickets_admin_unread') && !$conn->query("CREATE INDEX `idx_support_tickets_admin_unread` ON `support_tickets` (`unread_by_admin`, `updated_at`)")) {
        throw new RuntimeException('Не удалось создать индекс непрочитанных тикетов для админа.');
    }

    if (!bober_index_exists($conn, 'support_tickets', 'idx_support_tickets_user_unread') && !$conn->query("CREATE INDEX `idx_support_tickets_user_unread` ON `support_tickets` (`unread_by_user`, `updated_at`)")) {
        throw new RuntimeException('Не удалось создать индекс непрочитанных тикетов для игрока.');
    }

    if (!bober_index_exists($conn, 'support_tickets', 'idx_support_tickets_archived') && !$conn->query("CREATE INDEX `idx_support_tickets_archived` ON `support_tickets` (`archived_at`, `updated_at`)")) {
        throw new RuntimeException('Не удалось создать индекс архива тикетов поддержки.');
    }

    $createMessagesSql = <<<SQL
CREATE TABLE IF NOT EXISTS `support_ticket_messages` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `ticket_id` BIGINT UNSIGNED NOT NULL,
    `author_type` VARCHAR(16) NOT NULL,
    `author_user_id` INT NULL,
    `message_text` LONGTEXT NOT NULL,
    `attachments_json` LONGTEXT NULL,
    `attachments_count` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_support_ticket_messages_ticket` (`ticket_id`, `created_at`)
) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
SQL;

    if (!$conn->query($createMessagesSql)) {
        throw new RuntimeException('Не удалось создать таблицу сообщений тикетов.');
    }

    $supportMessageAlterStatements = [
        'ticket_id' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `ticket_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `id`",
        'author_type' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `author_type` VARCHAR(16) NOT NULL DEFAULT 'user' AFTER `ticket_id`",
        'author_user_id' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `author_user_id` INT NULL DEFAULT NULL AFTER `author_type`",
        'message_text' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `message_text` LONGTEXT NOT NULL AFTER `author_user_id`",
        'attachments_json' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `attachments_json` LONGTEXT NULL AFTER `message_text`",
        'attachments_count' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `attachments_count` INT NOT NULL DEFAULT 0 AFTER `attachments_json`",
        'created_at' => "ALTER TABLE `support_ticket_messages` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `message_text`",
    ];

    foreach ($supportMessageAlterStatements as $column => $sql) {
        if (!bober_column_exists($conn, 'support_ticket_messages', $column) && !$conn->query($sql)) {
            throw new RuntimeException('Не удалось обновить структуру сообщений тикетов.');
        }
    }

    if (!bober_index_exists($conn, 'support_ticket_messages', 'idx_support_ticket_messages_ticket') && !$conn->query("CREATE INDEX `idx_support_ticket_messages_ticket` ON `support_ticket_messages` (`ticket_id`, `created_at`)")) {
        throw new RuntimeException('Не удалось создать индекс сообщений тикетов.');
    }

    $schemaEnsured = true;
}

function bober_ensure_gameplay_schema($conn)
{
    static $schemaEnsured = false;

    if ($schemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('gameplay')) {
        $schemaEnsured = true;
        return;
    }

    bober_ensure_game_schema($conn);
    bober_ensure_security_schema($conn);
    bober_ensure_fly_beaver_schema($conn);
    bober_ensure_user_settings_schema($conn);
    bober_ensure_user_achievements_schema($conn);
    bober_ensure_achievement_catalog_schema($conn);
    bober_ensure_user_achievement_overrides_schema($conn);
    bober_ensure_user_quests_schema($conn);
    bober_ensure_support_schema($conn);

    bober_schema_guard_touch('gameplay');
    $schemaEnsured = true;
}

function bober_ensure_project_schema($conn)
{
    static $projectSchemaEnsured = false;

    if ($projectSchemaEnsured) {
        return;
    }

    if (bober_schema_guard_is_fresh('project')) {
        $projectSchemaEnsured = true;
        return;
    }

    bober_ensure_gameplay_schema($conn);
    bober_ensure_admin_schema($conn);

    bober_schema_guard_touch('project');
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

function bober_fetch_user_settings_record($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'settings' => bober_default_user_settings(),
            'updatedAt' => '',
        ];
    }

    bober_ensure_user_settings_schema($conn);

    $stmt = $conn->prepare('SELECT settings_json, updated_at FROM user_settings WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение пользовательских настроек.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить пользовательские настройки.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!$row || !is_string($row['settings_json'] ?? null) || trim((string) $row['settings_json']) === '') {
        return [
            'settings' => bober_default_user_settings(),
            'updatedAt' => '',
        ];
    }

    $decoded = json_decode((string) $row['settings_json'], true);
    return [
        'settings' => bober_normalize_user_settings(is_array($decoded) ? $decoded : null),
        'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
    ];
}

function bober_fetch_user_settings($conn, $userId)
{
    $record = bober_fetch_user_settings_record($conn, $userId);
    return is_array($record['settings'] ?? null) ? $record['settings'] : bober_default_user_settings();
}

function bober_store_user_settings($conn, $userId, $settings)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    bober_ensure_user_settings_schema($conn);

    $normalized = bober_normalize_user_settings($settings);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Не удалось сериализовать пользовательские настройки.');
    }

    $stmt = $conn->prepare('INSERT INTO user_settings (user_id, settings_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_at = CURRENT_TIMESTAMP');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сохранение пользовательских настроек.');
    }

    $stmt->bind_param('is', $userId, $json);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сохранить пользовательские настройки.');
    }
    $stmt->close();

    return bober_fetch_user_settings_record($conn, $userId);
}

function bober_support_attachment_max_count()
{
    return 3;
}

function bober_support_attachment_max_bytes()
{
    return 3 * 1024 * 1024;
}

function bober_support_attachment_allowed_mime_map()
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
}

function bober_support_attachment_storage_directory()
{
    return dirname(__DIR__, 2) . '/assets/support-uploads';
}

function bober_support_attachment_public_prefix()
{
    return '/assets/support-uploads';
}

function bober_support_attachment_safe_name($value, $fallback = 'photo')
{
    $name = trim((string) $value);
    if ($name === '') {
        $name = $fallback;
    }

    $name = preg_replace('/[^\p{L}\p{N}\._-]+/u', '-', $name);
    $name = trim((string) $name, '-._');
    if ($name === '') {
        $name = $fallback;
    }

    if (function_exists('mb_substr')) {
        $name = mb_substr($name, 0, 120, 'UTF-8');
    } else {
        $name = substr($name, 0, 120);
    }

    return $name;
}

function bober_normalize_support_ticket_message_optional($value)
{
    $message = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    $length = bober_support_text_length($message);
    if ($length > 6000) {
        throw new InvalidArgumentException('Сообщение тикета слишком длинное.');
    }

    return $message;
}

function bober_normalize_support_ticket_input_attachments($value)
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }

    $items = $value;
    if (is_string($items)) {
        $decoded = json_decode($items, true);
        $items = is_array($decoded) ? $decoded : null;
    }

    if (!is_array($items)) {
        throw new InvalidArgumentException('Некорректный список вложений для тикета.');
    }

    $items = array_values($items);
    if (count($items) > bober_support_attachment_max_count()) {
        throw new InvalidArgumentException('Слишком много изображений в одном сообщении.');
    }

    $normalized = [];
    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('Некорректный формат вложения в тикете.');
        }

        $dataUrl = trim((string) ($item['dataUrl'] ?? $item['data_url'] ?? $item['data'] ?? ''));
        if ($dataUrl === '') {
            throw new InvalidArgumentException('Пустое изображение в тикете.');
        }

        $normalized[] = [
            'name' => bober_support_attachment_safe_name($item['name'] ?? ('photo-' . ($index + 1)), 'photo-' . ($index + 1)),
            'dataUrl' => $dataUrl,
        ];
    }

    return $normalized;
}

function bober_prepare_support_ticket_message_payload($message, $attachments = [])
{
    $normalizedMessage = bober_normalize_support_ticket_message_optional($message);
    $normalizedAttachments = bober_normalize_support_ticket_input_attachments($attachments);

    if ($normalizedMessage === '' && count($normalizedAttachments) < 1) {
        throw new InvalidArgumentException('Сообщение тикета не должно быть пустым.');
    }

    return [
        'message' => $normalizedMessage,
        'attachments' => $normalizedAttachments,
    ];
}

function bober_cleanup_support_attachment_paths(array $paths)
{
    foreach ($paths as $path) {
        $normalizedPath = trim((string) $path);
        if ($normalizedPath === '' || !is_file($normalizedPath)) {
            continue;
        }

        @unlink($normalizedPath);
    }
}

function bober_store_support_ticket_attachments($ticketId, $authorType, array $attachments)
{
    $ticketId = max(0, (int) $ticketId);
    $authorType = trim((string) $authorType);
    if ($ticketId < 1 || $authorType === '' || count($attachments) < 1) {
        return [
            'attachments' => [],
            'paths' => [],
        ];
    }

    $mimeMap = bober_support_attachment_allowed_mime_map();
    $maxBytes = bober_support_attachment_max_bytes();
    $baseDirectory = bober_support_attachment_storage_directory();
    $relativeDirectory = date('Y/m');
    $targetDirectory = $baseDirectory . '/' . $relativeDirectory;

    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('Не удалось подготовить каталог для вложений тикетов.');
    }

    $storedAttachments = [];
    $storedPaths = [];

    foreach ($attachments as $index => $attachment) {
        $dataUrl = trim((string) ($attachment['dataUrl'] ?? ''));
        if (!preg_match('#^data:(image/(?:jpeg|png|webp|gif));base64,([A-Za-z0-9+/=\r\n]+)$#', $dataUrl, $matches)) {
            bober_cleanup_support_attachment_paths($storedPaths);
            throw new InvalidArgumentException('Поддерживаются только изображения JPEG, PNG, WEBP или GIF.');
        }

        $mimeType = strtolower(trim((string) ($matches[1] ?? '')));
        if (!isset($mimeMap[$mimeType])) {
            bober_cleanup_support_attachment_paths($storedPaths);
            throw new InvalidArgumentException('Недопустимый тип изображения в тикете.');
        }

        $binary = base64_decode((string) ($matches[2] ?? ''), true);
        if ($binary === false || $binary === '') {
            bober_cleanup_support_attachment_paths($storedPaths);
            throw new InvalidArgumentException('Не удалось прочитать изображение в тикете.');
        }

        $size = strlen($binary);
        if ($size > $maxBytes) {
            bober_cleanup_support_attachment_paths($storedPaths);
            throw new InvalidArgumentException('Одно изображение в тикете слишком большое.');
        }

        $imageInfo = @getimagesizefromstring($binary);
        if (!is_array($imageInfo) || max(0, (int) ($imageInfo[0] ?? 0)) < 1 || max(0, (int) ($imageInfo[1] ?? 0)) < 1) {
            bober_cleanup_support_attachment_paths($storedPaths);
            throw new InvalidArgumentException('Вложение не похоже на корректное изображение.');
        }

        $extension = $mimeMap[$mimeType];
        $safeBaseName = bober_support_attachment_safe_name($attachment['name'] ?? ('photo-' . ($index + 1)), 'photo-' . ($index + 1));
        $safeBaseName = preg_replace('/\.[A-Za-z0-9]+$/', '', $safeBaseName);
        $fileName = sprintf(
            'ticket-%d-%s-%s-%02d-%s.%s',
            $ticketId,
            $authorType,
            date('YmdHis'),
            $index + 1,
            bin2hex(random_bytes(4)),
            $extension
        );
        $targetPath = $targetDirectory . '/' . $fileName;

        if (file_put_contents($targetPath, $binary) === false) {
            bober_cleanup_support_attachment_paths($storedPaths);
            throw new RuntimeException('Не удалось сохранить изображение тикета.');
        }

        $storedPaths[] = $targetPath;
        $storedAttachments[] = [
            'name' => $safeBaseName . '.' . $extension,
            'url' => bober_support_attachment_public_prefix() . '/' . $relativeDirectory . '/' . $fileName,
            'mimeType' => $mimeType,
            'size' => $size,
        ];
    }

    return [
        'attachments' => $storedAttachments,
        'paths' => $storedPaths,
    ];
}

function bober_normalize_support_ticket_stored_attachments($value)
{
    if ($value === null || $value === '' || $value === []) {
        return [];
    }

    $items = $value;
    if (is_string($items)) {
        $decoded = json_decode($items, true);
        $items = is_array($decoded) ? $decoded : [];
    }

    if (!is_array($items)) {
        return [];
    }

    $publicPrefix = bober_support_attachment_public_prefix() . '/';
    $normalized = [];
    foreach ($items as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $url = trim((string) ($attachment['url'] ?? ''));
        if ($url === '' || strpos($url, $publicPrefix) !== 0) {
            continue;
        }

        $normalized[] = [
            'name' => bober_support_attachment_safe_name($attachment['name'] ?? 'photo', 'photo'),
            'url' => $url,
            'mimeType' => trim((string) ($attachment['mimeType'] ?? 'image/jpeg')),
            'size' => max(0, (int) ($attachment['size'] ?? 0)),
        ];
    }

    return $normalized;
}

function bober_support_ticket_categories()
{
    return [
        'account',
        'ban_appeal',
        'bugs',
        'skins',
        'skins_shop',
        'fly_beaver',
        'other',
    ];
}

function bober_support_ticket_statuses()
{
    return [
        'waiting_support',
        'waiting_user',
        'closed',
    ];
}

function bober_normalize_support_ticket_category($value)
{
    $normalized = strtolower(trim((string) $value));
    if ($normalized === 'skins_shop') {
        return 'skins';
    }
    return in_array($normalized, bober_support_ticket_categories(), true) ? $normalized : 'other';
}

function bober_normalize_support_ticket_status($value)
{
    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, bober_support_ticket_statuses(), true) ? $normalized : 'waiting_support';
}

function bober_support_ticket_status_label($status)
{
    $normalized = bober_normalize_support_ticket_status($status);
    $labels = [
        'waiting_support' => 'Ждет поддержку',
        'waiting_user' => 'Ждет игрока',
        'closed' => 'Закрыт',
    ];

    return $labels[$normalized] ?? $labels['waiting_support'];
}

function bober_insert_support_ticket_system_message($conn, $ticketId, $message)
{
    $ticketId = max(0, (int) $ticketId);
    $message = trim((string) $message);
    if ($ticketId < 1 || $message === '') {
        return false;
    }

    $stmt = $conn->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_text, attachments_json, attachments_count) VALUES (?, ?, NULL, ?, NULL, 0)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить системное сообщение тикета.');
    }

    $authorType = 'system';
    $stmt->bind_param('iss', $ticketId, $authorType, $message);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сохранить системное сообщение тикета.');
    }

    $stmt->close();
    return true;
}

function bober_log_support_ticket_status_change($conn, $ticketId, $fromStatus, $toStatus, $options = [])
{
    $ticketId = max(0, (int) $ticketId);
    if ($ticketId < 1) {
        return false;
    }

    $normalizedFrom = bober_normalize_support_ticket_status($fromStatus);
    $normalizedTo = bober_normalize_support_ticket_status($toStatus);
    if ($normalizedFrom === $normalizedTo) {
        return false;
    }

    $mode = trim((string) ($options['mode'] ?? 'manual'));
    if ($mode === 'create_admin') {
        $message = 'Системное сообщение: поддержка открыла тикет. Текущий статус: ' . bober_support_ticket_status_label($normalizedTo) . '.';
    } elseif ($mode === 'create_user') {
        $message = 'Системное сообщение: тикет создан. Текущий статус: ' . bober_support_ticket_status_label($normalizedTo) . '.';
    } elseif ($mode === 'auto_user_reply') {
        $message = 'Системное сообщение: после ответа игрока статус изменен: ' . bober_support_ticket_status_label($normalizedFrom) . ' → ' . bober_support_ticket_status_label($normalizedTo) . '.';
    } elseif ($mode === 'auto_admin_reply') {
        $message = 'Системное сообщение: после ответа поддержки статус изменен: ' . bober_support_ticket_status_label($normalizedFrom) . ' → ' . bober_support_ticket_status_label($normalizedTo) . '.';
    } else {
        $message = 'Системное сообщение: статус тикета изменен: ' . bober_support_ticket_status_label($normalizedFrom) . ' → ' . bober_support_ticket_status_label($normalizedTo) . '.';
    }

    return bober_insert_support_ticket_system_message($conn, $ticketId, $message);
}

function bober_support_text_length($value)
{
    $text = (string) $value;
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($text, 'UTF-8');
    }

    return strlen($text);
}

function bober_normalize_support_ticket_subject($value)
{
    $subject = trim(preg_replace('/\s+/u', ' ', (string) $value));
    $length = bober_support_text_length($subject);
    if ($length < 2) {
        throw new InvalidArgumentException('Тема тикета должна быть не короче 2 символов.');
    }
    if ($length > 180) {
        throw new InvalidArgumentException('Тема тикета слишком длинная.');
    }

    return $subject;
}

function bober_normalize_support_ticket_message($value)
{
    $message = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
    $length = bober_support_text_length($message);
    if ($length < 1) {
        throw new InvalidArgumentException('Сообщение тикета не должно быть пустым.');
    }
    if ($length > 6000) {
        throw new InvalidArgumentException('Сообщение тикета слишком длинное.');
    }

    return $message;
}

function bober_fetch_user_support_summary($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'unreadReplies' => 0,
            'openTickets' => 0,
        ];
    }

    bober_ensure_support_schema($conn);
    $stmt = $conn->prepare('SELECT COALESCE(SUM(CASE WHEN status <> ? THEN 1 ELSE 0 END), 0) AS open_tickets, COALESCE(SUM(unread_by_user), 0) AS unread_replies FROM support_tickets WHERE user_id = ? AND archived_at IS NULL');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сводку тикетов поддержки.');
    }

    $closedStatus = 'closed';
    $stmt->bind_param('si', $closedStatus, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить сводку тикетов поддержки.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return [
        'unreadReplies' => max(0, (int) ($row['unread_replies'] ?? 0)),
        'openTickets' => max(0, (int) ($row['open_tickets'] ?? 0)),
    ];
}

function bober_normalize_support_ticket_row(array $row)
{
    return [
        'id' => max(0, (int) ($row['id'] ?? 0)),
        'userId' => max(0, (int) ($row['user_id'] ?? $row['userId'] ?? 0)),
        'login' => isset($row['login']) ? (string) $row['login'] : '',
        'category' => bober_normalize_support_ticket_category($row['category'] ?? ''),
        'subject' => (string) ($row['subject'] ?? ''),
        'status' => bober_normalize_support_ticket_status($row['status'] ?? ''),
        'unreadByUser' => max(0, (int) ($row['unread_by_user'] ?? $row['unreadByUser'] ?? 0)),
        'unreadByAdmin' => max(0, (int) ($row['unread_by_admin'] ?? $row['unreadByAdmin'] ?? 0)),
        'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        'lastUserMessageAt' => isset($row['last_user_message_at']) ? (string) $row['last_user_message_at'] : null,
        'lastAdminMessageAt' => isset($row['last_admin_message_at']) ? (string) $row['last_admin_message_at'] : null,
        'archivedAt' => isset($row['archived_at']) && $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
        'lastMessagePreview' => isset($row['last_message_preview']) ? (string) $row['last_message_preview'] : '',
    ];
}

function bober_auto_archive_support_tickets($conn, array $options = [])
{
    bober_ensure_support_schema($conn);

    $archiveDays = max(1, min(365, (int) ($options['days'] ?? 14)));
    $archiveReason = trim((string) ($options['reason'] ?? 'closed_inactive'));
    if ($archiveReason === '') {
        $archiveReason = 'closed_inactive';
    }

    $sql = 'UPDATE support_tickets
        SET archived_at = CURRENT_TIMESTAMP,
            archive_reason = ?,
            unread_by_user = 0,
            unread_by_admin = 0
        WHERE archived_at IS NULL
            AND status = ?
            AND updated_at <= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить автоархивацию тикетов.');
    }

    $closedStatus = 'closed';
    $stmt->bind_param('ssi', $archiveReason, $closedStatus, $archiveDays);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось выполнить автоархивацию тикетов.');
    }

    $archivedCount = max(0, (int) $stmt->affected_rows);
    $stmt->close();

    return $archivedCount;
}

function bober_fetch_support_ticket_messages($conn, $ticketId)
{
    $ticketId = max(0, (int) $ticketId);
    if ($ticketId < 1) {
        return [];
    }

    $stmt = $conn->prepare('SELECT id, author_type, author_user_id, message_text, attachments_json, attachments_count, created_at FROM support_ticket_messages WHERE ticket_id = ? ORDER BY id ASC');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение сообщений тикета.');
    }

    $stmt->bind_param('i', $ticketId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить сообщения тикета.');
    }

    $result = $stmt->get_result();
    $items = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $items[] = [
            'id' => max(0, (int) ($row['id'] ?? 0)),
            'authorType' => (string) ($row['author_type'] ?? 'user'),
            'authorUserId' => isset($row['author_user_id']) ? max(0, (int) $row['author_user_id']) : null,
            'message' => (string) ($row['message_text'] ?? ''),
            'attachments' => bober_normalize_support_ticket_stored_attachments($row['attachments_json'] ?? null),
            'attachmentsCount' => max(0, (int) ($row['attachments_count'] ?? 0)),
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : '',
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $items;
}

function bober_fetch_user_support_tickets($conn, $userId, array $options = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    bober_ensure_support_schema($conn);
    bober_auto_archive_support_tickets($conn);
    $limit = max(1, min(100, (int) ($options['limit'] ?? 50)));
    $includeArchived = !empty($options['includeArchived']);
    $stmt = $conn->prepare("
        SELECT
            t.id,
            t.user_id,
            t.category,
            t.subject,
            t.status,
            t.unread_by_user,
            t.unread_by_admin,
            t.created_at,
            t.updated_at,
            t.last_user_message_at,
            t.last_admin_message_at,
            (
                SELECT CASE
                    WHEN CHAR_LENGTH(TRIM(COALESCE(m.message_text, ''))) > 0 THEN LEFT(m.message_text, 220)
                    WHEN COALESCE(m.attachments_count, 0) > 0 THEN CONCAT('Фото: ', m.attachments_count)
                    ELSE ''
                END
                FROM support_ticket_messages m
                WHERE m.ticket_id = t.id
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message_preview
        FROM support_tickets t
        WHERE t.user_id = ?
          AND (" . ($includeArchived ? '1=1' : 't.archived_at IS NULL') . ")
        ORDER BY t.unread_by_user DESC, t.updated_at DESC, t.id DESC
        LIMIT {$limit}
    ");
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить список тикетов пользователя.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить список тикетов пользователя.');
    }

    $result = $stmt->get_result();
    $items = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $items[] = bober_normalize_support_ticket_row($row);
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $items;
}

function bober_mark_user_support_ticket_read($conn, $userId, $ticketId)
{
    $userId = max(0, (int) $userId);
    $ticketId = max(0, (int) $ticketId);
    if ($userId < 1 || $ticketId < 1) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE support_tickets SET unread_by_user = 0 WHERE id = ? AND user_id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить отметку тикета как прочитанного.');
    }

    $stmt->bind_param('ii', $ticketId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось отметить тикет как прочитанный.');
    }

    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

function bober_fetch_user_support_ticket($conn, $userId, $ticketId, $markRead = false)
{
    $userId = max(0, (int) $userId);
    $ticketId = max(0, (int) $ticketId);
    if ($userId < 1 || $ticketId < 1) {
        throw new InvalidArgumentException('Некорректный тикет поддержки.');
    }

    bober_auto_archive_support_tickets($conn);
    $stmt = $conn->prepare('SELECT id, user_id, category, subject, status, unread_by_user, unread_by_admin, created_at, updated_at, last_user_message_at, last_admin_message_at, archived_at FROM support_tickets WHERE id = ? AND user_id = ? AND archived_at IS NULL LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение тикета поддержки.');
    }

    $stmt->bind_param('ii', $ticketId, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить тикет поддержки.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Тикет не найден.');
    }

    if ($markRead) {
        bober_mark_user_support_ticket_read($conn, $userId, $ticketId);
        $row['unread_by_user'] = 0;
    }

    $ticket = bober_normalize_support_ticket_row($row);
    $ticket['messages'] = bober_fetch_support_ticket_messages($conn, $ticketId);
    return $ticket;
}

function bober_create_support_ticket($conn, $userId, $category, $subject, $message, $attachments = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Не удалось определить аккаунт для тикета.');
    }

    bober_ensure_support_schema($conn);
    $category = bober_normalize_support_ticket_category($category);
    $subject = bober_normalize_support_ticket_subject($subject);
    $payload = bober_prepare_support_ticket_message_payload($message, $attachments);
    $status = 'waiting_support';

    $ticketStmt = $conn->prepare('INSERT INTO support_tickets (user_id, category, subject, status, unread_by_user, unread_by_admin, last_user_message_at) VALUES (?, ?, ?, ?, 0, 1, CURRENT_TIMESTAMP)');
    if (!$ticketStmt) {
        throw new RuntimeException('Не удалось подготовить создание тикета поддержки.');
    }

    $ticketStmt->bind_param('isss', $userId, $category, $subject, $status);
    if (!$ticketStmt->execute()) {
        $ticketStmt->close();
        throw new RuntimeException('Не удалось создать тикет поддержки.');
    }

    $ticketId = max(0, (int) $ticketStmt->insert_id);
    $ticketStmt->close();
    if ($ticketId < 1) {
        throw new RuntimeException('Не удалось определить созданный тикет поддержки.');
    }

    bober_log_support_ticket_status_change($conn, $ticketId, 'closed', $status, [
        'mode' => 'create_user',
    ]);

    $authorType = 'user';
    $storedAttachmentPaths = [];
    try {
        $storedAttachmentResult = bober_store_support_ticket_attachments($ticketId, $authorType, $payload['attachments']);
        $storedAttachments = $storedAttachmentResult['attachments'];
        $storedAttachmentPaths = $storedAttachmentResult['paths'];
        $attachmentsCount = count($storedAttachments);
        $attachmentsJson = $attachmentsCount > 0
            ? json_encode($storedAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($attachmentsCount > 0 && $attachmentsJson === false) {
            throw new RuntimeException('Не удалось сериализовать вложения тикета.');
        }

        $messageStmt = $conn->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_text, attachments_json, attachments_count) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$messageStmt) {
            throw new RuntimeException('Не удалось подготовить сохранение сообщения тикета.');
        }

        $messageStmt->bind_param('isissi', $ticketId, $authorType, $userId, $payload['message'], $attachmentsJson, $attachmentsCount);
        if (!$messageStmt->execute()) {
            $messageStmt->close();
            throw new RuntimeException('Не удалось сохранить сообщение тикета.');
        }
        $messageStmt->close();
    } catch (Throwable $error) {
        bober_cleanup_support_attachment_paths($storedAttachmentPaths);
        throw $error;
    }

    return bober_fetch_user_support_ticket($conn, $userId, $ticketId, false);
}

function bober_create_support_ticket_as_admin($conn, $userId, $category, $subject, $message, $attachments = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Не удалось определить аккаунт игрока для исходящего тикета.');
    }

    bober_ensure_support_schema($conn);
    $category = bober_normalize_support_ticket_category($category);
    $subject = bober_normalize_support_ticket_subject($subject);
    $payload = bober_prepare_support_ticket_message_payload($message, $attachments);
    $status = 'waiting_user';

    $userCheckStmt = $conn->prepare('SELECT id FROM users WHERE id = ? LIMIT 1');
    if (!$userCheckStmt) {
        throw new RuntimeException('Не удалось проверить аккаунт игрока для исходящего тикета.');
    }

    $userCheckStmt->bind_param('i', $userId);
    if (!$userCheckStmt->execute()) {
        $userCheckStmt->close();
        throw new RuntimeException('Не удалось проверить существование аккаунта игрока.');
    }

    $userCheckResult = $userCheckStmt->get_result();
    $userExists = $userCheckResult instanceof mysqli_result ? (bool) $userCheckResult->fetch_assoc() : false;
    if ($userCheckResult instanceof mysqli_result) {
        $userCheckResult->free();
    }
    $userCheckStmt->close();

    if (!$userExists) {
        throw new RuntimeException('Игрок для исходящего тикета не найден.');
    }

    $ticketStmt = $conn->prepare('INSERT INTO support_tickets (user_id, category, subject, status, unread_by_user, unread_by_admin, last_admin_message_at) VALUES (?, ?, ?, ?, 1, 0, CURRENT_TIMESTAMP)');
    if (!$ticketStmt) {
        throw new RuntimeException('Не удалось подготовить создание исходящего тикета поддержки.');
    }

    $ticketStmt->bind_param('isss', $userId, $category, $subject, $status);
    if (!$ticketStmt->execute()) {
        $ticketStmt->close();
        throw new RuntimeException('Не удалось создать исходящий тикет поддержки.');
    }

    $ticketId = max(0, (int) $ticketStmt->insert_id);
    $ticketStmt->close();
    if ($ticketId < 1) {
        throw new RuntimeException('Не удалось определить созданный исходящий тикет поддержки.');
    }

    bober_log_support_ticket_status_change($conn, $ticketId, 'closed', $status, [
        'mode' => 'create_admin',
    ]);

    $authorType = 'admin';
    $storedAttachmentPaths = [];
    try {
        $storedAttachmentResult = bober_store_support_ticket_attachments($ticketId, $authorType, $payload['attachments']);
        $storedAttachments = $storedAttachmentResult['attachments'];
        $storedAttachmentPaths = $storedAttachmentResult['paths'];
        $attachmentsCount = count($storedAttachments);
        $attachmentsJson = $attachmentsCount > 0
            ? json_encode($storedAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($attachmentsCount > 0 && $attachmentsJson === false) {
            throw new RuntimeException('Не удалось сериализовать вложения тикета.');
        }

        $messageStmt = $conn->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_text, attachments_json, attachments_count) VALUES (?, ?, NULL, ?, ?, ?)');
        if (!$messageStmt) {
            throw new RuntimeException('Не удалось подготовить первое сообщение исходящего тикета.');
        }

        $messageStmt->bind_param('isssi', $ticketId, $authorType, $payload['message'], $attachmentsJson, $attachmentsCount);
        if (!$messageStmt->execute()) {
            $messageStmt->close();
            throw new RuntimeException('Не удалось сохранить первое сообщение исходящего тикета.');
        }
        $messageStmt->close();
    } catch (Throwable $error) {
        bober_cleanup_support_attachment_paths($storedAttachmentPaths);
        throw $error;
    }

    return bober_fetch_admin_support_ticket($conn, $ticketId, false, [
        'includeArchived' => true,
    ]);
}

function bober_reply_support_ticket_as_user($conn, $userId, $ticketId, $message, $attachments = [])
{
    $userId = max(0, (int) $userId);
    $ticketId = max(0, (int) $ticketId);
    if ($userId < 1 || $ticketId < 1) {
        throw new InvalidArgumentException('Не удалось определить тикет пользователя.');
    }

    $payload = bober_prepare_support_ticket_message_payload($message, $attachments);
    $ticket = bober_fetch_user_support_ticket($conn, $userId, $ticketId, false);

    $authorType = 'user';
    $storedAttachmentPaths = [];
    try {
        $storedAttachmentResult = bober_store_support_ticket_attachments($ticketId, $authorType, $payload['attachments']);
        $storedAttachments = $storedAttachmentResult['attachments'];
        $storedAttachmentPaths = $storedAttachmentResult['paths'];
        $attachmentsCount = count($storedAttachments);
        $attachmentsJson = $attachmentsCount > 0
            ? json_encode($storedAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($attachmentsCount > 0 && $attachmentsJson === false) {
            throw new RuntimeException('Не удалось сериализовать вложения тикета.');
        }

        $messageStmt = $conn->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_text, attachments_json, attachments_count) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$messageStmt) {
            throw new RuntimeException('Не удалось подготовить ответ в тикет.');
        }

        $messageStmt->bind_param('isissi', $ticketId, $authorType, $userId, $payload['message'], $attachmentsJson, $attachmentsCount);
        if (!$messageStmt->execute()) {
            $messageStmt->close();
            throw new RuntimeException('Не удалось сохранить ответ пользователя.');
        }
        $messageStmt->close();
    } catch (Throwable $error) {
        bober_cleanup_support_attachment_paths($storedAttachmentPaths);
        throw $error;
    }

    $previousStatus = bober_normalize_support_ticket_status($ticket['status'] ?? 'waiting_support');
    $status = 'waiting_support';
    $updateStmt = $conn->prepare("UPDATE support_tickets SET status = ?, unread_by_admin = unread_by_admin + 1, updated_at = CURRENT_TIMESTAMP, last_user_message_at = CURRENT_TIMESTAMP, archived_at = NULL, archive_reason = '' WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось обновить статус тикета.');
    }

    $updateStmt->bind_param('sii', $status, $ticketId, $userId);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        throw new RuntimeException('Не удалось обновить тикет после ответа пользователя.');
    }
    $updateStmt->close();

    bober_log_support_ticket_status_change($conn, $ticketId, $previousStatus, $status, [
        'mode' => 'auto_user_reply',
    ]);

    return bober_fetch_user_support_ticket($conn, $userId, $ticketId, false);
}

function bober_mark_admin_support_ticket_read($conn, $ticketId)
{
    $ticketId = max(0, (int) $ticketId);
    if ($ticketId < 1) {
        return false;
    }

    $stmt = $conn->prepare('UPDATE support_tickets SET unread_by_admin = 0 WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить отметку тикета как прочитанного для админа.');
    }

    $stmt->bind_param('i', $ticketId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось отметить тикет как прочитанный для админа.');
    }

    $affected = $stmt->affected_rows > 0;
    $stmt->close();
    return $affected;
}

function bober_fetch_admin_support_tickets($conn, array $options = [])
{
    bober_ensure_support_schema($conn);
    bober_auto_archive_support_tickets($conn);
    $limit = max(1, min(200, (int) ($options['limit'] ?? 80)));
    $status = bober_normalize_support_ticket_status($options['status'] ?? '');
    $category = bober_normalize_support_ticket_category($options['category'] ?? '');
    $unreadFilter = trim((string) ($options['unread'] ?? 'all'));
    $search = trim((string) ($options['search'] ?? ''));
    $includeArchived = !empty($options['includeArchived']);
    $archivedOnly = !empty($options['archivedOnly']);

    $where = ['1=1'];
    if ($archivedOnly) {
        $where[] = 't.archived_at IS NOT NULL';
    } elseif (!$includeArchived) {
        $where[] = 't.archived_at IS NULL';
    }
    if (!empty($options['status']) && $status !== '') {
        $where[] = "t.status = '" . $conn->real_escape_string($status) . "'";
    }
    if (!empty($options['category']) && $category !== '') {
        $where[] = "t.category = '" . $conn->real_escape_string($category) . "'";
    }
    if ($unreadFilter === 'admin') {
        $where[] = 't.unread_by_admin > 0';
    } elseif ($unreadFilter === 'user') {
        $where[] = 't.unread_by_user > 0';
    }
    if ($search !== '') {
        $escapedSearch = $conn->real_escape_string('%' . $search . '%');
        $where[] = "(u.login LIKE '{$escapedSearch}' OR t.subject LIKE '{$escapedSearch}')";
    }

    $sql = "
        SELECT
            t.id,
            t.user_id,
            u.login,
            t.category,
            t.subject,
            t.status,
            t.unread_by_user,
            t.unread_by_admin,
            t.created_at,
            t.updated_at,
            t.last_user_message_at,
            t.last_admin_message_at,
            t.archived_at,
            (
                SELECT CASE
                    WHEN CHAR_LENGTH(TRIM(COALESCE(m.message_text, ''))) > 0 THEN LEFT(m.message_text, 220)
                    WHEN COALESCE(m.attachments_count, 0) > 0 THEN CONCAT('Фото: ', m.attachments_count)
                    ELSE ''
                END
                FROM support_ticket_messages m
                WHERE m.ticket_id = t.id
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message_preview
        FROM support_tickets t
        LEFT JOIN users u
            ON u.id = t.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.unread_by_admin DESC, t.updated_at DESC, t.id DESC
        LIMIT {$limit}
    ";

    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('Не удалось получить список тикетов поддержки.');
    }

    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = bober_normalize_support_ticket_row($row);
    }
    $result->free();

    return $items;
}

function bober_fetch_admin_support_ticket($conn, $ticketId, $markRead = true, array $options = [])
{
    $ticketId = max(0, (int) $ticketId);
    if ($ticketId < 1) {
        throw new InvalidArgumentException('Некорректный тикет поддержки.');
    }

    bober_auto_archive_support_tickets($conn);
    $includeArchived = !empty($options['includeArchived']);
    $whereArchive = $includeArchived ? '1=1' : 't.archived_at IS NULL';
    $stmt = $conn->prepare("SELECT t.id, t.user_id, u.login, t.category, t.subject, t.status, t.unread_by_user, t.unread_by_admin, t.created_at, t.updated_at, t.last_user_message_at, t.last_admin_message_at, t.archived_at FROM support_tickets t LEFT JOIN users u ON u.id = t.user_id WHERE t.id = ? AND {$whereArchive} LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение тикета поддержки для админа.');
    }

    $stmt->bind_param('i', $ticketId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить тикет поддержки для админа.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Тикет не найден.');
    }

    if ($markRead) {
        bober_mark_admin_support_ticket_read($conn, $ticketId);
        $row['unread_by_admin'] = 0;
    }

    $ticket = bober_normalize_support_ticket_row($row);
    $ticket['messages'] = bober_fetch_support_ticket_messages($conn, $ticketId);
    return $ticket;
}

function bober_reply_support_ticket_as_admin($conn, $ticketId, $message, $attachments = [])
{
    $ticketId = max(0, (int) $ticketId);
    if ($ticketId < 1) {
        throw new InvalidArgumentException('Не удалось определить тикет для ответа.');
    }

    $payload = bober_prepare_support_ticket_message_payload($message, $attachments);
    $ticket = bober_fetch_admin_support_ticket($conn, $ticketId, false, [
        'includeArchived' => true,
    ]);

    $authorType = 'admin';
    $storedAttachmentPaths = [];
    try {
        $storedAttachmentResult = bober_store_support_ticket_attachments($ticketId, $authorType, $payload['attachments']);
        $storedAttachments = $storedAttachmentResult['attachments'];
        $storedAttachmentPaths = $storedAttachmentResult['paths'];
        $attachmentsCount = count($storedAttachments);
        $attachmentsJson = $attachmentsCount > 0
            ? json_encode($storedAttachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        if ($attachmentsCount > 0 && $attachmentsJson === false) {
            throw new RuntimeException('Не удалось сериализовать вложения тикета.');
        }

        $messageStmt = $conn->prepare('INSERT INTO support_ticket_messages (ticket_id, author_type, author_user_id, message_text, attachments_json, attachments_count) VALUES (?, ?, NULL, ?, ?, ?)');
        if (!$messageStmt) {
            throw new RuntimeException('Не удалось подготовить ответ поддержки.');
        }

        $messageStmt->bind_param('isssi', $ticketId, $authorType, $payload['message'], $attachmentsJson, $attachmentsCount);
        if (!$messageStmt->execute()) {
            $messageStmt->close();
            throw new RuntimeException('Не удалось сохранить ответ поддержки.');
        }
        $messageStmt->close();
    } catch (Throwable $error) {
        bober_cleanup_support_attachment_paths($storedAttachmentPaths);
        throw $error;
    }

    $previousStatus = bober_normalize_support_ticket_status($ticket['status'] ?? 'waiting_support');
    $status = 'waiting_user';
    $updateStmt = $conn->prepare("UPDATE support_tickets SET status = ?, unread_by_user = unread_by_user + 1, unread_by_admin = 0, updated_at = CURRENT_TIMESTAMP, last_admin_message_at = CURRENT_TIMESTAMP, archived_at = NULL, archive_reason = '' WHERE id = ? LIMIT 1");
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось обновить тикет после ответа поддержки.');
    }

    $updateStmt->bind_param('si', $status, $ticketId);
    if (!$updateStmt->execute()) {
        $updateStmt->close();
        throw new RuntimeException('Не удалось обновить тикет после ответа поддержки.');
    }
    $updateStmt->close();

    bober_log_support_ticket_status_change($conn, $ticketId, $previousStatus, $status, [
        'mode' => 'auto_admin_reply',
    ]);

    return bober_fetch_admin_support_ticket($conn, $ticketId, false);
}

function bober_update_support_ticket_status($conn, $ticketId, $status)
{
    $ticketId = max(0, (int) $ticketId);
    $status = bober_normalize_support_ticket_status($status);
    if ($ticketId < 1) {
        throw new InvalidArgumentException('Не удалось определить тикет для смены статуса.');
    }

    $existingTicket = bober_fetch_admin_support_ticket($conn, $ticketId, false, [
        'includeArchived' => true,
    ]);
    $previousStatus = bober_normalize_support_ticket_status($existingTicket['status'] ?? 'waiting_support');

    $stmt = $conn->prepare("UPDATE support_tickets SET status = ?, updated_at = CURRENT_TIMESTAMP, archived_at = CASE WHEN ? <> 'closed' THEN NULL ELSE archived_at END, archive_reason = CASE WHEN ? <> 'closed' THEN '' ELSE archive_reason END WHERE id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить смену статуса тикета.');
    }

    $stmt->bind_param('sssi', $status, $status, $status, $ticketId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сменить статус тикета.');
    }
    $stmt->close();

    bober_log_support_ticket_status_change($conn, $ticketId, $previousStatus, $status, [
        'mode' => 'manual',
    ]);

    return bober_fetch_admin_support_ticket($conn, $ticketId, false);
}

function bober_get_default_achievement_reward_map()
{
    return [
        'clicker_10k' => 2500,
        'clicker_50k' => 7500,
        'clicker_100k' => 0,
        'clicker_500k' => 25000,
        'clicker_1m' => 0,
        'clicker_5m' => 100000,
        'clicker_10m' => 250000,
        'clicker_50m' => 1000000,
        'clicker_100m' => 2500000,
        'clicker_250m' => 6000000,
        'clicker_500m' => 12000000,
        'clicker_1b' => 25000000,
        'collector_1' => 1000,
        'collector_3' => 0,
        'collector_5' => 5000,
        'collector_10' => 15000,
        'collector_20' => 75000,
        'collector_30' => 150000,
        'collector_40' => 250000,
        'collector_50' => 400000,
        'fly_best_10' => 2500,
        'fly_best_25' => 0,
        'fly_best_50' => 0,
        'fly_best_75' => 15000,
        'fly_best_100' => 30000,
        'fly_best_150' => 75000,
        'fly_best_200' => 120000,
        'fly_best_300' => 300000,
        'fly_best_400' => 500000,
        'fly_best_500' => 900000,
        'fly_games_10' => 5000,
        'fly_games_50' => 15000,
        'fly_games_100' => 40000,
        'fly_games_250' => 80000,
        'fly_games_500' => 200000,
        'fly_games_750' => 350000,
        'fly_games_1000' => 600000,
        'upgrades_total_10' => 5000,
        'upgrades_total_25' => 15000,
        'upgrades_total_50' => 40000,
        'upgrades_total_100' => 100000,
        'upgrades_total_200' => 300000,
        'upgrades_total_300' => 500000,
        'upgrades_total_500' => 1000000,
        'plus_100' => 25000,
        'plus_500' => 150000,
        'plus_1000' => 500000,
        'energy_25k' => 50000,
        'energy_100k' => 250000,
        'energy_250k' => 700000,
        'energy_500k' => 1500000,
        'every_upgrade_once' => 60000,
        'tap_small_25' => 120000,
        'tap_big_25' => 150000,
        'energy_upgrade_25' => 160000,
        'tap_huge_25' => 220000,
        'regen_boost_25' => 260000,
        'energy_huge_10' => 300000,
        'top_clicker_1' => 0,
        'top_fly_1' => 0,
        'secret_double_top' => 250000,
        'secret_all_rounder' => 200000,
        'secret_grand_collector' => 200000,
        'secret_marathon_runner' => 300000,
        'secret_overclocked' => 350000,
        'secret_six_mastery' => 500000,
        'secret_clicker_legend' => 2500000,
        'secret_fly_machine' => 1800000,
        'secret_full_collection' => 1200000,
        'secret_balance_monster' => 2200000,
    ];
}

function bober_get_default_achievement_definition_map()
{
    return [
        'clicker_10k' => ['title' => 'Разогрев', 'description' => 'Наберите 10 000 коинов в основном кликере.', 'icon' => '🔥'],
        'clicker_50k' => ['title' => 'Уверенный старт', 'description' => 'Наберите 50 000 коинов в основном кликере.', 'icon' => '🚀'],
        'clicker_100k' => ['title' => 'Первая сотня тысяч', 'description' => 'Наберите 100 000 коинов в основном кликере.', 'icon' => '💯'],
        'clicker_500k' => ['title' => 'Полмиллиона', 'description' => 'Наберите 500 000 коинов в основном кликере.', 'icon' => '💎'],
        'clicker_1m' => ['title' => 'Миллионер', 'description' => 'Наберите 1 000 000 коинов в основном кликере.', 'icon' => '🏦'],
        'clicker_5m' => ['title' => 'Пять миллионов', 'description' => 'Наберите 5 000 000 коинов в основном кликере.', 'icon' => '💼'],
        'clicker_10m' => ['title' => 'Десятимиллионный клуб', 'description' => 'Наберите 10 000 000 коинов в основном кликере.', 'icon' => '🪙'],
        'clicker_50m' => ['title' => 'Финансовый бобр', 'description' => 'Наберите 50 000 000 коинов в основном кликере.', 'icon' => '🏛️'],
        'clicker_100m' => ['title' => 'Девятизначный бобр', 'description' => 'Наберите 100 000 000 коинов в основном кликере.', 'icon' => '📈'],
        'clicker_250m' => ['title' => 'Империя клика', 'description' => 'Наберите 250 000 000 коинов в основном кликере.', 'icon' => '🌆'],
        'clicker_500m' => ['title' => 'Полмиллиарда', 'description' => 'Наберите 500 000 000 коинов в основном кликере.', 'icon' => '🏙️'],
        'clicker_1b' => ['title' => 'Бобровый миллиард', 'description' => 'Наберите 1 000 000 000 коинов в основном кликере.', 'icon' => '🌍'],
        'collector_1' => ['title' => 'Первый скин', 'description' => 'Получите первый скин в коллекции.', 'icon' => '🧥'],
        'collector_3' => ['title' => 'Коллекционер', 'description' => 'Соберите минимум 3 скина.', 'icon' => '🎨'],
        'collector_5' => ['title' => 'Гардероб', 'description' => 'Соберите минимум 5 скинов.', 'icon' => '👕'],
        'collector_10' => ['title' => 'Модный дом', 'description' => 'Соберите минимум 10 скинов.', 'icon' => '🛍️'],
        'collector_20' => ['title' => 'Музей бобров', 'description' => 'Соберите минимум 20 скинов.', 'icon' => '🏛️'],
        'collector_30' => ['title' => 'Легендарный гардероб', 'description' => 'Соберите минимум 30 скинов.', 'icon' => '🎭'],
        'collector_40' => ['title' => 'Хранитель коллекции', 'description' => 'Соберите минимум 40 скинов.', 'icon' => '🗃️'],
        'collector_50' => ['title' => 'Полная витрина', 'description' => 'Соберите минимум 50 скинов.', 'icon' => '🪞'],
        'fly_best_10' => ['title' => 'Первые крылья', 'description' => 'Получите рекорд 10 в Летающем бобре.', 'icon' => '🪽'],
        'fly_best_25' => ['title' => 'Низкий пролет', 'description' => 'Получите рекорд 25 в Летающем бобре.', 'icon' => '🌿'],
        'fly_best_50' => ['title' => 'Повелитель болота', 'description' => 'Получите рекорд 50 в Летающем бобре.', 'icon' => '🌫️'],
        'fly_best_75' => ['title' => 'Над камышами', 'description' => 'Получите рекорд 75 в Летающем бобре.', 'icon' => '🪶'],
        'fly_best_100' => ['title' => 'Сотня в полете', 'description' => 'Получите рекорд 100 в Летающем бобре.', 'icon' => '🌤️'],
        'fly_best_150' => ['title' => 'Небесный бобр', 'description' => 'Получите рекорд 150 в Летающем бобре.', 'icon' => '☁️'],
        'fly_best_200' => ['title' => 'Стратосфера', 'description' => 'Получите рекорд 200 в Летающем бобре.', 'icon' => '🌌'],
        'fly_best_300' => ['title' => 'За облаками', 'description' => 'Получите рекорд 300 в Летающем бобре.', 'icon' => '🌠'],
        'fly_best_400' => ['title' => 'Над горизонтом', 'description' => 'Получите рекорд 400 в Летающем бобре.', 'icon' => '🛰️'],
        'fly_best_500' => ['title' => 'Предел неба', 'description' => 'Получите рекорд 500 в Летающем бобре.', 'icon' => '🚀'],
        'fly_games_10' => ['title' => 'Не сдаюсь', 'description' => 'Сыграйте 10 забегов в Летающем бобре.', 'icon' => '🎮'],
        'fly_games_50' => ['title' => 'Ветеран полетов', 'description' => 'Сыграйте 50 забегов в Летающем бобре.', 'icon' => '🧭'],
        'fly_games_100' => ['title' => 'Летная школа', 'description' => 'Сыграйте 100 забегов в Летающем бобре.', 'icon' => '🏅'],
        'fly_games_250' => ['title' => 'Пилот-испытатель', 'description' => 'Сыграйте 250 забегов в Летающем бобре.', 'icon' => '🧪'],
        'fly_games_500' => ['title' => 'Авиапарк бобров', 'description' => 'Сыграйте 500 забегов в Летающем бобре.', 'icon' => '🛫'],
        'fly_games_750' => ['title' => 'Лётный марафон', 'description' => 'Сыграйте 750 забегов в Летающем бобре.', 'icon' => '🛩️'],
        'fly_games_1000' => ['title' => 'Тысяча вылетов', 'description' => 'Сыграйте 1000 забегов в Летающем бобре.', 'icon' => '🪂'],
        'upgrades_total_10' => ['title' => 'Механик', 'description' => 'Купите суммарно 10 улучшений.', 'icon' => '🛠️'],
        'upgrades_total_25' => ['title' => 'Инженер клика', 'description' => 'Купите суммарно 25 улучшений.', 'icon' => '⚙️'],
        'upgrades_total_50' => ['title' => 'Архитектор фермы', 'description' => 'Купите суммарно 50 улучшений.', 'icon' => '🏗️'],
        'upgrades_total_100' => ['title' => 'Главный конструктор', 'description' => 'Купите суммарно 100 улучшений.', 'icon' => '🏭'],
        'upgrades_total_200' => ['title' => 'Индустриальный рывок', 'description' => 'Купите суммарно 200 улучшений.', 'icon' => '🏗'],
        'upgrades_total_300' => ['title' => 'Фабрика клика', 'description' => 'Купите суммарно 300 улучшений.', 'icon' => '🏭'],
        'upgrades_total_500' => ['title' => 'Мегакомбинат', 'description' => 'Купите суммарно 500 улучшений.', 'icon' => '🌐'],
        'plus_100' => ['title' => 'Рука тяжелеет', 'description' => 'Поднимите силу клика до +100.', 'icon' => '✋'],
        'plus_500' => ['title' => 'Удар плотины', 'description' => 'Поднимите силу клика до +500.', 'icon' => '💥'],
        'plus_1000' => ['title' => 'Сверхудар', 'description' => 'Поднимите силу клика до +1000.', 'icon' => '🧨'],
        'energy_25k' => ['title' => 'Большой запас', 'description' => 'Доведите максимум энергии до 25 000.', 'icon' => '🔋'],
        'energy_100k' => ['title' => 'Резервуар', 'description' => 'Доведите максимум энергии до 100 000.', 'icon' => '⚡'],
        'energy_250k' => ['title' => 'Энергоблок', 'description' => 'Доведите максимум энергии до 250 000.', 'icon' => '🔌'],
        'energy_500k' => ['title' => 'Энергостанция', 'description' => 'Доведите максимум энергии до 500 000.', 'icon' => '⚙️'],
        'every_upgrade_once' => ['title' => 'Полный стенд', 'description' => 'Купите хотя бы по одному разу каждое улучшение.', 'icon' => '🧰'],
        'tap_small_25' => ['title' => 'Точная лапа', 'description' => 'Прокачайте маленький тап до 25 уровня.', 'icon' => '🐾'],
        'tap_big_25' => ['title' => 'Тяжёлый клик', 'description' => 'Прокачайте большой тап до 25 уровня.', 'icon' => '🔨'],
        'energy_upgrade_25' => ['title' => 'Энергетик', 'description' => 'Прокачайте энергию до 25 уровня.', 'icon' => '🔋'],
        'tap_huge_25' => ['title' => 'Сокрушитель', 'description' => 'Прокачайте огромный тап до 25 уровня.', 'icon' => '🪵'],
        'regen_boost_25' => ['title' => 'Быстрая зарядка X25', 'description' => 'Прокачайте скорость восполнения энергии до 25 уровня.', 'icon' => '⚡'],
        'energy_huge_10' => ['title' => 'Сверхзапас X10', 'description' => 'Купите 10 уровней огромного запаса энергии.', 'icon' => '🛢️'],
        'top_clicker_1' => ['title' => 'Топ-1 кликера', 'description' => 'Удерживайте первое место в основном лидерборде.', 'icon' => '👑'],
        'top_fly_1' => ['title' => 'Топ-1 fly-beaver', 'description' => 'Удерживайте первое место в Летающем бобре.', 'icon' => '🏆'],
        'secret_double_top' => ['title' => 'Две короны', 'description' => 'Одновременно удерживайте топ-1 в кликере и в Летающем бобре.', 'icon' => '👑', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_all_rounder' => ['title' => 'Универсальный бобр', 'description' => 'Наберите 10 000 000 коинов, получите рекорд 100, соберите 10 скинов и купите 50 улучшений.', 'icon' => '🦫', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_grand_collector' => ['title' => 'Хранитель витрины', 'description' => 'Соберите 30 скинов и купите 100 улучшений.', 'icon' => '🗝️', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_marathon_runner' => ['title' => 'Живущий в небе', 'description' => 'Сыграйте 500 забегов и получите рекорд 200 в Летающем бобре.', 'icon' => '🌙', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_overclocked' => ['title' => 'Перегрузка', 'description' => 'Поднимите силу клика до +500 и максимум энергии до 100 000.', 'icon' => '⚙️', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_six_mastery' => ['title' => 'Шесть путей', 'description' => 'Прокачайте каждое из шести улучшений хотя бы до 10 уровня.', 'icon' => '🧠', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_clicker_legend' => ['title' => 'Легенда плотины', 'description' => 'Наберите 1 000 000 000 коинов и купите суммарно 500 улучшений.', 'icon' => '🏔️', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_fly_machine' => ['title' => 'Машина полёта', 'description' => 'Получите рекорд 400, сыграйте 1000 забегов и прокачайте каждое из шести улучшений хотя бы до 10 уровня.', 'icon' => '🛸', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_full_collection' => ['title' => 'Повелитель витрины', 'description' => 'Соберите 50 скинов и удерживайте топ-1 кликера.', 'icon' => '🫅', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
        'secret_balance_monster' => ['title' => 'Идеальный билд', 'description' => 'Поднимите силу клика до +1000, максимум энергии до 500 000 и соберите 40 скинов.', 'icon' => '🧬', 'secret' => true, 'lockedTitle' => 'Секретное достижение', 'lockedDescription' => 'Условие раскроется только после выполнения.', 'lockedIcon' => '❓'],
    ];
}

function bober_fetch_achievement_catalog_map($conn, $forceRefresh = false)
{
    $defaultRewardMap = bober_get_default_achievement_reward_map();
    $defaultDefinitionMap = bober_get_default_achievement_definition_map();
    $cacheKey = 'achievement_catalog_map_v1';

    if (!$forceRefresh) {
        $cached = bober_runtime_cache_fetch($conn, $cacheKey);
        if (is_array($cached['payload'] ?? null)) {
            return $cached['payload'];
        }
    }

    bober_ensure_achievement_catalog_schema($conn);

    $catalogMap = [];
    foreach ($defaultRewardMap as $achievementKey => $rewardCoins) {
        $defaultDefinition = is_array($defaultDefinitionMap[$achievementKey] ?? null) ? $defaultDefinitionMap[$achievementKey] : [];
        $catalogMap[(string) $achievementKey] = [
            'key' => (string) $achievementKey,
            'title' => trim((string) ($defaultDefinition['title'] ?? '')),
            'description' => trim((string) ($defaultDefinition['description'] ?? '')),
            'icon' => trim((string) ($defaultDefinition['icon'] ?? '')),
            'lockedTitle' => trim((string) ($defaultDefinition['lockedTitle'] ?? '')),
            'lockedDescription' => trim((string) ($defaultDefinition['lockedDescription'] ?? '')),
            'lockedIcon' => trim((string) ($defaultDefinition['lockedIcon'] ?? '')),
            'secret' => array_key_exists('secret', $defaultDefinition) ? !empty($defaultDefinition['secret']) : null,
            'rewardCoins' => max(0, (int) $rewardCoins),
            'isActive' => true,
            'createdAt' => '',
            'updatedAt' => '',
        ];
    }

    $stmt = $conn->prepare('SELECT achievement_key, title, description, icon, locked_title, locked_description, locked_icon, is_secret, reward_coins, is_active, created_at, updated_at FROM achievement_catalog');
    if ($stmt && $stmt->execute()) {
        $result = $stmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $achievementKey = trim((string) ($row['achievement_key'] ?? ''));
            if ($achievementKey === '' || !array_key_exists($achievementKey, $catalogMap)) {
                continue;
            }

            $catalogMap[$achievementKey] = [
                'key' => $achievementKey,
                'title' => trim((string) ($row['title'] ?? '')),
                'description' => trim((string) ($row['description'] ?? '')),
                'icon' => trim((string) ($row['icon'] ?? '')),
                'lockedTitle' => trim((string) ($row['locked_title'] ?? '')),
                'lockedDescription' => trim((string) ($row['locked_description'] ?? '')),
                'lockedIcon' => trim((string) ($row['locked_icon'] ?? '')),
                'secret' => array_key_exists('is_secret', $row) && $row['is_secret'] !== null
                    ? ((int) $row['is_secret'] === 1)
                    : null,
                'rewardCoins' => array_key_exists('reward_coins', $row) && $row['reward_coins'] !== null
                    ? max(0, (int) $row['reward_coins'])
                    : $catalogMap[$achievementKey]['rewardCoins'],
                'isActive' => array_key_exists('is_active', $row) && $row['is_active'] !== null
                    ? ((int) $row['is_active'] === 1)
                    : true,
                'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : '',
                'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
            ];
        }
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();
    } elseif ($stmt) {
        $stmt->close();
    }

    bober_runtime_cache_store($conn, $cacheKey, $catalogMap, 180);
    return $catalogMap;
}

function bober_fetch_achievement_catalog($conn, $forceRefresh = false)
{
    return array_values(bober_fetch_achievement_catalog_map($conn, $forceRefresh));
}

function bober_fetch_achievement_catalog_item($conn, $achievementKey)
{
    $achievementKey = trim((string) $achievementKey);
    if ($achievementKey === '') {
        return null;
    }

    $catalogMap = bober_fetch_achievement_catalog_map($conn);
    return is_array($catalogMap[$achievementKey] ?? null) ? $catalogMap[$achievementKey] : null;
}

function bober_get_achievement_reward_map($conn = null)
{
    if (!($conn instanceof mysqli)) {
        return bober_get_default_achievement_reward_map();
    }

    $catalogMap = bober_fetch_achievement_catalog_map($conn);
    $rewardMap = [];
    foreach ($catalogMap as $achievementKey => $item) {
        $rewardMap[(string) $achievementKey] = max(0, (int) ($item['rewardCoins'] ?? 0));
    }

    return $rewardMap;
}

function bober_get_achievement_reward_coins($achievementKey, $conn = null)
{
    $map = bober_get_achievement_reward_map($conn);
    return max(0, (int) ($map[$achievementKey] ?? 0));
}

function bober_get_all_achievement_keys()
{
    return array_values(array_keys(bober_get_default_achievement_reward_map()));
}

function bober_is_achievement_active($achievementKey, $conn = null)
{
    $achievementKey = trim((string) $achievementKey);
    if ($achievementKey === '') {
        return false;
    }

    if (!($conn instanceof mysqli)) {
        return array_key_exists($achievementKey, bober_get_default_achievement_reward_map());
    }

    $catalogItem = bober_fetch_achievement_catalog_item($conn, $achievementKey);
    if (!is_array($catalogItem)) {
        return false;
    }

    return !empty($catalogItem['isActive']);
}

function bober_normalize_achievement_catalog_payload($achievementKey, array $data = [])
{
    $achievementKey = trim((string) $achievementKey);
    if ($achievementKey === '' || !array_key_exists($achievementKey, bober_get_default_achievement_reward_map())) {
        throw new InvalidArgumentException('Некорректный ключ достижения.');
    }

    $defaultDefinitionMap = bober_get_default_achievement_definition_map();
    $defaultDefinition = is_array($defaultDefinitionMap[$achievementKey] ?? null) ? $defaultDefinitionMap[$achievementKey] : [];

    $title = trim((string) ($data['title'] ?? $defaultDefinition['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? $defaultDefinition['description'] ?? ''));
    $icon = trim((string) ($data['icon'] ?? $defaultDefinition['icon'] ?? ''));
    $lockedTitle = trim((string) ($data['lockedTitle'] ?? $data['locked_title'] ?? $defaultDefinition['lockedTitle'] ?? ''));
    $lockedDescription = trim((string) ($data['lockedDescription'] ?? $data['locked_description'] ?? $defaultDefinition['lockedDescription'] ?? ''));
    $lockedIcon = trim((string) ($data['lockedIcon'] ?? $data['locked_icon'] ?? $defaultDefinition['lockedIcon'] ?? ''));
    $rewardCoins = max(0, (int) ($data['rewardCoins'] ?? $data['reward_coins'] ?? bober_get_achievement_reward_coins($achievementKey)));
    $isActive = !array_key_exists('isActive', $data)
        ? (!isset($data['is_active']) || (int) $data['is_active'] === 1)
        : !empty($data['isActive']);
    $isSecret = !array_key_exists('isSecret', $data)
        ? ((isset($data['is_secret']) && (int) $data['is_secret'] === 1) || !empty($defaultDefinition['secret']))
        : !empty($data['isSecret']);

    $textLength = static function ($value) {
        $text = (string) $value;
        if (function_exists('mb_strlen')) {
            return (int) mb_strlen($text, 'UTF-8');
        }

        return strlen($text);
    };

    if ($title !== '' && $textLength($title) > 160) {
        throw new InvalidArgumentException('Название достижения должно быть не длиннее 160 символов.');
    }
    if ($description !== '' && $textLength($description) > 255) {
        throw new InvalidArgumentException('Описание достижения должно быть не длиннее 255 символов.');
    }
    if ($icon !== '' && $textLength($icon) > 32) {
        throw new InvalidArgumentException('Иконка достижения должна быть не длиннее 32 символов.');
    }
    if ($lockedTitle !== '' && $textLength($lockedTitle) > 160) {
        throw new InvalidArgumentException('Скрытое название достижения должно быть не длиннее 160 символов.');
    }
    if ($lockedDescription !== '' && $textLength($lockedDescription) > 255) {
        throw new InvalidArgumentException('Скрытое описание достижения должно быть не длиннее 255 символов.');
    }
    if ($lockedIcon !== '' && $textLength($lockedIcon) > 32) {
        throw new InvalidArgumentException('Скрытая иконка достижения должна быть не длиннее 32 символов.');
    }

    return [
        'key' => $achievementKey,
        'title' => $title,
        'description' => $description,
        'icon' => $icon,
        'lockedTitle' => $lockedTitle,
        'lockedDescription' => $lockedDescription,
        'lockedIcon' => $lockedIcon,
        'secret' => $isSecret,
        'rewardCoins' => $rewardCoins,
        'isActive' => $isActive,
    ];
}

function bober_update_achievement_catalog_item($conn, $achievementKey, array $data)
{
    bober_ensure_achievement_catalog_schema($conn);
    $item = bober_normalize_achievement_catalog_payload($achievementKey, $data);

    $stmt = $conn->prepare(
        'INSERT INTO achievement_catalog (
            achievement_key,
            title,
            description,
            icon,
            locked_title,
            locked_description,
            locked_icon,
            is_secret,
            reward_coins,
            is_active
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            description = VALUES(description),
            icon = VALUES(icon),
            locked_title = VALUES(locked_title),
            locked_description = VALUES(locked_description),
            locked_icon = VALUES(locked_icon),
            is_secret = VALUES(is_secret),
            reward_coins = VALUES(reward_coins),
            is_active = VALUES(is_active)'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сохранение каталога достижений.');
    }

    $secretFlag = $item['secret'] ? 1 : 0;
    $activeFlag = $item['isActive'] ? 1 : 0;
    $stmt->bind_param(
        'sssssssiii',
        $item['key'],
        $item['title'],
        $item['description'],
        $item['icon'],
        $item['lockedTitle'],
        $item['lockedDescription'],
        $item['lockedIcon'],
        $secretFlag,
        $item['rewardCoins'],
        $activeFlag
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сохранить каталог достижений.');
    }
    $stmt->close();

    bober_runtime_cache_delete($conn, 'achievement_catalog_map_v1');
    return bober_fetch_achievement_catalog_item($conn, $achievementKey);
}

function bober_build_achievement_meta_payload($achievementKey, $rewardCoins, array $catalogItem = [], array $extra = [])
{
    $meta = [
        'rewardCoins' => max(0, (int) $rewardCoins),
    ];

    $fieldMap = [
        'title' => 'title',
        'description' => 'description',
        'icon' => 'icon',
        'lockedTitle' => 'lockedTitle',
        'lockedDescription' => 'lockedDescription',
        'lockedIcon' => 'lockedIcon',
    ];

    foreach ($fieldMap as $targetKey => $sourceKey) {
        $value = trim((string) ($catalogItem[$sourceKey] ?? ''));
        if ($value !== '') {
            $meta[$targetKey] = $value;
        }
    }

    if (array_key_exists('secret', $catalogItem) && $catalogItem['secret'] !== null) {
        $meta['secret'] = !empty($catalogItem['secret']);
    }

    foreach ($extra as $key => $value) {
        $meta[$key] = $value;
    }

    return $meta;
}

function bober_fetch_achievement_stats($conn, $forceRefresh = false)
{
    $cacheKey = 'public_achievement_stats_v1';
    if (!$forceRefresh) {
        $cached = bober_runtime_cache_fetch($conn, $cacheKey);
        if (is_array($cached['payload'] ?? null)) {
            $cachedPayload = $cached['payload'];
            if (isset($cachedPayload['items']) && is_array($cachedPayload['items'])) {
                return [
                    'totalPlayers' => max(0, (int) ($cachedPayload['totalPlayers'] ?? 0)),
                    'items' => $cachedPayload['items'],
                ];
            }
        }
    }

    $allKeys = bober_get_all_achievement_keys();
    $totalPlayers = 0;
    $totalStmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE login <> 'test'");
    if ($totalStmt && $totalStmt->execute()) {
        $result = $totalStmt->get_result();
        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $totalPlayers = max(0, (int) ($row['total'] ?? 0));
        $totalStmt->close();
    } elseif ($totalStmt) {
        $totalStmt->close();
    }

    $stats = [];
    foreach ($allKeys as $achievementKey) {
        $stats[$achievementKey] = [
            'unlockCount' => 0,
            'totalPlayers' => $totalPlayers,
            'unlockPercent' => 0,
        ];
    }

    $statsStmt = $conn->prepare("
        SELECT ua.achievement_key, COUNT(*) AS unlock_count
        FROM user_achievements ua
        INNER JOIN users u ON u.id = ua.user_id
        WHERE u.login <> 'test'
          AND ua.revoked_at IS NULL
        GROUP BY ua.achievement_key
    ");
    if ($statsStmt && $statsStmt->execute()) {
        $result = $statsStmt->get_result();
        while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
            $achievementKey = trim((string) ($row['achievement_key'] ?? ''));
            if ($achievementKey === '' || !array_key_exists($achievementKey, $stats)) {
                continue;
            }

            $unlockCount = max(0, (int) ($row['unlock_count'] ?? 0));
            $unlockPercent = $totalPlayers > 0
                ? round(($unlockCount / $totalPlayers) * 100, 2)
                : 0;

            $stats[$achievementKey] = [
                'unlockCount' => $unlockCount,
                'totalPlayers' => $totalPlayers,
                'unlockPercent' => $unlockPercent,
            ];
        }

        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $statsStmt->close();
    } elseif ($statsStmt) {
        $statsStmt->close();
    }

    $payload = [
        'totalPlayers' => $totalPlayers,
        'items' => $stats,
    ];
    bober_runtime_cache_store($conn, $cacheKey, $payload, 180);

    return $payload;
}

function bober_enrich_achievement_items(array $items, array $achievementStats, $totalPlayers = 0, array $catalogMap = [])
{
    $resolvedTotalPlayers = max(0, (int) $totalPlayers);
    $enrichedItems = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $achievementKey = trim((string) ($item['key'] ?? ''));
        if ($achievementKey === '') {
            continue;
        }

        $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
        $stats = is_array($achievementStats[$achievementKey] ?? null) ? $achievementStats[$achievementKey] : [];
        $catalogItem = is_array($catalogMap[$achievementKey] ?? null) ? $catalogMap[$achievementKey] : [];
        $meta['rewardCoins'] = max(0, (int) ($meta['rewardCoins'] ?? $catalogItem['rewardCoins'] ?? bober_get_achievement_reward_coins($achievementKey)));
        $meta['unlockCount'] = max(0, (int) ($stats['unlockCount'] ?? 0));
        $meta['playerBase'] = max(0, (int) ($stats['totalPlayers'] ?? $resolvedTotalPlayers));
        $meta['unlockPercent'] = max(0, (float) ($stats['unlockPercent'] ?? 0));
        foreach ([
            'title' => 'title',
            'description' => 'description',
            'icon' => 'icon',
            'lockedTitle' => 'lockedTitle',
            'lockedDescription' => 'lockedDescription',
            'lockedIcon' => 'lockedIcon',
        ] as $metaKey => $catalogKey) {
            $catalogValue = trim((string) ($catalogItem[$catalogKey] ?? ''));
            if ($catalogValue !== '') {
                $meta[$metaKey] = $catalogValue;
            }
        }
        if (array_key_exists('secret', $catalogItem) && $catalogItem['secret'] !== null) {
            $meta['secret'] = !empty($catalogItem['secret']);
        }
        $item['meta'] = $meta;
        $enrichedItems[] = $item;
    }

    return $enrichedItems;
}

function bober_collect_expected_achievement_keys(array $snapshot)
{
    $keys = [];
    $score = max(0, (int) ($snapshot['score'] ?? 0));
    $plus = max(0, (int) ($snapshot['plus'] ?? 0));
    $energyMax = max(0, (int) ($snapshot['energyMax'] ?? 0));
    $ownedSkinCount = max(0, (int) ($snapshot['ownedSkinCount'] ?? 0));
    $totalUpgradePurchases = max(0, (int) ($snapshot['totalUpgradePurchases'] ?? 0));
    $clickerTop1 = !empty($snapshot['clickerTop1']);
    $flyTop1 = !empty($snapshot['flyTop1']);
    $upgradeCounts = is_array($snapshot['upgradeCounts'] ?? null) ? $snapshot['upgradeCounts'] : [];
    $normalizedUpgradeCounts = [
        max(0, (int) ($upgradeCounts['tapSmall'] ?? 0)),
        max(0, (int) ($upgradeCounts['tapBig'] ?? 0)),
        max(0, (int) ($upgradeCounts['energy'] ?? 0)),
        max(0, (int) ($upgradeCounts['tapHuge'] ?? 0)),
        max(0, (int) ($upgradeCounts['regenBoost'] ?? 0)),
        max(0, (int) ($upgradeCounts['energyHuge'] ?? 0)),
    ];
    $allUpgradeTypesBought = !empty($normalizedUpgradeCounts) && min($normalizedUpgradeCounts) >= 1;
    $allUpgradeTypesMastered = !empty($normalizedUpgradeCounts) && min($normalizedUpgradeCounts) >= 10;
    $flyBeaver = is_array($snapshot['flyBeaver'] ?? null) ? $snapshot['flyBeaver'] : [];
    $flyBest = max(0, (int) ($flyBeaver['bestScore'] ?? 0));
    $flyGamesPlayed = max(0, (int) ($flyBeaver['gamesPlayed'] ?? ($snapshot['flyGamesPlayed'] ?? 0)));

    if ($score >= 10000) {
        $keys[] = 'clicker_10k';
    }
    if ($score >= 50000) {
        $keys[] = 'clicker_50k';
    }

    if ($score >= 100000) {
        $keys[] = 'clicker_100k';
    }
    if ($score >= 500000) {
        $keys[] = 'clicker_500k';
    }
    if ($score >= 1000000) {
        $keys[] = 'clicker_1m';
    }
    if ($score >= 5000000) {
        $keys[] = 'clicker_5m';
    }
    if ($score >= 10000000) {
        $keys[] = 'clicker_10m';
    }
    if ($score >= 50000000) {
        $keys[] = 'clicker_50m';
    }
    if ($score >= 100000000) {
        $keys[] = 'clicker_100m';
    }
    if ($score >= 250000000) {
        $keys[] = 'clicker_250m';
    }
    if ($score >= 500000000) {
        $keys[] = 'clicker_500m';
    }
    if ($score >= 1000000000) {
        $keys[] = 'clicker_1b';
    }
    if ($ownedSkinCount >= 1) {
        $keys[] = 'collector_1';
    }
    if ($ownedSkinCount >= 5) {
        $keys[] = 'collector_5';
    }
    if ($ownedSkinCount >= 10) {
        $keys[] = 'collector_10';
    }
    if ($ownedSkinCount >= 20) {
        $keys[] = 'collector_20';
    }
    if ($ownedSkinCount >= 30) {
        $keys[] = 'collector_30';
    }
    if ($ownedSkinCount >= 40) {
        $keys[] = 'collector_40';
    }
    if ($ownedSkinCount >= 50) {
        $keys[] = 'collector_50';
    }
    if ($flyBest >= 10) {
        $keys[] = 'fly_best_10';
    }
    if ($flyBest >= 25) {
        $keys[] = 'fly_best_25';
    }
    if ($flyBest >= 50) {
        $keys[] = 'fly_best_50';
    }
    if ($flyBest >= 75) {
        $keys[] = 'fly_best_75';
    }
    if ($flyBest >= 100) {
        $keys[] = 'fly_best_100';
    }
    if ($flyBest >= 150) {
        $keys[] = 'fly_best_150';
    }
    if ($flyBest >= 200) {
        $keys[] = 'fly_best_200';
    }
    if ($flyBest >= 300) {
        $keys[] = 'fly_best_300';
    }
    if ($flyBest >= 400) {
        $keys[] = 'fly_best_400';
    }
    if ($flyBest >= 500) {
        $keys[] = 'fly_best_500';
    }
    if ($flyGamesPlayed >= 10) {
        $keys[] = 'fly_games_10';
    }
    if ($flyGamesPlayed >= 50) {
        $keys[] = 'fly_games_50';
    }
    if ($flyGamesPlayed >= 100) {
        $keys[] = 'fly_games_100';
    }
    if ($flyGamesPlayed >= 250) {
        $keys[] = 'fly_games_250';
    }
    if ($flyGamesPlayed >= 500) {
        $keys[] = 'fly_games_500';
    }
    if ($flyGamesPlayed >= 750) {
        $keys[] = 'fly_games_750';
    }
    if ($flyGamesPlayed >= 1000) {
        $keys[] = 'fly_games_1000';
    }
    if ($ownedSkinCount >= 3) {
        $keys[] = 'collector_3';
    }
    if ($totalUpgradePurchases >= 10) {
        $keys[] = 'upgrades_total_10';
    }
    if ($totalUpgradePurchases >= 25) {
        $keys[] = 'upgrades_total_25';
    }
    if ($totalUpgradePurchases >= 50) {
        $keys[] = 'upgrades_total_50';
    }
    if ($totalUpgradePurchases >= 100) {
        $keys[] = 'upgrades_total_100';
    }
    if ($totalUpgradePurchases >= 200) {
        $keys[] = 'upgrades_total_200';
    }
    if ($totalUpgradePurchases >= 300) {
        $keys[] = 'upgrades_total_300';
    }
    if ($totalUpgradePurchases >= 500) {
        $keys[] = 'upgrades_total_500';
    }
    if ($plus >= 100) {
        $keys[] = 'plus_100';
    }
    if ($plus >= 500) {
        $keys[] = 'plus_500';
    }
    if ($plus >= 1000) {
        $keys[] = 'plus_1000';
    }
    if ($energyMax >= 25000) {
        $keys[] = 'energy_25k';
    }
    if ($energyMax >= 100000) {
        $keys[] = 'energy_100k';
    }
    if ($energyMax >= 250000) {
        $keys[] = 'energy_250k';
    }
    if ($energyMax >= 500000) {
        $keys[] = 'energy_500k';
    }
    if ($allUpgradeTypesBought) {
        $keys[] = 'every_upgrade_once';
    }
    if (max(0, (int) ($upgradeCounts['tapSmall'] ?? 0)) >= 25) {
        $keys[] = 'tap_small_25';
    }
    if (max(0, (int) ($upgradeCounts['tapBig'] ?? 0)) >= 25) {
        $keys[] = 'tap_big_25';
    }
    if (max(0, (int) ($upgradeCounts['energy'] ?? 0)) >= 25) {
        $keys[] = 'energy_upgrade_25';
    }
    if (max(0, (int) ($upgradeCounts['tapHuge'] ?? 0)) >= 25) {
        $keys[] = 'tap_huge_25';
    }
    if (max(0, (int) ($upgradeCounts['regenBoost'] ?? 0)) >= 25) {
        $keys[] = 'regen_boost_25';
    }
    if (max(0, (int) ($upgradeCounts['energyHuge'] ?? 0)) >= 10) {
        $keys[] = 'energy_huge_10';
    }
    if ($clickerTop1) {
        $keys[] = 'top_clicker_1';
    }
    if ($flyTop1) {
        $keys[] = 'top_fly_1';
    }
    if ($clickerTop1 && $flyTop1) {
        $keys[] = 'secret_double_top';
    }
    if ($score >= 10000000 && $flyBest >= 100 && $ownedSkinCount >= 10 && $totalUpgradePurchases >= 50) {
        $keys[] = 'secret_all_rounder';
    }
    if ($ownedSkinCount >= 30 && $totalUpgradePurchases >= 100) {
        $keys[] = 'secret_grand_collector';
    }
    if ($flyGamesPlayed >= 500 && $flyBest >= 200) {
        $keys[] = 'secret_marathon_runner';
    }
    if ($plus >= 500 && $energyMax >= 100000) {
        $keys[] = 'secret_overclocked';
    }
    if ($allUpgradeTypesMastered) {
        $keys[] = 'secret_six_mastery';
    }
    if ($score >= 1000000000 && $totalUpgradePurchases >= 500) {
        $keys[] = 'secret_clicker_legend';
    }
    if ($flyBest >= 400 && $flyGamesPlayed >= 1000 && $allUpgradeTypesMastered) {
        $keys[] = 'secret_fly_machine';
    }
    if ($ownedSkinCount >= 50 && $clickerTop1) {
        $keys[] = 'secret_full_collection';
    }
    if ($plus >= 1000 && $energyMax >= 500000 && $ownedSkinCount >= 40) {
        $keys[] = 'secret_balance_monster';
    }

    return array_values(array_unique($keys));
}

function bober_fetch_user_achievement_override_map($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    bober_ensure_user_achievement_overrides_schema($conn);

    $stmt = $conn->prepare('SELECT achievement_key, override_mode, updated_at FROM user_achievement_overrides WHERE user_id = ?');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение override достижений пользователя.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось прочитать override достижений пользователя.');
    }

    $result = $stmt->get_result();
    $items = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $achievementKey = trim((string) ($row['achievement_key'] ?? ''));
        $mode = trim((string) ($row['override_mode'] ?? ''));
        if ($achievementKey === '' || !in_array($mode, ['grant', 'revoke'], true)) {
            continue;
        }

        $items[$achievementKey] = [
            'key' => $achievementKey,
            'mode' => $mode,
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : '',
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $items;
}

function bober_fetch_user_achievement_overrides($conn, $userId)
{
    return array_values(bober_fetch_user_achievement_override_map($conn, $userId));
}

function bober_apply_user_achievement_override_keys(array $expectedKeys, array $overrideMap)
{
    $resolvedMap = [];
    foreach ($expectedKeys as $achievementKey) {
        $achievementKey = trim((string) $achievementKey);
        if ($achievementKey !== '') {
            $resolvedMap[$achievementKey] = true;
        }
    }

    foreach ($overrideMap as $achievementKey => $override) {
        $mode = trim((string) ($override['mode'] ?? ''));
        if ($achievementKey === '' || !in_array($mode, ['grant', 'revoke'], true)) {
            continue;
        }

        if ($mode === 'grant') {
            $resolvedMap[$achievementKey] = true;
            continue;
        }

        unset($resolvedMap[$achievementKey]);
    }

    return array_values(array_keys($resolvedMap));
}

function bober_fetch_user_achievements($conn, $userId, array $options = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [];
    }

    bober_ensure_user_achievements_schema($conn);

    $includeRevoked = !empty($options['includeRevoked']);
    $sql = 'SELECT achievement_key, unlocked_at, meta_json, revoked_at FROM user_achievements WHERE user_id = ?';
    if (!$includeRevoked) {
        $sql .= ' AND revoked_at IS NULL';
    }
    $sql .= ' ORDER BY unlocked_at ASC, id ASC';

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить получение достижений.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить достижения пользователя.');
    }

    $result = $stmt->get_result();
    $items = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $decodedMeta = null;
        if (is_string($row['meta_json'] ?? null) && trim((string) $row['meta_json']) !== '') {
            $candidate = json_decode((string) $row['meta_json'], true);
            $decodedMeta = is_array($candidate) ? $candidate : null;
        }
        $items[] = [
            'key' => (string) ($row['achievement_key'] ?? ''),
            'unlockedAt' => isset($row['unlocked_at']) ? (string) $row['unlocked_at'] : '',
            'revokedAt' => isset($row['revoked_at']) ? (string) $row['revoked_at'] : '',
            'meta' => $decodedMeta,
        ];
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return $items;
}

function bober_refresh_user_achievements($conn, $userId, array $snapshot)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'items' => [],
            'newlyUnlocked' => [],
            'rewardCoins' => 0,
        ];
    }

    bober_ensure_user_achievements_schema($conn);
    bober_ensure_user_achievement_overrides_schema($conn);
    bober_ensure_achievement_catalog_schema($conn);

    $catalogMap = bober_fetch_achievement_catalog_map($conn);
    $autoExpectedKeys = array_values(array_filter(
        bober_collect_expected_achievement_keys($snapshot),
        static function ($achievementKey) use ($catalogMap) {
            return !empty($catalogMap[(string) $achievementKey]['isActive']);
        }
    ));
    $overrideMap = bober_fetch_user_achievement_override_map($conn, $userId);
    $expectedKeys = bober_apply_user_achievement_override_keys($autoExpectedKeys, $overrideMap);
    if (count($expectedKeys) < 1) {
        return [
            'items' => bober_fetch_user_achievements($conn, $userId),
            'newlyUnlocked' => [],
            'rewardCoins' => 0,
            'statsChanged' => false,
        ];
    }

    $existingStmt = $conn->prepare('SELECT achievement_key, revoked_at FROM user_achievements WHERE user_id = ?');
    if (!$existingStmt) {
        throw new RuntimeException('Не удалось подготовить чтение достижений пользователя.');
    }

    $existingStmt->bind_param('i', $userId);
    if (!$existingStmt->execute()) {
        $existingStmt->close();
        throw new RuntimeException('Не удалось прочитать достижения пользователя.');
    }

    $existingResult = $existingStmt->get_result();
    $activeExistingKeys = [];
    $revokedExistingKeys = [];
    while ($existingResult instanceof mysqli_result && ($row = $existingResult->fetch_assoc())) {
        $achievementKey = (string) ($row['achievement_key'] ?? '');
        if ($achievementKey === '') {
            continue;
        }

        if (!empty($row['revoked_at'])) {
            $revokedExistingKeys[] = $achievementKey;
            continue;
        }

        $activeExistingKeys[] = $achievementKey;
    }
    if ($existingResult instanceof mysqli_result) {
        $existingResult->free();
    }
    $existingStmt->close();

    $existingKeys = array_values(array_unique(array_merge($activeExistingKeys, $revokedExistingKeys)));
    $missingKeys = array_values(array_diff($expectedKeys, $existingKeys));
    $restoredKeys = array_values(array_intersect($expectedKeys, $revokedExistingKeys));
    $rewardCoinsTotal = 0;
    $statsChanged = false;
    $newlyUnlockedKeys = [];
    if (!empty($missingKeys)) {
        $insertStmt = $conn->prepare('INSERT IGNORE INTO user_achievements (user_id, achievement_key, meta_json, revoked_at) VALUES (?, ?, ?, NULL)');
        if (!$insertStmt) {
            throw new RuntimeException('Не удалось подготовить запись достижений пользователя.');
        }

        foreach ($missingKeys as $achievementKey) {
            $overrideMode = (string) ($overrideMap[$achievementKey]['mode'] ?? '');
            $catalogItem = is_array($catalogMap[$achievementKey] ?? null) ? $catalogMap[$achievementKey] : [];
            $rewardCoins = $overrideMode === 'grant'
                ? 0
                : max(0, (int) ($catalogItem['rewardCoins'] ?? bober_get_achievement_reward_coins($achievementKey, $conn)));
            $metaJson = json_encode(
                bober_build_achievement_meta_payload($achievementKey, $rewardCoins, $catalogItem, [
                    'manualOverride' => $overrideMode === 'grant',
                ]),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($metaJson === false) {
                $metaJson = '{"rewardCoins":0}';
            }

            $insertStmt->bind_param('iss', $userId, $achievementKey, $metaJson);
            if (!$insertStmt->execute()) {
                $insertStmt->close();
                throw new RuntimeException('Не удалось сохранить новое достижение пользователя.');
            }

            $statsChanged = true;
            if ($overrideMode !== 'grant') {
                $rewardCoinsTotal += $rewardCoins;
                $newlyUnlockedKeys[] = $achievementKey;
            }
        }

        $insertStmt->close();
    }

    if (!empty($restoredKeys)) {
        $restoreStmt = $conn->prepare('UPDATE user_achievements SET revoked_at = NULL WHERE user_id = ? AND achievement_key = ? LIMIT 1');
        if (!$restoreStmt) {
            throw new RuntimeException('Не удалось подготовить восстановление достижения пользователя.');
        }

        foreach ($restoredKeys as $achievementKey) {
            $restoreStmt->bind_param('is', $userId, $achievementKey);
            if (!$restoreStmt->execute()) {
                $restoreStmt->close();
                throw new RuntimeException('Не удалось восстановить достижение пользователя.');
            }
            $statsChanged = true;
        }

        $restoreStmt->close();
    }

    if ($rewardCoinsTotal > 0) {
        $rewardStmt = $conn->prepare('UPDATE users SET score = score + ? WHERE id = ? LIMIT 1');
        if (!$rewardStmt) {
            throw new RuntimeException('Не удалось подготовить начисление наград за достижения.');
        }

        $rewardStmt->bind_param('ii', $rewardCoinsTotal, $userId);
        if (!$rewardStmt->execute()) {
            $rewardStmt->close();
            throw new RuntimeException('Не удалось начислить награды за достижения.');
        }
        $rewardStmt->close();
    }

    if ($statsChanged) {
        bober_runtime_cache_delete($conn, 'public_achievement_stats_v1');
    }

    $items = bober_fetch_user_achievements($conn, $userId);
    $newlyUnlocked = [];
    if (!empty($newlyUnlockedKeys)) {
        foreach ($items as $item) {
            if (in_array((string) ($item['key'] ?? ''), $newlyUnlockedKeys, true)) {
                $newlyUnlocked[] = $item;
            }
        }
    }

    return [
        'items' => $items,
        'newlyUnlocked' => $newlyUnlocked,
        'rewardCoins' => $rewardCoinsTotal,
        'statsChanged' => $statsChanged,
    ];
}

function bober_collect_user_achievement_snapshot_from_database($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT
            u.score,
            u.skin,
            u.upgrade_tap_small_count,
            u.upgrade_tap_big_count,
            u.upgrade_energy_count,
            u.upgrade_tap_huge_count,
            u.upgrade_regen_boost_count,
            u.upgrade_energy_huge_count,
            u.upgrade_click_rate_count,
            COALESCE(f.best_score, 0) AS fly_best_score,
            COALESCE(f.games_played, 0) AS fly_games_played
         FROM users u
         LEFT JOIN fly_beaver_progress f ON f.user_id = u.id
         WHERE u.id = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить снимок прогресса для достижений.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось загрузить снимок прогресса для достижений.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!$row) {
        return null;
    }

    $upgradeCounts = bober_normalize_upgrade_counts([
        'tapSmall' => $row['upgrade_tap_small_count'] ?? 0,
        'tapBig' => $row['upgrade_tap_big_count'] ?? 0,
        'energy' => $row['upgrade_energy_count'] ?? 0,
        'tapHuge' => $row['upgrade_tap_huge_count'] ?? 0,
        'regenBoost' => $row['upgrade_regen_boost_count'] ?? 0,
        'energyHuge' => $row['upgrade_energy_huge_count'] ?? 0,
        'clickRate' => $row['upgrade_click_rate_count'] ?? 0,
    ]);
    $skinState = bober_decode_skin_state($row['skin'] ?? null);
    $ownedSkinIds = array_values(array_unique(array_map('strval', $skinState['ownedSkinIds'] ?? [])));

    return [
        'score' => max(0, (int) ($row['score'] ?? 0)),
        'plus' => bober_calculate_plus_from_upgrade_counts($upgradeCounts),
        'energyMax' => bober_calculate_energy_max_from_upgrade_counts($upgradeCounts),
        'ownedSkinCount' => count($ownedSkinIds),
        'clickerTop1' => in_array(bober_clicker_top_reward_skin_id(), $ownedSkinIds, true),
        'flyTop1' => in_array(bober_fly_beaver_top_reward_skin_id(), $ownedSkinIds, true),
        'flyBeaver' => [
            'bestScore' => max(0, (int) ($row['fly_best_score'] ?? 0)),
            'gamesPlayed' => max(0, (int) ($row['fly_games_played'] ?? 0)),
        ],
        'flyGamesPlayed' => max(0, (int) ($row['fly_games_played'] ?? 0)),
        'totalUpgradePurchases' => array_sum($upgradeCounts),
        'upgradeCounts' => $upgradeCounts,
    ];
}

function bober_peek_user_achievement_state($conn, $userId)
{
    $achievementStatsBundle = bober_fetch_achievement_stats($conn);
    $achievementStats = is_array($achievementStatsBundle['items'] ?? null) ? $achievementStatsBundle['items'] : [];
    $achievementPlayerBase = max(0, (int) ($achievementStatsBundle['totalPlayers'] ?? 0));
    $catalogMap = bober_fetch_achievement_catalog_map($conn);

    return [
        'items' => bober_enrich_achievement_items(
            bober_fetch_user_achievements($conn, $userId),
            $achievementStats,
            $achievementPlayerBase,
            $catalogMap
        ),
        'overrides' => bober_fetch_user_achievement_overrides($conn, $userId),
        'stats' => $achievementStats,
        'playerBase' => $achievementPlayerBase,
    ];
}

function bober_set_user_achievement_state($conn, $userId, $achievementKey, $mode)
{
    $userId = max(0, (int) $userId);
    $achievementKey = trim((string) $achievementKey);
    $mode = trim((string) $mode);

    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный пользователь для изменения достижения.');
    }
    if (!in_array($mode, ['grant', 'revoke', 'auto'], true)) {
        throw new InvalidArgumentException('Некорректный режим изменения достижения.');
    }
    if ($achievementKey === '' || !array_key_exists($achievementKey, bober_get_default_achievement_reward_map())) {
        throw new InvalidArgumentException('Некорректный ключ достижения.');
    }

    bober_ensure_user_achievement_overrides_schema($conn);
    bober_ensure_user_achievements_schema($conn);
    $catalogItem = bober_fetch_achievement_catalog_item($conn, $achievementKey);

    if ($mode === 'auto') {
        $clearStmt = $conn->prepare('DELETE FROM user_achievement_overrides WHERE user_id = ? AND achievement_key = ? LIMIT 1');
        if (!$clearStmt) {
            throw new RuntimeException('Не удалось подготовить сброс override достижения.');
        }
        $clearStmt->bind_param('is', $userId, $achievementKey);
        if (!$clearStmt->execute()) {
            $clearStmt->close();
            throw new RuntimeException('Не удалось сбросить override достижения.');
        }
        $clearStmt->close();

        $snapshot = bober_collect_user_achievement_snapshot_from_database($conn, $userId);
        if ($snapshot !== null) {
            $catalogMap = bober_fetch_achievement_catalog_map($conn);
            $autoExpectedKeys = array_values(array_filter(
                bober_collect_expected_achievement_keys($snapshot),
                static function ($expectedKey) use ($catalogMap) {
                    return !empty($catalogMap[(string) $expectedKey]['isActive']);
                }
            ));

            if (!in_array($achievementKey, $autoExpectedKeys, true)) {
                $metaStmt = $conn->prepare('SELECT meta_json FROM user_achievements WHERE user_id = ? AND achievement_key = ? AND revoked_at IS NULL LIMIT 1');
                if ($metaStmt) {
                    $metaStmt->bind_param('is', $userId, $achievementKey);
                    if ($metaStmt->execute()) {
                        $result = $metaStmt->get_result();
                        $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
                        if ($result instanceof mysqli_result) {
                            $result->free();
                        }

                        $decodedMeta = [];
                        if (is_array($row) && trim((string) ($row['meta_json'] ?? '')) !== '') {
                            $decodedMeta = json_decode((string) ($row['meta_json'] ?? ''), true);
                            if (!is_array($decodedMeta)) {
                                $decodedMeta = [];
                            }
                        }

                        if (!empty($decodedMeta['manualOverride']) || !empty($decodedMeta['manual_override'])) {
                            $revokeManualStmt = $conn->prepare('UPDATE user_achievements SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND achievement_key = ? AND revoked_at IS NULL LIMIT 1');
                            if ($revokeManualStmt) {
                                $revokeManualStmt->bind_param('is', $userId, $achievementKey);
                                $revokeManualStmt->execute();
                                $revokeManualStmt->close();
                            }
                        }
                    }
                    $metaStmt->close();
                }
            }
        }
    } else {
        $overrideStmt = $conn->prepare(
            'INSERT INTO user_achievement_overrides (user_id, achievement_key, override_mode)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE override_mode = VALUES(override_mode)'
        );
        if (!$overrideStmt) {
            throw new RuntimeException('Не удалось подготовить сохранение override достижения.');
        }
        $overrideStmt->bind_param('iss', $userId, $achievementKey, $mode);
        if (!$overrideStmt->execute()) {
            $overrideStmt->close();
            throw new RuntimeException('Не удалось сохранить override достижения.');
        }
        $overrideStmt->close();
    }

    if ($mode === 'grant') {
        $metaJson = json_encode(
            bober_build_achievement_meta_payload($achievementKey, 0, is_array($catalogItem) ? $catalogItem : [], [
                'manualOverride' => true,
            ]),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($metaJson === false) {
            $metaJson = '{"rewardCoins":0}';
        }

        $grantStmt = $conn->prepare(
            'INSERT INTO user_achievements (user_id, achievement_key, meta_json, revoked_at)
             VALUES (?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE
                meta_json = VALUES(meta_json),
                revoked_at = NULL'
        );
        if (!$grantStmt) {
            throw new RuntimeException('Не удалось подготовить ручную выдачу достижения.');
        }
        $grantStmt->bind_param('iss', $userId, $achievementKey, $metaJson);
        if (!$grantStmt->execute()) {
            $grantStmt->close();
            throw new RuntimeException('Не удалось вручную выдать достижение.');
        }
        $grantStmt->close();
    } elseif ($mode === 'revoke') {
        $revokeStmt = $conn->prepare('UPDATE user_achievements SET revoked_at = CURRENT_TIMESTAMP WHERE user_id = ? AND achievement_key = ? LIMIT 1');
        if (!$revokeStmt) {
            throw new RuntimeException('Не удалось подготовить ручное снятие достижения.');
        }
        $revokeStmt->bind_param('is', $userId, $achievementKey);
        if (!$revokeStmt->execute()) {
            $revokeStmt->close();
            throw new RuntimeException('Не удалось вручную снять достижение.');
        }
        $revokeStmt->close();
    }

    $snapshot = bober_collect_user_achievement_snapshot_from_database($conn, $userId);
    if ($snapshot !== null) {
        bober_refresh_user_achievements($conn, $userId, $snapshot);
    }

    bober_runtime_cache_delete($conn, 'public_achievement_stats_v1');
    return bober_peek_user_achievement_state($conn, $userId);
}

function bober_fetch_public_leaderboard($conn, $limit = 3)
{
    $limit = max(1, min(25, (int) $limit));
    $cacheKey = 'leaderboard_main_' . $limit;
    $cachedLeaders = bober_file_cache_fetch($cacheKey, 20);
    if (is_array($cachedLeaders)) {
        return $cachedLeaders;
    }

    $sql = <<<SQL
SELECT u.id, u.login, u.score
FROM users u
LEFT JOIN user_bans b
    ON b.user_id = u.id
    AND b.lifted_at IS NULL
    AND b.ban_until > CURRENT_TIMESTAMP
WHERE u.login IS NOT NULL
    AND u.login <> ''
    AND LOWER(TRIM(u.login)) <> 'test'
    AND b.id IS NULL
ORDER BY u.score DESC, u.id ASC
LIMIT {$limit}
SQL;

    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('Ошибка выполнения запроса таблицы лидеров.');
    }

    $leaders = [];
    while ($row = $result->fetch_assoc()) {
        $leaders[] = [
            'userId' => max(0, (int) ($row['id'] ?? 0)),
            'login' => (string) ($row['login'] ?? ''),
            'score' => max(0, (int) ($row['score'] ?? 0)),
        ];
    }
    $result->free();

    bober_file_cache_store($cacheKey, $leaders);
    return $leaders;
}

function bober_fetch_public_clicker_top_user_id($conn)
{
    $sql = <<<SQL
SELECT u.id
FROM users u
LEFT JOIN user_bans b
    ON b.user_id = u.id
    AND b.lifted_at IS NULL
    AND b.ban_until > CURRENT_TIMESTAMP
WHERE u.login IS NOT NULL
    AND u.login <> ''
    AND LOWER(TRIM(u.login)) <> 'test'
    AND b.id IS NULL
ORDER BY u.score DESC, u.id ASC
LIMIT 1
SQL;

    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('Ошибка определения топ-1 игрока кликера.');
    }

    $row = $result->fetch_assoc();
    $result->free();

    if (!is_array($row)) {
        return null;
    }

    $userId = max(0, (int) ($row['id'] ?? 0));
    return $userId > 0 ? $userId : null;
}

function bober_reconcile_clicker_top_reward_skin($conn)
{
    $skinId = bober_clicker_top_reward_skin_id();
    $topUserId = bober_fetch_public_clicker_top_user_id($conn);
    $likePattern = '%' . $skinId . '%';

    if ($topUserId !== null) {
        $stmt = $conn->prepare('SELECT `id`, `skin` FROM `users` WHERE `id` = ? OR `skin` LIKE ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить пересчет топового скина.');
        }

        $stmt->bind_param('is', $topUserId, $likePattern);
    } else {
        $stmt = $conn->prepare('SELECT `id`, `skin` FROM `users` WHERE `skin` LIKE ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить очистку топового скина.');
        }

        $stmt->bind_param('s', $likePattern);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить текущих владельцев топового скина.');
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (empty($rows)) {
        return;
    }

    $defaultEquippedSkinId = (string) (bober_default_skin_state()['equippedSkinId'] ?? 'classic');
    $updateStmt = $conn->prepare('UPDATE `users` SET `skin` = ? WHERE `id` = ? LIMIT 1');
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось подготовить сохранение топового скина.');
    }

    foreach ($rows as $row) {
        $userId = max(0, (int) ($row['id'] ?? 0));
        if ($userId < 1) {
            continue;
        }

        $skinState = bober_decode_skin_state($row['skin'] ?? '');
        $ownedSkinIds = array_values(array_unique(array_map('strval', $skinState['ownedSkinIds'] ?? [])));
        $hasTopSkin = in_array($skinId, $ownedSkinIds, true);
        $shouldOwnTopSkin = $topUserId !== null && $userId === $topUserId;
        $changed = false;

        if ($shouldOwnTopSkin && !$hasTopSkin) {
            $ownedSkinIds[] = $skinId;
            $changed = true;
        } elseif (!$shouldOwnTopSkin && $hasTopSkin) {
            $ownedSkinIds = array_values(array_filter($ownedSkinIds, static function ($ownedSkinId) use ($skinId) {
                return $ownedSkinId !== $skinId;
            }));
            if (($skinState['equippedSkinId'] ?? '') === $skinId) {
                $skinState['equippedSkinId'] = $defaultEquippedSkinId;
            }
            $changed = true;
        }

        if (!$changed) {
            continue;
        }

        $skinState['ownedSkinIds'] = $ownedSkinIds;
        $encodedSkin = bober_encode_skin_state($skinState);
        $updateStmt->bind_param('si', $encodedSkin, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new RuntimeException('Не удалось обновить владельца топового скина.');
        }
    }

    $updateStmt->close();
}

function bober_fetch_public_fly_beaver_top_user_id($conn)
{
    $sql = <<<SQL
SELECT u.id
FROM users u
INNER JOIN fly_beaver_progress f
    ON f.user_id = u.id
LEFT JOIN user_bans b
    ON b.user_id = u.id
    AND b.lifted_at IS NULL
    AND b.ban_until > CURRENT_TIMESTAMP
WHERE u.login IS NOT NULL
    AND u.login <> ''
    AND LOWER(TRIM(u.login)) <> 'test'
    AND b.id IS NULL
ORDER BY f.best_score DESC, u.score DESC, u.id ASC
LIMIT 1
SQL;

    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('Ошибка определения топ-1 игрока Летающего бобра.');
    }

    $row = $result->fetch_assoc();
    $result->free();

    if (!is_array($row)) {
        return null;
    }

    $userId = max(0, (int) ($row['id'] ?? 0));
    return $userId > 0 ? $userId : null;
}

function bober_fetch_public_fly_beaver_leaderboard($conn, $limit = 3)
{
    $limit = max(1, min(25, (int) $limit));
    $cacheKey = 'leaderboard_fly_' . $limit;
    $cachedLeaders = bober_file_cache_fetch($cacheKey, 20);
    if (is_array($cachedLeaders)) {
        return $cachedLeaders;
    }

    $sql = <<<SQL
SELECT u.id, u.login, MAX(f.best_score) AS score
FROM users u
INNER JOIN fly_beaver_progress f
    ON f.user_id = u.id
LEFT JOIN user_bans b
    ON b.user_id = u.id
    AND b.lifted_at IS NULL
    AND b.ban_until > CURRENT_TIMESTAMP
WHERE u.login IS NOT NULL
    AND u.login <> ''
    AND LOWER(TRIM(u.login)) <> 'test'
    AND b.id IS NULL
GROUP BY u.id, u.login
ORDER BY score DESC
LIMIT {$limit}
SQL;

    $result = $conn->query($sql);
    if ($result === false) {
        throw new RuntimeException('Ошибка выполнения запроса таблицы лидеров Летающего бобра.');
    }

    $leaders = [];
    while ($row = $result->fetch_assoc()) {
        $leaders[] = [
            'userId' => max(0, (int) ($row['id'] ?? 0)),
            'login' => (string) ($row['login'] ?? ''),
            'score' => max(0, (int) ($row['score'] ?? 0)),
        ];
    }
    $result->free();

    bober_file_cache_store($cacheKey, $leaders);
    return $leaders;
}

function bober_build_public_skin_payload($skinConfig, $isEquipped = false)
{
    if (!is_array($skinConfig)) {
        return null;
    }

    return [
        'id' => trim((string) ($skinConfig['id'] ?? '')),
        'name' => trim((string) ($skinConfig['name'] ?? '')),
        'image' => trim((string) ($skinConfig['image'] ?? '')),
        'rarity' => bober_normalize_skin_rarity($skinConfig['rarity'] ?? ''),
        'category' => bober_normalize_skin_category($skinConfig['category'] ?? ''),
        'issueMode' => bober_normalize_skin_issue_mode($skinConfig['issue_mode'] ?? ($skinConfig['issueMode'] ?? ''), $skinConfig),
        'equipped' => (bool) $isEquipped,
    ];
}

function bober_build_owned_skin_summary(array $catalog, array $ownedSkinIds)
{
    $summary = [
        'rareSkins' => 0,
        'epicSkins' => 0,
        'legendarySkins' => 0,
        'grantOnlySkins' => 0,
        'topRewardSkins' => 0,
    ];

    foreach ($ownedSkinIds as $ownedSkinId) {
        $skinConfig = is_array($catalog[$ownedSkinId] ?? null) ? $catalog[$ownedSkinId] : null;
        if (!$skinConfig) {
            continue;
        }

        $rarity = bober_normalize_skin_rarity($skinConfig['rarity'] ?? '');
        if (in_array($rarity, ['rare', 'epic', 'legendary', 'admin'], true)) {
            $summary['rareSkins']++;
        }
        if (in_array($rarity, ['epic', 'legendary', 'admin'], true)) {
            $summary['epicSkins']++;
        }
        if (in_array($rarity, ['legendary', 'admin'], true)) {
            $summary['legendarySkins']++;
        }

        $issueMode = bober_normalize_skin_issue_mode($skinConfig['issue_mode'] ?? ($skinConfig['issueMode'] ?? ''), $skinConfig);
        if ($issueMode === 'grant_only') {
            $summary['grantOnlySkins']++;
        }

        $category = bober_normalize_skin_category($skinConfig['category'] ?? '');
        if ($category === 'top') {
            $summary['topRewardSkins']++;
        }
    }

    return $summary;
}

function bober_build_profile_honors(array $context)
{
    $score = max(0, (int) ($context['score'] ?? 0));
    $flyBest = max(0, (int) ($context['flyBest'] ?? 0));
    $achievementsUnlocked = max(0, (int) ($context['achievementsUnlocked'] ?? 0));
    $ownedSkins = max(0, (int) ($context['ownedSkins'] ?? 0));
    $legendarySkins = max(0, (int) ($context['legendarySkins'] ?? 0));
    $grantOnlySkins = max(0, (int) ($context['grantOnlySkins'] ?? 0));
    $clickerTop1 = !empty($context['clickerTop1']);
    $flyTop1 = !empty($context['flyTop1']);

    $title = 'Начинающий бобр';
    if ($clickerTop1 && $flyTop1) {
        $title = 'Легенда болот';
    } elseif ($clickerTop1) {
        $title = 'Король кликов';
    } elseif ($flyTop1) {
        $title = 'Ас полётов';
    } elseif ($achievementsUnlocked >= 80) {
        $title = 'Архивариус достижений';
    } elseif ($legendarySkins >= 5) {
        $title = 'Хранитель витрины';
    } elseif ($score >= 1000000000) {
        $title = 'Миллиардный бобр';
    } elseif ($flyBest >= 500) {
        $title = 'Покоритель высот';
    } elseif ($achievementsUnlocked >= 40) {
        $title = 'Охотник за достижениями';
    } elseif ($ownedSkins >= 25) {
        $title = 'Коллекционер';
    } elseif ($score >= 10000000) {
        $title = 'Сильный аккаунт';
    }

    $frame = 'common';
    if ($clickerTop1 || $flyTop1 || $achievementsUnlocked >= 80 || $score >= 1000000000) {
        $frame = 'legend';
    } elseif ($achievementsUnlocked >= 40 || $ownedSkins >= 25 || $flyBest >= 250 || $score >= 50000000) {
        $frame = 'epic';
    } elseif ($achievementsUnlocked >= 15 || $ownedSkins >= 10 || $score >= 1000000 || $flyBest >= 100) {
        $frame = 'rare';
    }

    $frameLabelMap = [
        'common' => 'Базовая рамка',
        'rare' => 'Редкая рамка',
        'epic' => 'Эпическая рамка',
        'legend' => 'Легендарная рамка',
    ];

    $trophies = [];
    if ($clickerTop1 && $flyTop1) {
        $trophies[] = [
            'icon' => '🏛️',
            'label' => 'Двойной лидер',
            'description' => 'Игрок удерживал топ-1 сразу в двух режимах.',
        ];
    } else {
        if ($clickerTop1) {
            $trophies[] = [
                'icon' => '👑',
                'label' => 'Топ-1 кликера',
                'description' => 'Сейчас владеет главным трофеем кликера.',
            ];
        }
        if ($flyTop1) {
            $trophies[] = [
                'icon' => '🏆',
                'label' => 'Топ-1 fly-beaver',
                'description' => 'Сейчас владеет главным трофеем fly-beaver.',
            ];
        }
    }
    if ($achievementsUnlocked >= 60) {
        $trophies[] = [
            'icon' => '✨',
            'label' => 'Коллекция достижений',
            'description' => 'Открыл большой пласт достижений.',
        ];
    }
    if ($legendarySkins >= 3) {
        $trophies[] = [
            'icon' => '🎨',
            'label' => 'Редкая витрина',
            'description' => 'Собрал заметную коллекцию редких скинов.',
        ];
    }
    if ($grantOnlySkins >= 2) {
        $trophies[] = [
            'icon' => '🔐',
            'label' => 'Особая выдача',
            'description' => 'Имеет несколько эксклюзивных скинов.',
        ];
    }
    if ($flyBest >= 500) {
        $trophies[] = [
            'icon' => '🪽',
            'label' => 'Предел воздуха',
            'description' => 'Показал высокий рекорд в fly-beaver.',
        ];
    }
    if ($score >= 1000000000) {
        $trophies[] = [
            'icon' => '💰',
            'label' => 'Миллиардный счет',
            'description' => 'Преодолел рубеж в миллиард коинов.',
        ];
    }

    return [
        'title' => $title,
        'frame' => $frame,
        'frameLabel' => (string) ($frameLabelMap[$frame] ?? $frameLabelMap['common']),
        'trophies' => array_slice($trophies, 0, 4),
    ];
}

function bober_fetch_public_player_profile($conn, $userId)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор игрока.');
    }

    bober_reconcile_top_reward_skins($conn);

    $stmt = $conn->prepare(
        'SELECT u.id, u.login, u.plus, u.skin, u.ENERGY_MAX, u.score,
                u.upgrade_tap_small_count, u.upgrade_tap_big_count, u.upgrade_energy_count,
                u.upgrade_tap_huge_count, u.upgrade_regen_boost_count, u.upgrade_energy_huge_count,
                u.upgrade_click_rate_count,
                COALESCE(f.best_score, 0) AS fly_best,
                COALESCE(f.games_played, 0) AS fly_games_played,
                GREATEST(
                    COALESCE(u.updated_at, \'1970-01-01 00:00:00\'),
                    COALESCE(f.last_played_at, \'1970-01-01 00:00:00\'),
                    COALESCE((
                        SELECT MAX(iph.last_seen_at)
                        FROM user_ip_history iph
                        WHERE iph.user_id = u.id
                    ), \'1970-01-01 00:00:00\')
                ) AS last_activity_at
         FROM users u
         LEFT JOIN fly_beaver_progress f ON f.user_id = u.id
         LEFT JOIN user_bans b
            ON b.user_id = u.id
            AND b.lifted_at IS NULL
            AND b.ban_until > CURRENT_TIMESTAMP
         WHERE u.id = ?
            AND u.login IS NOT NULL
            AND u.login <> \'\'
            AND LOWER(TRIM(u.login)) <> \'test\'
            AND b.id IS NULL
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить загрузку публичного профиля игрока.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось загрузить публичный профиль игрока.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Игрок не найден в публичной таблице лидеров.');
    }

    if (!bober_is_user_profile_public($conn, $userId)) {
        throw new RuntimeException('Игрок скрыл свой профиль.');
    }

    $upgradeCounts = bober_normalize_upgrade_counts([
        'tapSmall' => $row['upgrade_tap_small_count'] ?? 0,
        'tapBig' => $row['upgrade_tap_big_count'] ?? 0,
        'energy' => $row['upgrade_energy_count'] ?? 0,
        'tapHuge' => $row['upgrade_tap_huge_count'] ?? 0,
        'regenBoost' => $row['upgrade_regen_boost_count'] ?? 0,
        'energyHuge' => $row['upgrade_energy_huge_count'] ?? 0,
        'clickRate' => $row['upgrade_click_rate_count'] ?? 0,
    ]);
    $resolvedPlus = bober_calculate_plus_from_upgrade_counts($upgradeCounts);
    $resolvedEnergyMax = bober_calculate_energy_max_from_upgrade_counts($upgradeCounts);
    $skinState = bober_decode_skin_state($row['skin'] ?? null);
    $catalog = bober_skin_catalog();
    $ownedSkinIds = array_values(array_unique(array_map('strval', $skinState['ownedSkinIds'] ?? [])));
    $equippedSkinId = trim((string) ($skinState['equippedSkinId'] ?? ''));
    if ($equippedSkinId === '' || !isset($catalog[$equippedSkinId])) {
        $equippedSkinId = (string) (bober_default_skin_state()['equippedSkinId'] ?? 'classic');
    }

    $equippedSkin = bober_build_public_skin_payload($catalog[$equippedSkinId] ?? null, true);
    $collectionSkins = [];
    foreach ($ownedSkinIds as $ownedSkinId) {
        $skinConfig = is_array($catalog[$ownedSkinId] ?? null) ? $catalog[$ownedSkinId] : null;
        if (!$skinConfig) {
            continue;
        }

        $payload = bober_build_public_skin_payload($skinConfig, $ownedSkinId === $equippedSkinId);
        if ($payload !== null) {
            $collectionSkins[] = $payload;
        }
    }
    $skinSummary = bober_build_owned_skin_summary($catalog, $ownedSkinIds);

    $achievementStatsBundle = bober_fetch_achievement_stats($conn);
    $achievementStats = is_array($achievementStatsBundle['items'] ?? null) ? $achievementStatsBundle['items'] : [];
    $achievementPlayerBase = max(0, (int) ($achievementStatsBundle['totalPlayers'] ?? 0));
    $achievementCatalogMap = bober_fetch_achievement_catalog_map($conn);
    $achievementItems = bober_enrich_achievement_items(
        bober_fetch_user_achievements($conn, $userId),
        $achievementStats,
        $achievementPlayerBase,
        $achievementCatalogMap
    );
    $clickerTop1 = in_array(bober_clicker_top_reward_skin_id(), $ownedSkinIds, true);
    $flyTop1 = in_array(bober_fly_beaver_top_reward_skin_id(), $ownedSkinIds, true);
    $economyProfile = bober_build_user_economy_profile([
        'score' => max(0, (int) ($row['score'] ?? 0)),
        'plus' => $resolvedPlus,
        'energyMax' => $resolvedEnergyMax,
        'flyBest' => max(0, (int) ($row['fly_best'] ?? 0)),
        'ownedSkinIds' => $ownedSkinIds,
        'upgradeCounts' => $upgradeCounts,
        'catalog' => $catalog,
    ]);
    $honors = bober_build_profile_honors([
        'score' => max(0, (int) ($row['score'] ?? 0)),
        'flyBest' => max(0, (int) ($row['fly_best'] ?? 0)),
        'achievementsUnlocked' => count($achievementItems),
        'ownedSkins' => count($ownedSkinIds),
        'legendarySkins' => $skinSummary['legendarySkins'],
        'grantOnlySkins' => $skinSummary['grantOnlySkins'],
        'clickerTop1' => $clickerTop1,
        'flyTop1' => $flyTop1,
    ]);
    $recentActivity = bober_fetch_user_activity_feed($conn, $userId, [
        'limit' => 8,
        'public_only' => true,
    ]);

    return [
        'userId' => max(0, (int) ($row['id'] ?? $userId)),
        'login' => (string) ($row['login'] ?? ''),
        'profile' => [
            'score' => max(0, (int) ($row['score'] ?? 0)),
            'plus' => $resolvedPlus,
            'energyMax' => $resolvedEnergyMax,
            'lastActivityAt' => isset($row['last_activity_at']) ? (string) $row['last_activity_at'] : null,
            'flyBest' => max(0, (int) ($row['fly_best'] ?? 0)),
            'flyGamesPlayed' => max(0, (int) ($row['fly_games_played'] ?? 0)),
            'ownedSkins' => count($ownedSkinIds),
            'collectionSkins' => count($collectionSkins),
            'achievementsUnlocked' => count($achievementItems),
            'rareSkins' => $skinSummary['rareSkins'],
            'epicSkins' => $skinSummary['epicSkins'],
            'legendarySkins' => $skinSummary['legendarySkins'],
            'grantOnlySkins' => $skinSummary['grantOnlySkins'],
            'topRewardSkins' => $skinSummary['topRewardSkins'],
            'clickerTop1' => $clickerTop1,
            'flyTop1' => $flyTop1,
            'economy' => $economyProfile,
            'honors' => $honors,
        ],
        'skins' => [
            'equipped' => $equippedSkin,
            'collection' => $collectionSkins,
        ],
        'achievements' => $achievementItems,
        'recentActivity' => $recentActivity,
    ];
}

function bober_fetch_public_player_profile_by_login($conn, $login)
{
    $login = trim((string) $login);
    if ($login === '') {
        throw new InvalidArgumentException('Некорректный логин игрока.');
    }

    $stmt = $conn->prepare(
        'SELECT u.id
         FROM users u
         LEFT JOIN user_bans b
            ON b.user_id = u.id
            AND b.lifted_at IS NULL
            AND b.ban_until > CURRENT_TIMESTAMP
         WHERE LOWER(TRIM(u.login)) = LOWER(TRIM(?))
            AND u.login IS NOT NULL
            AND u.login <> \'\'
            AND LOWER(TRIM(u.login)) <> \'test\'
            AND b.id IS NULL
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить поиск публичного профиля игрока.');
    }

    $stmt->bind_param('s', $login);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось найти публичный профиль игрока.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    $userId = max(0, (int) ($row['id'] ?? 0));
    if ($userId < 1) {
        throw new RuntimeException('Игрок не найден в публичной таблице лидеров.');
    }

    return bober_fetch_public_player_profile($conn, $userId);
}

function bober_reconcile_fly_beaver_top_reward_skin($conn)
{
    $skinId = bober_fly_beaver_top_reward_skin_id();
    $topUserId = bober_fetch_public_fly_beaver_top_user_id($conn);
    $likePattern = '%' . $skinId . '%';

    if ($topUserId !== null) {
        $stmt = $conn->prepare('SELECT `id`, `skin` FROM `users` WHERE `id` = ? OR `skin` LIKE ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить пересчет fly-beaver top skin.');
        }

        $stmt->bind_param('is', $topUserId, $likePattern);
    } else {
        $stmt = $conn->prepare('SELECT `id`, `skin` FROM `users` WHERE `skin` LIKE ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить очистку fly-beaver top skin.');
        }

        $stmt->bind_param('s', $likePattern);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось получить текущих владельцев fly-beaver top skin.');
    }

    $result = $stmt->get_result();
    $rows = [];
    while ($result instanceof mysqli_result && ($row = $result->fetch_assoc())) {
        $rows[] = $row;
    }
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (empty($rows)) {
        return;
    }

    $defaultEquippedSkinId = (string) (bober_default_skin_state()['equippedSkinId'] ?? 'classic');
    $updateStmt = $conn->prepare('UPDATE `users` SET `skin` = ? WHERE `id` = ? LIMIT 1');
    if (!$updateStmt) {
        throw new RuntimeException('Не удалось подготовить сохранение fly-beaver top skin.');
    }

    foreach ($rows as $row) {
        $userId = max(0, (int) ($row['id'] ?? 0));
        if ($userId < 1) {
            continue;
        }

        $skinState = bober_decode_skin_state($row['skin'] ?? '');
        $ownedSkinIds = array_values(array_unique(array_map('strval', $skinState['ownedSkinIds'] ?? [])));
        $hasTopSkin = in_array($skinId, $ownedSkinIds, true);
        $shouldOwnTopSkin = $topUserId !== null && $userId === $topUserId;
        $changed = false;

        if ($shouldOwnTopSkin && !$hasTopSkin) {
            $ownedSkinIds[] = $skinId;
            $changed = true;
        } elseif (!$shouldOwnTopSkin && $hasTopSkin) {
            $ownedSkinIds = array_values(array_filter($ownedSkinIds, static function ($ownedSkinId) use ($skinId) {
                return $ownedSkinId !== $skinId;
            }));
            if (($skinState['equippedSkinId'] ?? '') === $skinId) {
                $skinState['equippedSkinId'] = $defaultEquippedSkinId;
            }
            $changed = true;
        }

        if (!$changed) {
            continue;
        }

        $skinState['ownedSkinIds'] = $ownedSkinIds;
        $encodedSkin = bober_encode_skin_state($skinState);
        $updateStmt->bind_param('si', $encodedSkin, $userId);
        if (!$updateStmt->execute()) {
            $updateStmt->close();
            throw new RuntimeException('Не удалось обновить владельца fly-beaver top skin.');
        }
    }

    $updateStmt->close();
}

function bober_reconcile_top_reward_skins($conn)
{
    static $reconciledThisRequest = false;

    if ($reconciledThisRequest) {
        return;
    }

    if (bober_schema_guard_is_fresh('top_reward_skins', 90)) {
        $reconciledThisRequest = true;
        return;
    }

    bober_reconcile_clicker_top_reward_skin($conn);
    bober_reconcile_fly_beaver_top_reward_skin($conn);
    bober_schema_guard_touch('top_reward_skins');
    $reconciledThisRequest = true;
}

function bober_app_timezone()
{
    static $timezone = null;
    if (!$timezone instanceof DateTimeZone) {
        $timezone = new DateTimeZone('Asia/Omsk');
    }

    return $timezone;
}

function bober_get_quest_period_keys()
{
    $now = new DateTimeImmutable('now', bober_app_timezone());

    return [
        'daily' => $now->format('Y-m-d'),
        'weekly' => $now->format('o-\WW'),
    ];
}

function bober_default_quest_counters()
{
    $counters = [];
    foreach (array_keys(bober_get_quest_metric_definitions()) as $metricKey) {
        $counters[$metricKey] = 0;
    }

    return $counters;
}

function bober_decode_json_map($rawValue, array $fallback = [])
{
    if (!is_string($rawValue) || trim($rawValue) === '') {
        return $fallback;
    }

    $decoded = json_decode($rawValue, true);
    return is_array($decoded) ? $decoded : $fallback;
}

function bober_encode_json_map(array $payload)
{
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '{}' : $encoded;
}

function bober_decode_json_list($rawValue, array $fallback = [])
{
    if (!is_string($rawValue) || trim($rawValue) === '') {
        return $fallback;
    }

    $decoded = json_decode($rawValue, true);
    return is_array($decoded) ? array_values($decoded) : $fallback;
}

function bober_encode_json_list(array $payload)
{
    $encoded = json_encode(array_values($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $encoded === false ? '[]' : $encoded;
}

function bober_default_quest_manual_keys($count = 2)
{
    return array_fill(0, max(0, (int) $count), '');
}

function bober_normalize_quest_manual_keys($rawKeys, $count = 2)
{
    $normalized = bober_default_quest_manual_keys($count);
    if (!is_array($rawKeys)) {
        return $normalized;
    }

    for ($index = 0; $index < $count; $index++) {
        $normalized[$index] = trim((string) ($rawKeys[$index] ?? ''));
    }

    return $normalized;
}

function bober_get_daily_quest_definitions()
{
    return [
        'daily_score_15k' => [
            'title' => 'Разогрев дня',
            'description' => 'Заработайте 15 000 коинов за день.',
            'metric' => 'scoreGain',
            'goal' => 15000,
            'rewardCoins' => 3500,
        ],
        'daily_score_50k' => [
            'title' => 'Ускорение',
            'description' => 'Заработайте 50 000 коинов за день.',
            'metric' => 'scoreGain',
            'goal' => 50000,
            'rewardCoins' => 9000,
        ],
        'daily_upgrades_4' => [
            'title' => 'Тонкая настройка',
            'description' => 'Купите 4 улучшения за день.',
            'metric' => 'upgradeBuys',
            'goal' => 4,
            'rewardCoins' => 3000,
        ],
        'daily_upgrades_10' => [
            'title' => 'Конструктор',
            'description' => 'Купите 10 улучшений за день.',
            'metric' => 'upgradeBuys',
            'goal' => 10,
            'rewardCoins' => 8000,
        ],
        'daily_plus_20' => [
            'title' => 'Новый темп',
            'description' => 'Увеличьте силу клика на 20 за день.',
            'metric' => 'plusGain',
            'goal' => 20,
            'rewardCoins' => 2500,
        ],
        'daily_plus_60' => [
            'title' => 'Серьезный буст',
            'description' => 'Увеличьте силу клика на 60 за день.',
            'metric' => 'plusGain',
            'goal' => 60,
            'rewardCoins' => 7000,
        ],
        'daily_energy_2000' => [
            'title' => 'Запас на день',
            'description' => 'Увеличьте максимальную энергию на 2 000 за день.',
            'metric' => 'energyMaxGain',
            'goal' => 2000,
            'rewardCoins' => 4500,
        ],
        'daily_fly_runs_3' => [
            'title' => 'Три попытки',
            'description' => 'Сыграйте 3 забега в Летающего бобра за день.',
            'metric' => 'flyRuns',
            'goal' => 3,
            'rewardCoins' => 5000,
        ],
        'daily_fly_score_120' => [
            'title' => 'Полет по плану',
            'description' => 'Наберите 120 очков в Летающем бобре за день.',
            'metric' => 'flyScoreGain',
            'goal' => 120,
            'rewardCoins' => 6500,
        ],
    ];
}

function bober_get_weekly_quest_definitions()
{
    return [
        'weekly_score_250k' => [
            'title' => 'Большой рывок',
            'description' => 'Заработайте 250 000 коинов за неделю.',
            'metric' => 'scoreGain',
            'goal' => 250000,
            'rewardCoins' => 45000,
        ],
        'weekly_score_1m' => [
            'title' => 'Неделя без паузы',
            'description' => 'Заработайте 1 000 000 коинов за неделю.',
            'metric' => 'scoreGain',
            'goal' => 1000000,
            'rewardCoins' => 130000,
        ],
        'weekly_upgrades_30' => [
            'title' => 'Мастер апгрейдов',
            'description' => 'Купите 30 улучшений за неделю.',
            'metric' => 'upgradeBuys',
            'goal' => 30,
            'rewardCoins' => 40000,
        ],
        'weekly_upgrades_75' => [
            'title' => 'Инженер недели',
            'description' => 'Купите 75 улучшений за неделю.',
            'metric' => 'upgradeBuys',
            'goal' => 75,
            'rewardCoins' => 100000,
        ],
        'weekly_plus_150' => [
            'title' => 'Стабильный рост',
            'description' => 'Увеличьте силу клика на 150 за неделю.',
            'metric' => 'plusGain',
            'goal' => 150,
            'rewardCoins' => 35000,
        ],
        'weekly_plus_400' => [
            'title' => 'Тяжелая артиллерия',
            'description' => 'Увеличьте силу клика на 400 за неделю.',
            'metric' => 'plusGain',
            'goal' => 400,
            'rewardCoins' => 90000,
        ],
        'weekly_energy_12000' => [
            'title' => 'Энергоцех',
            'description' => 'Увеличьте максимальную энергию на 12 000 за неделю.',
            'metric' => 'energyMaxGain',
            'goal' => 12000,
            'rewardCoins' => 50000,
        ],
        'weekly_fly_runs_18' => [
            'title' => 'Небесная рутина',
            'description' => 'Сыграйте 18 забегов в Летающего бобра за неделю.',
            'metric' => 'flyRuns',
            'goal' => 18,
            'rewardCoins' => 48000,
        ],
        'weekly_fly_score_900' => [
            'title' => 'Крылатая неделя',
            'description' => 'Наберите 900 очков в Летающем бобре за неделю.',
            'metric' => 'flyScoreGain',
            'goal' => 900,
            'rewardCoins' => 70000,
        ],
    ];
}

function bober_get_quest_metric_definitions()
{
    return [
        'scoreGain' => [
            'label' => 'Заработанные коины',
        ],
        'upgradeBuys' => [
            'label' => 'Покупки улучшений',
        ],
        'plusGain' => [
            'label' => 'Рост силы клика',
        ],
        'energyMaxGain' => [
            'label' => 'Рост запаса энергии',
        ],
        'skinPurchases' => [
            'label' => 'Покупки скинов',
        ],
        'flyRuns' => [
            'label' => 'Забеги в fly-beaver',
        ],
        'flyScoreGain' => [
            'label' => 'Очки в fly-beaver',
        ],
        'flyRewardCoins' => [
            'label' => 'Вывод коинов из fly-beaver',
        ],
    ];
}

function bober_default_quest_template_catalog()
{
    $items = [];

    foreach (bober_get_daily_quest_definitions() as $questKey => $definition) {
        $items[] = [
            'quest_key' => (string) $questKey,
            'scope' => 'daily',
            'title' => (string) ($definition['title'] ?? $questKey),
            'description' => (string) ($definition['description'] ?? ''),
            'metric' => (string) ($definition['metric'] ?? 'scoreGain'),
            'goal' => max(1, (int) ($definition['goal'] ?? 1)),
            'reward_coins' => max(0, (int) ($definition['rewardCoins'] ?? 0)),
            'is_active' => 1,
        ];
    }

    foreach (bober_get_weekly_quest_definitions() as $questKey => $definition) {
        $items[] = [
            'quest_key' => (string) $questKey,
            'scope' => 'weekly',
            'title' => (string) ($definition['title'] ?? $questKey),
            'description' => (string) ($definition['description'] ?? ''),
            'metric' => (string) ($definition['metric'] ?? 'scoreGain'),
            'goal' => max(1, (int) ($definition['goal'] ?? 1)),
            'reward_coins' => max(0, (int) ($definition['rewardCoins'] ?? 0)),
            'is_active' => 1,
        ];
    }

    return $items;
}

function bober_normalize_quest_scope($scope)
{
    return strtolower(trim((string) $scope)) === 'weekly' ? 'weekly' : 'daily';
}

function bober_normalize_quest_metric($metric)
{
    $normalizedMetric = trim((string) $metric);
    $allowedMetrics = array_keys(bober_get_quest_metric_definitions());

    return in_array($normalizedMetric, $allowedMetrics, true) ? $normalizedMetric : 'scoreGain';
}

function bober_normalize_quest_template_row($row)
{
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => max(0, (int) ($row['id'] ?? 0)),
        'key' => trim((string) ($row['quest_key'] ?? $row['key'] ?? '')),
        'scope' => bober_normalize_quest_scope($row['scope'] ?? 'daily'),
        'title' => trim((string) ($row['title'] ?? '')),
        'description' => trim((string) ($row['description'] ?? '')),
        'metric' => bober_normalize_quest_metric($row['metric'] ?? 'scoreGain'),
        'goal' => max(1, (int) ($row['goal'] ?? 1)),
        'rewardCoins' => max(0, (int) ($row['reward_coins'] ?? $row['rewardCoins'] ?? 0)),
        'isActive' => !array_key_exists('is_active', $row)
            ? !empty($row['isActive'])
            : (int) ($row['is_active'] ?? 0) === 1,
        'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : ((string) ($row['createdAt'] ?? '')),
        'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : ((string) ($row['updatedAt'] ?? '')),
    ];
}

function bober_clear_quest_template_caches()
{
    bober_file_cache_delete('quest_templates_active_daily');
    bober_file_cache_delete('quest_templates_active_weekly');
}

function bober_seed_default_quest_templates($conn)
{
    $countResult = $conn->query('SELECT COUNT(*) AS total FROM `quest_templates`');
    if (!$countResult) {
        throw new RuntimeException('Не удалось проверить каталог шаблонов квестов.');
    }

    $countRow = $countResult->fetch_assoc();
    $countResult->free();
    if (max(0, (int) ($countRow['total'] ?? 0)) > 0) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO `quest_templates` (`quest_key`, `scope`, `title`, `description`, `metric`, `goal`, `reward_coins`, `is_active`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить заполнение шаблонов квестов.');
    }

    foreach (bober_default_quest_template_catalog() as $item) {
        $questKey = trim((string) ($item['quest_key'] ?? ''));
        $scope = bober_normalize_quest_scope($item['scope'] ?? 'daily');
        $title = trim((string) ($item['title'] ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $metric = bober_normalize_quest_metric($item['metric'] ?? 'scoreGain');
        $goal = max(1, (int) ($item['goal'] ?? 1));
        $rewardCoins = max(0, (int) ($item['reward_coins'] ?? 0));
        $isActive = !empty($item['is_active']) ? 1 : 0;

        $stmt->bind_param('sssssiii', $questKey, $scope, $title, $description, $metric, $goal, $rewardCoins, $isActive);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось заполнить стартовый каталог квестов.');
        }
    }

    $stmt->close();
    bober_clear_quest_template_caches();
}

function bober_sync_default_quest_templates($conn)
{
    $result = $conn->query('SELECT `quest_key` FROM `quest_templates`');
    if (!$result) {
        throw new RuntimeException('Не удалось проверить актуальность каталога квестов.');
    }

    $existingKeys = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $questKey = trim((string) ($row['quest_key'] ?? ''));
            if ($questKey !== '') {
                $existingKeys[$questKey] = true;
            }
        }
        $result->free();
    }

    $missingItems = array_values(array_filter(bober_default_quest_template_catalog(), static function ($item) use ($existingKeys) {
        $questKey = trim((string) ($item['quest_key'] ?? ''));
        return $questKey !== '' && !isset($existingKeys[$questKey]);
    }));
    if (!$missingItems) {
        return;
    }

    $stmt = $conn->prepare(
        'INSERT INTO `quest_templates` (`quest_key`, `scope`, `title`, `description`, `metric`, `goal`, `reward_coins`, `is_active`)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить обновление стартовых шаблонов квестов.');
    }

    foreach ($missingItems as $item) {
        $questKey = trim((string) ($item['quest_key'] ?? ''));
        $scope = bober_normalize_quest_scope($item['scope'] ?? 'daily');
        $title = trim((string) ($item['title'] ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $metric = bober_normalize_quest_metric($item['metric'] ?? 'scoreGain');
        $goal = max(1, (int) ($item['goal'] ?? 1));
        $rewardCoins = max(0, (int) ($item['reward_coins'] ?? 0));
        $isActive = !empty($item['is_active']) ? 1 : 0;

        $stmt->bind_param('sssssiii', $questKey, $scope, $title, $description, $metric, $goal, $rewardCoins, $isActive);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось добавить новые стартовые шаблоны квестов.');
        }
    }

    $stmt->close();
    bober_clear_quest_template_caches();
}

function bober_fetch_quest_templates($conn, array $options = [])
{
    bober_ensure_user_quests_schema($conn);

    $scope = trim((string) ($options['scope'] ?? ''));
    $activeOnly = !empty($options['activeOnly']) || !empty($options['active_only']);
    $conditions = [];
    $params = [];
    $types = '';

    if ($scope !== '') {
        $conditions[] = '`scope` = ?';
        $params[] = bober_normalize_quest_scope($scope);
        $types .= 's';
    }

    if ($activeOnly) {
        $conditions[] = '`is_active` = 1';
    }

    $sql = 'SELECT `id`, `quest_key`, `scope`, `title`, `description`, `metric`, `goal`, `reward_coins`, `is_active`, `created_at`, `updated_at`
            FROM `quest_templates`';
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= " ORDER BY FIELD(`scope`, 'daily', 'weekly'), `is_active` DESC, `updated_at` DESC, `id` DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение шаблонов квестов.');
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось прочитать шаблоны квестов.');
    }

    $result = $stmt->get_result();
    $items = [];
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $normalizedRow = bober_normalize_quest_template_row($row);
            if ($normalizedRow !== null) {
                $items[] = $normalizedRow;
            }
        }
        $result->free();
    }
    $stmt->close();

    return $items;
}

function bober_fetch_quest_template_by_id($conn, $templateId)
{
    $templateId = max(0, (int) $templateId);
    if ($templateId < 1) {
        return null;
    }

    bober_ensure_user_quests_schema($conn);
    $stmt = $conn->prepare(
        'SELECT `id`, `quest_key`, `scope`, `title`, `description`, `metric`, `goal`, `reward_coins`, `is_active`, `created_at`, `updated_at`
         FROM `quest_templates`
         WHERE `id` = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение шаблона квеста.');
    }

    $stmt->bind_param('i', $templateId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось прочитать шаблон квеста.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return bober_normalize_quest_template_row($row);
}

function bober_fetch_quest_template_by_key($conn, $questKey)
{
    $questKey = trim((string) $questKey);
    if ($questKey === '') {
        return null;
    }

    bober_ensure_user_quests_schema($conn);
    $stmt = $conn->prepare(
        'SELECT `id`, `quest_key`, `scope`, `title`, `description`, `metric`, `goal`, `reward_coins`, `is_active`, `created_at`, `updated_at`
         FROM `quest_templates`
         WHERE `quest_key` = ?
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение шаблона квеста по ключу.');
    }

    $stmt->bind_param('s', $questKey);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось прочитать шаблон квеста по ключу.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    return bober_normalize_quest_template_row($row);
}

function bober_build_quest_definition_from_template(array $item)
{
    $questKey = trim((string) ($item['key'] ?? $item['quest_key'] ?? ''));
    if ($questKey === '') {
        return null;
    }

    return [
        'id' => max(0, (int) ($item['id'] ?? 0)),
        'title' => (string) ($item['title'] ?? $questKey),
        'description' => (string) ($item['description'] ?? ''),
        'metric' => (string) ($item['metric'] ?? 'scoreGain'),
        'goal' => max(1, (int) ($item['goal'] ?? 1)),
        'rewardCoins' => max(0, (int) ($item['rewardCoins'] ?? $item['reward_coins'] ?? 0)),
        'isActive' => !array_key_exists('isActive', $item) || !empty($item['isActive']) || !empty($item['is_active']),
    ];
}

function bober_fetch_active_quest_definitions($conn, $scope)
{
    $normalizedScope = bober_normalize_quest_scope($scope);
    $cacheKey = 'quest_templates_active_' . $normalizedScope;
    $cachedDefinitions = bober_file_cache_fetch($cacheKey, 300);
    if (is_array($cachedDefinitions)) {
        return $cachedDefinitions;
    }

    $definitions = [];
    foreach (bober_fetch_quest_templates($conn, ['scope' => $normalizedScope, 'activeOnly' => true]) as $item) {
        $questKey = trim((string) ($item['key'] ?? ''));
        if ($questKey === '') {
            continue;
        }

        $definition = bober_build_quest_definition_from_template($item);
        if ($definition !== null) {
            $definitions[$questKey] = $definition;
        }
    }

    bober_file_cache_store($cacheKey, $definitions);

    return $definitions;
}

function bober_generate_quest_template_key($conn, $scope)
{
    $normalizedScope = bober_normalize_quest_scope($scope);

    for ($attempt = 0; $attempt < 8; $attempt++) {
        try {
            $suffix = strtolower(bin2hex(random_bytes(4)));
        } catch (Throwable $error) {
            $suffix = strtolower(str_replace('.', '', uniqid('', true)));
        }

        $candidateKey = $normalizedScope . '_custom_' . $suffix;
        $stmt = $conn->prepare('SELECT `id` FROM `quest_templates` WHERE `quest_key` = ? LIMIT 1');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить проверку ключа квеста.');
        }

        $stmt->bind_param('s', $candidateKey);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось проверить уникальность ключа квеста.');
        }

        $result = $stmt->get_result();
        $exists = $result instanceof mysqli_result && $result->fetch_assoc();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
        $stmt->close();

        if (!$exists) {
            return $candidateKey;
        }
    }

    throw new RuntimeException('Не удалось сгенерировать уникальный ключ квеста.');
}

function bober_save_quest_template($conn, array $data, $templateId = 0)
{
    bober_ensure_user_quests_schema($conn);

    $templateId = max(0, (int) $templateId);
    $scope = bober_normalize_quest_scope($data['scope'] ?? 'daily');
    $title = trim((string) ($data['title'] ?? ''));
    $description = trim((string) ($data['description'] ?? ''));
    $metric = bober_normalize_quest_metric($data['metric'] ?? 'scoreGain');
    $goal = max(1, (int) ($data['goal'] ?? 1));
    $rewardCoins = max(0, (int) ($data['rewardCoins'] ?? $data['reward_coins'] ?? 0));
    $isActive = !empty($data['isActive']) || !empty($data['is_active']) ? 1 : 0;

    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
    if ($titleLength < 2) {
        throw new InvalidArgumentException('Введите название квеста.');
    }

    if ($description === '') {
        throw new InvalidArgumentException('Добавьте описание квеста.');
    }

    if ($templateId > 0) {
        $currentTemplate = bober_fetch_quest_template_by_id($conn, $templateId);
        if ($currentTemplate === null) {
            throw new RuntimeException('Шаблон квеста не найден.');
        }

        $questKey = (string) ($currentTemplate['key'] ?? '');
        $stmt = $conn->prepare(
            'UPDATE `quest_templates`
             SET `scope` = ?, `title` = ?, `description` = ?, `metric` = ?, `goal` = ?, `reward_coins` = ?, `is_active` = ?
             WHERE `id` = ?
             LIMIT 1'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить обновление квеста.');
        }

        $stmt->bind_param('ssssiiii', $scope, $title, $description, $metric, $goal, $rewardCoins, $isActive, $templateId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось обновить шаблон квеста.');
        }
        $stmt->close();
        $savedTemplateId = $templateId;
    } else {
        $questKey = bober_generate_quest_template_key($conn, $scope);
        $stmt = $conn->prepare(
            'INSERT INTO `quest_templates` (`quest_key`, `scope`, `title`, `description`, `metric`, `goal`, `reward_coins`, `is_active`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить создание квеста.');
        }

        $stmt->bind_param('sssssiii', $questKey, $scope, $title, $description, $metric, $goal, $rewardCoins, $isActive);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось создать шаблон квеста.');
        }
        $savedTemplateId = (int) $stmt->insert_id;
        $stmt->close();
    }

    bober_clear_quest_template_caches();

    $savedTemplate = bober_fetch_quest_template_by_id($conn, $savedTemplateId);
    if ($savedTemplate === null) {
        throw new RuntimeException('Шаблон квеста сохранен, но не удалось прочитать его обратно.');
    }

    return $savedTemplate;
}

function bober_delete_quest_template($conn, $templateId)
{
    bober_ensure_user_quests_schema($conn);

    $templateId = max(0, (int) $templateId);
    if ($templateId < 1) {
        throw new InvalidArgumentException('Не указан шаблон квеста.');
    }

    $template = bober_fetch_quest_template_by_id($conn, $templateId);
    if ($template === null) {
        throw new RuntimeException('Шаблон квеста не найден.');
    }

    $stmt = $conn->prepare('DELETE FROM `quest_templates` WHERE `id` = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить удаление квеста.');
    }

    $stmt->bind_param('i', $templateId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось удалить шаблон квеста.');
    }
    $stmt->close();

    bober_clear_quest_template_caches();

    return $template;
}

function bober_select_rotating_quest_keys(array $definitions, $userId, $periodKey, $count, $rotationToken = 0)
{
    $scoredItems = [];
    foreach ($definitions as $questKey => $definition) {
        $scoredItems[] = [
            'key' => (string) $questKey,
            'hash' => hash('sha256', (string) $periodKey . '|' . (string) $rotationToken . '|' . (string) $userId . '|' . (string) $questKey),
        ];
    }

    usort($scoredItems, static function ($left, $right) {
        $hashCompare = strcmp((string) ($left['hash'] ?? ''), (string) ($right['hash'] ?? ''));
        if ($hashCompare !== 0) {
            return $hashCompare;
        }

        return strcmp((string) ($left['key'] ?? ''), (string) ($right['key'] ?? ''));
    });

    return array_values(array_map(static function ($item) {
        return (string) ($item['key'] ?? '');
    }, array_slice($scoredItems, 0, max(0, (int) $count))));
}

function bober_store_user_quest_progress_state($conn, $userId, array $state)
{
    $stmt = $conn->prepare(
        'INSERT INTO user_quest_progress (user_id, daily_period_key, daily_roll_token, daily_counters_json, daily_claimed_json, daily_manual_keys_json, weekly_period_key, weekly_roll_token, weekly_counters_json, weekly_claimed_json, weekly_manual_keys_json)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            daily_period_key = VALUES(daily_period_key),
            daily_roll_token = VALUES(daily_roll_token),
            daily_counters_json = VALUES(daily_counters_json),
            daily_claimed_json = VALUES(daily_claimed_json),
            daily_manual_keys_json = VALUES(daily_manual_keys_json),
            weekly_period_key = VALUES(weekly_period_key),
            weekly_roll_token = VALUES(weekly_roll_token),
            weekly_counters_json = VALUES(weekly_counters_json),
            weekly_claimed_json = VALUES(weekly_claimed_json),
            weekly_manual_keys_json = VALUES(weekly_manual_keys_json)'
    );
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить сохранение прогресса квестов.');
    }

    $dailyPeriodKey = (string) ($state['dailyPeriodKey'] ?? '');
    $dailyRollToken = max(0, (int) ($state['dailyRollToken'] ?? 0));
    $weeklyPeriodKey = (string) ($state['weeklyPeriodKey'] ?? '');
    $weeklyRollToken = max(0, (int) ($state['weeklyRollToken'] ?? 0));
    $dailyCountersJson = bober_encode_json_map((array) ($state['dailyCounters'] ?? []));
    $dailyClaimedJson = bober_encode_json_map((array) ($state['dailyClaimed'] ?? []));
    $dailyManualKeysJson = bober_encode_json_list(bober_normalize_quest_manual_keys((array) ($state['dailyManualKeys'] ?? [])));
    $weeklyCountersJson = bober_encode_json_map((array) ($state['weeklyCounters'] ?? []));
    $weeklyClaimedJson = bober_encode_json_map((array) ($state['weeklyClaimed'] ?? []));
    $weeklyManualKeysJson = bober_encode_json_list(bober_normalize_quest_manual_keys((array) ($state['weeklyManualKeys'] ?? [])));

    $stmt->bind_param(
        'isissssisss',
        $userId,
        $dailyPeriodKey,
        $dailyRollToken,
        $dailyCountersJson,
        $dailyClaimedJson,
        $dailyManualKeysJson,
        $weeklyPeriodKey,
        $weeklyRollToken,
        $weeklyCountersJson,
        $weeklyClaimedJson,
        $weeklyManualKeysJson
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось сохранить прогресс квестов.');
    }
    $stmt->close();
}

function bober_prepare_user_quest_progress_state($conn, $userId, $forUpdate = false)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'dailyPeriodKey' => '',
            'dailyRollToken' => 0,
            'dailyCounters' => bober_default_quest_counters(),
            'dailyClaimed' => [],
            'dailyManualKeys' => bober_default_quest_manual_keys(),
            'weeklyPeriodKey' => '',
            'weeklyRollToken' => 0,
            'weeklyCounters' => bober_default_quest_counters(),
            'weeklyClaimed' => [],
            'weeklyManualKeys' => bober_default_quest_manual_keys(),
        ];
    }

    bober_ensure_user_quests_schema($conn);
    $periods = bober_get_quest_period_keys();
    $query = 'SELECT user_id, daily_period_key, daily_roll_token, daily_counters_json, daily_claimed_json, daily_manual_keys_json, weekly_period_key, weekly_roll_token, weekly_counters_json, weekly_claimed_json, weekly_manual_keys_json FROM user_quest_progress WHERE user_id = ? LIMIT 1';
    if ($forUpdate) {
        $query .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить чтение прогресса квестов.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось прочитать прогресс квестов.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        $state = [
            'dailyPeriodKey' => $periods['daily'],
            'dailyRollToken' => 0,
            'dailyCounters' => bober_default_quest_counters(),
            'dailyClaimed' => [],
            'dailyManualKeys' => bober_default_quest_manual_keys(),
            'weeklyPeriodKey' => $periods['weekly'],
            'weeklyRollToken' => 0,
            'weeklyCounters' => bober_default_quest_counters(),
            'weeklyClaimed' => [],
            'weeklyManualKeys' => bober_default_quest_manual_keys(),
        ];
        bober_store_user_quest_progress_state($conn, $userId, $state);
        return $state;
    }

    $state = [
        'dailyPeriodKey' => (string) ($row['daily_period_key'] ?? ''),
        'dailyRollToken' => max(0, (int) ($row['daily_roll_token'] ?? 0)),
        'dailyCounters' => array_merge(
            bober_default_quest_counters(),
            array_map('intval', bober_decode_json_map((string) ($row['daily_counters_json'] ?? ''), []))
        ),
        'dailyClaimed' => array_filter(bober_decode_json_map((string) ($row['daily_claimed_json'] ?? ''), []), static function ($value) {
            return is_string($value) && trim($value) !== '';
        }),
        'dailyManualKeys' => bober_normalize_quest_manual_keys(bober_decode_json_list((string) ($row['daily_manual_keys_json'] ?? ''), [])),
        'weeklyPeriodKey' => (string) ($row['weekly_period_key'] ?? ''),
        'weeklyRollToken' => max(0, (int) ($row['weekly_roll_token'] ?? 0)),
        'weeklyCounters' => array_merge(
            bober_default_quest_counters(),
            array_map('intval', bober_decode_json_map((string) ($row['weekly_counters_json'] ?? ''), []))
        ),
        'weeklyClaimed' => array_filter(bober_decode_json_map((string) ($row['weekly_claimed_json'] ?? ''), []), static function ($value) {
            return is_string($value) && trim($value) !== '';
        }),
        'weeklyManualKeys' => bober_normalize_quest_manual_keys(bober_decode_json_list((string) ($row['weekly_manual_keys_json'] ?? ''), [])),
    ];

    $changed = false;
    if ($state['dailyPeriodKey'] !== $periods['daily']) {
        $state['dailyPeriodKey'] = $periods['daily'];
        $state['dailyRollToken'] = 0;
        $state['dailyCounters'] = bober_default_quest_counters();
        $state['dailyClaimed'] = [];
        $state['dailyManualKeys'] = bober_default_quest_manual_keys();
        $changed = true;
    }
    if ($state['weeklyPeriodKey'] !== $periods['weekly']) {
        $state['weeklyPeriodKey'] = $periods['weekly'];
        $state['weeklyRollToken'] = 0;
        $state['weeklyCounters'] = bober_default_quest_counters();
        $state['weeklyClaimed'] = [];
        $state['weeklyManualKeys'] = bober_default_quest_manual_keys();
        $changed = true;
    }

    if ($changed) {
        bober_store_user_quest_progress_state($conn, $userId, $state);
    }

    return $state;
}

function bober_increment_user_quest_counters($conn, $userId, array $deltas)
{
    $normalizedDeltas = [];
    foreach (array_keys(bober_get_quest_metric_definitions()) as $metricKey) {
        $normalizedDeltas[$metricKey] = max(0, (int) ($deltas[$metricKey] ?? 0));
    }

    $hasPositiveDelta = false;
    foreach ($normalizedDeltas as $value) {
        if ($value > 0) {
            $hasPositiveDelta = true;
            break;
        }
    }

    if (!$hasPositiveDelta) {
        return;
    }

    $state = bober_prepare_user_quest_progress_state($conn, $userId, true);
    foreach ($normalizedDeltas as $metric => $value) {
        if ($value < 1) {
            continue;
        }
        $state['dailyCounters'][$metric] = max(0, (int) ($state['dailyCounters'][$metric] ?? 0)) + $value;
        $state['weeklyCounters'][$metric] = max(0, (int) ($state['weeklyCounters'][$metric] ?? 0)) + $value;
    }

    bober_store_user_quest_progress_state($conn, $userId, $state);
}

function bober_attach_manual_quest_definitions($conn, array $definitions, $scope, array $manualKeys)
{
    $normalizedScope = bober_normalize_quest_scope($scope);
    foreach (bober_normalize_quest_manual_keys($manualKeys) as $questKey) {
        if ($questKey === '' || isset($definitions[$questKey])) {
            continue;
        }

        $template = bober_fetch_quest_template_by_key($conn, $questKey);
        if ($template === null || bober_normalize_quest_scope($template['scope'] ?? 'daily') !== $normalizedScope) {
            continue;
        }

        $definition = bober_build_quest_definition_from_template($template);
        if ($definition !== null) {
            $definitions[$questKey] = $definition;
        }
    }

    return $definitions;
}

function bober_resolve_user_quest_slots(array $definitions, array $rotatingKeys, array $manualKeys, $count = 2)
{
    $slotCount = max(1, (int) $count);
    $slots = array_fill(0, $slotCount, [
        'key' => '',
        'manual' => false,
    ]);
    $usedKeys = [];
    $normalizedManualKeys = bober_normalize_quest_manual_keys($manualKeys, $slotCount);

    for ($index = 0; $index < $slotCount; $index++) {
        $questKey = trim((string) ($normalizedManualKeys[$index] ?? ''));
        if ($questKey === '' || !isset($definitions[$questKey]) || in_array($questKey, $usedKeys, true)) {
            continue;
        }

        $slots[$index] = [
            'key' => $questKey,
            'manual' => true,
        ];
        $usedKeys[] = $questKey;
    }

    foreach ($rotatingKeys as $questKey) {
        $questKey = trim((string) $questKey);
        if ($questKey === '' || !isset($definitions[$questKey]) || in_array($questKey, $usedKeys, true)) {
            continue;
        }

        foreach ($slots as $index => $slot) {
            if (($slot['key'] ?? '') !== '') {
                continue;
            }

            $slots[$index] = [
                'key' => $questKey,
                'manual' => false,
            ];
            $usedKeys[] = $questKey;
            break;
        }
    }

    return $slots;
}

function bober_extract_user_quest_manual_keys_from_slots(array $slots, $count = 2)
{
    $slotCount = max(1, (int) $count);
    $manualKeys = bober_default_quest_manual_keys($slotCount);

    for ($index = 0; $index < $slotCount; $index++) {
        $slot = is_array($slots[$index] ?? null) ? $slots[$index] : [];
        if (!empty($slot['manual'])) {
            $manualKeys[$index] = trim((string) ($slot['key'] ?? ''));
        }
    }

    return $manualKeys;
}

function bober_resolve_user_quests($conn, $userId, $applyRewards = true)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        return [
            'items' => [
                'daily' => ['periodKey' => '', 'manualKeys' => ['', ''], 'items' => []],
                'weekly' => ['periodKey' => '', 'manualKeys' => ['', ''], 'items' => []],
            ],
            'newlyCompleted' => [],
            'rewardCoins' => 0,
        ];
    }

    $state = bober_prepare_user_quest_progress_state($conn, $userId, $applyRewards);
    $dailyActiveDefinitions = bober_fetch_active_quest_definitions($conn, 'daily');
    $weeklyActiveDefinitions = bober_fetch_active_quest_definitions($conn, 'weekly');
    $dailyKeys = bober_select_rotating_quest_keys($dailyActiveDefinitions, $userId, $state['dailyPeriodKey'], 2, $state['dailyRollToken'] ?? 0);
    $weeklyKeys = bober_select_rotating_quest_keys($weeklyActiveDefinitions, $userId, $state['weeklyPeriodKey'], 2, $state['weeklyRollToken'] ?? 0);
    $dailyDefinitions = bober_attach_manual_quest_definitions($conn, $dailyActiveDefinitions, 'daily', (array) ($state['dailyManualKeys'] ?? []));
    $weeklyDefinitions = bober_attach_manual_quest_definitions($conn, $weeklyActiveDefinitions, 'weekly', (array) ($state['weeklyManualKeys'] ?? []));
    $dailySlots = bober_resolve_user_quest_slots($dailyDefinitions, $dailyKeys, (array) ($state['dailyManualKeys'] ?? []), 2);
    $weeklySlots = bober_resolve_user_quest_slots($weeklyDefinitions, $weeklyKeys, (array) ($state['weeklyManualKeys'] ?? []), 2);
    $rewardCoinsTotal = 0;
    $newlyCompleted = [];
    $stateChanged = false;

    $normalizedDailyManualKeys = bober_extract_user_quest_manual_keys_from_slots($dailySlots, 2);
    $normalizedWeeklyManualKeys = bober_extract_user_quest_manual_keys_from_slots($weeklySlots, 2);
    if ($normalizedDailyManualKeys !== bober_normalize_quest_manual_keys((array) ($state['dailyManualKeys'] ?? []), 2)) {
        $state['dailyManualKeys'] = $normalizedDailyManualKeys;
        $stateChanged = true;
    }
    if ($normalizedWeeklyManualKeys !== bober_normalize_quest_manual_keys((array) ($state['weeklyManualKeys'] ?? []), 2)) {
        $state['weeklyManualKeys'] = $normalizedWeeklyManualKeys;
        $stateChanged = true;
    }

    $buildQuestItems = static function ($scope, array $definitions, array $questSlots, $periodKey, array $counters, array &$claimedMap) use (&$rewardCoinsTotal, &$newlyCompleted, &$stateChanged, $applyRewards) {
        $items = [];
        foreach ($questSlots as $slotIndex => $slot) {
            $questKey = trim((string) ($slot['key'] ?? ''));
            if (!isset($definitions[$questKey])) {
                continue;
            }

            $definition = $definitions[$questKey];
            $metric = (string) ($definition['metric'] ?? '');
            $goal = max(1, (int) ($definition['goal'] ?? 1));
            $progress = min($goal, max(0, (int) ($counters[$metric] ?? 0)));
            $completed = $progress >= $goal;
            $claimedAt = trim((string) ($claimedMap[$questKey] ?? ''));

            if ($completed && $claimedAt === '' && $applyRewards) {
                $claimedAt = gmdate('c');
                $claimedMap[$questKey] = $claimedAt;
                $rewardCoins = max(0, (int) ($definition['rewardCoins'] ?? 0));
                $rewardCoinsTotal += $rewardCoins;
                $stateChanged = true;
                $newlyCompleted[] = [
                    'scope' => $scope,
                    'periodKey' => $periodKey,
                    'key' => $questKey,
                    'title' => (string) ($definition['title'] ?? $questKey),
                    'description' => (string) ($definition['description'] ?? ''),
                    'goal' => $goal,
                    'progress' => $progress,
                    'rewardCoins' => $rewardCoins,
                    'claimedAt' => $claimedAt,
                ];
            }

            $items[] = [
                'scope' => $scope,
                'periodKey' => $periodKey,
                'key' => $questKey,
                'templateId' => max(0, (int) ($definition['id'] ?? 0)),
                'title' => (string) ($definition['title'] ?? $questKey),
                'description' => (string) ($definition['description'] ?? ''),
                'metric' => $metric,
                'goal' => $goal,
                'progress' => $progress,
                'slotIndex' => $slotIndex,
                'manual' => !empty($slot['manual']),
                'completed' => $completed,
                'claimed' => $claimedAt !== '',
                'claimedAt' => $claimedAt,
                'rewardCoins' => max(0, (int) ($definition['rewardCoins'] ?? 0)),
            ];
        }

        return $items;
    };

    $dailyItems = $buildQuestItems('daily', $dailyDefinitions, $dailySlots, $state['dailyPeriodKey'], (array) $state['dailyCounters'], $state['dailyClaimed']);
    $weeklyItems = $buildQuestItems('weekly', $weeklyDefinitions, $weeklySlots, $state['weeklyPeriodKey'], (array) $state['weeklyCounters'], $state['weeklyClaimed']);

    if ($stateChanged) {
        bober_store_user_quest_progress_state($conn, $userId, $state);
    }

    if ($rewardCoinsTotal > 0 && $applyRewards) {
        $rewardStmt = $conn->prepare('UPDATE users SET score = score + ? WHERE id = ? LIMIT 1');
        if (!$rewardStmt) {
            throw new RuntimeException('Не удалось подготовить начисление наград за квесты.');
        }

        $rewardStmt->bind_param('ii', $rewardCoinsTotal, $userId);
        if (!$rewardStmt->execute()) {
            $rewardStmt->close();
            throw new RuntimeException('Не удалось начислить награды за квесты.');
        }
        $rewardStmt->close();
    }

    return [
        'items' => [
            'daily' => [
                'periodKey' => $state['dailyPeriodKey'],
                'manualKeys' => bober_normalize_quest_manual_keys((array) ($state['dailyManualKeys'] ?? []), 2),
                'items' => $dailyItems,
            ],
            'weekly' => [
                'periodKey' => $state['weeklyPeriodKey'],
                'manualKeys' => bober_normalize_quest_manual_keys((array) ($state['weeklyManualKeys'] ?? []), 2),
                'items' => $weeklyItems,
            ],
        ],
        'newlyCompleted' => $newlyCompleted,
        'rewardCoins' => $rewardCoinsTotal,
    ];
}

function bober_refresh_user_quests($conn, $userId)
{
    return bober_resolve_user_quests($conn, $userId, true);
}

function bober_peek_user_quests($conn, $userId)
{
    return bober_resolve_user_quests($conn, $userId, false);
}

function bober_reroll_user_quests($conn, $userId, $scope = 'all')
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $normalizedScope = strtolower(trim((string) $scope));
    if (!in_array($normalizedScope, ['daily', 'weekly', 'all'], true)) {
        $normalizedScope = 'all';
    }

    $state = bober_prepare_user_quest_progress_state($conn, $userId, true);
    $changed = false;

    if ($normalizedScope === 'daily' || $normalizedScope === 'all') {
        $state['dailyRollToken'] = max(0, (int) ($state['dailyRollToken'] ?? 0)) + 1;
        $state['dailyClaimed'] = [];
        $state['dailyManualKeys'] = bober_default_quest_manual_keys();
        $changed = true;
    }

    if ($normalizedScope === 'weekly' || $normalizedScope === 'all') {
        $state['weeklyRollToken'] = max(0, (int) ($state['weeklyRollToken'] ?? 0)) + 1;
        $state['weeklyClaimed'] = [];
        $state['weeklyManualKeys'] = bober_default_quest_manual_keys();
        $changed = true;
    }

    if ($changed) {
        bober_store_user_quest_progress_state($conn, $userId, $state);
    }

    return bober_peek_user_quests($conn, $userId);
}

function bober_assign_user_quest($conn, $userId, $scope, $slotIndex, $questKey = '')
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $normalizedScope = bober_normalize_quest_scope($scope);
    $normalizedSlotIndex = max(0, min(1, (int) $slotIndex));
    $normalizedQuestKey = trim((string) $questKey);

    if ($normalizedQuestKey !== '') {
        $template = bober_fetch_quest_template_by_key($conn, $normalizedQuestKey);
        if ($template === null) {
            throw new RuntimeException('Шаблон квеста не найден.');
        }

        if (bober_normalize_quest_scope($template['scope'] ?? 'daily') !== $normalizedScope) {
            throw new RuntimeException('Нельзя выдать квест другого типа ротации.');
        }
    }

    $state = bober_prepare_user_quest_progress_state($conn, $userId, true);
    $manualKeysField = $normalizedScope === 'weekly' ? 'weeklyManualKeys' : 'dailyManualKeys';
    $claimedField = $normalizedScope === 'weekly' ? 'weeklyClaimed' : 'dailyClaimed';
    $manualKeys = bober_normalize_quest_manual_keys((array) ($state[$manualKeysField] ?? []), 2);

    $manualKeys[$normalizedSlotIndex] = $normalizedQuestKey;
    if ($normalizedQuestKey !== '') {
        foreach ($manualKeys as $index => $manualKey) {
            if ($index === $normalizedSlotIndex) {
                continue;
            }
            if (trim((string) $manualKey) === $normalizedQuestKey) {
                $manualKeys[$index] = '';
            }
        }
        unset($state[$claimedField][$normalizedQuestKey]);
    }

    $state[$manualKeysField] = $manualKeys;
    bober_store_user_quest_progress_state($conn, $userId, $state);

    return bober_peek_user_quests($conn, $userId);
}

function bober_fetch_account_snapshot($conn, $userId, array $options = [])
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }
    $includeActivity = !empty($options['includeActivity']) || !empty($options['include_activity']);

    bober_reconcile_top_reward_skins($conn);

    $stmt = $conn->prepare('SELECT id, login, plus, skin, energy, last_energy_update, ENERGY_MAX, score, upgrade_tap_small_count, upgrade_tap_big_count, upgrade_energy_count, upgrade_tap_huge_count, upgrade_regen_boost_count, upgrade_energy_huge_count, upgrade_click_rate_count FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    if ($result) {
        $result->free();
    }
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Сессия устарела.');
    }

    $normalizedSkin = bober_normalize_skin_json($row['skin'] ?? null);
    if ($normalizedSkin !== (string) ($row['skin'] ?? '')) {
        $updateStmt = $conn->prepare('UPDATE users SET skin = ? WHERE id = ?');
        if ($updateStmt) {
            $updateStmt->bind_param('si', $normalizedSkin, $userId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    $upgradeCounts = bober_normalize_upgrade_counts([
        'tapSmall' => $row['upgrade_tap_small_count'] ?? 0,
        'tapBig' => $row['upgrade_tap_big_count'] ?? 0,
        'energy' => $row['upgrade_energy_count'] ?? 0,
        'tapHuge' => $row['upgrade_tap_huge_count'] ?? 0,
        'regenBoost' => $row['upgrade_regen_boost_count'] ?? 0,
        'energyHuge' => $row['upgrade_energy_huge_count'] ?? 0,
        'clickRate' => $row['upgrade_click_rate_count'] ?? 0,
    ]);
    $resolvedPlus = bober_calculate_plus_from_upgrade_counts($upgradeCounts);
    $energyMax = bober_calculate_energy_max_from_upgrade_counts($upgradeCounts);
    $energy = max(0, min($energyMax, (int) ($row['energy'] ?? 0)));
    $flyBeaver = bober_fetch_fly_beaver_progress($conn, $userId);
    $skinState = bober_decode_skin_state($normalizedSkin);
    $ownedSkinIds = array_values(array_unique(array_map('strval', $skinState['ownedSkinIds'] ?? [])));
    $upgradeTapSmallCount = $upgradeCounts['tapSmall'];
    $upgradeTapBigCount = $upgradeCounts['tapBig'];
    $upgradeEnergyCount = $upgradeCounts['energy'];
    $upgradeTapHugeCount = $upgradeCounts['tapHuge'];
    $upgradeRegenBoostCount = $upgradeCounts['regenBoost'];
    $upgradeEnergyHugeCount = $upgradeCounts['energyHuge'];
    $upgradeClickRateCount = $upgradeCounts['clickRate'];
    $totalUpgradePurchases = array_sum($upgradeCounts);
    $achievementSnapshot = [
        'score' => max(0, (int) ($row['score'] ?? 0)),
        'plus' => $resolvedPlus,
        'energyMax' => $energyMax,
        'ownedSkinCount' => count($ownedSkinIds),
        'clickerTop1' => in_array(bober_clicker_top_reward_skin_id(), $ownedSkinIds, true),
        'flyTop1' => in_array(bober_fly_beaver_top_reward_skin_id(), $ownedSkinIds, true),
        'flyBeaver' => $flyBeaver,
        'flyGamesPlayed' => max(0, (int) ($flyBeaver['gamesPlayed'] ?? 0)),
        'totalUpgradePurchases' => $totalUpgradePurchases,
        'upgradeCounts' => [
            'tapSmall' => $upgradeTapSmallCount,
            'tapBig' => $upgradeTapBigCount,
            'energy' => $upgradeEnergyCount,
            'tapHuge' => $upgradeTapHugeCount,
            'regenBoost' => $upgradeRegenBoostCount,
            'energyHuge' => $upgradeEnergyHugeCount,
            'clickRate' => $upgradeClickRateCount,
        ],
    ];
    $achievementRefresh = bober_refresh_user_achievements($conn, $userId, $achievementSnapshot);
    $achievementStatsBundle = bober_fetch_achievement_stats($conn, !empty($achievementRefresh['statsChanged']));
    $achievementStats = is_array($achievementStatsBundle['items'] ?? null) ? $achievementStatsBundle['items'] : [];
    $achievementPlayerBase = max(0, (int) ($achievementStatsBundle['totalPlayers'] ?? 0));
    $achievementCatalogMap = bober_fetch_achievement_catalog_map($conn);
    $achievements = bober_enrich_achievement_items(
        is_array($achievementRefresh['items'] ?? null) ? $achievementRefresh['items'] : [],
        $achievementStats,
        $achievementPlayerBase,
        $achievementCatalogMap
    );
    $achievementUnlocks = bober_enrich_achievement_items(
        is_array($achievementRefresh['newlyUnlocked'] ?? null) ? $achievementRefresh['newlyUnlocked'] : [],
        $achievementStats,
        $achievementPlayerBase,
        $achievementCatalogMap
    );
    $achievementRewardCoins = max(0, (int) ($achievementRefresh['rewardCoins'] ?? 0));
    $questRefresh = bober_refresh_user_quests($conn, $userId);
    $quests = is_array($questRefresh['items'] ?? null) ? $questRefresh['items'] : [
        'daily' => ['periodKey' => '', 'manualKeys' => ['', ''], 'items' => []],
        'weekly' => ['periodKey' => '', 'manualKeys' => ['', ''], 'items' => []],
    ];
    $questUnlocks = is_array($questRefresh['newlyCompleted'] ?? null) ? $questRefresh['newlyCompleted'] : [];
    $questRewardCoins = max(0, (int) ($questRefresh['rewardCoins'] ?? 0));
    $resolvedScore = max(0, (int) ($row['score'] ?? 0)) + $achievementRewardCoins + $questRewardCoins;
    foreach ($achievementUnlocks as $achievementItem) {
        $achievementMeta = is_array($achievementItem['meta'] ?? null) ? $achievementItem['meta'] : [];
        $achievementTitle = trim((string) ($achievementMeta['title'] ?? ($achievementItem['key'] ?? '')));
        bober_log_user_activity($conn, $userId, 'achievement_unlock', [
            'action_group' => 'achievements',
            'source' => 'account_snapshot',
            'login' => (string) ($row['login'] ?? ''),
            'description' => $achievementTitle !== ''
                ? 'Открыто достижение «' . $achievementTitle . '».'
                : 'Открыто новое достижение.',
            'coins_delta' => max(0, (int) ($achievementMeta['rewardCoins'] ?? $achievementMeta['reward_coins'] ?? 0)),
            'meta' => [
                'key' => (string) ($achievementItem['key'] ?? ''),
                'title' => $achievementTitle,
                'reward_coins' => max(0, (int) ($achievementMeta['rewardCoins'] ?? $achievementMeta['reward_coins'] ?? 0)),
            ],
        ]);
    }
    foreach ($questUnlocks as $questItem) {
        $questTitle = trim((string) ($questItem['title'] ?? ($questItem['key'] ?? '')));
        bober_log_user_activity($conn, $userId, 'quest_complete', [
            'action_group' => 'quests',
            'source' => 'account_snapshot',
            'login' => (string) ($row['login'] ?? ''),
            'description' => $questTitle !== ''
                ? 'Выполнен квест «' . $questTitle . '».'
                : 'Выполнен квест.',
            'coins_delta' => max(0, (int) ($questItem['rewardCoins'] ?? 0)),
            'meta' => [
                'key' => (string) ($questItem['key'] ?? ''),
                'scope' => (string) ($questItem['scope'] ?? ''),
                'period_key' => (string) ($questItem['periodKey'] ?? ''),
                'title' => $questTitle,
                'description' => trim((string) ($questItem['description'] ?? '')),
                'goal' => max(0, (int) ($questItem['goal'] ?? 0)),
                'progress' => max(0, (int) ($questItem['progress'] ?? 0)),
                'reward_coins' => max(0, (int) ($questItem['rewardCoins'] ?? 0)),
            ],
        ]);
    }
    $supportSummary = bober_fetch_user_support_summary($conn, $userId);
    $catalog = bober_skin_catalog();
    $skinSummary = bober_build_owned_skin_summary($catalog, $ownedSkinIds);
    $economyProfile = bober_build_user_economy_profile([
        'score' => max(0, (int) ($row['score'] ?? 0)),
        'plus' => $resolvedPlus,
        'energyMax' => $energyMax,
        'flyBest' => max(0, (int) ($flyBeaver['bestScore'] ?? 0)),
        'ownedSkinIds' => $ownedSkinIds,
        'upgradeCounts' => $upgradeCounts,
        'catalog' => $catalog,
    ]);
    $dailyQuestItems = is_array($quests['daily']['items'] ?? null) ? $quests['daily']['items'] : [];
    $weeklyQuestItems = is_array($quests['weekly']['items'] ?? null) ? $quests['weekly']['items'] : [];
    $dailyCompletedCount = count(array_filter($dailyQuestItems, static function ($item) {
        return !empty($item['claimed']);
    }));
    $weeklyCompletedCount = count(array_filter($weeklyQuestItems, static function ($item) {
        return !empty($item['claimed']);
    }));
    $honors = bober_build_profile_honors([
        'score' => $resolvedScore,
        'flyBest' => max(0, (int) ($flyBeaver['bestScore'] ?? 0)),
        'achievementsUnlocked' => count($achievements),
        'ownedSkins' => count($ownedSkinIds),
        'legendarySkins' => $skinSummary['legendarySkins'],
        'grantOnlySkins' => $skinSummary['grantOnlySkins'],
        'clickerTop1' => !empty($achievementSnapshot['clickerTop1']),
        'flyTop1' => !empty($achievementSnapshot['flyTop1']),
    ]);
    $profile = [
        'ownedSkins' => count($ownedSkinIds),
        'achievementsUnlocked' => count($achievements),
        'clickerTop1' => !empty($achievementSnapshot['clickerTop1']),
        'flyTop1' => !empty($achievementSnapshot['flyTop1']),
        'rareSkins' => $skinSummary['rareSkins'],
        'epicSkins' => $skinSummary['epicSkins'],
        'legendarySkins' => $skinSummary['legendarySkins'],
        'grantOnlySkins' => $skinSummary['grantOnlySkins'],
        'topRewardSkins' => $skinSummary['topRewardSkins'],
        'score' => $resolvedScore,
        'plus' => $resolvedPlus,
        'energyMax' => $energyMax,
        'flyBest' => max(0, (int) ($flyBeaver['bestScore'] ?? 0)),
        'flyPending' => max(0, (int) ($flyBeaver['pendingTransferScore'] ?? 0)),
        'flyGamesPlayed' => max(0, (int) ($flyBeaver['gamesPlayed'] ?? 0)),
        'dailyQuestCompleted' => $dailyCompletedCount,
        'dailyQuestTotal' => count($dailyQuestItems),
        'weeklyQuestCompleted' => $weeklyCompletedCount,
        'weeklyQuestTotal' => count($weeklyQuestItems),
        'economy' => $economyProfile,
        'honors' => $honors,
    ];

    $settingsRecord = bober_fetch_user_settings_record($conn, $userId);
    $response = [
        'success' => true,
        'userId' => (int) ($row['id'] ?? $userId),
        'login' => (string) ($row['login'] ?? ''),
        'plus' => $resolvedPlus,
        'skin' => $normalizedSkin,
        'energy' => $energy,
        'lastEnergyUpdate' => max(0, (int) ($row['last_energy_update'] ?? 0)),
        'ENERGY_MAX' => $energyMax,
        'score' => $resolvedScore,
        'upgradePurchases' => [
            'tapSmall' => $upgradeTapSmallCount,
            'tapBig' => $upgradeTapBigCount,
            'energy' => $upgradeEnergyCount,
            'tapHuge' => $upgradeTapHugeCount,
            'regenBoost' => $upgradeRegenBoostCount,
            'energyHuge' => $upgradeEnergyHugeCount,
            'clickRate' => $upgradeClickRateCount,
        ],
        'flyBeaver' => $flyBeaver,
        'settings' => is_array($settingsRecord['settings'] ?? null) ? $settingsRecord['settings'] : bober_default_user_settings(),
        'settingsUpdatedAt' => isset($settingsRecord['updatedAt']) ? (string) $settingsRecord['updatedAt'] : '',
        'profile' => $profile,
        'achievements' => $achievements,
        'achievementUnlocks' => $achievementUnlocks,
        'achievementStats' => $achievementStats,
        'achievementPlayerBase' => $achievementPlayerBase,
        'quests' => $quests,
        'questUnlocks' => $questUnlocks,
        'supportSummary' => $supportSummary,
    ];

    if ($includeActivity) {
        $response['recentActivity'] = bober_fetch_user_activity_feed($conn, $userId, [
            'limit' => 12,
        ]);
    }

    return $response;
}

function bober_apply_user_state_update($conn, $userId, $data)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $score = max(0, (int) ($data['score'] ?? 0));
    $requestedSkinState = bober_decode_skin_state($data['skin'] ?? null);
    $energy = max(0, (int) ($data['energy'] ?? 0));
    $lastEnergyUpdate = (string) max(0, (int) ($data['lastEnergyUpdate'] ?? 0));
    $clientLogBatch = is_array($data['clientLogBatch'] ?? null) ? $data['clientLogBatch'] : null;

    $currentStmt = $conn->prepare('SELECT score, plus, skin, energy, ENERGY_MAX, upgrade_tap_small_count, upgrade_tap_big_count, upgrade_energy_count, upgrade_tap_huge_count, upgrade_regen_boost_count, upgrade_energy_huge_count, upgrade_click_rate_count FROM users WHERE id = ? LIMIT 1');
    if (!$currentStmt) {
        throw new RuntimeException('Ошибка подготовки чтения текущего состояния.');
    }

    $currentStmt->bind_param('i', $userId);
    if (!$currentStmt->execute()) {
        $currentStmt->close();
        throw new RuntimeException('Ошибка чтения текущего состояния.');
    }

    $currentResult = $currentStmt->get_result();
    $currentRow = $currentResult ? $currentResult->fetch_assoc() : null;
    if ($currentResult) {
        $currentResult->free();
    }
    $currentStmt->close();

    if (!is_array($currentRow)) {
        throw new RuntimeException('Пользователь не найден.');
    }

    $currentUpgradeCounts = bober_normalize_upgrade_counts([
        'tapSmall' => $currentRow['upgrade_tap_small_count'] ?? 0,
        'tapBig' => $currentRow['upgrade_tap_big_count'] ?? 0,
        'energy' => $currentRow['upgrade_energy_count'] ?? 0,
        'tapHuge' => $currentRow['upgrade_tap_huge_count'] ?? 0,
        'regenBoost' => $currentRow['upgrade_regen_boost_count'] ?? 0,
        'energyHuge' => $currentRow['upgrade_energy_huge_count'] ?? 0,
        'clickRate' => $currentRow['upgrade_click_rate_count'] ?? 0,
    ]);
    $upgradeTapSmallCount = $currentUpgradeCounts['tapSmall'];
    $upgradeTapBigCount = $currentUpgradeCounts['tapBig'];
    $upgradeEnergyCount = $currentUpgradeCounts['energy'];
    $upgradeTapHugeCount = $currentUpgradeCounts['tapHuge'];
    $upgradeRegenBoostCount = $currentUpgradeCounts['regenBoost'];
    $upgradeEnergyHugeCount = $currentUpgradeCounts['energyHuge'];
    $upgradeClickRateCount = $currentUpgradeCounts['clickRate'];
    $plus = bober_calculate_plus_from_upgrade_counts($currentUpgradeCounts);
    $energyMax = bober_calculate_energy_max_from_upgrade_counts($currentUpgradeCounts);
    $energy = min($energyMax, $energy);
    $currentSkinState = bober_decode_skin_state((string) ($currentRow['skin'] ?? ''));
    $nextSkinState = $currentSkinState;
    $requestedEquippedSkinId = trim((string) ($requestedSkinState['equippedSkinId'] ?? ''));
    if ($requestedEquippedSkinId !== '' && in_array($requestedEquippedSkinId, (array) ($currentSkinState['ownedSkinIds'] ?? []), true)) {
        $nextSkinState['equippedSkinId'] = $requestedEquippedSkinId;
    }
    $skin = bober_encode_skin_state($nextSkinState);

    $stmt = $conn->prepare('UPDATE users SET score = ?, plus = ?, skin = ?, energy = ?, last_energy_update = ?, ENERGY_MAX = ?, upgrade_tap_small_count = ?, upgrade_tap_big_count = ?, upgrade_energy_count = ?, upgrade_tap_huge_count = ?, upgrade_regen_boost_count = ?, upgrade_energy_huge_count = ?, upgrade_click_rate_count = ? WHERE id = ?');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('iisisiiiiiiiii', $score, $plus, $skin, $energy, $lastEnergyUpdate, $energyMax, $upgradeTapSmallCount, $upgradeTapBigCount, $upgradeEnergyCount, $upgradeTapHugeCount, $upgradeRegenBoostCount, $upgradeEnergyHugeCount, $upgradeClickRateCount, $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }
    $stmt->close();

    bober_reconcile_top_reward_skins($conn);

    if (is_array($currentRow)) {
        $previousScore = max(0, (int) ($currentRow['score'] ?? 0));
        $previousUpgradeCounts = $currentUpgradeCounts;
        $previousPlus = bober_calculate_plus_from_upgrade_counts($previousUpgradeCounts);
        $previousEnergyMax = bober_calculate_energy_max_from_upgrade_counts($previousUpgradeCounts);
        $previousTapSmall = max(0, (int) ($currentRow['upgrade_tap_small_count'] ?? 0));
        $previousTapBig = max(0, (int) ($currentRow['upgrade_tap_big_count'] ?? 0));
        $previousEnergyPurchases = max(0, (int) ($currentRow['upgrade_energy_count'] ?? 0));
        $previousTapHuge = max(0, (int) ($currentRow['upgrade_tap_huge_count'] ?? 0));
        $previousRegenBoost = max(0, (int) ($currentRow['upgrade_regen_boost_count'] ?? 0));
        $previousEnergyHuge = max(0, (int) ($currentRow['upgrade_energy_huge_count'] ?? 0));
        $previousClickRate = max(0, (int) ($currentRow['upgrade_click_rate_count'] ?? 0));
        $previousSkinState = $currentSkinState;

        if ($upgradeTapSmallCount > $previousTapSmall) {
            bober_log_user_activity($conn, $userId, 'upgrade_tap_small_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение +1 к тапу.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousTapSmall,
                    'next_count' => $upgradeTapSmallCount,
                    'previous_plus' => $previousPlus,
                    'next_plus' => $plus,
                ],
            ]);
        }

        if ($upgradeTapBigCount > $previousTapBig) {
            bober_log_user_activity($conn, $userId, 'upgrade_tap_big_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение +5 к тапу.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousTapBig,
                    'next_count' => $upgradeTapBigCount,
                    'previous_plus' => $previousPlus,
                    'next_plus' => $plus,
                ],
            ]);
        }

        if ($upgradeEnergyCount > $previousEnergyPurchases || $energyMax > $previousEnergyMax) {
            bober_log_user_activity($conn, $userId, 'upgrade_energy_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение запаса энергии.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousEnergyPurchases,
                    'next_count' => $upgradeEnergyCount,
                    'previous_energy_max' => $previousEnergyMax,
                    'next_energy_max' => $energyMax,
                ],
            ]);
        }

        if ($upgradeTapHugeCount > $previousTapHuge) {
            bober_log_user_activity($conn, $userId, 'upgrade_tap_huge_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение +100 к тапу.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousTapHuge,
                    'next_count' => $upgradeTapHugeCount,
                    'previous_plus' => $previousPlus,
                    'next_plus' => $plus,
                ],
            ]);
        }

        if ($upgradeRegenBoostCount > $previousRegenBoost) {
            bober_log_user_activity($conn, $userId, 'upgrade_regen_boost_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение скорости восполнения энергии.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousRegenBoost,
                    'next_count' => $upgradeRegenBoostCount,
                    'regen_bonus_per_second' => max(0, ($upgradeRegenBoostCount - $previousRegenBoost) * 2),
                ],
            ]);
        }

        if ($upgradeEnergyHugeCount > $previousEnergyHuge) {
            bober_log_user_activity($conn, $userId, 'upgrade_energy_huge_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение +10000 к запасу энергии.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousEnergyHuge,
                    'next_count' => $upgradeEnergyHugeCount,
                    'previous_energy_max' => $previousEnergyMax,
                    'next_energy_max' => $energyMax,
                ],
            ]);
        }

        if ($upgradeClickRateCount > $previousClickRate) {
            bober_log_user_activity($conn, $userId, 'upgrade_click_rate_purchase', [
                'action_group' => 'progress',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Куплено улучшение лимита засчитываемых кликов.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'previous_count' => $previousClickRate,
                    'next_count' => $upgradeClickRateCount,
                    'next_click_rate_limit' => bober_calculate_click_rate_limit_from_upgrade_counts($currentUpgradeCounts),
                ],
            ]);
        }

        if (($previousSkinState['equippedSkinId'] ?? '') !== ($nextSkinState['equippedSkinId'] ?? '')) {
            bober_log_user_activity($conn, $userId, 'equip_skin', [
                'action_group' => 'skins',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Игрок сменил активный скин.',
                'meta' => [
                    'previous_skin_id' => $previousSkinState['equippedSkinId'] ?? '',
                    'next_skin_id' => $nextSkinState['equippedSkinId'] ?? '',
                ],
            ]);
        }

        $previousOwned = array_values(array_diff((array) ($previousSkinState['ownedSkinIds'] ?? []), (array) ($nextSkinState['ownedSkinIds'] ?? [])));
        $newOwned = array_values(array_diff((array) ($nextSkinState['ownedSkinIds'] ?? []), (array) ($previousSkinState['ownedSkinIds'] ?? [])));
        if (!empty($newOwned)) {
            bober_log_user_activity($conn, $userId, 'unlock_skin', [
                'action_group' => 'skins',
                'source' => 'save_state',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Игрок получил новый скин.',
                'score_delta' => $score - $previousScore,
                'coins_delta' => $score - $previousScore,
                'meta' => [
                    'unlocked_skin_ids' => array_values($newOwned),
                    'removed_skin_ids' => array_values($previousOwned),
                ],
            ]);
        }

        $questDeltas = [
            'scoreGain' => max(0, $score - $previousScore),
            'upgradeBuys' => max(0, $upgradeTapSmallCount - $previousTapSmall)
                + max(0, $upgradeTapBigCount - $previousTapBig)
                + max(0, $upgradeEnergyCount - $previousEnergyPurchases)
                + max(0, $upgradeTapHugeCount - $previousTapHuge)
                + max(0, $upgradeRegenBoostCount - $previousRegenBoost)
                + max(0, $upgradeEnergyHugeCount - $previousEnergyHuge)
                + max(0, $upgradeClickRateCount - $previousClickRate),
            'plusGain' => max(0, $plus - $previousPlus),
            'energyMaxGain' => max(0, $energyMax - $previousEnergyMax),
        ];
        bober_increment_user_quest_counters($conn, $userId, $questDeltas);
    }

    $clientLogResult = null;
    if (is_array($clientLogBatch)) {
        try {
            $clientLogResult = bober_store_client_log_batch($conn, $userId, $clientLogBatch, [
                'login' => $_SESSION['game_login'] ?? '',
            ]);
        } catch (Throwable $logError) {
            $clientLogResult = [
                'received' => 0,
                'accepted' => 0,
                'inserted' => 0,
                'duplicates' => 0,
                'warning' => bober_exception_message($logError),
            ];
        }
    }

    return [
        'clientLog' => $clientLogResult,
    ];
}

function bober_fetch_user_purchase_runtime_state($conn, $userId, $lockRow = false)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $sql = 'SELECT id, login, score, plus, skin, energy, last_energy_update, ENERGY_MAX, upgrade_tap_small_count, upgrade_tap_big_count, upgrade_energy_count, upgrade_tap_huge_count, upgrade_regen_boost_count, upgrade_energy_huge_count, upgrade_click_rate_count FROM users WHERE id = ? LIMIT 1';
    if ($lockRow) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось подготовить загрузку состояния игрока.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Не удалось загрузить состояние игрока.');
    }

    $result = $stmt->get_result();
    $row = $result instanceof mysqli_result ? $result->fetch_assoc() : null;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    $stmt->close();

    if (!is_array($row)) {
        throw new RuntimeException('Пользователь не найден.');
    }

    $catalog = bober_skin_catalog();
    $skinState = bober_decode_skin_state($row['skin'] ?? null);
    $upgradeCounts = bober_normalize_upgrade_counts([
        'tapSmall' => $row['upgrade_tap_small_count'] ?? 0,
        'tapBig' => $row['upgrade_tap_big_count'] ?? 0,
        'energy' => $row['upgrade_energy_count'] ?? 0,
        'tapHuge' => $row['upgrade_tap_huge_count'] ?? 0,
        'regenBoost' => $row['upgrade_regen_boost_count'] ?? 0,
        'energyHuge' => $row['upgrade_energy_huge_count'] ?? 0,
        'clickRate' => $row['upgrade_click_rate_count'] ?? 0,
    ]);
    $resolvedPlus = bober_calculate_plus_from_upgrade_counts($upgradeCounts);
    $resolvedEnergyMax = bober_calculate_energy_max_from_upgrade_counts($upgradeCounts);
    $flyBeaver = bober_fetch_fly_beaver_progress($conn, $userId);
    $economyProfile = bober_build_user_economy_profile([
        'score' => max(0, (int) ($row['score'] ?? 0)),
        'plus' => $resolvedPlus,
        'energyMax' => $resolvedEnergyMax,
        'flyBest' => max(0, (int) ($flyBeaver['bestScore'] ?? 0)),
        'ownedSkinIds' => array_values(array_unique(array_map('strval', (array) ($skinState['ownedSkinIds'] ?? [])))),
        'upgradeCounts' => $upgradeCounts,
        'catalog' => $catalog,
    ]);

    return [
        'row' => $row,
        'catalog' => $catalog,
        'skinState' => $skinState,
        'upgradeCounts' => $upgradeCounts,
        'plus' => $resolvedPlus,
        'energyMax' => $resolvedEnergyMax,
        'flyBeaver' => $flyBeaver,
        'economy' => $economyProfile,
    ];
}

function bober_apply_authoritative_shop_purchase($conn, $userId, array $payload)
{
    $userId = max(0, (int) $userId);
    if ($userId < 1) {
        throw new InvalidArgumentException('Некорректный идентификатор пользователя.');
    }

    $kind = strtolower(trim((string) ($payload['kind'] ?? '')));
    if ($kind !== 'upgrade' && $kind !== 'skin') {
        throw new InvalidArgumentException('Некорректный тип покупки.');
    }

    $conn->begin_transaction();

    try {
        $runtimeState = bober_fetch_user_purchase_runtime_state($conn, $userId, true);
        $row = $runtimeState['row'];
        $catalog = $runtimeState['catalog'];
        $skinState = $runtimeState['skinState'];
        $upgradeCounts = $runtimeState['upgradeCounts'];
        $currentScore = max(0, (int) ($row['score'] ?? 0));
        $currentEnergy = max(0, (int) ($row['energy'] ?? 0));
        $currentLastEnergyUpdate = (string) max(0, (int) ($row['last_energy_update'] ?? 0));
        $currentPlus = max(1, (int) ($runtimeState['plus'] ?? 1));
        $currentEnergyMax = max(5000, (int) ($runtimeState['energyMax'] ?? 5000));
        $economyProfile = is_array($runtimeState['economy'] ?? null) ? $runtimeState['economy'] : ['index' => 0, 'multiplier' => 1.0, 'factors' => []];

        $purchase = [
            'kind' => $kind,
            'itemId' => '',
            'quantity' => 1,
            'unitPrice' => 0,
            'totalPrice' => 0,
        ];

        $nextUpgradeCounts = $upgradeCounts;
        $nextSkinState = $skinState;
        $nextScore = $currentScore;
        $nextEnergy = min($currentEnergy, $currentEnergyMax);
        $nextLastEnergyUpdate = $currentLastEnergyUpdate;

        if ($kind === 'upgrade') {
            $upgradeType = trim((string) ($payload['upgradeType'] ?? ''));
            $quantity = max(1, min(250, (int) ($payload['quantity'] ?? 1)));
            $upgradeCatalog = bober_upgrade_shop_catalog();
            $upgradeMeta = is_array($upgradeCatalog[$upgradeType] ?? null) ? $upgradeCatalog[$upgradeType] : null;
            if (!$upgradeMeta) {
                throw new InvalidArgumentException('Неизвестный тип улучшения.');
            }

            $unitPrice = bober_calculate_effective_purchase_price((int) ($upgradeMeta['baseCost'] ?? 0), $economyProfile);
            $totalPrice = $unitPrice * $quantity;
            if ($currentScore < $totalPrice) {
                throw new RuntimeException('Не хватает коинов для покупки.');
            }

            $nextUpgradeCounts[$upgradeType] = max(0, (int) ($nextUpgradeCounts[$upgradeType] ?? 0)) + $quantity;
            $nextScore -= $totalPrice;

            $purchase = [
                'kind' => 'upgrade',
                'itemId' => $upgradeType,
                'quantity' => $quantity,
                'unitPrice' => $unitPrice,
                'totalPrice' => $totalPrice,
            ];
        } else {
            $skinId = trim((string) ($payload['skinId'] ?? ''));
            if ($skinId === '') {
                throw new InvalidArgumentException('Некорректный идентификатор скина.');
            }

            $skinConfig = is_array($catalog[$skinId] ?? null) ? $catalog[$skinId] : null;
            if (!$skinConfig) {
                throw new RuntimeException('Скин не найден.');
            }

            $issueMode = bober_normalize_skin_issue_mode($skinConfig['issue_mode'] ?? ($skinConfig['issueMode'] ?? ''), $skinConfig);
            if ($issueMode !== 'shop' || empty($skinConfig['available'])) {
                throw new RuntimeException('Этот скин нельзя купить в магазине.');
            }

            $ownedSkinIds = array_values(array_unique(array_map('strval', (array) ($skinState['ownedSkinIds'] ?? []))));
            if (in_array($skinId, $ownedSkinIds, true)) {
                throw new RuntimeException('Этот скин уже куплен.');
            }

            $unitPrice = bober_calculate_effective_purchase_price((int) ($skinConfig['price'] ?? 0), $economyProfile, $issueMode);
            if ($currentScore < $unitPrice) {
                throw new RuntimeException('Не хватает коинов для покупки.');
            }

            $nextScore -= $unitPrice;
            $nextSkinState['ownedSkinIds'][] = $skinId;
            $nextSkinState['ownedSkinIds'] = array_values(array_unique(array_map('strval', $nextSkinState['ownedSkinIds'])));
            if (!empty($payload['equip'])) {
                $nextSkinState['equippedSkinId'] = $skinId;
            }

            $purchase = [
                'kind' => 'skin',
                'itemId' => $skinId,
                'quantity' => 1,
                'unitPrice' => $unitPrice,
                'totalPrice' => $unitPrice,
            ];
        }

        $nextPlus = bober_calculate_plus_from_upgrade_counts($nextUpgradeCounts);
        $nextEnergyMax = bober_calculate_energy_max_from_upgrade_counts($nextUpgradeCounts);
        $nextEnergy = min($nextEnergy, $nextEnergyMax);
        $nextSkinJson = bober_encode_skin_state($nextSkinState);

        $stmt = $conn->prepare('UPDATE users SET score = ?, plus = ?, skin = ?, energy = ?, last_energy_update = ?, ENERGY_MAX = ?, upgrade_tap_small_count = ?, upgrade_tap_big_count = ?, upgrade_energy_count = ?, upgrade_tap_huge_count = ?, upgrade_regen_boost_count = ?, upgrade_energy_huge_count = ?, upgrade_click_rate_count = ? WHERE id = ?');
        if (!$stmt) {
            throw new RuntimeException('Не удалось подготовить сохранение покупки.');
        }

        $tapSmallCount = max(0, (int) ($nextUpgradeCounts['tapSmall'] ?? 0));
        $tapBigCount = max(0, (int) ($nextUpgradeCounts['tapBig'] ?? 0));
        $energyCount = max(0, (int) ($nextUpgradeCounts['energy'] ?? 0));
        $tapHugeCount = max(0, (int) ($nextUpgradeCounts['tapHuge'] ?? 0));
        $regenBoostCount = max(0, (int) ($nextUpgradeCounts['regenBoost'] ?? 0));
        $energyHugeCount = max(0, (int) ($nextUpgradeCounts['energyHuge'] ?? 0));
        $clickRateCount = max(0, (int) ($nextUpgradeCounts['clickRate'] ?? 0));

        $stmt->bind_param('iisisiiiiiiiii', $nextScore, $nextPlus, $nextSkinJson, $nextEnergy, $nextLastEnergyUpdate, $nextEnergyMax, $tapSmallCount, $tapBigCount, $energyCount, $tapHugeCount, $regenBoostCount, $energyHugeCount, $clickRateCount, $userId);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Не удалось сохранить покупку.');
        }
        $stmt->close();

        if ($kind === 'upgrade') {
            $upgradeType = $purchase['itemId'];
            $upgradeCatalog = bober_upgrade_shop_catalog();
            $upgradeMeta = is_array($upgradeCatalog[$upgradeType] ?? null) ? $upgradeCatalog[$upgradeType] : [];
            $meta = [
                'previous_count' => max(0, (int) ($upgradeCounts[$upgradeType] ?? 0)),
                'next_count' => max(0, (int) ($nextUpgradeCounts[$upgradeType] ?? 0)),
                'quantity' => max(1, (int) ($purchase['quantity'] ?? 1)),
                'unit_price' => max(0, (int) ($purchase['unitPrice'] ?? 0)),
                'total_price' => max(0, (int) ($purchase['totalPrice'] ?? 0)),
            ];

            if ($upgradeType === 'tapSmall' || $upgradeType === 'tapBig' || $upgradeType === 'tapHuge') {
                $meta['previous_plus'] = $currentPlus;
                $meta['next_plus'] = $nextPlus;
            } elseif ($upgradeType === 'energy' || $upgradeType === 'energyHuge') {
                $meta['previous_energy_max'] = $currentEnergyMax;
                $meta['next_energy_max'] = $nextEnergyMax;
            } elseif ($upgradeType === 'regenBoost') {
                $meta['regen_bonus_per_second'] = max(0, (int) ($purchase['quantity'] ?? 1) * 2);
            } elseif ($upgradeType === 'clickRate') {
                $meta['next_click_rate_limit'] = bober_calculate_click_rate_limit_from_upgrade_counts($nextUpgradeCounts);
            }

            bober_log_user_activity($conn, $userId, (string) ($upgradeMeta['actionType'] ?? 'upgrade_tap_small_purchase'), [
                'action_group' => 'progress',
                'source' => 'shop_purchase',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => (string) ($upgradeMeta['description'] ?? 'Куплено улучшение.'),
                'score_delta' => -max(0, (int) ($purchase['totalPrice'] ?? 0)),
                'coins_delta' => -max(0, (int) ($purchase['totalPrice'] ?? 0)),
                'meta' => $meta,
            ]);

            bober_increment_user_quest_counters($conn, $userId, [
                'scoreGain' => 0,
                'upgradeBuys' => max(1, (int) ($purchase['quantity'] ?? 1)),
                'plusGain' => max(0, $nextPlus - $currentPlus),
                'energyMaxGain' => max(0, $nextEnergyMax - $currentEnergyMax),
            ]);
        } else {
            bober_log_user_activity($conn, $userId, 'unlock_skin', [
                'action_group' => 'skins',
                'source' => 'shop_purchase',
                'login' => $_SESSION['game_login'] ?? '',
                'description' => 'Игрок купил новый скин.',
                'score_delta' => -max(0, (int) ($purchase['totalPrice'] ?? 0)),
                'coins_delta' => -max(0, (int) ($purchase['totalPrice'] ?? 0)),
                'meta' => [
                    'unlocked_skin_ids' => [$purchase['itemId']],
                    'unit_price' => max(0, (int) ($purchase['unitPrice'] ?? 0)),
                    'total_price' => max(0, (int) ($purchase['totalPrice'] ?? 0)),
                    'equipped_after_purchase' => !empty($payload['equip']),
                ],
            ]);

            if (!empty($payload['equip']) && (($skinState['equippedSkinId'] ?? '') !== ($nextSkinState['equippedSkinId'] ?? ''))) {
                bober_log_user_activity($conn, $userId, 'equip_skin', [
                    'action_group' => 'skins',
                    'source' => 'shop_purchase',
                    'login' => $_SESSION['game_login'] ?? '',
                    'description' => 'Игрок сменил активный скин.',
                    'meta' => [
                        'previous_skin_id' => $skinState['equippedSkinId'] ?? '',
                        'next_skin_id' => $nextSkinState['equippedSkinId'] ?? '',
                    ],
                ]);
            }

            bober_increment_user_quest_counters($conn, $userId, [
                'skinPurchases' => 1,
            ]);
        }

        $conn->commit();
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }

    $account = bober_fetch_account_snapshot($conn, $userId);

    return [
        'account' => $account,
        'purchase' => $purchase,
    ];
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

    $sessionFingerprint = hash('sha256', $userId . '|' . $ipAddress . '|' . (string) $userAgent);
    $now = time();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $lastRecord = is_array($_SESSION['bober_user_ip_record'] ?? null) ? $_SESSION['bober_user_ip_record'] : [];
        $lastFingerprint = trim((string) ($lastRecord['fingerprint'] ?? ''));
        $lastRecordedAt = max(0, (int) ($lastRecord['recordedAt'] ?? 0));
        if ($lastFingerprint === $sessionFingerprint && ($now - $lastRecordedAt) < 1800) {
            return false;
        }
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

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['bober_user_ip_record'] = [
            'fingerprint' => $sessionFingerprint,
            'recordedAt' => $now,
        ];
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
