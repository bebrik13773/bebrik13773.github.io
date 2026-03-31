<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $clientLogBatch = is_array($data['clientLogBatch'] ?? null) ? $data['clientLogBatch'] : null;
    $reason = trim((string) ($data['reason'] ?? 'Подозрение на автокликер'));
    $meta = [
        'cps' => isset($data['cps']) ? (float) $data['cps'] : null,
        'clicks' => isset($data['clicks']) ? (int) $data['clicks'] : null,
        'mobile' => isset($data['mobile']) ? (bool) $data['mobile'] : null,
        'detectionCount' => isset($data['detectionCount']) ? (int) $data['detectionCount'] : null,
        'regularity' => isset($data['regularity']) ? (float) $data['regularity'] : null,
        'positionVariance' => isset($data['positionVariance']) ? (float) $data['positionVariance'] : null,
        'humanFactor' => isset($data['humanFactor']) ? (float) $data['humanFactor'] : null,
        'averageInterval' => isset($data['averageInterval']) ? (float) $data['averageInterval'] : null,
        'medianInterval' => isset($data['medianInterval']) ? (float) $data['medianInterval'] : null,
        'intervalStdDev' => isset($data['intervalStdDev']) ? (float) $data['intervalStdDev'] : null,
        'longestStreak' => isset($data['longestStreak']) ? (int) $data['longestStreak'] : null,
        'heat' => isset($data['heat']) ? (float) $data['heat'] : null,
    ];

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    $ban = bober_issue_user_ban($conn, $userId, $reason, [
        'source' => 'autoclicker',
        'detected_by' => 'client',
        'meta' => $meta,
    ]);
    bober_log_user_activity($conn, $userId, 'autoclicker_ban', [
        'action_group' => 'security',
        'source' => 'anti_cheat_report',
        'login' => $_SESSION['game_login'] ?? '',
        'description' => 'Игрок заблокирован античитом.',
        'meta' => [
            'reason' => $reason,
            'ban' => $ban,
            'detector_meta' => $meta,
        ],
    ]);

    $clientLogResult = null;
    if (is_array($clientLogBatch)) {
        try {
            $clientLogResult = bober_store_client_log_batch($conn, $userId, $clientLogBatch, [
                'login' => $_SESSION['game_login'] ?? '',
            ]);
        } catch (Throwable $logError) {
            $clientLogResult = [
                'received' => 0,
                'accepted' => 0,
                'inserted' => 0,
                'duplicates' => 0,
                'warning' => bober_exception_message($logError),
            ];
        }
    }

    bober_logout_user();
    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => $ban['message'],
        'ban' => $ban,
        'clientLog' => $clientLogResult,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
