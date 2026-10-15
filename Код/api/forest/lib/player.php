<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: профиль игрока, появление и состояние (ДЛ-10).
 * Сервер главный: позиция, энергия, монеты, рюкзак и поляна хранятся и проверяются здесь.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/formulas.php';
require_once __DIR__ . '/spawn.php';

/** Максимальная энергия по уровню выносливости. */
function wf_player_energy_max(int $staminaLvl): float
{
    $lvl = max(1, min((int) forest_config('hero.stamina_max_lvl'), $staminaLvl));

    return (float) forest_config('hero.energy_base') + (float) forest_config('hero.energy_per_stamina_lvl') * ($lvl - 1);
}

/** Вместимость рюкзака, кг, по уровню. */
function wf_player_pack_capacity(int $packLvl): float
{
    $lvl = max(1, min((int) forest_config('hero.pack_max_lvl'), $packLvl));

    return (float) forest_config('hero.pack_base_kg') + (float) forest_config('hero.pack_per_lvl_kg') * ($lvl - 1);
}

/** Строка игрока или null. $forUpdate блокирует строку в транзакции. */
function wf_player_row($conn, int $playerId, bool $forUpdate = false): ?array
{
    $sql = 'SELECT * FROM `forest_players` WHERE `player_id` = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '');
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Не удалось прочитать игрока.');
    }
    $stmt->bind_param('i', $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $row ?: null;
}

function wf_player_stats(array $row): array
{
    $stats = json_decode((string) ($row['stats_json'] ?? ''), true);

    return is_array($stats) ? $stats : [];
}

/** Запись изменения мира (поляна, пруд, клетка спавна). Возвращает true, если запись создана (false при дубле). */
function wf_change_insert($conn, string $kind, string $key, int $x, int $z, array $data, int $byPlayer): bool
{
    $cx = wf_wg_fdiv($x, 32);
    $cz = wf_wg_fdiv($z, 32);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = time();
    $stmt = $conn->prepare('INSERT IGNORE INTO `forest_chunk_changes` (`cx`, `cz`, `kind`, `ckey`, `data`, `by_player`, `ts`) VALUES (?, ?, ?, ?, ?, ?, ?)');
    if (!$stmt) {
        throw new RuntimeException('Не удалось записать изменение мира.');
    }
    $stmt->bind_param('iisssii', $cx, $cz, $kind, $key, $json, $byPlayer, $now);
    $stmt->execute();
    $created = $stmt->affected_rows > 0;
    $stmt->close();

    return $created;
}

/** Есть ли чужой спавн ближе $gap метров (квадратная рамка, чуть строже круга). */
function wf_spawn_neighbor_exists($conn, int $x, int $z, int $gap, int $exceptPlayer): bool
{
    $x1 = $x - $gap;
    $x2 = $x + $gap;
    $z1 = $z - $gap;
    $z2 = $z + $gap;
    $stmt = $conn->prepare('SELECT 1 FROM `forest_players` WHERE `spawn_x` BETWEEN ? AND ? AND `spawn_z` BETWEEN ? AND ? AND `player_id` <> ? AND `village_name` <> \'\' LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Не удалось проверить соседей.');
    }
    $stmt->bind_param('iiiii', $x1, $x2, $z1, $z2, $exceptPlayer);
    $stmt->execute();
    $stmt->store_result();
    $found = $stmt->num_rows > 0;
    $stmt->close();

    return $found;
}

/** Имя деревни. Заглушка до генератора имён из двух слов (ДЛ-15). */
function wf_player_village_name(int $playerId): string
{
    return 'Поляна ' . $playerId;
}

/**
 * Создаёт игрока и ставит его на поляну (идемпотентно: повторный вызов возвращает уже созданного).
 * Возвращает [строка игрока, создан ли сейчас].
 */
