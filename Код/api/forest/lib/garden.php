<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: сад, посадка деревьев (ДЛ-19).
 * Чистые функции (сезон, места посадки, рост по 20-минутным корзинам, засыхание) не трогают БД.
 * Действия plant, water, sapling_clear и sapling_chop работают внутри транзакции sync (строка игрока заблокирована).
 * Рост считается лениво: «что случилось с last_calc до сейчас» — по корзинам времени, множитель = сезон × погода.
 */
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/chop.php';
require_once __DIR__ . '/pack.php';

/** Сезон по реальной дате в часовом поясе игры: winter | spring | summer | autumn. */
function wf_garden_season(int $ts): string
{
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone((string) forest_config('time.tz')));
    $m = (int) $d->format('n');
    if ($m === 12 || $m <= 2) {
        return 'winter';
    }

    return $m <= 5 ? 'spring' : ($m <= 8 ? 'summer' : 'autumn');
}

/** Множитель роста от сезона. */
function wf_garden_season_k(int $ts): float
{
    return (float) forest_config('garden.season_k')[wf_garden_season($ts)];
}

/** Множитель роста от погоды корзины. Погода детерминированная появится в ДЛ-28, пока везде 1.0. */
function wf_garden_weather_k(int $bucket): float
{
    return 1.0;
}

/** Смещение часового пояса игры от UTC, секунд (Омск без перехода на летнее время, поэтому постоянное). */
function wf_garden_tz_offset(int $ts): int
{
    return (new DateTimeZone((string) forest_config('time.tz')))->getOffset(new DateTime('@' . $ts));
}

/** Мест посадки на уровне сада: slots + slots_per_lvl × (уровень − 1), не больше ring_slots. */
function wf_garden_slots(int $level): int
{
    $spec = wf_build_spec('garden');
    $n = (int) $spec['slots'] + (int) $spec['slots_per_lvl'] * (max(1, $level) - 1);

    return min((int) forest_config('garden.ring_slots'), $n);
}

/** Место посадки номер $slot вокруг центра сада: [x, z] в метрах (кольцо вокруг габарита, не зависит от уровня). */
function wf_garden_slot_pos(float $bx, float $bz, int $slot): array
{
    $spec = wf_build_spec('garden');
    $r = (float) $spec['r'] + (float) forest_config('garden.ring_r_extra_m');
    $a = 2 * M_PI * $slot / max(1, (int) forest_config('garden.ring_slots'));

    return [round($bx + cos($a) * $r, 2), round($bz + sin($a) * $r, 2)];
}

/** Бывает ли такое семя: item вида seed_<порода> с известной породой. Возвращает id породы или null. */
function wf_garden_seed_species(string $item): ?string
{
    if (strncmp($item, 'seed_', 5) !== 0) {
        return null;
    }
    $id = substr($item, 5);

    return isset(forest_config('trees.species')[$id]) ? $id : null;
}

/** Секунд роста породы до взрослого дерева. */
function wf_garden_grow_seconds(string $speciesId): int
{
    return (int) forest_config('trees.species')[$speciesId]['grow_days'] * 86400;
}

/** Размер взрослого дерева: 1 (среднее) или 2 (большое, если поливали). */
function wf_garden_size(int $wateredN): int
{
    return ($wateredN > 0 && forest_config('garden.large_if_watered')) ? 2 : 1;
}

/** Стадия роста по доле: 0 росток, 1 молодое, 2 среднее, 3 взрослое. */
function wf_garden_stage(float $frac): int
{
    return $frac >= 1.0 ? 3 : ($frac >= 0.66 ? 2 : ($frac >= 0.33 ? 1 : 0));
}

/** Засыхает ли саженец в конце суток dayIdx (детерминированно по id и дню). */
function wf_garden_dry_roll(int $saplingId, int $dayIdx, string $season, int $wateredUntil, int $dayEndTs): bool
{
    if ($wateredUntil >= $dayEndTs) {
        return false;
    }
    $permille = (int) forest_config('garden.dry_permille')[$season];

    return wf_wg_hash((int) forest_config('world.seed'), $saplingId, $dayIdx, (int) forest_config('garden.salts.dry')) % 1000 < $permille;
}

/**
 * Пересчёт саженца до момента $now (чистая функция). Вход: id, growth, grow_seconds, state, last_calc, watered_until.
 * Возвращает те же поля плюс dry_at (когда засох), без записи в БД. Рост копится по 20-минутным корзинам, на границе суток бросок на засыхание.
 */
