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
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
                $response['success'] = true;
                $response['password_changed'] = $_SESSION['password_changed'];
                $response['message'] = 'Авторизация успешна';
            } else {
                $response['message'] = 'Неверный пароль';
            }
        } else {
            $response['message'] = 'Ошибка базы данных';
        }
        
        $stmt->close();
        $conn->close();
    }
    
    if ($_POST['action'] === 'logout') {
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
                    $response['message'] = 'Ошибка SQL: ' . $conn->error;
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
    
    // Отправляем JSON ответ
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Проверяем авторизацию для доступа к интерфейсу
$authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'];
$password_changed = isset($_SESSION['password_changed']) && $_SESSION['password_changed'];
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
            --primary-color: #2563eb;
            --primary-dark: #1d4ed8;
            --primary-light: #60a5fa;
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
            --primary-color: #3b82f6;
            --primary-dark: #2563eb;
            --primary-light: #60a5fa;
            --secondary-color: #0ea5e9;
            --secondary-dark: #0284c7;
            --background: #0f172a;
            --surface: #1e293b;
            --error: #ef4444;
            --warning: #f59e0b;
            --success: #10b981;
            --on-primary: #ffffff;
            --on-secondary: #ffffff;
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
            background-color: var(--surface);
            color: var(--on-surface);
            border-radius: 8px;
            box-shadow: var(--shadow-heavy);
            min-width: 240px;
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
            width: 280px;
            background-color: var(--surface);
            height: calc(100vh - 64px);
            position: fixed;
            left: 0;
            top: 64px;
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
            margin-left: 280px;
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
            border-radius: 6px;
            font-size: 14px;
            background-color: var(--surface);
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
            font-family: 'Roboto Mono', monospace;
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
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            border-radius: 6px;
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
            background-color: rgba(37, 99, 235, 0.2);
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
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
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
            background-color: var(--surface);
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid var(--border);
            transition: transform 0.2s;
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
            background-color: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
        }
        
        .stat-icon.secondary {
            background-color: rgba(14, 165, 233, 0.1);
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
            top: 80px;
            right: 20px;
            z-index: 1200;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .notification {
            background-color: var(--surface);
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
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            padding: 20px;
        }
        
        .auth-card {
            background-color: var(--surface);
            border-radius: var(--radius);
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: var(--shadow-heavy);
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
            background-color: #ccc;
            border-radius: 13px;
            position: relative;
            transition: background-color 0.3s;
        }
        
        .dark-theme .toggle-switch {
            background-color: #475569; /* Более темный цвет для темной темы */
        }
        
        .toggle-switch:before {
            content: "";
            position: absolute;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background-color: white;
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
            border-radius: 6px;
            font-size: 14px;
            background-color: var(--surface);
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
            background-color: var(--hover);
            border-radius: 6px;
        }
        
        .profile-label {
            font-weight: 500;
            color: var(--on-surface);
        }
        
        .profile-value {
            font-family: 'Roboto Mono', monospace;
            font-size: 13px;
            color: var(--primary-color);
            background-color: rgba(37, 99, 235, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
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
    </style>
</head>
<body class="<?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'dark-theme' : ''; ?>">
    <?php if (!$authenticated): ?>
    <!-- Экран авторизации -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <span class="material-icons" style="font-size: 48px;">database</span>
                </div>
                <h1 class="auth-title">SQL Панель управления</h1>
                <p class="auth-subtitle">Войдите в систему для управления базой данных</p>
            </div>
            <form id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="password">Пароль</label>
                    <input type="password" id="password" class="form-control" placeholder="Введите пароль" required>
                    <div style="font-size: 12px; color: var(--on-surface); opacity: 0.7; margin-top: 8px;">
                        Пароль по умолчанию: <strong>Gosha123</strong>
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
                        <span class="material-icons">table_chart</span>
                    </div>
                    <div class="stat-value" id="tableCount">0</div>
                    <div class="stat-label">Таблиц в базе</div>
                </div>
                
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon secondary">
                        <span class="material-icons">storage</span>
                    </div>
                    <div class="stat-value" id="totalRows">0</div>
                    <div class="stat-label">Всего строк</div>
                </div>
                
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon primary">
                        <span class="material-icons">data_usage</span>
                    </div>
                    <div class="stat-value" id="queryCount">0</div>
                    <div class="stat-label">Выполнено запросов</div>
                </div>
                
                <div class="stat-card animated fadeIn">
                    <div class="stat-icon secondary">
                        <span class="material-icons">schedule</span>
                    </div>
                    <div class="stat-value" id="activeTime">0:00</div>
                    <div class="stat-label">Активное время</div>
                </div>
            </div>
            
            <!-- Карточка SQL редактора -->
            <div class="card animated fadeIn" id="sqlEditorCard">
                <div class="card-header">
                    <div>
                        <h2 class="card-title">
                            <span class="material-icons">code</span>
                            SQL Редактор
                        </h2>
                        <div class="card-subtitle" style="font-size: 14px; color: var(--on-surface); opacity: 0.7; margin-top: 4px;">
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
                    <button class="quick-sql-btn" data-sql="SELECT * FROM logs LIMIT 10;">
                        <span class="material-icons">history</span>
                        Логи
                    </button>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="sqlQuery">SQL запрос</label>
                    <textarea id="sqlQuery" class="form-control" placeholder="Введите SQL запрос...">SHOW TABLES;</textarea>
                    <div style="font-size: 12px; color: var(--on-surface); opacity: 0.7; margin-top: 8px;">
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
        
        // Функция показа статистики
        function showStatistics() {
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'block';
            document.getElementById('statsGrid').style.display = 'grid';
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('statisticsBtn');
            
            // Закрываем боковую панель на мобильных
            if (window.innerWidth <= 1200) {
                document.getElementById('sidebar').classList.remove('active');
            }
        }
        
        // Функция показа SQL редактора
        function showSqlEditor() {
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'block';
            document.getElementById('statsGrid').style.display = 'none';
            
            // Обновляем активный элемент меню
            updateActiveMenuItem('sqlEditorBtn');
            
            // Закрываем боковую панель на мобильных
            if (window.innerWidth <= 1200) {
                document.getElementById('sidebar').classList.remove('active');
            }
        }
        
        // Функция обновления активного элемента меню
        function updateActiveMenuItem(activeId) {
            // Убираем активный класс у всех элементов
            document.querySelectorAll('.sidebar-item').forEach(item => {
                item.classList.remove('active');
            });
            
            // Добавляем активный класс к выбранному элементу
            if (activeId) {
                document.getElementById(activeId).classList.add('active');
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
                    document.getElementById('tableCount').textContent = data.tables.length;
                    
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
                            document.getElementById('totalRows').textContent = totalRows.toLocaleString();
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
                
                li.innerHTML = `
                    <span class="material-icons table-icon">table_chart</span>
                    <span>${table}</span>
                `;
                
                li.addEventListener('click', function() {
                    // Загружаем данные таблицы
                    loadTableData(table);
                    
                    // Закрываем боковую панель на мобильных устройствах
                    if (window.innerWidth <= 1200) {
                        document.getElementById('sidebar').classList.remove('active');
                    }
                });
                
                tableList.appendChild(li);
            });
        }
        
        // Функция загрузки данных таблицы
        function loadTableData(table) {
            currentTable = table;
            
            // Показываем карточку с данными таблицы
            document.getElementById('tableDataCard').style.display = 'block';
            document.getElementById('sqlEditorCard').style.display = 'none';
            document.getElementById('statsGrid').style.display = 'none';
            
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
                <div style="background-color: var(--hover); padding: 12px; border-radius: 6px; font-family: 'Roboto Mono', monospace; font-size: 13px; overflow-x: auto;">
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