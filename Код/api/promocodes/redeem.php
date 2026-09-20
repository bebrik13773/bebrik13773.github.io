<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $sessionUserId = bober_get_logged_in_user_id();
    if ($sessionUserId === null) {
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена. Войдите в аккаунт заново.'], 401);
    }

    $code = trim((string) ($data['code'] ?? ''));
    if ($code === '') {
        bober_json_response(['success' => false, 'message' => 'Введите промокод.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $sessionValidation = bober_validate_current_game_session($conn, $sessionUserId, [
        'source' => 'redeem_promocode',
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

    try {
        $redemption = bober_redeem_promocode($conn, $sessionUserId, $code);
    } catch (Throwable $redeemError) {
        $conn->close();
        bober_json_response(['success' => false, 'message' => bober_exception_message($redeemError)], 400);
    }

    $account = bober_fetch_account_snapshot($conn, $sessionUserId);
    $conn->close();

    bober_json_response([
        'success' => true,
        'message' => 'Промокод активирован!',
        'redemption' => $redemption,
        'account' => $account,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
