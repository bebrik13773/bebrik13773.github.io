<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: общие формулы (ДЛ-08). Зеркало Код/games/wild-forest/src/shared/formulas.js, числа берутся из config.php (hero.*).
 * Любое изменение здесь делается вместе с JS-версией; совпадение проверяет tools/forest-selftest.php (раздел «Управление»).
 */
/**
 * Скорость бобра, м/с: v = v0 × (1 − 0.55 × min(1, груз/вместимость)^1.2) × (1 + дорога) × (1 + сапоги) × погода.
 * $road: 0 нет, 1 земля, 2 камень, 3 асфальт.
 */
function wf_hero_speed($load = 0.0, $capacity = 40.0, $road = 0, $boots = false, $weather = 1.0)
{
    $frac = $capacity > 0 ? min(1.0, max(0.0, (float) $load) / (float) $capacity) : 1.0;
    $carry = 1.0 - (float) forest_config('hero.load_slowdown') * pow($frac, (float) forest_config('hero.load_exponent'));
    $bonus = forest_config('hero.road_bonus');
    $roadK = 1.0 + (float) ($bonus[max(0, min(count($bonus) - 1, (int) $road))] ?? 0);
    $bootsK = 1.0 + ($boots ? (float) forest_config('hero.boots_bonus') : 0.0);
    return (float) forest_config('hero.walk_speed') * $carry * $roadK * $bootsK * (float) $weather;
}

/** Сколько метров допустимо пройти за $dtSec секунд при максимальной скорости $vMax: vMax × dt × 1.35 + 3 м. */
function wf_move_allowance($vMax, $dtSec)
{
    return (float) $vMax * max(0.0, (float) $dtSec) * (float) forest_config('hero.move_slack') + (float) forest_config('hero.move_const_m');
}

/**
 * Проверка журнала позиций [{t, x, z}, ...] по правилу сервера. Возвращает список индексов точек, где шаг больше допустимого
 * (пустой список = всё честно). $vMax одинаков для всего журнала (пересчёт по грузу и дороге делает вызывающий код).
 */
function wf_check_track(array $points, $vMax)
{
    $bad = [];
    for ($i = 1; $i < count($points); $i++) {
        $a = $points[$i - 1];
        $b = $points[$i];
        $dist = hypot((float) $b['x'] - (float) $a['x'], (float) $b['z'] - (float) $a['z']);
        if ($dist > wf_move_allowance($vMax, (float) $b['t'] - (float) $a['t']) + 1e-9) {
            $bad[] = $i;
        }
    }
    return $bad;
}

/** Уровень игрока (ДЛ-10): с уровня l на l+1 нужно round(base × l^exp) опыта. Зеркало shared/formulas.js (xpToNext, levelFromXp). */
function wf_xp_to_next(int $level): int
{
    return (int) round((float) forest_config('hero.xp_base') * pow($level, (float) forest_config('hero.xp_exp')));
}

/** ['level' => , 'into' => , 'need' => ] по общему опыту. */
function wf_level_from_xp(int $xp): array
{
    $max = (int) forest_config('hero.level_max');
    $level = 1;
    $rest = max(0, $xp);
    while ($level < $max && $rest >= wf_xp_to_next($level)) {
        $rest -= wf_xp_to_next($level);
        $level++;
    }

    return ['level' => $level, 'into' => $rest, 'need' => $level >= $max ? 0 : wf_xp_to_next($level)];
}
