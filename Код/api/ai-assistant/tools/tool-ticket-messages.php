<?php

require_once dirname(__DIR__, 2) . '/bootstrap/db.php';
require_once dirname(__DIR__) . '/db/ai-chat-schema.php';

/**
 * Tool "reply_support_ticket": ИИ-бобёр по команде игрока добавляет сообщение
 * в уже существующий тикет. Переиспользует bober_reply_support_ticket_as_user
 * и добавляет в переписку системное сообщение о том, что писал ИИ-бобёр.
 */
function bober_ai_tool_reply_support_ticket($conn, $userId, array $args, $actionLimitPerHour = 5)
{
    if (!bober_ai_check_and_bump_action_rate_limit($conn, $userId, max(1, (int) $actionLimitPerHour))) {
        return [
            'success' => false,
            'message' => 'Слишком много действий через чат за последний час. Попробуй чуть позже или ответь в тикете напрямую.',
        ];
    }

    $ticketId = max(0, (int) ($args['ticketId'] ?? 0));
    $message = trim((string) ($args['message'] ?? ''));
    if ($ticketId < 1 || $message === '') {
        return ['success' => false, 'message' => 'Нужны номер тикета и текст сообщения.'];
    }

    try {
        $conn->begin_transaction();
        bober_reply_support_ticket_as_user($conn, $userId, $ticketId, $message, []);
        bober_insert_support_ticket_system_message($conn, $ticketId, '🤖 Сообщение отправлено ИИ-бобром по команде игрока.');
        $conn->commit();

        return ['success' => true, 'ticketId' => $ticketId];
    } catch (Throwable $error) {
        $conn->rollback();
        return ['success' => false, 'message' => bober_exception_message($error)];
    }
}

/**
 * Tool "edit_support_ticket_message": ИИ-бобёр по команде игрока редактирует
 * сообщение игрока (или своё, отправленное от его имени) в тикете.
 * Ответы поддержки и системные сообщения редактировать нельзя.
 */
function bober_ai_tool_edit_support_ticket_message($conn, $userId, array $args, $actionLimitPerHour = 5)
{
    if (!bober_ai_check_and_bump_action_rate_limit($conn, $userId, max(1, (int) $actionLimitPerHour))) {
        return [
            'success' => false,
            'message' => 'Слишком много действий через чат за последний час. Попробуй чуть позже или отредактируй сообщение напрямую.',
        ];
    }

    $ticketId = max(0, (int) ($args['ticketId'] ?? 0));
    $messageId = max(0, (int) ($args['messageId'] ?? 0));
    $message = trim((string) ($args['message'] ?? ''));
    if ($ticketId < 1 || $messageId < 1 || $message === '') {
        return ['success' => false, 'message' => 'Нужны номер тикета, номер сообщения и новый текст.'];
    }

    try {
        $conn->begin_transaction();
        bober_edit_support_ticket_message_as_user($conn, $userId, $ticketId, $messageId, $message);
        bober_insert_support_ticket_system_message($conn, $ticketId, '🤖 Сообщение #' . $messageId . ' отредактировано ИИ-бобром по команде игрока.');
        $conn->commit();

        return ['success' => true, 'ticketId' => $ticketId, 'messageId' => $messageId];
    } catch (Throwable $error) {
        $conn->rollback();
        return ['success' => false, 'message' => bober_exception_message($error)];
    }
}

/**
 * Tool "get_support_ticket": читает переписку тикета игрока (с id сообщений),
 * чтобы модель могла выбрать, что редактировать. Только чтение.
 */
function bober_ai_tool_get_support_ticket($conn, $userId, array $args)
{
    $ticketId = max(0, (int) ($args['ticketId'] ?? 0));
    if ($ticketId < 1) {
        return ['success' => false, 'message' => 'Нужен номер тикета.'];
    }

    try {
        $ticket = bober_fetch_user_support_ticket($conn, $userId, $ticketId, false);
        $messages = [];
        foreach ((array) ($ticket['messages'] ?? []) as $item) {
            $messages[] = [
                'id' => (int) ($item['id'] ?? 0),
                'author' => (string) ($item['authorType'] ?? ''),
                'message' => (string) ($item['message'] ?? ''),
                'createdAt' => (string) ($item['createdAt'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'ticketId' => $ticketId,
            'subject' => (string) ($ticket['subject'] ?? ''),
            'status' => (string) ($ticket['status'] ?? ''),
            'messages' => $messages,
        ];
    } catch (Throwable $error) {
        return ['success' => false, 'message' => bober_exception_message($error)];
    }
}
