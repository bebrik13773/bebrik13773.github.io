<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: чистые функции веса и вместимости рюкзака (ДЛ-13), без БД. Зеркало PACK в shared/formulas.js;
 * проверяются по общим векторам tests/vectors/pack.json на forest-selftest.
 */
require_once __DIR__ . '/common.php';

/** Вес одной штуки, кг. Бревно считается средним (4–10), точный вес по размеру дерева придёт позже. */
function wf_pack_item_kg(string $item): float
{
    $w = forest_config('hero.weights_kg');
    if ($item === 'log') {
        return ((float) $w['log_min'] + (float) $w['log_max']) / 2;
    }

    return (float) ($w[$item] ?? 1.0);
}

/** Вес рюкзака, кг, по списку [item => qty]. */
function wf_pack_kg(array $items): float
{
    $kg = 0.0;
    foreach ($items as $key => $qty) {
        $kg += wf_pack_item_kg((string) $key) * max(0, (int) $qty);
    }

    return round($kg, 2);
}

/** Сколько штук предмета ещё влезет в рюкзак (0…$qty). */
function wf_pack_fit(float $capacity, float $loadKg, string $item, int $qty): int
{
    $per = wf_pack_item_kg($item);
    if ($per <= 0.0) {
        return max(0, $qty);
    }
    $room = $capacity - $loadKg;
    if ($room <= 0.0) {
        return 0;
    }

    return max(0, min($qty, (int) floor($room / $per + 1e-9)));
}

/**
 * Груз для проверки скорости: вес рюкзака минус то, что игрок бросает в этом же пакете (бросил и побежал — честно).
 * Подбор вес не снижает, поэтому его не учитываем (так проверка мягче к честному игроку, но не к «тяжёлому бегуну»).
 */
function wf_pack_move_load(array $items, array $actions): float
{
    $left = [];
    foreach ($items as $key => $qty) {
        $left[(string) $key] = max(0, (int) $qty);
    }
    foreach ($actions as $action) {
        if (!is_array($action) || ($action['type'] ?? '') !== 'drop') {
            continue;
        }
        $key = isset($action['item']) ? (string) $action['item'] : '';
        $qty = isset($action['qty']) && is_numeric($action['qty']) ? (int) $action['qty'] : 0;
        if ($key !== '' && isset($left[$key]) && $qty > 0) {
            $left[$key] = max(0, $left[$key] - $qty);
        }
    }

    return wf_pack_kg($left);
}
