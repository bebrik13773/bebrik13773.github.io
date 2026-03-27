<?php

require_once __DIR__ . '/db.php';

try {
    $data = bober_read_json_request();
    if ($data !== null && !is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $leaderboard = bober_fetch_public_leaderboard($conn, 3);
    $flyLeaderboard = bober_fetch_public_fly_beaver_leaderboard($conn, 3);
    $sessionUserId = bober_get_logged_in_user_id();
    $account = null;
    $settings = bober_default_user_settings();
    $flyBeaver = bober_default_fly_beaver_progress();
    $saveResult = null;
    $settingsSaved = false;

    $wantsStateSave = is_array($data) && (
        array_key_exists('score', $data) ||
        array_key_exists('plus', $data) ||
        array_key_exists('skin', $data) ||
        array_key_exists('energy', $data) ||
        array_key_exists('lastEnergyUpdate', $data) ||
        array_key_exists('ENERGY_MAX', $data) ||
        array_key_exists('upgradePurchases', $data) ||
        array_key_exists('clientLogBatch', $data)
    );
    $wantsSettingsSave = is_array($data) && is_array($data['settings'] ?? null);

    if ($sessionUserId === null && ($wantsStateSave || $wantsSettingsSave || max(0, (int) ($data['userId'] ?? 0)) > 0)) {
        $conn->close();
        bober_json_response(['success' => false, 'message' => 'Сессия не найдена. Войдите в аккаунт заново.'], 401);
    }

    if ($sessionUserId !== null) {
        $requestUserId = max(0, (int) ($data['userId'] ?? 0));
        if ($requestUserId > 0 && $requestUserId !== $sessionUserId) {
            $conn->close();
            bober_json_response(['success' => false, 'message' => 'Неверный идентификатор пользователя для активной сессии.'], 403);
        }

        $sessionValidation = bober_validate_current_game_session($conn, $sessionUserId, [
            'source' => 'sync_state',
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

        if ($wantsStateSave) {
            $saveResult = bober_apply_user_state_update($conn, $sessionUserId, $data);
        }

        if ($wantsSettingsSave) {
            $settings = bober_store_user_settings($conn, $sessionUserId, $data['settings']);
            $settingsSaved = true;
        }

        $account = bober_fetch_account_snapshot($conn, $sessionUserId);
        $settings = $account['settings'] ?? $settings;
        $flyBeaver = $account['flyBeaver'] ?? $flyBeaver;
        bober_record_user_ip($conn, $sessionUserId);
    }

    $conn->close();

    bober_json_response([
        'success' => true,
        'leaderboard' => $leaderboard,
        'leaderboards' => [
            'main' => $leaderboard,
            'flyBeaver' => $flyLeaderboard,
        ],
        'account' => $account,
        'flyBeaver' => $flyBeaver,
        'settings' => $settings,
        'serverTime' => (int) round(microtime(true) * 1000),
        'saved' => $saveResult !== null,
        'settingsSaved' => $settingsSaved,
        'clientLog' => $saveResult['clientLog'] ?? null,
    ]);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
