<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';
require_once __DIR__ . '/db/ai-chat-schema.php';

/**
 * Лёгкий эндпоинт: сообщает фронту, доступен ли ИИ-чат, нужно ли платить
 * за сообщение и сколько монет это будет стоить прямо сейчас (с учётом
 * текущего экономического индекса игрока) — не списывает ничего сам.
 */
try {
    $sessionUserId = bober_get_logged_in_user_id();
    if ($sessionUserId === null) {
        bober_json_response(['success' => false, 'message' => 'Войдите в аккаунт.'], 401);
    }

    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_ai_ensure_schema($conn);

    $accessInfo = bober_ai_resolve_access($conn, $sessionUserId);

    if (empty($accessInfo['allowed'])) {
        $conn->close();
        bober_json_response([
            'success' => true,
            'allowed' => false,
            'accessBlocked' => true,
        ]);
    }

    $priceCoins = 0;
    if (!empty($accessInfo['mustPay'])) {
        $priceCoins = bober_ai_calculate_message_price($conn, $sessionUserId, $accessInfo['basePriceCoins'] ?? 0);
    }

    $conn->close();

    bober_json_response([
        'success' => true,
        'allowed' => true,
        'mustPay' => !empty($accessInfo['mustPay']),
        'priceCoins' => $priceCoins,
    ]);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }

    bober_json_response([
        'success' => false,
        'message' => bober_exception_message($error, 'Не удалось получить статус ИИ-помощника.'),
    ], 500);
}
