<?php
/**
 * ДЛ-01: временная страница диагностики хостинга для «Дикого Леса».
 * Показывает версии PHP/MySQL, ini-лимиты и размер базы, чтобы выписать реальные лимиты.
 * Доступ: пароль администратора (тот же, что в админке). После использования файл удалить.
 */
require_once dirname(__DIR__) . '/api/bootstrap/db.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function wf_env_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$authorized = false;
$error = '';
$conn = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $conn = bober_db_connect();
        $hash = bober_fetch_admin_password_hash($conn);
        if (!$hash) {
            $hash = bober_configured_admin_password_hash();
        }
        $password = (string) ($_POST['password'] ?? '');
        if ($hash && $password !== '' && password_verify($password, $hash)) {
            $authorized = true;
        } else {
            $error = 'Неверный пароль.';
        }
    } catch (Throwable $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">';
echo '<meta name="viewport" content="width=device-width,initial-scale=1"><title>forest-env</title>';
echo '<style>body{font:14px/1.5 system-ui,sans-serif;margin:16px;max-width:760px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:4px 8px;text-align:left;vertical-align:top}th{background:#f3f3f3}</style></head><body>';
echo '<h1>Дикий Лес: окружение хостинга</h1>';

if (!$authorized) {
    if ($error !== '') {
        echo '<p style="color:#b00">' . wf_env_h($error) . '</p>';
    }
    echo '<form method="post"><label>Пароль администратора: <input type="password" name="password" autofocus></label> <button>Показать</button></form>';
    echo '</body></html>';
    exit;
}

$rows = [];
$rows[] = ['Дата проверки (сервер)', date('Y-m-d H:i:s T')];
$rows[] = ['Версия PHP', PHP_VERSION];
$rows[] = ['PHP SAPI', PHP_SAPI];
$rows[] = ['PHP_INT_SIZE (байт)', PHP_INT_SIZE];
$rows[] = ['memory_limit', ini_get('memory_limit')];
$rows[] = ['max_execution_time (с)', ini_get('max_execution_time')];
$rows[] = ['post_max_size', ini_get('post_max_size')];
$rows[] = ['upload_max_filesize', ini_get('upload_max_filesize')];
$rows[] = ['max_input_vars', ini_get('max_input_vars')];
$rows[] = ['zlib.output_compression', ini_get('zlib.output_compression')];
foreach (['mysqli', 'mbstring', 'openssl', 'curl', 'json', 'zlib', 'gd', 'intl', 'apcu'] as $ext) {
    $rows[] = ['расширение ' . $ext, extension_loaded($ext) ? 'есть' : 'нет'];
}
$rows[] = ['sys_getloadavg', function_exists('sys_getloadavg') ? implode(' / ', array_map(function ($v) { return round($v, 2); }, (array) sys_getloadavg())) : 'недоступно'];
$rows[] = ['disk_free_space (МБ)', @disk_free_space(__DIR__) !== false ? round(disk_free_space(__DIR__) / 1048576) : 'недоступно'];

try {
    if (!$conn) {
        $conn = bober_db_connect();
    }
    $rows[] = ['Версия MySQL/MariaDB', $conn->server_info];

    $one = function ($sql, $col = 0) use ($conn) {
        $res = $conn->query($sql);
        if (!$res instanceof mysqli_result) {
            return 'недоступно';
        }
        $row = $res->fetch_row();
        $res->free();
        return $row ? $row[$col] : 'нет данных';
    };

    $rows[] = ['max_connections', $one("SELECT @@max_connections")];
    $rows[] = ['max_user_connections', $one("SELECT @@max_user_connections")];
    $rows[] = ['max_allowed_packet (байт)', $one("SELECT @@max_allowed_packet")];
    $rows[] = ['default_storage_engine', $one("SELECT @@default_storage_engine")];
    $rows[] = ['transaction_isolation', $one("SELECT @@transaction_isolation")];
    $rows[] = ['GET_LOCK доступен', $one("SELECT GET_LOCK('wf_env_probe', 0)") == 1 ? 'да' : 'нет'];
    $conn->query("SELECT RELEASE_LOCK('wf_env_probe')");

    $dbName = $one('SELECT DATABASE()');
    $rows[] = ['База данных', $dbName];
    $rows[] = ['Размер базы (МБ)', $one("SELECT ROUND(SUM(data_length+index_length)/1048576,2) FROM information_schema.tables WHERE table_schema = DATABASE()")];
    $rows[] = ['Таблиц в базе', $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")];
    $rows[] = ['Таблицы InnoDB', $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine = 'InnoDB'")];
    $rows[] = ['Таблицы MyISAM', $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND engine = 'MyISAM'")];
    $rows[] = ['Строк в users', $one("SELECT COUNT(*) FROM `users`")];
    $rows[] = ['Тип users.id', $one("SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'id'")];

    // Проверка SELECT ... FOR UPDATE внутри транзакции (откатывается).
    $forUpdate = 'нет';
    if ($conn->begin_transaction()) {
        $probe = $conn->query("SELECT id FROM `users` ORDER BY id ASC LIMIT 1 FOR UPDATE");
        $forUpdate = ($probe instanceof mysqli_result) ? 'да' : 'нет';
        if ($probe instanceof mysqli_result) {
            $probe->free();
        }
        $conn->rollback();
    }
    $rows[] = ['SELECT ... FOR UPDATE работает', $forUpdate];

    $t0 = microtime(true);
    for ($i = 0; $i < 20; $i++) {
        $conn->query('SELECT 1');
    }
    $rows[] = ['20 запросов SELECT 1 (мс)', round((microtime(true) - $t0) * 1000, 1)];
} catch (Throwable $e) {
    $rows[] = ['Ошибка БД', $e->getMessage()];
}

echo '<table><tr><th>Параметр</th><th>Значение</th></tr>';
foreach ($rows as $row) {
    echo '<tr><td>' . wf_env_h($row[0]) . '</td><td>' . wf_env_h($row[1]) . '</td></tr>';
}
echo '</table>';
echo '<p>Лимиты запросов в сутки/минуту, процессорного времени и размер БД на тарифе смотри в панели хостинга и в условиях провайдера (InfinityFree). Результат запиши в <code>Код/docs/WILD_FOREST.md</code> с датой. После использования файл удали.</p>';
echo '</body></html>';
