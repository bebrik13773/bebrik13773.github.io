<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: склад, дом, рынок, мастерская (ДЛ-17).
 * Чистые функции (вместимость, кровати, цена улучшения) без БД; действия wh_put, wh_take и build_upgrade работают внутри транзакции sync.
 * Содержимое склада лежит в forest_inventory с container = bld:<id>.
 */
require_once __DIR__ . '/crate.php';
require_once __DIR__ . '/build.php';

/** Максимум уровней постройки (1, если улучшений нет). */
function wf_build_max_level(string $type): int
{
    $spec = wf_build_spec($type);

    return $spec === null ? 1 : max(1, (int) ($spec['levels'] ?? 1));
}

/** Вместимость склада (мест) на уровне: база × множитель^(уровень − 1). */
function wf_wh_capacity(int $level): int
{
    $base = (int) forest_config('buildings.warehouse.capacity');
    $mult = (int) forest_config('buildings.warehouse.cap_mult');

    return $base * (int) pow(max(1, $mult), max(1, $level) - 1);
}

/** Кроватей в доме на уровне: beds + beds_per_lvl × (уровень − 1), не больше beds_max. */
function wf_house_beds(int $level): int
{
    $n = (int) forest_config('buildings.house.beds') + (int) forest_config('buildings.house.beds_per_lvl') * (max(1, $level) - 1);

    return min((int) forest_config('buildings.house.beds_max'), $n);
}

/** Цена улучшения до уровня $to: массив logs, planks, ingots, coins, taps или null, если такого перехода нет. */
function wf_build_upgrade_spec(string $type, int $to): ?array
{
    $all = (array) forest_config('buildings.upgrades');
    if ($to < 2 || $to > wf_build_max_level($type) || !isset($all[$type][$to]) || !is_array($all[$type][$to])) {
        return null;
    }

    return $all[$type][$to];
}

/** Улучшения для клиента: [type => [to_level => spec]]. */
function wf_build_upgrades_out(): array
{
    $out = [];
    foreach ((array) forest_config('buildings.upgrades') as $type => $levels) {
        foreach ((array) $levels as $to => $s) {
            $out[(string) $type][(string) $to] = [
                'logs' => (int) $s['logs'], 'planks' => (int) $s['planks'], 'ingots' => (int) $s['ingots'], 'coins' => (int) $s['coins'], 'taps' => (int) $s['taps'],
            ];
        }
    }

    return $out;
}

/** Доп. поля постройки для клиента: вместимость склада, кровати дома, места очереди рынка, максимум уровней. */
function wf_build_extra(array $b): array
{
    $type = (string) $b['type'];
    $lvl = (int) $b['level'];
    $out = ['max_level' => wf_build_max_level($type)];
    if ($type === 'warehouse') {
        $out['cap'] = wf_wh_capacity($lvl);
    } elseif ($type === 'house') {
        $out['beds'] = wf_house_beds($lvl);
    } elseif ($type === 'market') {
        $out['places'] = (int) forest_config('buildings.market.queue_places');
    }

    return $out;
}

/** Своя ли готовая (или улучшаемая) постройка нужного типа. Возвращает [постройка, код ошибки]. */
function wf_storage_building($conn, int $pid, int $id, string $type): array
{
    $b = $id > 0 ? wf_build_load($conn, $id) : null;
    if ($b === null) {
        return [null, 'no_building'];
    }
    if ((int) $b['player_id'] !== $pid) {
        return [null, 'forbidden'];
    }
    if ((string) $b['type'] !== $type || ($b['state'] !== 'ready' && $b['state'] !== 'upgrading')) {
        return [null, 'bad_state'];
    }

    return [$b, null];
}

/** Переносит содержимое ящика-тайника на склад (первый склад). Что не влезло, остаётся в ящике. */
function wf_wh_migrate_crate($conn, int $pid, int $whId, int $level): array
{
    $moved = [];
    $items = wf_container_items($conn, $pid, 'crate');
    $used = wf_crate_units(wf_container_items($conn, $pid, 'bld:' . $whId));
    $cap = wf_wh_capacity($level);
    foreach ($items as $item => $qty) {
        $fit = wf_crate_fit($cap, $used, (int) $qty);
        if ($fit <= 0 || !wf_container_take($conn, $pid, 'crate', (string) $item, $fit)) {
            continue;
        }
        wf_container_add($conn, $pid, 'bld:' . $whId, (string) $item, $fit);
        $used += $fit;
        $moved[(string) $item] = $fit;
    }

    return $moved;
}

/**
 * Действия wh_put (из рюкзака на склад) и wh_take (со склада в рюкзак). $action: building, item, qty, px, pz.
 * Не влезло: переносим сколько влезает; нечего переносить: no_items; нет места: no_space.
 */
