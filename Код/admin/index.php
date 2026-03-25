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

function skinNameLength($value)
{
    $value = (string) $value;

    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function normalizeSkinDisplayName($value)
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    $length = skinNameLength($value);
    if ($length < 2 || $length > 60) {
        throw new InvalidArgumentException('Название скина должно быть длиной от 2 до 60 символов.');
    }

    return $value;
}

function transliterateSkinSlug($value)
{
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
    ];

    $value = trim((string) $value);
    if ($value === '') {
        return 'skin';
    }

    $lower = function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);

    $lower = strtr($lower, $map);
    $lower = preg_replace('/[^a-z0-9]+/u', '-', $lower) ?? $lower;
    $lower = trim($lower, '-');

    return $lower !== '' ? $lower : 'skin';
}

function buildUniqueSkinCatalogId($name, array $catalogItems)
{
    $baseId = transliterateSkinSlug($name);
    $existingIds = [];

    foreach ($catalogItems as $item) {
        $existingId = trim((string) ($item['id'] ?? ''));
        if ($existingId !== '') {
            $existingIds[$existingId] = true;
        }
    }

    $candidate = $baseId;
    $suffix = 2;

    while (isset($existingIds[$candidate])) {
        $candidate = $baseId . '-' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function skinUploadDirectoryPath()
{
    return dirname(__DIR__) . '/skins';
}

function detectSkinImageExtension($tmpPath, $originalName)
{
    $allowedByMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    $mimeType = null;
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if (is_string($detected) && $detected !== '') {
                $mimeType = $detected;
            }
        }
    }

    if ($mimeType !== null && isset($allowedByMime[$mimeType])) {
        return $allowedByMime[$mimeType];
    }

    $originalExtension = strtolower(pathinfo((string) $originalName, PATHINFO_EXTENSION));
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (in_array($originalExtension, $allowedExtensions, true)) {
        return $originalExtension === 'jpeg' ? 'jpg' : $originalExtension;
    }

    throw new InvalidArgumentException('Поддерживаются только изображения JPG, PNG, WEBP и GIF.');
}

