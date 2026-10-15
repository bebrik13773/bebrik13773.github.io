<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: серверные действия пакета sync (ДЛ-11: chop_done). Работают внутри транзакции с блокировкой строки игрока:
 * получают и меняют $row / $stats в памяти, а запись строки игрока делает sync.php один раз в конце.
 */
require_once __DIR__ . '/player.php';
require_once __DIR__ . '/chop.php';

/** Деревья «не существуют» внутри расчищенных кругов (clearing). */
function wf_action_in_clearing($conn, int $cx, int $cz, float $x, float $z): bool
{
    $scan = (int) forest_config('trees.chop.clear_scan_chunks');
    $x0 = $cx - $scan;
    $x1 = $cx + $scan;
    $z0 = $cz - $scan;
    $z1 = $cz + $scan;
    $stmt = $conn->prepare('SELECT `data` FROM `forest_chunk_changes` WHERE `kind` = \'clearing\' AND `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ?');
    $stmt->bind_param('iiii', $x0, $x1, $z0, $z1);
    $stmt->execute();
    $res = $stmt->get_result();
    $inside = false;
    while ($res && ($r = $res->fetch_assoc())) {
        $d = json_decode((string) $r['data'], true);
        if (is_array($d) && isset($d['x'], $d['z'], $d['r']) && hypot($x - (float) $d['x'], $z - (float) $d['z']) <= (float) $d['r']) {
            $inside = true;
            break;
        }
    }
    $stmt->close();

    return $inside;
}

function wf_action_tree_felled($conn, string $id): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM `forest_chunk_changes` WHERE `kind` = \'tree_felled\' AND `ckey` = ? LIMIT 1');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = $res && $res->fetch_row() !== null;
    $stmt->close();

    return $found;
}

/** Запись срубленного дерева (cx, cz из id дерева, чтобы выборка по чанку была точной). false = уже срублено. */
function wf_action_insert_felled($conn, int $cx, int $cz, string $id, int $byPlayer, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = time();
    $stmt = $conn->prepare('INSERT IGNORE INTO `forest_chunk_changes` (`cx`, `cz`, `kind`, `ckey`, `data`, `by_player`, `ts`) VALUES (?, ?, \'tree_felled\', ?, ?, ?, ?)');
    $stmt->bind_param('iissii', $cx, $cz, $id, $json, $byPlayer, $now);
    $stmt->execute();
    $created = $stmt->affected_rows > 0;
    $stmt->close();

    return $created;
}

function wf_action_add_drop($conn, int $cx, int $cz, float $x, float $z, string $item, int $qty, int $owner, int $now): int
{
    $until = $now + (int) forest_config('trees.chop.drop_owner_s');
    $exp = $now + (int) forest_config('trees.chop.drop_ttl_s');
    $stmt = $conn->prepare('INSERT INTO `forest_drops` (`x`, `z`, `cx`, `cz`, `item_key`, `qty`, `owner_id`, `owner_until`, `expires_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ddiisiiii', $x, $z, $cx, $cz, $item, $qty, $owner, $until, $exp);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
}

/**
 * Действие chop_done. $ctx: now, now_ms, prev (x, z), new (x, z), dt, allow.
 * Меняет $row (энергия, xp, level, coins) и $stats (бюджет тапов, страйки). Возвращает запись результата.
 */
