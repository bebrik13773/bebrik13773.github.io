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

    // Бот пишет в Telegram, что аккаунт привязан. Ошибка отправки не критична.
    $gameLogin = trim((string) ($response['login'] ?? ($_SESSION['game_login'] ?? '')));
    $safeLogin = htmlspecialchars($gameLogin !== '' ? $gameLogin : 'Игрок', ENT_QUOTES, 'UTF-8');
    $notifyText = "✅ Этот Telegram-аккаунт привязан к игровому аккаунту <b>" . $safeLogin . "</b> в «Бобёр Кликер 2».";
    if (!empty($linkResult['rewardSkinGranted'])) {
        $notifyText .= "\n🎁 Тебе начислен эксклюзивный скин «Телеграм-бобёр».";
    }
    bober_send_telegram_message($linkResult['telegramId'], $notifyText);

    $response['telegramLink'] = $linkResult;
    $response['message'] = $linkResult['rewardSkinGranted']
        ? 'Telegram привязан! Тебе начислен скин «Телеграм-бобёр».'
        : 'Telegram привязан!';

    bober_json_response($response);
} catch (Throwable $error) {
    $statusCode = $error instanceof InvalidArgumentException ? 400 : 500;
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], $statusCode);
}