function wf_player_join($conn, int $playerId): array
{
    $existing = wf_player_row($conn, $playerId);
    if ($existing && (string) $existing['village_name'] !== '') {
        return [$existing, false];
    }

    $created = forest_tx($conn, function ($conn) use ($playerId) {
        $now = time();
        // Занимаем строку игрока: параллельный join вторым запросом получит affected_rows = 0.
        $stmt = $conn->prepare('INSERT IGNORE INTO `forest_players` (`player_id`, `created_at`, `last_seen`) VALUES (?, ?, ?)');
        $stmt->bind_param('iii', $playerId, $now, $now);
        $stmt->execute();
        $stmt->close();

        $row = wf_player_row($conn, $playerId, true);
        if (!$row) {
            throw new RuntimeException('Не удалось создать игрока.');
        }
        if ((string) $row['village_name'] !== '') {
            return false; // параллельный запрос уже поселил
        }

        $w = wf_wg_world();
        $isFree = static function (int $x, int $z, int $gap) use ($conn, $playerId): bool {
            if (wf_spawn_neighbor_exists($conn, $x, $z, $gap, $playerId)) {
                return false;
            }

            // Клетка 150×150 м занимается уникальной записью: два игрока не получат одно место даже при гонке.
            return wf_change_insert($conn, 'spawn_cell', wf_spawn_cell($x, $z), $x, $z, ['p' => $playerId], $playerId);
        };
        $spot = wf_spawn_find($w, $playerId, $isFree);
        if ($spot === null) {
            throw new RuntimeException('Не нашлось места для поляны.');
        }

        $x = $spot['x'];
        $z = $spot['z'];
        $pond = wf_spawn_pond($playerId, $x, $z);
        $clearR = (int) forest_config('world.clearing_radius_m');
        $pondClear = (int) forest_config('spawn.pond_clear_m');

        wf_change_insert($conn, 'clearing', 'spawn:' . $playerId, $x, $z, ['x' => $x, 'z' => $z, 'r' => $clearR], $playerId);
        wf_change_insert($conn, 'clearing', 'pond:' . $playerId, $pond['x'], $pond['z'], ['x' => $pond['x'], 'z' => $pond['z'], 'r' => $pondClear], $playerId);
        wf_change_insert($conn, 'pond', (string) $playerId, $pond['x'], $pond['z'], [
            'x' => $pond['x'], 'z' => $pond['z'], 'r' => $pond['r'], 'deg' => $pond['deg'],
            'dam' => ['x' => $pond['damX'], 'z' => $pond['damZ'], 'level' => 1],
        ], $playerId);

        $dam = $conn->prepare('INSERT IGNORE INTO `forest_dams` (`player_id`, `level`, `durability`, `calc_ts`, `state`) VALUES (?, 1, 100, ?, \'ok\')');
        $dam->bind_param('ii', $playerId, $now);
        $dam->execute();
        $dam->close();

        $name = wf_player_village_name($playerId);
        $energy = wf_player_energy_max(1);
        $coins = (int) forest_config('hero.start_coins');
        $hotbar = json_encode(array_values((array) forest_config('spawn.start_hotbar')), JSON_UNESCAPED_UNICODE);
        $stats = json_encode(['last_a' => 0, 'spawn_attempt' => $spot['attempt']], JSON_UNESCAPED_UNICODE);
        $grace = $now + (int) forest_config('dam.offline_grace_h') * 3600;
        $ry = 0.0;
        $upd = $conn->prepare('UPDATE `forest_players` SET `village_name` = ?, `spawn_x` = ?, `spawn_z` = ?, `pos_x` = ?, `pos_z` = ?, `pos_ry` = ?, `energy` = ?, `energy_ts` = ?, `coins` = ?, `hotbar_json` = ?, `stats_json` = ?, `last_action` = ?, `last_seen` = ?, `grace_until` = ? WHERE `player_id` = ?');
        $fx = (float) $x;
        $fz = (float) $z;
        $upd->bind_param('sddddddiissiiii', $name, $fx, $fz, $fx, $fz, $ry, $energy, $now, $coins, $hotbar, $stats, $now, $now, $grace, $playerId);
        $upd->execute();
        $upd->close();

        forest_log($conn, $playerId, 'join', ['x' => $x, 'z' => $z, 'attempt' => $spot['attempt']]);

        return true;
    });

    return [wf_player_row($conn, $playerId), (bool) $created];
}

/** Содержимое рюкзака: [item_key => qty] и вес, кг. */
function wf_player_pack($conn, int $playerId): array
{
    $items = [];
    $stmt = $conn->prepare('SELECT `item_key`, `qty` FROM `forest_inventory` WHERE `player_id` = ? AND `container` = \'pack\' AND `qty` > 0');
    $stmt->bind_param('i', $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $items[(string) $r['item_key']] = (int) $r['qty'];
    }
    $stmt->close();

    $w = forest_config('hero.weights_kg');
    $kg = 0.0;
    foreach ($items as $key => $qty) {
        $per = $key === 'log' ? ((float) $w['log_min'] + (float) $w['log_max']) / 2 : (float) ($w[$key] ?? 1);
        $kg += $per * $qty;
    }

    return ['items' => $items, 'kg' => round($kg, 2)];
}

