<?php

require_once __DIR__ . '/db.php';

try {
    bober_logout_user();
    bober_json_response(['success' => true, 'message' => 'Вы вышли из аккаунта.']);
} catch (Throwable $error) {
    bober_json_response(['success' => false, 'message' => bober_exception_message($error)], 500);
}
