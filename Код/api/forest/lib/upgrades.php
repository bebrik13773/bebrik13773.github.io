<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: улучшения бобра (ДЛ-18). Топор, рюкзак, выносливость и фонарь растут по уровням,
 * снаряжение (дождевик, тёплая одежда, сапоги) и кирка покупаются один раз. Всё делается в мастерской:
 * бобёр стоит рядом со своей готовой мастерской, сервер считает цену, списывает монеты и ресурсы и меняет уровень.
 * Чистые функции (wf_upg_*) без БД тестируются таблицей кейсов на tools/forest-selftest.php.
 */
require_once __DIR__ . '/common.php';
require_once __DIR__ . '/crate.php';
require_once __DIR__ . '/build.php';

/** Столбцы уровней: вид улучшения => столбец forest_players (белый список для SQL). */
function wf_upg_columns(): array
{
    return ['axe' => 'axe_lvl', 'pack' => 'pack_lvl', 'stamina' => 'stamina_lvl', 'lamp' => 'lamp_lvl'];
}

/** Самый высокий уровень вида (топор 6, рюкзак 8, выносливость 6, фонарь 4). */
function wf_upg_max(string $kind): int
{
    switch ($kind) {
        case 'axe':
            return count((array) forest_config('trees.axe'));
        case 'pack':
            return (int) forest_config('hero.pack_max_lvl');
        case 'stamina':
            return (int) forest_config('hero.stamina_max_lvl');
        case 'lamp':
            return count((array) forest_config('upgrades.lamp'));
    }

    return 0;
}

/** Уровень, с которого игрок начинает: фонаря нет (0), остальное 1. */
function wf_upg_start(string $kind): int
{
    return $kind === 'lamp' ? 0 : 1;
}

/**
 * Цена перехода на уровень $to: ['coins' => int, 'items' => [предмет => штук]] или null, если такого уровня нет.
 */
function wf_upg_spec(string $kind, int $to): ?array
{
    if ($to <= wf_upg_start($kind) || $to > wf_upg_max($kind)) {
        return null;
    }
    $coins = 0;
    $items = [];
    if ($kind === 'axe') {
        $a = (array) forest_config('trees.axe');
        if (!isset($a[$to])) {
            return null;
        }
        $coins = (int) $a[$to]['coins'];
        if ((int) $a[$to]['ingots'] > 0) {
            $items['ingot'] = (int) $a[$to]['ingots'];
        }
    } else {
        $table = (array) forest_config('upgrades.' . $kind);
        if (!isset($table[$to])) {
            return null;
        }
        $coins = (int) $table[$to];
        if ($kind === 'lamp') {
            $ing = (array) forest_config('upgrades.lamp_ingots');
            if (isset($ing[$to]) && (int) $ing[$to] > 0) {
                $items['ingot'] = (int) $ing[$to];
            }
        }
    }

    return ['coins' => $coins, 'items' => $items];
}

/** Цена разовой покупки: 'pickaxe' или снаряжение (raincoat, warm, boots). null — такой вещи нет. */
function wf_upg_item_spec(string $name): ?array
{
    if ($name === 'pickaxe') {
        return ['coins' => (int) forest_config('trees.pickaxe_price'), 'items' => array_map('intval', (array) forest_config('upgrades.pickaxe_items'))];
    }
    $gear = (array) forest_config('upgrades.gear');
    if (!isset($gear[$name]) || !is_array($gear[$name])) {
        return null;
    }

    return ['coins' => (int) $gear[$name]['coins'], 'items' => array_map('intval', (array) $gear[$name]['items'])];
}

/** Снаряжение игрока из gear_json: [название => 1]. Неизвестные названия отбрасываются. */
function wf_upg_gear(array $row): array
{
    $list = json_decode((string) ($row['gear_json'] ?? ''), true);
    $out = [];
    if (is_array($list)) {
        $known = (array) forest_config('upgrades.gear');
        foreach ($list as $name => $v) {
            if (isset($known[(string) $name]) && $v) {
                $out[(string) $name] = 1;
            }
        }
    }

    return $out;
}

function wf_upg_has_boots(array $row): bool
{
    return isset(wf_upg_gear($row)['boots']);
}

