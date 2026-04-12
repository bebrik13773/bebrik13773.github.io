<?php
// tracker_api.php
// Клади рядом с tracker_db_config.php — он генерируется автоматически при деплое.
// Вручную ничего не заполнять.

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Подключаем конфиг, сгенерированный GitHub Actions из секретов
$cfgFile = __DIR__ . '/tracker_db_config.php';
if (!file_exists($cfgFile)) {
    http_response_code(503);
    echo json_encode([
        'ok'    => false,
        'error' => 'tracker_db_config.php не найден',
        'hint'  => 'Файл генерируется автоматически при деплое через GitHub Actions (секреты BOBER_DB_*)',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = require $cfgFile;

define('DB_HOST', $cfg['db_host']);
define('DB_NAME', $cfg['db_name']);
define('DB_USER', $cfg['db_user']);
define('DB_PASS', $cfg['db_pass']);
define('USER_KEY', 'bober');

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tracker_data (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user       VARCHAR(64)  NOT NULL UNIQUE,
            data       LONGTEXT     NOT NULL,
            updated_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
                                    ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    return $pdo;
}

try {
    $db     = getDB();
    $method = $_SERVER['REQUEST_METHOD'];

    // ---- GET: загрузить данные ----
    if ($method === 'GET') {
        $stmt = $db->prepare('SELECT data, updated_at FROM tracker_data WHERE user = ?');
        $stmt->execute([USER_KEY]);
        $row = $stmt->fetch();

        echo json_encode(
            $row
                ? ['ok' => true, 'data' => json_decode($row['data'], true), 'updated_at' => $row['updated_at']]
                : ['ok' => true, 'data' => null],
            JSON_UNESCAPED_UNICODE
        );

    // ---- POST: сохранить данные ----
    } elseif ($method === 'POST') {
        $body   = file_get_contents('php://input');
        $parsed = json_decode($body, true);

        if (!$parsed || !is_array($parsed)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Невалидный JSON'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // base64-картинка → сохраняем файлом, в БД кладём URL
        if (!empty($parsed['imgSrc']) && str_starts_with($parsed['imgSrc'], 'data:image/')) {
            preg_match('/^data:(image\/(\w+));base64,(.+)$/s', $parsed['imgSrc'], $m);
            if ($m) {
                $ext     = in_array(strtolower($m[2]), ['jpeg','jpg','png','webp','gif']) ? strtolower($m[2]) : 'png';
                $raw     = base64_decode($m[3]);
                $fname   = 'tracker_img_bober.' . $ext;
                if ($raw && file_put_contents(__DIR__ . '/' . $fname, $raw) !== false) {
                    $proto          = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $dir            = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                    $parsed['imgSrc'] = $proto . '://' . $_SERVER['HTTP_HOST'] . $dir . '/' . $fname;
                }
            }
        }

        $stmt = $db->prepare('
            INSERT INTO tracker_data (user, data)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE data = VALUES(data)
        ');
        $stmt->execute([USER_KEY, json_encode($parsed, JSON_UNESCAPED_UNICODE)]);

        echo json_encode(['ok' => true, 'saved_at' => date('c')], JSON_UNESCAPED_UNICODE);

    } else {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => 'Ошибка БД: ' . $e->getMessage(),
        'hint'  => 'Проверь GitHub Secrets: BOBER_DB_HOST, BOBER_DB_USER, BOBER_DB_PASS, BOBER_DB_NAME',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