function wf_action_warehouse($conn, array &$row, array &$stats, array $action, array $ctx, string $type): array
{
    $a = (int) $action['a'];
    $id = isset($action['building']) && is_numeric($action['building']) ? (int) $action['building'] : 0;
    $res = ['a' => $a, 'ok' => false, 'type' => $type, 'building_id' => $id, 'item' => isset($action['item']) ? (string) $action['item'] : ''];
    $args = wf_crate_args($action);
    if ($args === null) {
        $res['error'] = 'bad_state';

        return $res;
    }
    [$item, $qty] = $args;
    $pid = (int) $row['player_id'];
    [$b, $err] = wf_storage_building($conn, $pid, $id, 'warehouse');
    if ($b === null) {
        $res['error'] = $err;

        return $res;
    }
    $spec = wf_build_spec('warehouse');
    $reach = (float) $spec['r'] + (float) forest_config('buildings.tap_reach_m');
    [$near, $dist] = wf_crate_near($action, $ctx, (float) $b['x'], (float) $b['z'], $reach);
    if (!$near) {
        $res['error'] = 'too_far';
        if ($dist > 3 * $reach) {
            forest_log($conn, $pid, 'warehouse_too_far', ['dist' => round($dist, 1), 'building' => $id]);
        }

        return $res;
    }

    $box = 'bld:' . $id;
    $from = $type === 'wh_put' ? 'pack' : $box;
    $to = $type === 'wh_put' ? $box : 'pack';
    $have = (int) (wf_container_items($conn, $pid, $from)[$item] ?? 0);
    if ($have <= 0) {
        $res['error'] = 'no_items';

        return $res;
    }
    $want = min($qty, $have);
    if ($type === 'wh_put') {
        $move = wf_crate_fit(wf_wh_capacity((int) $b['level']), wf_crate_units(wf_container_items($conn, $pid, $box)), $want);
    } else {
        $pack = wf_player_pack($conn, $pid);
        $move = wf_pack_fit(wf_player_pack_capacity((int) $row['pack_lvl']), (float) $pack['kg'], $item, $want);
    }
    if ($move <= 0) {
        $res['error'] = 'no_space';

        return $res;
    }
    if (!wf_container_take($conn, $pid, $from, $item, $move)) {
        $res['error'] = 'no_items';

        return $res;
    }
    wf_container_add($conn, $pid, $to, $item, $move);
    $res['ok'] = true;
    $res['moved'] = $move;
    $res['left'] = $qty - $move;

    return $res;
}

/**
 * Действие build_upgrade: начать улучшение готовой постройки на следующий уровень. $action: building.
 * Ресурсы и монеты списываются сразу (рюкзак, ящик, склады), постройка переходит в state = upgrading с прогрессом 0 и ждёт тапов.
 * Пока идёт улучшение, постройка работает на старом уровне (дом даёт отдых, склад хранит).
 */
function wf_action_build_upgrade($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $res = wf_build_res($action, 'build_upgrade');
    $id = isset($action['building']) && is_numeric($action['building']) ? (int) $action['building'] : 0;
    $res['building_id'] = $id;
    $pid = (int) $row['player_id'];
    $b = $id > 0 ? wf_build_load($conn, $id) : null;
    if ($b === null) {
        $res['error'] = 'no_building';

        return $res;
    }
    if ((int) $b['player_id'] !== $pid) {
        $res['error'] = 'forbidden';

        return $res;
    }
    $type = (string) $b['type'];
    $to = (int) $b['level'] + 1;
    $up = wf_build_upgrade_spec($type, $to);
    if ($b['state'] !== 'ready' || $up === null) {
        $res['error'] = $up === null && $b['state'] === 'ready' ? 'max_level' : 'bad_state';
        $res['building'] = wf_build_out($b, $pid);

        return $res;
    }
    $spec = (array) wf_build_spec($type);
    $reach = (float) $spec['r'] + (float) forest_config('buildings.tap_reach_m');
    [$near] = wf_crate_near($action, $ctx, (float) $b['x'], (float) $b['z'], $reach);
    if (!$near) {
        $res['error'] = 'too_far';

        return $res;
    }
    $coins = wf_build_coins($up);
    if ((int) $row['coins'] < $coins) {
        $res['error'] = 'no_coins';
        $res['need_coins'] = $coins;

        return $res;
    }
    $plan = wf_build_plan_take(wf_build_stock($conn, $pid), wf_build_cost($up));
    if ($plan === null) {
        $res['error'] = 'no_items';
        $res['need'] = (object) wf_build_cost($up);

        return $res;
    }
    foreach ($plan as $step) {
        if (!wf_container_take($conn, $pid, (string) $step[0], (string) $step[1], (int) $step[2])) {
            $res['error'] = 'no_items';

            return $res;
        }
    }
    $row['coins'] = (int) $row['coins'] - $coins;
    $needed = max(1, (int) $up['taps']);
    $now = (int) $ctx['now'];
    $upd = $conn->prepare('UPDATE `forest_buildings` SET `state` = \'upgrading\', `progress` = 0, `needed` = ?, `updated_at` = ? WHERE `id` = ? AND `player_id` = ? AND `state` = \'ready\'');
    $upd->bind_param('iiii', $needed, $now, $id, $pid);
    $upd->execute();
    $ok = $upd->affected_rows > 0;
    $upd->close();
    if (!$ok) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $b['state'] = 'upgrading';
    $b['progress'] = 0;
    $b['needed'] = $needed;
    $res['ok'] = true;
    $res['to_level'] = $to;
    $res['building'] = wf_build_out($b, $pid);

    return $res;
}
