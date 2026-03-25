<?php

require_once __DIR__ . '/db.php';

bober_json_response(['success' => false, 'message' => 'Эндпоинт отключен. Используйте save-state.php.'], 410);