function wf_garden_advance(array $s, int $now): array
{
    if ($s['state'] !== 'growing') {
        return $s;
    }
    $bucket = (int) forest_config('garden.bucket_s');
    $t = (int) $s['last_calc'];
    $limit = $t + (int) forest_config('garden.max_catch_days') * 86400;
    $now = min($now, $limit);
    $growth = (float) $s['growth'];
    $need = (float) $s['grow_seconds'];
    $off = wf_garden_tz_offset($t);
    while ($t < $now) {
        $end = min($now, (intdiv($t, $bucket) + 1) * $bucket);
        $growth += ($end - $t) * wf_garden_season_k($t) * wf_garden_weather_k(intdiv($t, $bucket));
        if ($growth >= $need) {
            $s['growth'] = $need;
            $s['state'] = 'grown';
            $s['last_calc'] = $end;

            return $s;
        }
        $t = $end;
        if (($t + $off) % 86400 === 0) {
            $dayIdx = intdiv($t + $off, 86400);
            if (wf_garden_dry_roll((int) $s['id'], $dayIdx, wf_garden_season($t - 1), (int) $s['watered_until'], $t)) {
                $s['growth'] = $growth;
                $s['state'] = 'dry';
                $s['last_calc'] = $t;

                return $s;
            }
        }
    }
    $s['growth'] = $growth;
    $s['last_calc'] = $t;

    return $s;
}

/** Сколько ударов нужно по взрослому посаженному дереву этим топором (по породе, размеру и удалённости). */
function wf_garden_need(array $s, int $axeLvl): int
{
    $w = wf_wg_world();
    $tier = wf_wg_tier_dm($w, (int) floor((float) $s['x'] * 10), (int) floor((float) $s['z'] * 10));
    $hp = wf_chop_hp((string) $s['species'], wf_garden_size((int) $s['watered_n']), $tier);

    return wf_chop_need($hp, wf_chop_power($axeLvl));
}

/** Список саженцев для ответа клиенту (с числом ударов у выросших). */
function wf_garden_list_out(array $list, int $now, int $axeLvl): array
{
    return array_map(static function ($sp) use ($now, $axeLvl) {
        return wf_garden_out($sp, $now, $sp['state'] === 'grown' ? wf_garden_need($sp, $axeLvl) : 0);
    }, $list);
}

/** Строка саженца для клиента. */
function wf_garden_out(array $s, int $now, int $need = 0): array
{
    $need = max(1.0, (float) $s['grow_seconds']);
    $frac = $s['state'] === 'grown' ? 1.0 : min(1.0, (float) $s['growth'] / $need);

    return [
        'id' => (int) $s['id'], 'building' => (int) $s['building_id'], 'slot' => (int) $s['slot'], 'species' => (string) $s['species'],
        'x' => (float) $s['x'], 'z' => (float) $s['z'], 'state' => (string) $s['state'],
        'frac' => round($frac, 4), 'stage' => wf_garden_stage($frac),
        'need' => $s['state'] === 'grown' ? $need : 0,
        'watered' => (int) $s['watered_until'] > $now, 'big' => wf_garden_size((int) $s['watered_n']) === 2,
        'rate' => round(wf_garden_season_k($now) * wf_garden_weather_k(intdiv($now, (int) forest_config('garden.bucket_s'))), 3),
        'left_s' => $s['state'] === 'growing' ? (int) ceil(max(0.0, $need - (float) $s['growth']) / max(0.05, wf_garden_season_k($now) * wf_garden_weather_k(intdiv($now, (int) forest_config('garden.bucket_s'))))) : 0,
    ];
}

/** Саженцы игрока, пересчитанные до $now. Изменившееся состояние (выросло, засохло) сохраняется. */
function wf_garden_load($conn, int $playerId, int $now): array
{
    $stmt = $conn->prepare('SELECT `id`, `building_id`, `slot`, `species`, `x`, `z`, `planted_at`, `grow_seconds`, `growth`, `state`, `last_calc`, `watered_until`, `watered_n` FROM `forest_saplings` WHERE `player_id` = ? ORDER BY `id`');
    $stmt->bind_param('i', $playerId);
    $stmt->execute();
    $res = $stmt->get_result();
    $out = [];
    while ($res && ($r = $res->fetch_assoc())) {
        $adv = wf_garden_advance($r, $now);
        if ($adv['state'] !== $r['state']) {
            $up = $conn->prepare('UPDATE `forest_saplings` SET `growth` = ?, `state` = ?, `last_calc` = ? WHERE `id` = ? AND `player_id` = ?');
            $g = (float) $adv['growth'];
            $st = (string) $adv['state'];
            $lc = (int) $adv['last_calc'];
            $id = (int) $r['id'];
            $up->bind_param('dsiii', $g, $st, $lc, $id, $playerId);
            $up->execute();
            $up->close();
        }
        $out[] = $adv;
    }
    $stmt->close();

    return $out;
}

