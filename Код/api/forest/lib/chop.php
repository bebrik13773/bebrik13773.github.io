<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: рубка деревьев (ДЛ-11). Чистые функции без БД: формулы (зеркало shared/formulas.js), проверка действия chop_done,
 * награда, находки сокровища, места выпадения, бюджет тапов и страйки. Тестируются таблицей кейсов на tools/forest-selftest.php.
 */
require_once __DIR__ . '/worldgen.php';

/** Сила топора по уровню (1..6). */
function wf_chop_power(int $axeLvl): float
{
    $axe = forest_config('trees.axe');
    $lvl = max(1, min(count($axe), $axeLvl));

    return (float) $axe[$lvl]['power'];
}

/** Прочность дерева: 8 × H × k_размер × (1 + 0.30 × tier). */
function wf_chop_hp(string $speciesId, int $size, int $tier): float
{
    $sp = forest_config('trees.species')[$speciesId] ?? null;
    if (!$sp) {
        return 0.0;
    }
    $k = forest_config('trees.size_k');
    $kk = [(float) $k['small'], (float) $k['medium'], (float) $k['large']];

    return (float) forest_config('trees.hp_base') * (float) $sp['h'] * $kk[max(0, min(2, $size))] * (1 + (float) forest_config('trees.hp_tier_k') * max(0, $tier));
}

/** Сколько ударов нужно (урон тапа = сила топора). Допуск 1e-9, как в JS. */
function wf_chop_need(float $hp, float $power): int
{
    return max(1, (int) ceil($hp / $power - 1e-9));
}

function wf_chop_cost(int $need): float
{
    return $need * (float) forest_config('hero.cost_chop');
}

function wf_chop_logs(string $speciesId, int $size): int
{
    $sp = forest_config('trees.species')[$speciesId] ?? null;

    return $sp ? (int) $sp['logs'][max(0, min(2, $size))] : 0;
}

/**
 * Бюджет тапов (ведро токенов): вместимость limits.tap_bucket, пополнение limits.max_taps_per_s в секунду.
 * Возвращает ['ok' => bool, 'tokens' => float] (токены с пополнением; при ok уже списано $want).
 */
function wf_tap_take(float $tokens, int $tsMs, int $nowMs, float $want): array
{
    $refill = min((float) forest_config('limits.tap_bucket'), $tokens + (max(0, $nowMs - $tsMs) / 1000.0) * (float) forest_config('limits.max_taps_per_s'));
    if ($want <= $refill + 1e-9) {
        return ['ok' => true, 'tokens' => $refill - $want];
    }

    return ['ok' => false, 'tokens' => $refill];
}

/**
 * Проверка рубки без БД. Вход ($c):
 *  kind (вид клетки, 1 = дерево), cleared (внутри расчищенного круга), felled (уже срублено),
 *  dist_tree (от заявленной позиции до дерева), dist_prev / dist_new (от заявленной позиции до прошлой и новой позиции сервера),
 *  allow (допуск перемещения за время между sync), hits, need, energy, cost, tokens, window_ms (по меткам клиента или null).
 * Возвращает ['ok' => bool, 'error' => код|null, 'cheat' => bool]; cheat = страйк и запись в журнал.
 */
