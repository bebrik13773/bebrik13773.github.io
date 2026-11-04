<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: система построек (ДЛ-16). Чистые функции (стоимость, правила места, наклон, стадии, возврат, тапы, списание из запасов)
 * без БД, чтобы проверять их по тест-векторам (tests/vectors/build.json, зеркало shared/build.js); действия build_place, build_tap,
 * build_cancel и build_demolish работают внутри транзакции sync (строка игрока заблокирована). Цены, ресурсы, места и прогресс решает сервер.
 */
require_once __DIR__ . '/crate.php';
require_once __DIR__ . '/actions.php';

/** Ключи ресурсов постройки в конфиге → предметы. */
const WF_BUILD_ITEMS = ['logs' => 'log', 'planks' => 'plank', 'ingots' => 'ingot'];

// ── Чистые функции ──────────────────────────────────────────────────────

/** Описание постройки из конфига или null, если такого типа нет. */
function wf_build_spec(string $type): ?array
{
    $all = (array) forest_config('buildings');
    if (!preg_match('/^[a-z_]{1,24}$/', $type) || !isset($all[$type]) || !is_array($all[$type]) || !isset($all[$type]['taps'])) {
        return null;
    }

    return $all[$type];
}

/** Все типы построек по порядку конфига. */
function wf_build_types(): array
{
    $out = [];
    foreach ((array) forest_config('buildings') as $key => $spec) {
        if (is_array($spec) && isset($spec['taps'])) {
            $out[] = (string) $key;
        }
    }

    return $out;
}

/** Ресурсы постройки предметами: ['log' => 30, ...] (только больше нуля). Монеты отдельно, wf_build_coins. */
function wf_build_cost(array $spec): array
{
    $out = [];
    foreach (WF_BUILD_ITEMS as $key => $item) {
        $n = (int) ($spec[$key] ?? 0);
        if ($n > 0) {
            $out[$item] = $n;
        }
    }

    return $out;
}

function wf_build_coins(array $spec): int
{
    return max(0, (int) ($spec['coins'] ?? 0));
}

/** Сколько построек всего можно иметь на уровне игрока. */
function wf_build_max_total(int $level): int
{
    $n = (int) forest_config('buildings.max_total_base') + (int) forest_config('buildings.max_total_per_level') * (max(1, $level) - 1);

    return min((int) forest_config('buildings.max_total_cap'), $n);
}

/** Перекрываются ли два круга с зазором. */
function wf_build_circles_overlap(float $x1, float $z1, float $r1, float $x2, float $z2, float $r2, float $gap): bool
{
    return hypot($x1 - $x2, $z1 - $z2) < $r1 + $r2 + $gap - 1e-9;
}

/** Наклон площадки, градусы: по высотам в центре и в четырёх точках на краю габарита (радиус $r). */
function wf_build_slope_deg(array $heights, float $r): float
{
    if (count($heights) < 2 || $r <= 0) {
        return 0.0;
    }

    return rad2deg(atan((max($heights) - min($heights)) / (2.0 * $r)));
}

/** Видимая стадия по прогрессу: foundation, walls, roof, ready. */
function wf_build_stage(int $progress, int $needed): string
{
    if ($needed <= 0 || $progress >= $needed) {
        return 'ready';
    }
    $f = max(0, $progress) / $needed;
    if ($f >= (float) forest_config('buildings.stage_roof_at')) {
        return 'roof';
    }

    return $f >= (float) forest_config('buildings.stage_walls_at') ? 'walls' : 'foundation';
}

/**
 * Доля возврата ресурсов: отмена до 20% прогресса — 100%, дальше до готовности — 70%; снос готовой — 50%.
 * Возвращает null, если в таком состоянии отменять или сносить нельзя.
 */
function wf_build_refund_k(string $state, int $progress, int $needed): ?float
{
    if ($state === 'ready') {
        return (float) forest_config('buildings.demolish_refund');
    }
    if ($state !== 'blueprint' && $state !== 'building') {
        return null;
    }
    $f = $needed > 0 ? max(0, $progress) / $needed : 0.0;

    return $f <= (float) forest_config('buildings.cancel_full_refund_until') + 1e-9 ? 1.0 : (float) forest_config('buildings.cancel_partial_refund');
}

