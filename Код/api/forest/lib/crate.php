<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: ящик-тайник на поляне и подвода скупщика (ДЛ-14).
 * Чистые функции (места, вместимость, цена, дневной лимит) без БД; действия crate_put, crate_take и sell
 * работают внутри транзакции sync (строка игрока заблокирована). Деньги, цены и лимит считает только сервер.
 */
require_once __DIR__ . '/pack.php';

/** Места ящика и подводы: смещения от центра поляны (конфиг village). Возвращает ['crate' => [x, z], 'buyer' => [x, z]]. */
function wf_village_spots(float $spawnX, float $spawnZ): array
{
    $c = (array) forest_config('village.crate_offset');
    $b = (array) forest_config('village.buyer_offset');

    return [
        'crate' => [$spawnX + (float) $c[0], $spawnZ + (float) $c[1]],
        'buyer' => [$spawnX + (float) $b[0], $spawnZ + (float) $b[1]],
    ];
}

/** Занято мест в ящике (одна штука предмета = одно место). */
function wf_crate_units(array $items): int
{
    $n = 0;
    foreach ($items as $qty) {
        $n += max(0, (int) $qty);
    }

    return $n;
}

/** Сколько штук ещё влезет в ящик (0…$qty). */
function wf_crate_fit(int $capacity, int $used, int $qty): int
{
    return max(0, min($qty, $capacity - $used));
}

/** Цена одной штуки у скупщика (дробная, до округления итога): базовая цена × доля скупщика. 0 = скупщик это не берёт. */
function wf_buyer_unit(string $item): float
{
    $allowed = (array) forest_config('economy.starter_buyer_items');
    if (!in_array($item, $allowed, true)) {
        return 0.0;
    }
    $prices = (array) forest_config('economy.prices');
    $base = (float) ($prices[$item] ?? 0);

    return $base * (float) forest_config('economy.starter_buyer_ratio');
}

/** Выручка за qty штук (целые монеты, округление вниз). */
function wf_buyer_total(string $item, int $qty): int
{
    return (int) floor(wf_buyer_unit($item) * max(0, $qty) + 1e-9);
}

/** Сколько штук скупщик возьмёт, чтобы выручка не превысила остаток дневного лимита $left (0…$qty). */
function wf_buyer_max_qty(string $item, int $qty, int $left): int
{
    $u = wf_buyer_unit($item);
    if ($u <= 0.0 || $left <= 0 || $qty <= 0) {
        return 0;
    }
    // Наибольшее q с floor(u × q) ≤ left, то есть u × q < left + 1.
    $q = (int) ceil(($left + 1) / $u - 1e-9) - 1;

    return max(0, min($qty, $q));
}

/** Ключ реальных суток по часовому поясу игры (кандидаты, лимиты «в сутки»). */
function wf_day_key(int $ts): string
{
    $d = new DateTime('@' . $ts);
    $d->setTimezone(new DateTimeZone((string) forest_config('time.tz')));

    return $d->format('Y-m-d');
}

/** Сколько монет скупщик ещё готов заплатить сегодня. $stats: из stats_json игрока. */
function wf_buyer_left(array $stats, int $now): int
{
    $cap = (int) forest_config('economy.starter_buyer_daily_cap');
    $sold = isset($stats['sold']) && is_array($stats['sold']) && count($stats['sold']) === 2 ? $stats['sold'] : null;
    if ($sold === null || (string) $sold[0] !== wf_day_key($now)) {
        return $cap;
    }

    return max(0, $cap - (int) $sold[1]);
}

/** Цены скупщика за штуку (для показа игроку): [item => дробная цена]. */
function wf_buyer_prices(): array
{
    $out = [];
    foreach ((array) forest_config('economy.starter_buyer_items') as $item) {
        $out[(string) $item] = round(wf_buyer_unit((string) $item), 2);
    }

    return $out;
}