function wf_chop_validate(array $c): array
{
    $fail = static function (string $code, bool $cheat): array {
        return ['ok' => false, 'error' => $code, 'cheat' => $cheat];
    };
    if ((int) $c['kind'] !== WF_WG_KIND_TREE) {
        return $fail('no_tree', true);
    }
    if (!empty($c['cleared'])) {
        return $fail('no_tree', false);
    }
    $reach = (float) forest_config('limits.chop_reach_m');
    if ((float) $c['dist_tree'] > $reach + 1e-9) {
        return $fail('too_far', true);
    }
    if ((float) $c['dist_prev'] > (float) $c['allow'] + 1e-9 || (float) $c['dist_new'] > (float) $c['allow'] + 1e-9) {
        return $fail('too_far', true);
    }
    if (!empty($c['felled'])) {
        return $fail('tree_gone', false);
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

/** Глобальные индексы клетки (gx, gz) по id дерева. */
function wf_chop_cell_xy(int $cx, int $cz, int $ci): array
{
    return [$cx * WF_WG_CELLS_PER_CHUNK + ($ci % WF_WG_CELLS_PER_CHUNK), $cz * WF_WG_CELLS_PER_CHUNK + intdiv($ci, WF_WG_CELLS_PER_CHUNK)];
}

/** Положение дерева в метрах: центр клетки плюс смещение. */
function wf_chop_tree_pos(array $cell): array
{
    return [($cell['X'] + $cell['ox']) / 10.0, ($cell['Z'] + $cell['oz']) / 10.0];
}

/** Находка в дереве-сокровище, детерминированно по клетке: ['coins' => n] или ['item' => key, 'qty' => n]. */
function wf_chop_treasure(array $w, int $cx, int $cz, int $ci): array
{
    [$gx, $gz] = wf_chop_cell_xy($cx, $cz, $ci);
    $salts = forest_config('trees.chop.salts');
    $table = forest_config('trees.treasure_loot');
    $total = 0;
    foreach ($table as $row) {
        $total += (int) $row['weight'];
    }
    $pick = wf_wg_hash($w['seed'], $gx, $gz, (int) $salts['treasure_pick']) % max(1, $total);
    $amount = wf_wg_hash($w['seed'], $gx, $gz, (int) $salts['treasure_amount']);
    foreach ($table as $row) {
        if ($pick < (int) $row['weight']) {
            if ($row['kind'] === 'coins') {
                [$lo, $hi] = forest_config('trees.treasure_coins');

                return ['coins' => (int) $lo + $amount % ((int) $hi - (int) $lo + 1)];
            }
            $qty = (int) $row['qty'][0] + $amount % ((int) $row['qty'][1] - (int) $row['qty'][0] + 1);
            if ($row['kind'] === 'seed_rare') {
                $rare = [];
                foreach (forest_config('trees.species') as $id => $sp) {
                    if (in_array($id, ['gold_birch', 'ancient_cedar', 'iron_oak'], true)) {
                        $rare[] = $id;
                    }
                }

                return ['item' => 'seed_' . $rare[$amount % count($rare)], 'qty' => $qty];
            }

            return ['item' => (string) $row['item'], 'qty' => $qty];
        }
        $pick -= (int) $row['weight'];
    }

    return ['coins' => (int) forest_config('trees.treasure_coins')[0]];
}

/** Места выпадения $count предметов вокруг пня (метры, 2 знака), детерминированно по клетке. */
function wf_chop_drop_points(array $w, int $cx, int $cz, int $ci, float $x, float $z, int $count): array
{
    [$gx, $gz] = wf_chop_cell_xy($cx, $cz, $ci);
    $salts = forest_config('trees.chop.salts');
    $rMin = (float) forest_config('trees.chop.drop_r_min_m');
    $rMax = (float) forest_config('trees.chop.drop_r_max_m');
    $out = [];
    for ($k = 0; $k < $count; $k++) {
        $a = (wf_wg_hash($w['seed'], $gx, $gz, (int) $salts['drop_angle'] + $k) % 3600) / 3600.0 * 2 * M_PI;
        $r = $rMin + (wf_wg_hash($w['seed'], $gx, $gz, (int) $salts['drop_radius'] + $k) % 1000) / 1000.0 * ($rMax - $rMin);
        $out[] = ['x' => round($x + cos($a) * $r, 2), 'z' => round($z + sin($a) * $r, 2)];
    }

    return $out;
}

/**
 * Страйки в скользящем окне (раздел 7.5): добавляет метку времени, убирает старые.
 * Возвращает ['list' => [...метки], 'count' => n, 'level' => 'soft'|'cut'|'block'|'none'].
 */
function wf_strikes_add(array $list, int $now): array
{
    $window = (int) forest_config('limits.strikes_window_s');
    $list = array_values(array_filter($list, static function ($t) use ($now, $window) {
        return is_numeric($t) && $now - (int) $t < $window;
    }));
    $list[] = $now;
    $n = count($list);
    $level = 'none';
    if ($n >= (int) forest_config('limits.strikes_block')) {
        $level = 'block';
    } elseif ($n > (int) forest_config('limits.strikes_soft')) {
        $level = 'cut';
    } else {
        $level = 'soft';
    }

    return ['list' => $list, 'count' => $n, 'level' => $level];
}

/** Сколько страйков сейчас активно (без добавления). */
function wf_strikes_active(array $list, int $now): int
{
    $window = (int) forest_config('limits.strikes_window_s');
    $n = 0;
    foreach ($list as $t) {
        if (is_numeric($t) && $now - (int) $t < $window) {
            $n++;
        }
    }

    return $n;
}

/** Множитель награды при подозрительном поведении: 4 и больше страйков → ×0.5. */
function wf_strikes_reward_k(int $activeStrikes): float
{
    return $activeStrikes > (int) forest_config('limits.strikes_soft') ? (float) forest_config('trees.chop.strike_cut_reward') : 1.0;
}

/** Текущая энергия строки игрока: восстановление и расход считает wf_energy_tick в начале sync (ДЛ-12), здесь только ограничение сверху. */
function wf_energy_now(float $energy, int $energyTs, int $now, float $max): float
{
    return max(0.0, min($max, $energy));
}