/** Возврат: ресурсы и монеты, умноженные на долю (целые, вниз). Возвращает ['items' => [...], 'coins' => n]. */
function wf_build_refund(array $spec, float $k): array
{
    $items = [];
    foreach (wf_build_cost($spec) as $item => $qty) {
        $n = (int) floor($qty * $k + 1e-9);
        if ($n > 0) {
            $items[$item] = $n;
        }
    }

    return ['items' => $items, 'coins' => (int) floor(wf_build_coins($spec) * $k + 1e-9)];
}

/** Опыт за достроенную постройку: стоимость ресурсов по базовым ценам плюс монеты (раздел ДЛ-10). */
function wf_build_xp(array $spec): int
{
    $prices = (array) forest_config('economy.prices');
    $xp = wf_build_coins($spec);
    foreach (wf_build_cost($spec) as $item => $qty) {
        $xp += (int) round($qty * (float) ($prices[$item] ?? 0));
    }

    return $xp;
}

/**
 * Сколько тапов стройки принять: не больше запрошенного, оставшегося до готовности, токенов бюджета тапов и энергии.
 * Возвращает ['n' => принято, 'limit' => none|tokens|energy].
 */
function wf_build_tap_plan(int $want, int $left, float $tokens, float $energy, float $cost): array
{
    $byTokens = (int) floor(max(0.0, $tokens) + 1e-9);
    $byEnergy = $cost > 0 ? (int) floor(max(0.0, $energy) / $cost + 1e-9) : $want;
    $cap = max(0, min($want, $left));
    $n = max(0, min($cap, $byTokens, $byEnergy));
    $limit = 'none';
    if ($n < $cap) {
        $limit = $byEnergy < $byTokens ? 'energy' : 'tokens';
    }

    return ['n' => $n, 'limit' => $limit];
}

/**
 * Что списать из запасов. $stock: [контейнер => [предмет => штук]]. Порядок: рюкзак, ящик, склады (bld:N по возрастанию).
 * Возвращает список [контейнер, предмет, штук] или null, если не хватает.
 */
function wf_build_plan_take(array $stock, array $need): ?array
{
    $order = [];
    foreach (['pack', 'crate'] as $c) {
        if (isset($stock[$c])) {
            $order[] = $c;
        }
    }
    $rest = [];
    foreach (array_keys($stock) as $c) {
        if (strpos((string) $c, 'bld:') === 0) {
            $rest[] = (string) $c;
        }
    }
    sort($rest, SORT_NATURAL);
    $order = array_merge($order, $rest);

    $plan = [];
    foreach ($need as $item => $qty) {
        $left = (int) $qty;
        foreach ($order as $c) {
            if ($left <= 0) {
                break;
            }
            $have = (int) ($stock[$c][$item] ?? 0);
            $take = min($have, $left);
            if ($take > 0) {
                $plan[] = [$c, (string) $item, $take];
                $left -= $take;
            }
        }
        if ($left > 0) {
            return null;
        }
    }

    return $plan;
}

/**
 * Проверка места без БД. Вход ($c): level, unlock (с какого уровня), total / max_total (построек всего), same / max_same (такого типа),
 * inside_world, city, dist_village, foreign_dist (до ближайшего центра чужой деревни, INF если нет), water, slope_deg, trees (деревья, камни, руда в габарите),
 * overlap (пересекается с постройкой или объектом поляны). Возвращает код причины или null, если можно.
 */
function wf_build_place_error(array $c): ?string
{
    if ((int) $c['level'] < (int) $c['unlock']) {
        return 'locked';
    }
    if ((int) $c['total'] >= (int) $c['max_total']) {
        return 'limit';
    }
    if ((int) $c['same'] >= (int) $c['max_same']) {
        return 'limit_type';
    }
    if (empty($c['inside_world'])) {
        return 'world_edge';
    }
    if (!empty($c['city'])) {
        return 'city';
    }
    if ((float) $c['dist_village'] > (float) forest_config('buildings.max_dist_from_village_m') + 1e-9) {
        return 'far_from_village';
    }
    if ((float) $c['foreign_dist'] < (float) forest_config('buildings.min_dist_foreign_village_m') - 1e-9) {
        return 'foreign';
    }
    if (!empty($c['water'])) {
        return 'water';
    }
    if ((float) $c['slope_deg'] > (float) forest_config('buildings.max_slope_deg') + 1e-9) {
        return 'slope';
    }
    if ((int) $c['trees'] > 0) {
        return 'trees';
    }
    if (!empty($c['overlap'])) {
        return 'overlap';
    }

    return null;
}

