<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);

    $services = [
        'database' => [
            'ok' => true,
            'message' => 'Соединение с базой данных установлено.',
        ],
    ];

    try {
        bober_fetch_public_leaderboard($conn, 1);
        bober_fetch_public_fly_beaver_leaderboard($conn, 1);
        $services['sync'] = [
            'ok' => true,
            'message' => 'Публичная синхронизация и таблицы лидеров доступны.',
        ];
    } catch (Throwable $syncError) {
        $services['sync'] = [
            'ok' => false,
            'message' => bober_exception_message($syncError),
        ];
    }

    try {
        $skins = bober_skin_catalog_list();
        $services['catalog'] = [
            'ok' => is_array($skins),
            'message' => is_array($skins)
                ? 'Каталог скинов загружен.'
                : 'Каталог скинов вернул некорректный формат.',
        ];
    } catch (Throwable $catalogError) {
        $services['catalog'] = [
            'ok' => false,
            'message' => bober_exception_message($catalogError),
        ];
    }

    try {
        bober_ensure_support_schema($conn);
        $ticketCount = 0;
        $result = $conn->query('SELECT COUNT(*) AS total FROM support_tickets');
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $ticketCount = max(0, (int) ($row['total'] ?? 0));
            $result->free();
        }

        $services['support'] = [
            'ok' => true,
            'message' => 'Таблицы поддержки доступны.',
            'ticketCount' => $ticketCount,
        ];
    } catch (Throwable $supportError) {
        $services['support'] = [
            'ok' => false,
            'message' => bober_exception_message($supportError),
        ];
    }

    $conn->close();

    $hasFailures = false;
    foreach ($services as $service) {
        if (empty($service['ok'])) {
            $hasFailures = true;
            break;
        }
    }

    bober_json_response([
        'success' => !$hasFailures,
        'status' => $hasFailures ? 'degraded' : 'ok',
        'services' => $services,
        'generatedAt' => gmdate('c'),
    ], $hasFailures ? 503 : 200);
} catch (Throwable $error) {
    bober_json_response([
        'success' => false,
        'status' => 'down',
        'message' => bober_exception_message($error),
        'services' => [
            'database' => [
                'ok' => false,
                'message' => bober_exception_message($error),
            ],
        ],
        'generatedAt' => gmdate('c'),
    ], 500);
}
