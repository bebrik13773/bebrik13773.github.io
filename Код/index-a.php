<?php
// Настройки подключения к базе данных
define('DB_HOST', 'sql305.infinityfree.com');
define('DB_USER', 'if0_39950285');
define('DB_PASS', 'tmzPxb2Wu5aj6Lb');
define('DB_NAME', 'if0_39950285_base');

// Инициализация сессии
session_start();

// Обработка ошибок
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Функция для логирования действий
function logAction($action, $details = '') {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        return false;
    }
    
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;
    $action = $conn->real_escape_string($action);
    $details = $conn->real_escape_string($details);
    
    $sql = "INSERT INTO logs (user_id, action, details, created_at) 
            VALUES ($user_id, '$action', '$details', NOW())";
    
    $result = $conn->query($sql);
    $conn->close();
    
    return $result;
}

// Функция для подключения к БД
function connectDB() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Ошибка подключения: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Создание необходимых таблиц при первом запуске
function initializeDatabase() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
    
    if ($conn->connect_error) {
        die("Ошибка подключения к серверу БД: " . $conn->connect_error);
    }
    
    // Создание базы данных если не существует
    $sql = "CREATE DATABASE IF NOT EXISTS " . DB_NAME . " CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    $conn->query($sql);
    
    $conn->select_db(DB_NAME);
    
    // Создание таблицы для паролей
    $sql = "CREATE TABLE IF NOT EXISTS pass (
        id INT AUTO_INCREMENT PRIMARY KEY,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )";
    $conn->query($sql);
    
    // Проверяем, есть ли пароль в таблице
    $result = $conn->query("SELECT COUNT(*) as count FROM pass");
    $row = $result->fetch_assoc();
    
    // Если таблица пуста, добавляем дефолтный пароль
    if ($row['count'] == 0) {
        $default_password = 'Gosha123';
        $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO pass (password_hash) VALUES (?)");
        $stmt->bind_param("s", $hashed_password);
        $stmt->execute();
        $stmt->close();
    }
    
    // Создаем демонстрационные таблицы (только users)
    createDemoTables($conn);
    
    $conn->close();
}

// Создание демонстрационных таблиц
function createDemoTables($conn) {
    // Таблица пользователей
    $sql = "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        email VARCHAR(100) NOT NULL,
        role ENUM('admin', 'user', 'moderator') DEFAULT 'user',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_active BOOLEAN DEFAULT TRUE
    )";
    $conn->query($sql);
    
    // Проверяем, есть ли данные в таблице users
    $result = $conn->query("SELECT COUNT(*) as count FROM users");
    $row = $result->fetch_assoc();
    
    if ($row['count'] == 0) {
        // Добавляем демонстрационные данные
        $demo_users = [
            "('admin', 'admin@example.com', 'admin', TRUE)",
            "('john_doe', 'john@example.com', 'user', TRUE)",
            "('jane_smith', 'jane@example.com', 'moderator', TRUE)",
            "('bob_wilson', 'bob@example.com', 'user', FALSE)"
        ];
        
        foreach ($demo_users as $user) {
            $conn->query("INSERT INTO users (username, email, role, is_active) VALUES $user");
        }
    }
    
    // Таблица для логов (демонстрационная)
    $sql = "CREATE TABLE IF NOT EXISTS logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT,
        action VARCHAR(100) NOT NULL,
        details TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_created_at (created_at)
    )";
    $conn->query($sql);
}

// Инициализация БД при каждом запуске
initializeDatabase();

