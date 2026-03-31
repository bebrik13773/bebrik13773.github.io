<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    bober_json_response([
        'success' => true,
        'skins' => bober_skin_catalog_list(),
    ]);
} catch (Throwable $error) {
    bober_json_response([
        'success' => false,
        'message' => bober_exception_message($error),
    ], 500);
}