function wf_action_chop($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $id = isset($action['tree']) ? (string) $action['tree'] : '';
    $res = ['a' => $a, 'ok' => false, 'tree' => $id];
    $parts = explode(':', $id);
    if (count($parts) !== 3 || !preg_match('/^-?\d+$/', $parts[0]) || !preg_match('/^-?\d+$/', $parts[1]) || !preg_match('/^\d+$/', $parts[2]) || (int) $parts[2] > 63) {
        $res['error'] = 'no_tree';

        return $res;
    }
    [$cx, $cz, $ci] = [(int) $parts[0], (int) $parts[1], (int) $parts[2]];
    $w = wf_wg_world();
    $cell = wf_wg_cell($w, $cx, $cz, $ci);
    [$tx, $tz] = wf_chop_tree_pos($cell);

    $px = isset($action['px']) && is_numeric($action['px']) ? (float) $action['px'] : $ctx['new']['x'];
    $pz = isset($action['pz']) && is_numeric($action['pz']) ? (float) $action['pz'] : $ctx['new']['z'];
    $hits = isset($action['hits']) && is_numeric($action['hits']) ? (int) $action['hits'] : 0;
    $t0 = isset($action['t0']) && is_numeric($action['t0']) ? (float) $action['t0'] : null;
    $t1 = isset($action['t1']) && is_numeric($action['t1']) ? (float) $action['t1'] : null;
    $window = ($t0 !== null && $t1 !== null && $t1 >= $t0) ? $t1 - $t0 : null;

    $isTree = (int) $cell['kind'] === WF_WG_KIND_TREE;
    $speciesId = $isTree ? (string) ($w['species_ids'][$cell['species']] ?? '') : '';
    $tier = wf_wg_tier_dm($w, (int) $cell['X'], (int) $cell['Z']);
    $hp = $isTree ? wf_chop_hp($speciesId, (int) $cell['size'], $tier) : 0.0;
    $power = wf_chop_power((int) $row['axe_lvl']);
    $need = $hp > 0 ? wf_chop_need($hp, $power) : 1;
    $cost = wf_chop_cost($need);

    $emax = wf_player_energy_max((int) $row['stamina_lvl']);
    $energy = wf_energy_now((float) $row['energy'], (int) $row['energy_ts'], $ctx['now'], $emax);

    $bucket = isset($stats['tap']) && is_array($stats['tap']) && count($stats['tap']) === 2 ? $stats['tap'] : [(float) forest_config('limits.tap_bucket'), $ctx['now_ms']];
    $take = wf_tap_take((float) $bucket[0], (int) $bucket[1], $ctx['now_ms'], (float) $need);

    $cleared = $isTree && wf_action_in_clearing($conn, $cx, $cz, $tx, $tz);
    $felled = $isTree && !$cleared && wf_action_tree_felled($conn, $id);

    $v = wf_chop_validate([
        'kind' => $cell['kind'], 'cleared' => $cleared, 'felled' => $felled,
        'dist_tree' => hypot($px - $tx, $pz - $tz),
        'dist_prev' => hypot($px - $ctx['prev']['x'], $pz - $ctx['prev']['z']),
        'dist_new' => hypot($px - $ctx['new']['x'], $pz - $ctx['new']['z']),
        'allow' => $ctx['allow'], 'hits' => $hits, 'need' => $need,
        'energy' => $energy, 'cost' => $cost, 'tokens' => $take['tokens'], 'window_ms' => $window,
    ]);

    // Энергия и ведро тапов фиксируются в любом случае (ведро: при отказе только пополнение).
    $stats['tap'] = [round($v['ok'] ? $take['tokens'] : $take['tokens'], 3), $ctx['now_ms']];
    $row['energy'] = $energy;
    $row['energy_ts'] = $ctx['now'];

    if (!$v['ok']) {
        $res['error'] = $v['error'];
        if ($v['error'] === 'tree_gone') {
            // Дерево уже срублено другим: теряем половину потраченной энергии (раздел 7.6).
            $row['energy'] = max(0.0, $energy - $cost * (float) forest_config('trees.chop.gone_energy_k'));
        }
        if ($v['cheat']) {
            $s = wf_strikes_add(isset($stats['strikes']) && is_array($stats['strikes']) ? $stats['strikes'] : [], $ctx['now']);
            $stats['strikes'] = $s['list'];
            forest_log($conn, (int) $row['player_id'], 'chop_' . $v['error'], ['tree' => $id, 'hits' => $hits, 'need' => $need, 'strikes' => $s['count']]);
            if ($s['level'] === 'block') {
                $row['blocked_until'] = $ctx['now'] + (int) forest_config('limits.block_seconds');
                forest_log($conn, (int) $row['player_id'], 'block', ['strikes' => $s['count']]);
            }
        }

        return $res;
    }

    // Гонка двух игроков: победит тот, чья запись создалась (уникальный ключ kind + ckey).
    $created = wf_action_insert_felled($conn, $cx, $cz, $id, (int) $row['player_id'], ['x' => round($tx, 1), 'z' => round($tz, 1)]);
    if (!$created) {
        $res['error'] = 'tree_gone';
        $row['energy'] = max(0.0, $energy - $cost * (float) forest_config('trees.chop.gone_energy_k'));

        return $res;
    }

    $row['energy'] = max(0.0, $energy - $cost);
    $k = wf_strikes_reward_k(wf_strikes_active(isset($stats['strikes']) && is_array($stats['strikes']) ? $stats['strikes'] : [], $ctx['now']));
    $logs = wf_chop_logs($speciesId, (int) $cell['size']);
    if ($k < 1.0) {
        $logs = max(1, (int) floor($logs * $k));
    }
    $items = [['item' => 'log', 'qty' => $logs]];
    $coins = 0;
    if (!empty($cell['treasure'])) {
        $t = wf_chop_treasure($w, $cx, $cz, $ci);
        if (isset($t['coins'])) {
            $coins = (int) $t['coins'];
        } else {
            $items[] = ['item' => $t['item'], 'qty' => (int) $t['qty']];
        }
    }
    if ($coins > 0) {
        $row['coins'] = (int) $row['coins'] + $coins;
    }

    // Выпадение: каждое бревно отдельным предметом вокруг пня, остальное (находки) одной записью.
    $count = 0;
    foreach ($items as $it) {
        $count += $it['item'] === 'log' ? $it['qty'] : 1;
    }
    $points = wf_chop_drop_points($w, $cx, $cz, $ci, $tx, $tz, $count);
    $drops = [];
    $pi = 0;
    foreach ($items as $it) {
        $units = $it['item'] === 'log' ? $it['qty'] : 1;
        $qty = $it['item'] === 'log' ? 1 : $it['qty'];
        for ($u = 0; $u < $units; $u++) {
            $p = $points[$pi++];
            $drops[] = ['id' => wf_action_add_drop($conn, $cx, $cz, $p['x'], $p['z'], $it['item'], $qty, (int) $row['player_id'], $ctx['now']), 'x' => $p['x'], 'z' => $p['z'], 'item' => $it['item'], 'qty' => $qty];
        }
    }

    // Опыт по прочности дерева.
    $xp = (int) round($hp);
    $row['xp'] = (int) $row['xp'] + $xp;
    $row['level'] = wf_level_from_xp((int) $row['xp'])['level'];
    $stats['trees'] = (int) ($stats['trees'] ?? 0) + 1;

    $res['ok'] = true;
    $res['reward'] = ['log' => $logs] + ($coins > 0 ? ['coins' => $coins] : []);
    $res['drops'] = $drops;
    $res['xp'] = $xp;

    return $res;
}