/** Один саженец игрока (пересчитанный) или null. */
function wf_garden_one($conn, int $playerId, int $id, int $now): ?array
{
    foreach (wf_garden_load($conn, $playerId, $now) as $s) {
        if ((int) $s['id'] === $id) {
            return $s;
        }
    }

    return null;
}

/** Общая подготовка действий сада: игрок рядом? Возвращает [ok, dist]. */
function wf_garden_near(array $action, array $ctx, float $x, float $z, float $reach): array
{
    return wf_crate_near($action, $ctx, $x, $z, $reach);
}

/** Посадка: action = { building, slot, item: seed_<порода> }. Семя берётся из рюкзака. */
function wf_action_plant($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $id = isset($action['building']) && is_numeric($action['building']) ? (int) $action['building'] : 0;
    $slot = isset($action['slot']) && is_numeric($action['slot']) ? (int) $action['slot'] : -1;
    $item = isset($action['item']) ? (string) $action['item'] : '';
    $res = ['a' => $a, 'ok' => false, 'type' => 'plant', 'building_id' => $id, 'slot' => $slot, 'item' => $item];
    $species = wf_garden_seed_species($item);
    $pid = (int) $row['player_id'];
    if ($species === null || $slot < 0) {
        $res['error'] = 'bad_state';

        return $res;
    }
    [$b, $err] = wf_storage_building($conn, $pid, $id, 'garden');
    if ($b === null) {
        $res['error'] = $err;

        return $res;
    }
    if ($slot >= wf_garden_slots((int) $b['level'])) {
        $res['error'] = 'no_slot';

        return $res;
    }
    $spec = wf_build_spec('garden');
    $reach = (float) $spec['r'] + (float) forest_config('garden.reach_extra_m');
    [$near, $dist] = wf_garden_near($action, $ctx, (float) $b['x'], (float) $b['z'], $reach);
    if (!$near) {
        $res['error'] = 'too_far';

        return $res;
    }
    foreach (wf_garden_load($conn, $pid, $ctx['now']) as $s) {
        if ((int) $s['building_id'] === $id && (int) $s['slot'] === $slot) {
            $res['error'] = 'slot_busy';

            return $res;
        }
    }
    if (!wf_pack_take($conn, $pid, $item, 1)) {
        $res['error'] = 'no_items';

        return $res;
    }
    [$x, $z] = wf_garden_slot_pos((float) $b['x'], (float) $b['z'], $slot);
    $grow = wf_garden_grow_seconds($species);
    $now = (int) $ctx['now'];
    $ins = $conn->prepare('INSERT INTO `forest_saplings` (`player_id`, `building_id`, `slot`, `species`, `x`, `z`, `planted_at`, `grow_seconds`, `growth`, `state`, `last_calc`, `watered_until`, `watered_n`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, \'growing\', ?, 0, 0)');
    $ins->bind_param('iiisddiii', $pid, $id, $slot, $species, $x, $z, $now, $grow, $now);
    $ins->execute();
    $sid = (int) $conn->insert_id;
    $ins->close();
    $stats['planted'] = (int) ($stats['planted'] ?? 0) + 1;
    $res['ok'] = true;
    $res['sapling'] = wf_garden_out(['id' => $sid, 'building_id' => $id, 'slot' => $slot, 'species' => $species, 'x' => $x, 'z' => $z, 'state' => 'growing', 'growth' => 0, 'grow_seconds' => $grow, 'watered_until' => 0, 'watered_n' => 0], $now);

    return $res;
}

/** Общая часть действий над саженцем: загрузка, владелец, дальность. Возвращает [саженец|null, ошибка|null]. */
function wf_garden_target($conn, array $row, array $action, array $ctx, float $reach): array
{
    $sid = isset($action['sapling']) && is_numeric($action['sapling']) ? (int) $action['sapling'] : 0;
    $s = $sid > 0 ? wf_garden_one($conn, (int) $row['player_id'], $sid, (int) $ctx['now']) : null;
    if ($s === null) {
        return [null, 'no_sapling'];
    }
    [$near] = wf_garden_near($action, $ctx, (float) $s['x'], (float) $s['z'], $reach);

    return $near ? [$s, null] : [null, 'too_far'];
}

