<?php
/**
 * Дикий Лес: подключение к БД, конфиг и схема с версиями (ДЛ-03).
 *
 * - Все таблицы с префиксом `forest_`, явно ENGINE=InnoDB (на хостинге по умолчанию MyISAM,
 *   а транзакции и блокировки строк нужны для рубки, обмена, найма).
 * - Версия схемы хранится в `forest_meta` (`schema_version`), миграции идемпотентны и идут по порядку.
 * - Чтобы не тратить лимит запросов, проверка схемы кешируется файлом-замком (как у кликера).
 */

require_once __DIR__ . '/db.php';

const FOREST_SCHEMA_VERSION = 3;

/** Конфиг баланса (Код/api/forest/config.php), читается один раз за запрос. */
function forest_config($path = null)
{
    static $config = null;

    if ($config === null) {
        $loaded = require dirname(__DIR__) . '/forest/config.php';
        if (!is_array($loaded)) {
            throw new RuntimeException('Конфиг леса повреждён.');
        }
        $config = $loaded;
    }

    if ($path === null || $path === '') {
        return $config;
    }

    $node = $config;
    foreach (explode('.', (string) $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) {
            throw new RuntimeException('В конфиге леса нет параметра ' . $path . '.');
        }
        $node = $node[$key];
    }

    return $node;
}

/** Одно соединение на запрос (на хостинге не больше 16 соединений на пользователя БД). */
function forest_db()
{
    static $conn = null;

    if ($conn instanceof mysqli) {
        return $conn;
    }

    $conn = bober_db_connect();
    forest_ensure_schema($conn);

    return $conn;
}

/** Список таблиц схемы: имя => версия миграции, в которой она появилась. */
function forest_expected_tables()
{
    return [
        'forest_meta' => 1, 'forest_players' => 1, 'forest_inventory' => 1, 'forest_drops' => 1,
        'forest_chunk_changes' => 1, 'forest_log' => 1,
        'forest_buildings' => 2, 'forest_saplings' => 2, 'forest_road_tiles' => 2,
        'forest_routes' => 2, 'forest_dams' => 2,
        'forest_helpers' => 3, 'forest_trades' => 3, 'forest_presence' => 3, 'forest_chat' => 3,
        'forest_ratings' => 3, 'forest_seasons' => 3, 'forest_event_log' => 3,
    ];
}

function forest_ddl($conn, $sql, $what)
{
    if (!$conn->query($sql)) {
        throw new RuntimeException('Не удалось создать ' . $what . ': ' . $conn->error);
    }
}

function forest_read_schema_version($conn)
{
    try {
        $res = $conn->query("SELECT `v` FROM `forest_meta` WHERE `k` = 'schema_version' LIMIT 1");
    } catch (mysqli_sql_exception $e) {
        return 0; // таблицы ещё нет
    }
    if (!$res instanceof mysqli_result) {
        return 0;
    }
    $row = $res->fetch_row();
    $res->free();

    return $row ? (int) $row[0] : 0;
}

function forest_write_meta($conn, $key, $value)
{
    $stmt = $conn->prepare('INSERT INTO `forest_meta` (`k`, `v`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось записать служебное значение леса.');
    }
    $value = (string) $value;
    $stmt->bind_param('ss', $key, $value);
    $stmt->execute();
    $stmt->close();
}

/** Создаёт схему или обновляет её до актуальной версии. Безопасно вызывать повторно. */
function forest_ensure_schema($conn)
{
    static $done = false;
    if ($done) {
        return;
    }

    if (bober_schema_guard_is_fresh('forest', 600)) {
        $done = true;
        return;
    }

    $locked = false;
    $lockRes = $conn->query("SELECT GET_LOCK('forest_schema', 10)");
    if ($lockRes instanceof mysqli_result) {
        $lockRow = $lockRes->fetch_row();
        $lockRes->free();
        $locked = $lockRow && (int) $lockRow[0] === 1;
    }

    try {
        $current = forest_read_schema_version($conn);

        $migrations = [
            1 => 'forest_migration_1',
            2 => 'forest_migration_2',
            3 => 'forest_migration_3',
        ];

        foreach ($migrations as $version => $fn) {
            if ($current >= $version) {
                continue;
            }
            $fn($conn);
            forest_write_meta($conn, 'schema_version', $version);
            $current = $version;
        }

        forest_fix_engines($conn);
        bober_schema_guard_touch('forest');
        $done = true;
    } finally {
        if ($locked) {
            $conn->query("SELECT RELEASE_LOCK('forest_schema')");
        }
    }
}

