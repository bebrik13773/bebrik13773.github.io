<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response([
            'success' => false,
            'message' => 'Некорректный JSON.',
        ], 400);
    }

    $sessionUserId = bober_get_logged_in_user_id();
    if ($sessionUserId === null) {
        bober_json_response([
            'success' => false,
            'message' => 'Сессия не найдена. Войдите в аккаунт заново.',
        ], 401);
    }

    $requestUserId = max(0, (int) ($data['userId'] ?? 0));
    if ($requestUserId > 0 && $requestUserId !== $sessionUserId) {
        bober_json_response([
            'success' => false,
            'message' => 'Неверный идентификатор пользователя для активной сессии.',
        ], 403);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $sessionValidation = bober_validate_current_game_session($conn, $sessionUserId, [
        'source' => 'shop_purchase',
        'login' => $_SESSION['game_login'] ?? '',
    ]);
    if (empty($sessionValidation['ok'])) {
        $payload = is_array($sessionValidation['payload'] ?? null)
            ? $sessionValidation['payload']
            : bober_build_session_ended_payload();

        $conn->close();
        bober_logout_user(['skip_session_revoke' => true]);
        bober_json_response($payload, 409);
    }

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

    bober_enforce_runtime_access_rules($conn, $sessionUserId);

    $result = bober_apply_authoritative_shop_purchase($conn, $sessionUserId, $data);
    $account = is_array($result['account'] ?? null) ? $result['account'] : null;
    $purchase = is_array($result['purchase'] ?? null) ? $result['purchase'] : null;
    bober_record_user_ip($conn, $sessionUserId);
    $conn->close();

    bober_json_response([
        'success' => true,
        'account' => $account,
        'purchase' => $purchase,
        'profile' => is_array($account) ? ($account['profile'] ?? null) : null,
        'supportSummary' => is_array($account) ? ($account['supportSummary'] ?? null) : null,
        'achievements' => is_array($account) ? ($account['achievements'] ?? []) : [],
        'achievementUnlocks' => is_array($account) ? ($account['achievementUnlocks'] ?? []) : [],
        'achievementStats' => is_array($account) ? ($account['achievementStats'] ?? []) : [],
        'achievementPlayerBase' => is_array($account) ? ($account['achievementPlayerBase'] ?? 0) : 0,
        'quests' => is_array($account) ? ($account['quests'] ?? null) : null,
        'questUnlocks' => is_array($account) ? ($account['questUnlocks'] ?? []) : [],
        'flyBeaver' => is_array($account) ? ($account['flyBeaver'] ?? null) : null,
    ]);
} catch (Throwable $error) {
    $message = bober_exception_message($error);
    $statusCode = 500;
    if ($error instanceof InvalidArgumentException) {
        $statusCode = 400;
    } elseif (in_array($message, [
        'Не хватает коинов для покупки.',
        'Этот скин уже куплен.',
        'Этот скин нельзя купить в магазине.',
        'Скин не найден.',
        'Неизвестный тип улучшения.',
    ], true)) {
        $statusCode = 409;
    }

    bober_json_response([
        'success' => false,
        'message' => $message,
    ], $statusCode);
}
