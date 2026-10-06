<?php
/** GET /api/forest/admin-access.php: есть ли у текущего игрока права «Креатива» (для клиента, ?creative=1). */

require_once __DIR__ . '/lib/common.php';

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

try {
    forest_require_method('GET');
    $conn = forest_db();
    $userId = forest_require_player($conn, false);
    $role = forest_admin_role($conn, $userId);

    forest_json_out(['is_admin' => $role !== null, 'role' => $role]);
} catch (Throwable $error) {
    forest_fail($error);
}
