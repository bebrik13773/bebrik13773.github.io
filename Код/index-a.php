<?php
// Настройки подключения к базе данных
define('DB_HOST', 'sql305.infinityfree.com');
define('DB_USER', 'if0_39950285');
define('DB_PASS', 'tmzPxb2Wu5aj6Lb');
define('DB_NAME', 'sql_panel');

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
    
    $conn->close();
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
                
                $response['success'] = true;
                $response['columns'] = $columns;
                $response['rows'] = $rows;
                $response['row_count'] = $data_result->num_rows;
                
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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            transition: background-color 0.3s, color 0.3s, border-color 0.3s;
        }
        
        body {
            font-family: 'Roboto', sans-serif;
            background-color: #f5f5f5;
            color: #333;
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        body.dark-theme {
            background-color: #121212;
            color: #e0e0e0;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
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
        
        .animated {
            animation-duration: 0.3s;
            animation-fill-mode: both;
        }
        
        .fadeIn { animation-name: fadeIn; }
        .slideIn { animation-name: slideIn; }
        
        /* Заголовок */
        header {
            background-color: #6200ee;
            color: white;
            padding: 16px 24px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        body.dark-theme header {
            background-color: #1e1e1e;
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .logo h1 {
            font-size: 24px;
            font-weight: 500;
        }
        
        .logo-icon {
            font-size: 28px;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 16px;
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
            background-color: #6200ee;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #3700b3;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }
        
        .btn-secondary {
            background-color: #03dac6;
            color: #000;
        }
        
        .btn-secondary:hover {
            background-color: #018786;
            color: white;
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid #6200ee;
            color: #6200ee;
        }
        
        body.dark-theme .btn-outline {
            border-color: #bb86fc;
            color: #bb86fc;
        }
        
        .btn-outline:hover {
            background-color: rgba(98, 0, 238, 0.1);
        }
        
        body.dark-theme .btn-outline:hover {
            background-color: rgba(187, 134, 252, 0.1);
        }
        
        .btn-danger {
            background-color: #cf6679;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #b00020;
        }
        
        .btn-small {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        /* Карточки */
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 24px;
            margin-bottom: 24px;
        }
        
        body.dark-theme .card {
            background-color: #1e1e1e;
            box-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        body.dark-theme .card-header {
            border-bottom-color: #333;
        }
        
        .card-title {
            font-size: 20px;
            font-weight: 500;
        }
        
        /* Формы */
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }
        
        .form-control {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: 16px;
            background-color: white;
            color: #333;
        }
        
        body.dark-theme .form-control {
            background-color: #2c2c2c;
            border-color: #444;
            color: #e0e0e0;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #6200ee;
            box-shadow: 0 0 0 2px rgba(98, 0, 238, 0.2);
        }
        
        body.dark-theme .form-control:focus {
            border-color: #bb86fc;
            box-shadow: 0 0 0 2px rgba(187, 134, 252, 0.2);
        }
        
        textarea.form-control {
            min-height: 120px;
            resize: vertical;
            font-family: monospace;
            font-size: 14px;
        }
        
        /* Сетка */
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -12px;
        }
        
        .col {
            flex: 1;
            padding: 0 12px;
        }
        
        .col-6 {
            width: 50%;
            flex: 0 0 50%;
            padding: 0 12px;
        }
        
        /* Таблицы */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }
        
        body.dark-theme th, body.dark-theme td {
            border-bottom-color: #333;
        }
        
        th {
            background-color: #f5f5f5;
            font-weight: 500;
        }
        
        body.dark-theme th {
            background-color: #2c2c2c;
        }
        
        tr:hover {
            background-color: rgba(0,0,0,0.04);
        }
        
        body.dark-theme tr:hover {
            background-color: rgba(255,255,255,0.05);
        }
        
        /* Боковая панель */
        .sidebar {
            width: 280px;
            background-color: white;
            box-shadow: 2px 0 4px rgba(0,0,0,0.1);
            height: calc(100vh - 68px);
            position: fixed;
            left: 0;
            top: 68px;
            overflow-y: auto;
            z-index: 900;
            transform: translateX(-100%);
            transition: transform 0.3s;
        }
        
        body.dark-theme .sidebar {
            background-color: #1e1e1e;
            box-shadow: 2px 0 4px rgba(0,0,0,0.3);
        }
        
        .sidebar.active {
            transform: translateX(0);
        }
        
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        body.dark-theme .sidebar-header {
            border-bottom-color: #333;
        }
        
        .sidebar-title {
            font-size: 18px;
            font-weight: 500;
        }
        
        .table-list {
            list-style: none;
        }
        
        .table-item {
            padding: 12px 20px;
            border-bottom: 1px solid #f5f5f5;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        body.dark-theme .table-item {
            border-bottom-color: #2c2c2c;
        }
        
        .table-item:hover {
            background-color: rgba(98, 0, 238, 0.08);
        }
        
        body.dark-theme .table-item:hover {
            background-color: rgba(187, 134, 252, 0.1);
        }
        
        .table-item.active {
            background-color: rgba(98, 0, 238, 0.12);
            color: #6200ee;
            font-weight: 500;
        }
        
        body.dark-theme .table-item.active {
            background-color: rgba(187, 134, 252, 0.2);
            color: #bb86fc;
        }
        
        /* Основной контент */
        .main-content {
            margin-left: 0;
            transition: margin-left 0.3s;
        }
        
        .main-content.with-sidebar {
            margin-left: 280px;
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
        
        .toggle-slider {
            width: 50px;
            height: 26px;
            background-color: #ccc;
            border-radius: 13px;
            position: relative;
            transition: background-color 0.3s;
            margin-right: 10px;
        }
        
        body.dark-theme .toggle-slider {
            background-color: #6200ee;
        }
        
        .toggle-slider:before {
            content: "";
            position: absolute;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background-color: white;
            top: 2px;
            left: 2px;
            transition: transform 0.3s;
        }
        
        body.dark-theme .toggle-slider:before {
            transform: translateX(24px);
        }
        
        /* Модальные окна */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1100;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
            animation: fadeIn 0.3s;
        }
        
        .modal-content {
            background-color: white;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
        }
        
        body.dark-theme .modal-content {
            background-color: #1e1e1e;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e0e0e0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        body.dark-theme .modal-header {
            border-bottom-color: #333;
        }
        
        .modal-title {
            font-size: 20px;
            font-weight: 500;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #e0e0e0;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        body.dark-theme .modal-footer {
            border-top-color: #333;
        }
        
        /* Уведомления */
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1200;
            display: flex;
            align-items: center;
            gap: 12px;
            transform: translateX(150%);
            transition: transform 0.3s;
        }
        
        .notification.active {
            transform: translateX(0);
        }
        
        .notification.success {
            background-color: #4caf50;
            color: white;
        }
        
        .notification.error {
            background-color: #f44336;
            color: white;
        }
        
        .notification.info {
            background-color: #2196f3;
            color: white;
        }
        
        /* Адаптивность */
        @media (max-width: 992px) {
            .col-6 {
                width: 100%;
                flex: 0 0 100%;
            }
            
            .sidebar {
                width: 100%;
                transform: translateX(-100%);
            }
            
            .main-content.with-sidebar {
                margin-left: 0;
            }
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 10px;
            }
            
            header {
                padding: 12px 16px;
            }
            
            .logo h1 {
                font-size: 20px;
            }
            
            .card {
                padding: 16px;
            }
        }
        
        /* Лоадер */
        .loader {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #6200ee;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        /* Экран авторизации */
        .auth-container {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background-color: #f5f5f5;
        }
        
        body.dark-theme .auth-container {
            background-color: #121212;
        }
        
        .auth-card {
            width: 100%;
            max-width: 400px;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        
        body.dark-theme .auth-card {
            background-color: #1e1e1e;
            box-shadow: 0 8px 24px rgba(0,0,0,0.3);
        }
        
        .auth-title {
            text-align: center;
            margin-bottom: 30px;
            font-size: 24px;
            font-weight: 500;
        }
        
        /* Хлебные крошки */
        .breadcrumb {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .breadcrumb-item {
            display: flex;
            align-items: center;
            font-size: 14px;
        }
        
        .breadcrumb-item:not(:last-child):after {
            content: "chevron_right";
            font-family: 'Material Icons';
            font-size: 18px;
            margin: 0 8px;
            color: #757575;
        }
        
        .breadcrumb-item a {
            color: #6200ee;
            text-decoration: none;
        }
        
        body.dark-theme .breadcrumb-item a {
            color: #bb86fc;
        }
        
        .breadcrumb-item a:hover {
            text-decoration: underline;
        }
        
        .breadcrumb-item.active {
            color: #757575;
        }
        
        /* Пагинация */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
        }
        
        .pagination-btn {
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            background-color: transparent;
            border: none;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .pagination-btn:hover {
            background-color: rgba(0,0,0,0.1);
        }
        
        body.dark-theme .pagination-btn:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        .pagination-btn.active {
            background-color: #6200ee;
            color: white;
        }
        
        body.dark-theme .pagination-btn.active {
            background-color: #bb86fc;
            color: #000;
        }
        
        /* Кнопка меню */
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            cursor: pointer;
            font-size: 24px;
        }
        
        @media (max-width: 992px) {
            .menu-toggle {
                display: block;
            }
        }
        
        /* SQL редактор */
        .sql-editor {
            position: relative;
        }
        
        .sql-editor-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .sql-history {
            margin-top: 20px;
        }
        
        .history-item {
            padding: 8px 12px;
            background-color: #f5f5f5;
            border-radius: 4px;
            margin-bottom: 8px;
            font-family: monospace;
            font-size: 13px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        body.dark-theme .history-item {
            background-color: #2c2c2c;
        }
        
        .history-item:hover {
            background-color: #e0e0e0;
        }
        
        body.dark-theme .history-item:hover {
            background-color: #3c3c3c;
        }
    </style>
</head>
<body class="<?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'dark-theme' : ''; ?>">
    <?php if (!$authenticated): ?>
    <!-- Экран авторизации -->
    <div class="auth-container">
        <div class="auth-card card animated fadeIn">
            <h2 class="auth-title">SQL Панель управления</h2>
            <form id="loginForm">
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input type="password" id="password" class="form-control" placeholder="Введите пароль" required>
                    <div class="form-text">Пароль по умолчанию: Gosha123</div>
                </div>
                <button type="submit" class="btn btn-primary" style="width: 100%;">
                    <span class="btn-text">Войти</span>
                    <div class="loader" style="display: none;"></div>
                </button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <!-- Основной интерфейс -->
    <header>
        <div class="logo">
            <button class="menu-toggle" id="menuToggle">
                <span class="material-icons">menu</span>
            </button>
            <span class="material-icons logo-icon">storage</span>
            <h1>SQL Панель управления</h1>
        </div>
        <div class="header-actions">
            <div class="theme-toggle">
                <span class="material-icons">light_mode</span>
                <label class="toggle-slider">
                    <input type="checkbox" id="themeToggle" <?php echo isset($_COOKIE['dark_theme']) && $_COOKIE['dark_theme'] === 'true' ? 'checked' : ''; ?>>
                </label>
                <span class="material-icons">dark_mode</span>
            </div>
            <button class="btn btn-outline btn-small" id="changePasswordBtn">
                <span class="material-icons">password</span>
                Сменить пароль
            </button>
            <button class="btn btn-outline btn-small" id="logoutBtn">
                <span class="material-icons">logout</span>
                Выйти
            </button>
        </div>
    </header>
    
    <!-- Боковая панель -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <h3 class="sidebar-title">База данных</h3>
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
            <div class="breadcrumb" id="breadcrumb">
                <div class="breadcrumb-item"><a href="#" id="homeLink">Главная</a></div>
            </div>
            
            <!-- Карточка SQL редактора -->
            <div class="card animated fadeIn" id="sqlEditorCard">
                <div class="card-header">
                    <h2 class="card-title">SQL Редактор</h2>
                    <button class="btn btn-primary" id="executeSqlBtn">
                        <span class="material-icons">play_arrow</span>
                        Выполнить
                    </button>
                </div>
                <div class="form-group">
                    <label for="sqlQuery">SQL запрос</label>
                    <textarea id="sqlQuery" class="form-control" placeholder="Введите SQL запрос...">SHOW TABLES;</textarea>
                </div>
                <div id="sqlResults">
                    <!-- Результаты SQL запросов будут отображаться здесь -->
                </div>
            </div>
            
            <!-- Карточка данных таблицы -->
            <div class="card animated fadeIn" id="tableDataCard" style="display: none;">
                <div class="card-header">
                    <h2 class="card-title" id="tableTitle">Данные таблицы</h2>
                    <div>
                        <button class="btn btn-secondary" id="refreshTableBtn">
                            <span class="material-icons">refresh</span>
                            Обновить
                        </button>
                        <button class="btn btn-primary" id="addRowBtn" style="margin-left: 8px;">
                            <span class="material-icons">add</span>
                            Добавить
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
    <div class="modal" id="changePasswordModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Смена пароля</h3>
                <button class="btn btn-small" id="closeChangePasswordModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body">
                <?php if (!$password_changed): ?>
                <div class="notification info" style="position: relative; top: 0; right: 0; transform: none; margin-bottom: 20px;">
                    <span class="material-icons">info</span>
                    <span>Рекомендуется сменить пароль по умолчанию на более сложный</span>
                </div>
                <?php endif; ?>
                <form id="changePasswordForm">
                    <div class="form-group">
                        <label for="currentPassword">Текущий пароль</label>
                        <input type="password" id="currentPassword" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="newPassword">Новый пароль</label>
                        <input type="password" id="newPassword" class="form-control" required minlength="6">
                    </div>
                    <div class="form-group">
                        <label for="confirmPassword">Подтверждение пароля</label>
                        <input type="password" id="confirmPassword" class="form-control" required minlength="6">
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="cancelChangePassword">Отмена</button>
                <button class="btn btn-primary" id="savePasswordBtn">
                    <span class="btn-text">Сохранить</span>
                    <div class="loader" style="display: none;"></div>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Модальное окно добавления/редактирования строки -->
    <div class="modal" id="editRowModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="editModalTitle">Добавление строки</h3>
                <button class="btn btn-small" id="closeEditRowModal">
                    <span class="material-icons">close</span>
                </button>
            </div>
            <div class="modal-body">
                <form id="editRowForm">
                    <!-- Поля формы будут динамически добавлены -->
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="cancelEditRow">Отмена</button>
                <button class="btn btn-primary" id="saveRowBtn">
                    <span class="btn-text">Сохранить</span>
                    <div class="loader" style="display: none;"></div>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Уведомления -->
    <div class="notification" id="notification"></div>
    
    <script>
        // Глобальные переменные
        let currentTable = '';
        let sqlHistory = [];
        let currentEditingRow = null;
        
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
                        showNotification(data.message, 'success');
                        
                        // Если пароль не менялся, показываем уведомление
                        if (!data.password_changed) {
                            setTimeout(() => {
                                showNotification('Рекомендуется сменить пароль по умолчанию', 'info', 5000);
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
            
            // Фокус на поле пароля
            passwordInput.focus();
        }
        
        // Функция инициализации основной панели
        function initMainPanel() {
            // Загружаем список таблиц
            loadTables();
            
            // Инициализация элементов интерфейса
            initUIElements();
            
            // Выполняем начальный SQL запрос
            executeSql('SHOW TABLES;');
            
            // Если пароль не менялся, показываем модальное окно смены пароля
            <?php if (!$password_changed): ?>
            setTimeout(() => {
                document.getElementById('changePasswordModal').classList.add('active');
            }, 1000);
            <?php endif; ?>
        }
        
        // Функция инициализации элементов UI
        function initUIElements() {
            // Кнопка меню для мобильных устройств
            document.getElementById('menuToggle').addEventListener('click', function() {
                document.getElementById('sidebar').classList.toggle('active');
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
                    location.reload();
                });
            });
            
            // Кнопка смены пароля
            document.getElementById('changePasswordBtn').addEventListener('click', function() {
                document.getElementById('changePasswordModal').classList.add('active');
            });
            
            // Закрытие модального окна смены пароля
            document.getElementById('closeChangePasswordModal').addEventListener('click', function() {
                document.getElementById('changePasswordModal').classList.remove('active');
            });
            
            document.getElementById('cancelChangePassword').addEventListener('click', function() {
                document.getElementById('changePasswordModal').classList.remove('active');
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
                        document.getElementById('changePasswordModal').classList.remove('active');
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
                }
            });
            
            // Ссылка "Главная" в хлебных крошках
            document.getElementById('homeLink').addEventListener('click', function(e) {
                e.preventDefault();
                showSqlEditor();
            });
            
            // Кнопка обновления таблицы
            document.getElementById('refreshTableBtn').addEventListener('click', function() {
                if (currentTable) {
                    loadTableData(currentTable);
                }
            });
            
            // Кнопка добавления строки
            document.getElementById('addRowBtn').addEventListener('click', function() {
                if (currentTable) {
                    showAddRowForm(currentTable);
                }
            });
            
            // Закрытие модального окна редактирования строки
            document.getElementById('closeEditRowModal').addEventListener('click', function() {
                document.getElementById('editRowModal').classList.remove('active');
            });
            
            document.getElementById('cancelEditRow').addEventListener('click', function() {
                document.getElementById('editRowModal').classList.remove('active');
            });
            
            // Обработка нажатия Ctrl+Enter в SQL редакторе
            document.getElementById('sqlQuery').addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 'Enter') {
                    document.getElementById('executeSqlBtn').click();
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
                li.textContent = table;
                li.dataset.table = table;
                
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
                    if (window.innerWidth <= 992) {
                        document.getElementById('sidebar').classList.remove('active');
                    }
                });
                
                tableList.appendChild(li);
            });
        }
        
        // Функция загрузки данных таблицы
        function loadTableData(table) {
            currentTable = table;
            
            // Обновляем хлебные крошки
            updateBreadcrumb(table);
            
            // Показываем карточку с данными таблицы
            document.getElementById('tableDataCard').style.display = 'block';
            document.getElementById('sqlEditorCard').style.display = 'none';
            
            // Обновляем заголовок
            document.getElementById('tableTitle').textContent = `Таблица: ${table}`;
            
            // Показываем лоадер
            const container = document.getElementById('tableDataContainer');
            container.innerHTML = '<div style="text-align: center; padding: 40px;"><div class="loader" style="width: 40px; height: 40px; margin: 0 auto;"></div><p style="margin-top: 16px;">Загрузка данных...</p></div>';
            
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
                    renderTableData(data.columns, data.rows, table);
                } else {
                    showNotification(data.message, 'error');
                    container.innerHTML = '<div class="notification error" style="position: relative; top: 0; right: 0; transform: none;"><span class="material-icons">error</span><span>Ошибка загрузки данных</span></div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showNotification('Ошибка загрузки данных таблицы', 'error');
                container.innerHTML = '<div class="notification error" style="position: relative; top: 0; right: 0; transform: none;"><span class="material-icons">error</span><span>Ошибка сети</span></div>';
            });
        }
        
        // Функция отрисовки данных таблицы
        function renderTableData(columns, rows, table) {
            const container = document.getElementById('tableDataContainer');
            
            if (rows.length === 0) {
                container.innerHTML = '<div class="notification info" style="position: relative; top: 0; right: 0; transform: none;"><span class="material-icons">info</span><span>Таблица пуста</span></div>';
                return;
            }
            
            // Создаем таблицу
            let html = '<table>';
            
            // Заголовки таблицы
            html += '<thead><tr>';
            columns.forEach(column => {
                html += `<th>${column.name}<br><small>${column.type}</small></th>`;
            });
            html += '<th>Действия</th>';
            html += '</tr></thead>';
            
            // Тело таблицы
            html += '<tbody>';
            rows.forEach(row => {
                html += '<tr>';
                columns.forEach(column => {
                    const value = row[column.name] !== null ? row[column.name] : '<span style="color: #999; font-style: italic;">NULL</span>';
                    html += `<td>${value}</td>`;
                });
                
                // Кнопки действий
                html += `<td>
                    <button class="btn btn-outline btn-small edit-row-btn" data-table="${table}" data-row='${JSON.stringify(row)}'>
                        <span class="material-icons">edit</span>
                    </button>
                    <button class="btn btn-outline btn-small delete-row-btn" data-table="${table}" data-row='${JSON.stringify(row)}' style="margin-left: 4px;">
                        <span class="material-icons">delete</span>
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
                    
                    if (confirm('Вы уверены, что хотите удалить эту строку?')) {
                        deleteRow(table, row);
                    }
                });
            });
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
                    document.getElementById('editRowModal').classList.add('active');
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
                    document.getElementById('editRowModal').classList.add('active');
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
                } else if (column.type.includes('date') || column.type.includes('time')) {
                    input = document.createElement('input');
                    input.type = column.type.includes('date') ? 'date' : 'time';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                } else {
                    input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control';
                    input.id = `field_${column.name}`;
                    input.name = column.name;
                    input.value = value;
                }
                
                // Если поле обязательное и не имеет значения по умолчанию
                if (column.nullable === false && !column.default) {
                    input.required = true;
                    label.innerHTML += ' <span style="color: #f44336;">*</span>';
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
                    formData[column.name] = input.value;
                    
                    // Если поле пустое и nullable, устанавливаем NULL
                    if (input.value === '' && column.nullable) {
                        formData[column.name] = null;
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
                    document.getElementById('editRowModal').classList.remove('active');
                    
                    // Перезагружаем данные таблицы
                    if (currentTable === table) {
                        loadTableData(table);
                    }
                }
                
                // Скрываем лоадер
                btnText.style.display = 'inline';
                loader.style.display = 'none';
                saveBtn.disabled = false;
            });
        }
        
        // Функция удаления строки
        function deleteRow(table, row) {
            const whereClause = Object.keys(row)
                .map(key => `\`${key}\` = '${escapeSql(row[key])}'`)
                .join(' AND ');
            
            const sql = `DELETE FROM \`${table}\` WHERE ${whereClause};`;
            
            executeSql(sql, true).then(success => {
                if (success && currentTable === table) {
                    loadTableData(table);
                }
            });
        }
        
        // Функция выполнения SQL запроса
        function executeSql(sql, silent = false) {
            return new Promise((resolve) => {
                if (!silent) {
                    // Показываем лоадер в результатах
                    const resultsDiv = document.getElementById('sqlResults');
                    resultsDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><div class="loader" style="width: 30px; height: 30px; margin: 0 auto;"></div><p style="margin-top: 12px;">Выполнение запроса...</p></div>';
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
                        }
                        resolve(true);
                    } else {
                        if (!silent) {
                            showNotification(data.message, 'error');
                            const resultsDiv = document.getElementById('sqlResults');
                            resultsDiv.innerHTML = `<div class="notification error" style="position: relative; top: 0; right: 0; transform: none; margin-top: 20px;">
                                <span class="material-icons">error</span>
                                <span>${data.message}</span>
                            </div>`;
                        }
                        resolve(false);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (!silent) {
                        showNotification('Ошибка сети', 'error');
                    }
                    resolve(false);
                });
            });
        }
        
        // Функция отрисовки результатов SQL запроса
        function renderSqlResults(results, affectedRows, sql) {
            const resultsDiv = document.getElementById('sqlResults');
            resultsDiv.innerHTML = '';
            
            if (results.length === 0 && affectedRows > 0) {
                // Запрос без результата (INSERT, UPDATE, DELETE и т.д.)
                resultsDiv.innerHTML = `
                    <div class="notification success" style="position: relative; top: 0; right: 0; transform: none; margin-top: 20px;">
                        <span class="material-icons">check_circle</span>
                        <span>Запрос выполнен успешно. Затронуто строк: ${affectedRows}</span>
                    </div>
                `;
                return;
            }
            
            // Отображаем результаты каждого запроса
            results.forEach((result, index) => {
                if (result.columns && result.rows) {
                    const tableDiv = document.createElement('div');
                    tableDiv.className = 'card';
                    tableDiv.style.marginTop = index > 0 ? '20px' : '0';
                    
                    tableDiv.innerHTML = `
                        <div class="card-header">
                            <h3 class="card-title">Результат ${index + 1}</h3>
                            <span>Найдено строк: ${result.row_count}</span>
                        </div>
                        <div class="table-container">
                            <table>
                                <thead>
                                    <tr>
                                        ${result.columns.map(col => `<th>${col}</th>`).join('')}
                                    </tr>
                                </thead>
                                <tbody>
                                    ${result.rows.map(row => `
                                        <tr>
                                            ${result.columns.map(col => `<td>${row[col] !== null ? row[col] : '<span style="color: #999; font-style: italic;">NULL</span>'}</td>`).join('')}
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    `;
                    
                    resultsDiv.appendChild(tableDiv);
                }
            });
            
            // Если результатов нет, показываем сообщение
            if (results.length === 0) {
                resultsDiv.innerHTML = `
                    <div class="notification info" style="position: relative; top: 0; right: 0; transform: none; margin-top: 20px;">
                        <span class="material-icons">info</span>
                        <span>Запрос выполнен успешно</span>
                    </div>
                `;
            }
        }
        
        // Функция добавления запроса в историю
        function addToSqlHistory(sql) {
            // Добавляем запрос в начало массива
            sqlHistory.unshift({
                sql: sql,
                timestamp: new Date().toLocaleTimeString()
            });
            
            // Ограничиваем историю 10 последними запросами
            if (sqlHistory.length > 10) {
                sqlHistory.pop();
            }
            
            // Обновляем отображение истории
            renderSqlHistory();
        }
        
        // Функция отрисовки истории SQL запросов
        function renderSqlHistory() {
            const sqlEditorCard = document.getElementById('sqlEditorCard');
            let historySection = sqlEditorCard.querySelector('.sql-history');
            
            // Если раздела истории нет, создаем его
            if (!historySection) {
                historySection = document.createElement('div');
                historySection.className = 'sql-history';
                sqlEditorCard.appendChild(historySection);
            }
            
            if (sqlHistory.length === 0) {
                historySection.innerHTML = '<p>История запросов пуста</p>';
                return;
            }
            
            let html = '<h3 style="margin-top: 20px; margin-bottom: 12px;">История запросов</h3>';
            
            sqlHistory.forEach((item, index) => {
                html += `
                    <div class="history-item" data-index="${index}">
                        <div style="font-size: 11px; color: #757575; margin-bottom: 4px;">${item.timestamp}</div>
                        <div style="font-family: monospace; font-size: 13px;">${item.sql.length > 100 ? item.sql.substring(0, 100) + '...' : item.sql}</div>
                    </div>
                `;
            });
            
            historySection.innerHTML = html;
            
            // Добавляем обработчики кликов на элементы истории
            document.querySelectorAll('.history-item').forEach(item => {
                item.addEventListener('click', function() {
                    const index = this.dataset.index;
                    document.getElementById('sqlQuery').value = sqlHistory[index].sql;
                });
            });
        }
        
        // Функция показа SQL редактора
        function showSqlEditor() {
            document.getElementById('tableDataCard').style.display = 'none';
            document.getElementById('sqlEditorCard').style.display = 'block';
            
            // Обновляем хлебные крошки
            updateBreadcrumb();
            
            // Снимаем выделение с таблиц в боковой панели
            document.querySelectorAll('.table-item').forEach(item => {
                item.classList.remove('active');
            });
            
            currentTable = '';
        }
        
        // Функция обновления хлебных крошек
        function updateBreadcrumb(table = null) {
            const breadcrumb = document.getElementById('breadcrumb');
            
            if (!table) {
                breadcrumb.innerHTML = '<div class="breadcrumb-item"><a href="#" id="homeLink">Главная</a></div>';
            } else {
                breadcrumb.innerHTML = `
                    <div class="breadcrumb-item"><a href="#" id="homeLink">Главная</a></div>
                    <div class="breadcrumb-item active">${table}</div>
                `;
                
                // Обновляем обработчик для ссылки "Главная"
                document.getElementById('homeLink').addEventListener('click', function(e) {
                    e.preventDefault();
                    showSqlEditor();
                });
            }
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
                });
            }
        }
        
        // Функция показа уведомления
        function showNotification(message, type = 'info', duration = 3000) {
            const notification = document.getElementById('notification');
            
            // Устанавливаем тип и сообщение
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <span class="material-icons">${type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info'}</span>
                <span>${message}</span>
            `;
            
            // Показываем уведомление
            notification.classList.add('active');
            
            // Скрываем через указанное время
            setTimeout(() => {
                notification.classList.remove('active');
            }, duration);
        }
        
        // Функция экранирования SQL строк
        function escapeSql(str) {
            if (str === null) return '';
            return String(str).replace(/'/g, "''");
        }
    </script>
</body>
</html>