/** Уровни игрока: ['axe' => , 'pack' => , 'stamina' => , 'lamp' => ]. */
function wf_upg_levels(array $row): array
{
    $out = [];
    foreach (wf_upg_columns() as $kind => $col) {
        $out[$kind] = (int) ($row[$col] ?? wf_upg_start($kind));
    }

    return $out;
}

/**
 * Что покупаем. $levels — текущие уровни, $gear — снаряжение, $hotbar — быстрые слоты, $to — желаемый уровень (для разовых вещей не нужен).
 * Возвращает ['ok' => true, 'spec' => [...], 'to' => уровень] или ['ok' => false, 'error' => код]:
 * unknown (нет такой вещи), max_level (уже самый высокий), owned (разовая вещь уже есть), stale (клиент отстал: уровень уже другой), no_space (нет свободного быстрого слота).
 */
function wf_upg_resolve(string $kind, ?int $to, array $levels, array $gear, array $hotbar): array
{
    $cols = wf_upg_columns();
    if (isset($cols[$kind])) {
        $cur = (int) ($levels[$kind] ?? wf_upg_start($kind));
        if ($cur >= wf_upg_max($kind)) {
            return ['ok' => false, 'error' => 'max_level'];
        }
        if ($to !== null && $to !== $cur + 1) {
            return ['ok' => false, 'error' => 'stale'];
        }
        $spec = wf_upg_spec($kind, $cur + 1);
        if ($spec === null) {
            return ['ok' => false, 'error' => 'unknown'];
        }

        return ['ok' => true, 'spec' => $spec, 'to' => $cur + 1];
    }
    $spec = wf_upg_item_spec($kind);
    if ($spec === null) {
        return ['ok' => false, 'error' => 'unknown'];
    }
    if ($kind === 'pickaxe') {
        if (in_array('pickaxe', $hotbar, true)) {
            return ['ok' => false, 'error' => 'owned'];
        }
        if (count($hotbar) >= (int) forest_config('ui.hotbar_slots')) {
            return ['ok' => false, 'error' => 'no_space'];
        }
    } elseif (isset($gear[$kind])) {
        return ['ok' => false, 'error' => 'owned'];
    }

    return ['ok' => true, 'spec' => $spec, 'to' => 1];
}

/** Каталог улучшений для клиента: levels[вид][уровень] = {coins, items}, items — цена разовых вещей. */
function wf_upg_out(): array
{
    $levels = [];
    foreach (array_keys(wf_upg_columns()) as $kind) {
        for ($to = wf_upg_start($kind) + 1; $to <= wf_upg_max($kind); $to++) {
            $s = wf_upg_spec($kind, $to);
            if ($s !== null) {
                $levels[$kind][(string) $to] = ['coins' => $s['coins'], 'items' => (object) $s['items']];
            }
        }
    }
    $once = [];
    foreach (array_merge(['pickaxe'], array_keys((array) forest_config('upgrades.gear'))) as $name) {
        $s = wf_upg_item_spec((string) $name);
        if ($s !== null) {
            $once[(string) $name] = ['coins' => $s['coins'], 'items' => (object) $s['items']];
        }
    }

    return [
        'levels' => $levels,
        'once' => $once,
        'lamp_bonus_m' => (object) array_map('intval', (array) forest_config('upgrades.lamp_bonus_m')),
        'boots_speed' => (float) forest_config('upgrades.boots_speed'),
    ];
}

/** Ближайшая своя готовая мастерская в пределах досягаемости: true, если игрок стоит у верстака. */
function wf_upg_at_workshop($conn, int $pid, array $action, array $ctx): bool
{
    $spec = wf_build_spec('workshop');
    $reach = (float) ($spec['r'] ?? 2.8) + (float) forest_config('upgrades.workshop_reach_m');
    $stmt = $conn->prepare('SELECT `x`, `z` FROM `forest_buildings` WHERE `player_id` = ? AND `type` = \'workshop\' AND `state` IN (\'ready\', \'upgrading\')');
    $stmt->bind_param('i', $pid);
    $stmt->execute();
    $res = $stmt->get_result();
    $near = false;
    while ($res && ($r = $res->fetch_assoc())) {
        [$ok] = wf_crate_near($action, $ctx, (float) $r['x'], (float) $r['z'], $reach);
        if ($ok) {
            $near = true;
            break;
        }
    }
    $stmt->close();

    return $near;
}