/** Таблицы леса, оказавшиеся не InnoDB, переводим в InnoDB (иначе нет транзакций). */
function forest_fix_engines($conn)
{
    $res = $conn->query(
        "SELECT table_name, engine FROM information_schema.tables "
        . "WHERE table_schema = DATABASE() AND table_name LIKE 'forest\\_%'"
    );
    if (!$res instanceof mysqli_result) {
        return;
    }

    $bad = [];
    while ($row = $res->fetch_row()) {
        if (strcasecmp((string) $row[1], 'InnoDB') !== 0) {
            $bad[] = (string) $row[0];
        }
    }
    $res->free();

    foreach ($bad as $table) {
        if (preg_match('/^forest_[a-z_]+$/', $table)) {
            forest_ddl($conn, "ALTER TABLE `{$table}` ENGINE=InnoDB", 'перевод ' . $table . ' в InnoDB');
        }
    }
}

const FOREST_TABLE_TAIL = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

/** Миграция 1: ядро (мета, игроки, инвентарь, выпавшие предметы, журнал изменений мира, журнал аномалий). */
function forest_migration_1($conn)
{
    $t = FOREST_TABLE_TAIL;

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_meta` (
        `k` VARCHAR(64) NOT NULL PRIMARY KEY,
        `v` TEXT NOT NULL
    ){$t}", 'forest_meta');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_players` (
        `player_id` INT NOT NULL PRIMARY KEY,
        `village_name` VARCHAR(48) NOT NULL DEFAULT '',
        `spawn_x` FLOAT NOT NULL DEFAULT 0,
        `spawn_z` FLOAT NOT NULL DEFAULT 0,
        `pos_x` FLOAT NOT NULL DEFAULT 0,
        `pos_z` FLOAT NOT NULL DEFAULT 0,
        `pos_ry` FLOAT NOT NULL DEFAULT 0,
        `energy` FLOAT NOT NULL DEFAULT 100,
        `energy_ts` INT UNSIGNED NOT NULL DEFAULT 0,
        `coins` BIGINT NOT NULL DEFAULT 0,
        `xp` BIGINT NOT NULL DEFAULT 0,
        `level` INT NOT NULL DEFAULT 1,
        `axe_lvl` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `pack_lvl` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `stamina_lvl` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `lamp_lvl` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `gear_json` TEXT NULL,
        `hotbar_json` TEXT NULL,
        `tutorial_step` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        `last_action` INT UNSIGNED NOT NULL DEFAULT 0,
        `last_seen` INT UNSIGNED NOT NULL DEFAULT 0,
        `grace_until` INT UNSIGNED NOT NULL DEFAULT 0,
        `blocked_until` INT UNSIGNED NOT NULL DEFAULT 0,
        `stats_json` TEXT NULL,
        `created_at` INT UNSIGNED NOT NULL DEFAULT 0,
        KEY `idx_forest_players_spawn` (`spawn_x`, `spawn_z`),
        KEY `idx_forest_players_seen` (`last_seen`)
    ){$t}", 'forest_players');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_inventory` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `player_id` INT NOT NULL,
        `container` VARCHAR(24) NOT NULL DEFAULT 'pack',
        `item_key` VARCHAR(32) NOT NULL,
        `qty` INT NOT NULL DEFAULT 0,
        UNIQUE KEY `uq_forest_inventory` (`player_id`, `container`, `item_key`)
    ){$t}", 'forest_inventory');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_drops` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `x` FLOAT NOT NULL,
        `z` FLOAT NOT NULL,
        `cx` INT NOT NULL,
        `cz` INT NOT NULL,
        `item_key` VARCHAR(32) NOT NULL,
        `qty` INT NOT NULL DEFAULT 1,
        `owner_id` INT NOT NULL DEFAULT 0,
        `owner_until` INT UNSIGNED NOT NULL DEFAULT 0,
        `expires_at` INT UNSIGNED NOT NULL,
        KEY `idx_forest_drops_chunk` (`cx`, `cz`),
        KEY `idx_forest_drops_expires` (`expires_at`)
    ){$t}", 'forest_drops');

    // ckey вместо key: `key` — зарезервированное слово SQL.
    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_chunk_changes` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `cx` INT NOT NULL,
        `cz` INT NOT NULL,
        `kind` VARCHAR(24) NOT NULL,
        `ckey` VARCHAR(64) NOT NULL,
        `data` TEXT NULL,
        `by_player` INT NOT NULL DEFAULT 0,
        `ts` INT UNSIGNED NOT NULL,
        UNIQUE KEY `uq_forest_change` (`kind`, `ckey`),
        KEY `idx_forest_changes_chunk` (`cx`, `cz`, `id`)
    ){$t}", 'forest_chunk_changes');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_log` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `player_id` INT NOT NULL DEFAULT 0,
        `kind` VARCHAR(32) NOT NULL,
        `data_json` TEXT NULL,
        `ts` INT UNSIGNED NOT NULL,
        KEY `idx_forest_log_player` (`player_id`, `ts`),
        KEY `idx_forest_log_kind` (`kind`, `ts`)
    ){$t}", 'forest_log');
}

