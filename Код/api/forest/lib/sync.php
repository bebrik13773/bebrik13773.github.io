<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: разбор пакета sync версии 1 (ДЛ-10). Чистая проверка позиции и частоты без БД,
 * чтобы тестировать таблицей кейсов на forest-selftest.
 */
require_once __DIR__ . '/formulas.php';

/**
 * Проверка перемещения: расстояние от прошлой позиции не больше vMax × dt × 1.35 + 3 м.
 * $dtSec ограничен limits.move_dt_cap_s. Возвращает ['ok' => bool, 'dist' => , 'allowed' => ].
 * $load — вес ноши, кг: тяжёлый рюкзак снижает допустимую скорость по той же формуле, что и у клиента.
 */
function wf_sync_check_move(float $fromX, float $fromZ, float $toX, float $toZ, float $dtSec, float $capacity = 40.0, float $load = 0.0): array
{
    $dt = max(0.0, min((float) forest_config('limits.move_dt_cap_s'), $dtSec));
    // Груз замедляет бобра (ДЛ-13). Дороги и сапоги появятся в ДЛ-24 и ДЛ-18, тогда сюда придут их настоящие значения.
    $vMax = wf_hero_speed($load, $capacity, 0, false);
    $allowed = wf_move_allowance($vMax, $dt);
    $dist = hypot($toX - $fromX, $toZ - $fromZ);

    return ['ok' => $dist <= $allowed + 1e-9, 'dist' => $dist, 'allowed' => $allowed];
}

/** Следующий интервал sync, мс: активный, простой (нет действий и движения дольше idle_after_s). */
function wf_sync_next_ms(int $idleSeconds, bool $background = false): int
{
    if ($background) {
        return (int) forest_config('limits.sync_background_ms');
    }

    return $idleSeconds >= (int) forest_config('limits.idle_after_s')
        ? (int) forest_config('limits.sync_idle_ms')
        : (int) forest_config('limits.sync_active_ms');
}

/** Приводит позицию из запроса к числам и проверяет границы мира. Возвращает [x, z, ry] или null. */
function wf_sync_parse_pos($pos): ?array
{
    if (!is_array($pos) || !isset($pos['x'], $pos['z'])) {
        return null;
    }
    if (!is_numeric($pos['x']) || !is_numeric($pos['z'])) {
        return null;
    }
    $x = (float) $pos['x'];
    $z = (float) $pos['z'];
    $ry = isset($pos['ry']) && is_numeric($pos['ry']) ? (float) $pos['ry'] : 0.0;
    $r = (float) forest_config('world.world_radius_m');
    if (!is_finite($x) || !is_finite($z) || abs($x) > $r || abs($z) > $r) {
        return null;
    }

    return [$x, $z, $ry];
}