/** Поворот в градусах: кратно шагу rot_step_deg, 0…359. */
function wf_build_rot(float $deg): int
{
    $step = max(1, (int) forest_config('buildings.rot_step_deg'));
    $n = (int) round($deg / $step) * $step;

    return (($n % 360) + 360) % 360;
}

/** Каталог для клиента: список построек с ценой, габаритом и порогом уровня. */
function wf_build_catalog(): array
{
    $out = [];
    foreach (wf_build_types() as $type) {
        $s = (array) wf_build_spec($type);
        $out[] = [
            'type' => $type, 'logs' => (int) $s['logs'], 'planks' => (int) $s['planks'], 'ingots' => (int) $s['ingots'], 'coins' => (int) $s['coins'],
            'taps' => (int) $s['taps'], 'r' => (float) $s['r'], 'h' => (float) $s['h'], 'unlock' => (int) $s['unlock'], 'max' => (int) $s['max'],
        ];
    }

    return $out;
}

/** Правила места и возврата для клиента (чтобы призрак подсвечивался так же, как решит сервер). */
function wf_build_rules(int $level): array
{
    return [
        'max_dist' => (float) forest_config('buildings.max_dist_from_village_m'),
        'foreign' => (float) forest_config('buildings.min_dist_foreign_village_m'),
        'slope' => (float) forest_config('buildings.max_slope_deg'),
        'gap' => (float) forest_config('buildings.gap_m'),
        'trunk' => (float) forest_config('buildings.tree_trunk_m'),
        'reach' => (float) forest_config('buildings.tap_reach_m'),
        'rot_step' => (int) forest_config('buildings.rot_step_deg'),
        'stage_walls' => (float) forest_config('buildings.stage_walls_at'),
        'stage_roof' => (float) forest_config('buildings.stage_roof_at'),
        'cancel_full' => (float) forest_config('buildings.cancel_full_refund_until'),
        'cancel_partial' => (float) forest_config('buildings.cancel_partial_refund'),
        'demolish' => (float) forest_config('buildings.demolish_refund'),
        'max_total' => wf_build_max_total($level),
        'tap_cost' => (float) forest_config('hero.cost_build_tap'),
    ];
}

// ── Работа с БД ─────────────────────────────────────────────────────────

/** Строка постройки в формате ответа. */
function wf_build_out(array $r, int $me): array
{
    return [
        'id' => (int) $r['id'], 'type' => (string) $r['type'],
        'x' => round((float) $r['x'], 2), 'z' => round((float) $r['z'], 2), 'rot' => (int) round((float) $r['rot']),
        'level' => (int) $r['level'], 'state' => (string) $r['state'],
        'progress' => (int) $r['progress'], 'needed' => (int) $r['needed'],
        'mine' => (int) $r['player_id'] === $me, 'owner' => (int) $r['player_id'],
    ];
}

/** Постройки (своих и чужих) в радиусе чанков вокруг игрока: для входа в игру. */
function wf_build_near($conn, array $row): array
{
    $rad = (int) forest_config('buildings.state_radius_chunks');
    $cx = wf_wg_fdiv((int) floor((float) $row['pos_x']), 32);
    $cz = wf_wg_fdiv((int) floor((float) $row['pos_z']), 32);
    $x0 = $cx - $rad;
    $x1 = $cx + $rad;
    $z0 = $cz - $rad;
    $z1 = $cz + $rad;
    $limit = (int) forest_config('buildings.state_max');
    $me = (int) $row['player_id'];
    $out = [];
    $stmt = $conn->prepare('SELECT `id`, `player_id`, `type`, `x`, `z`, `rot`, `level`, `state`, `progress`, `needed` FROM `forest_buildings` WHERE `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ? ORDER BY `id` LIMIT ?');
    $stmt->bind_param('iiiii', $x0, $x1, $z0, $z1, $limit);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $out[] = wf_build_out($r, $me);
    }
    $stmt->close();

    return $out;
}

