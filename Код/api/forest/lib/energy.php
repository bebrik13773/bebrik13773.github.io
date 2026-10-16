<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: энергия, сон и еда (ДЛ-12). Ленивый расчёт по времени между sync, без фоновых процессов.
 * Чистые функции (wf_energy_rate, wf_energy_tick, wf_energy_walk_cost, wf_eat_gain) без БД проверяются на forest-selftest
 * и по общим векторам tests/vectors/energy.json; функции с БД (дом, еда) внизу файла.
 */
require_once __DIR__ . '/player.php';
require_once __DIR__ . '/chop.php';

/** Расход энергии на ходьбу: метры × 0.015 × множитель погоды (множитель придёт в ДЛ-28, пока 1.0). */
function wf_energy_walk_cost(float $distM, float $mult = 1.0): float
{
    return max(0.0, $distM) * (float) forest_config('hero.cost_walk_per_m') * $mult;
}

/** Скорость восстановления, единиц в секунду: сон (0.2, в доме 1.0), отдых стоя (0.1, в доме 1.0), движение и работа 0. */
function wf_energy_rate(bool $sleeping, bool $home, bool $still): float
{
    if ($sleeping) {
        return $home ? (float) forest_config('hero.regen_home_ps') : (float) forest_config('hero.regen_sleep_ps');
    }
    if (!$still) {
        return 0.0;
    }

    return $home ? (float) forest_config('hero.regen_home_ps') : (float) forest_config('hero.regen_rest_ps');
}

/**
 * Один шаг ленивого расчёта между двумя sync. $in: energy, max, dt (секунд с прошлого расчёта), dist (метров пути),
 * still (стоял и ничего не делал), home (рядом с домом), sleeping, mult (погода). Возвращает [energy, sleeping].
 * Время восстановления ограничено limits.rest_credit_cap_s: длинный офлайн энергию не копит.
 */
function wf_energy_tick(array $in): array
{
    $eps = (float) forest_config('hero.energy_eps');
    $max = (float) $in['max'];
    $sleeping = (bool) $in['sleeping'];
    $e = (float) $in['energy'] - wf_energy_walk_cost((float) $in['dist'], (float) ($in['mult'] ?? 1.0));
    $e = max(0.0, $e);
    if (!$sleeping && $e <= $eps) {
        $sleeping = true; // силы кончились: бобёр засыпает на месте
    }
    $dt = max(0.0, min((float) $in['dt'], (float) forest_config('limits.rest_credit_cap_s')));
    $e = min($max, $e + wf_energy_rate($sleeping, (bool) $in['home'], (bool) $in['still']) * $dt);
    if ($sleeping && $e >= (float) forest_config('hero.wake_energy')) {
        $sleeping = false;
    }

    return ['energy' => $e, 'sleeping' => $sleeping];
}

/** После действий (рубка тоже тратит): если энергия на нуле, бобёр засыпает. */
function wf_energy_settle(float $energy, bool $sleeping): bool
{
    return $sleeping || $energy <= (float) forest_config('hero.energy_eps');
}

/** Сколько энергии даёт еда: 'fish'/'fish_small' малая, 'fish_big' крупная; null — не еда. */
function wf_eat_gain(string $item): ?float
{
    $map = ['fish' => 'small', 'fish_small' => 'small', 'fish_big' => 'big'];
    if (!isset($map[$item])) {
        return null;
    }
    $t = forest_config('hero.fish_energy');

    return (float) $t[$map[$item]];
}

/** Рядом ли с готовым домом игрока (в радиусе hero.home_radius_m). До ДЛ-17 домов нет, тогда всегда false. */
function wf_energy_home($conn, int $playerId, float $x, float $z): bool
{
    $r = (float) forest_config('hero.home_radius_m');
    $r2 = $r * $r;
    $stmt = $conn->prepare('SELECT 1 FROM `forest_buildings` WHERE `player_id` = ? AND `type` = \'house\' AND `state` = \'ready\' AND ((`x` - ?) * (`x` - ?) + (`z` - ?) * (`z` - ?)) <= ? LIMIT 1');
    $stmt->bind_param('idddddd', $playerId, $x, $x, $z, $z, $r2);
    $stmt->execute();
    $res = $stmt->get_result();
    $found = $res && $res->fetch_row() !== null;
    $stmt->close();

    return $found;
}

/**
 * Действие eat: съесть рыбу из рюкзака. Пауза между приёмами hero.eat_pause_s. Меняет $row (энергия) и $stats (eat_ms, sleep).
 */
function wf_action_eat($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $item = isset($action['item']) ? (string) $action['item'] : '';
    $res = ['a' => $a, 'ok' => false, 'type' => 'eat', 'item' => $item];
    $gain = wf_eat_gain($item);
    if ($gain === null) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $max = wf_player_energy_max((int) $row['stamina_lvl']);
    $energy = wf_energy_now((float) $row['energy'], (int) $row['energy_ts'], $ctx['now'], $max);
    if ($energy >= $max - 0.5) {
        $res['error'] = 'bad_state';
        $res['full'] = true;

        return $res;
    }
    $last = (int) ($stats['eat_ms'] ?? 0);
    if ($ctx['now_ms'] - $last < (int) forest_config('hero.eat_pause_s') * 1000) {
        $res['error'] = 'too_fast';

        return $res;
    }
    $stmt = $conn->prepare('UPDATE `forest_inventory` SET `qty` = `qty` - 1 WHERE `player_id` = ? AND `container` = \'pack\' AND `item_key` = ? AND `qty` >= 1');
    $pid = (int) $row['player_id'];
    $stmt->bind_param('is', $pid, $item);
    $stmt->execute();
    $taken = $stmt->affected_rows > 0;
    $stmt->close();
    if (!$taken) {
        $res['error'] = 'no_items';

        return $res;
    }
    $energy = min($max, $energy + $gain);
    $row['energy'] = $energy;
    $row['energy_ts'] = $ctx['now'];
    $stats['eat_ms'] = $ctx['now_ms'];
    if (!empty($stats['sleep']) && $energy >= (float) forest_config('hero.wake_energy')) {
        $stats['sleep'] = 0; // поел и проснулся
    }
    $res['ok'] = true;
    $res['gain'] = $gain;
    $res['energy'] = round($energy, 2);

    return $res;
}
