<?php

require_once __DIR__ . '/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $activeIpBan = bober_fetch_active_ip_ban($conn);
    if ($activeIpBan !== null) {
        $conn->close();
        bober_logout_user();
        bober_json_response([
            'success' => false,
            'message' => $activeIpBan['message'],
            'ipBan' => $activeIpBan,
        ], 403);
    }

    $stmt = $conn->prepare('SELECT id, login, plus, skin, energy, last_energy_update, ENERGY_MAX, score FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        throw new RuntimeException('Ошибка подготовки запроса.');
    }

    $stmt->bind_param('i', $userId);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Ошибка выполнения запроса.');
    }

    $stmt->store_result();

    if ($stmt->num_rows !== 1) {
        $stmt->close();
        $conn->close();
        bober_logout_user();
        bober_json_response(['success' => false, 'message' => 'Сессия устарела.'], 401);
    }

    $stmt->bind_result($id, $login, $plus, $skin, $energy, $lastEnergyUpdate, $energyMax, $score);
    $stmt->fetch();
    $stmt->close();

    $activeBan = bober_fetch_active_user_ban($conn, (int) $id);
    if ($activeBan !== null) {
        bober_propagate_user_ban_to_ip_bans($conn, (int) $id, $activeBan, [
            'include_current_ip' => false,
        ]);
        $conn->close();
        bober_logout_user();
        bober_json_response([
            'success' => false,
            'message' => $activeBan['message'],
            'ban' => $activeBan,
        ], 403);
    }

    bober_record_user_ip($conn, (int) $id);

    $normalizedSkin = bober_normalize_skin_json($skin);
    if ($normalizedSkin !== $skin) {
        $updateStmt = $conn->prepare('UPDATE users SET skin = ? WHERE id = ?');
        if ($updateStmt) {
            $normalizedUserId = (int) $id;
            $updateStmt->bind_param('si', $normalizedSkin, $normalizedUserId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    $energyMax = max(1, (int) $energyMax);
    $energy = max(0, min($energyMax, (int) $energy));
    $flyBeaver = bober_fetch_fly_beaver_progress($conn, (int) $id);

    $conn->close();

    bober_json_response([
        'success' => true,
        'userId' => (int) $id,
        'login' => (string) $login,
        'plus' => max(1, (int) $plus),
        'skin' => $normalizedSkin,
        'energy' => $energy,
        'lastEnergyUpdate' => max(0, (int) $lastEnergyUpdate),
        'ENERGY_MAX' => $energyMax,
        'score' => max(0, (int) $score),
        'flyBeaver' => $flyBeaver,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