/** Одна постройка по id (с блокировкой строки) или null. */
function wf_build_load($conn, int $id, bool $forUpdate = true): ?array
{
    $stmt = $conn->prepare('SELECT * FROM `forest_buildings` WHERE `id` = ? LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''));
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $r = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return $r ?: null;
}

/** Общий замок размещения: два игрока не поставят пересекающиеся постройки одновременно. Держится до конца транзакции sync. */
function wf_build_lock($conn): void
{
    $conn->query("INSERT IGNORE INTO `forest_meta` (`k`, `v`) VALUES ('build_lock', '0')");
    $res = $conn->query("SELECT `v` FROM `forest_meta` WHERE `k` = 'build_lock' FOR UPDATE");
    if ($res instanceof mysqli_result) {
        $res->free();
    }
}

/** Все запасы игрока для списания: [контейнер => [предмет => штук]] (рюкзак, ящик, склады деревни). */
function wf_build_stock($conn, int $playerId): array
{
    $stock = [];
    $stmt = $conn->prepare('SELECT `container`, `item_key`, `qty` FROM `forest_inventory` WHERE `player_id` = ? AND `qty` > 0 AND (`container` = \'pack\' OR `container` = \'crate\' OR `container` LIKE \'bld:%\')');
    $stmt->bind_param('i', $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $stock[(string) $r['container']][(string) $r['item_key']] = (int) $r['qty'];
    }
    $stmt->close();

    return $stock;
}

/** Свободные объекты поляны, мешающие стройке: круги [x, z, r]. Пруд, плотина, ящик, подвода. */
function wf_build_fixed_circles($conn, array $row): array
{
    $spots = wf_village_spots((float) $row['spawn_x'], (float) $row['spawn_z']);
    $circles = [[$spots['crate'][0], $spots['crate'][1], 0.9], [$spots['buyer'][0], $spots['buyer'][1], 1.6]];
    $pond = wf_player_world_records($conn, (int) $row['player_id'])['pond'];
    if (is_array($pond) && isset($pond['x'], $pond['z'], $pond['r'])) {
        $circles[] = [(float) $pond['x'], (float) $pond['z'], (float) $pond['r'] + 0.6];
        $dam = isset($pond['dam']) && is_array($pond['dam']) ? $pond['dam'] : null;
        if ($dam !== null && isset($dam['x'], $dam['z'])) {
            $circles[] = [(float) $dam['x'], (float) $dam['z'], 2.4];
        }
    }

    return $circles;
}

/** Пересекается ли габарит с другой постройкой (любого игрока) или с объектом поляны. */
function wf_build_overlaps($conn, array $row, float $x, float $z, float $r, int $exceptId = 0): bool
{
    $gap = (float) forest_config('buildings.gap_m');
    foreach (wf_build_fixed_circles($conn, $row) as $c) {
        if (wf_build_circles_overlap($x, $z, $r, $c[0], $c[1], $c[2], $gap)) {
            return true;
        }
    }
    $span = 12.0;
    $cx0 = wf_wg_fdiv((int) floor($x - $span), 32);
    $cx1 = wf_wg_fdiv((int) floor($x + $span), 32);
    $cz0 = wf_wg_fdiv((int) floor($z - $span), 32);
    $cz1 = wf_wg_fdiv((int) floor($z + $span), 32);
    $stmt = $conn->prepare('SELECT `id`, `type`, `x`, `z` FROM `forest_buildings` WHERE `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ? AND `id` <> ?');
    $stmt->bind_param('iiiii', $cx0, $cx1, $cz0, $cz1, $exceptId);
    $stmt->execute();
    $res = $stmt->get_result();
    $hit = false;
    while ($res && ($o = $res->fetch_assoc())) {
        $spec = wf_build_spec((string) $o['type']);
        $or = $spec ? (float) $spec['r'] : 3.0;
        if (wf_build_circles_overlap($x, $z, $r, (float) $o['x'], (float) $o['z'], $or, $gap)) {
            $hit = true;
            break;
        }
    }
    $stmt->close();

    return $hit;
}

/** Расстояние до центра ближайшей чужой деревни (INF, если рядом нет). */
function wf_build_foreign_dist($conn, int $playerId, float $x, float $z): float
{
    $d = (float) forest_config('buildings.min_dist_foreign_village_m') + 1.0;
    $x0 = $x - $d;
    $x1 = $x + $d;
    $z0 = $z - $d;
    $z1 = $z + $d;
    $stmt = $conn->prepare('SELECT `spawn_x`, `spawn_z` FROM `forest_players` WHERE `spawn_x` BETWEEN ? AND ? AND `spawn_z` BETWEEN ? AND ? AND `player_id` <> ? AND `village_name` <> \'\'');
    $stmt->bind_param('ddddi', $x0, $x1, $z0, $z1, $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $best = INF;
    while ($res && ($r = $res->fetch_assoc())) {
        $best = min($best, hypot($x - (float) $r['spawn_x'], $z - (float) $r['spawn_z']));
    }
    $stmt->close();

    return $best;
}

/** Высота земли в метрах. */
function wf_build_height_m(array $w, float $x, float $z): float
{
    return wf_wg_height_dm($w, (int) floor($x * 10), (int) floor($z * 10)) / 10.0;
}

/** Пять точек замера: центр и четыре на краю габарита. */
function wf_build_sample_points(float $x, float $z, float $r): array
{
    return [[$x, $z], [$x + $r, $z], [$x - $r, $z], [$x, $z + $r], [$x, $z - $r]];
}

/** Сколько деревьев (не срубленных и не в расчищенных кругах), камней и руды попадает в габарит. */
function wf_build_scan_blockers($conn, array $w, float $x, float $z, float $r): int
{
    $trunk = (float) forest_config('buildings.tree_trunk_m');
    $reach = $r + $trunk + 1.0;
    $gx0 = (int) floor(($x - $reach) / 4.0);
    $gx1 = (int) floor(($x + $reach) / 4.0);
    $gz0 = (int) floor(($z - $reach) / 4.0);
    $gz1 = (int) floor(($z + $reach) / 4.0);
    $cx0 = wf_wg_fdiv($gx0, 8);
    $cx1 = wf_wg_fdiv($gx1, 8);
    $cz0 = wf_wg_fdiv($gz0, 8);
    $cz1 = wf_wg_fdiv($gz1, 8);

    // Срубленные деревья в этих чанках.
    $felled = [];
    $stmt = $conn->prepare('SELECT `ckey` FROM `forest_chunk_changes` WHERE `kind` = \'tree_felled\' AND `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ?');
    $stmt->bind_param('iiii', $cx0, $cx1, $cz0, $cz1);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($q = $res->fetch_assoc())) {
        $felled[(string) $q['ckey']] = true;
    }
    $stmt->close();

    // Расчищенные круги (поляна, пруд) рядом.
    $scan = (int) forest_config('trees.chop.clear_scan_chunks');
    $clears = [];
    $a0 = $cx0 - $scan;
    $a1 = $cx1 + $scan;
    $b0 = $cz0 - $scan;
    $b1 = $cz1 + $scan;
    $stmt = $conn->prepare('SELECT `data` FROM `forest_chunk_changes` WHERE `kind` = \'clearing\' AND `cx` BETWEEN ? AND ? AND `cz` BETWEEN ? AND ?');
    $stmt->bind_param('iiii', $a0, $a1, $b0, $b1);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($q = $res->fetch_assoc())) {
        $d = json_decode((string) $q['data'], true);
        if (is_array($d) && isset($d['x'], $d['z'], $d['r'])) {
            $clears[] = [(float) $d['x'], (float) $d['z'], (float) $d['r']];
        }
    }
    $stmt->close();

    $count = 0;
    for ($gz = $gz0; $gz <= $gz1; $gz++) {
        for ($gx = $gx0; $gx <= $gx1; $gx++) {
            $cx = wf_wg_fdiv($gx, 8);
            $cz = wf_wg_fdiv($gz, 8);
            $ci = ($gz - $cz * 8) * 8 + ($gx - $cx * 8);
            $cell = wf_wg_cell($w, $cx, $cz, $ci);
            $kind = (int) $cell['kind'];
            if ($kind === WF_WG_KIND_NONE) {
                continue;
            }
            [$tx, $tz] = wf_chop_tree_pos($cell);
            $rad = $kind === WF_WG_KIND_TREE ? $trunk : 0.5;
            if (hypot($tx - $x, $tz - $z) >= $r + $rad) {
                continue;
            }
            $cleared = false;
            foreach ($clears as $c) {
                if (hypot($tx - $c[0], $tz - $c[1]) <= $c[2]) {
                    $cleared = true;
                    break;
                }
            }
            if ($cleared) {
                continue;
            }
            if ($kind === WF_WG_KIND_TREE && isset($felled[wf_wg_tree_id($cx, $cz, $ci)])) {
                continue;
            }
            $count++;
        }
    }

    return $count;
}

