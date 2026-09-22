<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $userId = bober_get_logged_in_user_id();

    if ($userId === null) {
        bober_json_response(['success' => false, 'message' => 'Сначала войдите в игровой аккаунт.'], 401);
    }

    $data = bober_read_json_request();
    if (!is_array($data)) {
        bober_json_response(['success' => false, 'message' => 'Некорректный JSON.'], 400);
    }

    // Два источника данных Telegram — какой пришёл, тот и проверяем:
    // 1) initData из Telegram Mini App (window.Telegram.WebApp.initData)
    // 2) плоский объект от Telegram Login Widget (id/hash/auth_date/...)
    $initData = trim((string) ($data['initData'] ?? ''));
    $widgetPayload = is_array($data['widgetData'] ?? null) ? $data['widgetData'] : null;

    $telegramData = null;
    if ($initData !== '') {
        $telegramData = bober_verify_telegram_webapp_init_data($initData);
    } elseif ($widgetPayload !== null) {
        $telegramData = bober_verify_telegram_login_widget($widgetPayload);
    }

    if ($telegramData === null) {
        bober_json_response(['success' => false, 'message' => 'Не удалось подтвердить данные Telegram. Попробуйте ещё раз.'], 400);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    try {
        $linkResult = bober_link_telegram_to_user($conn, $userId, $telegramData);
    } catch (InvalidArgumentException $linkError) {
        $conn->close();
        bober_json_response(['success' => false, 'message' => $linkError->getMessage()], 409);
    }

    bober_log_user_activity($conn, $userId, 'telegram_link_success', [
        'action_group' => 'auth',
        'source' => 'telegram_link',
        'description' => 'Игрок привязал Telegram-аккаунт.',
        'meta' => [
            'telegram_username' => $linkResult['username'],
        ],
    ]);

    $response = bober_fetch_account_snapshot($conn, $userId);
    $conn->close();

    $response['telegramLink'] = $linkResult;
    $response['message'] = $linkResult['rewardSkinGranted']
        ? 'Telegram привязан! Тебе начислен скин «Телеграм-бобёр».'
        : 'Telegram привязан!';

    bober_json_response($response);
} catch (Throwable $error) {
    $statusCode = $error instanceof InvalidArgumentException ? 400 : 500;
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], $statusCode);
}
