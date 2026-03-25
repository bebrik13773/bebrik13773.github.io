<?php
/*
Owner decisions:
1. Админке нужен полный SQL-доступ с удобным доступом и нормальным дизайном.
2. Test и production используют одну базу данных.
3. Админ-пароль хранить только как hash в secrets.
4. Проект живет на бесплатном сервере с ограниченным доступом и автодеплоем.
5. Нужен audit trail: кто, когда и что изменил в админке.
*/
session_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__) . '/db.php';

$bootstrapError = null;

function connectDB()
{
    return bober_db_connect();
}

function initializeAdmin()
{
    global $bootstrapError;

    try {
        $conn = connectDB();
        bober_ensure_project_schema($conn);
        $conn->close();
    } catch (Throwable $error) {
        $bootstrapError = 'Панель не настроена. Создайте `Код/db_config.php` или задайте переменные окружения `BOBER_DB_*`.';
    }
}

function adminIsAuthenticated()
{
    return isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
}

function requireAdminAuth(&$response)
{
    if (!adminIsAuthenticated()) {
        $response['message'] = 'Неавторизованный доступ';
        return false;
    }

    return true;
}

function normalizeTableName($table)
{
    $table = trim((string) $table);
    if ($table === '') {
        return '';
    }

    return bober_require_identifier($table, 'Имя таблицы');
}

function normalizeColumnName($column)
{
    $column = trim((string) $column);
    if ($column === '') {
        return '';
    }

    return bober_require_identifier($column, 'Имя колонки');
}

function sqlValueForQuery($conn, $value)
{
    if ($value === null) {
        return 'NULL';
    }

    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    return "'" . $conn->real_escape_string((string) $value) . "'";
}

function normalizeSingleSqlStatement($sql)
{
    $sql = trim((string) $sql);

    if ($sql === '') {
        return '';
    }

    $sql = preg_replace('/;+\s*$/u', '', $sql);
    $sql = trim((string) $sql);

    if ($sql === '') {
        return '';
    }

    if (preg_match('/;\s*\S/u', $sql) === 1) {
        throw new InvalidArgumentException('Разрешен только один SQL-запрос за один запуск.');
    }

    return $sql;
}

initializeAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];

    if ($bootstrapError !== null) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['success' => false, 'message' => $bootstrapError],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    try {
        $action = (string) $_POST['action'];

        if ($action === 'login') {
            $password = trim((string) ($_POST['password'] ?? ''));

            if ($password === '') {
                $response['message'] = 'Введите пароль';
            } else {
                $conn = connectDB();
                $passwordHash = bober_fetch_admin_password_hash($conn);

                if ($passwordHash === null) {
                    $response['message'] = 'Пароль администратора не настроен. Задайте `BOBER_ADMIN_PASSWORD_HASH` или `BOBER_ADMIN_INITIAL_PASSWORD`.';
                } elseif (bober_admin_is_default_password_hash($passwordHash)) {
                    $response['message'] = 'Пароль по умолчанию отключен. Укажите новый пароль в конфиге окружения.';
                } elseif (password_verify($password, $passwordHash)) {
                    $_SESSION['authenticated'] = true;
                    $_SESSION['password_changed'] = true;
                    $response['success'] = true;
                    $response['password_changed'] = true;
                    $response['message'] = 'Авторизация успешна';
                    bober_admin_log_action($conn, 'login_success');
                } else {
                    $response['message'] = 'Неверный пароль';
                }

                $conn->close();
            }
        }

        if ($action === 'logout') {
            $conn = connectDB();
            bober_admin_log_action($conn, 'logout');
            $conn->close();
            session_destroy();
            $response['success'] = true;
            $response['message'] = 'Выход выполнен';
        }

        if ($action === 'change_password') {
            if (requireAdminAuth($response)) {
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');
                $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

                if ($newPassword === '' || strlen($newPassword) < 6) {
                    $response['message'] = 'Новый пароль должен содержать минимум 6 символов';
                } elseif ($newPassword !== $confirmPassword) {
                    $response['message'] = 'Пароли не совпадают';
                } else {
                    $conn = connectDB();
                    $result = $conn->query('SELECT id, password_hash FROM pass ORDER BY id ASC LIMIT 1');

                    if ($result === false || $result->num_rows === 0) {
                        $response['message'] = 'Пароль администратора не настроен';
                    } else {
                        $row = $result->fetch_assoc();
                        $result->free();

                        if (!password_verify($currentPassword, $row['password_hash'])) {
                            $response['message'] = 'Текущий пароль неверен';
                        } else {
                            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                            $adminId = (int) $row['id'];
                            $updateStmt = $conn->prepare('UPDATE pass SET password_hash = ? WHERE id = ?');

                            if (!$updateStmt) {
                                throw new RuntimeException('Ошибка подготовки запроса.');
                            }

                            $updateStmt->bind_param('si', $newPasswordHash, $adminId);

                            if ($updateStmt->execute()) {
                                $_SESSION['password_changed'] = true;
                                $response['success'] = true;
                                $response['message'] = 'Пароль успешно изменен';
                                bober_admin_log_action($conn, 'change_password');
                            } else {
                                $response['message'] = 'Ошибка при обновлении пароля';
                            }

                            $updateStmt->close();
                        }
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'execute_sql') {
            if (requireAdminAuth($response)) {
                $sql = trim((string) ($_POST['sql'] ?? ''));

                if ($sql === '') {
                    $response['message'] = 'SQL запрос не может быть пустым';
                } else {
                    $sql = normalizeSingleSqlStatement($sql);
                    if ($sql === '') {
                        $response['message'] = 'SQL запрос не может быть пустым';
                    } else {
                        $conn = connectDB();
                        $result = $conn->query($sql);

                        if ($result === false) {
                            $response['message'] = 'Ошибка SQL: ' . $conn->error;
                        } else {
                            $rows = [];
                            $columns = [];
                            $affectedRows = max(0, (int) $conn->affected_rows);

                            if ($result instanceof mysqli_result) {
                                while ($field = $result->fetch_field()) {
                                    $columns[] = $field->name;
                                }

                                while ($row = $result->fetch_assoc()) {
                                    $rows[] = $row;
                                }

                                $result->free();

                                $response['results'] = [[
                                    'columns' => $columns,
                                    'rows' => $rows,
                                    'row_count' => count($rows),
                                ]];
                            } else {
                                $response['results'] = [];
                            }

                            $response['success'] = true;
                            $response['affected_rows'] = $affectedRows;
                            $response['message'] = 'Запрос выполнен успешно';
                            bober_admin_log_action($conn, 'execute_sql', [
                                'query_text' => $sql,
                                'affected_rows' => $affectedRows,
                            ]);
                        }

                        $conn->close();
                    }
                }
            }
        }

        if ($action === 'get_tables') {
            if (requireAdminAuth($response)) {
                $conn = connectDB();
                $result = $conn->query('SHOW TABLES');

                if ($result === false) {
                    $response['message'] = 'Не удалось получить список таблиц';
                } else {
                    $tables = [];
                    while ($row = $result->fetch_array()) {
                        $tables[] = $row[0];
                    }

                    $result->free();
                    $response['success'] = true;
                    $response['tables'] = $tables;
                }

                $conn->close();
            }
        }

        if ($action === 'get_dashboard_stats') {
            if (requireAdminAuth($response)) {
                $conn = connectDB();
                bober_ensure_project_schema($conn);

                $tablesResult = $conn->query('SHOW TABLES');
                if ($tablesResult === false) {
                    $response['message'] = 'Не удалось получить список таблиц';
                } else {
                    $tables = [];
                    while ($row = $tablesResult->fetch_array()) {
                        $tables[] = $row[0];
                    }
                    $tablesResult->free();

                    $totalRows = 0;
                    foreach ($tables as $tableName) {
                        $safeTable = normalizeTableName($tableName);
                        $countResult = $conn->query("SELECT COUNT(*) AS total FROM `{$safeTable}`");
                        if ($countResult instanceof mysqli_result) {
                            $countRow = $countResult->fetch_assoc();
                            $totalRows += (int) ($countRow['total'] ?? 0);
                            $countResult->free();
                        }
                    }

                    $activeBans = 0;
                    $flyPending = 0;
                    $usersCount = 0;

                    $activeBansResult = $conn->query("SELECT COUNT(*) AS total FROM `user_bans` WHERE `lifted_at` IS NULL AND `ban_until` > CURRENT_TIMESTAMP");
                    if ($activeBansResult instanceof mysqli_result) {
                        $activeBans = (int) ($activeBansResult->fetch_assoc()['total'] ?? 0);
                        $activeBansResult->free();
                    }

                    $flyPendingResult = $conn->query("SELECT COALESCE(SUM(`pending_transfer_score`), 0) AS total FROM `fly_beaver_progress`");
                    if ($flyPendingResult instanceof mysqli_result) {
                        $flyPending = (int) ($flyPendingResult->fetch_assoc()['total'] ?? 0);
                        $flyPendingResult->free();
                    }

                    $usersResult = $conn->query("SELECT COUNT(*) AS total FROM `users`");
                    if ($usersResult instanceof mysqli_result) {
                        $usersCount = (int) ($usersResult->fetch_assoc()['total'] ?? 0);
                        $usersResult->free();
                    }

                    $response['success'] = true;
                    $response['stats'] = [
                        'table_count' => count($tables),
                        'total_rows' => $totalRows,
                        'active_bans' => $activeBans,
                        'fly_pending_score' => $flyPending,
                        'users_count' => $usersCount,
                    ];
                }

                $conn->close();
            }
        }

        if ($action === 'get_users_overview') {
            if (requireAdminAuth($response)) {
                $search = trim((string) ($_POST['search'] ?? ''));
                $searchLike = '%' . $search . '%';

                $conn = connectDB();
                bober_ensure_project_schema($conn);

                $sql = <<<SQL
SELECT
    `u`.`id`,
    `u`.`login`,
    `u`.`score`,
    `u`.`plus`,
    `u`.`energy`,
    `u`.`ENERGY_MAX`,
    `u`.`updated_at`,
    GREATEST(
        COALESCE(`u`.`updated_at`, '1970-01-01 00:00:00'),
        COALESCE(`f`.`last_played_at`, '1970-01-01 00:00:00'),
        COALESCE((
            SELECT MAX(`iph`.`last_seen_at`)
            FROM `user_ip_history` `iph`
            WHERE `iph`.`user_id` = `u`.`id`
        ), '1970-01-01 00:00:00')
    ) AS `last_activity_at`,
    COALESCE(`f`.`best_score`, 0) AS `fly_best_score`,
    COALESCE(`f`.`pending_transfer_score`, 0) AS `fly_pending_score`,
    EXISTS(
        SELECT 1
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        LIMIT 1
    ) AS `is_banned`,
    (
        SELECT `ub2`.`ban_until`
        FROM `user_bans` `ub2`
        WHERE `ub2`.`user_id` = `u`.`id`
          AND `ub2`.`lifted_at` IS NULL
          AND `ub2`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub2`.`ban_until` DESC
        LIMIT 1
    ) AS `ban_until`
FROM `users` `u`
LEFT JOIN `fly_beaver_progress` `f` ON `f`.`user_id` = `u`.`id`
WHERE `u`.`login` LIKE ? OR CAST(`u`.`id` AS CHAR) LIKE ?
ORDER BY `is_banned` DESC, `last_activity_at` DESC, `u`.`score` DESC, `u`.`id` DESC
LIMIT 200
SQL;

                $stmt = $conn->prepare($sql);
                if (!$stmt) {
                    throw new RuntimeException('Не удалось подготовить список пользователей.');
                }

                $stmt->bind_param('ss', $searchLike, $searchLike);
                if (!$stmt->execute()) {
                    $stmt->close();
                    throw new RuntimeException('Не удалось загрузить список пользователей.');
                }

                $result = $stmt->get_result();
                $users = [];

                while ($row = $result->fetch_assoc()) {
                    $users[] = [
                        'id' => (int) ($row['id'] ?? 0),
                        'login' => (string) ($row['login'] ?? ''),
                        'score' => max(0, (int) ($row['score'] ?? 0)),
                        'plus' => max(1, (int) ($row['plus'] ?? 1)),
                        'energy' => max(0, (int) ($row['energy'] ?? 0)),
                        'energyMax' => max(1, (int) ($row['ENERGY_MAX'] ?? 1)),
                        'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
                        'lastActivityAt' => isset($row['last_activity_at']) ? (string) $row['last_activity_at'] : null,
                        'flyBestScore' => max(0, (int) ($row['fly_best_score'] ?? 0)),
                        'flyPendingScore' => max(0, (int) ($row['fly_pending_score'] ?? 0)),
                        'isBanned' => (int) ($row['is_banned'] ?? 0) === 1,
                        'banUntil' => isset($row['ban_until']) ? (string) $row['ban_until'] : null,
                    ];
                }

                if ($result instanceof mysqli_result) {
                    $result->free();
                }
                $stmt->close();

                $countStmt = $conn->prepare('SELECT COUNT(*) AS total FROM `users` WHERE `login` LIKE ? OR CAST(`id` AS CHAR) LIKE ?');
                if (!$countStmt) {
                    throw new RuntimeException('Не удалось получить число пользователей.');
                }

                $countStmt->bind_param('ss', $searchLike, $searchLike);
                if (!$countStmt->execute()) {
                    $countStmt->close();
                    throw new RuntimeException('Не удалось получить число пользователей.');
                }

                $countResult = $countStmt->get_result();
                $countRow = $countResult ? $countResult->fetch_assoc() : null;
                $total = (int) ($countRow['total'] ?? 0);
                if ($countResult instanceof mysqli_result) {
                    $countResult->free();
                }
                $countStmt->close();

                $response['success'] = true;
                $response['users'] = $users;
                $response['returned'] = count($users);
                $response['total'] = $total;
                $response['search'] = $search;

                $conn->close();
            }
        }

        if ($action === 'get_user_profile') {
            if (requireAdminAuth($response)) {
                $userId = max(0, (int) ($_POST['user_id'] ?? 0));

                if ($userId < 1) {
                    $response['message'] = 'Некорректный идентификатор пользователя';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $profileSql = <<<SQL
SELECT
    `u`.`id`,
    `u`.`login`,
    `u`.`plus`,
    `u`.`skin`,
    `u`.`energy`,
    `u`.`last_energy_update`,
    `u`.`ENERGY_MAX`,
    `u`.`score`,
    `u`.`upgrade_tap_small_count`,
    `u`.`upgrade_tap_big_count`,
    `u`.`upgrade_energy_count`,
    `u`.`created_at`,
    `u`.`updated_at`,
    GREATEST(
        COALESCE(`u`.`updated_at`, '1970-01-01 00:00:00'),
        COALESCE(`f`.`last_played_at`, '1970-01-01 00:00:00'),
        COALESCE((
            SELECT MAX(`iph`.`last_seen_at`)
            FROM `user_ip_history` `iph`
            WHERE `iph`.`user_id` = `u`.`id`
        ), '1970-01-01 00:00:00')
    ) AS `last_activity_at`,
    COALESCE(`f`.`best_score`, 0) AS `fly_best_score`,
    COALESCE(`f`.`last_score`, 0) AS `fly_last_score`,
    COALESCE(`f`.`last_level`, 1) AS `fly_last_level`,
    COALESCE(`f`.`games_played`, 0) AS `fly_games_played`,
    COALESCE(`f`.`total_score`, 0) AS `fly_total_score`,
    COALESCE(`f`.`pending_transfer_score`, 0) AS `fly_pending_transfer_score`,
    COALESCE(`f`.`transferred_total_score`, 0) AS `fly_transferred_total_score`,
    `f`.`last_played_at` AS `fly_last_played_at`,
    (
        SELECT `ub`.`id`
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub`.`ban_until` DESC
        LIMIT 1
    ) AS `active_ban_id`,
    (
        SELECT `ub`.`reason`
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub`.`ban_until` DESC
        LIMIT 1
    ) AS `active_ban_reason`,
    (
        SELECT `ub`.`ban_until`
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub`.`ban_until` DESC
        LIMIT 1
    ) AS `active_ban_until`,
    (
        SELECT `ub`.`duration_days`
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub`.`ban_until` DESC
        LIMIT 1
    ) AS `active_ban_duration_days`,
    (
        SELECT `ub`.`is_repeat`
        FROM `user_bans` `ub`
        WHERE `ub`.`user_id` = `u`.`id`
          AND `ub`.`lifted_at` IS NULL
          AND `ub`.`ban_until` > CURRENT_TIMESTAMP
        ORDER BY `ub`.`ban_until` DESC
        LIMIT 1
    ) AS `active_ban_is_repeat`
FROM `users` `u`
LEFT JOIN `fly_beaver_progress` `f` ON `f`.`user_id` = `u`.`id`
WHERE `u`.`id` = ?
LIMIT 1
SQL;

                    $stmt = $conn->prepare($profileSql);
                    if (!$stmt) {
                        throw new RuntimeException('Не удалось подготовить карточку пользователя.');
                    }

                    $stmt->bind_param('i', $userId);
                    if (!$stmt->execute()) {
                        $stmt->close();
                        throw new RuntimeException('Не удалось загрузить карточку пользователя.');
                    }

                    $result = $stmt->get_result();
                    $row = $result ? $result->fetch_assoc() : null;
                    if ($result instanceof mysqli_result) {
                        $result->free();
                    }
                    $stmt->close();

                    if (!$row) {
                        $response['message'] = 'Пользователь не найден';
                    } else {
                        $ipHistory = [];
                        $ipStmt = $conn->prepare('SELECT `ip_address`, `login_count`, `first_seen_at`, `last_seen_at`, `last_user_agent` FROM `user_ip_history` WHERE `user_id` = ? ORDER BY `last_seen_at` DESC LIMIT 20');
                        if ($ipStmt) {
                            $ipStmt->bind_param('i', $userId);
                            if ($ipStmt->execute()) {
                                $ipResult = $ipStmt->get_result();
                                if ($ipResult instanceof mysqli_result) {
                                    while ($ipRow = $ipResult->fetch_assoc()) {
                                        $ipHistory[] = [
                                            'ipAddress' => (string) ($ipRow['ip_address'] ?? ''),
                                            'loginCount' => max(0, (int) ($ipRow['login_count'] ?? 0)),
                                            'firstSeenAt' => isset($ipRow['first_seen_at']) ? (string) $ipRow['first_seen_at'] : null,
                                            'lastSeenAt' => isset($ipRow['last_seen_at']) ? (string) $ipRow['last_seen_at'] : null,
                                            'userAgent' => isset($ipRow['last_user_agent']) ? (string) $ipRow['last_user_agent'] : '',
                                        ];
                                    }
                                    $ipResult->free();
                                }
                            }
                            $ipStmt->close();
                        }

                        $ipBans = [];
                        $ipBanStmt = $conn->prepare('SELECT `ip_address`, `ban_until`, `lifted_at`, `created_at` FROM `ip_bans` WHERE `source_user_id` = ? ORDER BY `created_at` DESC LIMIT 20');
                        if ($ipBanStmt) {
                            $ipBanStmt->bind_param('i', $userId);
                            if ($ipBanStmt->execute()) {
                                $ipBanResult = $ipBanStmt->get_result();
                                if ($ipBanResult instanceof mysqli_result) {
                                    while ($ipBanRow = $ipBanResult->fetch_assoc()) {
                                        $ipBans[] = [
                                            'ipAddress' => (string) ($ipBanRow['ip_address'] ?? ''),
                                            'banUntil' => isset($ipBanRow['ban_until']) ? (string) $ipBanRow['ban_until'] : null,
                                            'liftedAt' => isset($ipBanRow['lifted_at']) ? (string) $ipBanRow['lifted_at'] : null,
                                            'createdAt' => isset($ipBanRow['created_at']) ? (string) $ipBanRow['created_at'] : null,
                                        ];
                                    }
                                    $ipBanResult->free();
                                }
                            }
                            $ipBanStmt->close();
                        }

                        $response['success'] = true;
                        $response['user'] = [
                            'id' => (int) ($row['id'] ?? 0),
                            'login' => (string) ($row['login'] ?? ''),
                            'plus' => max(1, (int) ($row['plus'] ?? 1)),
                            'skin' => (string) ($row['skin'] ?? bober_default_skin_json()),
                            'energy' => max(0, (int) ($row['energy'] ?? 0)),
                            'lastEnergyUpdate' => max(0, (int) ($row['last_energy_update'] ?? 0)),
                            'ENERGY_MAX' => max(1, (int) ($row['ENERGY_MAX'] ?? 1)),
                            'score' => max(0, (int) ($row['score'] ?? 0)),
                            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
                            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
                            'lastActivityAt' => isset($row['last_activity_at']) ? (string) $row['last_activity_at'] : null,
                            'upgradePurchases' => [
                                'tapSmall' => max(0, (int) ($row['upgrade_tap_small_count'] ?? 0)),
                                'tapBig' => max(0, (int) ($row['upgrade_tap_big_count'] ?? 0)),
                                'energy' => max(0, (int) ($row['upgrade_energy_count'] ?? 0)),
                            ],
                            'activeBan' => !empty($row['active_ban_id']) ? [
                                'id' => (int) $row['active_ban_id'],
                                'reason' => (string) ($row['active_ban_reason'] ?? ''),
                                'banUntil' => isset($row['active_ban_until']) ? (string) $row['active_ban_until'] : null,
                                'durationDays' => max(0, (int) ($row['active_ban_duration_days'] ?? 0)),
                                'isPermanent' => max(0, (int) ($row['active_ban_duration_days'] ?? 0)) === 0
                                    || strncmp((string) ($row['active_ban_until'] ?? ''), '2099-', 5) === 0,
                                'isRepeat' => (int) ($row['active_ban_is_repeat'] ?? 0) === 1,
                            ] : null,
                            'flyBeaver' => [
                                'bestScore' => max(0, (int) ($row['fly_best_score'] ?? 0)),
                                'lastScore' => max(0, (int) ($row['fly_last_score'] ?? 0)),
                                'lastLevel' => max(1, (int) ($row['fly_last_level'] ?? 1)),
                                'gamesPlayed' => max(0, (int) ($row['fly_games_played'] ?? 0)),
                                'totalScore' => max(0, (int) ($row['fly_total_score'] ?? 0)),
                                'pendingTransferScore' => max(0, (int) ($row['fly_pending_transfer_score'] ?? 0)),
                                'transferredTotalScore' => max(0, (int) ($row['fly_transferred_total_score'] ?? 0)),
                                'lastPlayedAt' => isset($row['fly_last_played_at']) ? (string) $row['fly_last_played_at'] : null,
                            ],
                            'ipHistory' => $ipHistory,
                            'ipBans' => $ipBans,
                        ];
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'save_user_profile') {
            if (requireAdminAuth($response)) {
                $userId = max(0, (int) ($_POST['user_id'] ?? 0));
                $data = json_decode((string) ($_POST['data'] ?? '{}'), true);

                if ($userId < 1 || !is_array($data)) {
                    $response['message'] = 'Недостаточно данных для сохранения пользователя';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $userExistsStmt = $conn->prepare('SELECT `id`, `login` FROM `users` WHERE `id` = ? LIMIT 1');
                    if (!$userExistsStmt) {
                        throw new RuntimeException('Не удалось проверить пользователя.');
                    }

                    $userExistsStmt->bind_param('i', $userId);
                    if (!$userExistsStmt->execute()) {
                        $userExistsStmt->close();
                        throw new RuntimeException('Не удалось проверить пользователя.');
                    }

                    $userExistsResult = $userExistsStmt->get_result();
                    $existingUser = $userExistsResult ? $userExistsResult->fetch_assoc() : null;
                    if ($userExistsResult instanceof mysqli_result) {
                        $userExistsResult->free();
                    }
                    $userExistsStmt->close();

                    if (!$existingUser) {
                        $response['message'] = 'Пользователь не найден';
                    } else {
                        $login = bober_require_game_login($data['login'] ?? '');
                        $plus = max(1, (int) ($data['plus'] ?? 1));
                        $energyMax = max(1, (int) ($data['ENERGY_MAX'] ?? 5000));
                        $energy = max(0, min($energyMax, (int) ($data['energy'] ?? 0)));
                        $score = max(0, (int) ($data['score'] ?? 0));
                        $lastEnergyUpdate = max(0, (int) ($data['lastEnergyUpdate'] ?? 0));
                        $skin = bober_normalize_skin_json($data['skin'] ?? bober_default_skin_json());
                        $upgradePurchases = is_array($data['upgradePurchases'] ?? null) ? $data['upgradePurchases'] : [];
                        $upgradeTapSmallCount = max(0, (int) ($upgradePurchases['tapSmall'] ?? 0));
                        $upgradeTapBigCount = max(0, (int) ($upgradePurchases['tapBig'] ?? 0));
                        $upgradeEnergyCount = max(0, (int) ($upgradePurchases['energy'] ?? 0));
                        $newPassword = (string) ($data['newPassword'] ?? '');
                        $confirmPassword = (string) ($data['confirmPassword'] ?? '');
                        $fly = is_array($data['flyBeaver'] ?? null) ? $data['flyBeaver'] : [];
                        $flyBestScore = max(0, (int) ($fly['bestScore'] ?? 0));
                        $flyLastScore = max(0, (int) ($fly['lastScore'] ?? 0));
                        $flyLastLevel = max(1, (int) ($fly['lastLevel'] ?? 1));
                        $flyGamesPlayed = max(0, (int) ($fly['gamesPlayed'] ?? 0));
                        $flyTotalScore = max(0, (int) ($fly['totalScore'] ?? 0));
                        $flyPendingTransferScore = max(0, (int) ($fly['pendingTransferScore'] ?? 0));
                        $flyTransferredTotalScore = max(0, (int) ($fly['transferredTotalScore'] ?? 0));

                        $duplicateStmt = $conn->prepare('SELECT `id` FROM `users` WHERE `login` = ? AND `id` <> ? LIMIT 1');
                        if (!$duplicateStmt) {
                            throw new RuntimeException('Не удалось проверить уникальность логина.');
                        }

                        $duplicateStmt->bind_param('si', $login, $userId);
                        if (!$duplicateStmt->execute()) {
                            $duplicateStmt->close();
                            throw new RuntimeException('Не удалось проверить уникальность логина.');
                        }

                        $duplicateResult = $duplicateStmt->get_result();
                        $duplicateUser = $duplicateResult ? $duplicateResult->fetch_assoc() : null;
                        if ($duplicateResult instanceof mysqli_result) {
                            $duplicateResult->free();
                        }
                        $duplicateStmt->close();

                        $passwordValidationMessage = null;
                        if ($newPassword !== '' || $confirmPassword !== '') {
                            if (strlen($newPassword) < 6) {
                                $passwordValidationMessage = 'Пароль пользователя должен быть не короче 6 символов';
                            } elseif ($newPassword !== $confirmPassword) {
                                $passwordValidationMessage = 'Подтверждение пароля пользователя не совпадает';
                            }
                        }

                        if ($duplicateUser) {
                            $response['message'] = 'Этот логин уже занят другим пользователем';
                        } elseif ($passwordValidationMessage !== null) {
                            $response['message'] = $passwordValidationMessage;
                        } else {
                            $userAssignments = [
                                "`login` = " . sqlValueForQuery($conn, $login),
                                "`plus` = " . sqlValueForQuery($conn, $plus),
                                "`skin` = " . sqlValueForQuery($conn, $skin),
                                "`energy` = " . sqlValueForQuery($conn, $energy),
                                "`last_energy_update` = " . sqlValueForQuery($conn, $lastEnergyUpdate),
                                "`ENERGY_MAX` = " . sqlValueForQuery($conn, $energyMax),
                                "`score` = " . sqlValueForQuery($conn, $score),
                                "`upgrade_tap_small_count` = " . sqlValueForQuery($conn, $upgradeTapSmallCount),
                                "`upgrade_tap_big_count` = " . sqlValueForQuery($conn, $upgradeTapBigCount),
                                "`upgrade_energy_count` = " . sqlValueForQuery($conn, $upgradeEnergyCount),
                            ];

                            $passwordChanged = false;
                            if ($newPassword !== '') {
                                $userAssignments[] = "`password` = " . sqlValueForQuery($conn, password_hash($newPassword, PASSWORD_DEFAULT));
                                $passwordChanged = true;
                            }

                            $userUpdateSql = "UPDATE `users` SET "
                                . implode(', ', $userAssignments)
                                . " WHERE `id` = " . sqlValueForQuery($conn, $userId)
                                . " LIMIT 1";

                            if (!$conn->query($userUpdateSql)) {
                                throw new RuntimeException('Не удалось сохранить изменения пользователя.');
                            }

                            bober_ensure_fly_progress_row($conn, $userId);

                            $flyUpdateSql = "UPDATE `fly_beaver_progress` SET "
                                . "`best_score` = " . sqlValueForQuery($conn, $flyBestScore) . ", "
                                . "`last_score` = " . sqlValueForQuery($conn, $flyLastScore) . ", "
                                . "`last_level` = " . sqlValueForQuery($conn, $flyLastLevel) . ", "
                                . "`games_played` = " . sqlValueForQuery($conn, $flyGamesPlayed) . ", "
                                . "`total_score` = " . sqlValueForQuery($conn, $flyTotalScore) . ", "
                                . "`pending_transfer_score` = " . sqlValueForQuery($conn, $flyPendingTransferScore) . ", "
                                . "`transferred_total_score` = " . sqlValueForQuery($conn, $flyTransferredTotalScore)
                                . " WHERE `user_id` = " . sqlValueForQuery($conn, $userId)
                                . " LIMIT 1";

                            if (!$conn->query($flyUpdateSql)) {
                                throw new RuntimeException('Не удалось сохранить прогресс fly-beaver.');
                            }

                            bober_admin_log_action($conn, 'save_user_profile', [
                                'target_table' => 'users',
                                'query_text' => 'SAVE USER PROFILE #' . $userId,
                                'affected_rows' => 2,
                                'meta' => [
                                    'user_id' => $userId,
                                    'previous_login' => (string) ($existingUser['login'] ?? ''),
                                    'login' => $login,
                                    'password_changed' => $passwordChanged,
                                    'upgrade_purchases' => [
                                        'tapSmall' => $upgradeTapSmallCount,
                                        'tapBig' => $upgradeTapBigCount,
                                        'energy' => $upgradeEnergyCount,
                                    ],
                                ],
                            ]);

                            $response['success'] = true;
                            $response['message'] = $passwordChanged
                                ? 'Карточка пользователя и пароль сохранены'
                                : 'Карточка пользователя сохранена';
                        }
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'get_table_data') {
            if (requireAdminAuth($response)) {
                $table = normalizeTableName($_POST['table'] ?? '');

                if ($table === '') {
                    $response['message'] = 'Имя таблицы не указано';
                } else {
                    $conn = connectDB();
                    $result = $conn->query("DESCRIBE `{$table}`");

                    if ($result === false) {
                        $response['message'] = 'Таблица не найдена';
                    } else {
                        $columns = [];
                        while ($row = $result->fetch_assoc()) {
                            $columns[] = [
                                'name' => $row['Field'],
                                'type' => $row['Type'],
                                'nullable' => $row['Null'] === 'YES',
                                'key' => $row['Key'],
                                'default' => $row['Default'],
                                'extra' => $row['Extra'],
                            ];
                        }
                        $result->free();

                        $dataResult = $conn->query("SELECT * FROM `{$table}` LIMIT 100");
                        $countResult = $conn->query("SELECT COUNT(*) AS total FROM `{$table}`");

                        if ($dataResult === false || $countResult === false) {
                            $response['message'] = 'Не удалось получить данные таблицы';
                        } else {
                            $rows = [];
                            while ($row = $dataResult->fetch_assoc()) {
                                $rows[] = $row;
                            }

                            $totalRows = (int) $countResult->fetch_assoc()['total'];

                            $dataResult->free();
                            $countResult->free();

                            $response['success'] = true;
                            $response['columns'] = $columns;
                            $response['rows'] = $rows;
                            $response['row_count'] = count($rows);
                            $response['total_rows'] = $totalRows;
                        }
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'update_row') {
            if (requireAdminAuth($response)) {
                $table = normalizeTableName($_POST['table'] ?? '');
                $data = json_decode($_POST['data'] ?? '{}', true);
                $primaryKey = normalizeColumnName($_POST['primary_key'] ?? 'id');
                $primaryValue = $_POST['primary_value'] ?? '';

                if ($table === '' || $primaryValue === '' || !is_array($data)) {
                    $response['message'] = 'Недостаточно данных для обновления';
                } else {
                    $conn = connectDB();
                    $setParts = [];

                    foreach ($data as $key => $value) {
                        $column = normalizeColumnName($key);
                        if ($column === $primaryKey) {
                            continue;
                        }

                        $setParts[] = "`{$column}` = " . sqlValueForQuery($conn, $value);
                    }

                    if (!$setParts) {
                        $response['message'] = 'Нет данных для обновления';
                    } else {
                        $primaryValueSql = sqlValueForQuery($conn, $primaryValue);
                        $sql = "UPDATE `{$table}` SET " . implode(', ', $setParts) . " WHERE `{$primaryKey}` = {$primaryValueSql}";

                        if ($conn->query($sql)) {
                            $response['success'] = true;
                            $response['message'] = 'Строка успешно обновлена';
                            $response['affected_rows'] = $conn->affected_rows;
                            bober_admin_log_action($conn, 'update_row', [
                                'target_table' => $table,
                                'query_text' => $sql,
                                'affected_rows' => (int) $conn->affected_rows,
                            ]);
                        } else {
                            $response['message'] = 'Ошибка выполнения запроса: ' . $conn->error;
                        }
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'insert_row') {
            if (requireAdminAuth($response)) {
                $table = normalizeTableName($_POST['table'] ?? '');
                $data = json_decode($_POST['data'] ?? '{}', true);

                if ($table === '' || !is_array($data) || !$data) {
                    $response['message'] = 'Недостаточно данных для добавления строки';
                } else {
                    $conn = connectDB();
                    $columns = [];
                    $values = [];

                    foreach ($data as $key => $value) {
                        $column = normalizeColumnName($key);
                        $columns[] = "`{$column}`";
                        $values[] = sqlValueForQuery($conn, $value);
                    }

                    $sql = "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ')';

                    if ($conn->query($sql)) {
                        $response['success'] = true;
                        $response['message'] = 'Строка успешно добавлена';
                        $response['affected_rows'] = $conn->affected_rows;
                        bober_admin_log_action($conn, 'insert_row', [
                            'target_table' => $table,
                            'query_text' => $sql,
                            'affected_rows' => (int) $conn->affected_rows,
                        ]);
                    } else {
                        $response['message'] = 'Ошибка выполнения запроса: ' . $conn->error;
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'delete_row') {
            if (requireAdminAuth($response)) {
                $table = normalizeTableName($_POST['table'] ?? '');
                $primaryKey = normalizeColumnName($_POST['primary_key'] ?? 'id');
                $primaryValue = $_POST['primary_value'] ?? '';

                if ($table === '' || $primaryValue === '') {
                    $response['message'] = 'Недостаточно данных для удаления строки';
                } else {
                    $conn = connectDB();
                    $primaryValueSql = sqlValueForQuery($conn, $primaryValue);
                    $sql = "DELETE FROM `{$table}` WHERE `{$primaryKey}` = {$primaryValueSql} LIMIT 1";

                    if ($conn->query($sql)) {
                        $response['success'] = true;
                        $response['message'] = 'Строка успешно удалена';
                        $response['affected_rows'] = $conn->affected_rows;
                        bober_admin_log_action($conn, 'delete_row', [
                            'target_table' => $table,
                            'query_text' => $sql,
                            'affected_rows' => (int) $conn->affected_rows,
                        ]);
                    } else {
                        $response['message'] = 'Ошибка выполнения запроса: ' . $conn->error;
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'ban_user_account') {
            if (requireAdminAuth($response)) {
                $userId = max(0, (int) ($_POST['user_id'] ?? 0));
                $reason = trim((string) ($_POST['reason'] ?? 'Ручной бан администрацией'));
                $isPermanent = (string) ($_POST['is_permanent'] ?? '0') === '1';
                $durationDays = $isPermanent ? 0 : max(0, (int) ($_POST['duration_days'] ?? 0));

                if ($userId < 1) {
                    $response['message'] = 'Некорректный идентификатор пользователя';
                } elseif (!$isPermanent && $durationDays < 1) {
                    $response['message'] = 'Укажите срок бана в днях или выберите бессрочный бан';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $userStmt = $conn->prepare('SELECT login FROM users WHERE id = ? LIMIT 1');
                    if (!$userStmt) {
                        throw new RuntimeException('Не удалось подготовить получение пользователя.');
                    }

                    $userStmt->bind_param('i', $userId);
                    if (!$userStmt->execute()) {
                        $userStmt->close();
                        throw new RuntimeException('Не удалось получить пользователя.');
                    }

                    $userResult = $userStmt->get_result();
                    $userRow = $userResult ? $userResult->fetch_assoc() : null;
                    if ($userResult) {
                        $userResult->free();
                    }
                    $userStmt->close();

                    if (!$userRow) {
                        $response['message'] = 'Пользователь не найден';
                    } else {
                        $ban = bober_issue_user_ban($conn, $userId, $reason, [
                            'source' => 'admin_manual',
                            'detected_by' => 'admin_panel',
                            'duration_days' => $durationDays,
                            'is_permanent' => $isPermanent,
                            'meta' => [
                                'admin_action' => 'ban_user_account',
                                'requested_duration_days' => $isPermanent ? null : $durationDays,
                                'requested_permanent' => $isPermanent,
                            ],
                        ]);

                        $response['success'] = true;
                        $response['ban'] = $ban;
                        $response['message'] = $ban['message'] ?? 'Пользователь забанен';

                        bober_admin_log_action($conn, 'ban_user_account', [
                            'target_table' => 'users',
                            'query_text' => 'BAN USER #' . $userId,
                            'affected_rows' => 1,
                            'meta' => [
                                'user_id' => $userId,
                                'login' => $userRow['login'] ?? '',
                                'ban_until' => $ban['banUntil'] ?? null,
                                'duration_days' => $ban['durationDays'] ?? null,
                                'is_permanent' => !empty($ban['isPermanent']),
                            ],
                        ]);
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'unban_user_account') {
            if (requireAdminAuth($response)) {
                $userId = max(0, (int) ($_POST['user_id'] ?? 0));

                if ($userId < 1) {
                    $response['message'] = 'Некорректный идентификатор пользователя';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $userStmt = $conn->prepare('SELECT login FROM users WHERE id = ? LIMIT 1');
                    if (!$userStmt) {
                        throw new RuntimeException('Не удалось подготовить получение пользователя.');
                    }

                    $userStmt->bind_param('i', $userId);
                    if (!$userStmt->execute()) {
                        $userStmt->close();
                        throw new RuntimeException('Не удалось получить пользователя.');
                    }

                    $userResult = $userStmt->get_result();
                    $userRow = $userResult ? $userResult->fetch_assoc() : null;
                    if ($userResult) {
                        $userResult->free();
                    }
                    $userStmt->close();

                    if (!$userRow) {
                        $response['message'] = 'Пользователь не найден';
                    } else {
                        $liftResult = bober_lift_user_bans($conn, $userId);
                        $liftedUserBans = max(0, (int) ($liftResult['liftedUserBans'] ?? 0));
                        $liftedIpBans = max(0, (int) ($liftResult['liftedIpBans'] ?? 0));

                        $response['success'] = true;
                        $response['message'] = $liftedUserBans > 0 || $liftedIpBans > 0
                            ? 'Бан пользователя и связанные IP-баны сняты'
                            : 'Активных банов для этого пользователя не найдено';
                        $response['lifted'] = $liftResult;

                        bober_admin_log_action($conn, 'unban_user_account', [
                            'target_table' => 'users',
                            'query_text' => 'UNBAN USER #' . $userId,
                            'affected_rows' => $liftedUserBans + $liftedIpBans,
                            'meta' => [
                                'user_id' => $userId,
                                'login' => $userRow['login'] ?? '',
                                'lifted_user_bans' => $liftedUserBans,
                                'lifted_ip_bans' => $liftedIpBans,
                            ],
                        ]);
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'delete_user_account') {
            if (requireAdminAuth($response)) {
                $userId = max(0, (int) ($_POST['user_id'] ?? 0));

                if ($userId < 1) {
                    $response['message'] = 'Некорректный идентификатор пользователя';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $userStmt = $conn->prepare('SELECT login FROM users WHERE id = ? LIMIT 1');
                    if (!$userStmt) {
                        throw new RuntimeException('Не удалось подготовить получение пользователя.');
                    }

                    $userStmt->bind_param('i', $userId);
                    if (!$userStmt->execute()) {
                        $userStmt->close();
                        throw new RuntimeException('Не удалось получить пользователя.');
                    }

                    $userResult = $userStmt->get_result();
                    $userRow = $userResult ? $userResult->fetch_assoc() : null;
                    if ($userResult) {
                        $userResult->free();
                    }
                    $userStmt->close();

                    if (!$userRow) {
                        $response['message'] = 'Пользователь не найден';
                    } else {
                        $deleteMeta = [
                            'fly_beaver_runs' => 0,
                            'fly_beaver_progress' => 0,
                            'user_ip_history' => 0,
                            'ip_bans' => 0,
                            'user_bans' => 0,
                            'users' => 0,
                        ];

                        $conn->begin_transaction();

                        try {
                            $deleteQueries = [
                                'fly_beaver_runs' => 'DELETE FROM `fly_beaver_runs` WHERE `user_id` = ?',
                                'fly_beaver_progress' => 'DELETE FROM `fly_beaver_progress` WHERE `user_id` = ?',
                                'user_ip_history' => 'DELETE FROM `user_ip_history` WHERE `user_id` = ?',
                                'ip_bans' => 'DELETE FROM `ip_bans` WHERE `source_user_id` = ?',
                                'user_bans' => 'DELETE FROM `user_bans` WHERE `user_id` = ?',
                                'users' => 'DELETE FROM `users` WHERE `id` = ? LIMIT 1',
                            ];

                            foreach ($deleteQueries as $tableName => $sql) {
                                $deleteStmt = $conn->prepare($sql);
                                if (!$deleteStmt) {
                                    throw new RuntimeException('Не удалось подготовить удаление данных пользователя.');
                                }

                                $deleteStmt->bind_param('i', $userId);
                                if (!$deleteStmt->execute()) {
                                    $deleteStmt->close();
                                    throw new RuntimeException('Не удалось удалить связанные данные пользователя.');
                                }

                                $deleteMeta[$tableName] = max(0, (int) $deleteStmt->affected_rows);
                                $deleteStmt->close();
                            }

                            $conn->commit();
                        } catch (Throwable $transactionError) {
                            $conn->rollback();
                            throw $transactionError;
                        }

                        $response['success'] = true;
                        $response['message'] = 'Аккаунт и связанные данные удалены';
                        $response['deleted'] = $deleteMeta;

                        bober_admin_log_action($conn, 'delete_user_account', [
                            'target_table' => 'users',
                            'query_text' => 'DELETE USER #' . $userId,
                            'affected_rows' => array_sum($deleteMeta),
                            'meta' => [
                                'user_id' => $userId,
                                'login' => $userRow['login'] ?? '',
                                'deleted' => $deleteMeta,
                            ],
                        ]);
                    }

                    $conn->close();
                }
            }
        }
    } catch (InvalidArgumentException $error) {
        $response['message'] = $error->getMessage();
    } catch (Throwable $error) {
        $response['message'] = 'Внутренняя ошибка сервера';
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$authenticated = adminIsAuthenticated() && $bootstrapError === null;
$password_changed = isset($_SESSION['password_changed']) ? (bool) $_SESSION['password_changed'] : true;
$adminConfig = bober_load_config();
$adminDbHost = trim((string) ($adminConfig['db_host'] ?? ''));
$adminDbUser = trim((string) ($adminConfig['db_user'] ?? ''));
$adminDbName = trim((string) ($adminConfig['db_name'] ?? ''));
$darkThemeEnabled = !isset($_COOKIE['dark_theme']) || $_COOKIE['dark_theme'] === 'true';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bober Admin</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;600&family=Rubik:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #11d2ff;
            --primary-dark: #08a8cf;
            --primary-light: #dffbff;
            --secondary-color: #6e63ff;
            --secondary-dark: #4f46e5;
            --background: #eef9ff;
            --surface: rgba(255, 255, 255, 0.82);
            --surface-strong: rgba(255, 255, 255, 0.94);
            --error: #ff6b8a;
            --warning: #f7c969;
            --success: #43d7a3;
            --on-primary: #06212f;
            --on-secondary: #ffffff;
            --on-background: #13314b;
            --on-surface: #13314b;
            --muted-text: rgba(19, 49, 75, 0.68);
            --border: rgba(17, 210, 255, 0.18);
            --border-strong: rgba(17, 210, 255, 0.32);
            --hover: rgba(17, 210, 255, 0.08);
            --shadow: 0 16px 36px rgba(22, 66, 95, 0.12);
            --shadow-heavy: 0 26px 72px rgba(22, 66, 95, 0.18);
            --radius: 18px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --body-gradient:
                radial-gradient(circle at top left, rgba(110, 99, 255, 0.16), transparent 26%),
                radial-gradient(circle at top right, rgba(17, 210, 255, 0.16), transparent 25%),
                linear-gradient(180deg, #f6fdff, #eaf6ff);
            --header-gradient: linear-gradient(180deg, rgba(255, 255, 255, 0.92), rgba(240, 249, 255, 0.88));
            --sidebar-gradient: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(236, 248, 255, 0.92));
            --panel-gradient: linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(239, 249, 255, 0.88));
            --panel-soft-bg: rgba(255, 255, 255, 0.74);
            --panel-hover-bg: rgba(247, 253, 255, 0.98);
            --panel-active-bg: linear-gradient(180deg, rgba(221, 248, 255, 0.98), rgba(244, 252, 255, 0.98));
            --field-bg: rgba(255, 255, 255, 0.76);
            --auth-gradient:
                radial-gradient(circle at top left, rgba(110, 99, 255, 0.18), transparent 25%),
                radial-gradient(circle at top right, rgba(17, 210, 255, 0.18), transparent 24%),
                linear-gradient(180deg, #f6fdff, #e7f4ff);
            --overlay-bg: rgba(9, 18, 31, 0.26);
            --empty-bg: rgba(255, 255, 255, 0.56);
            --stack-bg: rgba(255, 255, 255, 0.6);
            --chip-bg: rgba(17, 210, 255, 0.08);
            --chip-border: rgba(17, 210, 255, 0.18);
            --pill-active-bg: rgba(67, 215, 163, 0.14);
            --pill-active-text: #107a5a;
            --pill-active-border: rgba(67, 215, 163, 0.24);
            --pill-banned-bg: rgba(255, 107, 138, 0.14);
            --pill-banned-text: #c34a66;
            --pill-banned-border: rgba(255, 107, 138, 0.26);
            --primary-soft-bg: rgba(17, 210, 255, 0.12);
            --secondary-soft-bg: rgba(110, 99, 255, 0.12);
        }

        .dark-theme {
            --background: #040913;
            --surface: rgba(10, 19, 34, 0.92);
            --surface-strong: rgba(14, 25, 43, 0.98);
            --on-background: #edf7ff;
            --on-surface: #edf7ff;
            --muted-text: rgba(237, 247, 255, 0.68);
            --border: rgba(123, 227, 255, 0.16);
            --border-strong: rgba(123, 227, 255, 0.3);
            --hover: rgba(123, 227, 255, 0.1);
            --shadow: 0 18px 34px rgba(0, 0, 0, 0.34);
            --shadow-heavy: 0 28px 72px rgba(0, 0, 0, 0.52);
            --body-gradient:
                radial-gradient(circle at top left, rgba(139, 92, 246, 0.18), transparent 28%),
                radial-gradient(circle at top right, rgba(123, 227, 255, 0.16), transparent 26%),
                linear-gradient(180deg, rgba(7, 14, 26, 0.98), rgba(4, 9, 19, 1));
            --header-gradient: linear-gradient(180deg, rgba(18, 31, 51, 0.98), rgba(11, 20, 35, 0.96));
            --sidebar-gradient: linear-gradient(180deg, rgba(13, 24, 41, 0.98), rgba(8, 15, 27, 0.98));
            --panel-gradient: linear-gradient(180deg, rgba(19, 31, 50, 0.92), rgba(10, 18, 31, 0.94));
            --panel-soft-bg: rgba(7, 14, 26, 0.76);
            --panel-hover-bg: rgba(10, 20, 34, 0.92);
            --panel-active-bg: linear-gradient(180deg, rgba(24, 41, 67, 0.98), rgba(13, 24, 41, 0.98));
            --field-bg: rgba(6, 12, 22, 0.62);
            --auth-gradient:
                radial-gradient(circle at top left, rgba(139, 92, 246, 0.22), transparent 25%),
                radial-gradient(circle at top right, rgba(123, 227, 255, 0.2), transparent 24%),
                linear-gradient(180deg, #07111d, #040913);
            --overlay-bg: rgba(0, 0, 0, 0.5);
            --empty-bg: rgba(7, 14, 26, 0.5);
            --stack-bg: rgba(255, 255, 255, 0.02);
            --chip-bg: rgba(123, 227, 255, 0.08);
            --chip-border: rgba(123, 227, 255, 0.12);
            --pill-active-bg: rgba(67, 215, 163, 0.12);
            --pill-active-text: #a6ffd9;
            --pill-active-border: rgba(67, 215, 163, 0.2);
            --pill-banned-bg: rgba(255, 107, 138, 0.12);
            --pill-banned-text: #ffc0ce;
            --pill-banned-border: rgba(255, 107, 138, 0.22);
            --primary-soft-bg: rgba(17, 210, 255, 0.14);
            --secondary-soft-bg: rgba(110, 99, 255, 0.18);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: var(--transition);
        }
        
        body {
            font-family: 'Rubik', sans-serif;
            background: var(--body-gradient);
            color: var(--on-background);
            line-height: 1.6;
            overflow-x: hidden;
            min-height: 100vh;
        }
        
        /* Анимации */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes slideIn {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
        
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        
        .animated {
            animation-duration: 0.3s;
            animation-fill-mode: both;
        }
        
        .fadeIn { animation-name: fadeIn; }
        .slideIn { animation-name: slideIn; }
        .slideUp { animation-name: slideUp; }
        
        /* Заголовок */
        .app-header {
            background: var(--header-gradient);
            border-bottom: 1px solid var(--border);
            padding: 0 24px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 64px;
            z-index: 1000;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .logo-area {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            color: var(--on-surface);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }
        
        .menu-toggle:hover {
            background-color: var(--hover);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            font-size: 28px;
            color: var(--primary-color);
        }
        
        .logo-text h1 {
            font-size: 20px;
            font-weight: 600;
            color: var(--primary-color);
            line-height: 1.2;
        }
        
        /* Улучшенный заголовок действий */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            position: relative;
        }
        
        .action-button {
            background: none;
            border: none;
            color: var(--on-surface);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: background-color 0.2s;
        }
        
        .action-button:hover {
            background-color: var(--hover);
        }
        
        .action-button.with-text {
            width: auto;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            gap: 8px;
            font-weight: 500;
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: var(--surface-strong);
            color: var(--on-surface);
            border-radius: 16px;
            box-shadow: var(--shadow-heavy);
            min-width: 240px;
            overflow: hidden;
            z-index: 1001;
            margin-top: 8px;
            border: 1px solid var(--border);
            display: none;
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }
        
        .user-dropdown.active {
            display: block;
            animation: slideUp 0.2s;
        }
        
        .user-dropdown-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }
        
        .user-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .user-dropdown-item:hover {
            background-color: var(--hover);
        }
        
        /* Боковая панель */
        .sidebar {
            width: 320px;
            background: var(--sidebar-gradient);
            height: calc(100vh - 64px);
            position: fixed;
            left: 0;
            top: 64px;
            overflow-y: auto;
            z-index: 900;
            border-right: 1px solid var(--border);
            transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-section {
            padding: 20px;
            border-bottom: 1px solid var(--border);
        }
        
        .sidebar-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            padding: 8px 0;
            font-weight: 600;
            color: var(--on-surface);
            font-size: 15px;
        }
        
        .toggle-icon {
            transition: transform 0.3s;
            color: var(--primary-color);
        }
        
        .toggle-icon.expanded {
            transform: rotate(90deg);
        }
        
        .table-list {
            list-style: none;
            margin-top: 8px;
            display: none;
        }
        
        .table-list.expanded {
            display: block;
        }
        
        .table-item {
            padding: 10px 16px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
            font-size: 14px;
        }
        
        .table-item:hover {
            background-color: var(--hover);
        }
        
        .table-item.active {
            background-color: var(--primary-color);
            color: var(--on-primary);
        }
        
        .table-icon {
            font-size: 18px;
        }
        
        .sidebar-item {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background-color 0.2s;
            font-weight: 500;
            color: var(--on-surface);
            border-bottom: 1px solid var(--border);
        }
        
        .sidebar-item:hover {
            background-color: var(--hover);
        }
        
        .sidebar-item.active {
            background-color: var(--primary-color);
            color: var(--on-primary);
        }
        
        /* Основной контент */
        .main-content {
            margin-left: 0;
            margin-top: 64px;
            padding: 24px;
            min-height: calc(100vh - 64px);
            transition: margin-left 0.3s;
        }
        
        .main-content.with-sidebar {
            margin-left: 320px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Карточки */
        .card {
            background-color: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        /* Формы */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--on-surface);
            font-size: 14px;
        }
        
        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            background-color: var(--field-bg);
            color: var(--on-surface);
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: 'JetBrains Mono', monospace;
            font-size: 14px;
            line-height: 1.5;
        }
        
        /* SQL редактор */
        .sql-toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .quick-sql-btn {
            background-color: var(--chip-bg);
            color: var(--primary-color);
            border: 1px solid var(--chip-border);
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
        
        .quick-sql-btn:hover {
            background-color: var(--hover);
            border-color: var(--border-strong);
        }
        
        /* Таблицы */
        .data-table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border);
            margin-top: 20px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        .data-table th {
            background-color: var(--hover);
            padding: 14px 16px;
            text-align: left;
            font-weight: 600;
            color: var(--on-surface);
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
            font-size: 14px;
        }
        
        .data-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            font-size: 14px;
        }
        
        .data-table tr:hover {
            background-color: var(--hover);
        }
        
        .data-table tr.editing {
            background-color: rgba(37, 99, 235, 0.05);
        }
        
        .edit-input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--primary-color);
            border-radius: 4px;
            background-color: var(--surface);
            color: var(--on-surface);
            font-size: 14px;
        }
        
        .edit-input:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.2);
        }
        
        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn:disabled {
            opacity: 0.58;
            cursor: not-allowed;
            transform: none !important;
            box-shadow: none !important;
            filter: saturate(0.7);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), rgba(91, 222, 255, 0.98) 58%, var(--secondary-color));
            color: var(--on-primary);
            box-shadow: 0 14px 30px rgba(17, 210, 255, 0.18);
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--shadow-heavy);
        }
        
        .btn-secondary {
            background-color: var(--secondary-color);
            color: var(--on-secondary);
        }
        
        .btn-secondary:hover {
            background-color: var(--secondary-dark);
        }
        
        .btn-outline {
            background-color: var(--chip-bg);
            border: 1px solid var(--border);
            color: var(--on-surface);
        }
        
        .btn-outline:hover {
            background-color: var(--hover);
        }
        
        .btn-danger {
            background-color: var(--error);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #dc2626;
        }
        
        .btn-success {
            background-color: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background-color: #059669;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            border-radius: 6px;
        }
        
        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: var(--panel-gradient);
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid var(--border);
            transition: transform 0.2s;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-heavy);
        }
        
        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            font-size: 24px;
        }
        
        .stat-icon.primary {
            background-color: var(--primary-soft-bg);
            color: var(--primary-color);
        }

        .stat-icon.secondary {
            background-color: var(--secondary-soft-bg);
            color: var(--secondary-color);
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
            color: var(--on-surface);
        }
        
        .stat-label {
            font-size: 14px;
            color: var(--on-surface);
            opacity: 0.7;
        }
        
        /* Модальные окна */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--overlay-bg);
            z-index: 1100;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background-color: var(--surface-strong);
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-heavy);
            transform: translateY(20px);
            transition: transform 0.3s;
            border: 1px solid var(--border);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        
        /* Уведомления */
        .notification-container {
            position: fixed;
            top: 80px;
            right: 20px;
            z-index: 1200;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .notification {
            background-color: var(--surface-strong);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow-heavy);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            border-left: 4px solid;
            transform: translateX(150%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }
        
        .notification.active {
            transform: translateX(0);
        }
        
        .notification.success {
            border-left-color: var(--success);
        }
        
        .notification.success .notification-icon {
            color: var(--success);
        }
        
        .notification.error {
            border-left-color: var(--error);
        }
        
        .notification.error .notification-icon {
            color: var(--error);
        }
        
        .notification.info {
            border-left-color: var(--primary-color);
        }
        
        .notification.info .notification-icon {
            color: var(--primary-color);
        }
        
        .notification.warning {
            border-left-color: var(--warning);
        }
        
        .notification.warning .notification-icon {
            color: var(--warning);
        }
        
        .notification-close {
            margin-left: auto;
            background: none;
            border: none;
            color: var(--on-surface);
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        
        .notification-close:hover {
            opacity: 1;
        }
        
        /* Загрузчик */
        .loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(0, 0, 0, 0.1);
            border-top-color: var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Авторизация */
        .auth-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: var(--auth-gradient);
            padding: 20px;
        }

        .auth-card {
            background-color: var(--surface-strong);
            border-radius: var(--radius);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: var(--shadow-heavy);
            border: 1px solid var(--border);
            backdrop-filter: blur(16px);
            animation: fadeIn 0.5s;
        }
        
        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-bottom: 20px;
            color: var(--primary-color);
        }
        
        .auth-title {
            font-size: 24px;
            font-weight: 600;
            color: var(--on-surface);
            margin-bottom: 8px;
        }
        
        .auth-subtitle {
            font-size: 14px;
            color: var(--on-surface);
            opacity: 0.7;
        }
        
        /* Переключатель темы */
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .theme-toggle input {
            display: none;
        }
        
        .toggle-switch {
            width: 50px;
            height: 26px;
            background-color: var(--chip-bg);
            border: 1px solid var(--chip-border);
            border-radius: 13px;
            position: relative;
            transition: background-color 0.3s;
        }

        .toggle-switch:before {
            content: "";
            position: absolute;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background-color: var(--surface-strong);
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .dark-theme .toggle-switch:before {
            transform: translateX(24px);
        }
        
        /* Поле поиска */
        .search-box {
            position: relative;
            margin-bottom: 20px;
        }
        
        .search-input {
            width: 100%;
            padding: 10px 14px 10px 40px;
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            background-color: var(--field-bg);
            color: var(--on-surface);
        }
        
        .search-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--on-surface);
            opacity: 0.5;
        }
        
        /* Адаптивность */
        @media (max-width: 1200px) {
            .main-content.with-sidebar {
                margin-left: 0;
            }
            
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
        }
        
        @media (max-width: 768px) {
            .app-header {
                padding: 0 16px;
            }
            
            .logo-text h1 {
                font-size: 18px;
            }
            
            .action-button.with-text span:not(.material-icons) {
                display: none;
            }
            
            .action-button.with-text {
                padding: 8px;
            }
            
            .main-content {
                padding: 16px;
            }
            
            .card {
                padding: 16px;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .notification {
                min-width: auto;
                width: calc(100vw - 40px);
            }
        }
        
        @media (max-width: 480px) {
            .auth-card {
                padding: 24px;
            }
        }
        
        /* Профиль настроек */
        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }
        
        .profile-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background-color: var(--stack-bg);
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        
        .profile-label {
            font-weight: 500;
            color: var(--on-surface);
        }
        
        .profile-value {
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            color: var(--primary-color);
            background-color: var(--chip-bg);
            padding: 4px 8px;
            border-radius: 8px;
        }
        
        /* Инлайн редактирование */
        .edit-actions {
            display: flex;
            gap: 8px;
        }
        
        .view-mode {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .edit-mode {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .card-subtitle {
            font-size: 14px;
            color: var(--muted-text);
            margin-top: 4px;
        }

        .accounts-view {
            display: block;
        }

        .accounts-shell {
            display: grid;
            grid-template-columns: minmax(320px, 420px) minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }

        .accounts-summary {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 12px;
            color: var(--muted-text);
            font-size: 13px;
        }

        .summary-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 12px;
            border-radius: 999px;
            background: var(--chip-bg);
            border: 1px solid var(--chip-border);
        }

        .account-list-panel,
        .account-detail-panel {
            background: var(--panel-gradient);
        }

        .account-list-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .account-list-meta {
            color: var(--muted-text);
            font-size: 13px;
            margin-bottom: 12px;
        }

        .account-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-height: 72vh;
            overflow-y: auto;
            padding-right: 4px;
        }

        .account-list-item {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 18px;
            background: var(--panel-soft-bg);
            color: var(--on-surface);
            padding: 16px;
            cursor: pointer;
            text-align: left;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .account-list-item:hover {
            transform: translateY(-1px);
            border-color: var(--border-strong);
            background: var(--panel-hover-bg);
        }

        .account-list-item.active {
            border-color: rgba(123, 227, 255, 0.5);
            box-shadow: 0 0 0 1px rgba(123, 227, 255, 0.18), 0 18px 42px rgba(0, 0, 0, 0.3);
            background: var(--panel-active-bg);
        }

        .account-list-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 8px;
        }

        .account-name {
            font-size: 17px;
            font-weight: 600;
        }

        .account-id {
            color: var(--muted-text);
            font-size: 13px;
        }

        .account-list-stats {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .account-list-foot {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .mini-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 10px;
            border-radius: 999px;
            font-size: 12px;
            background: var(--chip-bg);
            border: 1px solid var(--chip-border);
            color: var(--on-surface);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
            border: 1px solid transparent;
        }

        .status-pill.active {
            background: var(--pill-active-bg);
            color: var(--pill-active-text);
            border-color: var(--pill-active-border);
        }

        .status-pill.banned {
            background: var(--pill-banned-bg);
            color: var(--pill-banned-text);
            border-color: var(--pill-banned-border);
        }

        .account-empty {
            min-height: 420px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: var(--muted-text);
            border: 1px dashed var(--border);
            border-radius: 20px;
            background: var(--empty-bg);
            padding: 30px;
        }

        .account-detail-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .account-hero {
            display: flex;
            gap: 14px;
            align-items: center;
        }

        .account-avatar {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, rgba(123, 227, 255, 0.24), rgba(139, 92, 246, 0.26));
            color: var(--primary-light);
            border: 1px solid var(--border-strong);
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.08);
        }

        .account-title {
            font-size: 28px;
            font-weight: 700;
            line-height: 1.1;
        }

        .account-subline {
            color: var(--muted-text);
            font-size: 13px;
            margin-top: 6px;
        }

        .account-head-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-start;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .detail-section {
            padding: 18px;
            border-radius: 20px;
            border: 1px solid var(--border);
            background: var(--stack-bg);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .detail-section.wide {
            grid-column: 1 / -1;
        }

        .detail-section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 14px;
        }

        .detail-form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .detail-form-grid .form-group {
            margin-bottom: 0;
        }

        .detail-form-grid .form-group.wide {
            grid-column: 1 / -1;
        }

        .readonly-value {
            display: block;
            min-height: 44px;
            padding: 11px 14px;
            border-radius: 12px;
            border: 1px solid var(--border);
            background: var(--field-bg);
            color: var(--on-surface);
            font-size: 14px;
        }

        .code-block {
            font-family: 'JetBrains Mono', monospace;
            font-size: 12px;
            line-height: 1.55;
            white-space: pre-wrap;
            word-break: break-word;
        }

        .stack-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .stack-item {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 14px 16px;
            background: var(--stack-bg);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }

        .stack-item-title {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .stack-item-meta {
            color: var(--muted-text);
            font-size: 13px;
        }

        .inline-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
        }

        .empty-list {
            color: var(--muted-text);
            font-size: 14px;
            padding: 12px 0;
        }

        @media (max-width: 1100px) {
            .accounts-shell,
            .detail-grid,
            .detail-form-grid {
                grid-template-columns: 1fr;
            }

            .account-list {
                max-height: 40vh;
            }
        }

        @media (max-width: 768px) {
            .account-list-toolbar,
            .account-detail-head,
            .account-head-actions,
            .account-list-foot {
                flex-direction: column;
                align-items: stretch;
            }

            .account-title {
                font-size: 22px;
            }

            .account-hero {
                align-items: flex-start;
            }

            .account-avatar {
                width: 50px;
                height: 50px;
                border-radius: 16px;
            }
        }
    </style>
</head>
<body class="<?php echo $darkThemeEnabled ? 'dark-theme' : ''; ?>">
    <?php if (!$authenticated): ?>
    <!-- Экран авторизации -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <span class="material-icons" style="font-size: 48px;">database</span>
                </div>
                <h1 class="auth-title">Bober Admin</h1>
                <p class="auth-subtitle">Темная панель управления аккаунтами, банами и базой</p>
            </div>
            <?php if ($bootstrapError): ?>
            <div class="notification error" style="margin-bottom: 20px; position: relative; top: 0; right: 0; transform: none;">
                <span class="material-icons notification-icon">error</span>
                <span><?php echo htmlspecialchars($bootstrapError, ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
            <?php endif; ?>
            <form id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="password">Пароль</label>
                    <input type="password" id="password" class="form-control" placeholder="Введите пароль" required>
                    <div style="font-size: 12px; color: var(--on-surface); opacity: 0.7; margin-top: 8px;">
                        Пароль хранится вне репозитория. Используйте `BOBER_ADMIN_PASSWORD_HASH` или `BOBER_ADMIN_INITIAL_PASSWORD`.
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 20px;">
                    <span class="btn-text">Войти в систему</span>
                    <div class="loader" style="display: none; margin-left: 8px;"></div>
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <!-- Основной интерфейс -->
    <header class="app-header">
        <div class="header-content">
            <div class="logo-area">
                <button class="menu-toggle" id="menuToggle">
                    <span class="material-icons">menu</span>
                </button>
                <div class="logo">
                    <span class="material-icons logo-icon">data_array</span>
                    <div class="logo-text">
                        <h1>Bober Admin</h1>
                    </div>
                </div>
            </div>
            
            <div class="header-actions">
                <div class="theme-toggle">
                    <span class="material-icons">light_mode</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="themeToggle" <?php echo $darkThemeEnabled ? 'checked' : ''; ?>>
                    </label>
                    <span class="material-icons">dark_mode</span>
                </div>
                
                <div class="user-menu">
                    <button class="action-button with-text" id="userMenuToggle">
                        <span class="material-icons">account_circle</span>
                        <span>Профиль</span>
                    </button>
                    <div class="user-dropdown" id="userDropdown">
                        <div class="user-dropdown-item" id="profileSettingsBtn">
                            <span class="material-icons">settings</span>
                            <span>Настройки профиля</span>
                        </div>
                        <div class="user-dropdown-item" id="changePasswordBtn">
                            <span class="material-icons">password</span>
                            <span>Сменить пароль</span>
                        </div>
                        <div class="user-dropdown-item" id="logoutBtn">
                            <span class="material-icons">logout</span>
                            <span>Выйти</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Боковая панель -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-section">
            <div class="sidebar-section-header" id="tablesHeader">
                <span>Таблицы</span>
                <span class="material-icons toggle-icon">chevron_right</span>
            </div>
            <ul class="table-list" id="tableList">
                <!-- Список таблиц будет загружен через AJAX -->
            </ul>
        </div>

        <div class="sidebar-item active" id="accountsBtn">
            <span class="material-icons">groups</span>
            <span>Аккаунты</span>
        </div>
        
        <div class="sidebar-item" id="statisticsBtn">
            <span class="material-icons">analytics</span>
            <span>Статистика</span>
        </div>
        
        <div class="sidebar-item" id="sqlEditorBtn">
            <span class="material-icons">code</span>
            <span>SQL Редактор</span>
        </div>
    </aside>
    
    <!-- Основной контент -->
    <main class="main-content" id="mainContent">
        <div class="container">
            <!-- Панель статистики -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon primary">
                        <span class="material-icons">groups</span>
                    </div>
                    <div class="stat-value" id="tableCount">0</div>
                    <div class="stat-label">Пользователей</div>
                </div>
                
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon secondary">
                        <span class="material-icons">gpp_bad</span>
                    </div>
                    <div class="stat-value" id="totalRows">0</div>
                    <div class="stat-label">Активных банов</div>
                </div>
                
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon primary">
                        <span class="material-icons">terminal</span>
                    </div>
                    <div class="stat-value" id="queryCount">0</div>
                    <div class="stat-label">SQL запросов</div>
                </div>
                
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon secondary">
                        <span class="material-icons">timer</span>
                    </div>
                    <div class="stat-value" id="activeTime">0:00</div>
                    <div class="stat-label">В панели</div>
                </div>
            </div>

            <div class="accounts-view animated fadeIn" id="accountsView">
                <div class="card account-list-panel">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">
                                <span class="material-icons">groups</span>
                                Аккаунты
                            </h2>
                            <div class="card-subtitle">Поиск по логину или ID, быстрый бан/разбан и редактирование прогресса без SQL.</div>
                            <div class="accounts-summary">
                                <div class="summary-chip">
                                    <span class="material-icons" style="font-size: 16px;">shield</span>
                                    <span>Бан пользователя тянет связанные IP-адреса</span>
                                </div>
                                <div class="summary-chip">
                                    <span class="material-icons" style="font-size: 16px;">phone_iphone</span>
                                    <span>Панель адаптирована для телефона</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="accounts-shell">
                        <section class="card account-list-panel" style="margin-bottom: 0;">
                            <div class="account-list-toolbar">
                                <div class="search-box" style="flex: 1; margin-bottom: 0;">
                                    <span class="material-icons search-icon">search</span>
                                    <input type="text" id="accountSearchInput" class="search-input" placeholder="Найти аккаунт по логину или ID">
                                </div>
                                <button class="btn btn-outline" id="refreshAccountsBtn">
                                    <span class="material-icons">refresh</span>
                                    Обновить
                                </button>
                            </div>

                            <div class="account-list-meta" id="accountListMeta">Загрузка аккаунтов...</div>
                            <div class="account-list" id="accountList"></div>
                        </section>

                        <section class="card account-detail-panel" style="margin-bottom: 0;">
                            <div id="accountDetailEmpty" class="account-empty">
                                <div>
                                    <span class="material-icons" style="font-size: 52px; color: var(--primary-color); opacity: 0.8;">manage_accounts</span>
                                    <p style="margin-top: 14px;">Выберите аккаунт слева, и здесь появятся бан, прогресс, история IP и редактор данных.</p>
                                </div>
                            </div>
                            <div id="accountDetailContainer" style="display: none;"></div>
                        </section>
                    </div>
                </div>
            </div>
            
            <!-- Карточка SQL редактора -->
            <div class="card animated fadeIn" id="sqlEditorCard" style="display: none;">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">
                            <span class="material-icons">code</span>
                            SQL Редактор
                        </h2>
                        <div class="card-subtitle" style="font-size: 14px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;">
                            Разрешен один SQL-запрос за запуск. Изменения применяются сразу.
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-primary" id="executeSqlBtn">
                            <span class="material-icons">play_arrow</span>
                            Выполнить
                        </button>
                    </div>
                </div>
                
                <div class="sql-toolbar">
                    <button class="quick-sql-btn" data-sql="SHOW TABLES;">
                        <span class="material-icons">list</span>
                        Показать таблицы
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT * FROM users LIMIT 10;">
                        <span class="material-icons">people</span>
                        Пользователи
                    </button>
                    <button class="quick-sql-btn" data-sql="DESCRIBE users;">
                        <span class="material-icons">schema</span>
                        Структура users
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT * FROM admin_audit_log ORDER BY id DESC LIMIT 50;">
                        <span class="material-icons">history</span>
                        Аудит админки
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT * FROM fly_beaver_progress ORDER BY best_score DESC LIMIT 50;">
                        <span class="material-icons">sports_esports</span>
                        Fly Progress
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT * FROM user_bans ORDER BY id DESC LIMIT 50;">
                        <span class="material-icons">gpp_bad</span>
                        Активность банов
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT * FROM user_ip_history ORDER BY last_seen_at DESC LIMIT 100;">
                        <span class="material-icons">lan</span>
                        История IP
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT * FROM ip_bans ORDER BY id DESC LIMIT 100;">
                        <span class="material-icons">shield</span>
                        IP Баны
                    </button>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="sqlQuery">SQL запрос</label>
                    <textarea id="sqlQuery" class="form-control" placeholder="Введите SQL запрос...">SHOW TABLES;</textarea>
                    <div style="font-size: 12px; color: var(--on-surface); opacity: 0.7; margin-top: 8px;">
                        Используйте Ctrl+Enter для быстрого выполнения одного SQL-запроса
                    </div>
                </div>
                
                <div id="sqlResults">
                    <!-- Результаты SQL запросов будут отображаться здесь -->
                </div>
            </div>
            
            <!-- Карточка данных таблицы -->
            <div class="card animated fadeIn" id="tableDataCard" style="display: none;">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">
                            <span class="material-icons">table_rows</span>
                            <span id="tableTitle">Данные таблицы</span>
                        </h2>
                        <div class="card-subtitle" style="font-size: 14px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;" id="tableSubtitle">
                            Просмотр и редактирование данных
                        </div>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button class="btn btn-outline btn-small" id="refreshTableBtn">
                            <span class="material-icons">refresh</span>
                            Обновить
                        </button>
                        <button class="btn btn-primary btn-small" id="addRowBtn">
                            <span class="material-icons">add</span>
                            Добавить строку
                        </button>
                    </div>
                </div>
                
                <div class="table-container" id="tableDataContainer">
                    <!-- Данные таблицы будут отображаться здесь -->
                </div>
            </div>
        </div>
    </main>
    
    <!-- Модальное окно смены пароля -->
    <div class="modal-overlay" id="changePasswordModal">
        <div class="modal-content">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="material-icons">password</span>
                    Смена пароля
                </h3>
                <button class="action-button btn-icon" id="closeChangePasswordModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <?php if (!$password_changed): ?>
                <div class="notification info" style="margin-bottom: 20px; position: relative; top: 0; right: 0; transform: none;">
                    <span class="material-icons notification-icon">info</span>
                    <span>Рекомендуется сменить пароль по умолчанию на более сложный</span>
                </div>
                <?php endif; ?>
                <form id="changePasswordForm">
                    <div class="form-group">
                        <label class="form-label" for="currentPassword">Текущий пароль</label>
                        <input type="password" id="currentPassword" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="newPassword">Новый пароль</label>
                        <input type="password" id="newPassword" class="form-control" required minlength="6">
                        <div style="font-size: 12px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;">
                            Минимум 6 символов
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirmPassword">Подтверждение пароля</label>
                        <input type="password" id="confirmPassword" class="form-control" required minlength="6">
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelChangePassword">Отмена</button>
                <button class="btn btn-primary" id="savePasswordBtn">
                    <span class="btn-text">Сохранить</span>
                    <div class="loader" style="display: none; margin-left: 8px;"></div>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно настроек профиля -->
    <div class="modal-overlay" id="profileSettingsModal">
        <div class="modal-content">
            <div class="card-header">
                <h3 class="card-title">
                    <span class="material-icons">settings</span>
                    Настройки профиля
                </h3>
                <button class="action-button btn-icon" id="closeProfileSettingsModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="profile-info">
                    <div class="profile-item">
                        <span class="profile-label">Хост базы данных:</span>
                        <span class="profile-value"><?php echo htmlspecialchars($adminDbHost !== '' ? $adminDbHost : 'не настроен', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-item">
                        <span class="profile-label">Пользователь БД:</span>
                        <span class="profile-value"><?php echo htmlspecialchars($adminDbUser !== '' ? $adminDbUser : 'не настроен', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-item">
                        <span class="profile-label">Имя базы данных:</span>
                        <span class="profile-value"><?php echo htmlspecialchars($adminDbName !== '' ? $adminDbName : 'не настроено', ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-item">
                        <span class="profile-label">Кодировка:</span>
                        <span class="profile-value">UTF-8</span>
                    </div>
                    <div class="profile-item">
                        <span class="profile-label">Статус пароля:</span>
                        <span class="profile-value"><?php echo $password_changed ? 'Изменен' : 'По умолчанию'; ?></span>
                    </div>
                </div>
                
                <div style="margin-top: 24px; padding-top: 20px; border-top: 1px solid var(--border);">
                    <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 16px; color: var(--on-surface);">
                        <span class="material-icons" style="font-size: 18px; vertical-align: middle; margin-right: 8px;">info</span>
                        Информация о системе
                    </h4>
                    <div style="font-size: 14px; color: var(--on-surface); opacity: 0.8; line-height: 1.6;">
                        <p>Версия PHP: <?php echo phpversion(); ?></p>
                        <p>Тип базы данных: MySQL</p>
                        <p>Версия SQL панели: 2.0</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="closeProfileSettingsBtn">Закрыть</button>
                <button class="btn btn-primary" onclick="showChangePasswordModal()">
                    <span class="material-icons" style="font-size: 18px; margin-right: 6px;">password</span>
                    Сменить пароль
                </button>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно добавления строки -->
    <div class="modal-overlay" id="addRowModal">
        <div class="modal-content">
            <div class="card-header">
                <h3 class="card-title" id="addModalTitle">Добавление строки</h3>
                <button class="action-button btn-icon" id="closeAddRowModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <form id="addRowForm">
                    <!-- Поля формы будут динамически добавлены -->
                </form>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelAddRow">Отмена</button>
                <button class="btn btn-primary" id="saveNewRowBtn">
                    <span class="btn-text">Сохранить</span>
                    <div class="loader" style="display: none; margin-left: 8px;"></div>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Уведомления -->
    <div class="notification-container" id="notificationContainer"></div>
    
    <script>
        // Глобальные переменные
        let currentTable = '';
        let queryCount = parseInt(localStorage.getItem('queryCount') || '0');
        let sessionStartTime = new Date();
        let activeTimeInterval;
        let editingRowId = null;
        let tableColumns = [];
        let selectedAccountId = null;
        let selectedAccountBaselineSnapshot = '';
        let selectedAccountDirty = false;
        let currentAdminView = 'accounts';
        let accountSearchDebounce = null;
        let lastAccountSearch = '';
        
        // Инициализация при загрузке страницы
        document.addEventListener('DOMContentLoaded', function() {
            <?php if (!$authenticated): ?>
            // Инициализация экрана авторизации
            initLoginScreen();
            <?php else: ?>
            // Инициализация основной панели
            initMainPanel();
            <?php endif; ?>
            
            // Инициализация переключателя темы
            initThemeToggle();
        });
        
        // Функция инициализации экрана авторизации
        function initLoginScreen() {
            const loginForm = document.getElementById('loginForm');
            const passwordInput = document.getElementById('password');
            const submitBtn = loginForm.querySelector('button[type="submit"]');
            const btnText = submitBtn.querySelector('.btn-text');
            const loader = submitBtn.querySelector('.loader');
            
            // Фокус на поле пароля
            passwordInput.focus();
            
            loginForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const password = passwordInput.value.trim();
                
                if (!password) {
                    showNotification('Введите пароль', 'error');
                    return;
                }
                
                // Показываем лоадер
                btnText.style.display = 'none';
                loader.style.display = 'inline-block';
                submitBtn.disabled = true;
                
                // Отправляем запрос на авторизацию
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'login',
                        password: password
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('Авторизация успешна', 'success');
                        
                        // Если пароль не менялся, показываем уведомление
                        if (!data.password_changed) {
                            setTimeout(() => {
                                showNotification('Рекомендуется сменить пароль по умолчанию', 'warning', 5000);
                            }, 1000);
                        }
                        
                        // Перезагружаем страницу
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Ошибка сети', 'error');
                })
                .finally(() => {
                    // Скрываем лоадер
                    btnText.style.display = 'inline';
                    loader.style.display = 'none';
                    submitBtn.disabled = false;
                });
            });
        }

        function postAction(params) {
            return fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(params)
            }).then(response => response.json());
        }
        
        // Функция инициализации основной панели
        function initMainPanel() {
            // Инициализация таймера активного времени
            updateActiveTime();
            activeTimeInterval = setInterval(updateActiveTime, 60000);
            
            // Обновляем статистику
            updateStats();
            
            // Загружаем список таблиц
            loadTables();
            
            // Инициализация элементов интерфейса
            initUIElements();
            showAccountsView();
            
            // Если пароль не менялся, показываем уведомление
            <?php if (!$password_changed): ?>
            setTimeout(() => {
                showNotification('Рекомендуется сменить пароль по умолчанию на более сложный', 'warning', 5000);
            }, 2000);
            <?php endif; ?>
        }
        
        // Функция обновления активного времени
        function updateActiveTime() {
            const now = new Date();
            const diff = Math.floor((now - sessionStartTime) / 1000);
            const minutes = Math.floor(diff / 60);
            const seconds = diff % 60;
            const timeStr = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            document.getElementById('activeTime').textContent = timeStr;
        }
        
        // Функция обновления статистики
        function updateStats() {
            // Обновляем счетчик запросов
            document.getElementById('queryCount').textContent = queryCount;
            
            // Сохраняем в localStorage
            localStorage.setItem('queryCount', queryCount);
        }

        function loadDashboardStats() {
            postAction({
                action: 'get_dashboard_stats'
            })
            .then(data => {
                if (!data.success || !data.stats) {
                    return;
                }

                document.getElementById('tableCount').textContent = Number(data.stats.users_count || 0).toLocaleString('ru-RU');
                document.getElementById('totalRows').textContent = Number(data.stats.active_bans || 0).toLocaleString('ru-RU');
                document.getElementById('tableCount').title = `Таблиц в базе: ${Number(data.stats.table_count || 0).toLocaleString('ru-RU')}`;
                document.getElementById('totalRows').title = `Очков в очереди из fly-beaver: ${Number(data.stats.fly_pending_score || 0).toLocaleString('ru-RU')}`;
            })
            .catch(error => {
                console.error('Error:', error);
            });
        }
        
        // Функция инициализации элементов UI
        function initUIElements() {
            // Кнопка меню для мобильных устройств
            document.getElementById('menuToggle').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
            });
            
            // Меню пользователя
            document.getElementById('userMenuToggle').addEventListener('click', function(e) {
                e.stopPropagation();
                document.getElementById('userDropdown').classList.toggle('active');
            });
            
            // Закрытие меню пользователя при клике вне его
            document.addEventListener('click', function(e) {
                const userDropdown = document.getElementById('userDropdown');
                const userMenuToggle = document.getElementById('userMenuToggle');
                
                if (!userMenuToggle.contains(e.target) && !userDropdown.contains(e.target)) {
                    userDropdown.classList.remove('active');
                }
            });
            
            // Кнопка выхода
            document.getElementById('logoutBtn').addEventListener('click', function() {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'logout'
                    })
                })
                .then(() => {
                    showNotification('Выход выполнен', 'success');
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                });
            });
            
            // Кнопка смены пароля
            document.getElementById('changePasswordBtn').addEventListener('click', function() {
                document.getElementById('userDropdown').classList.remove('active');
                showChangePasswordModal();
            });
            
            // Кнопка настроек профиля
            document.getElementById('profileSettingsBtn').addEventListener('click', function() {
                document.getElementById('userDropdown').classList.remove('active');
                showProfileSettingsModal();
            });
            
            // Закрытие модального окна смены пароля
            document.getElementById('closeChangePasswordModal').addEventListener('click', function() {
                hideChangePasswordModal();
            });
            
            document.getElementById('cancelChangePassword').addEventListener('click', function() {
                hideChangePasswordModal();
            });
            
            // Форма смены пароля
            document.getElementById('savePasswordBtn').addEventListener('click', function() {
                const currentPassword = document.getElementById('currentPassword').value;
                const newPassword = document.getElementById('newPassword').value;
                const confirmPassword = document.getElementById('confirmPassword').value;
                
                if (!currentPassword || !newPassword || !confirmPassword) {
                    showNotification('Заполните все поля', 'error');
                    return;
                }
                
                if (newPassword.length < 6) {
                    showNotification('Новый пароль должен содержать минимум 6 символов', 'error');
                    return;
                }
                
                if (newPassword !== confirmPassword) {
                    showNotification('Пароли не совпадают', 'error');
                    return;
                }
                
                const saveBtn = document.getElementById('savePasswordBtn');
                const btnText = saveBtn.querySelector('.btn-text');
                const loader = saveBtn.querySelector('.loader');
                
                // Показываем лоадер
                btnText.style.display = 'none';
                loader.style.display = 'inline-block';
                saveBtn.disabled = true;
                
                // Отправляем запрос на смену пароля
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'change_password',
                        current_password: currentPassword,
                        new_password: newPassword,
                        confirm_password: confirmPassword
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification(data.message, 'success');
                        hideChangePasswordModal();
                        document.getElementById('currentPassword').value = '';
                        document.getElementById('newPassword').value = '';
                        document.getElementById('confirmPassword').value = '';
                    } else {
                        showNotification(data.message, 'error');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('Ошибка сети', 'error');
                })
                .finally(() => {
                    // Скрываем лоадер
                    btnText.style.display = 'inline';
                    loader.style.display = 'none';
                    saveBtn.disabled = false;
                });
            });
            
            // Кнопка выполнения SQL запроса
            document.getElementById('executeSqlBtn').addEventListener('click', function() {
                const sqlQuery = document.getElementById('sqlQuery').value.trim();
                if (sqlQuery) {
                    executeSql(sqlQuery);
                    queryCount++;
                    updateStats();
                }
            });
            
            // Кнопки быстрых SQL запросов
            document.querySelectorAll('.quick-sql-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sql = this.dataset.sql;
                    document.getElementById('sqlQuery').value = sql;
                    document.getElementById('executeSqlBtn').click();
                });
            });
            
            // Кнопка обновления таблицы
            document.getElementById('refreshTableBtn').addEventListener('click', function() {
                if (currentTable) {
                    loadTableData(currentTable);
                    showNotification('Таблица обновлена', 'success');
                }
            });
            
            // Кнопка добавления строки
            document.getElementById('addRowBtn').addEventListener('click', function() {
                if (currentTable) {
                    showAddRowForm(currentTable);
                }
            });
            
            // Закрытие модального окна добавления строки
            document.getElementById('closeAddRowModal').addEventListener('click', function() {
                hideAddRowModal();
            });
            
            document.getElementById('cancelAddRow').addEventListener('click', function() {
                hideAddRowModal();
            });
            
            // Кнопки бокового меню
            document.getElementById('tablesHeader').addEventListener('click', function() {
                const tableList = document.getElementById('tableList');
                const toggleIcon = this.querySelector('.toggle-icon');
                
                tableList.classList.toggle('expanded');
                toggleIcon.classList.toggle('expanded');
            });

            document.getElementById('accountsBtn').addEventListener('click', function() {
                showAccountsView();
            });
            
            document.getElementById('statisticsBtn').addEventListener('click', function() {
                showStatistics();
            });
            
            document.getElementById('sqlEditorBtn').addEventListener('click', function() {
                showSqlEditor();
            });
            
            // Закрытие модального окна настроек профиля
            document.getElementById('closeProfileSettingsModal').addEventListener('click', function() {
                hideProfileSettingsModal();
            });
            
            document.getElementById('closeProfileSettingsBtn').addEventListener('click', function() {
                hideProfileSettingsModal();
            });
            
            // Обработка нажатия Ctrl+Enter в SQL редакторе
            document.getElementById('sqlQuery').addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('executeSqlBtn').click();
                }
            });

            const accountSearchInput = document.getElementById('accountSearchInput');
            if (accountSearchInput) {
                accountSearchInput.addEventListener('input', function() {
                    const value = this.value.trim();
                    clearTimeout(accountSearchDebounce);
                    accountSearchDebounce = setTimeout(() => {
                        loadAccounts(value);
                    }, 220);
                });
            }

            const refreshAccountsBtn = document.getElementById('refreshAccountsBtn');
            if (refreshAccountsBtn) {
                refreshAccountsBtn.addEventListener('click', function() {
                    loadAccounts(document.getElementById('accountSearchInput').value.trim());
                });
            }
        }
        
        // Функция показа модального окна смены пароля
        function showChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.add('active');
        }
        
        // Функция скрытия модального окна смены пароля
        function hideChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.remove('active');
        }
        
        // Функция показа модального окна настроек профиля
        function showProfileSettingsModal() {
            document.getElementById('profileSettingsModal').classList.add('active');
        }
        
        // Функция скрытия модального окна настроек профиля
        function hideProfileSettingsModal() {
            document.getElementById('profileSettingsModal').classList.remove('active');
        }
        
        // Функция показа модального окна добавления строки
        function showAddRowModal() {
            document.getElementById('addRowModal').classList.add('active');
        }
        
        // Функция скрытия модального окна добавления строки
        function hideAddRowModal() {
            document.getElementById('addRowModal').classList.remove('active');
        }

        function showAccountsView() {
            currentAdminView = 'accounts';
            document.getElementById('accountsView').style.display = 'block';
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'none';
            document.getElementById('statsGrid').style.display = 'grid';
            updateActiveMenuItem('accountsBtn');
            loadDashboardStats();
            loadAccounts(document.getElementById('accountSearchInput').value.trim());
        }
        
        // Функция показа статистики
        function showStatistics() {
            currentAdminView = 'statistics';
            document.getElementById('accountsView').style.display = 'none';
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'none';
            document.getElementById('statsGrid').style.display = 'grid';
            loadDashboardStats();
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('statisticsBtn');
        }
        
        // Функция показа SQL редактора
        function showSqlEditor() {
            currentAdminView = 'sql';
            document.getElementById('accountsView').style.display = 'none';
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'block';
            document.getElementById('statsGrid').style.display = 'none';
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('sqlEditorBtn');
        }
        
        // Функция обновления активного элемента меню
        function updateActiveMenuItem(activeId) {
            // Убираем активный класс у всех элементов
            document.querySelectorAll('.sidebar-item, .table-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Добавляем активный класс к выбранному элементу
            if (activeId) {
                const activeElement = document.getElementById(activeId);
                if (activeElement) {
                    activeElement.classList.add('active');
                }
            }
        }

        function formatAdminDateTime(value) {
            if (!value) {
                return '—';
            }

            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return String(value);
            }

            return date.toLocaleString('ru-RU');
        }

        function formatAdminNumber(value) {
            return Number(value || 0).toLocaleString('ru-RU');
        }

        function markActiveAccountInList() {
            document.querySelectorAll('.account-list-item').forEach(item => {
                item.classList.toggle('active', Number(item.dataset.userId) === Number(selectedAccountId));
            });
        }

        function loadAccounts(search = '') {
            lastAccountSearch = search;

            const meta = document.getElementById('accountListMeta');
            const list = document.getElementById('accountList');

            if (meta) {
                meta.textContent = 'Загрузка аккаунтов...';
            }
            if (list) {
                list.innerHTML = `
                    <div class="empty-list">
                        <div class="loader" style="margin-right: 10px;"></div>
                        Загружаю список аккаунтов...
                    </div>
                `;
            }

            postAction({
                action: 'get_users_overview',
                search: search
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось загрузить список аккаунтов');
                }

                renderAccountsList(data.users || [], Number(data.total || 0), search);

                if (Array.isArray(data.users) && data.users.length > 0) {
                    const hasSelected = data.users.some(user => Number(user.id) === Number(selectedAccountId));
                    const nextUserId = hasSelected ? selectedAccountId : Number(data.users[0].id);
                    if (nextUserId > 0) {
                        loadUserProfile(nextUserId);
                    }
                } else {
                    selectedAccountId = null;
                    selectedAccountBaselineSnapshot = '';
                    selectedAccountDirty = false;
                    document.getElementById('accountDetailContainer').style.display = 'none';
                    document.getElementById('accountDetailEmpty').style.display = 'flex';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                if (meta) {
                    meta.textContent = 'Не удалось загрузить аккаунты';
                }
                if (list) {
                    list.innerHTML = `<div class="empty-list">${escapeHtml(error.message || 'Ошибка сети при загрузке аккаунтов')}</div>`;
                }
                showNotification(error.message || 'Ошибка загрузки аккаунтов', 'error');
            });
        }

        function renderAccountsList(users, total, search) {
            const meta = document.getElementById('accountListMeta');
            const list = document.getElementById('accountList');

            if (!list) {
                return;
            }

            if (!Array.isArray(users) || users.length === 0) {
                list.innerHTML = `<div class="empty-list">По запросу ничего не найдено.</div>`;
                if (meta) {
                    meta.textContent = search
                        ? `Поиск "${search}" ничего не нашел`
                        : 'Аккаунты пока не найдены';
                }
                return;
            }

            if (meta) {
                meta.textContent = search
                    ? `Найдено ${formatAdminNumber(total)} аккаунтов по запросу "${search}", показаны первые ${formatAdminNumber(users.length)}`
                    : `Всего аккаунтов: ${formatAdminNumber(total)}. Показаны первые ${formatAdminNumber(users.length)} по активности.`;
            }

            list.innerHTML = users.map(user => `
                <button class="account-list-item" data-user-id="${Number(user.id)}" type="button">
                    <div class="account-list-top">
                        <div>
                            <div class="account-name">${escapeHtml(user.login || 'Без логина')}</div>
                            <div class="account-id">ID ${Number(user.id)}</div>
                        </div>
                        <div class="status-pill ${user.isBanned ? 'banned' : 'active'}">
                            <span class="material-icons" style="font-size: 16px;">${user.isBanned ? 'gpp_bad' : 'verified_user'}</span>
                            <span>${user.isBanned ? 'Забанен' : 'Активен'}</span>
                        </div>
                    </div>
                    <div class="account-list-stats">
                        <span class="mini-chip"><span class="material-icons" style="font-size: 14px;">toll</span>${formatAdminNumber(user.score)}</span>
                        <span class="mini-chip"><span class="material-icons" style="font-size: 14px;">bolt</span>${formatAdminNumber(user.energy)}/${formatAdminNumber(user.energyMax)}</span>
                        <span class="mini-chip"><span class="material-icons" style="font-size: 14px;">sports_esports</span>${formatAdminNumber(user.flyBestScore)}</span>
                    </div>
                    <div class="account-list-foot">
                        <div class="account-id">
                            Последняя активность: ${escapeHtml(formatAdminDateTime(user.lastActivityAt))}
                        </div>
                        <div class="account-id">
                            ${user.isBanned && user.banUntil ? (String(user.banUntil).startsWith('2099-') ? 'Бессрочный бан' : `Бан до ${escapeHtml(formatAdminDateTime(user.banUntil))}`) : `Обновлен ${escapeHtml(formatAdminDateTime(user.updatedAt))}`}
                        </div>
                    </div>
                </button>
            `).join('');

            list.querySelectorAll('.account-list-item').forEach(item => {
                item.addEventListener('click', function() {
                    loadUserProfile(Number(this.dataset.userId));
                });
            });

            markActiveAccountInList();
        }

        function loadUserProfile(userId) {
            selectedAccountId = Number(userId) || 0;
            if (selectedAccountId < 1) {
                return;
            }

            selectedAccountBaselineSnapshot = '';
            selectedAccountDirty = false;
            markActiveAccountInList();
            document.getElementById('accountDetailEmpty').style.display = 'none';
            document.getElementById('accountDetailContainer').style.display = 'block';
            document.getElementById('accountDetailContainer').innerHTML = `
                <div class="account-empty">
                    <div>
                        <div class="loader" style="width: 42px; height: 42px; margin: 0 auto;"></div>
                        <p style="margin-top: 16px;">Загружаю карточку пользователя...</p>
                    </div>
                </div>
            `;

            postAction({
                action: 'get_user_profile',
                user_id: String(selectedAccountId)
            })
            .then(data => {
                if (!data.success || !data.user) {
                    throw new Error(data.message || 'Не удалось загрузить карточку пользователя');
                }

                renderUserProfile(data.user);
            })
            .catch(error => {
                console.error('Error:', error);
                selectedAccountBaselineSnapshot = '';
                selectedAccountDirty = false;
                document.getElementById('accountDetailContainer').innerHTML = `
                    <div class="account-empty">
                        <div>
                            <span class="material-icons" style="font-size: 52px; color: var(--error);">error</span>
                            <p style="margin-top: 14px;">${escapeHtml(error.message || 'Ошибка загрузки карточки пользователя')}</p>
                        </div>
                    </div>
                `;
                showNotification(error.message || 'Ошибка загрузки пользователя', 'error');
            });
        }

        function renderStackItems(items, emptyMessage, formatter) {
            if (!Array.isArray(items) || items.length === 0) {
                return `<div class="empty-list">${escapeHtml(emptyMessage)}</div>`;
            }

            return `<div class="stack-list">${items.map(item => formatter(item)).join('')}</div>`;
        }

        function normalizeSelectedAccountPayload(payload) {
            return {
                login: String(payload.login || '').trim(),
                score: Number(payload.score || 0),
                plus: Number(payload.plus || 1),
                energy: Number(payload.energy || 0),
                ENERGY_MAX: Number(payload.ENERGY_MAX || 1),
                lastEnergyUpdate: Number(payload.lastEnergyUpdate || 0),
                skin: String(payload.skin || ''),
                upgradePurchases: {
                    tapSmall: Number((payload.upgradePurchases || {}).tapSmall || 0),
                    tapBig: Number((payload.upgradePurchases || {}).tapBig || 0),
                    energy: Number((payload.upgradePurchases || {}).energy || 0)
                },
                newPassword: String(payload.newPassword || ''),
                confirmPassword: String(payload.confirmPassword || ''),
                flyBeaver: {
                    bestScore: Number((payload.flyBeaver || {}).bestScore || 0),
                    lastScore: Number((payload.flyBeaver || {}).lastScore || 0),
                    lastLevel: Number((payload.flyBeaver || {}).lastLevel || 1),
                    gamesPlayed: Number((payload.flyBeaver || {}).gamesPlayed || 0),
                    totalScore: Number((payload.flyBeaver || {}).totalScore || 0),
                    pendingTransferScore: Number((payload.flyBeaver || {}).pendingTransferScore || 0),
                    transferredTotalScore: Number((payload.flyBeaver || {}).transferredTotalScore || 0)
                }
            };
        }

        function getSelectedAccountPayloadSnapshot() {
            return JSON.stringify(normalizeSelectedAccountPayload(collectSelectedAccountPayload()));
        }

        function setSelectedAccountDirty(isDirty) {
            selectedAccountDirty = Boolean(isDirty);
            const saveBtn = document.getElementById('saveAccountBtn');
            if (!saveBtn) {
                return;
            }

            saveBtn.disabled = !selectedAccountDirty;
            saveBtn.title = selectedAccountDirty ? 'Есть несохраненные изменения' : 'Изменений нет';
        }

        function syncSelectedAccountDirtyState() {
            const saveBtn = document.getElementById('saveAccountBtn');
            if (!saveBtn || selectedAccountBaselineSnapshot === '') {
                setSelectedAccountDirty(false);
                return;
            }

            setSelectedAccountDirty(getSelectedAccountPayloadSnapshot() !== selectedAccountBaselineSnapshot);
        }

        function bindSelectedAccountDirtyTracking() {
            const trackedFieldIds = [
                'adminUserLogin',
                'adminUserScore',
                'adminUserPlus',
                'adminUserEnergy',
                'adminUserEnergyMax',
                'adminUserEnergyTs',
                'adminUserSkin',
                'adminUpgradeTapSmall',
                'adminUpgradeTapBig',
                'adminUpgradeEnergy',
                'adminUserNewPassword',
                'adminUserConfirmPassword',
                'flyBestScore',
                'flyLastScore',
                'flyLastLevel',
                'flyGamesPlayed',
                'flyTotalScore',
                'flyPendingTransferScore',
                'flyTransferredTotalScore'
            ];

            trackedFieldIds.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (!field) {
                    return;
                }

                field.addEventListener('input', syncSelectedAccountDirtyState);
                field.addEventListener('change', syncSelectedAccountDirtyState);
            });
        }

        function renderUserProfile(user) {
            const activeBan = user.activeBan || null;
            const fly = user.flyBeaver || {};
            const detailContainer = document.getElementById('accountDetailContainer');

            detailContainer.innerHTML = `
                <div class="account-detail-head">
                    <div class="account-head-actions">
                        <button class="btn ${activeBan ? 'btn-success' : 'btn-danger'}" id="toggleBanAccountBtn">
                            <span class="material-icons">${activeBan ? 'verified_user' : 'gpp_bad'}</span>
                            ${activeBan ? 'Разбанить' : 'Забанить'}
                        </button>
                        <button class="btn btn-danger" id="deleteAccountBtn">
                            <span class="material-icons">delete_forever</span>
                            Удалить аккаунт
                        </button>
                        <button class="btn btn-primary" id="saveAccountBtn">
                            <span class="material-icons">save</span>
                            Сохранить
                        </button>
                    </div>
                    <div class="account-hero">
                        <div class="account-avatar">
                            <span class="material-icons" style="font-size: 30px;">person</span>
                        </div>
                        <div>
                            <div class="account-title">${escapeHtml(user.login || 'Без логина')}</div>
                            <div class="account-subline">
                                ID ${formatAdminNumber(user.id)} • создан ${escapeHtml(formatAdminDateTime(user.createdAt))} • обновлен ${escapeHtml(formatAdminDateTime(user.updatedAt))} • активность ${escapeHtml(formatAdminDateTime(user.lastActivityAt))}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="inline-actions" style="margin-top: 0; margin-bottom: 16px;">
                    <div class="status-pill ${activeBan ? 'banned' : 'active'}">
                        <span class="material-icons" style="font-size: 16px;">${activeBan ? 'gpp_bad' : 'verified'}</span>
                        <span>${activeBan ? (activeBan.isPermanent ? 'Бессрочный бан' : `Бан до ${escapeHtml(formatAdminDateTime(activeBan.banUntil))}`) : 'Аккаунт активен'}</span>
                    </div>
                    <div class="mini-chip"><span class="material-icons" style="font-size: 14px;">devices</span>${formatAdminNumber(Array.isArray(user.ipHistory) ? user.ipHistory.length : 0)} IP в истории</div>
                    <div class="mini-chip"><span class="material-icons" style="font-size: 14px;">shield</span>${formatAdminNumber(Array.isArray(user.ipBans) ? user.ipBans.filter(item => !item.liftedAt).length : 0)} активных IP-банов</div>
                </div>

                <div class="detail-grid">
                    <section class="detail-section">
                        <div class="detail-section-title">
                            <span class="material-icons">edit_note</span>
                            Основная игра
                        </div>
                        <div class="detail-form-grid">
                            <div class="form-group">
                                <label class="form-label" for="adminUserLogin">Логин</label>
                                <input class="form-control" id="adminUserLogin" type="text" value="${escapeHtml(user.login || '')}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUserScore">Счет</label>
                                <input class="form-control" id="adminUserScore" type="number" min="0" value="${Number(user.score || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUserPlus">Плюс за клик</label>
                                <input class="form-control" id="adminUserPlus" type="number" min="1" value="${Number(user.plus || 1)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUserEnergy">Энергия</label>
                                <input class="form-control" id="adminUserEnergy" type="number" min="0" value="${Number(user.energy || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUserEnergyMax">Макс. энергия</label>
                                <input class="form-control" id="adminUserEnergyMax" type="number" min="1" value="${Number(user.ENERGY_MAX || 1)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUserEnergyTs">last_energy_update</label>
                                <input class="form-control" id="adminUserEnergyTs" type="number" min="0" value="${Number(user.lastEnergyUpdate || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Последняя активность</label>
                                <span class="readonly-value">${escapeHtml(formatAdminDateTime(user.lastActivityAt))}</span>
                            </div>
                            <div class="form-group wide">
                                <label class="form-label" for="adminUserSkin">Skin JSON</label>
                                <textarea class="form-control code-block" id="adminUserSkin" rows="6">${escapeHtml(user.skin || '')}</textarea>
                            </div>
                        </div>
                    </section>

                    <section class="detail-section">
                        <div class="detail-section-title">
                            <span class="material-icons">tune</span>
                            Баланс и пароль
                        </div>
                        <div class="detail-form-grid">
                            <div class="form-group">
                                <label class="form-label" for="adminUpgradeTapSmall">Покупки +1</label>
                                <input class="form-control" id="adminUpgradeTapSmall" type="number" min="0" value="${Number((user.upgradePurchases || {}).tapSmall || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUpgradeTapBig">Покупки +5</label>
                                <input class="form-control" id="adminUpgradeTapBig" type="number" min="0" value="${Number((user.upgradePurchases || {}).tapBig || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUpgradeEnergy">Покупки энергии</label>
                                <input class="form-control" id="adminUpgradeEnergy" type="number" min="0" value="${Number((user.upgradePurchases || {}).energy || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="adminUserNewPassword">Новый пароль</label>
                                <input class="form-control" id="adminUserNewPassword" type="password" minlength="6" placeholder="Оставьте пустым, чтобы не менять">
                            </div>
                            <div class="form-group wide">
                                <label class="form-label" for="adminUserConfirmPassword">Подтверждение нового пароля</label>
                                <input class="form-control" id="adminUserConfirmPassword" type="password" minlength="6" placeholder="Повторите пароль пользователя">
                            </div>
                        </div>
                    </section>

                    <section class="detail-section">
                        <div class="detail-section-title">
                            <span class="material-icons">sports_esports</span>
                            Летающий бобер
                        </div>
                        <div class="detail-form-grid">
                            <div class="form-group">
                                <label class="form-label" for="flyBestScore">Лучший счет</label>
                                <input class="form-control" id="flyBestScore" type="number" min="0" value="${Number(fly.bestScore || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="flyLastScore">Последний счет</label>
                                <input class="form-control" id="flyLastScore" type="number" min="0" value="${Number(fly.lastScore || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="flyLastLevel">Последний уровень</label>
                                <input class="form-control" id="flyLastLevel" type="number" min="1" value="${Number(fly.lastLevel || 1)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="flyGamesPlayed">Забегов</label>
                                <input class="form-control" id="flyGamesPlayed" type="number" min="0" value="${Number(fly.gamesPlayed || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="flyTotalScore">Всего счета</label>
                                <input class="form-control" id="flyTotalScore" type="number" min="0" value="${Number(fly.totalScore || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="flyPendingTransferScore">В очереди на перевод</label>
                                <input class="form-control" id="flyPendingTransferScore" type="number" min="0" value="${Number(fly.pendingTransferScore || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="flyTransferredTotalScore">Уже переведено</label>
                                <input class="form-control" id="flyTransferredTotalScore" type="number" min="0" value="${Number(fly.transferredTotalScore || 0)}">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Последняя игра</label>
                                <span class="readonly-value">${escapeHtml(formatAdminDateTime(fly.lastPlayedAt))}</span>
                            </div>
                        </div>
                    </section>

                    <section class="detail-section">
                        <div class="detail-section-title">
                            <span class="material-icons">shield</span>
                            Бан и ручные действия
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="banReasonInput">Причина ручного бана</label>
                            <textarea class="form-control" id="banReasonInput" rows="4" placeholder="Например: ручная проверка, жалоба, обход античита">${escapeHtml(activeBan && activeBan.reason ? activeBan.reason : 'Ручной бан администрацией')}</textarea>
                        </div>
                        <div class="stack-item">
                            <div class="stack-item-title">
                                <span>${activeBan ? 'Активный бан' : 'Бан отсутствует'}</span>
                                <span class="status-pill ${activeBan ? 'banned' : 'active'}">${activeBan ? 'Бан активен' : 'Нет бана'}</span>
                            </div>
                            <div class="stack-item-meta">
                                ${activeBan
                                    ? `Причина: ${escapeHtml(activeBan.reason || 'не указана')}<br>${activeBan.isPermanent ? 'Срок: бессрочно' : `До: ${escapeHtml(formatAdminDateTime(activeBan.banUntil))}<br>Срок: ${formatAdminNumber(activeBan.durationDays || 0)} дн.`}${activeBan.isRepeat ? ' Повторный бан.' : ''}`
                                    : 'Пользователь сейчас не забанен.'}
                            </div>
                        </div>
                    </section>

                    <section class="detail-section">
                        <div class="detail-section-title">
                            <span class="material-icons">lan</span>
                            История IP
                        </div>
                        ${renderStackItems(user.ipHistory || [], 'Для этого пользователя еще не накопилась история IP.', item => `
                            <div class="stack-item">
                                <div class="stack-item-title">
                                    <span>${escapeHtml(item.ipAddress || 'Неизвестный IP')}</span>
                                    <span class="stack-item-meta">${escapeHtml(formatAdminDateTime(item.lastSeenAt))}</span>
                                </div>
                                <div class="stack-item-meta">
                                    Входов: ${formatAdminNumber(item.loginCount || 0)}<br>
                                    Первый вход: ${escapeHtml(formatAdminDateTime(item.firstSeenAt))}<br>
                                    Последний user-agent: ${escapeHtml(item.userAgent || 'не сохранен')}
                                </div>
                            </div>
                        `)}
                    </section>

                    <section class="detail-section wide">
                        <div class="detail-section-title">
                            <span class="material-icons">shield_moon</span>
                            Связанные IP-баны
                        </div>
                        ${renderStackItems(user.ipBans || [], 'Связанных IP-банов пока нет.', item => `
                            <div class="stack-item">
                                <div class="stack-item-title">
                                    <span>${escapeHtml(item.ipAddress || 'Неизвестный IP')}</span>
                                    <span class="status-pill ${item.liftedAt ? 'active' : 'banned'}">${item.liftedAt ? 'снят' : 'активен'}</span>
                                </div>
                                <div class="stack-item-meta">
                                    Создан: ${escapeHtml(formatAdminDateTime(item.createdAt))}<br>
                                    До: ${escapeHtml(formatAdminDateTime(item.banUntil))}<br>
                                    ${item.liftedAt ? `Снят: ${escapeHtml(formatAdminDateTime(item.liftedAt))}` : 'Сейчас ограничивает вход и регистрацию с этих IP'}
                                </div>
                            </div>
                        `)}
                    </section>
                </div>
            `;

            selectedAccountBaselineSnapshot = getSelectedAccountPayloadSnapshot();
            bindSelectedAccountDirtyTracking();
            setSelectedAccountDirty(false);
            document.getElementById('saveAccountBtn').addEventListener('click', saveSelectedAccountProfile);
            document.getElementById('deleteAccountBtn').addEventListener('click', deleteSelectedAccount);
            document.getElementById('toggleBanAccountBtn').addEventListener('click', function() {
                if (activeBan) {
                    unbanSelectedAccount();
                } else {
                    banSelectedAccount();
                }
            });
        }

        function collectSelectedAccountPayload() {
            return {
                login: document.getElementById('adminUserLogin').value.trim(),
                score: Number(document.getElementById('adminUserScore').value || 0),
                plus: Number(document.getElementById('adminUserPlus').value || 1),
                energy: Number(document.getElementById('adminUserEnergy').value || 0),
                ENERGY_MAX: Number(document.getElementById('adminUserEnergyMax').value || 1),
                lastEnergyUpdate: Number(document.getElementById('adminUserEnergyTs').value || 0),
                skin: document.getElementById('adminUserSkin').value,
                upgradePurchases: {
                    tapSmall: Number(document.getElementById('adminUpgradeTapSmall').value || 0),
                    tapBig: Number(document.getElementById('adminUpgradeTapBig').value || 0),
                    energy: Number(document.getElementById('adminUpgradeEnergy').value || 0)
                },
                newPassword: document.getElementById('adminUserNewPassword').value,
                confirmPassword: document.getElementById('adminUserConfirmPassword').value,
                flyBeaver: {
                    bestScore: Number(document.getElementById('flyBestScore').value || 0),
                    lastScore: Number(document.getElementById('flyLastScore').value || 0),
                    lastLevel: Number(document.getElementById('flyLastLevel').value || 1),
                    gamesPlayed: Number(document.getElementById('flyGamesPlayed').value || 0),
                    totalScore: Number(document.getElementById('flyTotalScore').value || 0),
                    pendingTransferScore: Number(document.getElementById('flyPendingTransferScore').value || 0),
                    transferredTotalScore: Number(document.getElementById('flyTransferredTotalScore').value || 0)
                }
            };
        }

        function saveSelectedAccountProfile() {
            if (!selectedAccountId) {
                return;
            }

            if (!selectedAccountDirty) {
                return;
            }

            const nextPassword = document.getElementById('adminUserNewPassword').value;
            const confirmPassword = document.getElementById('adminUserConfirmPassword').value;
            if (nextPassword !== '' || confirmPassword !== '') {
                if (nextPassword.length < 6) {
                    showNotification('Пароль пользователя должен быть не короче 6 символов', 'error');
                    return;
                }

                if (nextPassword !== confirmPassword) {
                    showNotification('Подтверждение пароля пользователя не совпадает', 'error');
                    return;
                }
            }

            const payload = collectSelectedAccountPayload();
            const saveBtn = document.getElementById('saveAccountBtn');
            const originalContent = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = `<div class="loader" style="width: 18px; height: 18px; border-width: 2px;"></div>`;

            postAction({
                action: 'save_user_profile',
                user_id: String(selectedAccountId),
                data: JSON.stringify(payload)
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось сохранить пользователя');
                }

                selectedAccountBaselineSnapshot = getSelectedAccountPayloadSnapshot();
                setSelectedAccountDirty(false);
                showNotification(data.message || 'Пользователь сохранен', 'success');
                loadDashboardStats();
                loadAccounts(lastAccountSearch);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка сохранения пользователя', 'error');
            })
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = originalContent;
            });
        }

        function parseAdminBanDurationInput(rawValue) {
            const value = String(rawValue || '').trim().toLowerCase();
            if (value === '') {
                return null;
            }

            if (['inf', 'infinite', 'forever', 'navsegda', 'навсегда', 'бессрочно', 'permanent', '∞'].includes(value)) {
                return {
                    isPermanent: true,
                    durationDays: 0
                };
            }

            if (!/^\d+$/.test(value)) {
                return null;
            }

            const durationDays = Number(value);
            if (!Number.isFinite(durationDays) || durationDays < 1) {
                return null;
            }

            return {
                isPermanent: false,
                durationDays: Math.floor(durationDays)
            };
        }

        function banSelectedAccount() {
            if (!selectedAccountId) {
                return;
            }

            const reasonInput = document.getElementById('banReasonInput');
            const reason = reasonInput ? reasonInput.value.trim() : '';
            const rawDurationInput = window.prompt('Введите срок бана в днях. Для бессрочного бана напишите: inf', '5');
            if (rawDurationInput === null) {
                return;
            }

            const banDuration = parseAdminBanDurationInput(rawDurationInput);
            if (!banDuration) {
                showNotification('Укажите число дней или inf для бессрочного бана', 'error');
                return;
            }

            const actionButton = document.getElementById('toggleBanAccountBtn');
            const originalContent = actionButton.innerHTML;
            actionButton.disabled = true;
            actionButton.innerHTML = `<div class="loader" style="width: 18px; height: 18px; border-width: 2px;"></div>`;

            postAction({
                action: 'ban_user_account',
                user_id: String(selectedAccountId),
                reason: reason || 'Ручной бан администрацией',
                duration_days: String(banDuration.durationDays),
                is_permanent: banDuration.isPermanent ? '1' : '0'
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось забанить пользователя');
                }

                showNotification(data.message || 'Пользователь забанен', 'warning');
                loadDashboardStats();
                loadAccounts(lastAccountSearch);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка бана пользователя', 'error');
            })
            .finally(() => {
                actionButton.disabled = false;
                actionButton.innerHTML = originalContent;
            });
        }

        function unbanSelectedAccount() {
            if (!selectedAccountId) {
                return;
            }

            const actionButton = document.getElementById('toggleBanAccountBtn');
            const originalContent = actionButton.innerHTML;
            actionButton.disabled = true;
            actionButton.innerHTML = `<div class="loader" style="width: 18px; height: 18px; border-width: 2px;"></div>`;

            postAction({
                action: 'unban_user_account',
                user_id: String(selectedAccountId)
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось разбанить пользователя');
                }

                showNotification(data.message || 'Бан снят', 'success');
                loadDashboardStats();
                loadAccounts(lastAccountSearch);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка разбана пользователя', 'error');
            })
            .finally(() => {
                actionButton.disabled = false;
                actionButton.innerHTML = originalContent;
            });
        }

        function deleteSelectedAccount() {
            if (!selectedAccountId) {
                return;
            }

            const loginInput = document.getElementById('adminUserLogin');
            const currentLogin = loginInput ? loginInput.value.trim() : '';
            const accountLabel = currentLogin || `ID ${selectedAccountId}`;
            const confirmed = window.confirm(`Удалить аккаунт ${accountLabel}? Это удалит основную игру, fly-beaver, историю IP и баны. Отменить нельзя.`);
            if (!confirmed) {
                return;
            }

            if (currentLogin) {
                const loginConfirmation = window.prompt(`Для подтверждения удаления введите логин: ${currentLogin}`, '');
                if (loginConfirmation === null) {
                    return;
                }

                if (loginConfirmation.trim() !== currentLogin) {
                    showNotification('Логин не совпал. Удаление отменено.', 'error');
                    return;
                }
            }

            const deleteButton = document.getElementById('deleteAccountBtn');
            const originalContent = deleteButton.innerHTML;
            deleteButton.disabled = true;
            deleteButton.innerHTML = `<div class="loader" style="width: 18px; height: 18px; border-width: 2px;"></div>`;

            postAction({
                action: 'delete_user_account',
                user_id: String(selectedAccountId)
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось удалить аккаунт');
                }

                selectedAccountId = null;
                selectedAccountBaselineSnapshot = '';
                selectedAccountDirty = false;
                document.getElementById('accountDetailContainer').style.display = 'none';
                document.getElementById('accountDetailEmpty').style.display = 'flex';
                showNotification(data.message || 'Аккаунт удален', 'success');
                loadDashboardStats();
                loadAccounts(lastAccountSearch);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка удаления аккаунта', 'error');
            })
            .finally(() => {
                deleteButton.disabled = false;
                deleteButton.innerHTML = originalContent;
            });
        }
        
        // Функция загрузки списка таблиц
        function loadTables() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_tables'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderTableList(data.tables);
                    loadDashboardStats();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки списка таблиц', 'error');
            });
        }
        
        // Функция отрисовки списка таблиц
        function renderTableList(tables) {
            const tableList = document.getElementById('tableList');
            tableList.innerHTML = '';
            
            tables.forEach(table => {
                const li = document.createElement('li');
                li.className = 'table-item';
                li.dataset.table = table;
                
                li.innerHTML = `
                    <span class="material-icons table-icon">table_chart</span>
                    <span>${table}</span>
                `;
                
                li.addEventListener('click', function() {
                    // Загружаем данные таблицы
                    loadTableData(table);
                });
                
                tableList.appendChild(li);
            });
        }
        
        // Функция загрузки данных таблицы
        function loadTableData(table) {
            currentTable = table;
            currentAdminView = 'table';
            
            // Показываем карточку с данными таблицы
            document.getElementById('accountsView').style.display = 'none';
            document.getElementById('tableDataCard').style.display = 'block';
            document.getElementById('sqlEditorCard').style.display = 'none';
            document.getElementById('statsGrid').style.display = 'none';
            document.querySelectorAll('.table-item').forEach(item => {
                item.classList.toggle('active', item.dataset.table === table);
            });
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Обновляем заголовок
            document.getElementById('tableTitle').textContent = table;
            
            // Показываем лоадер
            const container = document.getElementById('tableDataContainer');
            container.innerHTML = `
                <div style="text-align: center; padding: 60px 20px;">
                    <div class="loader" style="width: 40px; height: 40px; margin: 0 auto; border-width: 3px;"></div>
                    <p style="margin-top: 20px; color: var(--on-surface); opacity: 0.7;">Загрузка данных таблицы...</p>
                </div>
            `;
            
            // Запрашиваем данные таблицы
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_table_data',
                    table: table
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    tableColumns = data.columns;
                    renderTableData(data.columns, data.rows, table, data.total_rows);
                } else {
                    showNotification(data.message, 'error');
                    container.innerHTML = `
                        <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                            <span class="material-icons" style="font-size: 48px; color: var(--on-surface); opacity: 0.3;">error</span>
                            <p style="margin-top: 16px; color: var(--on-surface); opacity: 0.7;">Ошибка загрузки данных таблицы</p>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки данных таблицы', 'error');
                container.innerHTML = `
                    <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                        <span class="material-icons" style="font-size: 48px; color: var(--on-surface); opacity: 0.3;">wifi_off</span>
                        <p style="margin-top: 16px; color: var(--on-surface); opacity: 0.7;">Ошибка сети при загрузке данных</p>
                    </div>
                `;
            });
        }
        
        // Функция отрисовки данных таблицы с inline-редактированием
        function renderTableData(columns, rows, table) {
            const container = document.getElementById('tableDataContainer');
            
            if (rows.length === 0) {
                container.innerHTML = `
                    <div class="empty-state" style="text-align: center; padding: 40px 20px;">
                        <span class="material-icons" style="font-size: 48px; color: var(--on-surface); opacity: 0.3;">table_rows</span>
                        <p style="margin-top: 16px; color: var(--on-surface); opacity: 0.7;">Таблица "${table}" пуста</p>
                        <button class="btn btn-primary" onclick="showAddRowForm('${table}')" style="margin-top: 16px;">
                            <span class="material-icons">add</span>
                            Добавить первую строку
                        </button>
                    </div>
                `;
                return;
            }
            
            // Создаем таблицу
            let html = '<div class="data-table-container">';
            html += '<table class="data-table">';
            
            // Заголовки таблицы
            html += '<thead><tr>';
            html += '<th style="width: 60px;">Действия</th>';
            columns.forEach(column => {
                html += `<th>${column.name}<br><small style="opacity: 0.7; font-weight: normal;">${column.type}</small></th>`;
            });
            html += '</tr></thead>';
            
            // Тело таблицы
            html += '<tbody>';
            rows.forEach((row, rowIndex) => {
                const rowId = row.id || rowIndex;
                html += `<tr id="row-${rowId}" data-row-id="${rowId}">`;
                
                // Ячейка с действиями
                html += `<td class="actions-cell">`;
                html += `<div class="view-mode">`;
                html += `<button class="btn btn-outline btn-icon btn-small edit-row-btn" data-row-id="${rowId}" title="Редактировать">`;
                html += `<span class="material-icons" style="font-size: 16px;">edit</span>`;
                html += `</button>`;
                html += `<button class="btn btn-danger btn-icon btn-small delete-row-btn" data-row-id="${rowId}" title="Удалить" style="margin-left: 6px;">`;
                html += `<span class="material-icons" style="font-size: 16px;">delete</span>`;
                html += `</button>`;
                html += `</div>`;
                html += `<div class="edit-mode" style="display: none;">`;
                html += `<button class="btn btn-success btn-icon btn-small save-row-btn" data-row-id="${rowId}" title="Сохранить">`;
                html += `<span class="material-icons" style="font-size: 16px;">save</span>`;
                html += `</button>`;
                html += `<button class="btn btn-outline btn-icon btn-small cancel-edit-btn" data-row-id="${rowId}" title="Отменить">`;
                html += `<span class="material-icons" style="font-size: 16px;">close</span>`;
                html += `</button>`;
                html += `</div>`;
                html += `</td>`;
                
                // Ячейки с данными
                columns.forEach(column => {
                    let value = row[column.name];
                    let displayValue = value;
                    
                    if (value === null || value === '') {
                        displayValue = '<span style="color: var(--on-surface); opacity: 0.3; font-style: italic;">NULL</span>';
                    } else if (typeof value === 'string' && value.length > 50) {
                        displayValue = value.substring(0, 50) + '...';
                    }
                    
                    html += `<td data-column="${column.name}" data-original-value="${escapeHtml(value || '')}">`;
                    html += `<div class="view-cell">${displayValue}</div>`;
                    html += `<div class="edit-cell" style="display: none;">`;
                    
                    // Определяем тип поля для редактирования
                    if (column.type.includes('text') || column.type.includes('varchar')) {
                        html += `<textarea class="edit-input" data-column="${column.name}" style="width: 100%; height: 60px;">${escapeHtml(value || '')}</textarea>`;
                    } else if (column.type.includes('int') || column.type.includes('float') || column.type.includes('decimal')) {
                        html += `<input type="number" class="edit-input" data-column="${column.name}" value="${escapeHtml(value || '')}" step="${column.type.includes('int') ? '1' : '0.01'}">`;
                    } else if (column.type.includes('date')) {
                        html += `<input type="date" class="edit-input" data-column="${column.name}" value="${escapeHtml(value || '')}">`;
                    } else if (column.type.includes('datetime') || column.type.includes('timestamp')) {
                        let dateValue = '';
                        if (value) {
                            const date = new Date(value);
                            if (!isNaN(date)) {
                                dateValue = date.toISOString().slice(0, 16);
                            }
                        }
                        html += `<input type="datetime-local" class="edit-input" data-column="${column.name}" value="${dateValue}">`;
                    } else if (column.type.includes('tinyint(1)')) {
                        html += `<select class="edit-input" data-column="${column.name}">`;
                        html += `<option value="1" ${value == 1 ? 'selected' : ''}>Да</option>`;
                        html += `<option value="0" ${value == 0 ? 'selected' : ''}>Нет</option>`;
                        html += `</select>`;
                    } else {
                        html += `<input type="text" class="edit-input" data-column="${column.name}" value="${escapeHtml(value || '')}">`;
                    }
                    
                    html += `</div>`;
                    html += `</td>`;
                });
                
                html += '</tr>';
            });
            html += '</tbody></table></div>';
            
            container.innerHTML = html;
            
            // Добавляем обработчики событий для кнопок редактирования
            document.querySelectorAll('.edit-row-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const rowId = this.dataset.rowId;
                    enableRowEditing(rowId);
                });
            });

            document.querySelectorAll('.delete-row-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const rowId = this.dataset.rowId;
                    deleteRow(rowId, table);
                });
            });
            
            // Добавляем обработчики событий для кнопок сохранения
            document.querySelectorAll('.save-row-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const rowId = this.dataset.rowId;
                    saveRowEditing(rowId, table);
                });
            });
            
            // Добавляем обработчики событий для кнопок отмены
            document.querySelectorAll('.cancel-edit-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const rowId = this.dataset.rowId;
                    cancelRowEditing(rowId);
                });
            });
        }
        
        // Функция включения редактирования строки
        function enableRowEditing(rowId) {
            const row = document.getElementById(`row-${rowId}`);
            if (!row) return;
            
            // Отключаем редактирование других строк
            if (editingRowId && editingRowId !== rowId) {
                cancelRowEditing(editingRowId);
            }
            
            // Активируем редактирование текущей строки
            editingRowId = rowId;
            row.classList.add('editing');
            
            // Показываем режим редактирования
            row.querySelector('.view-mode').style.display = 'none';
            row.querySelector('.edit-mode').style.display = 'flex';
            
            // Показываем поля редактирования
            row.querySelectorAll('.view-cell').forEach(cell => {
                cell.style.display = 'none';
            });
            
            row.querySelectorAll('.edit-cell').forEach(cell => {
                cell.style.display = 'block';
            });
            
            // Фокус на первое поле
            const firstInput = row.querySelector('.edit-input');
            if (firstInput) {
                firstInput.focus();
            }
        }
        
        // Функция сохранения редактирования строки
        function saveRowEditing(rowId, table) {
            const row = document.getElementById(`row-${rowId}`);
            if (!row) return;
            
            // Собираем данные для обновления
            const updateData = {};
            const primaryKey = 'id'; // Предполагаем, что первичный ключ называется 'id'
            let primaryValue = null;
            
            row.querySelectorAll('.edit-input').forEach(input => {
                const column = input.dataset.column;
                let value = input.value;
                
                // Обработка специальных типов
                if (input.type === 'number') {
                    value = parseFloat(value) || 0;
                } else if (input.type === 'select-one' && input.classList.contains('edit-input')) {
                    value = parseInt(value) || 0;
                }
                
                if (column === primaryKey) {
                    primaryValue = value;
                } else {
                    updateData[column] = value;
                }
            });
            
            if (!primaryValue) {
                // Ищем первичный ключ в другом месте
                const primaryCell = row.querySelector(`td[data-column="${primaryKey}"]`);
                if (primaryCell) {
                    primaryValue = primaryCell.dataset.originalValue;
                }
            }
            
            if (!primaryValue) {
                showNotification('Не удалось определить первичный ключ строки', 'error');
                return;
            }
            
            // Показываем лоадер
            const saveBtn = row.querySelector('.save-row-btn');
            const originalContent = saveBtn.innerHTML;
            saveBtn.innerHTML = '<div class="loader" style="width: 16px; height: 16px; border-width: 2px;"></div>';
            saveBtn.disabled = true;
            
            // Отправляем запрос на обновление
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'update_row',
                    table: table,
                    data: JSON.stringify(updateData),
                    primary_key: primaryKey,
                    primary_value: primaryValue
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Строка успешно обновлена', 'success');
                    loadDashboardStats();
                    
                    // Обновляем отображаемые значения
                    row.querySelectorAll('.edit-input').forEach(input => {
                        const column = input.dataset.column;
                        const cell = row.querySelector(`td[data-column="${column}"]`);
                        
                        if (cell) {
                            // Обновляем оригинальное значение
                            cell.dataset.originalValue = input.value;
                            
                            // Обновляем отображаемое значение
                            let displayValue = input.value;
                            if (input.value === null || input.value === '') {
                                displayValue = '<span style="color: var(--on-surface); opacity: 0.3; font-style: italic;">NULL</span>';
                            } else if (typeof input.value === 'string' && input.value.length > 50) {
                                displayValue = input.value.substring(0, 50) + '...';
                            }
                            
                            const viewCell = cell.querySelector('.view-cell');
                            if (viewCell) {
                                viewCell.innerHTML = displayValue;
                            }
                        }
                    });
                    
                    // Возвращаемся в режим просмотра
                    cancelRowEditing(rowId);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при обновлении строки', 'error');
            })
            .finally(() => {
                // Восстанавливаем кнопку
                saveBtn.innerHTML = originalContent;
                saveBtn.disabled = false;
            });
        }
        
        // Функция отмены редактирования строки
        function cancelRowEditing(rowId) {
            const row = document.getElementById(`row-${rowId}`);
            if (!row) return;
            
            // Сбрасываем ID редактируемой строки
            if (editingRowId === rowId) {
                editingRowId = null;
            }
            
            row.classList.remove('editing');
            
            // Возвращаемся к режиму просмотра
            row.querySelector('.view-mode').style.display = 'flex';
            row.querySelector('.edit-mode').style.display = 'none';
            
            // Восстанавливаем исходные значения
            row.querySelectorAll('.edit-cell').forEach(cell => {
                cell.style.display = 'none';
            });
            
            row.querySelectorAll('.view-cell').forEach(cell => {
                cell.style.display = 'block';
            });
        }

        function deleteRow(rowId, table) {
            const row = document.getElementById(`row-${rowId}`);
            if (!row) {
                return;
            }

            const primaryKey = 'id';
            const primaryCell = row.querySelector(`td[data-column="${primaryKey}"]`);
            const primaryValue = primaryCell ? primaryCell.dataset.originalValue : rowId;

            if (!primaryValue) {
                showNotification('Не удалось определить первичный ключ строки', 'error');
                return;
            }

            const shouldDelete = confirm(`Удалить строку #${primaryValue} из таблицы "${table}"?`);
            if (!shouldDelete) {
                return;
            }

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'delete_row',
                    table: table,
                    primary_key: primaryKey,
                    primary_value: primaryValue
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification('Строка удалена', 'success');
                    loadTableData(table);
                    loadDashboardStats();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при удалении строки', 'error');
            });
        }
        
        // Функция отображения формы добавления строки
        function showAddRowForm(table) {
            document.getElementById('addModalTitle').textContent = `Добавление строки в таблицу "${table}"`;
            
            // Запрашиваем структуру таблицы для создания формы
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_table_data',
                    table: table
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    renderAddForm(data.columns, table);
                    showAddRowModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки структуры таблицы', 'error');
            });
        }
        
        // Функция отрисовки формы добавления
        function renderAddForm(columns, table) {
            const form = document.getElementById('addRowForm');
            form.innerHTML = '';
            
            columns.forEach(column => {
                // Пропускаем автоинкрементные поля
                if (column.extra.includes('auto_increment')) {
                    return;
                }
                
                const div = document.createElement('div');
                div.className = 'form-group';
                
                const label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = `${column.name} (${column.type})`;
                label.htmlFor = `add_field_${column.name}`;
                
                let input;
                
                // Определяем тип поля
                if (column.type.includes('text') || column.type.includes('varchar')) {
                    input = document.createElement('textarea');
                    input.className = 'form-control';
                    input.id = `add_field_${column.name}`;
                    input.name = column.name;
                    input.rows = 3;
                } else if (column.type.includes('int') || column.type.includes('float') || column.type.includes('decimal')) {
                    input = document.createElement('input');
                    input.type = 'number';
                    input.className = 'form-control';
                    input.id = `add_field_${column.name}`;
                    input.name = column.name;
                    input.step = column.type.includes('int') ? '1' : '0.01';
                } else if (column.type.includes('date')) {
                    input = document.createElement('input');
                    input.type = 'date';
                    input.className = 'form-control';
                    input.id = `add_field_${column.name}`;
                    input.name = column.name;
                } else if (column.type.includes('datetime') || column.type.includes('timestamp')) {
                    input = document.createElement('input');
                    input.type = 'datetime-local';
                    input.className = 'form-control';
                    input.id = `add_field_${column.name}`;
                    input.name = column.name;
                } else if (column.type.includes('tinyint(1)')) {
                    input = document.createElement('select');
                    input.className = 'form-control';
                    input.id = `add_field_${column.name}`;
                    input.name = column.name;
                    
                    const optionTrue = document.createElement('option');
                    optionTrue.value = '1';
                    optionTrue.textContent = 'Да';
                    
                    const optionFalse = document.createElement('option');
                    optionFalse.value = '0';
                    optionFalse.textContent = 'Нет';
                    optionFalse.selected = true;
                    
                    input.appendChild(optionTrue);
                    input.appendChild(optionFalse);
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control';
                    input.id = `add_field_${column.name}`;
                    input.name = column.name;
                }
                
                // Если поле обязательное
                if (column.nullable === false && !column.default && column.extra !== 'auto_increment') {
                    input.required = true;
                    label.innerHTML += ' <span style="color: var(--error);">*</span>';
                }
                
                // Добавляем значение по умолчанию
                if (column.default) {
                    input.value = column.default === 'CURRENT_TIMESTAMP' ? '' : column.default;
                }
                
                div.appendChild(label);
                div.appendChild(input);
                form.appendChild(div);
            });
            
            // Обработчик сохранения
            document.getElementById('saveNewRowBtn').onclick = function() {
                saveNewRow(table, columns);
            };
        }
        
        // Функция сохранения новой строки
        function saveNewRow(table, columns) {
            const form = document.getElementById('addRowForm');
            const formData = {};
            
            // Собираем данные формы
            columns.forEach(column => {
                if (column.extra.includes('auto_increment')) {
                    return;
                }
                
                const input = form.querySelector(`#add_field_${column.name}`);
                if (input) {
                    let value = input.value;
                    
                    // Обработка специальных типов
                    if (input.type === 'number') {
                        value = parseFloat(value) || 0;
                    } else if (input.type === 'select-one') {
                        value = parseInt(value) || 0;
                    }
                    
                    // Если поле пустое и nullable, устанавливаем NULL
                    if (value === '' && column.nullable) {
                        formData[column.name] = null;
                    } else {
                        formData[column.name] = value;
                    }
                }
            });
            
            const saveBtn = document.getElementById('saveNewRowBtn');
            const btnText = saveBtn.querySelector('.btn-text');
            const loader = saveBtn.querySelector('.loader');
            
            // Показываем лоадер
            btnText.style.display = 'none';
            loader.style.display = 'inline-block';
            saveBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'insert_row',
                    table: table,
                    data: JSON.stringify(formData)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    hideAddRowModal();
                    
                    if (currentTable === table) {
                        loadTableData(table);
                    }
                    loadDashboardStats();
                    
                    showNotification('Строка успешно добавлена', 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при добавлении строки', 'error');
            })
            .finally(() => {
                btnText.style.display = 'inline';
                loader.style.display = 'none';
                saveBtn.disabled = false;
            });
        }
        
        // Функция выполнения SQL запроса
        function executeSql(sql, silent = false) {
            return new Promise((resolve) => {
                if (!silent) {
                    // Показываем лоадер в результатах
                    const resultsDiv = document.getElementById('sqlResults');
                    resultsDiv.innerHTML = `
                        <div style="text-align: center; padding: 40px 20px;">
                            <div class="loader" style="width: 40px; height: 40px; margin: 0 auto; border-width: 3px;"></div>
                            <p style="margin-top: 16px; color: var(--on-surface); opacity: 0.7;">Выполнение SQL запроса...</p>
                        </div>
                    `;
                }
                
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'execute_sql',
                        sql: sql
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        if (!silent) {
                            renderSqlResults(data.results, data.affected_rows, sql);
                            showNotification('Запрос выполнен успешно', 'success');
                        }
                        loadDashboardStats();
                        resolve(true);
                    } else {
                        if (!silent) {
                            showNotification(data.message, 'error');
                            const resultsDiv = document.getElementById('sqlResults');
                            resultsDiv.innerHTML = `
                                <div class="notification error" style="margin-top: 20px; position: relative; top: 0; right: 0; transform: none;">
                                    <span class="material-icons notification-icon">error</span>
                                    <span>${data.message}</span>
                                </div>
                            `;
                        }
                        resolve(false);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (!silent) {
                        showNotification('Ошибка сети при выполнении запроса', 'error');
                    }
                    resolve(false);
                });
            });
        }
        
        // Функция отрисовки результатов SQL запроса
        function renderSqlResults(results, affectedRows, sql) {
            const resultsDiv = document.getElementById('sqlResults');
            resultsDiv.innerHTML = '';
            
            // Показываем выполненный запрос
            const queryCard = document.createElement('div');
            queryCard.className = 'card';
            queryCard.style.marginBottom = '16px';
            queryCard.innerHTML = `
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                    <span class="material-icons" style="color: var(--primary-color);">code</span>
                    <h3 style="font-size: 16px; font-weight: 600;">Выполненный запрос</h3>
                </div>
                <div style="background-color: var(--hover); padding: 12px; border-radius: 6px; font-family: 'JetBrains Mono', monospace; font-size: 13px; overflow-x: auto;">
                    ${escapeHtml(sql)}
                </div>
                <div style="margin-top: 12px; font-size: 14px; color: var(--on-surface); opacity: 0.7;">
                    Затронуто строк: <strong>${affectedRows}</strong>
                </div>
            `;
            resultsDiv.appendChild(queryCard);
            
            if (results.length === 0 && affectedRows > 0) {
                return;
            }
            
            // Отображаем результаты каждого запроса
            results.forEach((result, index) => {
                if (result.columns && result.rows) {
                    const tableCard = document.createElement('div');
                    tableCard.className = 'card';
                    tableCard.style.marginTop = index > 0 ? '16px' : '0';
                    
                    tableCard.innerHTML = `
                        <div class="card-header" style="padding: 16px; margin-bottom: 16px;">
                            <h3 class="card-title" style="font-size: 16px;">
                                <span class="material-icons">table_chart</span>
                                Результат ${index + 1}
                            </h3>
                            <span style="font-size: 14px; color: var(--on-surface); opacity: 0.7;">Найдено строк: ${result.row_count}</span>
                        </div>
                        <div class="data-table-container">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        ${result.columns.map(col => `<th>${col}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody>
                                    ${result.rows.map(row => `
                                        <tr>
                                            ${result.columns.map(col => `
                                                <td>
                                                    ${row[col] !== null && row[col] !== '' ? 
                                                        (typeof row[col] === 'string' && row[col].length > 50 ? 
                                                            row[col].substring(0, 50) + '...' : escapeHtml(row[col])) : 
                                                        '<span style="color: var(--on-surface); opacity: 0.3; font-style: italic;">NULL</span>'}
                                                </td>
                                            `).join('')}
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;
                    
                    resultsDiv.appendChild(tableCard);
                }
            });
        }
        
        // Функция инициализации переключателя темы
        function initThemeToggle() {
            const themeToggle = document.getElementById('themeToggle');
            
            if (themeToggle) {
                themeToggle.addEventListener('change', function() {
                    const isDarkTheme = this.checked;
                    document.body.classList.toggle('dark-theme', isDarkTheme);
                    
                    // Сохраняем выбор темы в cookie
                    document.cookie = `dark_theme=${isDarkTheme}; path=/; max-age=31536000`;
                    
                    showNotification(isDarkTheme ? 'Темная тема включена' : 'Светлая тема включена', 'info');
                });
            }
        }
        
        // Функция показа уведомления
        function showNotification(message, type = 'info', duration = 3000) {
            const notificationContainer = document.getElementById('notificationContainer');
            const notificationId = 'notification-' + Date.now();
            
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.id = notificationId;
            
            const icons = {
                'success': 'check_circle',
                'error': 'error',
                'info': 'info',
                'warning': 'warning'
            };
            
            notification.innerHTML = `
                <span class="material-icons notification-icon">${icons[type] || 'info'}</span>
                <span style="flex: 1;">${message}</span>
                <button class="notification-close" onclick="removeNotification('${notificationId}')">
                    <span class="material-icons">close</span>
                </button>
            `;
            
            notificationContainer.appendChild(notification);
            
            // Анимация появления
            setTimeout(() => {
                notification.classList.add('active');
            }, 10);
            
            // Автоматическое удаление
            if (duration > 0) {
                setTimeout(() => {
                    removeNotification(notificationId);
                }, duration);
            }
            
            return notificationId;
        }
        
        // Функция удаления уведомления
        function removeNotification(notificationId) {
            const notification = document.getElementById(notificationId);
            if (notification) {
                notification.classList.remove('active');
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }
        }
        
        // Функция экранирования SQL строк
        function escapeSql(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/'/g, "''").replace(/\\/g, '\\\\');
        }
        
        // Функция экранирования HTML
        function escapeHtml(text) {
            if (text === null || text === undefined) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
