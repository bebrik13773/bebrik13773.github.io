<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';
require_once dirname(__DIR__) . '/db/ai-chat-schema.php';

/**
 * Обработчик tool "create_support_ticket". Переиспользует существующую
 * логику тикетов поддержки (bober_create_support_ticket), просто вызванную
 * из чата вместо формы в интерфейсе.
 */
function bober_ai_tool_create_support_ticket($conn, $userId, array $args)
{
    if (!bober_ai_check_and_bump_action_rate_limit($conn, $userId, 5)) {
        return [
            'success' => false,
            'message' => 'Слишком много действий через чат за последний час. Попробуй создать тикет напрямую в поддержке или чуть позже.',
        ];
    }

    $category = bober_normalize_support_ticket_category($args['category'] ?? 'other');
    $subject = trim((string) ($args['subject'] ?? ''));
    $message = trim((string) ($args['message'] ?? ''));

    if ($subject === '' || $message === '') {
        return [
            'success' => false,
            'message' => 'Нужны тема и текст тикета.',
        ];
    }

    try {
        $conn->begin_transaction();
        $ticket = bober_create_support_ticket($conn, $userId, $category, $subject, $message, []);
        $conn->commit();

        return [
            'success' => true,
            'ticketId' => (int) ($ticket['id'] ?? 0),
            'category' => $category,
            'subject' => $subject,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        return [
            'success' => false,
            'message' => bober_exception_message($error),
        ];
    }
}
