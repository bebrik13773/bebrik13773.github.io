<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';
require_once dirname(__DIR__) . '/db/ai-chat-schema.php';

/**
 * Обработчик tool "buy_upgrade". НЕ содержит собственной логики списания —
 * дёргает ту же bober_apply_authoritative_shop_purchase(), что и обычная
 * кнопка покупки в интерфейсе. Единая точка правды для всех покупок.
 */
function bober_ai_tool_buy_upgrade($conn, $userId, array $args, $actionLimitPerHour = 5)
{
    if (!bober_ai_check_and_bump_action_rate_limit($conn, $userId, max(1, (int) $actionLimitPerHour))) {
        return [
            'success' => false,
            'message' => 'Слишком много покупок через чат за последний час. Попробуй чуть позже или купи апгрейд напрямую в магазине.',
        ];
    }

    $upgradeType = trim((string) ($args['upgradeType'] ?? ''));
    $quantity = max(1, min(250, (int) ($args['quantity'] ?? 1)));

    try {
        $result = bober_apply_authoritative_shop_purchase($conn, $userId, [
            'kind' => 'upgrade',
            'upgradeType' => $upgradeType,
            'quantity' => $quantity,
        ]);

        $purchase = is_array($result['purchase'] ?? null) ? $result['purchase'] : [];
        $account = is_array($result['account'] ?? null) ? $result['account'] : [];

        return [
            'success' => true,
            'purchase' => $purchase,
            'newBalance' => (int) ($account['score'] ?? 0),
        ];
    } catch (Throwable $error) {
        return [
            'success' => false,
            'message' => bober_exception_message($error),
        ];
    }
}