// Обработка авторизации
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $response = ['success' => false, 'message' => ''];
    
    if ($_POST['action'] === 'login') {
        $password = $_POST['password'] ?? '';
        
        $conn = connectDB();
        $stmt = $conn->prepare("SELECT password_hash FROM pass LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            
            // Проверяем, нужно ли сменить пароль (если используется дефолтный)
            $is_default_password = password_verify('Gosha123', $row['password_hash']);
            
            if (password_verify($password, $row['password_hash'])) {
                $_SESSION['authenticated'] = true;
                $_SESSION['password_changed'] = !$is_default_password;
                $_SESSION['user_id'] = 1; // Для логирования
                $response['success'] = true;
                $response['password_changed'] = $_SESSION['password_changed'];
                $response['message'] = 'Авторизация успешна';
                
                // Логируем вход
                logAction('login', 'Успешный вход в систему');
            } else {
                $response['message'] = 'Неверный пароль';
                logAction('login_failed', 'Неверный пароль');
            }
        } else {
            $response['message'] = 'Ошибка базы данных';
        }
        
        $stmt->close();
        $conn->close();
    }
    
    if ($_POST['action'] === 'logout') {
        logAction('logout', 'Выход из системы');
        session_destroy();
        $response['success'] = true;
        $response['message'] = 'Выход выполнен';
    }
    
    if ($_POST['action'] === 'change_password') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($new_password) || strlen($new_password) < 6) {
                $response['message'] = 'Новый пароль должен содержать минимум 6 символов';
            } elseif ($new_password !== $confirm_password) {
                $response['message'] = 'Пароли не совпадают';
            } else {
                $conn = connectDB();
                $stmt = $conn->prepare("SELECT password_hash FROM pass LIMIT 1");
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows > 0) {
                    $row = $result->fetch_assoc();
                    
                    if (password_verify($current_password, $row['password_hash'])) {
                        $new_hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                        $update_stmt = $conn->prepare("UPDATE pass SET password_hash = ?");
                        $update_stmt->bind_param("s", $new_hashed_password);
                        
                        if ($update_stmt->execute()) {
                            $_SESSION['password_changed'] = true;
                            $response['success'] = true;
                            $response['message'] = 'Пароль успешно изменен';
                            
                            // Логируем смену пароля
                            logAction('password_change', 'Пароль успешно изменен');
                        } else {
                            $response['message'] = 'Ошибка при обновлении пароля';
                        }
                        
                        $update_stmt->close();
                    } else {
                        $response['message'] = 'Текущий пароль неверен';
                    }
                }
                
                $stmt->close();
                $conn->close();
            }
        }
    }
    
    if ($_POST['action'] === 'execute_sql') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $sql = $_POST['sql'] ?? '';
            
            if (empty(trim($sql))) {
                $response['message'] = 'SQL запрос не может быть пустым';
            } else {
                $conn = connectDB();
                
                // Логируем SQL запрос (только не-SELECT)
                $is_select = stripos(trim($sql), 'SELECT') === 0;
                if (!$is_select) {
                    logAction('sql_execute', substr($sql, 0, 200));
                }
                
                // Выполняем SQL запрос
                if ($conn->multi_query($sql)) {
                    $results = [];
                    $affected_rows = 0;
                    
                    do {
                        if ($result = $conn->store_result()) {
                            // Запрос вернул результат (SELECT, SHOW, DESCRIBE, etc.)
                            $rows = [];
                            $columns = [];
                            
                            // Получаем названия колонок
                            while ($field = $result->fetch_field()) {
                                $columns[] = $field->name;
                            }
                            
                            // Получаем данные
                            while ($row = $result->fetch_assoc()) {
                                $rows[] = $row;
                            }
                            
                            $results[] = [
                                'columns' => $columns,
                                'rows' => $rows,
                                'row_count' => $result->num_rows
                            ];
                            
                            $result->free();
                        } else {
                            // Запрос не вернул результат (INSERT, UPDATE, DELETE, etc.)
                            if ($conn->affected_rows > 0) {
                                $affected_rows += $conn->affected_rows;
                            }
                        }
                    } while ($conn->more_results() && $conn->next_result());
                    
                    $response['success'] = true;
                    $response['results'] = $results;
                    $response['affected_rows'] = $affected_rows;
                    $response['message'] = 'Запрос выполнен успешно';
                } else {
                    $error = $conn->error;
                    $response['message'] = 'Ошибка SQL: ' . $error;
                    logAction('sql_error', $error);
                }
                
                $conn->close();
            }
        }
    }
    
    if ($_POST['action'] === 'get_tables') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $conn = connectDB();
            
            // Получаем список таблиц
            $result = $conn->query("SHOW TABLES");
            $tables = [];
            
            while ($row = $result->fetch_array()) {
                $tables[] = $row[0];
            }
            
            $response['success'] = true;
            $response['tables'] = $tables;
            
            $conn->close();
        }
    }
    
    if ($_POST['action'] === 'get_table_data') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $table = $_POST['table'] ?? '';
            
            if (empty($table)) {
                $response['message'] = 'Имя таблицы не указано';
            } else {
                $conn = connectDB();
                
                // Получаем структуру таблицы
                $result = $conn->query("DESCRIBE `$table`");
                $columns = [];
                
                while ($row = $result->fetch_assoc()) {
                    $columns[] = [
                        'name' => $row['Field'],
                        'type' => $row['Type'],
                        'nullable' => $row['Null'] === 'YES',
                        'key' => $row['Key'],
                        'default' => $row['Default'],
                        'extra' => $row['Extra']
                    ];
                }
                
                // Получаем данные таблицы (ограничиваем 100 строк для производительности)
                $data_result = $conn->query("SELECT * FROM `$table` LIMIT 100");
                $rows = [];
                
                while ($row = $data_result->fetch_assoc()) {
                    $rows[] = $row;
                }
                
                // Получаем количество строк в таблице
                $count_result = $conn->query("SELECT COUNT(*) as total FROM `$table`");
                $total_rows = $count_result->fetch_assoc()['total'];
                
                $response['success'] = true;
                $response['columns'] = $columns;
                $response['rows'] = $rows;
                $response['row_count'] = $data_result->num_rows;
                $response['total_rows'] = $total_rows;
                
                $conn->close();
            }
        }
    }
    
    if ($_POST['action'] === 'update_row') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $table = $_POST['table'] ?? '';
            $data = json_decode($_POST['data'] ?? '{}', true);
            $primary_key = $_POST['primary_key'] ?? 'id';
            $primary_value = $_POST['primary_value'] ?? '';
            
            if (empty($table) || empty($primary_value)) {
                $response['message'] = 'Недостаточно данных для обновления';
            } else {
                $conn = connectDB();
                
                // Строим SET часть запроса
                $set_parts = [];
                $types = '';
                $values = [];
                
                foreach ($data as $key => $value) {
                    if ($key !== $primary_key) {
                        $set_parts[] = "`$key` = ?";
                        $types .= 's';
                        $values[] = $value;
                    }
                }
                
                if (empty($set_parts)) {
                    $response['message'] = 'Нет данных для обновления';
                } else {
                    $set_clause = implode(', ', $set_parts);
                    $types .= 's';
                    $values[] = $primary_value;
                    
                    $sql = "UPDATE `$table` SET $set_clause WHERE `$primary_key` = ?";
                    $stmt = $conn->prepare($sql);
                    
                    if ($stmt) {
                        $stmt->bind_param($types, ...$values);
                        
                        if ($stmt->execute()) {
                            $response['success'] = true;
                            $response['message'] = 'Строка успешно обновлена';
                            $response['affected_rows'] = $stmt->affected_rows;
                            
                            // Логируем обновление
                            logAction('update_row', "Таблица: $table, ID: $primary_value");
                        } else {
                            $response['message'] = 'Ошибка выполнения запроса: ' . $stmt->error;
                        }
                        
                        $stmt->close();
                    } else {
                        $response['message'] = 'Ошибка подготовки запроса: ' . $conn->error;
                    }
                }
                
                $conn->close();
            }
        }
    }
    
    if ($_POST['action'] === 'delete_row') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $table = $_POST['table'] ?? '';
            $primary_key = $_POST['primary_key'] ?? 'id';
            $primary_value = $_POST['primary_value'] ?? '';
            
            if (empty($table) || empty($primary_value)) {
                $response['message'] = 'Недостаточно данных для удаления';
            } else {
                $conn = connectDB();
                
                $sql = "DELETE FROM `$table` WHERE `$primary_key` = ?";
                $stmt = $conn->prepare($sql);
                
                if ($stmt) {
                    $stmt->bind_param("s", $primary_value);
                    
                    if ($stmt->execute()) {
                        $response['success'] = true;
                        $response['message'] = 'Строка успешно удалена';
                        $response['affected_rows'] = $stmt->affected_rows;
                        
                        // Логируем удаление
                        logAction('delete_row', "Таблица: $table, ID: $primary_value");
                    } else {
                        $response['message'] = 'Ошибка выполнения запроса: ' . $stmt->error;
                    }
                    
                    $stmt->close();
                } else {
                    $response['message'] = 'Ошибка подготовки запроса: ' . $conn->error;
                }
                
                $conn->close();
            }
        }
    }
    
    if ($_POST['action'] === 'delete_table') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $table = $_POST['table'] ?? '';
            
            if (empty($table)) {
                $response['message'] = 'Имя таблицы не указано';
            } else {
                $conn = connectDB();
                
                $sql = "DROP TABLE IF EXISTS `$table`";
                
                if ($conn->query($sql)) {
                    $response['success'] = true;
                    $response['message'] = 'Таблица успешно удалена';
                    
                    // Логируем удаление таблицы
                    logAction('delete_table', "Таблица: $table");
                } else {
                    $response['message'] = 'Ошибка удаления таблицы: ' . $conn->error;
                }
                
                $conn->close();
            }
        }
    }
    
    if ($_POST['action'] === 'export_tables') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $tables = json_decode($_POST['tables'] ?? '[]', true);
            
            if (empty($tables)) {
                $response['message'] = 'Не выбраны таблицы для экспорта';
            } else {
                $conn = connectDB();
                
                // Начинаем формировать SQL файл
                $output = "-- SQL Export\n";
                $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
                $output .= "-- Database: " . DB_NAME . "\n\n";
                
                foreach ($tables as $table) {
                    // Получаем структуру таблицы
                    $result = $conn->query("SHOW CREATE TABLE `$table`");
                    if ($row = $result->fetch_assoc()) {
                        $output .= "--\n-- Table structure for table `$table`\n--\n\n";
                        $output .= $row['Create Table'] . ";\n\n";
                        
                        // Получаем данные таблицы
                        $output .= "--\n-- Dumping data for table `$table`\n--\n\n";
                        $data_result = $conn->query("SELECT * FROM `$table`");
                        
                        while ($data_row = $data_result->fetch_assoc()) {
                            $columns = array_map(function($col) {
                                return "`$col`";
                            }, array_keys($data_row));
                            
                            $values = array_map(function($val) use ($conn) {
                                if ($val === null) return 'NULL';
                                return "'" . $conn->real_escape_string($val) . "'";
                            }, array_values($data_row));
                            
                            $output .= "INSERT INTO `$table` (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $values) . ");\n";
                        }
                        $output .= "\n";
                    }
                }
                
                $conn->close();
                
                // Логируем экспорт
                logAction('export_tables', 'Экспортировано таблиц: ' . count($tables));
                
                // Возвращаем результат для скачивания
                $response['success'] = true;
                $response['message'] = 'Экспорт завершен';
                $response['filename'] = 'export_' . date('Y-m-d_H-i-s') . '.sql';
                $response['content'] = $output;
            }
        }
    }
    
    if ($_POST['action'] === 'import_sql') {
        if (!isset($_SESSION['authenticated']) || !$_SESSION['authenticated']) {
            $response['message'] = 'Неавторизованный доступ';
        } else {
            $sql_content = $_POST['sql_content'] ?? '';
            
            if (empty($sql_content)) {
                $response['message'] = 'SQL содержимое пусто';
            } else {
                $conn = connectDB();
                
                // Выполняем SQL запросы
                if ($conn->multi_query($sql_content)) {
                    do {
                        if ($result = $conn->store_result()) {
                            $result->free();
                        }
                    } while ($conn->more_results() && $conn->next_result());
                    
                    $response['success'] = true;
                    $response['message'] = 'SQL файл успешно импортирован';
                    
                    // Логируем импорт
                    logAction('import_sql', 'Импорт SQL файла');
                } else {
                    $response['message'] = 'Ошибка импорта: ' . $conn->error;
                }
                
                $conn->close();
            }
        }
    }
    
    // Отправляем JSON ответ
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Проверяем авторизацию для доступа к интерфейсу
$authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'];
$password_changed = isset($_SESSION['password_changed']) && $_SESSION['password_changed'];

