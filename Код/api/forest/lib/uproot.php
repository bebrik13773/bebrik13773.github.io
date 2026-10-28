<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: выкорчёвывание пня киркой (#64). Пень остаётся от срубленного дерева навсегда, пока его не выкорчуют.
 * Пень = запись tree_felled; выкорчеванный = запись stump_uprooted с тем же ключом (id дерева).
 * Чистые функции (без БД): wf_uproot_need, wf_uproot_cost, wf_uproot_validate. Действие wf_action_uproot работает внутри
 * транзакции sync (блокировка строки игрока) и меняет $row / $stats в памяти.
 */
require_once __DIR__ . '/chop.php';

/** Сколько ударов киркой нужно для пня размера $size (0..2). Зеркало shared/formulas.js uprootNeed. */
function wf_uproot_need(int $size): int
{
    $hits = (array) forest_config('trees.uproot.hits');

    return (int) ($hits[max(0, min(2, $size))] ?? 6);
}

/** Энергия за выкорчёвывание. Зеркало shared/formulas.js uprootCost. */
function wf_uproot_cost(int $size): float
{
    return wf_uproot_need($size) * (float) forest_config('trees.uproot.cost_hit');
}

/** Есть ли инструмент в быстрых слотах игрока ($hotbarJson = столбец hotbar_json). */
function wf_uproot_has_tool($hotbarJson): bool
{
    $list = json_decode((string) $hotbarJson, true);

    return is_array($list) && in_array((string) forest_config('trees.uproot.tool'), array_map('strval', $list), true);
}

/**
 * Проверка без БД. Вход ($c): kind (1 = дерево), cleared (расчищенный круг), felled (дерево срублено, пень есть),
 * uprooted (уже выкорчеван), has_tool, dist_stump, dist_prev, dist_new, allow, hits, need, energy, cost, tokens, window_ms.
 * Возвращает ['ok', 'error', 'cheat'] как wf_chop_validate.
 */
function wf_uproot_validate(array $c): array
{
    $fail = static function (string $code, bool $cheat): array {
        return ['ok' => false, 'error' => $code, 'cheat' => $cheat];
    };
    if ((int) $c['kind'] !== WF_WG_KIND_TREE) {
        return $fail('no_stump', true);
    }
    if (!empty($c['cleared'])) {
        return $fail('no_stump', false);
    }
    if (empty($c['has_tool'])) {
        return $fail('no_tool', true);
    }
    $reach = (float) forest_config('limits.chop_reach_m');
    if ((float) $c['dist_stump'] > $reach + 1e-9) {
        return $fail('too_far', true);
    }
    if ((float) $c['dist_prev'] > (float) $c['allow'] + 1e-9 || (float) $c['dist_new'] > (float) $c['allow'] + 1e-9) {
        return $fail('too_far', true);
    }
    if (!empty($c['uprooted'])) {
        return $fail('stump_gone', false);
    }
    if (empty($c['felled'])) {
        return $fail('no_stump', false); // дерево ещё растёт: пня нет
    }
    $need = (int) $c['need'];
    $hits = (int) $c['hits'];
    if ($hits < $need) {
        return $fail('too_fast', true);
    }
    if ($hits > $need * (int) forest_config('trees.chop.hit_cap_k') + 10) {
        return $fail('bad_request', true);
    }
    if ($c['window_ms'] !== null) {
        $minMs = ($need - 1) / (float) forest_config('limits.max_taps_per_s') * 1000.0 * (float) forest_config('trees.chop.min_window_k');
        if ((float) $c['window_ms'] < $minMs - 1e-9) {
            return $fail('too_fast', true);
        }
    }
    if ((float) $c['energy'] + 1e-9 < (float) $c['cost']) {
        return $fail('no_energy', false);
    }
    if ((float) $c['tokens'] + 1e-9 < $need) {
        return $fail('too_fast', true);
    }

    return ['ok' => true, 'error' => null, 'cheat' => false];
}

function wf_uproot_is_uprooted($conn, string $id): bool
{
    $stmt = $conn->prepare('SELECT 1 FROM `forest_chunk_changes` WHERE `kind` = \'stump_uprooted\' AND `ckey` = ? LIMIT 1');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = $res && $res->fetch_row() !== null;
    $stmt->close();

    return $found;
}

/** Запись выкорчёванного пня. false = уже выкорчеван (гонка двух игроков: уникальный ключ kind + ckey). */
function wf_uproot_insert($conn, int $cx, int $cz, string $id, int $byPlayer, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = time();
    $stmt = $conn->prepare('INSERT IGNORE INTO `forest_chunk_changes` (`cx`, `cz`, `kind`, `ckey`, `data`, `by_player`, `ts`) VALUES (?, ?, \'stump_uprooted\', ?, ?, ?, ?)');
    $stmt->bind_param('iissii', $cx, $cz, $id, $json, $byPlayer, $now);
    $stmt->execute();
    $created = $stmt->affected_rows > 0;
    $stmt->close();

    return $created;
}

/**
 * Действие uproot: tree (id дерева «cx:cz:ci»), hits, t0, t1, px, pz. $ctx как у wf_action_chop.
 * Меняет $row (энергия, xp) и $stats (бюджет тапов, страйки, счётчик). Возвращает запись результата с type = 'uproot'.
 */
function wf_action_uproot($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $id = isset($action['tree']) ? (string) $action['tree'] : '';
    $res = ['a' => $a, 'ok' => false, 'type' => 'uproot', 'tree' => $id];
    $parts = explode(':', $id);
    if (count($parts) !== 3 || !preg_match('/^-?\d+$/', $parts[0]) || !preg_match('/^-?\d+$/', $parts[1]) || !preg_match('/^\d+$/', $parts[2]) || (int) $parts[2] > 63) {
        $res['error'] = 'no_stump';

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
    $size = $isTree ? (int) $cell['size'] : 0;
    $need = wf_uproot_need($size);
    $cost = wf_uproot_cost($size);

    $emax = wf_player_energy_max((int) $row['stamina_lvl']);
    $energy = wf_energy_now((float) $row['energy'], (int) $row['energy_ts'], $ctx['now'], $emax);

    $bucket = isset($stats['tap']) && is_array($stats['tap']) && count($stats['tap']) === 2 ? $stats['tap'] : [(float) forest_config('limits.tap_bucket'), $ctx['now_ms']];
    $take = wf_tap_take((float) $bucket[0], (int) $bucket[1], $ctx['now_ms'], (float) $need);

    $cleared = $isTree && wf_action_in_clearing($conn, $cx, $cz, $tx, $tz);
    $felled = $isTree && !$cleared && wf_action_tree_felled($conn, $id);
    $uprooted = $felled && wf_uproot_is_uprooted($conn, $id);

    $v = wf_uproot_validate([
        'kind' => $cell['kind'], 'cleared' => $cleared, 'felled' => $felled, 'uprooted' => $uprooted,
        'has_tool' => wf_uproot_has_tool($row['hotbar_json'] ?? ''),
        'dist_stump' => hypot($px - $tx, $pz - $tz),
        'dist_prev' => hypot($px - $ctx['prev']['x'], $pz - $ctx['prev']['z']),
        'dist_new' => hypot($px - $ctx['new']['x'], $pz - $ctx['new']['z']),
        'allow' => $ctx['allow'], 'hits' => $hits, 'need' => $need,
        'energy' => $energy, 'cost' => $cost, 'tokens' => $take['tokens'], 'window_ms' => $window,
    ]);

    // Энергия и ведро тапов фиксируются в любом случае (при отказе ведро только пополняется).
    $stats['tap'] = [round($take['tokens'], 3), $ctx['now_ms']];
    $row['energy'] = $energy;
    $row['energy_ts'] = $ctx['now'];

    if (!$v['ok']) {
        $res['error'] = $v['error'];
        if ($v['error'] === 'stump_gone') {
            $row['energy'] = max(0.0, $energy - $cost * (float) forest_config('trees.uproot.gone_energy_k'));
        }
        if ($v['cheat']) {
            $s = wf_strikes_add(isset($stats['strikes']) && is_array($stats['strikes']) ? $stats['strikes'] : [], $ctx['now']);
            $stats['strikes'] = $s['list'];
            forest_log($conn, (int) $row['player_id'], 'uproot_' . $v['error'], ['tree' => $id, 'hits' => $hits, 'need' => $need, 'strikes' => $s['count']]);
            if ($s['level'] === 'block') {
                $row['blocked_until'] = $ctx['now'] + (int) forest_config('limits.block_seconds');
                forest_log($conn, (int) $row['player_id'], 'block', ['strikes' => $s['count']]);
            }
        }

        return $res;
    }

    // Гонка двух игроков: побеждает тот, чья запись создалась.
    $created = wf_uproot_insert($conn, $cx, $cz, $id, (int) $row['player_id'], ['x' => round($tx, 1), 'z' => round($tz, 1)]);
    if (!$created) {
        $res['error'] = 'stump_gone';
        $row['energy'] = max(0.0, $energy - $cost * (float) forest_config('trees.uproot.gone_energy_k'));

        return $res;
    }

    $row['energy'] = max(0.0, $energy - $cost);
    $stats['uprooted'] = (int) ($stats['uprooted'] ?? 0) + 1;
    $res['ok'] = true;

    return $res;
}