/** Миграция 2: деревня и мир (постройки, саженцы, дороги, маршруты, плотины). */
function forest_migration_2($conn)
{
    $t = FOREST_TABLE_TAIL;

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_buildings` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `player_id` INT NOT NULL,
        `type` VARCHAR(24) NOT NULL,
        `x` FLOAT NOT NULL,
        `z` FLOAT NOT NULL,
        `cx` INT NOT NULL,
        `cz` INT NOT NULL,
        `rot` FLOAT NOT NULL DEFAULT 0,
        `level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `state` VARCHAR(16) NOT NULL DEFAULT 'blueprint',
        `progress` INT NOT NULL DEFAULT 0,
        `needed` INT NOT NULL DEFAULT 1,
        `recipe` VARCHAR(24) NULL,
        `recipe_qty` INT NOT NULL DEFAULT 0,
        `started_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `helper_id` BIGINT UNSIGNED NULL,
        `created_at` INT UNSIGNED NOT NULL,
        `updated_at` INT UNSIGNED NOT NULL,
        KEY `idx_forest_buildings_player` (`player_id`),
        KEY `idx_forest_buildings_chunk` (`cx`, `cz`)
    ){$t}", 'forest_buildings');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_saplings` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `player_id` INT NOT NULL,
        `species` VARCHAR(24) NOT NULL,
        `x` FLOAT NOT NULL,
        `z` FLOAT NOT NULL,
        `planted_at` INT UNSIGNED NOT NULL,
        `grow_seconds` INT UNSIGNED NOT NULL,
        `growth` FLOAT NOT NULL DEFAULT 0,
        `state` VARCHAR(12) NOT NULL DEFAULT 'growing',
        `last_calc` INT UNSIGNED NOT NULL,
        KEY `idx_forest_saplings_player` (`player_id`)
    ){$t}", 'forest_saplings');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_road_tiles` (
        `tx` INT NOT NULL,
        `tz` INT NOT NULL,
        `surface` VARCHAR(12) NOT NULL DEFAULT 'dirt',
        `built_by` INT NOT NULL DEFAULT 0,
        `updated_at` INT UNSIGNED NOT NULL,
        PRIMARY KEY (`tx`, `tz`)
    ){$t}", 'forest_road_tiles');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_routes` (
        `player_id` INT NOT NULL PRIMARY KEY,
        `connected` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `length_m` INT UNSIGNED NOT NULL DEFAULT 0,
        `quality` FLOAT NOT NULL DEFAULT 1,
        `calc_at` INT UNSIGNED NOT NULL DEFAULT 0
    ){$t}", 'forest_routes');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_dams` (
        `player_id` INT NOT NULL PRIMARY KEY,
        `level` TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `durability` FLOAT NOT NULL DEFAULT 100,
        `calc_ts` INT UNSIGNED NOT NULL,
        `state` VARCHAR(12) NOT NULL DEFAULT 'ok',
        `breach_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `flood_until` INT UNSIGNED NOT NULL DEFAULT 0,
        `repairs` INT UNSIGNED NOT NULL DEFAULT 0
    ){$t}", 'forest_dams');
}

/** Миграция 3: люди и мета (помощники, торги, присутствие, чат, рейтинг, сезоны, события). */
function forest_migration_3($conn)
{
    $t = FOREST_TABLE_TAIL;

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_helpers` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(48) NOT NULL,
        `skill_chop` FLOAT NOT NULL DEFAULT 1,
        `carry_kg` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
        `courage` FLOAT NOT NULL DEFAULT 0.5,
        `greed` FLOAT NOT NULL DEFAULT 0.5,
        `trait` VARCHAR(24) NOT NULL DEFAULT '',
        `employer_id` INT NULL,
        `wage` INT NOT NULL DEFAULT 0,
        `hired_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `paid_until` INT UNSIGNED NOT NULL DEFAULT 0,
        `mode` VARCHAR(12) NOT NULL DEFAULT 'stay',
        `area_x` FLOAT NOT NULL DEFAULT 0,
        `area_z` FLOAT NOT NULL DEFAULT 0,
        `area_r` FLOAT NOT NULL DEFAULT 0,
        `warehouse_id` BIGINT UNSIGNED NULL,
        `bed_id` BIGINT UNSIGNED NULL,
        `pos_x` FLOAT NOT NULL DEFAULT 0,
        `pos_z` FLOAT NOT NULL DEFAULT 0,
        `mood` FLOAT NOT NULL DEFAULT 70,
        `state` VARCHAR(16) NOT NULL DEFAULT 'idle',
        `calc_ts` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_day` INT UNSIGNED NOT NULL DEFAULT 0,
        KEY `idx_forest_helpers_employer` (`employer_id`),
        KEY `idx_forest_helpers_day` (`created_day`)
    ){$t}", 'forest_helpers');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_trades` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `player_id` INT NOT NULL,
        `buyer_type` VARCHAR(12) NOT NULL,
        `buyer_seed` INT UNSIGNED NOT NULL,
        `item_key` VARCHAR(32) NOT NULL,
        `qty` INT NOT NULL DEFAULT 1,
        `base_price` INT NOT NULL DEFAULT 0,
        `patience` TINYINT NOT NULL DEFAULT 3,
        `mood` FLOAT NOT NULL DEFAULT 0,
        `round` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        `state` VARCHAR(12) NOT NULL DEFAULT 'waiting',
        `created_at` INT UNSIGNED NOT NULL,
        `expires_at` INT UNSIGNED NOT NULL,
        KEY `idx_forest_trades_player` (`player_id`, `state`)
    ){$t}", 'forest_trades');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_presence` (
        `player_id` INT NOT NULL PRIMARY KEY,
        `x` FLOAT NOT NULL,
        `z` FLOAT NOT NULL,
        `ry` FLOAT NOT NULL DEFAULT 0,
        `anim` VARCHAR(12) NOT NULL DEFAULT 'idle',
        `emote` VARCHAR(8) NOT NULL DEFAULT '',
        `cell` INT NOT NULL DEFAULT 0,
        `ts` INT UNSIGNED NOT NULL,
        KEY `idx_forest_presence_cell` (`cell`, `ts`)
    ){$t}", 'forest_presence');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_chat` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `channel` VARCHAR(16) NOT NULL DEFAULT 'city',
        `player_id` INT NOT NULL,
        `text` VARCHAR(400) NOT NULL,
        `ts` INT UNSIGNED NOT NULL,
        `flags` TINYINT UNSIGNED NOT NULL DEFAULT 0,
        KEY `idx_forest_chat_channel` (`channel`, `id`)
    ){$t}", 'forest_chat');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_seasons` (
        `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `starts_at` INT UNSIGNED NOT NULL,
        `ends_at` INT UNSIGNED NOT NULL,
        `name` VARCHAR(32) NOT NULL DEFAULT '',
        `rewards_json` TEXT NULL
    ){$t}", 'forest_seasons');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_ratings` (
        `player_id` INT NOT NULL,
        `season_id` INT NOT NULL DEFAULT 0,
        `score` INT NOT NULL DEFAULT 0,
        `parts_json` TEXT NULL,
        `updated_at` INT UNSIGNED NOT NULL DEFAULT 0,
        PRIMARY KEY (`player_id`, `season_id`),
        KEY `idx_forest_ratings_top` (`season_id`, `score`)
    ){$t}", 'forest_ratings');

    forest_ddl($conn, "CREATE TABLE IF NOT EXISTS `forest_event_log` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `event_key` VARCHAR(32) NOT NULL,
        `started_at` INT UNSIGNED NOT NULL,
        `ended_at` INT UNSIGNED NOT NULL DEFAULT 0,
        `params_json` TEXT NULL,
        KEY `idx_forest_event_key` (`event_key`, `started_at`)
    ){$t}", 'forest_event_log');
}