/** Складывает возврат: рюкзак (по весу), потом ящик (по вместимости), остальное падает на землю рядом. Возвращает ['items' => [...], 'dropped' => [...]]. */
function wf_build_give_back($conn, array &$row, array $items, array $ctx): array
{
    $pid = (int) $row['player_id'];
    $packRow = wf_player_pack($conn, $pid);
    $kg = (float) $packRow['kg'];
    $capacity = wf_player_pack_capacity((int) $row['pack_lvl']);
    $crateUsed = wf_crate_units(wf_container_items($conn, $pid, 'crate'));
    $crateCap = (int) forest_config('economy.crate_capacity');
    $toPack = [];
    $toCrate = [];
    $dropped = [];
    foreach ($items as $item => $qty) {
        $left = (int) $qty;
        $fit = wf_pack_fit($capacity, $kg, (string) $item, $left);
        if ($fit > 0) {
            wf_container_add($conn, $pid, 'pack', (string) $item, $fit);
            $kg += wf_pack_kg([(string) $item => $fit]);
            $toPack[$item] = $fit;
            $left -= $fit;
        }
        if ($left > 0) {
            $fit = wf_crate_fit($crateCap, $crateUsed, $left);
            if ($fit > 0) {
                wf_container_add($conn, $pid, 'crate', (string) $item, $fit);
                $crateUsed += $fit;
                $toCrate[$item] = $fit;
                $left -= $fit;
            }
        }
        if ($left > 0) {
            $dropped[$item] = $left;
        }
    }
    foreach ($dropped as $item => $qty) {
        $x = (float) $ctx['new']['x'];
        $z = (float) $ctx['new']['z'];
        $cx = wf_wg_fdiv((int) floor($x), 32);
        $cz = wf_wg_fdiv((int) floor($z), 32);
        wf_action_add_drop($conn, $cx, $cz, $x, $z, (string) $item, (int) $qty, $pid, (int) $ctx['now']);
    }

    return ['pack' => $toPack, 'crate' => $toCrate, 'dropped' => $dropped];
}

