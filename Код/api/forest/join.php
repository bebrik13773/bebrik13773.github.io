<?php
/** POST /api/forest/join: создаёт игрока в лесу и ставит на поляну. Повторный вызов безопасен. Возвращает полное состояние. */

require_once __DIR__ . '/lib/player.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    forest_require_method('POST');
    if (!forest_ip_guard()) {
        forest_error('rate_limited');
    }
    $conn = forest_db();
    $playerId = forest_require_player($conn, true);
    forest_json_in();

    [$row, $created] = wf_player_join($conn, $playerId);
    if (!$row) {
        forest_error('server_error', 'Не удалось создать игрока.');
    }

    $data = wf_player_full_state($conn, $row);
    $data['created'] = $created;
    forest_json_out($data);
} catch (Throwable $error) {
    forest_fail($error);
}
