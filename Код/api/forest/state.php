<?php
/** GET /api/forest/state: полное состояние игрока при входе (позиция, энергия, рюкзак, поляна, пруд). Нет профиля, то bad_state с need_join. */

require_once __DIR__ . '/lib/player.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    forest_require_method('GET');
    if (!forest_ip_guard()) {
        forest_error('rate_limited');
    }
    $conn = forest_db();
    $playerId = forest_require_player($conn, true);

    $row = wf_player_row($conn, $playerId);
    if (!$row || (string) $row['village_name'] === '') {
        forest_error('bad_state', 'Игрока в лесу ещё нет.', ['need_join' => true]);
    }

    forest_json_out(wf_player_full_state($conn, $row));
} catch (Throwable $error) {
    forest_fail($error);
}
