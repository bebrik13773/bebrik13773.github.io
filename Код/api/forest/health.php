<?php
/** GET /api/forest/health: проверка работы леса и версии схемы. Сама создаёт/обновляет схему. */

require_once __DIR__ . '/lib/common.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    forest_require_method('GET');

    if (!forest_ip_guard()) {
        forest_error('rate_limited');
    }

    $conn = forest_db(); // подключение + автомиграция схемы
    $version = forest_read_schema_version($conn);

    forest_json_out([
        'service' => 'wild-forest',
        'beta' => (bool) forest_config('beta.enabled'),
        'schema_version' => $version,
        'schema_expected' => FOREST_SCHEMA_VERSION,
        'schema_ok' => $version === FOREST_SCHEMA_VERSION,
        'config_version' => (int) forest_config('version'),
        'server_ts' => time(),
        'server_ms' => forest_now_ms(),
        'php' => PHP_VERSION,
    ]);
} catch (Throwable $error) {
    forest_fail($error);
}