// ── Действия sync ───────────────────────────────────────────────────────

/** Базовая запись результата. */
function wf_build_res(array $action, string $type): array
{
    return ['a' => (int) $action['a'], 'ok' => false, 'type' => $type];
}

/**
 * Действие build_place: поставить постройку-чертёж. $action: btype (тип постройки), x, z, rot (градусы).
 * Сервер заново проверяет все правила места, списывает ресурсы и монеты, создаёт запись в состоянии blueprint.
 */
function wf_action_build_place($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $res = wf_build_res($action, 'build_place');
    $type = isset($action['btype']) ? (string) $action['btype'] : '';
    $spec = wf_build_spec($type);
    $x = isset($action['x']) && is_numeric($action['x']) ? round((float) $action['x'], 2) : null;
    $z = isset($action['z']) && is_numeric($action['z']) ? round((float) $action['z'], 2) : null;
    $rot = isset($action['rot']) && is_numeric($action['rot']) ? wf_build_rot((float) $action['rot']) : 0;
    $res['building_type'] = $type;
    if ($spec === null || $x === null || $z === null || abs($x) > 1.0e6 || abs($z) > 1.0e6) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $pid = (int) $row['player_id'];
    $r = (float) $spec['r'];
    $w = wf_wg_world();

    wf_build_lock($conn);

    $total = 0;
    $same = 0;
    $stmt = $conn->prepare('SELECT `type`, COUNT(*) AS n FROM `forest_buildings` WHERE `player_id` = ? GROUP BY `type`');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $q = $stmt->get_result();
    while ($q && ($o = $q->fetch_assoc())) {
        $total += (int) $o['n'];
        if ((string) $o['type'] === $type) {
            $same = (int) $o['n'];
        }
    }
    $stmt->close();

    $points = wf_build_sample_points($x, $z, $r);
    $heights = [];
    $water = false;
    foreach ($points as $p) {
        $heights[] = wf_build_height_m($w, $p[0], $p[1]);
        if (wf_wg_biome_dm($w, (int) floor($p[0] * 10), (int) floor($p[1] * 10)) === WF_WG_BIOME_WATER) {
            $water = true;
        }
    }
    $X = (int) floor($x * 10);
    $Z = (int) floor($z * 10);
    $err = wf_build_place_error([
        'level' => (int) $row['level'], 'unlock' => (int) $spec['unlock'],
        'total' => $total, 'max_total' => wf_build_max_total((int) $row['level']),
        'same' => $same, 'max_same' => (int) $spec['max'],
        'inside_world' => wf_wg_inside_world($w, $X, $Z), 'city' => wf_wg_is_city($w, $X, $Z),
        'dist_village' => hypot($x - (float) $row['spawn_x'], $z - (float) $row['spawn_z']),
        'foreign_dist' => wf_build_foreign_dist($conn, $pid, $x, $z),
        'water' => $water, 'slope_deg' => wf_build_slope_deg($heights, $r),
        'trees' => wf_build_scan_blockers($conn, $w, $x, $z, $r),
        'overlap' => wf_build_overlaps($conn, $row, $x, $z, $r),
    ]);
    if ($err !== null) {
        $res['error'] = 'bad_place';
        $res['reason'] = $err;

        return $res;
    }

    $coins = wf_build_coins($spec);
    if ((int) $row['coins'] < $coins) {
        $res['error'] = 'no_coins';
        $res['need_coins'] = $coins;

        return $res;
    }
    $plan = wf_build_plan_take(wf_build_stock($conn, $pid), wf_build_cost($spec));
    if ($plan === null) {
        $res['error'] = 'no_items';
        $res['need'] = (object) wf_build_cost($spec);

        return $res;
    }
    foreach ($plan as $step) {
        if (!wf_container_take($conn, $pid, (string) $step[0], (string) $step[1], (int) $step[2])) {
            $res['error'] = 'no_items';

            return $res;
        }
    }
    $row['coins'] = (int) $row['coins'] - $coins;

    $now = (int) $ctx['now'];
    $cx = wf_wg_fdiv((int) floor($x), 32);
    $cz = wf_wg_fdiv((int) floor($z), 32);
    $needed = (int) $spec['taps'];
    $ins = $conn->prepare('INSERT INTO `forest_buildings` (`player_id`, `type`, `x`, `z`, `cx`, `cz`, `rot`, `level`, `state`, `progress`, `needed`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, ?, ?, ?, 1, \'blueprint\', 0, ?, ?, ?)');
    $rotF = (float) $rot;
    $ins->bind_param('isddiidiii', $pid, $type, $x, $z, $cx, $cz, $rotF, $needed, $now, $now);
    $ins->execute();
    $id = (int) $ins->insert_id;
    $ins->close();

    $stats['placed'] = (int) ($stats['placed'] ?? 0) + 1;
    $res['ok'] = true;
    $res['building'] = ['id' => $id, 'type' => $type, 'x' => $x, 'z' => $z, 'rot' => $rot, 'level' => 1, 'state' => 'blueprint', 'progress' => 0, 'needed' => $needed, 'mine' => true, 'owner' => $pid];

    return $res;
}