/** Полив: сутки без засыхания, 0.3 энергии. action = { sapling }. */
function wf_action_water($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $res = ['a' => $a, 'ok' => false, 'type' => 'water', 'sapling_id' => isset($action['sapling']) ? (int) $action['sapling'] : 0];
    [$s, $err] = wf_garden_target($conn, $row, $action, $ctx, (float) forest_config('garden.reach_extra_m') + 2.0);
    if ($s === null) {
        $res['error'] = $err;

        return $res;
    }
    if ($s['state'] !== 'growing') {
        $res['error'] = 'bad_state';

        return $res;
    }
    $cost = (float) forest_config('garden.water_cost');
    if ((float) $row['energy'] < $cost) {
        $res['error'] = 'no_energy';

        return $res;
    }
    $row['energy'] = (float) $row['energy'] - $cost;
    $until = (int) $ctx['now'] + (int) forest_config('garden.water_s');
    $sid = (int) $s['id'];
    $pid = (int) $row['player_id'];
    $growth = (float) $s['growth'];
    $lc = (int) $s['last_calc'];
    $up = $conn->prepare('UPDATE `forest_saplings` SET `watered_until` = ?, `watered_n` = `watered_n` + 1, `growth` = ?, `last_calc` = ? WHERE `id` = ? AND `player_id` = ?');
    $up->bind_param('idiii', $until, $growth, $lc, $sid, $pid);
    $up->execute();
    $up->close();
    $s['watered_until'] = $until;
    $s['watered_n'] = (int) $s['watered_n'] + 1;
    $res['ok'] = true;
    $res['sapling'] = wf_garden_out($s, (int) $ctx['now']);

    return $res;
}

/** Убрать засохшее: место освобождается. action = { sapling }. */
function wf_action_sapling_clear($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $res = ['a' => $a, 'ok' => false, 'type' => 'sapling_clear', 'sapling_id' => isset($action['sapling']) ? (int) $action['sapling'] : 0];
    [$s, $err] = wf_garden_target($conn, $row, $action, $ctx, (float) forest_config('garden.reach_extra_m') + 2.0);
    if ($s === null) {
        $res['error'] = $err;

        return $res;
    }
    if ($s['state'] !== 'dry') {
        $res['error'] = 'bad_state';

        return $res;
    }
    $sid = (int) $s['id'];
    $pid = (int) $row['player_id'];
    $del = $conn->prepare('DELETE FROM `forest_saplings` WHERE `id` = ? AND `player_id` = ?');
    $del->bind_param('ii', $sid, $pid);
    $del->execute();
    $del->close();
    $res['ok'] = true;

    return $res;
}

