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
    
    // Создаем демонстрационные таблицы
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
    
    // Таблица продуктов
    $sql = "CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        category VARCHAR(50),
        price DECIMAL(10,2) NOT NULL,
        stock INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $conn->query($sql);
    
    // Проверяем, есть ли данные в таблице products
    $result = $conn->query("SELECT COUNT(*) as count FROM products");
    $row = $result->fetch_assoc();
    
    if ($row['count'] == 0) {
        // Добавляем демонстрационные данные
        $demo_products = [
            "('Ноутбук', 'Электроника', 899.99, 15)",
            "('Смартфон', 'Электроника', 599.99, 30)",
            "('Наушники', 'Электроника', 149.99, 50)",
            "('Книга', 'Книги', 24.99, 100)",
            "('Кофеварка', 'Бытовая техника', 89.99, 20)"
        ];
        
        foreach ($demo_products as $product) {
            $conn->query("INSERT INTO products (name, category, price, stock) VALUES $product");
        }
    }
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
    <title>SQL Панель управления</title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6200ee;
            --primary-dark: #3700b3;
            --primary-light: #bb86fc;
            --secondary-color: #03dac6;
            --secondary-dark: #018786;
            --background: #f5f5f5;
            --surface: #ffffff;
            --error: #cf6679;
            --on-primary: #ffffff;
            --on-secondary: #000000;
            --on-background: #333333;
            --on-surface: #333333;
            --border: #e0e0e0;
            --hover: rgba(0, 0, 0, 0.04);
            --shadow: 0 2px 4px rgba(0,0,0,0.1);
            --shadow-heavy: 0 4px 8px rgba(0,0,0,0.2);
            --radius: 8px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .dark-theme {
            --primary-color: #bb86fc;
            --primary-dark: #3700b3;
            --primary-light: #6200ee;
            --secondary-color: #03dac6;
            --secondary-dark: #018786;
            --background: #121212;
            --surface: #1e1e1e;
            --error: #cf6679;
            --on-primary: #000000;
            --on-secondary: #000000;
            --on-background: #e0e0e0;
            --on-surface: #e0e0e0;
            --border: #333333;
            --hover: rgba(255, 255, 255, 0.05);
            --shadow: 0 2px 4px rgba(0,0,0,0.3);
            --shadow-heavy: 0 4px 8px rgba(0,0,0,0.5);
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
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animated {
            animation-duration: 0.3s;
            animation-fill-mode: both;
        }
        
        .fadeIn { animation-name: fadeIn; }
        .slideIn { animation-name: slideIn; }
        .slideDown { animation-name: slideDown; }
        
        /* Заголовок */
        .app-header {
            background-color: var(--primary-color);
            color: var(--on-primary);
            padding: 0 24px;
            box-shadow: var(--shadow-heavy);
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
            color: var(--on-primary);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background-color 0.2s;
        }
        
        .menu-toggle:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo-icon {
            font-size: 28px;
            color: var(--on-primary);
        }
        
        .logo-text h1 {
            font-size: 20px;
            font-weight: 500;
            line-height: 1.2;
        }
        
        .logo-text .subtitle {
            font-size: 12px;
            opacity: 0.8;
        }
        
        /* Улучшенный заголовок действий */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }
        
        .action-button {
            background: none;
            border: none;
            color: var(--on-primary);
            cursor: pointer;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            transition: background-color 0.2s;
        }
        
        .action-button:hover {
            background-color: rgba(255, 255, 255, 0.1);
        }
        
        .action-button.with-text {
            width: auto;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 14px;
            gap: 8px;
        }
        
        .action-button .badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background-color: var(--secondary-color);
            color: var(--on-secondary);
            font-size: 10px;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
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
            border-radius: var(--radius);
            box-shadow: var(--shadow-heavy);
            min-width: 200px;
            overflow: hidden;
            z-index: 1001;
            margin-top: 8px;
            display: none;
        }
        
        .user-dropdown.active {
            display: block;
            animation: slideDown 0.2s;
        }
        
        .user-dropdown-item {
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            transition: background-color 0.2s;
            border-bottom: 1px solid var(--border);
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
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .sidebar-title {
            font-size: 18px;
            font-weight: 500;
            color: var(--on-surface);
        }
        
        .database-info {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            color: var(--primary-color);
            background-color: rgba(98, 0, 238, 0.1);
            padding: 4px 8px;
            border-radius: 4px;
        }
        
        .dark-theme .database-info {
            background-color: rgba(187, 134, 252, 0.1);
        }
        
        .table-list {
            list-style: none;
        }
        
        .table-item {
            padding: 12px 20px;
            border-bottom: 1px solid var(--border);
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .table-item:hover {
            background-color: var(--hover);
        }
        
        .table-item.active {
            background-color: rgba(98, 0, 238, 0.1);
            color: var(--primary-color);
            font-weight: 500;
            border-left: 4px solid var(--primary-color);
        }
        
        .dark-theme .table-item.active {
            background-color: rgba(187, 134, 252, 0.1);
        }
        
        .table-info {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .table-icon {
            font-size: 20px;
            color: var(--primary-color);
        }
        
        .table-name {
            font-weight: 500;
        }
        
        .table-stats {
            font-size: 12px;
            opacity: 0.7;
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
            font-weight: 500;
            color: var(--on-surface);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .card-subtitle {
            font-size: 14px;
            color: var(--primary-color);
            margin-top: 4px;
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
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border);
            border-radius: 4px;
            font-size: 16px;
            background-color: var(--surface);
            color: var(--on-surface);
            transition: border-color 0.2s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(98, 0, 238, 0.2);
        }
        
        .dark-theme .form-control:focus {
            box-shadow: 0 0 0 2px rgba(187, 134, 252, 0.2);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: 'Roboto Mono', monospace;
            font-size: 14px;
            line-height: 1.5;
        }
        
        /* SQL редактор */
        .sql-editor-container {
            position: relative;
        }
        
        .sql-toolbar {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        
        .quick-sql-btn {
            background-color: rgba(98, 0, 238, 0.1);
            color: var(--primary-color);
            border: 1px solid var(--primary-color);
            border-radius: 4px;
            padding: 8px 16px;
            font-size: 14px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .quick-sql-btn:hover {
            background-color: rgba(98, 0, 238, 0.2);
        }
        
        .dark-theme .quick-sql-btn {
            background-color: rgba(187, 134, 252, 0.1);
            border-color: var(--primary-light);
        }
        
        .sql-history-panel {
            background-color: var(--surface);
            border-radius: var(--radius);
            padding: 20px;
            margin-top: 20px;
            border: 1px solid var(--border);
            display: none;
        }
        
        .sql-history-panel.active {
            display: block;
        }
        
        /* Таблицы */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .data-table th {
            background-color: rgba(0, 0, 0, 0.02);
            padding: 16px;
            text-align: left;
            font-weight: 500;
            color: var(--on-surface);
            border-bottom: 2px solid var(--border);
            position: sticky;
            top: 0;
        }
        
        .dark-theme .data-table th {
            background-color: rgba(255, 255, 255, 0.02);
        }
        
        .data-table td {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        
        .data-table tr:hover {
            background-color: var(--hover);
        }
        
        .data-table .actions-cell {
            white-space: nowrap;
        }
        
        /* Кнопки */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
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
            transform: translateY(-2px);
            box-shadow: var(--shadow-heavy);
        }
        
        .btn-secondary {
            background-color: var(--secondary-color);
            color: var(--on-secondary);
        }
        
        .btn-secondary:hover {
            background-color: var(--secondary-dark);
            color: white;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
        }
        
        .btn-outline:hover {
            background-color: rgba(98, 0, 238, 0.1);
        }
        
        .btn-danger {
            background-color: var(--error);
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b00020;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        .btn-icon {
            width: 36px;
            height: 36px;
            padding: 0;
            border-radius: 50%;
        }
        
        /* Статистика */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background-color: var(--surface);
            border-radius: var(--radius);
            padding: 20px;
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
            background-color: rgba(98, 0, 238, 0.1);
            color: var(--primary-color);
        }
        
        .stat-icon.secondary {
            background-color: rgba(3, 218, 198, 0.1);
            color: var(--secondary-color);
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 4px;
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
        }
        
        .notification.active {
            transform: translateX(0);
        }
        
        .notification.success {
            border-left-color: #4caf50;
        }
        
        .notification.success .notification-icon {
            color: #4caf50;
        }
        
        .notification.error {
            border-left-color: #f44336;
        }
        
        .notification.error .notification-icon {
            color: #f44336;
        }
        
        .notification.info {
            border-left-color: #2196f3;
        }
        
        .notification.info .notification-icon {
            color: #2196f3;
        }
        
        .notification.warning {
            border-left-color: #ff9800;
        }
        
        .notification.warning .notification-icon {
            color: #ff9800;
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
            font-weight: 500;
            color: var(--on-surface);
            margin-bottom: 8px;
        }
        
        .auth-subtitle {
            font-size: 14px;
            color: var(--on-surface);
            opacity: 0.7;
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
            
            .sql-toolbar {
                flex-direction: column;
            }
            
            .quick-sql-btn {
                width: 100%;
                justify-content: center;
            }
            
            .data-table {
                display: block;
                overflow-x: auto;
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
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Улучшения для таблиц */
        .table-actions {
            display: flex;
            gap: 8px;
            margin-bottom: 16px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .table-stats-info {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 14px;
            color: var(--on-surface);
            opacity: 0.7;
            margin-left: auto;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--on-surface);
            opacity: 0.7;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }
        
        /* Переключатель темы */
        .theme-toggle {
            display: flex;
            align-items: center;
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
            margin: 0 8px;
        }
        
        .dark-theme .toggle-switch {
            background-color: var(--primary-color);
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
            padding: 12px 16px 12px 40px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
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
    </style>
</head>
<body class="<?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'dark-theme' : ''; ?>">
    <?php if (!$authenticated): ?>
    <!-- Экран авторизации -->
    <div class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <div class="auth-logo">
                    <span class="material-icons" style="font-size: 48px;">storage</span>
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
                    <span class="material-icons logo-icon">storage</span>
                    <div class="logo-text">
                        <h1>SQL Панель управления</h1>
                        <div class="subtitle">Управление базой данных</div>
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
                        <span>Аккаунт</span>
                    </button>
                    <div class="user-dropdown" id="userDropdown">
                        <div class="user-dropdown-item" id="changePasswordBtn">
                            <span class="material-icons">password</span>
                            <span>Сменить пароль</span>
                        </div>
                        <div class="user-dropdown-item" id="settingsBtn">
                            <span class="material-icons">settings</span>
                            <span>Настройки</span>
                        </div>
                        <div class="user-dropdown-item" id="helpBtn">
                            <span class="material-icons">help</span>
                            <span>Справка</span>
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
        <div class="sidebar-header">
            <h3 class="sidebar-title">Таблицы базы данных</h3>
            <div class="database-info">
                <span class="material-icons" style="font-size: 16px;">database</span>
                <span><?php echo DB_NAME; ?></span>
            </div>
        </div>
        
        <div class="search-box" style="padding: 0 20px; margin-top: 10px;">
            <span class="material-icons search-icon">search</span>
            <input type="text" class="search-input" id="tableSearch" placeholder="Поиск таблиц...">
        </div>
        
        <div class="sidebar-content">
            <ul class="table-list" id="tableList">
                <!-- Список таблиц будет загружен через AJAX -->
            </ul>
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
                        <span class="material-icons">code</span>
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
                        <div class="card-subtitle">Выполняйте SQL запросы к базе данных</div>
                    </div>
                    <div>
                        <button class="btn btn-outline btn-small" id="toggleHistoryBtn">
                            <span class="material-icons">history</span>
                            История
                        </button>
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
                    <button class="quick-sql-btn" data-sql="SELECT * FROM products LIMIT 10;">
                        <span class="material-icons">shopping_cart</span>
                        Продукты
                    </button>
                    <button class="quick-sql-btn" data-sql="SELECT COUNT(*) as count FROM users;">
                        <span class="material-icons">calculate</span>
                        Подсчет строк
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
                
                <div class="sql-history-panel" id="sqlHistoryPanel">
                    <h3 style="margin-bottom: 16px;">История запросов</h3>
                    <div id="historyList"></div>
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
                        <div class="card-subtitle" id="tableSubtitle"></div>
                    </div>
                    <div class="table-actions">
                        <button class="btn btn-outline btn-small" id="showStructureBtn">
                            <span class="material-icons">schema</span>
                            Структура
                        </button>
                        <button class="btn btn-outline btn-small" id="exportTableBtn">
                            <span class="material-icons">download</span>
                            Экспорт
                        </button>
                        <button class="btn btn-secondary" id="refreshTableBtn">
                            <span class="material-icons">refresh</span>
                            Обновить
                        </button>
                        <button class="btn btn-primary" id="addRowBtn">
                            <span class="material-icons">add</span>
                            Добавить строку
                        </button>
                        <div class="table-stats-info">
                            <span id="tableRowCount">0 строк</span>
                            <span id="tableTotalRows">Всего: 0</span>
                        </div>
                    </div>
                </div>
                
                <div class="table-container" id="tableDataContainer">
                    <!-- Данные таблицы будут отображаться здесь -->
                </div>
            </div>
        </div>
    </main>
    
    <!-- Модальные окна -->
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
    
    <!-- Модальное окно добавления/редактирования строки -->
    <div class="modal-overlay" id="editRowModal">
        <div class="modal-content">
            <div class="card-header">
                <h3 class="card-title" id="editModalTitle">Добавление строки</h3>
                <button class="action-button btn-icon" id="closeEditRowModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body" style="padding: 24px;">
                <form id="editRowForm">
                    <!-- Поля формы будут динамически добавлены -->
                </form>
            </div>
            <div class="modal-footer" style="padding: 20px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 12px;">
                <button class="btn btn-outline" id="cancelEditRow">Отмена</button>
                <button class="btn btn-primary" id="saveRowBtn">
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
        let sqlHistory = JSON.parse(localStorage.getItem('sqlHistory') || '[]');
        let currentEditingRow = null;
        let queryCount = parseInt(localStorage.getItem('queryCount') || '0');
        let sessionStartTime = new Date();
        let activeTimeInterval;
        
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
            
            // Обработка нажатия Enter в поле пароля
            passwordInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    loginForm.dispatchEvent(new Event('submit'));
                }
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
            
            // Загружаем историю запросов
            renderSqlHistory();
            
            // Выполняем начальный SQL запрос
            executeSql('SHOW TABLES;');
            
            // Если пароль не менялся, показываем модальное окно смены пароля
            <?php if (!$password_changed): ?>
            setTimeout(() => {
                showChangePasswordModal();
            }, 1500);
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
                showChangePasswordModal();
            });
            
            // Кнопка помощи
            document.getElementById('helpBtn').addEventListener('click', function() {
                showNotification('Для справки ознакомьтесь с документацией SQL', 'info');
            });
            
            // Кнопка настроек
            document.getElementById('settingsBtn').addEventListener('click', function() {
                showNotification('Настройки будут доступны в следующей версии', 'info');
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
                    addToSqlHistory(sqlQuery);
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
            
            // Кнопка переключения истории
            document.getElementById('toggleHistoryBtn').addEventListener('click', function() {
                document.getElementById('sqlHistoryPanel').classList.toggle('active');
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
            
            // Кнопка показа структуры
            document.getElementById('showStructureBtn').addEventListener('click', function() {
                if (currentTable) {
                    showTableStructure(currentTable);
                }
            });
            
            // Кнопка экспорта
            document.getElementById('exportTableBtn').addEventListener('click', function() {
                if (currentTable) {
                    exportTable(currentTable);
                }
            });
            
            // Закрытие модального окна редактирования строки
            document.getElementById('closeEditRowModal').addEventListener('click', function() {
                hideEditRowModal();
            });
            
            document.getElementById('cancelEditRow').addEventListener('click', function() {
                hideEditRowModal();
            });
            
            // Поиск таблиц
            document.getElementById('tableSearch').addEventListener('input', function() {
                const searchTerm = this.value.toLowerCase();
                const tableItems = document.querySelectorAll('.table-item');
                
                tableItems.forEach(item => {
                    const tableName = item.querySelector('.table-name').textContent.toLowerCase();
                    if (tableName.includes(searchTerm)) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
            
            // Обработка нажатия Ctrl+Enter в SQL редакторе
            document.getElementById('sqlQuery').addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    e.preventDefault();
                    document.getElementById('executeSqlBtn').click();
                }
            });
            
            // Автоподсказки для SQL редактора
            initSqlAutocomplete();
        }
        
        // Функция показа модального окна смены пароля
        function showChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.add('active');
        }
        
        // Функция скрытия модального окна смены пароля
        function hideChangePasswordModal() {
            document.getElementById('changePasswordModal').classList.remove('active');
        }
        
        // Функция показа модального окна редактирования строки
        function showEditRowModal() {
            document.getElementById('editRowModal').classList.add('active');
        }
        
        // Функция скрытия модального окна редактирования строки
        function hideEditRowModal() {
            document.getElementById('editRowModal').classList.remove('active');
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
                    <div class="table-info">
                        <span class="material-icons table-icon">table_chart</span>
                        <div>
                            <div class="table-name">${table}</div>
                            <div class="table-stats">Загрузка...</div>
                        </div>
                    </div>
                `;
                
                li.addEventListener('click', function() {
                    // Убираем активный класс у всех элементов
                    document.querySelectorAll('.table-item').forEach(item => {
                        item.classList.remove('active');
                    });
                    
                    // Добавляем активный класс к выбранному элементу
                    this.classList.add('active');
                    
                    // Загружаем данные таблицы
                    loadTableData(table);
                    
                    // Закрываем боковую панель на мобильных устройствах
                    if (window.innerWidth <= 1200) {
                        document.getElementById('sidebar').classList.remove('active');
                    }
                });
                
                tableList.appendChild(li);
                
                // Загружаем статистику для этой таблицы
                loadTableStats(table, li);
            });
        }
        
        // Функция загрузки статистики таблицы
        function loadTableStats(table, listItem) {
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
                    const statsEl = listItem.querySelector('.table-stats');
                    statsEl.textContent = `${data.total_rows || 0} строк`;
                    
                    // Сохраняем количество строк в data-атрибут
                    listItem.dataset.rows = data.total_rows || 0;
                }
            });
        }
        
        // Функция загрузки данных таблицы
        function loadTableData(table) {
            currentTable = table;
            
            // Показываем карточку с данными таблицы
            document.getElementById('tableDataCard').style.display = 'block';
            document.getElementById('sqlEditorCard').style.display = 'none';
            
            // Обновляем заголовок
            document.getElementById('tableTitle').textContent = table;
            document.getElementById('tableSubtitle').textContent = `Просмотр и редактирование данных таблицы "${table}"`;
            
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
                    renderTableData(data.columns, data.rows, table, data.total_rows);
                    document.getElementById('tableRowCount').textContent = `${data.rows.length} строк`;
                    document.getElementById('tableTotalRows').textContent = `Всего: ${data.total_rows}`;
                } else {
                    showNotification(data.message, 'error');
                    container.innerHTML = `
                        <div class="empty-state">
                            <span class="material-icons empty-state-icon">error</span>
                            <p>Ошибка загрузки данных таблицы</p>
                            <button class="btn btn-outline" onclick="loadTableData('${table}')" style="margin-top: 16px;">
                                <span class="material-icons">refresh</span>
                                Повторить попытку
                            </button>
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки данных таблицы', 'error');
                container.innerHTML = `
                    <div class="empty-state">
                        <span class="material-icons empty-state-icon">wifi_off</span>
                        <p>Ошибка сети при загрузке данных</p>
                        <button class="btn btn-outline" onclick="loadTableData('${table}')" style="margin-top: 16px;">
                            <span class="material-icons">refresh</span>
                            Повторить попытку
                        </button>
                    </div>
                `;
            });
        }
        
        // Функция отрисовки данных таблицы
        function renderTableData(columns, rows, table, totalRows) {
            const container = document.getElementById('tableDataContainer');
            
            if (rows.length === 0) {
                container.innerHTML = `
                    <div class="empty-state">
                        <span class="material-icons empty-state-icon">table_rows</span>
                        <p>Таблица "${table}" пуста</p>
                        <button class="btn btn-primary" onclick="showAddRowForm('${table}')" style="margin-top: 16px;">
                            <span class="material-icons">add</span>
                            Добавить первую строку
                        </button>
                    </div>
                `;
                return;
            }
            
            // Создаем таблицу
            let html = '<table class="data-table">';
            
            // Заголовки таблицы
            html += '<thead><tr>';
            columns.forEach(column => {
                html += `<th>${column.name}<br><small style="opacity: 0.7; font-weight: normal;">${column.type}</small></th>`;
            });
            html += '<th style="width: 100px;">Действия</th>';
            html += '</tr></thead>';
            
            // Тело таблицы
            html += '<tbody>';
            rows.forEach(row => {
                html += '<tr>';
                columns.forEach(column => {
                    let value = row[column.name];
                    if (value === null || value === '') {
                        value = '<span style="color: var(--on-surface); opacity: 0.3; font-style: italic;">NULL</span>';
                    } else if (typeof value === 'string' && value.length > 100) {
                        value = value.substring(0, 100) + '...';
                    }
                    html += `<td>${value}</td>`;
                });
                
                // Кнопки действий
                html += `<td class="actions-cell">
                    <button class="btn btn-outline btn-small edit-row-btn" data-table="${table}" data-row='${JSON.stringify(row)}' title="Редактировать">
                        <span class="material-icons" style="font-size: 16px;">edit</span>
                    </button>
                    <button class="btn btn-outline btn-small delete-row-btn" data-table="${table}" data-row='${JSON.stringify(row)}' title="Удалить" style="margin-left: 4px;">
                        <span class="material-icons" style="font-size: 16px;">delete</span>
                    </button>
                </td>`;
                html += '</tr>';
            });
            html += '</tbody></table>';
            
            container.innerHTML = html;
            
            // Добавляем обработчики событий для кнопок
            document.querySelectorAll('.edit-row-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const table = this.dataset.table;
                    const row = JSON.parse(this.dataset.row);
                    showEditRowForm(table, row);
                });
            });
            
            document.querySelectorAll('.delete-row-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const table = this.dataset.table;
                    const row = JSON.parse(this.dataset.row);
                    
                    showNotification(`Удалить строку из таблицы ${table}?`, 'warning', 5000);
                    
                    // Создаем кнопку подтверждения
                    setTimeout(() => {
                        showNotification(
                            `Подтвердите удаление строки`,
                            'error',
                            5000,
                            `<button class="btn btn-danger btn-small" onclick="confirmDeleteRow('${table}', '${escapeSql(JSON.stringify(row))}')">Удалить</button>`
                        );
                    }, 100);
                });
            });
        }
        
        // Функция подтверждения удаления строки
        function confirmDeleteRow(table, rowJson) {
            const row = JSON.parse(rowJson);
            
            const whereClause = Object.keys(row)
                .map(key => `\`${key}\` = '${escapeSql(row[key])}'`)
                .join(' AND ');
            
            const sql = `DELETE FROM \`${table}\` WHERE ${whereClause};`;
            
            executeSql(sql, true).then(success => {
                if (success && currentTable === table) {
                    loadTableData(table);
                    showNotification('Строка успешно удалена', 'success');
                }
            });
        }
        
        // Функция отображения структуры таблицы
        function showTableStructure(table) {
            const sql = `DESCRIBE \`${table}\``;
            document.getElementById('sqlQuery').value = sql;
            document.getElementById('executeSqlBtn').click();
            
            // Переключаемся на SQL редактор
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'block';
        }
        
        // Функция экспорта таблицы
        function exportTable(table) {
            const sql = `SELECT * FROM \`${table}\``;
            document.getElementById('sqlQuery').value = sql;
            document.getElementById('executeSqlBtn').click();
            showNotification(`Запрос для экспорта таблицы "${table}" готов к выполнению`, 'info');
        }
        
        // Функция отображения формы добавления строки
        function showAddRowForm(table) {
            currentEditingRow = null;
            document.getElementById('editModalTitle').textContent = `Добавление строки в таблицу "${table}"`;
            
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
                    renderEditForm(data.columns, table, null);
                    showEditRowModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки структуры таблицы', 'error');
            });
        }
        
        // Функция отображения формы редактирования строки
        function showEditRowForm(table, row) {
            currentEditingRow = row;
            document.getElementById('editModalTitle').textContent = `Редактирование строки в таблице "${table}"`;
            
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
                    renderEditForm(data.columns, table, row);
                    showEditRowModal();
                } else {
                    showNotification(data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки структуры таблицы', 'error');
            });
        }
        
        // Функция отрисовки формы редактирования
        function renderEditForm(columns, table, row) {
            const form = document.getElementById('editRowForm');
            form.innerHTML = '';
            
            columns.forEach(column => {
                // Пропускаем автоинкрементные поля
                if (column.extra.includes('auto_increment')) {
                    return;
                }
                
                const value = row ? (row[column.name] || '') : '';
                
                const div = document.createElement('div');
                div.className = 'form-group';
                
                const label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = `${column.name} (${column.type})`;
                label.htmlFor = `field_${column.name}`;
                
                let input;
                
                // Определяем тип поля
                if (column.type.includes('text') || column.type.includes('varchar')) {
                    input = document.createElement('textarea');
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                    input.rows = 3;
                } else if (column.type.includes('int') || column.type.includes('float') || column.type.includes('decimal')) {
                    input = document.createElement('input');
                    input.type = 'number';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                    input.step = column.type.includes('int') ? '1' : '0.01';
                } else if (column.type.includes('date')) {
                    input = document.createElement('input');
                    input.type = 'date';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                } else if (column.type.includes('time')) {
                    input = document.createElement('input');
                    input.type = 'time';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                } else if (column.type.includes('datetime') || column.type.includes('timestamp')) {
                    input = document.createElement('input');
                    input.type = 'datetime-local';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    // Преобразуем значение в формат datetime-local
                    if (value) {
                        const date = new Date(value);
                        if (!isNaN(date)) {
                            input.value = date.toISOString().slice(0, 16);
                        }
                    }
                } else if (column.type.includes('enum')) {
                    input = document.createElement('select');
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    
                    // Извлекаем значения ENUM из типа
                    const enumValues = column.type.match(/enum\(([^)]+)\)/)[1]
                        .replace(/'/g, '')
                        .split(',');
                    
                    enumValues.forEach(enumValue => {
                        const option = document.createElement('option');
                        option.value = enumValue;
                        option.textContent = enumValue;
                        if (value === enumValue) {
                            option.selected = true;
                        }
                        input.appendChild(option);
                    });
                } else if (column.type.includes('tinyint(1)')) {
                    input = document.createElement('select');
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    
                    const optionTrue = document.createElement('option');
                    optionTrue.value = '1';
                    optionTrue.textContent = 'Да';
                    
                    const optionFalse = document.createElement('option');
                    optionFalse.value = '0';
                    optionFalse.textContent = 'Нет';
                    
                    input.appendChild(optionTrue);
                    input.appendChild(optionFalse);
                    
                    if (value === '1' || value === 1) {
                        optionTrue.selected = true;
                    } else {
                        optionFalse.selected = true;
                    }
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                }
                
                // Если поле обязательное и не имеет значения по умолчанию
                if (column.nullable === false && !column.default && column.extra !== 'auto_increment') {
                    input.required = true;
                    label.innerHTML += ' <span style="color: var(--error);">*</span>';
                }
                
                div.appendChild(label);
                div.appendChild(input);
                form.appendChild(div);
            });
            
            // Обработчик сохранения
            document.getElementById('saveRowBtn').onclick = function() {
                saveRow(table, columns, row);
            };
        }
        
        // Функция сохранения строки
        function saveRow(table, columns, originalRow) {
            const form = document.getElementById('editRowForm');
            const formData = {};
            
            // Собираем данные формы
            columns.forEach(column => {
                if (column.extra.includes('auto_increment')) {
                    return;
                }
                
                const input = form.querySelector(`#field_${column.name}`);
                if (input) {
                    let value = input.value;
                    
                    // Обработка специальных типов
                    if (column.type.includes('tinyint(1)')) {
                        value = value === '1' ? 1 : 0;
                    }
                    
                    // Если поле пустое и nullable, устанавливаем NULL
                    if (value === '' && column.nullable) {
                        formData[column.name] = null;
                    } else {
                        formData[column.name] = value;
                    }
                }
            });
            
            // Определяем, это добавление или обновление
            let sql;
            if (originalRow) {
                // Обновление существующей строки
                const setClause = Object.keys(formData)
                    .filter(key => formData[key] !== null)
                    .map(key => `\`${key}\` = '${escapeSql(formData[key])}'`)
                    .join(', ');
                
                const whereClause = Object.keys(originalRow)
                    .map(key => `\`${key}\` = '${escapeSql(originalRow[key])}'`)
                    .join(' AND ');
                
                sql = `UPDATE \`${table}\` SET ${setClause} WHERE ${whereClause};`;
            } else {
                // Добавление новой строки
                const columnsClause = Object.keys(formData)
                    .map(key => `\`${key}\``)
                    .join(', ');
                
                const valuesClause = Object.values(formData)
                    .map(value => value === null ? 'NULL' : `'${escapeSql(value)}'`)
                    .join(', ');
                
                sql = `INSERT INTO \`${table}\` (${columnsClause}) VALUES (${valuesClause});`;
            }
            
            const saveBtn = document.getElementById('saveRowBtn');
            const btnText = saveBtn.querySelector('.btn-text');
            const loader = saveBtn.querySelector('.loader');
            
            // Показываем лоадер
            btnText.style.display = 'none';
            loader.style.display = 'inline-block';
            saveBtn.disabled = true;
            
            // Выполняем SQL запрос
            executeSql(sql, true).then(success => {
                if (success) {
                    hideEditRowModal();
                    
                    // Перезагружаем данные таблицы
                    if (currentTable === table) {
                        loadTableData(table);
                    }
                    
                    showNotification(originalRow ? 'Строка обновлена' : 'Строка добавлена', 'success');
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
                    <h3 style="font-size: 16px; font-weight: 500;">Выполненный запрос</h3>
                </div>
                <div style="background-color: rgba(0,0,0,0.05); padding: 12px; border-radius: 4px; font-family: 'Roboto Mono', monospace; font-size: 13px; overflow-x: auto;">
                    ${sql}
                </div>
                <div style="margin-top: 12px; font-size: 14px; color: var(--on-surface); opacity: 0.7;">
                    Затронуто строк: <strong>${affectedRows}</strong>
                </div>
            `;
            resultsDiv.appendChild(queryCard);
            
            if (results.length === 0 && affectedRows > 0) {
                // Запрос без результата (INSERT, UPDATE, DELETE и т.д.)
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
                        <div class="table-container">
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
                                                            row[col].substring(0, 50) + '...' : row[col]) : 
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
            
            // Если результатов нет, показываем сообщение
            if (results.length === 0 && affectedRows === 0) {
                resultsDiv.innerHTML += `
                    <div class="notification info" style="margin-top: 20px; position: relative; top: 0; right: 0; transform: none;">
                        <span class="material-icons notification-icon">info</span>
                        <span>Запрос выполнен успешно, но не вернул результатов</span>
                    </div>
                `;
            }
        }
        
        // Функция добавления запроса в историю
        function addToSqlHistory(sql) {
            // Добавляем запрос в начало массива
            sqlHistory.unshift({
                sql: sql,
                timestamp: new Date().toLocaleTimeString(),
                date: new Date().toLocaleDateString()
            });
            
            // Ограничиваем историю 20 последними запросами
            if (sqlHistory.length > 20) {
                sqlHistory.pop();
            }
            
            // Сохраняем в localStorage
            localStorage.setItem('sqlHistory', JSON.stringify(sqlHistory));
            
            // Обновляем отображение истории
            renderSqlHistory();
        }
        
        // Функция отрисовки истории SQL запросов
        function renderSqlHistory() {
            const historyList = document.getElementById('historyList');
            
            if (sqlHistory.length === 0) {
                historyList.innerHTML = `
                    <div class="empty-state" style="padding: 20px 0;">
                        <span class="material-icons empty-state-icon">history</span>
                        <p>История запросов пуста</p>
                    </div>
                `;
                return;
            }
            
            let html = '<div style="display: flex; flex-direction: column; gap: 8px;">';
            
            sqlHistory.forEach((item, index) => {
                html += `
                    <div class="history-item" style="background-color: var(--hover); padding: 12px; border-radius: 4px; cursor: pointer; transition: background-color 0.2s;" 
                         onclick="document.getElementById('sqlQuery').value = \`${escapeHtml(item.sql)}\`; document.getElementById('sqlHistoryPanel').classList.remove('active');">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                            <span style="font-size: 11px; color: var(--on-surface); opacity: 0.7;">${item.date} ${item.timestamp}</span>
                            <button class="btn-icon" onclick="deleteHistoryItem(${index}); event.stopPropagation();" style="width: 24px; height: 24px; padding: 0;">
                                <span class="material-icons" style="font-size: 16px;">delete</span>
                            </button>
                        </div>
                        <div style="font-family: 'Roboto Mono', monospace; font-size: 12px; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                            ${escapeHtml(item.sql.length > 100 ? item.sql.substring(0, 100) + '...' : item.sql)}
                        </div>
                    </div>
                `;
            });
            
            html += '</div>';
            
            historyList.innerHTML = html;
        }
        
        // Функция удаления элемента истории
        function deleteHistoryItem(index) {
            sqlHistory.splice(index, 1);
            localStorage.setItem('sqlHistory', JSON.stringify(sqlHistory));
            renderSqlHistory();
        }
        
        // Функция инициализации переключателя темы
        function initThemeToggle() {
            const themeToggle = document.getElementById('themeToggle');
            
            if (themeToggle) {
                themeToggle.addEventListener('change', function() {
                    const isDarkTheme = this.checked;
                    document.body.classList.toggle('dark-theme', isDarkTheme);
                    
                    // Сохраняем выбор темы в cookie
                    document.cookie = `dark_theme=${isDarkTheme}; path=/; max-age=31536000`; // 1 год
                    
                    showNotification(isDarkTheme ? 'Темная тема включена' : 'Светлая тема включена', 'info');
                });
            }
        }
        
        // Функция показа уведомления
        function showNotification(message, type = 'info', duration = 3000, actionHtml = '') {
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
                ${actionHtml}
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
        
        // Функция инициализации автодополнения SQL
        function initSqlAutocomplete() {
            const textarea = document.getElementById('sqlQuery');
            const keywords = ['SELECT', 'FROM', 'WHERE', 'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE', 
                            'CREATE', 'TABLE', 'ALTER', 'DROP', 'SHOW', 'DESCRIBE', 'JOIN', 'LEFT', 'RIGHT', 
                            'INNER', 'OUTER', 'ON', 'GROUP BY', 'ORDER BY', 'LIMIT', 'AND', 'OR', 'NOT', 'NULL'];
            
            textarea.addEventListener('keydown', function(e) {
                if (e.key === 'Tab') {
                    e.preventDefault();
                    const start = this.selectionStart;
                    const end = this.selectionEnd;
                    
                    // Вставляем отступ
                    this.value = this.value.substring(0, start) + '    ' + this.value.substring(end);
                    this.selectionStart = this.selectionEnd = start + 4;
                }
            });
        }
        
        // Функция экранирования SQL строк
        function escapeSql(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/'/g, "''").replace(/\\/g, '\\\\');
        }
        
        // Функция экранирования HTML
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>