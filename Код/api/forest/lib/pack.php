<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: рюкзак, вес ноши, подбор и бросание предметов (ДЛ-13).
 * Чистые функции веса лежат в pack_weight.php; действия pickup и drop работают внутри транзакции sync (строка игрока заблокирована).
 */
require_once __DIR__ . '/pack_weight.php';
require_once __DIR__ . '/player.php';
require_once __DIR__ . '/actions.php';

/** Добавляет предмет в рюкзак. */
function wf_pack_add($conn, int $playerId, string $item, int $qty): void
{
    $stmt = $conn->prepare('INSERT INTO `forest_inventory` (`player_id`, `container`, `item_key`, `qty`) VALUES (?, \'pack\', ?, ?) ON DUPLICATE KEY UPDATE `qty` = `qty` + VALUES(`qty`)');
    $stmt->bind_param('isi', $playerId, $item, $qty);
    $stmt->execute();
    $stmt->close();
}

/** Забирает предмет из рюкзака. false = столько нет. */
function wf_pack_take($conn, int $playerId, string $item, int $qty): bool
{
    $stmt = $conn->prepare('UPDATE `forest_inventory` SET `qty` = `qty` - ? WHERE `player_id` = ? AND `container` = \'pack\' AND `item_key` = ? AND `qty` >= ?');
    $stmt->bind_param('iisi', $qty, $playerId, $item, $qty);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

/** Убирает просроченные предметы с земли (порциями, чтобы таблица не росла). */
function wf_pack_gc($conn, int $now): void
{
    $limit = (int) forest_config('pack.gc_limit');
    $stmt = $conn->prepare('DELETE FROM `forest_drops` WHERE `expires_at` <= ? LIMIT ' . $limit);
    $stmt->bind_param('i', $now);
    $stmt->execute();
    $stmt->close();
}

/**
 * Действие pickup: поднять предмет с земли. $action: drop (id), px, pz (позиция бобра в момент подбора).
 * Рюкзак заполнен частично: берём, сколько влезает (стопка остаётся на земле с меньшим числом).
 */
function wf_action_pickup($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $id = isset($action['drop']) && is_numeric($action['drop']) ? (int) $action['drop'] : 0;
    $res = ['a' => $a, 'ok' => false, 'type' => 'pickup', 'drop' => $id];
    if ($id <= 0) {
        $res['error'] = 'no_items';

        return $res;
    }
    $pid = (int) $row['player_id'];
    $now = (int) $ctx['now'];

    $stmt = $conn->prepare('SELECT `x`, `z`, `item_key`, `qty`, `owner_id`, `owner_until`, `expires_at` FROM `forest_drops` WHERE `id` = ? LIMIT 1 FOR UPDATE');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $r = $stmt->get_result();
    $d = $r ? $r->fetch_assoc() : null;
    $stmt->close();
    // Нет, просрочено или ещё под защитой другого игрока: для клиента это «предмета нет».
    if (!$d || (int) $d['expires_at'] <= $now || ((int) $d['owner_id'] !== $pid && (int) $d['owner_until'] > $now)) {
        $res['error'] = 'no_items';

        return $res;
    }

    $px = isset($action['px']) && is_numeric($action['px']) ? (float) $action['px'] : $ctx['new']['x'];
    $pz = isset($action['pz']) && is_numeric($action['pz']) ? (float) $action['pz'] : $ctx['new']['z'];
    $distDrop = hypot($px - (float) $d['x'], $pz - (float) $d['z']);
    $okPos = hypot($px - $ctx['prev']['x'], $pz - $ctx['prev']['z']) <= $ctx['allow'] && hypot($px - $ctx['new']['x'], $pz - $ctx['new']['z']) <= $ctx['allow'];
    if ($distDrop > (float) forest_config('pack.pickup_r_m') || !$okPos) {
        $res['error'] = 'too_far';
        if ($distDrop > 3 * (float) forest_config('pack.pickup_r_m')) {
            forest_log($conn, $pid, 'pickup_too_far', ['drop' => $id, 'dist' => round($distDrop, 1)]);
        }

        return $res;
    }

    $item = (string) $d['item_key'];
    $have = (int) $d['qty'];
    $pack = wf_player_pack($conn, $pid);
    $take = wf_pack_fit(wf_player_pack_capacity((int) $row['pack_lvl']), (float) $pack['kg'], $item, $have);
    if ($take <= 0) {
        $res['error'] = 'no_space';

        return $res;
    }
    if ($take >= $have) {
        $del = $conn->prepare('DELETE FROM `forest_drops` WHERE `id` = ?');
        $del->bind_param('i', $id);
        $del->execute();
        $del->close();
    } else {
        $upd = $conn->prepare('UPDATE `forest_drops` SET `qty` = `qty` - ? WHERE `id` = ?');
        $upd->bind_param('ii', $take, $id);
        $upd->execute();
        $upd->close();
    }
    wf_pack_add($conn, $pid, $item, $take);

    $res['ok'] = true;
    $res['item'] = $item;
    $res['taken'] = $take;
    $res['left'] = $have - $take;

    return $res;
}

/** Действие drop: бросить предмет из рюкзака на землю рядом с бобром. $action: item, qty. */
function wf_action_drop($conn, array &$row, array &$stats, array $action, array $ctx): array
{
    $a = (int) $action['a'];
    $item = isset($action['item']) ? (string) $action['item'] : '';
    $qty = isset($action['qty']) && is_numeric($action['qty']) ? (int) $action['qty'] : 0;
    $res = ['a' => $a, 'ok' => false, 'type' => 'drop', 'item' => $item];
    if (!preg_match('/^[a-z_]{1,32}$/', $item) || $qty < 1 || $qty > (int) forest_config('pack.max_throw_qty')) {
        $res['error'] = 'bad_state';

        return $res;
    }
    $pid = (int) $row['player_id'];
    $now = (int) $ctx['now'];
    wf_pack_gc($conn, $now);

    $cnt = $conn->prepare('SELECT COUNT(*) FROM `forest_drops` WHERE `owner_id` = ? AND `expires_at` > ?');
    $cnt->bind_param('ii', $pid, $now);
    $cnt->execute();
    $c = $cnt->get_result();
    $n = $c ? (int) ($c->fetch_row()[0] ?? 0) : 0;
    $cnt->close();
    if ($n >= (int) forest_config('pack.max_player_drops')) {
        $res['error'] = 'rate_limited';

        return $res;
    }
    if (!wf_pack_take($conn, $pid, $item, $qty)) {
        $res['error'] = 'no_items';

        return $res;
    }

    // Куда ложится: рядом с бобром, направление зависит от номера действия (детерминированно, без хранения угла).
    $ang = fmod($a * 2.399963, 6.283185307);
    $x = $ctx['new']['x'] + cos($ang) * (float) forest_config('pack.throw_dist_m');
    $z = $ctx['new']['z'] + sin($ang) * (float) forest_config('pack.throw_dist_m');
    $cx = wf_wg_fdiv((int) floor($x), 32);
    $cz = wf_wg_fdiv((int) floor($z), 32);
    $until = $now + (int) forest_config('pack.thrown_owner_s');
    $exp = $now + (int) forest_config('trees.chop.drop_ttl_s');
    $stmt = $conn->prepare('INSERT INTO `forest_drops` (`x`, `z`, `cx`, `cz`, `item_key`, `qty`, `owner_id`, `owner_until`, `expires_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->bind_param('ddiisiiii', $x, $z, $cx, $cz, $item, $qty, $pid, $until, $exp);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    $res['ok'] = true;
    $res['drop'] = ['id' => $id, 'x' => round($x, 2), 'z' => round($z, 2), 'item' => $item, 'qty' => $qty, 'ttl' => $exp - $now];

    return $res;
}
