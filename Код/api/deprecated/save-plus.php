<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

bober_json_response(['success' => false, 'message' => 'Эндпоинт отключен. Используйте /api/state/save.php.'], 410);
