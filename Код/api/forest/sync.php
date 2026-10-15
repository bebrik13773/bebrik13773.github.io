<?php
/**
 * POST /api/forest/sync: объединённый пакет клиента (ДЛ-10, версия 1): позиция, номера действий, ответ с состоянием.
 * Действия: chop_done (ДЛ-11); подбор (ДЛ-13), стройка (ДЛ-16) и прочие подключаются в своих пунктах. Повтор действия с тем же номером безопасен.
 */

require_once __DIR__ . '/lib/player.php';
require_once __DIR__ . '/lib/sync.php';
require_once __DIR__ . '/lib/actions.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    forest_require_method('POST');
    if (!forest_ip_guard()) {
        forest_error('rate_limited');
    }
    $conn = forest_db();
    $playerId = forest_require_player($conn, false);

    if (!forest_rate_limit('sync:' . $playerId, (int) forest_config('limits.sync_min_interval_s') * 1000)) {
        forest_error('rate_limited', null, ['retry_ms' => (int) forest_config('limits.sync_min_interval_s') * 1000]);
    }

    $in = forest_json_in();
    $row = wf_player_row($conn, $playerId);
    if (!$row || (string) $row['village_name'] === '') {
        forest_error('bad_state', 'Игрока в лесу ещё нет.', ['need_join' => true]);
    }

    $now = time();
    if ((int) $row['blocked_until'] > $now) {
        forest_error('forbidden', 'Действия временно заблокированы.', ['until' => (int) $row['blocked_until']]);
    }

    $seq = isset($in['seq']) && is_numeric($in['seq']) ? (int) $in['seq'] : 0;
    $background = !empty($in['background']);
    $actions = isset($in['actions']) && is_array($in['actions']) ? $in['actions'] : [];
    if (count($actions) > (int) forest_config('limits.max_actions_per_sync')) {
        forest_error('bad_request', 'Слишком много действий в пакете.');
    }

    // Всё изменение игрока в одной транзакции с блокировкой строки: позиция, действия, энергия, награды.
    $out = forest_tx($conn, function ($conn) use ($playerId, $in, $actions, $now, $background) {
        $row = wf_player_row($conn, $playerId, true);
        $stats = wf_player_stats($row);
        $lastA = (int) ($stats['last_a'] ?? 0);
        $prevX = (float) $row['pos_x'];
        $prevZ = (float) $row['pos_z'];

        // Позиция: проверяем скорость, при нарушении возвращаем correct_pos.
        $correct = null;
        $moved = false;
        $newX = $prevX;
        $newZ = $prevZ;
        $newRy = (float) $row['pos_ry'];
        $dt = max(0, $now - (int) $row['last_seen']);
        $capacity = wf_player_pack_capacity((int) $row['pack_lvl']);
        $parsed = wf_sync_parse_pos($in['pos'] ?? null);
        if ($parsed !== null) {
            $check = wf_sync_check_move($prevX, $prevZ, $parsed[0], $parsed[1], (float) $dt, $capacity);
            if ($check['ok']) {
                $moved = $check['dist'] > 0.5;
                $newX = $parsed[0];
                $newZ = $parsed[1];
                $newRy = $parsed[2];
            } else {
                $correct = ['x' => $prevX, 'z' => $prevZ];
                forest_log($conn, $playerId, 'move_far', ['dist' => round($check['dist'], 1), 'allowed' => round($check['allowed'], 1), 'dt' => $dt]);
            }
        }
        // Допуск перемещения за время между sync нужен действиям, привязанным к месту (рубка).
        $allow = wf_sync_check_move($prevX, $prevZ, $prevX, $prevZ, (float) $dt, $capacity)['allowed'];
        $ctx = ['now' => $now, 'now_ms' => forest_now_ms(), 'prev' => ['x' => $prevX, 'z' => $prevZ], 'new' => ['x' => $newX, 'z' => $newZ], 'dt' => $dt, 'allow' => $allow];

        // Действия: номера a растут, обработанные пропускаем (идемпотентность).
        $results = [];
        $processed = 0;
        foreach ($actions as $action) {
            if (!is_array($action) || !isset($action['a']) || !is_numeric($action['a'])) {
                continue;
            }
            $a = (int) $action['a'];
            if ($a <= $lastA) {
                $results[] = ['a' => $a, 'ok' => true, 'dup' => true];
                continue;
            }
            $lastA = $a;
            $processed++;
            $type = isset($action['type']) ? (string) $action['type'] : '';
            if ((int) $row['blocked_until'] > $now) {
                $results[] = ['a' => $a, 'ok' => false, 'error' => 'forbidden'];
            } elseif ($type === 'chop_done') {
                $results[] = wf_action_chop($conn, $row, $stats, $action, $ctx);
            } else {
                // Остальные типы подключаются в своих пунктах плана (подбор ДЛ-13, стройка ДЛ-16 и т.д.).
                $results[] = ['a' => $a, 'ok' => false, 'error' => 'bad_state'];
            }
        }

        $active = $moved || $processed > 0;
        $idle = $now - ($active ? $now : (int) $row['last_action']);
        $stats['last_a'] = $lastA;
        $statsJson = json_encode($stats, JSON_UNESCAPED_UNICODE);
        $lastAction = $active ? $now : (int) $row['last_action'];
        // «Запас офлайна» плотины продлевают только настоящие действия, а не пассивные пинги открытой вкладки.
        $grace = $active ? $now + (int) forest_config('dam.offline_grace_h') * 3600 : (int) $row['grace_until'];
        // Энергия: пока без ДЛ-12 только восстановление «стоя в лесу» (wf_energy_now), чтобы игра не замирала.
        $energy = wf_energy_now((float) $row['energy'], (int) $row['energy_ts'], $now, wf_player_energy_max((int) $row['stamina_lvl']));
        $row['energy'] = $energy;
        $xp = (int) $row['xp'];
        $level = (int) $row['level'];
        $coins = (int) $row['coins'];
        $blocked = (int) $row['blocked_until'];

        $upd = $conn->prepare('UPDATE `forest_players` SET `pos_x` = ?, `pos_z` = ?, `pos_ry` = ?, `last_seen` = ?, `last_action` = ?, `grace_until` = ?, `stats_json` = ?, `energy` = ?, `energy_ts` = ?, `xp` = ?, `level` = ?, `coins` = ?, `blocked_until` = ? WHERE `player_id` = ?');
        $upd->bind_param('dddiiisdiiiiii', $newX, $newZ, $newRy, $now, $lastAction, $grace, $statsJson, $energy, $now, $xp, $level, $coins, $blocked, $playerId);
        $upd->execute();
        $upd->close();

        $row['pos_x'] = $newX;
        $row['pos_z'] = $newZ;

        return ['row' => $row, 'results' => $results, 'correct' => $correct, 'idle' => $idle];
    });
    $row = $out['row'];
    $results = $out['results'];
    $correct = $out['correct'];
    $idle = $out['idle'];

    $pack = wf_player_pack($conn, $playerId);
    $payload = [
        'ack' => $seq,
        'server_ts' => $now,
        'server_ms' => forest_now_ms(),
        'results' => $results,
        'state' => wf_player_light_state($row, $pack),
        'next_sync_ms' => wf_sync_next_ms($idle, $background),
    ];
    if ($correct !== null) {
        $payload['correct_pos'] = $correct;
    }
    forest_json_out($payload);
} catch (Throwable $error) {
    forest_fail($error);
}