function storeUploadedSkinImage($skinId, array $uploadedFile)
{
    $errorCode = (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($errorCode === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Выберите изображение скина.');
    }

    if ($errorCode !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Не удалось загрузить изображение скина.');
    }

    $tmpName = (string) ($uploadedFile['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new RuntimeException('Временный файл изображения не найден.');
    }

    $fileSize = max(0, (int) ($uploadedFile['size'] ?? 0));
    if ($fileSize < 1) {
        throw new InvalidArgumentException('Изображение скина оказалось пустым.');
    }

    if ($fileSize > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Изображение скина должно быть не больше 5 МБ.');
    }

    $extension = detectSkinImageExtension($tmpName, (string) ($uploadedFile['name'] ?? ''));
    $skinsDir = skinUploadDirectoryPath();

    if (!is_dir($skinsDir) && !mkdir($skinsDir, 0775, true) && !is_dir($skinsDir)) {
        throw new RuntimeException('Не удалось подготовить папку для скинов.');
    }

    $fileName = $skinId . '-' . gmdate('YmdHis') . '.' . $extension;
    $targetPath = $skinsDir . '/' . $fileName;

    if (!move_uploaded_file($tmpName, $targetPath)) {
        throw new RuntimeException('Не удалось сохранить изображение скина.');
    }

    return [
        'relative_path' => 'skins/' . $fileName,
        'absolute_path' => $targetPath,
    ];
}

function postBooleanFlag($value)
{
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function findSkinCatalogItemIndex(array $catalogItems, $skinId)
{
    $skinId = trim((string) $skinId);
    if ($skinId === '') {
        return -1;
    }

    foreach ($catalogItems as $index => $item) {
        if (trim((string) ($item['id'] ?? '')) === $skinId) {
            return (int) $index;
        }
    }

    return -1;
}

function ensureSkinCatalogDefaults(array $catalogItems)
{
    $normalizedItems = [];
    foreach ($catalogItems as $item) {
        $normalized = bober_normalize_skin_catalog_item($item);
        if ($normalized !== null) {
            $normalizedItems[] = $normalized;
        }
    }

    if (count($normalizedItems) === 0) {
        throw new RuntimeException('Каталог скинов не может быть пустым.');
    }

    $hasDefaultOwned = false;
    foreach ($normalizedItems as $item) {
        if (!empty($item['default_owned'])) {
            $hasDefaultOwned = true;
            break;
        }
    }

    if (!$hasDefaultOwned) {
        $normalizedItems[0]['default_owned'] = true;
    }

    return $normalizedItems;
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

        if ($action === 'get_skin_catalog') {
            if (requireAdminAuth($response)) {
                $catalogItems = ensureSkinCatalogDefaults(bober_skin_catalog_list());

                $response['success'] = true;
                $response['skins'] = array_values($catalogItems);
                $response['total'] = count($catalogItems);
            }
        }

        if ($action === 'create_skin_catalog_item') {
            if (requireAdminAuth($response)) {
                $skinName = normalizeSkinDisplayName($_POST['skin_name'] ?? '');
                $skinPrice = max(0, (int) ($_POST['skin_price'] ?? 0));
                $defaultOwned = postBooleanFlag($_POST['default_owned'] ?? '0');
                $available = !array_key_exists('available', $_POST) || postBooleanFlag($_POST['available']);
                $uploadedFile = isset($_FILES['skin_image']) && is_array($_FILES['skin_image'])
                    ? $_FILES['skin_image']
                    : null;

                if ($uploadedFile === null) {
                    $response['message'] = 'Выберите изображение скина.';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $catalogItems = bober_skin_catalog_list();
                    $skinId = buildUniqueSkinCatalogId($skinName, $catalogItems);
                    $storedImage = storeUploadedSkinImage($skinId, $uploadedFile);

                    try {
                        $newSkinItem = [
                            'id' => $skinId,
                            'name' => $skinName,
                            'price' => $skinPrice,
                            'image' => $storedImage['relative_path'],
                            'default_owned' => $defaultOwned,
                            'available' => $available,
                        ];

                        $catalogItems[] = $newSkinItem;
                        $catalogItems = ensureSkinCatalogDefaults($catalogItems);
                        bober_store_skin_catalog($catalogItems);

                        $storedIndex = findSkinCatalogItemIndex($catalogItems, $skinId);
                        $storedItem = $storedIndex >= 0 ? $catalogItems[$storedIndex] : $newSkinItem;

                        $response['success'] = true;
                        $response['message'] = 'Скин добавлен и сразу доступен в магазине.';
                        $response['skin'] = $storedItem;
                        $response['catalog_count'] = count($catalogItems);

                        bober_admin_log_action($conn, 'create_skin_catalog_item', [
                            'target_table' => 'skin_catalog',
                            'query_text' => 'CREATE SKIN ' . $skinId,
                            'affected_rows' => 1,
                            'meta' => [
                                'skin_id' => $skinId,
                                'skin_name' => $skinName,
                                'price' => $skinPrice,
                                'image' => $storedImage['relative_path'],
                                'default_owned' => !empty($storedItem['default_owned']),
                                'available' => !empty($storedItem['available']),
                            ],
                        ]);
                    } catch (Throwable $error) {
                        $absolutePath = (string) ($storedImage['absolute_path'] ?? '');
                        if ($absolutePath !== '' && is_file($absolutePath)) {
                            @unlink($absolutePath);
                        }
                        throw $error;
                    } finally {
                        $conn->close();
                    }
                }
            }
        }

        if ($action === 'update_skin_catalog_item') {
            if (requireAdminAuth($response)) {
                $skinId = trim((string) ($_POST['skin_id'] ?? ''));
                $skinName = normalizeSkinDisplayName($_POST['skin_name'] ?? '');
                $skinPrice = max(0, (int) ($_POST['skin_price'] ?? 0));
                $defaultOwned = postBooleanFlag($_POST['default_owned'] ?? '0');
                $available = !array_key_exists('available', $_POST) || postBooleanFlag($_POST['available']);
                $uploadedFile = isset($_FILES['skin_image']) && is_array($_FILES['skin_image']) ? $_FILES['skin_image'] : null;

                if ($skinId === '') {
                    $response['message'] = 'Не указан идентификатор скина.';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $catalogItems = ensureSkinCatalogDefaults(bober_skin_catalog_list());
                    $skinIndex = findSkinCatalogItemIndex($catalogItems, $skinId);

                    if ($skinIndex < 0) {
                        $response['message'] = 'Скин не найден в каталоге.';
                        $conn->close();
                    } else {
                        $currentItem = $catalogItems[$skinIndex];
                        $storedImage = null;

                        try {
                            $nextImage = (string) ($currentItem['image'] ?? '');
                            if ($uploadedFile && (int) ($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                                $storedImage = storeUploadedSkinImage($skinId, $uploadedFile);
                                $nextImage = $storedImage['relative_path'];
                            }

                            $catalogItems[$skinIndex] = [
                                'id' => $skinId,
                                'name' => $skinName,
                                'price' => $skinPrice,
                                'image' => $nextImage,
                                'default_owned' => $defaultOwned,
                                'available' => $available,
                            ];

                            $catalogItems = ensureSkinCatalogDefaults($catalogItems);
                            bober_store_skin_catalog($catalogItems);

                            $updatedIndex = findSkinCatalogItemIndex($catalogItems, $skinId);
                            $updatedItem = $updatedIndex >= 0 ? $catalogItems[$updatedIndex] : $catalogItems[$skinIndex];

                            $response['success'] = true;
                            $response['message'] = 'Скин обновлен.';
                            $response['skin'] = $updatedItem;

                            bober_admin_log_action($conn, 'update_skin_catalog_item', [
                                'target_table' => 'skin_catalog',
                                'query_text' => 'UPDATE SKIN ' . $skinId,
                                'affected_rows' => 1,
                                'meta' => [
                                    'skin_id' => $skinId,
                                    'previous_name' => $currentItem['name'] ?? '',
                                    'name' => $updatedItem['name'] ?? $skinName,
                                    'previous_image' => $currentItem['image'] ?? '',
                                    'image' => $updatedItem['image'] ?? $nextImage,
                                    'price' => $skinPrice,
                                    'default_owned' => !empty($updatedItem['default_owned']),
                                    'available' => !empty($updatedItem['available']),
                                ],
                            ]);
                        } catch (Throwable $error) {
                            $absolutePath = (string) ($storedImage['absolute_path'] ?? '');
                            if ($absolutePath !== '' && is_file($absolutePath)) {
                                @unlink($absolutePath);
                            }
                            throw $error;
                        } finally {
                            $conn->close();
                        }
                    }
                }
            }
        }

        if ($action === 'delete_skin_catalog_item') {
            if (requireAdminAuth($response)) {
                $skinId = trim((string) ($_POST['skin_id'] ?? ''));

                if ($skinId === '') {
                    $response['message'] = 'Не указан идентификатор скина.';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $catalogItems = ensureSkinCatalogDefaults(bober_skin_catalog_list());
                    $skinIndex = findSkinCatalogItemIndex($catalogItems, $skinId);

                    if ($skinIndex < 0) {
                        $response['message'] = 'Скин не найден в каталоге.';
                    } elseif (count($catalogItems) <= 1) {
                        $response['message'] = 'Нельзя удалить последний скин в каталоге.';
                    } else {
                        $deletedItem = $catalogItems[$skinIndex];
                        array_splice($catalogItems, $skinIndex, 1);
                        $catalogItems = ensureSkinCatalogDefaults($catalogItems);
                        bober_store_skin_catalog($catalogItems);

                        $response['success'] = true;
                        $response['message'] = 'Скин удален из каталога.';
                        $response['deleted_skin_id'] = $skinId;
                        $response['catalog_count'] = count($catalogItems);

                        bober_admin_log_action($conn, 'delete_skin_catalog_item', [
                            'target_table' => 'skin_catalog',
                            'query_text' => 'DELETE SKIN ' . $skinId,
                            'affected_rows' => 1,
                            'meta' => [
                                'skin_id' => $skinId,
                                'skin_name' => $deletedItem['name'] ?? '',
                                'image' => $deletedItem['image'] ?? '',
                            ],
                        ]);
                    }

                    $conn->close();
                }
            }
        }

        if ($action === 'get_users_overview') {
            if (requireAdminAuth($response)) {
                $search = trim((string) ($_POST['search'] ?? ''));
                $sort = trim((string) ($_POST['sort'] ?? 'activity_desc'));
                $searchLike = '%' . $search . '%';
                $sortMap = [
                    'activity_desc' => '`is_banned` DESC, `last_activity_at` DESC, `u`.`score` DESC, `u`.`id` DESC',
                    'score_desc' => '`is_banned` DESC, `u`.`score` DESC, `last_activity_at` DESC, `u`.`id` DESC',
                    'score_asc' => '`is_banned` DESC, `u`.`score` ASC, `last_activity_at` DESC, `u`.`id` DESC',
                    'created_desc' => '`is_banned` DESC, `u`.`created_at` DESC, `u`.`id` DESC',
                    'created_asc' => '`is_banned` DESC, `u`.`created_at` ASC, `u`.`id` ASC',
                    'login_asc' => '`is_banned` DESC, `u`.`login` ASC, `u`.`id` ASC',
                ];
                $orderBySql = $sortMap[$sort] ?? $sortMap['activity_desc'];
                $sort = isset($sortMap[$sort]) ? $sort : 'activity_desc';

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
ORDER BY {$orderBySql}
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
                        'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
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
                $response['sort'] = $sort;

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
                        $ipBanStmt = $conn->prepare('SELECT `id`, `ip_address`, `reason`, `ban_until`, `lifted_at`, `created_at` FROM `ip_bans` WHERE `source_user_id` = ? ORDER BY `created_at` DESC LIMIT 20');
                        if ($ipBanStmt) {
                            $ipBanStmt->bind_param('i', $userId);
                            if ($ipBanStmt->execute()) {
                                $ipBanResult = $ipBanStmt->get_result();
                                if ($ipBanResult instanceof mysqli_result) {
                                    while ($ipBanRow = $ipBanResult->fetch_assoc()) {
                                        $ipBans[] = [
                                            'id' => (int) ($ipBanRow['id'] ?? 0),
                                            'ipAddress' => (string) ($ipBanRow['ip_address'] ?? ''),
                                            'reason' => (string) ($ipBanRow['reason'] ?? ''),
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

        if ($action === 'lift_single_ip_ban') {
            if (requireAdminAuth($response)) {
                $ipBanId = max(0, (int) ($_POST['ip_ban_id'] ?? 0));
                $userId = max(0, (int) ($_POST['user_id'] ?? 0));

                if ($ipBanId < 1) {
                    $response['message'] = 'Некорректный идентификатор IP-бана';
                } else {
                    $conn = connectDB();
                    bober_ensure_project_schema($conn);

                    $ipBanStmt = $conn->prepare('SELECT `id`, `source_user_id`, `ip_address`, `reason`, `lifted_at` FROM `ip_bans` WHERE `id` = ? LIMIT 1');
                    if (!$ipBanStmt) {
                        throw new RuntimeException('Не удалось подготовить получение IP-бана.');
                    }

                    $ipBanStmt->bind_param('i', $ipBanId);
                    if (!$ipBanStmt->execute()) {
                        $ipBanStmt->close();
                        throw new RuntimeException('Не удалось получить IP-бан.');
                    }

                    $ipBanResult = $ipBanStmt->get_result();
                    $ipBanRow = $ipBanResult ? $ipBanResult->fetch_assoc() : null;
                    if ($ipBanResult instanceof mysqli_result) {
                        $ipBanResult->free();
                    }
                    $ipBanStmt->close();

                    if (!$ipBanRow) {
                        $response['message'] = 'IP-бан не найден';
                    } elseif ($userId > 0 && (int) ($ipBanRow['source_user_id'] ?? 0) !== $userId) {
                        $response['message'] = 'Этот IP-бан не относится к выбранному пользователю';
                    } elseif (!empty($ipBanRow['lifted_at'])) {
                        $response['success'] = true;
                        $response['message'] = 'Этот IP-бан уже снят';
                    } else {
                        $liftStmt = $conn->prepare('UPDATE `ip_bans` SET `lifted_at` = CURRENT_TIMESTAMP WHERE `id` = ? LIMIT 1');
                        if (!$liftStmt) {
                            throw new RuntimeException('Не удалось подготовить снятие IP-бана.');
                        }

                        $liftStmt->bind_param('i', $ipBanId);
                        if (!$liftStmt->execute()) {
                            $liftStmt->close();
                            throw new RuntimeException('Не удалось снять IP-бан.');
                        }

                        $affectedRows = max(0, (int) $liftStmt->affected_rows);
                        $liftStmt->close();

                        $response['success'] = true;
                        $response['message'] = $affectedRows > 0
                            ? 'IP-бан снят точечно'
                            : 'IP-бан уже был снят';

                        bober_admin_log_action($conn, 'lift_single_ip_ban', [
                            'target_table' => 'ip_bans',
                            'query_text' => 'LIFT IP BAN #' . $ipBanId,
                            'affected_rows' => $affectedRows,
                            'meta' => [
                                'ip_ban_id' => $ipBanId,
                                'user_id' => (int) ($ipBanRow['source_user_id'] ?? 0),
                                'ip_address' => (string) ($ipBanRow['ip_address'] ?? ''),
                                'reason' => (string) ($ipBanRow['reason'] ?? ''),
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
            width: min(94vw, 640px);
            max-width: 640px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: var(--shadow-heavy);
            transform: translateY(20px) scale(0.97);
            transition: transform 0.32s cubic-bezier(0.22, 1, 0.36, 1);
            border: 1px solid var(--border);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0) scale(1);
        }

        .modal-content.modal-content-wide {
            width: min(94vw, 760px);
            max-width: 760px;
        }

        .modal-content.modal-content-danger {
            width: min(94vw, 700px);
            max-width: 700px;
        }

        .modal-content.modal-content-skin {
            width: min(95vw, 900px);
            max-width: 900px;
        }

        .modal-hero {
            display: flex;
            gap: 16px;
            padding: 18px;
            border-radius: 18px;
            background:
                radial-gradient(circle at top right, rgba(111, 241, 227, 0.18), transparent 42%),
                linear-gradient(135deg, rgba(39, 26, 72, 0.92), rgba(16, 14, 31, 0.96));
            border: 1px solid rgba(111, 241, 227, 0.16);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .modal-hero.danger {
            background:
                radial-gradient(circle at top right, rgba(255, 138, 101, 0.2), transparent 45%),
                linear-gradient(135deg, rgba(64, 22, 33, 0.94), rgba(20, 12, 18, 0.98));
            border-color: rgba(255, 138, 101, 0.2);
        }

        .modal-hero-icon {
            width: 52px;
            height: 52px;
            flex-shrink: 0;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-color);
            background: rgba(111, 241, 227, 0.12);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.08);
        }

        .modal-hero.danger .modal-hero-icon {
            color: #ffb4a2;
            background: rgba(255, 138, 101, 0.14);
        }

        .modal-hero-title {
            font-size: 16px;
            font-weight: 700;
            color: var(--on-surface);
            margin-bottom: 4px;
        }

        .modal-hero-text {
            font-size: 14px;
            line-height: 1.55;
            color: var(--on-surface);
            opacity: 0.8;
        }

        .modal-stack {
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .preset-duration-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .preset-duration-btn {
            width: 100%;
            padding: 16px;
            border-radius: 16px;
            border: 1px solid var(--border);
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.04), rgba(255, 255, 255, 0.01)),
                var(--field-bg);
            color: var(--on-surface);
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4px;
            cursor: pointer;
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.12);
        }

        .preset-duration-btn:hover {
            transform: translateY(-2px);
            border-color: rgba(111, 241, 227, 0.35);
        }

        .preset-duration-btn.active {
            border-color: rgba(111, 241, 227, 0.55);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22), 0 0 0 1px rgba(111, 241, 227, 0.18);
            background:
                linear-gradient(180deg, rgba(111, 241, 227, 0.12), rgba(111, 241, 227, 0.05)),
                var(--field-bg);
        }

        .preset-duration-btn.permanent.active {
            border-color: rgba(255, 138, 101, 0.45);
            background:
                linear-gradient(180deg, rgba(255, 138, 101, 0.14), rgba(255, 138, 101, 0.06)),
                var(--field-bg);
            box-shadow: 0 14px 28px rgba(0, 0, 0, 0.22), 0 0 0 1px rgba(255, 138, 101, 0.16);
        }

        .preset-duration-title {
            font-size: 15px;
            font-weight: 700;
        }

        .preset-duration-meta {
            font-size: 13px;
            opacity: 0.72;
            line-height: 1.4;
        }

        .duration-custom-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .duration-custom-row .form-control {
            flex: 1;
            min-width: 0;
        }

        .duration-addon {
            flex-shrink: 0;
            min-width: 92px;
            text-align: center;
            padding: 12px 14px;
            border-radius: 14px;
            background-color: var(--chip-bg);
            border: 1px solid var(--chip-border);
            color: var(--on-surface);
            font-size: 13px;
            font-weight: 600;
        }

        .duration-summary {
            padding: 16px 18px;
            border-radius: 16px;
            background-color: rgba(111, 241, 227, 0.08);
            border: 1px solid rgba(111, 241, 227, 0.15);
            color: var(--on-surface);
            line-height: 1.55;
        }

        .duration-summary strong {
            color: var(--primary-color);
        }

        .modal-helper-text {
            font-size: 13px;
            line-height: 1.5;
            color: var(--on-surface);
            opacity: 0.72;
        }

        .danger-phrase-box {
            padding: 16px 18px;
            border-radius: 16px;
            border: 1px dashed rgba(255, 138, 101, 0.45);
            background: rgba(255, 138, 101, 0.08);
        }

        .danger-phrase-label {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--on-surface);
            opacity: 0.7;
            margin-bottom: 8px;
        }

        .danger-phrase-code {
            font-size: 18px;
            font-weight: 700;
            color: #ffccbc;
            word-break: break-word;
            user-select: none;
            -webkit-user-select: none;
            -webkit-touch-callout: none;
        }

        .danger-confirm-status {
            font-size: 13px;
            line-height: 1.5;
            color: var(--on-surface);
            opacity: 0.8;
        }

        .danger-confirm-status.ready {
            color: var(--success);
            opacity: 1;
        }

        .skin-create-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(260px, 0.85fr);
            gap: 18px;
            align-items: start;
        }

        .skin-upload-card {
            padding: 18px;
            border-radius: 20px;
            background:
                radial-gradient(circle at top right, rgba(17, 210, 255, 0.15), transparent 42%),
                linear-gradient(135deg, rgba(17, 32, 54, 0.9), rgba(9, 16, 27, 0.96));
            border: 1px solid rgba(17, 210, 255, 0.18);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);
        }

        .skin-upload-preview {
            aspect-ratio: 1 / 1;
            width: 100%;
            border-radius: 22px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            background:
                linear-gradient(180deg, rgba(10, 12, 28, 0.08), rgba(10, 12, 28, 0.36)),
                linear-gradient(135deg, rgba(110, 99, 255, 0.85), rgba(17, 210, 255, 0.82));
            background-size: cover;
            background-position: center;
            box-shadow: 0 18px 32px rgba(0, 0, 0, 0.22);
            margin-bottom: 14px;
        }

        .skin-upload-preview-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
            color: #f6fcff;
        }

        .skin-upload-preview-title {
            font-size: 18px;
            font-weight: 700;
        }

        .skin-upload-preview-subtitle {
            font-size: 13px;
            opacity: 0.8;
            line-height: 1.5;
        }

        .skin-upload-preview-chip {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            align-self: flex-start;
            padding: 9px 12px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            font-size: 13px;
            font-weight: 600;
        }

        .file-drop-shell {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 10px;
            padding: 16px;
            border-radius: 18px;
            border: 1px dashed rgba(17, 210, 255, 0.28);
            background:
                linear-gradient(180deg, rgba(17, 210, 255, 0.08), rgba(17, 210, 255, 0.03)),
                var(--field-bg);
        }

        .file-drop-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--on-surface);
        }

        .file-drop-note {
            font-size: 13px;
            line-height: 1.5;
            color: var(--muted-text);
        }

        .file-drop-shell input[type="file"] {
            width: 100%;
            padding: 12px;
            border-radius: 14px;
            border: 1px solid rgba(17, 210, 255, 0.2);
            background: rgba(255, 255, 255, 0.06);
            color: var(--on-surface);
        }

        .skin-create-success-hint {
            margin-top: 8px;
            font-size: 12px;
            line-height: 1.5;
            color: var(--muted-text);
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px 14px;
            border-radius: 14px;
            border: 1px solid var(--border);
            background: var(--field-bg);
        }

        .form-check input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: var(--primary-color);
            margin: 0;
        }

        .form-check-label {
            font-size: 14px;
            color: var(--on-surface);
            font-weight: 500;
        }

        .skins-panel {
            background: var(--panel-gradient);
        }

        .skin-catalog-toolbar {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .skin-catalog-meta {
            color: var(--muted-text);
            font-size: 13px;
            margin-bottom: 12px;
        }

        .skin-catalog-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
        }

        .skin-catalog-card {
            border: 1px solid var(--border);
            border-radius: 20px;
            background: var(--panel-soft-bg);
            overflow: hidden;
            box-shadow: var(--shadow);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            display: flex;
            flex-direction: column;
        }

        .skin-catalog-card-preview {
            aspect-ratio: 4 / 3;
            background:
                linear-gradient(180deg, rgba(9, 14, 27, 0.04), rgba(9, 14, 27, 0.22)),
                linear-gradient(135deg, rgba(110, 99, 255, 0.85), rgba(17, 210, 255, 0.82));
            background-size: cover;
            background-position: center;
            border-bottom: 1px solid var(--border);
        }

        .skin-catalog-card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .skin-catalog-card-head {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }

        .skin-catalog-card-title {
            font-size: 18px;
            font-weight: 700;
            line-height: 1.2;
            color: var(--on-surface);
        }

        .skin-catalog-card-id {
            font-size: 12px;
            color: var(--muted-text);
            font-family: 'JetBrains Mono', monospace;
        }

        .skin-catalog-card-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: auto;
        }

        @media (max-width: 768px) {
            .modal-content {
                width: calc(100% - 16px);
                max-width: none;
                max-height: calc(100vh - 24px);
            }

            .modal-content.modal-content-wide,
            .modal-content.modal-content-danger,
            .modal-content.modal-content-skin {
                width: calc(100% - 16px);
                max-width: none;
            }

            .modal-hero {
                padding: 16px;
                gap: 12px;
            }

            .modal-hero-icon {
                width: 46px;
                height: 46px;
                border-radius: 14px;
            }

            .preset-duration-grid {
                grid-template-columns: 1fr;
            }

            .duration-custom-row {
                flex-direction: column;
                align-items: stretch;
            }

            .duration-addon {
                width: 100%;
            }

            .skin-create-layout {
                grid-template-columns: 1fr;
            }

            .skin-catalog-grid {
                grid-template-columns: 1fr;
            }
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
            flex-wrap: wrap;
            margin-bottom: 16px;
        }

        .account-sort-select {
            min-width: 220px;
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

        .stack-item-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 12px;
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

            .account-sort-select {
                min-width: 0;
                width: 100%;
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

        <div class="sidebar-item" id="skinsBtn">
            <span class="material-icons">palette</span>
            <span>Скины</span>
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
                        <button class="btn btn-primary" id="openSkinsViewBtn">
                            <span class="material-icons">palette</span>
                            Редактор скинов
                        </button>
                    </div>

                    <div class="accounts-shell">
                        <section class="card account-list-panel" style="margin-bottom: 0;">
                            <div class="account-list-toolbar">
                                <div class="search-box" style="flex: 1; margin-bottom: 0;">
                                    <span class="material-icons search-icon">search</span>
                                    <input type="text" id="accountSearchInput" class="search-input" placeholder="Найти аккаунт по логину или ID">
                                </div>
                                <select class="form-control account-sort-select" id="accountSortSelect" aria-label="Сортировка аккаунтов">
                                    <option value="activity_desc">Сначала по активности</option>
                                    <option value="score_desc">Сначала по счету</option>
                                    <option value="score_asc">Сначала по меньшему счету</option>
                                    <option value="created_desc">Сначала новые</option>
                                    <option value="created_asc">Сначала старые</option>
                                    <option value="login_asc">По логину A-Z</option>
                                </select>
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

            <div class="skins-view animated fadeIn" id="skinsView" style="display: none;">
                <div class="card skins-panel">
                    <div class="card-header">
                        <div>
                            <h2 class="card-title">
                                <span class="material-icons">palette</span>
                                Каталог скинов
                            </h2>
                            <div class="card-subtitle">Полное управление витриной: создание, редактирование цены и названия, замена картинки и удаление.</div>
                        </div>
                        <button class="btn btn-primary" id="createSkinBtn">
                            <span class="material-icons">add_photo_alternate</span>
                            Новый скин
                        </button>
                    </div>

                    <div class="skin-catalog-toolbar">
                        <div class="search-box" style="flex: 1; margin-bottom: 0;">
                            <span class="material-icons search-icon">search</span>
                            <input type="text" id="skinCatalogSearchInput" class="search-input" placeholder="Найти скин по названию или ID">
                        </div>
                        <button class="btn btn-outline" id="refreshSkinCatalogBtn">
                            <span class="material-icons">refresh</span>
                            Обновить
                        </button>
                    </div>

                    <div class="skin-catalog-meta" id="skinCatalogMeta">Загрузка каталога скинов...</div>
                    <div class="skin-catalog-grid" id="skinCatalogGrid"></div>
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

    <div class="modal-overlay" id="banDurationModal">
        <div class="modal-content modal-content-wide">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        <span class="material-icons">gpp_bad</span>
                        Ручной бан
                    </h3>
                    <div class="card-subtitle" id="banDurationModalSubtitle" style="font-size: 14px; color: var(--muted-text); margin-top: 4px;">
                        Выберите срок бана для пользователя
                    </div>
                </div>
                <button class="action-button btn-icon" id="closeBanDurationModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="modal-stack">
                    <div class="modal-hero">
                        <div class="modal-hero-icon">
                            <span class="material-icons">shield</span>
                        </div>
                        <div>
                            <div class="modal-hero-title">Блокировка аккаунта и связанных адресов</div>
                            <div class="modal-hero-text" id="banDurationReasonPreview">Причина будет взята из поля ручного бана и сразу распространится на связанные IP-адреса.</div>
                        </div>
                    </div>

                    <div>
                        <label class="form-label">Шаблоны срока</label>
                        <div class="preset-duration-grid">
                            <button class="preset-duration-btn active" type="button" data-ban-duration="5">
                                <span class="preset-duration-title">5 дней</span>
                                <span class="preset-duration-meta">Быстрый ручной бан</span>
                            </button>
                            <button class="preset-duration-btn" type="button" data-ban-duration="30">
                                <span class="preset-duration-title">30 дней</span>
                                <span class="preset-duration-meta">Повторное серьезное нарушение</span>
                            </button>
                            <button class="preset-duration-btn" type="button" data-ban-duration="90">
                                <span class="preset-duration-title">90 дней</span>
                                <span class="preset-duration-meta">Долгое ограничение</span>
                            </button>
                            <button class="preset-duration-btn" type="button" data-ban-duration="365">
                                <span class="preset-duration-title">365 дней</span>
                                <span class="preset-duration-meta">Максимальный срочный бан</span>
                            </button>
                            <button class="preset-duration-btn permanent" type="button" data-ban-permanent="1" style="grid-column: 1 / -1;">
                                <span class="preset-duration-title">Бессрочно</span>
                                <span class="preset-duration-meta">Полная блокировка без даты окончания</span>
                            </button>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="banDurationDaysInput">Свой срок</label>
                        <div class="duration-custom-row">
                            <input type="number" id="banDurationDaysInput" class="form-control" min="1" step="1" value="5" placeholder="Введите число дней">
                            <div class="duration-addon">дней</div>
                        </div>
                        <div class="modal-helper-text" style="margin-top: 8px;">
                            Можно выбрать готовый шаблон или вручную указать свой срок.
                        </div>
                    </div>

                    <div class="duration-summary" id="banDurationSummary"></div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelBanDurationModal">Отмена</button>
                <button class="btn btn-danger" id="confirmBanDurationBtn">
                    <span class="btn-text">Забанить</span>
                    <div class="loader" style="display: none; margin-left: 8px;"></div>
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteAccountModal">
        <div class="modal-content modal-content-danger">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        <span class="material-icons">delete_forever</span>
                        Удаление аккаунта
                    </h3>
                    <div class="card-subtitle" id="deleteAccountModalSubtitle" style="font-size: 14px; color: var(--muted-text); margin-top: 4px;">
                        Это действие нельзя отменить
                    </div>
                </div>
                <button class="action-button btn-icon" id="closeDeleteAccountModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="modal-stack">
                    <div class="modal-hero danger">
                        <div class="modal-hero-icon">
                            <span class="material-icons">warning</span>
                        </div>
                        <div>
                            <div class="modal-hero-title">Будут удалены все связанные данные</div>
                            <div class="modal-hero-text" id="deleteAccountDangerText">Основная игра, fly-beaver, история IP и все баны будут стерты без возможности восстановления.</div>
                        </div>
                    </div>

                    <div class="danger-phrase-box">
                        <div class="danger-phrase-label">Введите фразу точно как ниже</div>
                        <div class="danger-phrase-code">Я ПОДТВЕРЖДАЮ УДАЛЕНИЕ</div>
                    </div>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label" for="deleteAccountConfirmInput">Подтверждение удаления</label>
                        <input type="text" id="deleteAccountConfirmInput" class="form-control" autocomplete="off" placeholder="Введите: Я ПОДТВЕРЖДАЮ УДАЛЕНИЕ">
                        <div class="danger-confirm-status" id="deleteAccountConfirmStatus" style="margin-top: 8px;">
                            Пока фраза не совпала, удаление заблокировано.
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelDeleteAccountModal">Отмена</button>
                <button class="btn btn-danger" id="confirmDeleteAccountBtn" disabled>
                    <span class="btn-text">Удалить аккаунт</span>
                    <div class="loader" style="display: none; margin-left: 8px;"></div>
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="addSkinModal">
        <div class="modal-content modal-content-skin">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        <span class="material-icons">palette</span>
                        <span id="addSkinModalTitle">Новый скин</span>
                    </h3>
                    <div class="card-subtitle" id="addSkinModalSubtitle" style="font-size: 14px; color: var(--muted-text); margin-top: 4px;">
                        Загружаете картинку, задаете имя и цену, а дальше скин сразу попадает в магазин.
                    </div>
                </div>
                <button class="action-button btn-icon" id="closeAddSkinModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="skin-create-layout">
                    <form id="addSkinForm" class="modal-stack" novalidate>
                        <div class="file-drop-shell">
                            <div class="file-drop-title">Изображение скина</div>
                            <div class="file-drop-note">Подойдут JPG, PNG, WEBP или GIF до 5 МБ. Картинка сразу покажется в превью справа.</div>
                            <input type="file" id="addSkinImageInput" accept="image/png,image/jpeg,image/webp,image/gif" required>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="addSkinNameInput">Название</label>
                            <input class="form-control" id="addSkinNameInput" type="text" maxlength="60" placeholder="Например: Кристальный бобер" required>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label" for="addSkinPriceInput">Цена</label>
                            <input class="form-control" id="addSkinPriceInput" type="number" min="0" step="1" value="0" required>
                            <div class="skin-create-success-hint">ID скина сгенерируется автоматически, руками ничего прописывать не нужно.</div>
                        </div>

                        <label class="form-check">
                            <input type="checkbox" id="addSkinAvailableInput" checked>
                            <span class="form-check-label">Скин доступен игрокам сразу после сохранения</span>
                        </label>

                        <label class="form-check">
                            <input type="checkbox" id="addSkinDefaultOwnedInput">
                            <span class="form-check-label">Выдавать этот скин по умолчанию новым игрокам</span>
                        </label>
                    </form>

                    <div class="skin-upload-card">
                        <div class="skin-upload-preview" id="addSkinPreview"></div>
                        <div class="skin-upload-preview-meta">
                            <div class="skin-upload-preview-title" id="addSkinPreviewTitle">Новый скин</div>
                            <div class="skin-upload-preview-subtitle" id="addSkinPreviewSubtitle">После сохранения скин сразу подхватится магазином и будет доступен игрокам.</div>
                            <div class="skin-upload-preview-chip">
                                <span class="material-icons" style="font-size: 16px;">sell</span>
                                <span id="addSkinPreviewPrice">Цена: 0 коинов</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelAddSkinModal">Отмена</button>
                <button class="btn btn-primary" id="saveAddSkinBtn">
                    <span class="btn-text">Сохранить скин</span>
                    <div class="loader" style="display: none; margin-left: 8px;"></div>
                </button>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteSkinModal">
        <div class="modal-content modal-content-danger">
            <div class="card-header">
                <div>
                    <h3 class="card-title">
                        <span class="material-icons">delete</span>
                        Удаление скина
                    </h3>
                    <div class="card-subtitle" id="deleteSkinModalSubtitle" style="font-size: 14px; color: var(--muted-text); margin-top: 4px;">
                        Скин исчезнет из каталога магазина
                    </div>
                </div>
                <button class="action-button btn-icon" id="closeDeleteSkinModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <div class="modal-stack">
                    <div class="modal-hero danger">
                        <div class="modal-hero-icon">
                            <span class="material-icons">auto_awesome</span>
                        </div>
                        <div>
                            <div class="modal-hero-title" id="deleteSkinModalTitle">Удалить этот скин?</div>
                            <div class="modal-hero-text" id="deleteSkinModalText">Картинка останется на сервере, но сам скин пропадет из каталога магазина.</div>
                        </div>
                    </div>
                    <div class="duration-summary" id="deleteSkinModalInfo"></div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelDeleteSkinModal">Отмена</button>
                <button class="btn btn-danger" id="confirmDeleteSkinBtn">
                    <span class="btn-text">Удалить скин</span>
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
        let currentAccountSort = localStorage.getItem('admin_account_sort') || 'activity_desc';
        let skinCatalogItems = [];
        let skinCatalogSearch = '';
        let pendingDeleteSkinId = '';
        let skinEditorState = {
            mode: 'create',
            skinId: '',
            currentImage: ''
        };
        let pendingBanDurationSelection = {
            isPermanent: false,
            durationDays: 5
        };
        let addSkinPreviewObjectUrl = '';
        const deleteAccountConfirmationPhrase = 'Я ПОДТВЕРЖДАЮ УДАЛЕНИЕ';
        
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

            document.getElementById('closeBanDurationModal').addEventListener('click', function() {
                hideBanDurationModal();
            });

            document.getElementById('cancelBanDurationModal').addEventListener('click', function() {
                hideBanDurationModal();
            });

            document.getElementById('banDurationModal').addEventListener('click', function(event) {
                if (event.target === this) {
                    hideBanDurationModal();
                }
            });

            document.querySelectorAll('[data-ban-duration]').forEach(button => {
                button.addEventListener('click', function() {
                    setBanDurationSelection({
                        isPermanent: false,
                        durationDays: Number(this.dataset.banDuration || 5)
                    });
                });
            });

            document.querySelectorAll('[data-ban-permanent]').forEach(button => {
                button.addEventListener('click', function() {
                    setBanDurationSelection({
                        isPermanent: true,
                        durationDays: 0
                    });
                });
            });

            document.getElementById('banDurationDaysInput').addEventListener('input', function() {
                const nextValue = Math.max(1, Math.floor(Number(this.value || 0)));
                if (Number.isFinite(nextValue) && nextValue > 0) {
                    setBanDurationSelection({
                        isPermanent: false,
                        durationDays: nextValue
                    });
                }
            });

            document.getElementById('confirmBanDurationBtn').addEventListener('click', function() {
                submitBanDurationModal();
            });

            document.getElementById('closeDeleteAccountModal').addEventListener('click', function() {
                hideDeleteAccountModal();
            });

            document.getElementById('cancelDeleteAccountModal').addEventListener('click', function() {
                hideDeleteAccountModal();
            });

            document.getElementById('deleteAccountModal').addEventListener('click', function(event) {
                if (event.target === this) {
                    hideDeleteAccountModal();
                }
            });

            document.getElementById('deleteAccountConfirmInput').addEventListener('input', function() {
                updateDeleteAccountConfirmState();
            });

            const deletePhraseCode = document.querySelector('.danger-phrase-code');
            if (deletePhraseCode) {
                ['copy', 'cut', 'contextmenu', 'dragstart'].forEach(eventName => {
                    deletePhraseCode.addEventListener(eventName, function(event) {
                        event.preventDefault();
                    });
                });
            }

            document.getElementById('deleteAccountConfirmInput').addEventListener('keydown', function(event) {
                if (event.key === 'Enter' && !document.getElementById('confirmDeleteAccountBtn').disabled) {
                    event.preventDefault();
                    submitDeleteSelectedAccount();
                }
            });

            document.getElementById('confirmDeleteAccountBtn').addEventListener('click', function() {
                submitDeleteSelectedAccount();
            });

            const openSkinsViewBtn = document.getElementById('openSkinsViewBtn');
            if (openSkinsViewBtn) {
                openSkinsViewBtn.addEventListener('click', function() {
                    showSkinsView();
                });
            }

            const createSkinBtn = document.getElementById('createSkinBtn');
            if (createSkinBtn) {
                createSkinBtn.addEventListener('click', function() {
                    showAddSkinModal('create');
                });
            }

            const addSkinModal = document.getElementById('addSkinModal');
            if (addSkinModal) {
                addSkinModal.addEventListener('click', function(event) {
                    if (event.target === this) {
                        hideAddSkinModal();
                    }
                });
            }

            const closeAddSkinModalBtn = document.getElementById('closeAddSkinModal');
            if (closeAddSkinModalBtn) {
                closeAddSkinModalBtn.addEventListener('click', function() {
                    hideAddSkinModal();
                });
            }

            const cancelAddSkinModalBtn = document.getElementById('cancelAddSkinModal');
            if (cancelAddSkinModalBtn) {
                cancelAddSkinModalBtn.addEventListener('click', function() {
                    hideAddSkinModal();
                });
            }

            const addSkinNameInput = document.getElementById('addSkinNameInput');
            const addSkinPriceInput = document.getElementById('addSkinPriceInput');
            const addSkinImageInput = document.getElementById('addSkinImageInput');
            const addSkinAvailableInput = document.getElementById('addSkinAvailableInput');
            const addSkinDefaultOwnedInput = document.getElementById('addSkinDefaultOwnedInput');
            const saveAddSkinBtn = document.getElementById('saveAddSkinBtn');

            [addSkinNameInput, addSkinPriceInput, addSkinImageInput, addSkinAvailableInput, addSkinDefaultOwnedInput].forEach(field => {
                if (field) {
                    field.addEventListener('input', updateAddSkinPreview);
                    field.addEventListener('change', updateAddSkinPreview);
                }
            });

            if (saveAddSkinBtn) {
                saveAddSkinBtn.addEventListener('click', function() {
                    submitAddSkinModal();
                });
            }

            const deleteSkinModal = document.getElementById('deleteSkinModal');
            if (deleteSkinModal) {
                deleteSkinModal.addEventListener('click', function(event) {
                    if (event.target === this) {
                        hideDeleteSkinModal();
                    }
                });
            }

            const closeDeleteSkinModal = document.getElementById('closeDeleteSkinModal');
            if (closeDeleteSkinModal) {
                closeDeleteSkinModal.addEventListener('click', function() {
                    hideDeleteSkinModal();
                });
            }

            const cancelDeleteSkinModal = document.getElementById('cancelDeleteSkinModal');
            if (cancelDeleteSkinModal) {
                cancelDeleteSkinModal.addEventListener('click', function() {
                    hideDeleteSkinModal();
                });
            }

            const confirmDeleteSkinBtn = document.getElementById('confirmDeleteSkinBtn');
            if (confirmDeleteSkinBtn) {
                confirmDeleteSkinBtn.addEventListener('click', function() {
                    submitDeleteSkinModal();
                });
            }

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

            document.getElementById('skinsBtn').addEventListener('click', function() {
                showSkinsView();
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
                        loadAccounts(value, currentAccountSort);
                    }, 220);
                });
            }

            const accountSortSelect = document.getElementById('accountSortSelect');
            if (accountSortSelect) {
                accountSortSelect.value = currentAccountSort;
                accountSortSelect.addEventListener('change', function() {
                    currentAccountSort = this.value;
                    localStorage.setItem('admin_account_sort', currentAccountSort);
                    loadAccounts(document.getElementById('accountSearchInput').value.trim(), currentAccountSort);
                });
            }

            const refreshAccountsBtn = document.getElementById('refreshAccountsBtn');
            if (refreshAccountsBtn) {
                refreshAccountsBtn.addEventListener('click', function() {
                    loadAccounts(document.getElementById('accountSearchInput').value.trim(), currentAccountSort);
                });
            }

            const skinCatalogSearchInput = document.getElementById('skinCatalogSearchInput');
            if (skinCatalogSearchInput) {
                skinCatalogSearchInput.addEventListener('input', function() {
                    skinCatalogSearch = this.value.trim();
                    renderSkinCatalogList();
                });
            }

            const refreshSkinCatalogBtn = document.getElementById('refreshSkinCatalogBtn');
            if (refreshSkinCatalogBtn) {
                refreshSkinCatalogBtn.addEventListener('click', function() {
                    loadSkinCatalogAdmin();
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

        function showBanDurationModal() {
            if (!selectedAccountId) {
                return;
            }

            const login = document.getElementById('adminUserLogin') ? document.getElementById('adminUserLogin').value.trim() : '';
            const subtitle = document.getElementById('banDurationModalSubtitle');
            const reasonPreview = document.getElementById('banDurationReasonPreview');

            if (subtitle) {
                subtitle.textContent = `Выберите срок бана для ${login || `ID ${selectedAccountId}`}`;
            }

            if (reasonPreview) {
                const reasonValue = document.getElementById('banReasonInput') ? document.getElementById('banReasonInput').value.trim() : '';
                reasonPreview.textContent = `Причина: ${reasonValue || 'Ручной бан администрацией'}. Бан сразу ограничит аккаунт и связанные IP-адреса.`;
            }

            setBanDurationSelection({
                isPermanent: false,
                durationDays: 5
            });

            document.getElementById('banDurationModal').classList.add('active');
        }

        function hideBanDurationModal() {
            document.getElementById('banDurationModal').classList.remove('active');
        }

        function setBanDurationSelection(selection) {
            const normalized = {
                isPermanent: Boolean(selection && selection.isPermanent),
                durationDays: Math.max(1, Math.floor(Number(selection && selection.durationDays ? selection.durationDays : 5)))
            };

            if (normalized.isPermanent) {
                normalized.durationDays = 0;
            }

            pendingBanDurationSelection = normalized;
            updateBanDurationModalUI();
        }

        function updateBanDurationModalUI() {
            const input = document.getElementById('banDurationDaysInput');
            const summary = document.getElementById('banDurationSummary');

            document.querySelectorAll('[data-ban-duration], [data-ban-permanent]').forEach(button => {
                const isPermanentButton = button.dataset.banPermanent === '1';
                const isActive = pendingBanDurationSelection.isPermanent
                    ? isPermanentButton
                    : (!isPermanentButton && Number(button.dataset.banDuration || 0) === Number(pendingBanDurationSelection.durationDays || 0));
                button.classList.toggle('active', isActive);
            });

            if (input) {
                input.disabled = pendingBanDurationSelection.isPermanent;
                input.value = pendingBanDurationSelection.isPermanent
                    ? ''
                    : String(Math.max(1, Number(pendingBanDurationSelection.durationDays || 5)));
            }

            if (summary) {
                summary.innerHTML = pendingBanDurationSelection.isPermanent
                    ? '<strong>Выбран срок:</strong> бессрочный бан. Аккаунт и связанные адреса будут заблокированы без даты окончания.'
                    : `<strong>Выбран срок:</strong> ${escapeHtml(String(pendingBanDurationSelection.durationDays))} дн. Бан закроет доступ ко всем игровым системам с аккаунта и связанных адресов.`;
            }
        }

        function submitBanDurationModal() {
            if (!selectedAccountId) {
                hideBanDurationModal();
                return;
            }

            const reasonInput = document.getElementById('banReasonInput');
            const reason = reasonInput ? reasonInput.value.trim() : '';
            const actionButton = document.getElementById('toggleBanAccountBtn');
            const confirmButton = document.getElementById('confirmBanDurationBtn');
            const confirmText = confirmButton ? confirmButton.querySelector('.btn-text') : null;
            const confirmLoader = confirmButton ? confirmButton.querySelector('.loader') : null;
            const originalActionButtonContent = actionButton ? actionButton.innerHTML : '';

            if (confirmButton) {
                confirmButton.disabled = true;
            }
            if (confirmText) {
                confirmText.style.display = 'none';
            }
            if (confirmLoader) {
                confirmLoader.style.display = 'inline-block';
            }
            if (actionButton) {
                actionButton.disabled = true;
                actionButton.innerHTML = `<div class="loader" style="width: 18px; height: 18px; border-width: 2px;"></div>`;
            }

            postAction({
                action: 'ban_user_account',
                user_id: String(selectedAccountId),
                reason: reason || 'Ручной бан администрацией',
                duration_days: String(pendingBanDurationSelection.isPermanent ? 0 : pendingBanDurationSelection.durationDays),
                is_permanent: pendingBanDurationSelection.isPermanent ? '1' : '0'
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось забанить пользователя');
                }

                hideBanDurationModal();
                showNotification(data.message || 'Пользователь забанен', 'warning');
                loadDashboardStats();
                loadAccounts(lastAccountSearch, currentAccountSort);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка бана пользователя', 'error');
            })
            .finally(() => {
                if (confirmButton) {
                    confirmButton.disabled = false;
                }
                if (confirmText) {
                    confirmText.style.display = 'inline';
                }
                if (confirmLoader) {
                    confirmLoader.style.display = 'none';
                }
                if (actionButton) {
                    actionButton.disabled = false;
                    actionButton.innerHTML = originalActionButtonContent;
                }
            });
        }

        function showDeleteAccountModal() {
            if (!selectedAccountId) {
                return;
            }

            const login = document.getElementById('adminUserLogin') ? document.getElementById('adminUserLogin').value.trim() : '';
            const subtitle = document.getElementById('deleteAccountModalSubtitle');
            const dangerText = document.getElementById('deleteAccountDangerText');
            const input = document.getElementById('deleteAccountConfirmInput');

            if (subtitle) {
                subtitle.textContent = `Удаление ${login || `аккаунта ID ${selectedAccountId}`}`;
            }

            if (dangerText) {
                dangerText.textContent = `Будут удалены ${login || `аккаунт ID ${selectedAccountId}`}, основная игра, fly-beaver, история IP и все связанные баны.`;
            }

            if (input) {
                input.value = '';
            }

            updateDeleteAccountConfirmState();
            document.getElementById('deleteAccountModal').classList.add('active');
        }

        function hideDeleteAccountModal() {
            document.getElementById('deleteAccountModal').classList.remove('active');
        }

        function clearAddSkinPreviewObjectUrl() {
            if (addSkinPreviewObjectUrl) {
                URL.revokeObjectURL(addSkinPreviewObjectUrl);
                addSkinPreviewObjectUrl = '';
            }
        }

        function resolveAdminSkinImageUrl(imagePath) {
            const normalizedPath = String(imagePath || '').trim();
            if (!normalizedPath) {
                return '';
            }

            if (/^(https?:)?\/\//i.test(normalizedPath) || normalizedPath.startsWith('data:')) {
                return normalizedPath;
            }

            if (normalizedPath.startsWith('../') || normalizedPath.startsWith('./')) {
                return normalizedPath;
            }

            return `../${normalizedPath.replace(/^\/+/, '')}`;
        }

        function getFilteredSkinCatalogItems() {
            const query = skinCatalogSearch.trim().toLowerCase();
            if (!query) {
                return [...skinCatalogItems];
            }

            return skinCatalogItems.filter(item => {
                const name = String(item.name || '').toLowerCase();
                const id = String(item.id || '').toLowerCase();
                return name.includes(query) || id.includes(query);
            });
        }

        function isPinnedLastAdminSkinItem(item) {
            return Boolean(item && String(item.id || '').trim() === 'dev');
        }

        function sortAdminSkinCatalogItems(items) {
            const regularItems = [];
            const pinnedLastItems = [];

            (Array.isArray(items) ? items : []).forEach(item => {
                if (isPinnedLastAdminSkinItem(item)) {
                    pinnedLastItems.push(item);
                    return;
                }

                regularItems.push(item);
            });

            return [...regularItems, ...pinnedLastItems];
        }

        function renderSkinCatalogList() {
            const grid = document.getElementById('skinCatalogGrid');
            const meta = document.getElementById('skinCatalogMeta');

            if (!grid || !meta) {
                return;
            }

            const filteredItems = getFilteredSkinCatalogItems();
            meta.textContent = skinCatalogSearch
                ? `Найдено ${formatAdminNumber(filteredItems.length)} из ${formatAdminNumber(skinCatalogItems.length)} скинов по запросу "${skinCatalogSearch}".`
                : `Всего скинов в каталоге: ${formatAdminNumber(skinCatalogItems.length)}.`;

            if (filteredItems.length === 0) {
                grid.innerHTML = '<div class="empty-list">По этому запросу скины не найдены.</div>';
                return;
            }

            grid.innerHTML = filteredItems.map(item => {
                const priceLabel = Number(item.price || 0) > 0
                    ? `${formatAdminNumber(item.price)} коинов`
                    : 'Бесплатно';
                const availabilityLabel = item.available === false ? 'Скрыт' : 'Доступен';
                const imageUrl = resolveAdminSkinImageUrl(item.image);

                return `
                    <article class="skin-catalog-card" data-skin-id="${escapeHtml(item.id)}">
                        <div class="skin-catalog-card-preview" style="background-image: linear-gradient(180deg, rgba(9, 14, 27, 0.04), rgba(9, 14, 27, 0.22)), url('${escapeHtml(imageUrl)}');"></div>
                        <div class="skin-catalog-card-body">
                            <div class="skin-catalog-card-head">
                                <div>
                                    <div class="skin-catalog-card-title">${escapeHtml(item.name || item.id)}</div>
                                    <div class="skin-catalog-card-id">${escapeHtml(item.id)}</div>
                                </div>
                                <span class="status-pill ${item.available === false ? 'banned' : 'active'}">${item.available === false ? 'Скрыт' : 'В магазине'}</span>
                            </div>
                            <div class="inline-actions" style="margin-top: 0;">
                                <span class="mini-chip"><span class="material-icons" style="font-size: 14px;">sell</span>${priceLabel}</span>
                                <span class="mini-chip"><span class="material-icons" style="font-size: 14px;">verified_user</span>${item.default_owned ? 'Стартовый' : 'Покупаемый'}</span>
                                <span class="mini-chip"><span class="material-icons" style="font-size: 14px;">image</span>${availabilityLabel}</span>
                            </div>
                            <div class="skin-catalog-card-actions">
                                <button class="btn btn-outline btn-small edit-skin-btn" type="button" data-skin-id="${escapeHtml(item.id)}">
                                    <span class="material-icons">edit</span>
                                    Редактировать
                                </button>
                                <button class="btn btn-danger btn-small delete-skin-btn" type="button" data-skin-id="${escapeHtml(item.id)}">
                                    <span class="material-icons">delete</span>
                                    Удалить
                                </button>
                            </div>
                        </div>
                    </article>
                `;
            }).join('');

            grid.querySelectorAll('.edit-skin-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const skinId = String(this.dataset.skinId || '');
                    const skinItem = skinCatalogItems.find(item => String(item.id) === skinId);
                    if (skinItem) {
                        showAddSkinModal('edit', skinItem);
                    }
                });
            });

            grid.querySelectorAll('.delete-skin-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const skinId = String(this.dataset.skinId || '');
                    const skinItem = skinCatalogItems.find(item => String(item.id) === skinId);
                    if (skinItem) {
                        showDeleteSkinModal(skinItem);
                    }
                });
            });
        }

        function loadSkinCatalogAdmin() {
            const grid = document.getElementById('skinCatalogGrid');
            const meta = document.getElementById('skinCatalogMeta');

            if (grid) {
                grid.innerHTML = '<div class="empty-list"><div class="loader" style="margin-right: 10px;"></div>Загружаю каталог скинов...</div>';
            }
            if (meta) {
                meta.textContent = 'Загрузка каталога скинов...';
            }

            postAction({
                action: 'get_skin_catalog'
            })
            .then(data => {
                if (!data.success || !Array.isArray(data.skins)) {
                    throw new Error(data.message || 'Не удалось загрузить каталог скинов');
                }

                skinCatalogItems = sortAdminSkinCatalogItems(data.skins);
                renderSkinCatalogList();
            })
            .catch(error => {
                console.error('Error:', error);
                if (grid) {
                    grid.innerHTML = `<div class="empty-list">${escapeHtml(error.message || 'Ошибка загрузки каталога')}</div>`;
                }
                if (meta) {
                    meta.textContent = 'Не удалось загрузить каталог скинов';
                }
                showNotification(error.message || 'Ошибка загрузки каталога скинов', 'error');
            });
        }

        function showSkinsView() {
            currentAdminView = 'skins';
            document.getElementById('accountsView').style.display = 'none';
            document.getElementById('skinsView').style.display = 'block';
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'none';
            document.getElementById('statsGrid').style.display = 'grid';
            updateActiveMenuItem('skinsBtn');
            loadDashboardStats();
            loadSkinCatalogAdmin();
        }

        function updateAddSkinPreview() {
            const titleNode = document.getElementById('addSkinPreviewTitle');
            const subtitleNode = document.getElementById('addSkinPreviewSubtitle');
            const priceNode = document.getElementById('addSkinPreviewPrice');
            const previewNode = document.getElementById('addSkinPreview');
            const imageInput = document.getElementById('addSkinImageInput');
            const nameInput = document.getElementById('addSkinNameInput');
            const priceInput = document.getElementById('addSkinPriceInput');
            const availableInput = document.getElementById('addSkinAvailableInput');
            const defaultOwnedInput = document.getElementById('addSkinDefaultOwnedInput');

            const skinName = nameInput ? nameInput.value.trim() : '';
            const skinPrice = Math.max(0, Math.floor(Number(priceInput ? priceInput.value : 0) || 0));
            const isAvailable = !availableInput || availableInput.checked;
            const isDefaultOwned = Boolean(defaultOwnedInput && defaultOwnedInput.checked);

            if (titleNode) {
                titleNode.textContent = skinName || 'Новый скин';
            }

            if (subtitleNode) {
                subtitleNode.textContent = skinEditorState.mode === 'edit'
                    ? `Редактируете скин "${skinName || skinEditorState.skinId || 'без названия'}". Можно заменить картинку, цену и статус без ручной правки файлов.`
                    : (skinName
                        ? `Скин "${skinName}" появится в общем каталоге и будет доступен в магазине сразу после сохранения.`
                        : 'После сохранения скин сразу подхватится магазином и будет доступен игрокам.');
            }

            if (priceNode) {
                const availabilityLabel = isAvailable ? 'виден игрокам' : 'скрыт из магазина';
                const defaultLabel = isDefaultOwned ? ' • стартовый' : '';
                priceNode.textContent = `Цена: ${formatAdminNumber(skinPrice)} коинов • ${availabilityLabel}${defaultLabel}`;
            }

            if (previewNode) {
                clearAddSkinPreviewObjectUrl();
                const file = imageInput && imageInput.files ? imageInput.files[0] : null;
                if (file) {
                    addSkinPreviewObjectUrl = URL.createObjectURL(file);
                    previewNode.style.backgroundImage = `linear-gradient(180deg, rgba(10, 12, 28, 0.04), rgba(10, 12, 28, 0.2)), url("${addSkinPreviewObjectUrl}")`;
                } else if (skinEditorState.mode === 'edit' && skinEditorState.currentImage) {
                    previewNode.style.backgroundImage = `linear-gradient(180deg, rgba(10, 12, 28, 0.04), rgba(10, 12, 28, 0.2)), url("${resolveAdminSkinImageUrl(skinEditorState.currentImage)}")`;
                } else {
                    previewNode.style.backgroundImage = 'linear-gradient(135deg, rgba(110, 99, 255, 0.85), rgba(17, 210, 255, 0.82))';
                }
            }
        }

        function resetAddSkinForm() {
            const form = document.getElementById('addSkinForm');
            if (form) {
                form.reset();
            }

            const priceInput = document.getElementById('addSkinPriceInput');
            if (priceInput) {
                priceInput.value = '0';
            }

            const availableInput = document.getElementById('addSkinAvailableInput');
            if (availableInput) {
                availableInput.checked = true;
            }

            const defaultOwnedInput = document.getElementById('addSkinDefaultOwnedInput');
            if (defaultOwnedInput) {
                defaultOwnedInput.checked = false;
            }

            clearAddSkinPreviewObjectUrl();
            skinEditorState = {
                mode: 'create',
                skinId: '',
                currentImage: ''
            };
            updateAddSkinPreview();
        }

        function showAddSkinModal(mode = 'create', skinItem = null) {
            resetAddSkinForm();

            const titleNode = document.getElementById('addSkinModalTitle');
            const subtitleNode = document.getElementById('addSkinModalSubtitle');
            const saveButtonText = document.querySelector('#saveAddSkinBtn .btn-text');
            const imageInput = document.getElementById('addSkinImageInput');
            const nameInput = document.getElementById('addSkinNameInput');
            const priceInput = document.getElementById('addSkinPriceInput');
            const availableInput = document.getElementById('addSkinAvailableInput');
            const defaultOwnedInput = document.getElementById('addSkinDefaultOwnedInput');

            if (mode === 'edit' && skinItem) {
                skinEditorState = {
                    mode: 'edit',
                    skinId: String(skinItem.id || ''),
                    currentImage: String(skinItem.image || '')
                };

                if (titleNode) {
                    titleNode.textContent = 'Редактировать скин';
                }
                if (subtitleNode) {
                    subtitleNode.textContent = `ID: ${skinEditorState.skinId}. Можно обновить цену, название, доступность и при желании заменить картинку.`;
                }
                if (saveButtonText) {
                    saveButtonText.textContent = 'Сохранить изменения';
                }
                if (nameInput) {
                    nameInput.value = String(skinItem.name || '');
                }
                if (priceInput) {
                    priceInput.value = String(Math.max(0, Number(skinItem.price) || 0));
                }
                if (availableInput) {
                    availableInput.checked = skinItem.available !== false;
                }
                if (defaultOwnedInput) {
                    defaultOwnedInput.checked = Boolean(skinItem.default_owned || skinItem.defaultOwned);
                }
                if (imageInput) {
                    imageInput.required = false;
                }
            } else {
                if (titleNode) {
                    titleNode.textContent = 'Новый скин';
                }
                if (subtitleNode) {
                    subtitleNode.textContent = 'Загружаете картинку, задаете имя и цену, а дальше скин сразу попадает в магазин.';
                }
                if (saveButtonText) {
                    saveButtonText.textContent = 'Добавить скин';
                }
                if (imageInput) {
                    imageInput.required = true;
                }
            }

            updateAddSkinPreview();
            document.getElementById('addSkinModal').classList.add('active');
        }

        function hideAddSkinModal() {
            document.getElementById('addSkinModal').classList.remove('active');
            clearAddSkinPreviewObjectUrl();
        }

        function submitAddSkinModal() {
            const nameInput = document.getElementById('addSkinNameInput');
            const priceInput = document.getElementById('addSkinPriceInput');
            const imageInput = document.getElementById('addSkinImageInput');
            const availableInput = document.getElementById('addSkinAvailableInput');
            const defaultOwnedInput = document.getElementById('addSkinDefaultOwnedInput');
            const saveButton = document.getElementById('saveAddSkinBtn');
            const btnText = saveButton ? saveButton.querySelector('.btn-text') : null;
            const loader = saveButton ? saveButton.querySelector('.loader') : null;

            const skinName = nameInput ? nameInput.value.trim() : '';
            const skinPrice = Math.max(0, Math.floor(Number(priceInput ? priceInput.value : 0) || 0));
            const imageFile = imageInput && imageInput.files ? imageInput.files[0] : null;
            const isAvailable = !availableInput || availableInput.checked;
            const isDefaultOwned = Boolean(defaultOwnedInput && defaultOwnedInput.checked);

            if (skinName.length < 2) {
                showNotification('Введите название скина.', 'error');
                return;
            }

            if (skinEditorState.mode === 'create' && !imageFile) {
                showNotification('Выберите изображение скина.', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('action', skinEditorState.mode === 'edit' ? 'update_skin_catalog_item' : 'create_skin_catalog_item');
            if (skinEditorState.mode === 'edit') {
                formData.append('skin_id', skinEditorState.skinId);
            }
            formData.append('skin_name', skinName);
            formData.append('skin_price', String(skinPrice));
            formData.append('available', isAvailable ? '1' : '0');
            formData.append('default_owned', isDefaultOwned ? '1' : '0');
            if (imageFile) {
                formData.append('skin_image', imageFile);
            }

            if (saveButton) {
                saveButton.disabled = true;
            }
            if (btnText) {
                btnText.style.display = 'none';
            }
            if (loader) {
                loader.style.display = 'inline-block';
            }

            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось добавить скин');
                }

                hideAddSkinModal();
                loadSkinCatalogAdmin();
                showNotification(data.message || 'Скин сохранен', 'success');
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка сохранения скина', 'error');
            })
            .finally(() => {
                if (saveButton) {
                    saveButton.disabled = false;
                }
                if (btnText) {
                    btnText.style.display = 'inline';
                }
                if (loader) {
                    loader.style.display = 'none';
                }
            });
        }

        function showDeleteSkinModal(skinItem) {
            pendingDeleteSkinId = String(skinItem.id || '');

            const subtitle = document.getElementById('deleteSkinModalSubtitle');
            const title = document.getElementById('deleteSkinModalTitle');
            const text = document.getElementById('deleteSkinModalText');
            const info = document.getElementById('deleteSkinModalInfo');

            if (subtitle) {
                subtitle.textContent = `Скин "${skinItem.name || skinItem.id}" будет удален из каталога.`;
            }
            if (title) {
                title.textContent = `Удалить ${skinItem.name || skinItem.id}?`;
            }
            if (text) {
                text.textContent = 'Игроки перестанут видеть этот скин в магазине. Уже сохраненные картинки останутся на сервере.';
            }
            if (info) {
                info.innerHTML = `
                    <strong>ID:</strong> ${escapeHtml(String(skinItem.id || ''))}<br>
                    <strong>Цена:</strong> ${formatAdminNumber(Number(skinItem.price || 0))} коинов<br>
                    <strong>Статус:</strong> ${skinItem.available === false ? 'скрыт' : 'виден в магазине'}
                `;
            }

            document.getElementById('deleteSkinModal').classList.add('active');
        }

        function hideDeleteSkinModal() {
            pendingDeleteSkinId = '';
            document.getElementById('deleteSkinModal').classList.remove('active');
        }

        function submitDeleteSkinModal() {
            if (!pendingDeleteSkinId) {
                hideDeleteSkinModal();
                return;
            }

            const confirmButton = document.getElementById('confirmDeleteSkinBtn');
            const confirmText = confirmButton ? confirmButton.querySelector('.btn-text') : null;
            const confirmLoader = confirmButton ? confirmButton.querySelector('.loader') : null;

            if (confirmButton) {
                confirmButton.disabled = true;
            }
            if (confirmText) {
                confirmText.style.display = 'none';
            }
            if (confirmLoader) {
                confirmLoader.style.display = 'inline-block';
            }

            postAction({
                action: 'delete_skin_catalog_item',
                skin_id: pendingDeleteSkinId
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось удалить скин');
                }

                hideDeleteSkinModal();
                loadSkinCatalogAdmin();
                showNotification(data.message || 'Скин удален', 'success');
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка удаления скина', 'error');
            })
            .finally(() => {
                if (confirmButton) {
                    confirmButton.disabled = false;
                }
                if (confirmText) {
                    confirmText.style.display = 'inline';
                }
                if (confirmLoader) {
                    confirmLoader.style.display = 'none';
                }
            });
        }

        function updateDeleteAccountConfirmState() {
            const input = document.getElementById('deleteAccountConfirmInput');
            const status = document.getElementById('deleteAccountConfirmStatus');
            const confirmButton = document.getElementById('confirmDeleteAccountBtn');
            const currentValue = input ? input.value.trim() : '';
            const isReady = currentValue === deleteAccountConfirmationPhrase;

            if (confirmButton) {
                confirmButton.disabled = !isReady;
            }

            if (status) {
                status.classList.toggle('ready', isReady);
                status.textContent = isReady
                    ? 'Фраза совпала. Теперь удаление можно подтвердить.'
                    : 'Пока фраза не совпала, удаление заблокировано.';
            }
        }

        function showAccountsView() {
            currentAdminView = 'accounts';
            document.getElementById('accountsView').style.display = 'block';
            document.getElementById('skinsView').style.display = 'none';
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'none';
            document.getElementById('statsGrid').style.display = 'grid';
            updateActiveMenuItem('accountsBtn');
            loadDashboardStats();
            loadAccounts(document.getElementById('accountSearchInput').value.trim(), currentAccountSort);
        }
        
        // Функция показа статистики
        function showStatistics() {
            currentAdminView = 'statistics';
            document.getElementById('accountsView').style.display = 'none';
            document.getElementById('skinsView').style.display = 'none';
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
            document.getElementById('skinsView').style.display = 'none';
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

        function getAccountSortLabel(sortKey) {
            const labels = {
                activity_desc: 'сначала по активности',
                score_desc: 'сначала по счету',
                score_asc: 'сначала по меньшему счету',
                created_desc: 'сначала новые',
                created_asc: 'сначала старые',
                login_asc: 'по логину A-Z'
            };

            return labels[sortKey] || labels.activity_desc;
        }

        function markActiveAccountInList() {
            document.querySelectorAll('.account-list-item').forEach(item => {
                item.classList.toggle('active', Number(item.dataset.userId) === Number(selectedAccountId));
            });
        }

        function loadAccounts(search = '', sort = currentAccountSort) {
            lastAccountSearch = search;
            currentAccountSort = sort || currentAccountSort;

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
                search: search,
                sort: currentAccountSort
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось загрузить список аккаунтов');
                }

                currentAccountSort = data.sort || currentAccountSort;
                const sortSelect = document.getElementById('accountSortSelect');
                if (sortSelect && sortSelect.value !== currentAccountSort) {
                    sortSelect.value = currentAccountSort;
                }

                renderAccountsList(data.users || [], Number(data.total || 0), search, currentAccountSort);

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

        function renderAccountsList(users, total, search, sort) {
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
                    ? `Найдено ${formatAdminNumber(total)} аккаунтов по запросу "${search}", показаны первые ${formatAdminNumber(users.length)} (${getAccountSortLabel(sort)}).`
                    : `Всего аккаунтов: ${formatAdminNumber(total)}. Показаны первые ${formatAdminNumber(users.length)} (${getAccountSortLabel(sort)}).`;
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
                                    Причина: ${escapeHtml(item.reason || 'не указана')}<br>
                                    ${item.liftedAt ? `Снят: ${escapeHtml(formatAdminDateTime(item.liftedAt))}` : 'Сейчас ограничивает вход и регистрацию с этих IP'}
                                </div>
                                ${item.liftedAt ? '' : `
                                    <div class="stack-item-actions">
                                        <button class="btn btn-outline btn-small lift-ip-ban-btn" type="button" data-ip-ban-id="${Number(item.id)}">
                                            <span class="material-icons">shield_unlock</span>
                                            Снять IP-бан
                                        </button>
                                    </div>
                                `}
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
            detailContainer.querySelectorAll('.lift-ip-ban-btn').forEach(button => {
                button.addEventListener('click', function() {
                    liftSingleIpBan(Number(this.dataset.ipBanId || 0));
                });
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
                loadAccounts(lastAccountSearch, currentAccountSort);
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

        function liftSingleIpBan(ipBanId) {
            if (!selectedAccountId || ipBanId < 1) {
                return;
            }

            postAction({
                action: 'lift_single_ip_ban',
                user_id: String(selectedAccountId),
                ip_ban_id: String(ipBanId)
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось снять IP-бан');
                }

                showNotification(data.message || 'IP-бан снят', 'success');
                loadDashboardStats();
                loadAccounts(lastAccountSearch, currentAccountSort);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка снятия IP-бана', 'error');
            });
        }

        function banSelectedAccount() {
            if (!selectedAccountId) {
                return;
            }

            showBanDurationModal();
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
                loadAccounts(lastAccountSearch, currentAccountSort);
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

            showDeleteAccountModal();
        }

        function submitDeleteSelectedAccount() {
            if (!selectedAccountId) {
                hideDeleteAccountModal();
                return;
            }

            const confirmationInput = document.getElementById('deleteAccountConfirmInput');
            if (!confirmationInput || confirmationInput.value.trim() !== deleteAccountConfirmationPhrase) {
                showNotification('Введите точную фразу подтверждения удаления', 'error');
                return;
            }

            const deleteButton = document.getElementById('deleteAccountBtn');
            const confirmButton = document.getElementById('confirmDeleteAccountBtn');
            const confirmText = confirmButton ? confirmButton.querySelector('.btn-text') : null;
            const confirmLoader = confirmButton ? confirmButton.querySelector('.loader') : null;
            const originalContent = deleteButton.innerHTML;

            deleteButton.disabled = true;
            deleteButton.innerHTML = `<div class="loader" style="width: 18px; height: 18px; border-width: 2px;"></div>`;
            if (confirmButton) {
                confirmButton.disabled = true;
            }
            if (confirmText) {
                confirmText.style.display = 'none';
            }
            if (confirmLoader) {
                confirmLoader.style.display = 'inline-block';
            }

            postAction({
                action: 'delete_user_account',
                user_id: String(selectedAccountId)
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Не удалось удалить аккаунт');
                }

                hideDeleteAccountModal();
                selectedAccountId = null;
                selectedAccountBaselineSnapshot = '';
                selectedAccountDirty = false;
                document.getElementById('accountDetailContainer').style.display = 'none';
                document.getElementById('accountDetailEmpty').style.display = 'flex';
                showNotification(data.message || 'Аккаунт удален', 'success');
                loadDashboardStats();
                loadAccounts(lastAccountSearch, currentAccountSort);
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification(error.message || 'Ошибка удаления аккаунта', 'error');
            })
            .finally(() => {
                deleteButton.disabled = false;
                deleteButton.innerHTML = originalContent;
                if (confirmButton) {
                    updateDeleteAccountConfirmState();
                }
                if (confirmText) {
                    confirmText.style.display = 'inline';
                }
                if (confirmLoader) {
                    confirmLoader.style.display = 'none';
                }
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
            document.getElementById('skinsView').style.display = 'none';
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
