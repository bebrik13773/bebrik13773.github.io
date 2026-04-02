<?php

require_once dirname(__DIR__) . '/bootstrap/db.php';

try {
    $conn = bober_db_connect();
    bober_ensure_gameplay_schema($conn);
    bober_ensure_security_schema($conn);

    $countRows = static function(mysqli $conn, string $tableName): int {
        $result = $conn->query(sprintf('SELECT COUNT(*) AS total FROM `%s`', $conn->real_escape_string($tableName)));
        if (!$result instanceof mysqli_result) {
            throw new RuntimeException(sprintf('Не удалось прочитать таблицу %s.', $tableName));
        }

        $row = $result->fetch_assoc();
        $result->free();

        return max(0, (int) ($row['total'] ?? 0));
    };

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
        $userCount = $countRows($conn, 'users');
        $services['auth'] = [
            'ok' => true,
            'message' => 'Пользователи и базовые auth-таблицы доступны.',
            'userCount' => $userCount,
        ];
    } catch (Throwable $authError) {
        $services['auth'] = [
            'ok' => false,
            'message' => bober_exception_message($authError),
        ];
    }

    try {
        $activeSessions = 0;
        $result = $conn->query('SELECT COUNT(*) AS total FROM user_sessions WHERE revoked_at IS NULL');
        if ($result instanceof mysqli_result) {
            $row = $result->fetch_assoc();
            $activeSessions = max(0, (int) ($row['total'] ?? 0));
            $result->free();
        }

        $services['sessions'] = [
            'ok' => true,
            'message' => 'Таблица игровых сессий доступна.',
            'activeSessionCount' => $activeSessions,
        ];
    } catch (Throwable $sessionError) {
        $services['sessions'] = [
            'ok' => false,
            'message' => bober_exception_message($sessionError),
        ];
    }

    try {
        bober_fetch_public_fly_beaver_leaderboard($conn, 1);
        $services['fly'] = [
            'ok' => true,
            'message' => 'Публичные данные fly-beaver доступны.',
        ];
    } catch (Throwable $flyError) {
        $services['fly'] = [
            'ok' => false,
            'message' => bober_exception_message($flyError),
        ];
    }

    try {
        $activityCount = $countRows($conn, 'user_activity_log');
        $clientLogCount = $countRows($conn, 'user_client_event_log');
        $services['logs'] = [
            'ok' => true,
            'message' => 'Таблицы forensic и client-log доступны.',
            'activityCount' => $activityCount,
            'clientLogCount' => $clientLogCount,
        ];
    } catch (Throwable $logError) {
        $services['logs'] = [
            'ok' => false,
            'message' => bober_exception_message($logError),
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

    try {
        $rootDir = dirname(dirname(__DIR__));
        $requiredFiles = [
            'pages/clicker/index.html',
            'games/fly-beaver/index.html',
            'api/state/sync.php',
            'api/state/save.php',
            'api/health/check.php',
            'api/auth/login.php',
            'api/auth/register.php',
            'api/auth/logout.php',
            'api/auth/session.php',
            'api/catalog/skin-catalog.php',
            'api/leaderboards/main.php',
            'api/leaderboards/player-profile.php',
            'api/support/tickets.php',
            'api/sessions/game-sessions.php',
            'api/fly/session.php',
            'api/fly/save-run.php',
            'api/fly/claim-reward.php',
            'api/logs/client-log.php',
            'api/logs/anti-cheat-report.php',
            'sync-state.php',
            'save-state.php',
            'leaderboard.php',
            'support-tickets.php',
            'game-sessions.php',
            'fly-session.php',
            'fly-save-run.php',
            'fly-claim-reward.php',
            'login-t.php',
            'register-t.php',
            'logout-t.php',
            'session-t.php',
            'client-log.php',
            'anti-cheat-report.php',
            'skin-catalog.php',
        ];
        $missingFiles = [];

        foreach ($requiredFiles as $relativePath) {
            if (!is_file($rootDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath))) {
                $missingFiles[] = $relativePath;
            }
        }

        $services['files'] = [
            'ok' => count($missingFiles) === 0,
            'message' => count($missingFiles) === 0
                ? 'Критичные endpoint- и wrapper-файлы присутствуют.'
                : 'На диске отсутствуют критичные endpoint- или wrapper-файлы.',
            'missing' => $missingFiles,
        ];
    } catch (Throwable $filesError) {
        $services['files'] = [
            'ok' => false,
            'message' => bober_exception_message($filesError),
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
