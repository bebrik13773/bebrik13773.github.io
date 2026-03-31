<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();
    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_enforce_runtime_access_rules($conn, $userId, [
        'source' => 'session_management',
    ]);

    $currentSessionHash = bober_current_session_hash();
    $currentSessionRow = bober_fetch_game_session_row_by_hash($conn, $currentSessionHash);
    $currentSession = $currentSessionRow
        ? bober_normalize_game_session_row($currentSessionRow, $currentSessionHash)
        : null;

    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $terminated = false;

    if ($method === 'POST') {
        $data = bober_read_json_request();
        if (!is_array($data)) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
        }

        $terminateSessionId = max(0, (int) ($data['terminateSessionId'] ?? 0));
        if ($terminateSessionId < 1) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Не выбрана сессия для завершения.'], 400);
        }

        if ($currentSession !== null && (int) $currentSession['sessionId'] === $terminateSessionId) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Нельзя завершить текущую сессию через этот список.'], 400);
        }

        $terminated = bober_revoke_game_session_by_id($conn, $userId, $terminateSessionId, 'terminated_from_conflict_modal');
        if (!$terminated) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Не удалось завершить выбранную сессию. Обновите список и попробуйте снова.'], 409);
        }

        bober_log_user_activity($conn, $userId, 'terminate_other_session', [
            'action_group' => 'sessions',
            'source' => 'session_management',
            'login' => $_SESSION['game_login'] ?? '',
            'description' => 'Пользователь завершил одну из своих других игровых сессий.',
            'meta' => [
                'terminated_session_id' => $terminateSessionId,
            ],
        ]);
    }

    $otherSessions = bober_fetch_user_active_game_sessions($conn, $userId, [
        'exclude_session_hash' => $currentSessionHash,
        'current_session_hash' => $currentSessionHash,
    ]);
    $hasConflict = !empty($otherSessions);

    if ($method === 'POST') {
        $message = $hasConflict
            ? 'Остались ещё активные сессии. Завершите одну из них, чтобы продолжить игру здесь.'
            : 'Лишняя сессия завершена. Можно продолжать игру на этом устройстве.';
    } else {
        $message = $hasConflict
            ? 'Аккаунт уже открыт ещё на одном устройстве. Завершите одну из активных сессий, чтобы продолжить игру здесь.'
            : 'Активна только текущая игровая сессия.';
    }

    $response = [
        'success' => true,
        'message' => $message,
        'sessionConflict' => $hasConflict,
        'authenticatedConflict' => $hasConflict,
        'currentSession' => $currentSession,
        'sessions' => array_values($otherSessions),
        'activeSessionCount' => count($otherSessions) + ($currentSession !== null ? 1 : 0),
    ];

    if ($method === 'POST') {
        $response['terminated'] = $terminated;
    }

    $conn->close();
    bober_json_response($response);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