/** Рубка взрослого посаженного дерева: как обычная (удары, окно, энергия, ведро тапов), награда по породе и размеру. action = { sapling, hits, t0, t1 }. */
function wf_action_sapling_chop($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $res = ['a' => $a, 'ok' => false, 'type' => 'sapling_chop', 'sapling_id' => isset($action['sapling']) ? (int) $action['sapling'] : 0];
    $sid = $res['sapling_id'];
    $pid = (int) $row['player_id'];
    $s = $sid > 0 ? wf_garden_one($conn, $pid, $sid, (int) $ctx['now']) : null;
    if ($s === null) {
        $res['error'] = 'tree_gone';

        return $res;
    }
    $px = isset($action['px']) && is_numeric($action['px']) ? (float) $action['px'] : $ctx['new']['x'];
    $pz = isset($action['pz']) && is_numeric($action['pz']) ? (float) $action['pz'] : $ctx['new']['z'];
    $hits = isset($action['hits']) && is_numeric($action['hits']) ? (int) $action['hits'] : 0;
    $t0 = isset($action['t0']) && is_numeric($action['t0']) ? (float) $action['t0'] : null;
    $t1 = isset($action['t1']) && is_numeric($action['t1']) ? (float) $action['t1'] : null;
    $window = ($t0 !== null && $t1 !== null && $t1 >= $t0) ? $t1 - $t0 : null;

    $w = wf_wg_world();
    $tx = (float) $s['x'];
    $tz = (float) $s['z'];
    $tier = wf_wg_tier_dm($w, (int) floor($tx * 10), (int) floor($tz * 10));
    $size = wf_garden_size((int) $s['watered_n']);
    $hp = wf_chop_hp((string) $s['species'], $size, $tier);
    $need = wf_chop_need($hp, wf_chop_power((int) $row['axe_lvl']));
    $cost = wf_chop_cost($need);
    $energy = wf_energy_now((float) $row['energy'], (int) $row['energy_ts'], $ctx['now'], wf_player_energy_max((int) $row['stamina_lvl']));
    $bucket = isset($stats['tap']) && is_array($stats['tap']) && count($stats['tap']) === 2 ? $stats['tap'] : [(float) forest_config('limits.tap_bucket'), $ctx['now_ms']];
    $take = wf_tap_take((float) $bucket[0], (int) $bucket[1], $ctx['now_ms'], (float) $need);

    $v = wf_chop_validate([
        'kind' => WF_WG_KIND_TREE, 'cleared' => false, 'felled' => $s['state'] !== 'grown',
        'dist_tree' => hypot($px - $tx, $pz - $tz),
        'dist_prev' => hypot($px - $ctx['prev']['x'], $pz - $ctx['prev']['z']),
        'dist_new' => hypot($px - $ctx['new']['x'], $pz - $ctx['new']['z']),
        'allow' => $ctx['allow'], 'hits' => $hits, 'need' => $need,
        'energy' => $energy, 'cost' => $cost, 'tokens' => $take['tokens'], 'window_ms' => $window,
    ]);
    $stats['tap'] = [round($take['tokens'], 3), $ctx['now_ms']];
    $row['energy'] = $energy;
    $row['energy_ts'] = $ctx['now'];
    if (!$v['ok']) {
        $res['error'] = $v['error'] === 'tree_gone' ? 'not_grown' : $v['error'];
        if ($v['cheat']) {
            $st = wf_strikes_add(isset($stats['strikes']) && is_array($stats['strikes']) ? $stats['strikes'] : [], $ctx['now']);
            $stats['strikes'] = $st['list'];
            forest_log($conn, $pid, 'sapling_chop_' . $v['error'], ['sapling' => $sid, 'hits' => $hits, 'need' => $need, 'strikes' => $st['count']]);
            if ($st['level'] === 'block') {
                $row['blocked_until'] = $ctx['now'] + (int) forest_config('limits.block_seconds');
                forest_log($conn, $pid, 'block', ['strikes' => $st['count']]);
            }
        }

        return $res;
    }

    // Удаляем строку первой: кто успел, тот и получил награду (повтор безопасен).
    $del = $conn->prepare('DELETE FROM `forest_saplings` WHERE `id` = ? AND `player_id` = ?');
    $del->bind_param('ii', $sid, $pid);
    $del->execute();
    $gone = $del->affected_rows < 1;
    $del->close();
    if ($gone) {
        $res['error'] = 'tree_gone';

        return $res;
    }
    $row['energy'] = max(0.0, $energy - $cost);
    $k = wf_strikes_reward_k(wf_strikes_active(isset($stats['strikes']) && is_array($stats['strikes']) ? $stats['strikes'] : [], $ctx['now']));
    $logs = wf_chop_logs((string) $s['species'], $size);
    if ($k < 1.0) {
        $logs = max(1, (int) floor($logs * $k));
    }
    $cx = (int) floor($tx / 32);
    $cz = (int) floor($tz / 32);
    $points = wf_chop_drop_points($w, $cx, $cz, (int) $s['slot'], $tx, $tz, $logs);
    $drops = [];
    foreach ($points as $p) {
        $drops[] = ['id' => wf_action_add_drop($conn, $cx, $cz, $p['x'], $p['z'], 'log', 1, $pid, $ctx['now']), 'x' => $p['x'], 'z' => $p['z'], 'item' => 'log', 'qty' => 1, 'ttl' => (int) forest_config('trees.chop.drop_ttl_s')];
    }
    $xp = (int) round($hp);
    $row['xp'] = (int) $row['xp'] + $xp;
    $row['level'] = wf_level_from_xp((int) $row['xp'])['level'];
    $stats['trees'] = (int) ($stats['trees'] ?? 0) + 1;
    $res['ok'] = true;
    $res['reward'] = ['log' => $logs];
    $res['drops'] = $drops;
    $res['xp'] = $xp;

    return $res;
}

/** Семена для списка клиенту: item => порода для всех видов семян (обычные и редкие). */
function wf_garden_seed_catalog(): array
{
    $out = [];
    foreach ((array) forest_config('trees.species') as $id => $sp) {
        $out['seed_' . $id] = ['species' => (string) $id, 'grow_days' => (int) $sp['grow_days'], 'logs' => $sp['logs']];
    }

    return $out;
}