/**
 * Действие build_tap: достраивать тапами. $action: building (id), taps, px, pz. Сервер принимает не больше, чем позволяют бюджет тапов и энергия;
 * дальность считается от края габарита (tap_reach_m). Готовая постройка даёт опыт.
 */
function wf_action_build_tap($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $res = wf_build_res($action, 'build_tap');
    $id = isset($action['building']) && is_numeric($action['building']) ? (int) $action['building'] : 0;
    $want = isset($action['taps']) && is_numeric($action['taps']) ? (int) $action['taps'] : 0;
    $res['building_id'] = $id;
    if ($id <= 0 || $want < 1 || $want > (int) forest_config('buildings.tap_max_per_action')) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $pid = (int) $row['player_id'];
    $b = wf_build_load($conn, $id);
    if ($b === null) {
        $res['error'] = 'no_building';

        return $res;
    }
    if ((int) $b['player_id'] !== $pid) {
        $res['error'] = 'forbidden';

        return $res;
    }
    if ($b['state'] !== 'blueprint' && $b['state'] !== 'building') {
        $res['error'] = 'bad_state';
        $res['building'] = wf_build_out($b, $pid);

        return $res;
    }
    $spec = wf_build_spec((string) $b['type']);
    $r = $spec ? (float) $spec['r'] : 3.0;
    [$near, $dist] = wf_crate_near($action, $ctx, (float) $b['x'], (float) $b['z'], $r + (float) forest_config('buildings.tap_reach_m'));
    if (!$near) {
        $res['error'] = 'too_far';
        if ($dist > 3 * ($r + (float) forest_config('buildings.tap_reach_m'))) {
            forest_log($conn, $pid, 'build_too_far', ['dist' => round($dist, 1), 'building' => $id]);
        }

        return $res;
    }

    $needed = (int) $b['needed'];
    $progress = (int) $b['progress'];
    $cost = (float) forest_config('hero.cost_build_tap');
    $energy = wf_energy_now((float) $row['energy'], (int) $row['energy_ts'], (int) $ctx['now'], wf_player_energy_max((int) $row['stamina_lvl']));
    $bucket = isset($stats['tap']) && is_array($stats['tap']) && count($stats['tap']) === 2 ? $stats['tap'] : [(float) forest_config('limits.tap_bucket'), $ctx['now_ms']];
    $refill = wf_tap_take((float) $bucket[0], (int) $bucket[1], (int) $ctx['now_ms'], 0.0)['tokens'];
    $plan = wf_build_tap_plan($want, $needed - $progress, (float) $refill, $energy, $cost);
    $n = $plan['n'];

    $stats['tap'] = [round($refill - $n, 3), $ctx['now_ms']];
    $row['energy'] = max(0.0, $energy - $n * $cost);
    $row['energy_ts'] = $ctx['now'];
    if ($n <= 0) {
        $res['error'] = $plan['limit'] === 'energy' ? 'no_energy' : 'too_fast';
        $res['building'] = wf_build_out($b, $pid);

        return $res;
    }

    $progress += $n;
    $state = $progress >= $needed ? 'ready' : 'building';
    $now = (int) $ctx['now'];
    $upd = $conn->prepare('UPDATE `forest_buildings` SET `progress` = ?, `state` = ?, `updated_at` = ? WHERE `id` = ?');
    $upd->bind_param('isii', $progress, $state, $now, $id);
    $upd->execute();
    $upd->close();
    $b['progress'] = $progress;
    $b['state'] = $state;

    $res['ok'] = true;
    $res['accepted'] = $n;
    $res['limit'] = $plan['limit'];
    $res['building'] = wf_build_out($b, $pid);
    if ($state === 'ready') {
        $xp = $spec ? wf_build_xp($spec) : 0;
        $row['xp'] = (int) $row['xp'] + $xp;
        $row['level'] = wf_level_from_xp((int) $row['xp'])['level'];
        $stats['built'] = (int) ($stats['built'] ?? 0) + 1;
        $res['xp'] = $xp;
    }

    return $res;
}