/** Лёгкая часть состояния (приходит в каждом sync). */
function wf_player_light_state(array $row, array $pack): array
{
    $lv = wf_level_from_xp((int) $row['xp']);

    return [
        'energy' => round((float) $row['energy'], 2),
        'energy_max' => wf_player_energy_max((int) $row['stamina_lvl']),
        'coins' => (int) $row['coins'],
        'weight' => $pack['kg'],
        'capacity' => wf_player_pack_capacity((int) $row['pack_lvl']),
        'pack' => (object) $pack['items'],
        'level' => $lv['level'],
        'xp_into' => $lv['into'],
        'xp_need' => $lv['need'],
    ];
}

/** Поляна, пруд и плотина игрока (записи forest_chunk_changes). */
function wf_player_world_records($conn, int $playerId): array
{
    $out = ['clearings' => [], 'pond' => null];
    $stmt = $conn->prepare('SELECT `kind`, `data` FROM `forest_chunk_changes` WHERE `by_player` = ? AND `kind` IN (\'clearing\', \'pond\')');
    $stmt->bind_param('i', $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $data = json_decode((string) $r['data'], true);
        if (!is_array($data)) {
            continue;
        }
        if ($r['kind'] === 'clearing') {
            $out['clearings'][] = $data;
        } else {
            $out['pond'] = $data;
        }
    }
    $stmt->close();

    return $out;
}

/** Срубленные деревья и выпавшие предметы рядом с игроком (до потока изменений ДЛ-21: только при входе). */
function wf_player_world_near($conn, array $row): array
{
    $cfg = forest_config('trees.chop');
    $rad = (int) $cfg['state_radius_chunks'];
    $cx = wf_wg_fdiv((int) floor((float) $row['pos_x']), 32);
    $cz = wf_wg_fdiv((int) floor((float) $row['pos_z']), 32);
    $x0 = $cx - $rad;
    $x1 = $cx + $rad;
    $z0 = $cz - $rad;
    $z1 = $cz + $rad;
    $limit = (int) $cfg['state_max'];
    $felled = [];
    $stmt = $conn->prepare('SELECT `ckey` FROM `forest_chunk_changes` WHERE `kind` = \'tree_felled\' AND `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ? ORDER BY `id` DESC LIMIT ?');
    $stmt->bind_param('iiiii', $x0, $x1, $z0, $z1, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $felled[] = (string) $r['ckey'];
    }
    $stmt->close();

    $drops = [];
    $now = time();
    $me = (int) $row['player_id'];
    $stmt = $conn->prepare('SELECT `id`, `x`, `z`, `item_key`, `qty` FROM `forest_drops` WHERE `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ? AND `expires_at` > ? AND (`owner_id` = ? OR `owner_until` <= ?) ORDER BY `id` DESC LIMIT 200');
    $stmt->bind_param('iiiiiii', $x0, $x1, $z0, $z1, $now, $me, $now);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $drops[] = ['id' => (int) $r['id'], 'x' => round((float) $r['x'], 2), 'z' => round((float) $r['z'], 2), 'item' => (string) $r['item_key'], 'qty' => (int) $r['qty']];
    }
    $stmt->close();

    return ['felled' => $felled, 'drops' => $drops];
}

/** Полное состояние для входа (forest/state). */
function wf_player_full_state($conn, array $row): array
{
    $id = (int) $row['player_id'];
    $pack = wf_player_pack($conn, $id);
    $hotbar = json_decode((string) ($row['hotbar_json'] ?? ''), true);
    $records = wf_player_world_records($conn, $id);
    $near = wf_player_world_near($conn, $row);

    return [
        'player_id' => $id,
        'village' => ['name' => (string) $row['village_name'], 'x' => (float) $row['spawn_x'], 'z' => (float) $row['spawn_z']],
        'pos' => ['x' => (float) $row['pos_x'], 'z' => (float) $row['pos_z'], 'ry' => (float) $row['pos_ry']],
        'state' => wf_player_light_state($row, $pack),
        'upgrades' => ['axe' => (int) $row['axe_lvl'], 'pack' => (int) $row['pack_lvl'], 'stamina' => (int) $row['stamina_lvl'], 'lamp' => (int) $row['lamp_lvl']],
        'hotbar' => is_array($hotbar) ? array_values($hotbar) : [],
        'tutorial_step' => (int) $row['tutorial_step'],
        'last_a' => (int) (wf_player_stats($row)['last_a'] ?? 0),
        'clearings' => $records['clearings'],
        'pond' => $records['pond'],
        'felled' => $near['felled'],
        'drops' => $near['drops'],
        'server_ts' => time(),
        'server_ms' => forest_now_ms(),
    ];
}