/** Забирает предмет из контейнера игрока ('pack' или 'crate'). false = столько нет. */
function wf_container_take($conn, int $playerId, string $container, string $item, int $qty): bool
{
    $stmt = $conn->prepare('UPDATE `forest_inventory` SET `qty` = `qty` - ? WHERE `player_id` = ? AND `container` = ? AND `item_key` = ? AND `qty` >= ?');
    $stmt->bind_param('iissi', $qty, $playerId, $container, $item, $qty);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

/** Кладёт предмет в контейнер игрока. */
function wf_container_add($conn, int $playerId, string $container, string $item, int $qty): void
{
    $stmt = $conn->prepare('INSERT INTO `forest_inventory` (`player_id`, `container`, `item_key`, `qty`) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE `qty` = `qty` + VALUES(`qty`)');
    $stmt->bind_param('issi', $playerId, $container, $item, $qty);
    $stmt->execute();
    $stmt->close();
}

/** Содержимое одного контейнера: [item => qty]. */
function wf_container_items($conn, int $playerId, string $container): array
{
    $items = [];
    $stmt = $conn->prepare('SELECT `item_key`, `qty` FROM `forest_inventory` WHERE `player_id` = ? AND `container` = ? AND `qty` > 0');
    $stmt->bind_param('is', $playerId, $container);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($res && ($r = $res->fetch_assoc())) {
        $items[(string) $r['item_key']] = (int) $r['qty'];
    }
    $stmt->close();

    return $items;
}

/**
 * Рядом ли бобёр с точкой (ящик, подвода). Позиция берётся из действия (px, pz) или из пакета; допуск движения как у подбора.
 * Возвращает [ok, расстояние].
 */
function wf_crate_near(array $action, array $ctx, float $x, float $z, float $reach): array
{
    $px = isset($action['px']) && is_numeric($action['px']) ? (float) $action['px'] : $ctx['new']['x'];
    $pz = isset($action['pz']) && is_numeric($action['pz']) ? (float) $action['pz'] : $ctx['new']['z'];
    $dist = hypot($px - $x, $pz - $z);
    $okPos = hypot($px - $ctx['prev']['x'], $pz - $ctx['prev']['z']) <= $ctx['allow'] && hypot($px - $ctx['new']['x'], $pz - $ctx['new']['z']) <= $ctx['allow'];

    return [$dist <= $reach && $okPos, $dist];
}

/** Общая проверка названия предмета и количества у действий ящика и продажи. Возвращает [item, qty] или null. */
function wf_crate_args(array $action): ?array
{
    $item = isset($action['item']) ? (string) $action['item'] : '';
    $qty = isset($action['qty']) && is_numeric($action['qty']) ? (int) $action['qty'] : 0;
    if (!preg_match('/^[a-z_]{1,32}$/', $item) || $qty < 1 || $qty > (int) forest_config('village.max_move_qty')) {
        return null;
    }

    return [$item, $qty];
}

/**
 * Действия crate_put (из рюкзака в ящик) и crate_take (из ящика в рюкзак). $action: item, qty, px, pz.
 * Не влезло — переносим сколько влезает (частичный перенос), нечего переносить — no_items, нет места — no_space.
 */
function wf_action_crate($conn, array &$row, array &$stats, array $action, array $ctx, string $type): array
{
    $a = (int) $action['a'];
    $args = wf_crate_args($action);
    $res = ['a' => $a, 'ok' => false, 'type' => $type, 'item' => isset($action['item']) ? (string) $action['item'] : ''];
    if ($args === null) {
        $res['error'] = 'bad_state';

        return $res;
    }
    [$item, $qty] = $args;
    $pid = (int) $row['player_id'];
    $spots = wf_village_spots((float) $row['spawn_x'], (float) $row['spawn_z']);
    [$near, $dist] = wf_crate_near($action, $ctx, $spots['crate'][0], $spots['crate'][1], (float) forest_config('village.crate_reach_m'));
    if (!$near) {
        $res['error'] = 'too_far';
        if ($dist > 3 * (float) forest_config('village.crate_reach_m')) {
            forest_log($conn, $pid, 'crate_too_far', ['dist' => round($dist, 1)]);
        }

        return $res;
    }

    $from = $type === 'crate_put' ? 'pack' : 'crate';
    $to = $type === 'crate_put' ? 'crate' : 'pack';
    $haveItems = wf_container_items($conn, $pid, $from);
    $have = (int) ($haveItems[$item] ?? 0);
    if ($have <= 0) {
        $res['error'] = 'no_items';

        return $res;
    }
    $want = min($qty, $have);
    if ($type === 'crate_put') {
        $used = wf_crate_units(wf_container_items($conn, $pid, 'crate'));
        $move = wf_crate_fit((int) forest_config('economy.crate_capacity'), $used, $want);
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
    $res['left'] = $qty - $move; // запрошено, но не перенесено

    return $res;
}

/**
 * Действие sell: продать скупщику на поляне из рюкзака. $action: item, qty, px, pz.
 * Скупщик берёт только свои товары (starter_buyer_items) и не больше суточного лимита выручки (starter_buyer_daily_cap).
 * Лимит упёрся посреди пачки — продаём, сколько влезает под лимит. Выручка, опыт и счётчик «продано» считаются здесь.
 */
function wf_action_sell($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $args = wf_crate_args($action);
    $res = ['a' => $a, 'ok' => false, 'type' => 'sell', 'item' => isset($action['item']) ? (string) $action['item'] : ''];
    if ($args === null) {
        $res['error'] = 'bad_state';

        return $res;
    }
    [$item, $qty] = $args;
    $pid = (int) $row['player_id'];
    $now = (int) $ctx['now'];
    if (wf_buyer_unit($item) <= 0.0) {
        $res['error'] = 'not_wanted';

        return $res;
    }
    $spots = wf_village_spots((float) $row['spawn_x'], (float) $row['spawn_z']);
    [$near, $dist] = wf_crate_near($action, $ctx, $spots['buyer'][0], $spots['buyer'][1], (float) forest_config('village.buyer_reach_m'));
    if (!$near) {
        $res['error'] = 'too_far';
        if ($dist > 3 * (float) forest_config('village.buyer_reach_m')) {
            forest_log($conn, $pid, 'sell_too_far', ['dist' => round($dist, 1)]);
        }

        return $res;
    }

    $haveItems = wf_container_items($conn, $pid, 'pack');
    $have = (int) ($haveItems[$item] ?? 0);
    if ($have <= 0) {
        $res['error'] = 'no_items';

        return $res;
    }
    $left = wf_buyer_left($stats, $now);
    $sell = wf_buyer_max_qty($item, min($qty, $have), $left);
    if ($sell <= 0) {
        $res['error'] = 'daily_limit';
        $res['left'] = $left;

        return $res;
    }
    if (!wf_container_take($conn, $pid, 'pack', $item, $sell)) {
        $res['error'] = 'no_items';

        return $res;
    }

    $total = wf_buyer_total($item, $sell);
    $row['coins'] = (int) $row['coins'] + $total;
    $day = wf_day_key($now);
    $soldToday = isset($stats['sold']) && is_array($stats['sold']) && count($stats['sold']) === 2 && (string) $stats['sold'][0] === $day ? (int) $stats['sold'][1] : 0;
    $stats['sold'] = [$day, $soldToday + $total];
    $stats['sold_value'] = (int) ($stats['sold_value'] ?? 0) + $total; // для рейтинга (ДЛ-34)
    // Опыт за продажи равен выручке (ДЛ-10).
    $row['xp'] = (int) $row['xp'] + $total;
    $row['level'] = wf_level_from_xp((int) $row['xp'])['level'];

    $res['ok'] = true;
    $res['sold'] = $sell;
    $res['coins'] = $total;
    $res['left_today'] = wf_buyer_left($stats, $now);
    $res['rest'] = $qty - $sell; // запрошено, но не продано (упёрлись в лимит или в запас)

    return $res;
}