// Получаем текущую страницу из URL
$current_page = isset($_GET['page']) ? $_GET['page'] : 'sql-editor';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL - Панель управления базой данных</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #7c3aed;
            --primary-dark: #6d28d9;
            --primary-light: #8b5cf6;
            --secondary-color: #0ea5e9;
            --secondary-dark: #0284c7;
            --background: #f8fafc;
            --surface: #ffffff;
            --error: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --on-primary: #ffffff;
            --on-secondary: #ffffff;
            --on-background: #1e293b;
            --on-surface: #1e293b;
            --border: #e2e8f0;
            --hover: #f1f5f9;
            --shadow: 0 1px 3px rgba(0,0,0,0.1);
            --shadow-heavy: 0 4px 6px -1px rgba(0,0,0,0.1);
            --radius: 8px;
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .dark-theme {
            --primary-color: #a78bfa;
            --primary-dark: #8b5cf6;
            --primary-light: #c4b5fd;
            --secondary-color: #38bdf8;
            --secondary-dark: #0ea5e9;
            --background: #0f172a;
            --surface: #1e293b;
            --error: #f87171;
            --warning: #fbbf24;
            --success: #34d399;
            --on-primary: #0f172a;
            --on-secondary: #0f172a;
            --on-background: #f1f5f9;
            --on-surface: #f1f5f9;
            --border: #334155;
            --hover: #2d3748;
            --shadow: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-heavy: 0 4px 6px -1px rgba(0,0,0,0.5);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: var(--transition);
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background-color: var(--background);
            color: var(--on-background);
            line-height: 1.6;
            overflow-x: hidden;
            min-height: 100vh;
            font-size: 14px;
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
            background-color: var(--surface);
            border-bottom: 1px solid var(--border);
            padding: 0 20px;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 60px;
            z-index: 1000;
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
            gap: 12px;
        }
        
        .menu-toggle {
            background: none;
            border: none;
            color: var(--on-surface);
            cursor: pointer;
            width: 36px;
            height: 36px;
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
            gap: 10px;
        }
        
        .logo-icon {
            font-size: 24px;
            color: var(--primary-color);
        }
        
        .logo-text h1 {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary-color);
            line-height: 1.2;
        }
        
        /* Улучшенный заголовок действий */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            position: relative;
        }
        
        .action-button {
            background: none;
            border: none;
            color: var(--on-surface);
            cursor: pointer;
            width: 36px;
            height: 36px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: background-color 0.2s;
            font-size: 20px;
        }
        
        .action-button:hover {
            background-color: var(--hover);
        }
        
        .action-button.with-text {
            width: auto;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 13px;
            gap: 6px;
            font-weight: 500;
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-dropdown {
            position: absolute;
            top: 100%;
            right: 0;
            background-color: var(--surface);
            color: var(--on-surface);
            border-radius: 8px;
            box-shadow: var(--shadow-heavy);
            min-width: 200px;
            overflow: hidden;
            z-index: 1001;
            margin-top: 8px;
            border: 1px solid var(--border);
            display: none;
        }
        
        .user-dropdown.active {
            display: block;
            animation: slideUp 0.2s;
        }
        
        .user-dropdown-item {
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid var(--border);
            font-size: 13px;
        }
        
        .user-dropdown-item:last-child {
            border-bottom: none;
        }
        
        .user-dropdown-item:hover {
            background-color: var(--hover);
        }
        
        /* Боковая панель */
        .sidebar {
            width: 260px;
            background-color: var(--surface);
            height: calc(100vh - 60px);
            position: fixed;
            left: 0;
            top: 60px;
            overflow-y: auto;
            z-index: 900;
            border-right: 1px solid var(--border);
            transform: translateX(-100%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-section {
            padding: 16px;
            border-bottom: 1px solid var(--border);
        }
        
        .sidebar-section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: pointer;
            padding: 6px 0;
            font-weight: 600;
            color: var(--on-surface);
            font-size: 14px;
        }
        
        .toggle-icon {
            transition: transform 0.3s;
            color: var(--primary-color);
            font-size: 18px;
        }
        
        .toggle-icon.expanded {
            transform: rotate(90deg);
        }
        
        .table-list {
            list-style: none;
            margin-top: 6px;
            display: none;
        }
        
        .table-list.expanded {
            display: block;
        }
        
        .table-item {
            padding: 8px 14px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 3px;
            font-size: 13px;
        }
        
        .table-item:hover {
            background-color: var(--hover);
        }
        
        .table-item.active {
            background-color: var(--primary-color);
            color: var(--on-primary);
        }
        
        .table-icon {
            font-size: 16px;
        }
        
        .sidebar-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 10px;
            cursor: pointer;
            transition: background-color 0.2s;
            font-weight: 500;
            color: var(--on-surface);
            border-bottom: 1px solid var(--border);
            font-size: 13px;
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
            margin-top: 60px;
            padding: 20px;
            min-height: calc(100vh - 60px);
            transition: margin-left 0.3s;
        }
        
        .main-content.with-sidebar {
            margin-left: 260px;
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
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .card-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Формы */
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--on-surface);
            font-size: 13px;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background-color: var(--surface);
            color: var(--on-surface);
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }
        
        textarea.form-control {
            min-height: 100px;
            resize: vertical;
            font-family: 'Roboto Mono', monospace;
            font-size: 13px;
            line-height: 1.5;
        }
        
        /* SQL редактор */
        .sql-toolbar {
            display: flex;
            gap: 6px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }
        
        .quick-sql-btn {
            background-color: rgba(124, 58, 237, 0.1);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            border-radius: 6px;
            padding: 6px 12px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 5px;
            font-weight: 500;
        }
        
        .quick-sql-btn:hover {
            background-color: rgba(124, 58, 237, 0.2);
        }
        
        /* Таблицы - уменьшен масштаб */
        .data-table-container {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid var(--border);
            margin-top: 16px;
            font-size: 12px;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }
        
        .data-table th {
            background-color: var(--hover);
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: var(--on-surface);
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
            font-size: 12px;
            white-space: nowrap;
        }
        
        .data-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
            font-size: 12px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .data-table tr:hover {
            background-color: var(--hover);
        }
        
        .data-table tr.editing {
            background-color: rgba(124, 58, 237, 0.05);
        }
        
        .edit-input {
            width: 100%;
            padding: 6px 10px;
            border: 1px solid var(--primary-color);
            border-radius: 4px;
            background-color: var(--surface);
            color: var(--on-surface);
            font-size: 12px;
        }
        
        .edit-input:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(124, 58, 237, 0.2);
        }
        
        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            white-space: nowrap;
        }
        
        .btn-primary {
            background-color: var(--primary-color);
            color: var(--on-primary);
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
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
            background-color: transparent;
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
            padding: 5px 10px;
            font-size: 12px;
        }
        
        .btn-icon {
            width: 32px;
            height: 32px;
            padding: 0;
            border-radius: 6px;
            font-size: 16px;
        }
        
        /* Модальные окна */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1100;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s;
        }
        
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background-color: var(--surface);
            border-radius: var(--radius);
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-heavy);
            transform: translateY(20px);
            transition: transform 0.3s;
        }
        
        .modal-overlay.active .modal-content {
            transform: translateY(0);
        }
        
        /* Уведомления */
        .notification-container {
            position: fixed;
            top: 70px;
            right: 16px;
            z-index: 1200;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .notification {
            background-color: var(--surface);
            border-radius: var(--radius);
            padding: 14px 18px;
            box-shadow: var(--shadow-heavy);
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 280px;
            border-left: 4px solid;
            transform: translateX(150%);
            transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid var(--border);
            font-size: 13px;
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
            font-size: 16px;
        }
        
        .notification-close:hover {
            opacity: 1;
        }
        
        /* Загрузчик */
        .loader {
            display: inline-block;
            width: 16px;
            height: 16px;
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
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            padding: 16px;
        }
        
        .auth-card {
            background-color: var(--surface);
            border-radius: var(--radius);
            padding: 32px;
            width: 100%;
            max-width: 380px;
            box-shadow: var(--shadow-heavy);
            animation: fadeIn 0.5s;
        }
        
        .auth-header {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .auth-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 16px;
            color: var(--primary-color);
        }
        
        .auth-title {
            font-size: 20px;
            font-weight: 600;
            color: var(--on-surface);
            margin-bottom: 6px;
        }
        
        .auth-subtitle {
            font-size: 13px;
            color: var(--on-surface);
            opacity: 0.7;
        }
        
        /* Переключатель темы */
        .theme-toggle {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        
        .theme-toggle input {
            display: none;
        }
        
        .toggle-switch {
            width: 46px;
            height: 24px;
            background-color: #ccc;
            border-radius: 12px;
            position: relative;
            transition: background-color 0.3s;
        }
        
        .dark-theme .toggle-switch {
            background-color: #6d28d9; /* Сиреневый цвет для темной темы */
        }
        
        .toggle-switch:before {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: white;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .dark-theme .toggle-switch:before {
            transform: translateX(22px);
        }
        
        /* Поле поиска */
        .search-box {
            position: relative;
            margin-bottom: 16px;
        }
        
        .search-input {
            width: 100%;
            padding: 8px 12px 8px 36px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 13px;
            background-color: var(--surface);
            color: var(--on-surface);
        }
        
        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--on-surface);
            opacity: 0.5;
            font-size: 16px;
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
                padding: 0 12px;
                height: 56px;
            }
            
            .main-content {
                margin-top: 56px;
                padding: 12px;
            }
            
            .logo-text h1 {
                font-size: 16px;
            }
            
            .action-button.with-text span:not(.material-icons) {
                display: none;
            }
            
            .action-button.with-text {
                padding: 6px;
            }
            
            .card {
                padding: 16px;
            }
            
            .notification {
                min-width: auto;
                width: calc(100vw - 32px);
            }
            
            .sidebar {
                width: 100%;
                height: calc(100vh - 56px);
                top: 56px;
            }
        }
        
        @media (max-width: 480px) {
            .auth-card {
                padding: 20px;
            }
        }
        
        /* Профиль настроек */
        .profile-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .profile-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px;
            background-color: var(--hover);
            border-radius: 6px;
            font-size: 13px;
        }
        
        .profile-label {
            font-weight: 500;
            color: var(--on-surface);
        }
        
        .profile-value {
            font-family: 'Roboto Mono', monospace;
            font-size: 12px;
            color: var(--primary-color);
            background-color: rgba(124, 58, 237, 0.1);
            padding: 3px 6px;
            border-radius: 4px;
        }
        
        /* Инлайн редактирование */
        .edit-actions {
            display: flex;
            gap: 6px;
        }
        
        .view-mode {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .edit-mode {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        /* Импорт/экспорт */
        .export-import-container {
            max-width: 600px;
            margin: 0 auto;
        }
        
        .operation-selector {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .operation-btn {
            flex: 1;
            padding: 12px;
            text-align: center;
            border: 2px solid var(--border);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .operation-btn:hover {
            background-color: var(--hover);
        }
        
        .operation-btn.active {
            border-color: var(--primary-color);
            background-color: rgba(124, 58, 237, 0.1);
            color: var(--primary-color);
        }
        
        .tables-selector {
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 16px;
        }
        
        .table-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .table-checkbox:hover {
            background-color: var(--hover);
        }
        
        .select-all {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background-color: var(--hover);
            border-radius: 6px;
            margin-bottom: 12px;
            cursor: pointer;
            font-weight: 500;
        }
        
        /* Конфирмационные окна */
        .confirm-dialog {
            text-align: center;
            padding: 20px;
        }
        
        .confirm-icon {
            font-size: 48px;
            color: var(--warning);
            margin-bottom: 16px;
        }
        
        .confirm-message {
            font-size: 16px;
            margin-bottom: 20px;
            color: var(--on-surface);
        }
        
        .confirm-actions {
            display: flex;
            justify-content: center;
            gap: 12px;
        }
        
        /* Улучшенные кнопки действий в таблице */
        .table-actions-header {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-bottom: 12px;
            flex-wrap: wrap;
        }
        
        .table-actions-header .btn {
            flex-shrink: 0;
        }
    </style>
</head>
<body class="<?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'dark-theme' : ''; ?>">
    <?php if (!$authenticated): ?>
    <!-- Экран авторизации -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <span class="material-icons" style="font-size: 40px;">database</span>
                </div>
                <h1 class="auth-title">SQL Панель управления</h1>
                <p class="auth-subtitle">Войдите в систему для управления базой данных</p>
            </div>
            <form id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="password">Пароль</label>
                    <input type="password" id="password" class="form-control" placeholder="Введите пароль" required>
                    <div style="font-size: 11px; color: var(--on-surface); opacity: 0.7; margin-top: 6px;">
                        Пароль по умолчанию: <strong>Gosha123</strong>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 16px;">
                    <span class="btn-text">Войти в систему</span>
                    <div class="loader" style="display: none; margin-left: 6px;"></div>
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
                        <h1>SQL</h1>
                    </div>
                </div>
            </div>
            
            <div class="header-actions">
                <div class="theme-toggle">
                    <span class="material-icons">light_mode</span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="themeToggle" <?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'checked' : ''; ?>>
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
        
        <div class="sidebar-item" id="sqlEditorBtn">
            <span class="material-icons">code</span>
            <span>SQL Редактор</span>
        </div>
        
        <div class="sidebar-item" id="exportImportBtn">
            <span class="material-icons">import_export</span>
            <span>Импорт/Экспорт</span>
        </div>
    </aside>
    
    <!-- Основной контент -->
    <main class="main-content" id="mainContent">
        <div class="container">
            <!-- Карточка SQL редактора -->
            <div class="card animated fadeIn" id="sqlEditorCard" style="<?php echo $current_page == 'sql-editor' ? '' : 'display: none;' ?>">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">
                            <span class="material-icons">code</span>
                            SQL Редактор
                        </h2>
                        <div style="font-size: 13px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;">
                            Выполняйте SQL запросы к базе данных
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
                    <button class="quick-sql-btn" data-sql="SELECT * FROM logs ORDER BY created_at DESC LIMIT 10;">
                        <span class="material-icons">history</span>
                        Логи
                    </button>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="sqlQuery">SQL запрос</label>
                    <textarea id="sqlQuery" class="form-control" placeholder="Введите SQL запрос...">SHOW TABLES;</textarea>
                    <div style="font-size: 11px; color: var(--on-surface); opacity: 0.7; margin-top: 6px;">
                        Используйте Ctrl+Enter для быстрого выполнения
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
                        <div style="font-size: 13px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;" id="tableSubtitle">
                            Просмотр и редактирование данных
                        </div>
                    </div>
                    <div class="table-actions-header">
                        <button class="btn btn-outline btn-small" id="deleteTableBtn">
                            <span class="material-icons">delete</span>
                            Удалить таблицу
                        </button>
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
            
            <!-- Карточка импорта/экспорта -->
            <div class="card animated fadeIn" id="exportImportCard" style="display: none;">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">
                            <span class="material-icons">import_export</span>
                            Импорт / Экспорт данных
                        </h2>
                        <div style="font-size: 13px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;">
                            Импортируйте и экспортируйте данные в формате SQL
                        </div>
                    </div>
                </div>
                
                <div class="export-import-container">
                    <div class="operation-selector">
                        <div class="operation-btn active" id="exportBtn">
                            Экспорт данных
                        </div>
                        <div class="operation-btn" id="importBtn">
                            Импорт данных
                        </div>
                    </div>
                    
                    <!-- Экспорт данных -->
                    <div id="exportSection">
                        <div class="form-group">
                            <label class="form-label">Выберите таблицы для экспорта:</label>
                            <div class="tables-selector" id="exportTablesList">
                                <!-- Список таблиц будет загружен через AJAX -->
                            </div>
                            <div class="select-all" id="selectAllExport">
                                <input type="checkbox" id="selectAllExportCheckbox" checked>
                                <label for="selectAllExportCheckbox">Выбрать все</label>
                            </div>
                        </div>
                        <button class="btn btn-primary" id="exportDataBtn" style="width: 100%;">
                            <span class="material-icons">download</span>
                            Экспортировать выбранные таблицы
                        </button>
                    </div>
                    
                    <!-- Импорт данных -->
                    <div id="importSection" style="display: none;">
                        <div class="form-group">
                            <label class="form-label">Вставьте SQL код для импорта:</label>
                            <textarea id="importSqlContent" class="form-control" placeholder="Вставьте SQL код здесь..." rows="10"></textarea>
                            <div style="font-size: 11px; color: var(--on-surface); opacity: 0.7; margin-top: 6px;">
                                Поддерживается любой валидный SQL код (CREATE TABLE, INSERT, etc.)
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Или загрузите SQL файл:</label>
                            <input type="file" id="sqlFileUpload" class="form-control" accept=".sql,.txt">
                        </div>
                        <button class="btn btn-primary" id="importDataBtn" style="width: 100%;">
                            <span class="material-icons">upload</span>
                            Импортировать SQL данные
                        </button>
                    </div>
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
            <div class="modal-body" style="padding: 20px;">
                <?php if (!$password_changed): ?>
                <div class="notification info" style="margin-bottom: 16px; position: relative; top: 0; right: 0; transform: none;">
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
                        <div style="font-size: 11px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;">
                            Минимум 6 символов
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="confirmPassword">Подтверждение пароля</label>
                        <input type="password" id="confirmPassword" class="form-control" required minlength="6">
                    </div>
                </form>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-outline" id="cancelChangePassword">Отмена</button>
                <button class="btn btn-primary" id="savePasswordBtn">
                    <span class="btn-text">Сохранить</span>
                    <div class="loader" style="display: none; margin-left: 6px;"></div>
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
            <div class="modal-body" style="padding: 20px;">
                <div class="profile-info">
                    <div class="profile-item">
                        <span class="profile-label">Хост базы данных:</span>
                        <span class="profile-value"><?php echo DB_HOST; ?></span>
                    </div>
                    <div class="profile-item">
                        <span class="profile-label">Пользователь БД:</span>
                        <span class="profile-value"><?php echo DB_USER; ?></span>
                    </div>
                    <div class="profile-item">
                        <span class="profile-label">Имя базы данных:</span>
                        <span class="profile-value"><?php echo DB_NAME; ?></span>
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
                
                <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--border);">
                    <h4 style="font-size: 15px; font-weight: 600; margin-bottom: 12px; color: var(--on-surface);">
                        <span class="material-icons" style="font-size: 17px; vertical-align: middle; margin-right: 6px;">info</span>
                        Информация о системе
                    </h4>
                    <div style="font-size: 13px; color: var(--on-surface); opacity: 0.8; line-height: 1.6;">
                        <p>Версия PHP: <?php echo phpversion(); ?></p>
                        <p>Тип базы данных: MySQL</p>
                        <p>Версия SQL панели: 2.1</p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-outline" id="closeProfileSettingsBtn">Закрыть</button>
                <button class="btn btn-primary" onclick="showChangePasswordModal()">
                    <span class="material-icons" style="font-size: 17px; margin-right: 5px;">password</span>
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
            <div class="modal-body" style="padding: 20px;">
                <form id="addRowForm">
                    <!-- Поля формы будут динамически добавлены -->
                </form>
            </div>
            <div class="modal-footer" style="padding: 16px 20px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px;">
                <button class="btn btn-outline" id="cancelAddRow">Отмена</button>
                <button class="btn btn-primary" id="saveNewRowBtn">
                    <span class="btn-text">Сохранить</span>
                    <div class="loader" style="display: none; margin-left: 6px;"></div>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно подтверждения удаления строки -->
    <div class="modal-overlay" id="confirmDeleteRowModal">
        <div class="modal-content">
            <div class="confirm-dialog">
                <div class="confirm-icon">
                    <span class="material-icons">warning</span>
                </div>
                <div class="confirm-message" id="confirmDeleteRowMessage">
                    Вы уверены, что хотите удалить эту строку?
                </div>
                <div class="confirm-actions">
                    <button class="btn btn-outline" id="cancelDeleteRow">Отмена</button>
                    <button class="btn btn-danger" id="confirmDeleteRowBtn">
                        <span class="material-icons">delete</span>
                        Удалить
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно подтверждения удаления таблицы -->
    <div class="modal-overlay" id="confirmDeleteTableModal">
        <div class="modal-content">
            <div class="confirm-dialog">
                <div class="confirm-icon">
                    <span class="material-icons">warning</span>
                </div>
                <div class="confirm-message" id="confirmDeleteTableMessage">
                    Вы уверены, что хотите удалить таблицу?
                </div>
                <div class="confirm-actions">
                    <button class="btn btn-outline" id="cancelDeleteTable">Отмена</button>
                    <button class="btn btn-danger" id="confirmDeleteTableBtn">
                        <span class="material-icons">delete</span>
                        Удалить таблицу
                    </button>
                </div>
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
        let currentPage = '<?php echo $current_page; ?>';
        let deleteRowData = null;
        let deleteTableData = null;
        
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
                            location.href = '?page=sql-editor';
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
        
        // Функция инициализации основной панели
        function initMainPanel() {
            // Инициализация таймера активного времени
            updateActiveTime();
            activeTimeInterval = setInterval(updateActiveTime, 60000);
            
            // Обновляем счетчик запросов
            document.getElementById('queryCount').textContent = queryCount;
            
            // Загружаем список таблиц
            loadTables();
            
            // Инициализация элементов интерфейса
            initUIElements();
            
            // Загружаем текущую страницу
            loadPage(currentPage);
            
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
            
            if (document.getElementById('activeTime')) {
                const activeTimeEl = document.getElementById('activeTime');
            if (activeTimeEl) {
                activeTimeEl.textContent = timeStr;
            }
            }
        }
        
        // Функция загрузки страницы
        function loadPage(page) {
            // Скрываем все карточки
            const queryCountEl = document.getElementById('sqlEditorCard');
            if (queryCountEl) {
                queryCountEl.style.display = 'none';
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('exportImportCard').style.display = 'none';
            }
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('');
            
            if (page === 'sql-editor') {
                document.getElementById('sqlEditorCard').style.display = 'block';
                updateActiveMenuItem('sqlEditorBtn');
                updateUrl('sql-editor');
            } else if (page === 'export-import') {
                document.getElementById('exportImportCard').style.display = 'block';
                updateActiveMenuItem('exportImportBtn');
                updateUrl('export-import');
                loadTablesForExport();
            } else if (page.startsWith('table-')) {
                const table = page.replace('table-', '');
                loadTableData(table);
            }
        }
        
        // Функция обновления URL
        function updateUrl(page) {
            const url = new URL(window.location);
            url.searchParams.set('page', page);
            window.history.replaceState({}, '', url);
        }
        
        // Функция инициализации элементов UI
        function initUIElements() {
            // Кнопка меню для мобильных устройств
            const menuToggle = document.getElementById('menuToggle');
            if (menuToggle) {
                menuToggle.addEventListener('click', function() {
                    const sidebar = document.getElementById('sidebar');
                    if (sidebar) {
                        sidebar.classList.toggle('active');
                    }
                });
            }
            
            // Меню пользователя
            const userMenuToggle = document.getElementById('userMenuToggle');
            if (userMenuToggle) {
                userMenuToggle.addEventListener('click', function(e) {
                    e.stopPropagation();
                    const userDropdown = document.getElementById('userDropdown');
                    if (userDropdown) {
                        userDropdown.classList.toggle('active');
                    }
                });
            }
            
            // Закрытие меню пользователя при клике вне его
            document.addEventListener('click', function(e) {
                const userDropdown = document.getElementById('userDropdown');
                const userMenuToggle = document.getElementById('userMenuToggle');
                
                if (userMenuToggle && userDropdown) {
                    if (!userMenuToggle.contains(e.target) && !userDropdown.contains(e.target)) {
                        userDropdown.classList.remove('active');
                    }
                }
            });
            
            // Кнопка выхода
            const logoutBtn = document.getElementById('logoutBtn');
            if (logoutBtn) {
                logoutBtn.addEventListener('click', function() {
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
                            location.href = '?page=sql-editor';
                        }, 1000);
                    });
                });
            }
            
            // Кнопка смены пароля
            const changePasswordBtn = document.getElementById('changePasswordBtn');
            if (changePasswordBtn) {
                changePasswordBtn.addEventListener('click', function() {
                    const userDropdown = document.getElementById('userDropdown');
                    if (userDropdown) {
                        userDropdown.classList.remove('active');
                    }
                    showChangePasswordModal();
                });
            }
            
            // Кнопка настроек профиля
            const profileSettingsBtn = document.getElementById('profileSettingsBtn');
            if (profileSettingsBtn) {
                profileSettingsBtn.addEventListener('click', function() {
                    const userDropdown = document.getElementById('userDropdown');
                    if (userDropdown) {
                        userDropdown.classList.remove('active');
                    }
                    showProfileSettingsModal();
                });
            }
            
            // Закрытие модального окна смены пароля
            const closeChangePasswordModal = document.getElementById('closeChangePasswordModal');
            if (closeChangePasswordModal) {
                closeChangePasswordModal.addEventListener('click', function() {
                    hideChangePasswordModal();
                });
            }
            
            const cancelChangePassword = document.getElementById('cancelChangePassword');
            if (cancelChangePassword) {
                cancelChangePassword.addEventListener('click', function() {
                    hideChangePasswordModal();
                });
            }
            
            // Форма смены пароля
            const savePasswordBtn = document.getElementById('savePasswordBtn');
            if (savePasswordBtn) {
                savePasswordBtn.addEventListener('click', function() {
                    const currentPassword = document.getElementById('currentPassword');
                    const newPassword = document.getElementById('newPassword');
                    const confirmPassword = document.getElementById('confirmPassword');
                    
                    if (!currentPassword || !newPassword || !confirmPassword) {
                        showNotification('Заполните все поля', 'error');
                        return;
                    }
                    
                    const currentPasswordVal = currentPassword.value;
                    const newPasswordVal = newPassword.value;
                    const confirmPasswordVal = confirmPassword.value;
                    
                    if (!currentPasswordVal || !newPasswordVal || !confirmPasswordVal) {
                        showNotification('Заполните все поля', 'error');
                        return;
                    }
                    
                    if (newPasswordVal.length < 6) {
                        showNotification('Новый пароль должен содержать минимум 6 символов', 'error');
                        return;
                    }
                    
                    if (newPasswordVal !== confirmPasswordVal) {
                        showNotification('Пароли не совпадают', 'error');
                        return;
                    }
                    
                    const btnText = savePasswordBtn.querySelector('.btn-text');
                    const loader = savePasswordBtn.querySelector('.loader');
                    
                    // Показываем лоадер
                    if (btnText) btnText.style.display = 'none';
                    if (loader) loader.style.display = 'inline-block';
                    savePasswordBtn.disabled = true;
                    
                    // Отправляем запрос на смену пароля
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: new URLSearchParams({
                            action: 'change_password',
                            current_password: currentPasswordVal,
                            new_password: newPasswordVal,
                            confirm_password: confirmPasswordVal
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showNotification(data.message, 'success');
                            hideChangePasswordModal();
                            if (currentPassword) currentPassword.value = '';
                            if (newPassword) newPassword.value = '';
                            if (confirmPassword) confirmPassword.value = '';
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
                        if (btnText) btnText.style.display = 'inline';
                        if (loader) loader.style.display = 'none';
                        savePasswordBtn.disabled = false;
                    });
                });
            }
            
            // Кнопка выполнения SQL запроса
            const executeSqlBtn = document.getElementById('executeSqlBtn');
            if (executeSqlBtn) {
                executeSqlBtn.addEventListener('click', function() {
                    const sqlQueryEl = document.getElementById('sqlQuery');
                    if (sqlQueryEl) {
                        const sqlQuery = sqlQueryEl.value.trim();
                        if (sqlQuery) {
                            executeSql(sqlQuery);
                            queryCount++;
                            localStorage.setItem('queryCount', queryCount);
                        }
                    }
                });
            }
            
            // Кнопки быстрых SQL запросов
            document.querySelectorAll('.quick-sql-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const sql = this.dataset.sql;
                    const sqlQueryEl = document.getElementById('sqlQuery');
                    if (sqlQueryEl) {
                        sqlQueryEl.value = sql;
                        const executeSqlBtnEl = document.getElementById('executeSqlBtn');
                        if (executeSqlBtnEl) {
                            executeSqlBtnEl.click();
                        }
                    }
                });
            });
            
            // Кнопка обновления таблицы
            const refreshTableBtn = document.getElementById('refreshTableBtn');
            if (refreshTableBtn) {
                refreshTableBtn.addEventListener('click', function() {
                    if (currentTable) {
                        loadTableData(currentTable);
                        showNotification('Таблица обновлена', 'success');
                    }
                });
            }
            
            // Кнопка добавления строки
            const addRowBtn = document.getElementById('addRowBtn');
            if (addRowBtn) {
                addRowBtn.addEventListener('click', function() {
                    if (currentTable) {
                        showAddRowForm(currentTable);
                    }
                });
            }
            
            // Закрытие модального окна добавления строки
            const closeAddRowModal = document.getElementById('closeAddRowModal');
            if (closeAddRowModal) {
                closeAddRowModal.addEventListener('click', function() {
                    hideAddRowModal();
                });
            }
            
            const cancelAddRow = document.getElementById('cancelAddRow');
            if (cancelAddRow) {
                cancelAddRow.addEventListener('click', function() {
                    hideAddRowModal();
                });
            }
            
            // Кнопки бокового меню
            const tablesHeader = document.getElementById('tablesHeader');
            if (tablesHeader) {
                tablesHeader.addEventListener('click', function() {
                    const tableList = document.getElementById('tableList');
                    const toggleIcon = this.querySelector('.toggle-icon');
                    
                    if (tableList) {
                        tableList.classList.toggle('expanded');
                    }
                    if (toggleIcon) {
                        toggleIcon.classList.toggle('expanded');
                    }
                });
            }
            
            const statisticsBtn = document.getElementById('statisticsBtn');
            if (statisticsBtn) {
                statisticsBtn.addEventListener('click', function() {
                    showStatistics();
                });
            }
            
            const sqlEditorBtn = document.getElementById('sqlEditorBtn');
            if (sqlEditorBtn) {
                sqlEditorBtn.addEventListener('click', function() {
                    showSqlEditor();
                });
            }
            
            // Закрытие модального окна настроек профиля
            const closeProfileSettingsModal = document.getElementById('closeProfileSettingsModal');
            if (closeProfileSettingsModal) {
                closeProfileSettingsModal.addEventListener('click', function() {
                    hideProfileSettingsModal();
                });
            }
            
            const closeProfileSettingsBtn = document.getElementById('closeProfileSettingsBtn');
            if (closeProfileSettingsBtn) {
                closeProfileSettingsBtn.addEventListener('click', function() {
                    hideProfileSettingsModal();
                });
            }
            
            // Обработка нажатия Ctrl+Enter в SQL редакторе
            const sqlQueryEl = document.getElementById('sqlQuery');
            if (sqlQueryEl) {
                sqlQueryEl.addEventListener('keydown', function(e) {
                    if (e.ctrlKey && e.key === 'Enter') {
                        e.preventDefault();
                        const executeSqlBtnEl = document.getElementById('executeSqlBtn');
                        if (executeSqlBtnEl) {
                            executeSqlBtnEl.click();
                        }
                    }
                });
            }
        }
        
        // Функция показа модального окна смены пароля
        function showChangePasswordModal() {
            const modal = document.getElementById('changePasswordModal');
            if (modal) {
                modal.classList.add('active');
            }
        }
        
        // Функция скрытия модального окна смены пароля
        function hideChangePasswordModal() {
            const modal = document.getElementById('changePasswordModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }
        
        // Функция показа модального окна настроек профиля
        function showProfileSettingsModal() {
            const modal = document.getElementById('profileSettingsModal');
            if (modal) {
                modal.classList.add('active');
            }
        }
        
        // Функция скрытия модального окна настроек профиля
        function hideProfileSettingsModal() {
            const modal = document.getElementById('profileSettingsModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }
        
        // Функция показа модального окна добавления строки
        function showAddRowModal() {
            const modal = document.getElementById('addRowModal');
            if (modal) {
                modal.classList.add('active');
            }
        }
        
        // Функция скрытия модального окна добавления строки
        function hideAddRowModal() {
            const modal = document.getElementById('addRowModal');
            if (modal) {
                modal.classList.remove('active');
            }
        }
        
        // Функция показа статистики
        function showStatistics() {
            const tableDataCard = document.getElementById('tableDataCard');
            const sqlEditorCard = document.getElementById('sqlEditorCard');
            const statsGrid = document.getElementById('statsGrid');
            
            if (tableDataCard) tableDataCard.style.display = 'none';
            if (sqlEditorCard) sqlEditorCard.style.display = 'block';
            if (statsGrid) statsGrid.style.display = 'grid';
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('statisticsBtn');
            
            // Закрываем боковую панель на мобильных
            if (window.innerWidth <= 1200) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.classList.remove('active');
                }
            }
        }
        
        // Функция показа SQL редактора
        function showSqlEditor() {
            const tableDataCard = document.getElementById('tableDataCard');
            const sqlEditorCard = document.getElementById('sqlEditorCard');
            const statsGrid = document.getElementById('statsGrid');
            
            if (tableDataCard) tableDataCard.style.display = 'none';
            if (sqlEditorCard) sqlEditorCard.style.display = 'block';
            if (statsGrid) statsGrid.style.display = 'none';
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('sqlEditorBtn');
            
            // Закрываем боковую панель на мобильных
            if (window.innerWidth <= 1200) {
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.classList.remove('active');
                }
            }
        }
        
        // Функция обновления активного элемента меню
        function updateActiveMenuItem(activeId) {
            // Убираем активный класс у всех элементов
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.classList.remove('active');
            });
            
            document.querySelectorAll('.table-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Добавляем активный класс к выбранному элементу
            if (activeId) {
                const element = document.getElementById(activeId);
                if (element) {
                    element.classList.add('active');
                }
            }
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
                    const tableCountEl = document.getElementById('tableCount');
                    if (tableCountEl) {
                        tableCountEl.textContent = data.tables.length;
                    }
                    
                    // Загружаем статистику по строкам
                    loadTotalRows(data.tables);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки списка таблиц', 'error');
            });
        }
        
        // Функция загрузки общего количества строк
        function loadTotalRows(tables) {
            let totalRows = 0;
            let completedRequests = 0;
            
            tables.forEach(table => {
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
                        totalRows += data.total_rows || 0;
                        completedRequests++;
                        
                        if (completedRequests === tables.length) {
                            const totalRowsEl = document.getElementById('totalRows');
                            if (totalRowsEl) {
                                totalRowsEl.textContent = totalRows.toLocaleString();
                            }
                        }
                    }
                })
                .catch(() => {
                    completedRequests++;
                });
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
                li.id = `table-item-${table}`;
                
                li.innerHTML = `
                    <span class="material-icons table-icon">table_chart</span>
                    <span>${table}</span>
                `;
                
                li.addEventListener('click', function() {
                    loadPage(`table-${table}`);
                    
                    // Закрываем боковую панель на мобильных устройствах
                    if (window.innerWidth <= 1200) {
                        document.getElementById('sidebar').classList.remove('active');
                    }
                });
                
                tableList.appendChild(li);
            });
        }
        
        // Функция отрисовки списка таблиц для экспорта
        function renderExportTablesList(tables) {
            const exportTablesList = document.getElementById('exportTablesList');
            exportTablesList.innerHTML = '';
            
            tables.forEach(table => {
                const div = document.createElement('div');
                div.className = 'table-checkbox';
                
                div.innerHTML = `
                    <input type="checkbox" id="export_${table}" value="${table}" checked>
                    <label for="export_${table}">${table}</label>
                `;
                
                exportTablesList.appendChild(div);
            });
        }
        
        // Функция загрузки данных таблицы
        function loadTableData(table) {
            currentTable = table;
            
            // Показываем карточку с данными таблицы
            const tableDataCard = document.getElementById('tableDataCard');
            const sqlEditorCard = document.getElementById('sqlEditorCard');
            const statsGrid = document.getElementById('statsGrid');
            const tableTitle = document.getElementById('tableTitle');
            const container = document.getElementById('tableDataContainer');
            
            if (tableDataCard) tableDataCard.style.display = 'block';
            if (sqlEditorCard) sqlEditorCard.style.display = 'none';
            if (statsGrid) statsGrid.style.display = 'none';
            
            // Обновляем заголовок
            if (tableTitle) tableTitle.textContent = table;
            document.getElementById('tableTitle').textContent = table;
            
            // Обновляем активный элемент в меню
            updateActiveMenuItem(`table-item-${table}`);
            
            // Обновляем URL
            updateUrl(`table-${table}`);
            
            // Показываем лоадер
            const container = document.getElementById('tableDataContainer');
            container.innerHTML = `
                <div style="text-align: center; padding: 40px 20px;">
                    <div class="loader" style="width: 32px; height: 32px; margin: 0 auto; border-width: 3px;"></div>
                    <p style="margin-top: 16px; color: var(--on-surface); opacity: 0.7;">Загрузка данных таблицы...</p>
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
                    if (container) {
                        container.innerHTML = `
                            <div style="text-align: center; padding: 40px 20px;">
                                <span class="material-icons" style="font-size: 40px; color: var(--on-surface); opacity: 0.3;">error</span>
                                <p style="margin-top: 12px; color: var(--on-surface); opacity: 0.7;">Ошибка загрузки данных таблицы</p>
                            </div>
                        `;
                    }
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
            
            if (!container) {
                console.warn('Table data container not found');
                return;
            }
            
            if (rows.length === 0) {
                container.innerHTML = `
                    <div style="text-align: center; padding: 40px 20px;">
                        <span class="material-icons" style="font-size: 40px; color: var(--on-surface); opacity: 0.3;">table_rows</span>
                        <p style="margin-top: 12px; color: var(--on-surface); opacity: 0.7;">Таблица "${table}" пуста</p>
                        <button class="btn btn-primary" onclick="showAddRowForm('${table}')" style="margin-top: 12px;">
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
            html += '<th style="width: 80px;">Действия</th>';
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
                html += `<span class="material-icons" style="font-size: 14px;">edit</span>`;
                html += `</button>`;
                html += `<button class="btn btn-outline btn-icon btn-small delete-row-btn" data-row-id="${rowId}" title="Удалить">`;
                html += `<span class="material-icons" style="font-size: 14px;">delete</span>`;
                html += `</button>`;
                html += `</div>`;
                html += `<div class="edit-mode" style="display: none;">`;
                html += `<button class="btn btn-success btn-icon btn-small save-row-btn" data-row-id="${rowId}" title="Сохранить">`;
                html += `<span class="material-icons" style="font-size: 14px;">save</span>`;
                html += `</button>`;
                html += `<button class="btn btn-outline btn-icon btn-small cancel-edit-btn" data-row-id="${rowId}" title="Отменить">`;
                html += `<span class="material-icons" style="font-size: 14px;">close</span>`;
                html += `</button>`;
                html += `</div>`;
                html += `</td>`;
                
                // Ячейки с данными
                columns.forEach(column => {
                    let value = row[column.name];
                    let displayValue = value;
                    
                    if (value === null || value === '') {
                        displayValue = '<span style="color: var(--on-surface); opacity: 0.3; font-style: italic;">NULL</span>';
                    } else if (typeof value === 'string' && value.length > 30) {
                        displayValue = value.substring(0, 30) + '...';
                    }
                    
                    html += `<td data-column="${column.name}" data-original-value="${escapeHtml(value || '')}">`;
                    html += `<div class="view-cell">${displayValue}</div>`;
                    html += `<div class="edit-cell" style="display: none;">`;
                    
                    // Определяем тип поля для редактирования
                    if (column.type.includes('text') || column.type.includes('varchar')) {
                        html += `<textarea class="edit-input" data-column="${column.name}" style="width: 100%; height: 50px;">${escapeHtml(value || '')}</textarea>`;
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
            
            // Добавляем обработчики событий для кнопок удаления
            document.querySelectorAll('.delete-row-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const rowId = this.dataset.rowId;
                    const row = document.getElementById(`row-${rowId}`);
                    if (!row) return;
                    
                    // Находим первичный ключ и значение
                    let primaryKey = 'id';
                    let primaryValue = null;
                    
                    // Пытаемся найти первичный ключ
                    const idCell = row.querySelector('td[data-column="id"]');
                    if (idCell) {
                        primaryValue = idCell.dataset.originalValue;
                    } else {
                        // Если нет колонки id, берем первую колонку
                        const firstCell = row.querySelector('td[data-column]');
                        if (firstCell) {
                            primaryKey = firstCell.dataset.column;
                            primaryValue = firstCell.dataset.originalValue;
                        }
                    }
                    
                    if (primaryValue) {
                        showDeleteRowConfirm(table, primaryKey, primaryValue, row);
                    }
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
        
        // Функция удаления строки
        function deleteRow(table, primaryKey, primaryValue) {
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
                    showNotification(data.message, 'success');
                    // Перезагружаем данные таблицы
                    loadTableData(table);
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при удалении строки', 'error');
            });
        }
        
        // Функция удаления таблицы
        function deleteTable(table) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'delete_table',
                    table: table
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    // Обновляем список таблиц
                    loadTables();
                    loadTablesForExport();
                    // Возвращаемся на главную страницу
                    loadPage('sql-editor');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при удалении таблицы', 'error');
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
            saveBtn.innerHTML = '<div class="loader" style="width: 14px; height: 14px; border-width: 2px;"></div>';
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
                            } else if (typeof input.value === 'string' && input.value.length > 30) {
                                displayValue = input.value.substring(0, 30) + '...';
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
        
        // Функция отображения формы добавления строки
        function showAddRowForm(table) {
            const addModalTitle = document.getElementById('addModalTitle');
            if (!addModalTitle) {
                console.warn('Add modal title element not found');
                return;
            }
            
            addModalTitle.textContent = `Добавление строки в таблицу "${table}"`;
            
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
            
            if (!form) {
                console.warn('Add row form element not found');
                return;
            }
            
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
                    input.rows = 2;
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
            
            // Строим SQL запрос для добавления
            const columnsClause = Object.keys(formData)
                .map(key => `\`${key}\``)
                .join(', ');
            
            const valuesClause = Object.values(formData)
                .map(value => value === null ? 'NULL' : `'${escapeSql(value)}'`)
                .join(', ');
            
            const sql = `INSERT INTO \`${table}\` (${columnsClause}) VALUES (${valuesClause});`;
            
            const saveBtn = document.getElementById('saveNewRowBtn');
            const btnText = saveBtn.querySelector('.btn-text');
            const loader = saveBtn.querySelector('.loader');
            
            // Показываем лоадер
            btnText.style.display = 'none';
            loader.style.display = 'inline-block';
            saveBtn.disabled = true;
            
            // Выполняем SQL запрос
            executeSql(sql, true).then(success => {
                if (success) {
                    hideAddRowModal();
                    
                    // Перезагружаем данные таблицы
                    if (currentTable === table) {
                        loadTableData(table);
                    }
                    
                    showNotification('Строка успешно добавлена', 'success');
                }
                
                // Скрываем лоадер
                btnText.style.display = 'inline';
                loader.style.display = 'none';
                saveBtn.disabled = false;
            });
        }
        
        // Функция экспорта данных
        function exportData() {
            const selectedTables = [];
            document.querySelectorAll('#exportTablesList input[type="checkbox"]:checked').forEach(checkbox => {
                selectedTables.push(checkbox.value);
            });
            
            if (selectedTables.length === 0) {
                showNotification('Выберите хотя бы одну таблицу для экспорта', 'warning');
                return;
            }
            
            const exportBtn = document.getElementById('exportDataBtn');
            const originalContent = exportBtn.innerHTML;
            exportBtn.innerHTML = '<div class="loader" style="width: 16px; height: 16px; border-width: 2px; margin-right: 6px;"></div> Экспорт...';
            exportBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'export_tables',
                    tables: JSON.stringify(selectedTables)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Создаем и скачиваем файл
                    const blob = new Blob([data.content], { type: 'application/sql' });
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    window.URL.revokeObjectURL(url);
                    
                    showNotification('Экспорт завершен успешно', 'success');
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при экспорте', 'error');
            })
            .finally(() => {
                exportBtn.innerHTML = originalContent;
                exportBtn.disabled = false;
            });
        }
        
        // Функция импорта данных
        function importData() {
            const sqlContent = document.getElementById('importSqlContent').value.trim();
            
            if (!sqlContent) {
                showNotification('Введите SQL код для импорта', 'warning');
                return;
            }
            
            const importBtn = document.getElementById('importDataBtn');
            const originalContent = importBtn.innerHTML;
            importBtn.innerHTML = '<div class="loader" style="width: 16px; height: 16px; border-width: 2px; margin-right: 6px;"></div> Импорт...';
            importBtn.disabled = true;
            
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'import_sql',
                    sql_content: sqlContent
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showNotification(data.message, 'success');
                    document.getElementById('importSqlContent').value = '';
                    document.getElementById('sqlFileUpload').value = '';
                    
                    // Обновляем список таблиц
                    loadTables();
                    loadTablesForExport();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка сети при импорте', 'error');
            })
            .finally(() => {
                importBtn.innerHTML = originalContent;
                importBtn.disabled = false;
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
                            showNotification(`Запрос выполнен успешно. Затронуто строк: ${data.affected_rows}`, 'success');
                        }
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
            
            if (!resultsDiv) {
                console.warn('SQL results container not found');
                return;
            }
            
            resultsDiv.innerHTML = '';
            
            // Показываем выполненный запрос
            const queryCard = document.createElement('div');
            queryCard.className = 'card';
            queryCard.style.marginBottom = '12px';
            queryCard.innerHTML = `
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                    <span class="material-icons" style="color: var(--primary-color);">code</span>
                    <h3 style="font-size: 15px; font-weight: 600;">Выполненный запрос</h3>
                </div>
                <div style="background-color: var(--hover); padding: 10px; border-radius: 6px; font-family: 'Roboto Mono', monospace; font-size: 12px; overflow-x: auto;">
                    ${escapeHtml(sql)}
                </div>
                <div style="margin-top: 10px; font-size: 13px; color: var(--on-surface); opacity: 0.7;">
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
                    tableCard.style.marginTop = index > 0 ? '12px' : '0';
                    
                    tableCard.innerHTML = `
                        <div class="card-header" style="padding: 12px; margin-bottom: 12px;">
                            <h3 class="card-title" style="font-size: 15px;">
                                <span class="material-icons">table_chart</span>
                                Результат ${index + 1}
                            </h3>
                            <span style="font-size: 13px; color: var(--on-surface); opacity: 0.7;">Найдено строк: ${result.row_count}</span>
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
                                                        (typeof row[col] === 'string' && row[col].length > 30 ? 
                                                            row[col].substring(0, 30) + '...' : escapeHtml(row[col])) : 
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
            
            if (!notificationContainer) {
                console.warn('Notification container not found');
                return;
            }
            
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