/**
 * Действие upgrade_buy: $action = {kind, to?, px, pz}. kind: axe | pack | stamina | lamp (уровень на 1 выше текущего)
 * или pickaxe | raincoat | warm | boots (разовая покупка). Нужна своя готовая мастерская рядом.
 * Ошибки: forbidden-нет, no_workshop, too_far, max_level, owned, stale, no_space, no_coins, no_items, bad_state.
 */
function wf_action_upgrade_buy($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $res = wf_build_res($action, 'upgrade_buy');
    $kind = isset($action['kind']) ? (string) $action['kind'] : '';
    $to = isset($action['to']) && is_numeric($action['to']) ? (int) $action['to'] : null;
    $res['kind'] = $kind;
    $pid = (int) $row['player_id'];

    $hotbar = json_decode((string) ($row['hotbar_json'] ?? ''), true);
    $hotbar = is_array($hotbar) ? array_values(array_map('strval', $hotbar)) : [];
    $gear = wf_upg_gear($row);
    $plan = wf_upg_resolve($kind, $to, wf_upg_levels($row), $gear, $hotbar);
    if (!$plan['ok']) {
        $code = (string) $plan['error'];
        $res['error'] = ($code === 'unknown' || $code === 'stale') ? 'bad_state' : $code;
        $res['upgrades'] = (object) wf_upg_levels($row);

        return $res;
    }
    if (!wf_upg_at_workshop($conn, $pid, $action, $ctx)) {
        // отличаем «нет мастерской» от «далеко»: считаем мастерские без учёта дальности
        $c = $conn->prepare('SELECT COUNT(*) AS n FROM `forest_buildings` WHERE `player_id` = ? AND `type` = \'workshop\' AND `state` IN (\'ready\', \'upgrading\')');
        $c->bind_param('i', $pid);
        $c->execute();
        $r = $c->get_result();
        $n = $r ? (int) ($r->fetch_assoc()['n'] ?? 0) : 0;
        $c->close();
        $res['error'] = $n > 0 ? 'too_far' : 'no_workshop';

        return $res;
    }
    $spec = $plan['spec'];
    $coins = (int) $spec['coins'];
    if ((int) $row['coins'] < $coins) {
        $res['error'] = 'no_coins';
        $res['need_coins'] = $coins;

        return $res;
    }
    $take = wf_build_plan_take(wf_build_stock($conn, $pid), $spec['items']);
    if ($take === null) {
        $res['error'] = 'no_items';
        $res['need'] = (object) $spec['items'];

        return $res;
    }
    foreach ($take as $step) {
        if (!wf_container_take($conn, $pid, (string) $step[0], (string) $step[1], (int) $step[2])) {
            $res['error'] = 'no_items';

            return $res;
        }
    }

    $cols = wf_upg_columns();
    if (isset($cols[$kind])) {
        $col = $cols[$kind]; // из белого списка выше
        $lvl = (int) $plan['to'];
        $upd = $conn->prepare('UPDATE `forest_players` SET `' . $col . '` = ? WHERE `player_id` = ?');
        $upd->bind_param('ii', $lvl, $pid);
        $upd->execute();
        $upd->close();
        $row[$col] = $lvl;
    } elseif ($kind === 'pickaxe') {
        $hotbar[] = 'pickaxe';
        $json = json_encode($hotbar, JSON_UNESCAPED_UNICODE);
        $upd = $conn->prepare('UPDATE `forest_players` SET `hotbar_json` = ? WHERE `player_id` = ?');
        $upd->bind_param('si', $json, $pid);
        $upd->execute();
        $upd->close();
        $row['hotbar_json'] = $json;
    } else {
        $gear[$kind] = 1;
        $json = json_encode($gear, JSON_UNESCAPED_UNICODE);
        $upd = $conn->prepare('UPDATE `forest_players` SET `gear_json` = ? WHERE `player_id` = ?');
        $upd->bind_param('si', $json, $pid);
        $upd->execute();
        $upd->close();
        $row['gear_json'] = $json;
    }
    $row['coins'] = (int) $row['coins'] - $coins;
    $stats['spent_upgrades'] = (int) ($stats['spent_upgrades'] ?? 0) + $coins;

    $res['ok'] = true;
    $res['to_level'] = (int) $plan['to'];
    $res['upgrades'] = (object) wf_upg_levels($row);
    $res['gear'] = (object) wf_upg_gear($row);
    $res['hotbar'] = $hotbar;

    return $res;
}
