<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

const MATCH3_TOTAL_LEVELS = 25;

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $completedLevel = (int) ($data['completedLevel'] ?? 0);
    if ($completedLevel < 1 || $completedLevel > MATCH3_TOTAL_LEVELS) {
        bober_json_response(['success' => false, 'message' => 'Некорректный номер уровня.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId);

    // Продвигаем прогресс только если игрок только что прошел уровень, который
    // на сервере и так числится текущим - защита от накрутки/пропуска уровней.
    $match3 = bober_advance_match3_level($conn, $userId, $completedLevel, MATCH3_TOTAL_LEVELS);

    bober_log_user_activity($conn, $userId, 'match3_level_completed', [
        'action_group' => 'match3',
        'source' => 'match3_complete_level',
        'login' => $_SESSION['game_login'] ?? '',
        'description' => 'Пройден уровень Три Бобра.',
        'meta' => [
            'completed_level' => $completedLevel,
            'new_current_level' => $match3['currentLevel'],
        ],
    ]);

    $conn->close();

    bober_json_response([
        'success' => true,
        'match3' => $match3,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