/**
 * Действия build_cancel (чертёж или стройка, до готовности) и build_demolish (готовая постройка): удаляют постройку и возвращают долю ресурсов.
 * Склад с содержимым снести нельзя (not_empty). $action: building (id).
 */
function wf_action_build_remove($conn, array &$row, array &$stats, array $action, array $ctx, string $type): array
{
    $res = wf_build_res($action, $type);
    $id = isset($action['building']) && is_numeric($action['building']) ? (int) $action['building'] : 0;
    $res['building_id'] = $id;
    if ($id <= 0) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $pid = (int) $row['player_id'];
    $b = wf_build_load($conn, $id);
    if ($b === null) {
        $res['error'] = 'no_building';

        return $res;
    }
    if ((int) $b['player_id'] !== $pid) {
        $res['error'] = 'forbidden';

        return $res;
    }
    $ready = $b['state'] === 'ready';
    if (($type === 'build_cancel' && $ready) || ($type === 'build_demolish' && !$ready)) {
        $res['error'] = 'bad_state';
        $res['building'] = wf_build_out($b, $pid);

        return $res;
    }
    $k = wf_build_refund_k((string) $b['state'], (int) $b['progress'], (int) $b['needed']);
    if ($k === null) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $content = wf_container_items($conn, $pid, 'bld:' . $id);
    if (wf_crate_units($content) > 0) {
        $res['error'] = 'not_empty';

        return $res;
    }
    $spec = wf_build_spec((string) $b['type']) ?? [];
    $refund = wf_build_refund($spec, $k);

    $del = $conn->prepare('DELETE FROM `forest_buildings` WHERE `id` = ? AND `player_id` = ?');
    $del->bind_param('ii', $id, $pid);
    $del->execute();
    $gone = $del->affected_rows > 0;
    $del->close();
    if (!$gone) {
        $res['error'] = 'no_building';

        return $res;
    }
    $row['coins'] = (int) $row['coins'] + $refund['coins'];
    $given = wf_build_give_back($conn, $row, $refund['items'], $ctx);

    $res['ok'] = true;
    $res['removed'] = $id;
    $res['refund'] = ['items' => (object) $refund['items'], 'coins' => $refund['coins'], 'k' => $k, 'dropped' => (object) $given['dropped']];

    return $res;
}
