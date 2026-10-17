<?php
/**
 * Дикий Лес: страница самопроверки сервера (ДЛ-03).
 * PHP в контейнере разработки не запускается, поэтому серверную часть проверяем здесь, на хостинге.
 * Доступ: пароль администратора (тот же, что в админке). Новые проверки добавляются в своих пунктах плана.
 */
require_once dirname(__DIR__) . '/api/forest/lib/common.php';
require_once dirname(__DIR__) . '/api/forest/lib/player.php';
require_once dirname(__DIR__) . '/api/forest/lib/sync.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

function wf_st_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">';
echo '<meta name="viewport" content="width=device-width,initial-scale=1"><title>forest-selftest</title>';
echo '<style>body{font:14px/1.5 system-ui,sans-serif;margin:16px;max-width:900px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:4px 8px;text-align:left;vertical-align:top}th{background:#f3f3f3}.ok{color:#0a7a2f;font-weight:700}.fail{color:#b00020;font-weight:700}</style></head><body>';
echo '<h1>Дикий Лес: самопроверка</h1>';

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

if (!$authorized) {
    if ($error !== '') {
        echo '<p class="fail">' . wf_st_h($error) . '</p>';
    }
    echo '<form method="post"><label>Пароль администратора: <input type="password" name="password" autofocus></label> <button>Запустить</button></form></body></html>';
    exit;
}

$results = [];

/** Одна проверка: $fn возвращает [ожидалось, получено] (строки) или бросает исключение. */
function wf_st_test(array &$results, $name, callable $fn)
{
    try {
        [$expected, $actual] = $fn();
        $results[] = [$name, (string) $expected, (string) $actual, (string) $expected === (string) $actual];
    } catch (Throwable $e) {
        $results[] = [$name, 'без ошибки', 'ошибка: ' . $e->getMessage(), false];
    }
}

try {
    if (!$conn) {
        $conn = bober_db_connect();
    }
    forest_ensure_schema($conn);
} catch (Throwable $e) {
    echo '<p class="fail">Не удалось подготовить схему: ' . wf_st_h($e->getMessage()) . '</p></body></html>';
    exit;
}

// 1. Конфиг
wf_st_test($results, 'Конфиг читается, есть версия', function () {
    return ['да', is_int(forest_config('version')) ? 'да' : 'нет'];
});
wf_st_test($results, 'Конфиг: все группы на месте', function () {
    $need = ['world', 'time', 'hero', 'trees', 'economy', 'buildings', 'upgrades', 'dam', 'weather', 'trade', 'helpers', 'beasts', 'mine', 'rating', 'events', 'limits'];
    $missing = array_values(array_diff($need, array_keys(forest_config())));
    return ['нет пропусков', $missing ? 'нет: ' . implode(', ', $missing) : 'нет пропусков'];
});
wf_st_test($results, 'Конфиг: шансы погоды по сезонам в сумме 100', function () {
    $bad = [];
    foreach (forest_config('weather.table') as $season => $row) {
        if (array_sum($row) !== 100) {
            $bad[] = $season . '=' . array_sum($row);
        }
    }
    return ['все 100', $bad ? implode(', ', $bad) : 'все 100'];
});
wf_st_test($results, 'Конфиг: 10 пород деревьев', function () {
    return [10, count(forest_config('trees.species'))];
});
wf_st_test($results, 'Конфиг: путь с точками (hero.walk_speed)', function () {
    return ['3.2', (string) forest_config('hero.walk_speed')];
});
wf_st_test($results, 'Конфиг: прямой заход на config.php закрыт', function () {
    $ok = strpos((string) file_get_contents(dirname(__DIR__) . '/api/forest/config.php'), 'http_response_code(404)') !== false;
    return ['да', $ok ? 'да' : 'нет'];
});

// 2. Схема
wf_st_test($results, 'Версия схемы актуальна', function () use ($conn) {
    return [FOREST_SCHEMA_VERSION, forest_read_schema_version($conn)];
});
wf_st_test($results, 'Все таблицы леса существуют', function () use ($conn) {
    $have = [];
    $res = $conn->query("SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'forest\\_%'");
    while ($res && ($row = $res->fetch_row())) {
        $have[] = (string) $row[0];
    }
    $missing = array_values(array_diff(array_keys(forest_expected_tables()), $have));
    return ['нет пропусков', $missing ? 'нет: ' . implode(', ', $missing) : 'нет пропусков'];
});
wf_st_test($results, 'Все таблицы леса InnoDB', function () use ($conn) {
    $bad = [];
    $res = $conn->query("SELECT table_name, engine FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE 'forest\\_%'");
    while ($res && ($row = $res->fetch_row())) {
        if (strcasecmp((string) $row[1], 'InnoDB') !== 0) {
            $bad[] = $row[0] . '=' . $row[1];
        }
    }
    return ['все InnoDB', $bad ? implode(', ', $bad) : 'все InnoDB'];
});
wf_st_test($results, 'Тип player_id совпадает с users.id', function () use ($conn) {
    $type = function ($table, $col) use ($conn) {
        $stmt = $conn->prepare('SELECT COLUMN_TYPE FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        $stmt->bind_param('ss', $table, $col);
        $stmt->execute();
        $stmt->bind_result($t);
        $stmt->fetch();
        $stmt->close();
        return (string) $t;
    };
    return [$type('users', 'id'), $type('forest_players', 'player_id')];
});
wf_st_test($results, 'Повторная миграция не ломает схему', function () use ($conn) {
    forest_migration_1($conn);
    forest_migration_2($conn);
    forest_migration_3($conn);
    return [FOREST_SCHEMA_VERSION, forest_read_schema_version($conn)];
});

// 3. Мета и транзакции
wf_st_test($results, 'forest_meta: запись и чтение', function () use ($conn) {
    $value = 'ok-' . mt_rand(1000, 9999);
    forest_write_meta($conn, 'selftest', $value);
    $res = $conn->query("SELECT `v` FROM `forest_meta` WHERE `k` = 'selftest'");
    $row = $res ? $res->fetch_row() : null;
    $conn->query("DELETE FROM `forest_meta` WHERE `k` = 'selftest'");
    return [$value, $row ? $row[0] : 'нет строки'];
});
wf_st_test($results, 'forest_tx: откат при исключении', function () use ($conn) {
    try {
        forest_tx($conn, function ($c) {
            $c->query("INSERT INTO `forest_meta` (`k`, `v`) VALUES ('selftest_tx', '1')");
            throw new RuntimeException('откат');
        });
    } catch (RuntimeException $e) {
        // ожидаемо
    }
    $res = $conn->query("SELECT COUNT(*) FROM `forest_meta` WHERE `k` = 'selftest_tx'");
    $row = $res->fetch_row();
    return [0, (int) $row[0]];
});
wf_st_test($results, 'forest_tx: фиксация при успехе', function () use ($conn) {
    forest_tx($conn, function ($c) {
        $c->query("INSERT INTO `forest_meta` (`k`, `v`) VALUES ('selftest_tx2', '1')");
    });
    $res = $conn->query("SELECT COUNT(*) FROM `forest_meta` WHERE `k` = 'selftest_tx2'");
    $row = $res->fetch_row();
    $conn->query("DELETE FROM `forest_meta` WHERE `k` = 'selftest_tx2'");
    return [1, (int) $row[0]];
});
wf_st_test($results, 'SELECT ... FOR UPDATE в транзакции', function () use ($conn) {
    forest_write_meta($conn, 'selftest_lock', '1');
    $ok = forest_tx($conn, function ($c) {
        $res = $c->query("SELECT `v` FROM `forest_meta` WHERE `k` = 'selftest_lock' FOR UPDATE");
        return $res instanceof mysqli_result && $res->fetch_row() !== null;
    });
    $conn->query("DELETE FROM `forest_meta` WHERE `k` = 'selftest_lock'");
    return ['да', $ok ? 'да' : 'нет'];
});
wf_st_test($results, 'forest_log: запись в журнал', function () use ($conn) {
    $kind = 'selftest';
    forest_log($conn, 0, $kind, ['a' => 1]);
    $res = $conn->query("SELECT COUNT(*) FROM `forest_log` WHERE `kind` = 'selftest'");
    $row = $res->fetch_row();
    $conn->query("DELETE FROM `forest_log` WHERE `kind` = 'selftest'");
    return ['да', ((int) $row[0]) >= 1 ? 'да' : 'нет'];
});

wf_st_test($results, 'Права администратора леса: выдача, проверка, отзыв', function () use ($conn) {
    $fakeId = 2147483000; // несуществующий игрок
    forest_admin_grant($conn, $fakeId, 'creative', 'selftest');
    $granted = forest_is_admin($conn, $fakeId, ['creative']);
    $wrongRole = forest_is_admin($conn, $fakeId, ['moderator']);
    $revoked = forest_admin_revoke($conn, $fakeId);
    $after = forest_admin_role($conn, $fakeId);
    $line = ($granted ? 'выдано' : 'не выдано') . ', ' . ($wrongRole ? 'чужая роль проходит' : 'чужая роль нет') . ', ' . ($revoked ? 'забрано' : 'не забрано') . ', ' . ($after === null ? 'роли нет' : 'роль осталась');
    return ['выдано, чужая роль нет, забрано, роли нет', $line];
});

// 4. Лимит частоты и каталог ошибок
wf_st_test($results, 'Лимит частоты: первый вызов проходит', function () {
    return ['да', forest_rate_limit('selftest:' . mt_rand(), 5000) ? 'да' : 'нет'];
});
wf_st_test($results, 'Лимит частоты: повтор сразу отклоняется', function () {
    $key = 'selftest:' . mt_rand();
    forest_rate_limit($key, 5000);
    return ['нет', forest_rate_limit($key, 5000) ? 'да' : 'нет'];
});
wf_st_test($results, 'Лимит частоты: нулевой интервал не блокирует', function () {
    $key = 'selftest:' . mt_rand();
    forest_rate_limit($key, 0);
    return ['да', forest_rate_limit($key, 0) ? 'да' : 'нет'];
});
wf_st_test($results, 'Каталог кодов ошибок полон', function () {
    $need = ['too_fast', 'too_far', 'no_energy', 'no_tree', 'tree_gone', 'bad_state', 'no_items', 'no_space', 'rate_limited', 'forbidden', 'outdated', 'unauthorized'];
    $missing = array_values(array_diff($need, array_keys(forest_error_catalog())));
    return ['нет пропусков', $missing ? 'нет: ' . implode(', ', $missing) : 'нет пропусков'];
});
wf_st_test($results, 'Хранилище лимитов частоты', function () {
    $mode = (function_exists('apcu_enabled') && apcu_enabled()) ? 'apcu' : 'файл';
    return [$mode, $mode]; // информационная строка: всегда зелёная
});

// 4а. Управление (ДЛ-08): формулы скорости и допуск движения, те же числа, что в shared/formulas.js (tests/controls.test.mjs)
require_once dirname(__DIR__) . '/api/forest/lib/formulas.php';
wf_st_test($results, 'Скорость бобра налегке 3.2 м/с', function () {
    return ['3.2', (string) round(wf_hero_speed(), 6)];
});
wf_st_test($results, 'Скорость с полным рюкзаком 1.44 м/с', function () {
    return ['1.44', (string) round(wf_hero_speed(40, 40), 6)];
});
wf_st_test($results, 'Скорость по асфальту 4.64 м/с', function () {
    return ['4.64', (string) round(wf_hero_speed(0, 40, 3), 6)];
});
wf_st_test($results, 'Скорость в сапогах 3.52 м/с', function () {
    return ['3.52', (string) round(wf_hero_speed(0, 40, 0, true), 6)];
});
wf_st_test($results, 'Допуск движения за 1 с: 7.32 м', function () {
    return ['7.32', (string) round(wf_move_allowance(3.2, 1), 6)];
});
wf_st_test($results, 'Журнал позиций: честный проходит, рывок на 40 м за секунду нет', function () {
    $honest = [['t' => 0, 'x' => 0, 'z' => 0], ['t' => 1, 'x' => 3.2, 'z' => 0], ['t' => 2, 'x' => 6.4, 'z' => 0]];
    $cheat = [['t' => 0, 'x' => 0, 'z' => 0], ['t' => 1, 'x' => 40, 'z' => 0]];
    return ['0 1', count(wf_check_track($honest, 3.2)) . ' ' . count(wf_check_track($cheat, 3.2))];
});

// 4б. Рубка (ДЛ-11): формулы по тем же векторам, что и в JS (tests/chop.test.mjs), таблица кейсов проверки действия, страйки, находки
require_once dirname(__DIR__) . '/api/forest/lib/chop.php';
function wf_st_chop_vectors()
{
    static $v = null;
    if ($v === null) {
        $raw = @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/chop.json');
        $v = json_decode((string) $raw, true);
        if (!is_array($v) || empty($v['trees'])) {
            throw new RuntimeException('нет файла векторов рубки: games/wild-forest/tests/vectors/chop.json');
        }
    }
    return $v;
}
wf_st_test($results, 'Рубка: прочность, число ударов, энергия и брёвна совпадают с JS на всех векторах', function () {
    $bad = [];
    $rows = wf_st_chop_vectors()['trees'];
    foreach ($rows as $r) {
        [$sp, $size, $tier, $axe, $hp, $need, $cost, $logs] = $r;
        $power = wf_chop_power($axe);
        $gotHp = wf_chop_hp($sp, $size, $tier);
        $gotNeed = wf_chop_need($gotHp, $power);
        if (abs($gotHp - $hp) > 1e-5 || $gotNeed !== (int) $need || abs(wf_chop_cost($gotNeed) - $cost) > 1e-5 || wf_chop_logs($sp, $size) !== (int) $logs) {
            $bad[] = implode(',', $r);
        }
    }
    return ['0 расхождений из ' . count($rows), count($bad) . ' расхождений из ' . count($rows) . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Рубка: бюджет тапов (ведро 40, +6 в секунду) совпадает с JS', function () {
    $bad = 0;
    $rows = wf_st_chop_vectors()['taps'];
    foreach ($rows as $r) {
        $t = wf_tap_take((float) $r[0], (int) $r[1], (int) $r[2], (float) $r[3]);
        if ((int) $t['ok'] !== (int) $r[4] || abs($t['tokens'] - $r[5]) > 1e-5) {
            $bad++;
        }
    }
    return ['0 расхождений', $bad . ' расхождений'];
});
$wfChopOk = ['kind' => 1, 'cleared' => false, 'felled' => false, 'dist_tree' => 2.0, 'dist_prev' => 2.5, 'dist_new' => 2.5, 'allow' => 20.0, 'hits' => 10, 'need' => 8, 'energy' => 50.0, 'cost' => 4.0, 'tokens' => 40.0, 'window_ms' => 2000.0];
$wfChopCases = [
    ['честная рубка принимается', [], null],
    ['не дерево: no_tree', ['kind' => 0], 'no_tree'],
    ['дерево в расчищенном круге: no_tree', ['cleared' => true], 'no_tree'],
    ['дальше 3.5 м от дерева: too_far', ['dist_tree' => 4.0], 'too_far'],
    ['заявленная позиция далеко от прошлой позиции сервера: too_far', ['dist_prev' => 99.0], 'too_far'],
    ['заявленная позиция далеко от новой позиции: too_far', ['dist_new' => 99.0], 'too_far'],
    ['дерево уже срублено: tree_gone', ['felled' => true], 'tree_gone'],
    ['ударов меньше прочности: too_fast', ['hits' => 7], 'too_fast'],
    ['слишком много ударов: bad_request', ['hits' => 200], 'bad_request'],
    ['окно ударов короче возможного (8 ударов за 0.5 с): too_fast', ['window_ms' => 500.0], 'too_fast'],
    ['не хватает энергии: no_energy', ['energy' => 3.0], 'no_energy'],
    ['пустое ведро тапов: too_fast', ['tokens' => 5.0], 'too_fast'],
];
foreach ($wfChopCases as $case) {
    wf_st_test($results, 'Рубка: ' . $case[0], function () use ($wfChopOk, $case) {
        $v = wf_chop_validate(array_merge($wfChopOk, $case[1]));
        return [(string) ($case[2] ?? 'ok'), (string) ($v['error'] ?? 'ok')];
    });
}
wf_st_test($results, 'Рубка: tree_gone не страйк, too_far и too_fast страйк', function () use ($wfChopOk) {
    $a = wf_chop_validate(array_merge($wfChopOk, ['felled' => true]))['cheat'];
    $b = wf_chop_validate(array_merge($wfChopOk, ['dist_tree' => 9.0]))['cheat'];
    $c = wf_chop_validate(array_merge($wfChopOk, ['hits' => 1]))['cheat'];
    return ['0 1 1', (int) $a . ' ' . (int) $b . ' ' . (int) $c];
});
wf_st_test($results, 'Страйки: 1–3 мягко, 4–7 урезание награды, 8 блок, старые выходят из окна', function () {
    $list = [];
    $levels = [];
    for ($i = 1; $i <= 8; $i++) {
        $s = wf_strikes_add($list, 1000 + $i);
        $list = $s['list'];
        $levels[] = $s['level'];
    }
    $old = wf_strikes_add([1], 1000 + (int) forest_config('limits.strikes_window_s') + 5);
    return ['soft soft soft cut cut cut cut block 1', implode(' ', $levels) . ' ' . $old['count']];
});
wf_st_test($results, 'Страйки: награда урезается вдвое только после 3 активных', function () {
    return ['1 1 0.5', wf_strikes_reward_k(0) . ' ' . wf_strikes_reward_k(3) . ' ' . wf_strikes_reward_k(4)];
});
wf_st_test($results, 'Находка в сокровище и места выпадения детерминированы по дереву', function () {
    $w = wf_wg_world();
    $a = wf_chop_treasure($w, 3, -2, 17);
    $b = wf_chop_treasure($w, 3, -2, 17);
    $p1 = wf_chop_drop_points($w, 3, -2, 17, 100.0, -60.0, 4);
    $p2 = wf_chop_drop_points($w, 3, -2, 17, 100.0, -60.0, 4);
    $rMax = (float) forest_config('trees.chop.drop_r_max_m');
    $inRing = true;
    foreach ($p1 as $p) {
        $d = hypot($p['x'] - 100.0, $p['z'] + 60.0);
        $inRing = $inRing && $d >= (float) forest_config('trees.chop.drop_r_min_m') - 0.02 && $d <= $rMax + 0.02;
    }
    return ['1 1', (int) ($a === $b && $p1 === $p2) . ' ' . (int) $inRing];
});
wf_st_test($results, 'Находка в сокровище: монеты 50–300 либо предмет с количеством', function () {
    $w = wf_wg_world();
    $ok = true;
    for ($ci = 0; $ci < 64; $ci++) {
        $t = wf_chop_treasure($w, 5, 7, $ci);
        if (isset($t['coins'])) {
            $ok = $ok && $t['coins'] >= 50 && $t['coins'] <= 300;
        } else {
            $ok = $ok && !empty($t['item']) && $t['qty'] >= 1;
        }
    }
    return ['1', (string) (int) $ok];
});

// 4в. Энергия, сон и еда (ДЛ-12): формулы по тем же векторам, что и в JS (tests/energy.test.mjs), кейсы сна и еды
require_once dirname(__DIR__) . '/api/forest/lib/energy.php';
wf_st_test($results, 'Энергия: шаг расчёта (ходьба, отдых, сон, дом, офлайн) совпадает с JS на всех векторах', function () {
    $raw = @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/energy.json');
    $v = json_decode((string) $raw, true);
    if (!is_array($v) || empty($v['ticks'])) {
        throw new RuntimeException('нет файла векторов энергии: games/wild-forest/tests/vectors/energy.json');
    }
    $bad = [];
    foreach ($v['ticks'] as $r) {
        $t = wf_energy_tick(['energy' => (float) $r[0], 'max' => (float) $r[1], 'dt' => (float) $r[2], 'dist' => (float) $r[3], 'still' => (bool) $r[4], 'home' => (bool) $r[5], 'sleeping' => (bool) $r[6], 'mult' => 1.0]);
        if (abs($t['energy'] - $r[7]) > 1e-5 || (int) $t['sleeping'] !== (int) $r[8]) {
            $bad[] = implode(',', $r);
        }
    }
    return ['0 расхождений из ' . count($v['ticks']), count($bad) . ' расхождений из ' . count($v['ticks']) . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Энергия: расход на ходьбу 0.015 за метр, погода умножает', function () {
    return ['1.5 1.65', round(wf_energy_walk_cost(100), 4) . ' ' . round(wf_energy_walk_cost(100, 1.1), 4)];
});
wf_st_test($results, 'Энергия: скорость восстановления (стоя 0.1, сон 0.2, дом 1.0, в движении 0)', function () {
    return ['0.1 0 1 0.2 1', implode(' ', [wf_energy_rate(false, false, true), wf_energy_rate(false, false, false), wf_energy_rate(false, true, true), wf_energy_rate(true, false, true), wf_energy_rate(true, true, true)])];
});
wf_st_test($results, 'Энергия: на нуле бобёр засыпает, к 20 просыпается (около 100 секунд)', function () {
    $e = 0.0;
    $sleep = true;
    $t = 0;
    while ($sleep && $t < 1000) {
        $r = wf_energy_tick(['energy' => $e, 'max' => 100.0, 'dt' => 10.0, 'dist' => 0.0, 'still' => true, 'home' => false, 'sleeping' => true]);
        $e = $r['energy'];
        $sleep = $r['sleeping'];
        $t += 10;
    }
    $fell = wf_energy_tick(['energy' => 0.5, 'max' => 100.0, 'dt' => 0.0, 'dist' => 100.0, 'still' => false, 'home' => false, 'sleeping' => false]);
    return ['100 true', $t . ' ' . ($fell['sleeping'] ? 'true' : 'false')];
});
wf_st_test($results, 'Энергия: офлайн не копится (900 секунд засчитываются как 120)', function () {
    $r = wf_energy_tick(['energy' => 10.0, 'max' => 100.0, 'dt' => 900.0, 'dist' => 0.0, 'still' => true, 'home' => false, 'sleeping' => false]);
    return ['22', (string) round($r['energy'], 4)];
});
wf_st_test($results, 'Еда: рыба даёт 25 и 45, остальное не еда', function () {
    return ['25 25 45 null', implode(' ', [wf_eat_gain('fish'), wf_eat_gain('fish_small'), wf_eat_gain('fish_big'), wf_eat_gain('log') === null ? 'null' : 'нет'])];
});
wf_st_test($results, 'Энергия: засыпание после действий (ноль энергии или уже спит)', function () {
    return ['1 0 1', (int) wf_energy_settle(0.0, false) . ' ' . (int) wf_energy_settle(50.0, false) . ' ' . (int) wf_energy_settle(50.0, true)];
});

// 4г. Рюкзак, вес ноши и подбор (ДЛ-13): чистые функции по тем же векторам, что и в JS (tests/pack.test.mjs)
require_once dirname(__DIR__) . '/api/forest/lib/pack_weight.php';
function wf_st_pack_vectors()
{
    static $v = null;
    if ($v === null) {
        $v = json_decode((string) @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/pack.json'), true);
        if (!is_array($v) || empty($v['fits'])) {
            throw new RuntimeException('нет файла векторов рюкзака: games/wild-forest/tests/vectors/pack.json');
        }
    }
    return $v;
}
wf_st_test($results, 'Рюкзак: вес штуки и набора совпадает с JS на всех векторах', function () {
    $v = wf_st_pack_vectors();
    $bad = [];
    foreach ($v['kg'] as $r) {
        if (abs(wf_pack_item_kg((string) $r[0]) - (float) $r[1]) > 1e-9) {
            $bad[] = $r[0];
        }
    }
    foreach ($v['kgs'] as $r) {
        if (abs(wf_pack_kg((array) $r[0]) - (float) $r[1]) > 1e-6) {
            $bad[] = json_encode($r[0]);
        }
    }
    $n = count($v['kg']) + count($v['kgs']);
    return ['0 расхождений из ' . $n, count($bad) . ' расхождений из ' . $n . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Рюкзак: сколько влезет совпадает с JS на всех векторах', function () {
    $v = wf_st_pack_vectors();
    $bad = [];
    foreach ($v['fits'] as $r) {
        if (wf_pack_fit((float) $r[0], (float) $r[1], (string) $r[2], (int) $r[3]) !== (int) $r[4]) {
            $bad[] = implode(',', $r);
        }
    }
    return ['0 расхождений из ' . count($v['fits']), count($bad) . ' расхождений из ' . count($v['fits']) . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Рюкзак: груз для проверки скорости (брошенное в пакете вычитается) совпадает с JS', function () {
    $v = wf_st_pack_vectors();
    $bad = [];
    foreach ($v['loads'] as $r) {
        if (abs(wf_pack_move_load((array) $r[0], (array) $r[1]) - (float) $r[2]) > 1e-6) {
            $bad[] = json_encode($r);
        }
    }
    return ['0 расхождений из ' . count($v['loads']), count($bad) . ' расхождений из ' . count($v['loads']) . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 2)) . ')' : '')];
});
wf_st_test($results, 'Рюкзак: скорость с грузом совпадает с JS (вес замедляет бобра)', function () {
    $v = wf_st_pack_vectors();
    $bad = [];
    foreach ($v['speeds'] as $r) {
        if (abs(wf_hero_speed((float) $r[0], (float) $r[1]) - (float) $r[2]) > 1e-5) {
            $bad[] = implode(',', $r);
        }
    }
    return ['0 расхождений из ' . count($v['speeds']), count($bad) . ' расхождений из ' . count($v['speeds']) . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Рюкзак: вместимость по уровню 40, 50, 110, 110', function () {
    $v = wf_st_pack_vectors();
    $got = [];
    foreach ($v['capacities'] as $r) {
        $got[] = wf_player_pack_capacity((int) $r[0]);
    }
    return ['40 50 110 110', implode(' ', $got)];
});
wf_st_test($results, 'Рюкзак: полный рюкзак не проходит проверку движения на скорости пустого', function () {
    $free = wf_sync_check_move(0, 0, 25.0, 0, 8.0, 40.0, 0.0)['ok'];   // 25 м за 8 с налегке
    $full = wf_sync_check_move(0, 0, 25.0, 0, 8.0, 40.0, 40.0)['ok'];  // то же с полным рюкзаком
    $slow = wf_sync_check_move(0, 0, 10.0, 0, 8.0, 40.0, 40.0)['ok'];  // 10 м за 8 с с полным рюкзаком (честно)
    return ['1 0 1', (int) $free . ' ' . (int) $full . ' ' . (int) $slow];
});

// 5. Генерация мира (ДЛ-05): PHP должен давать те же числа, что и JS, на золотых тест-векторах
require_once dirname(__DIR__) . '/api/forest/lib/worldgen.php';
@set_time_limit(120);
$wfQuick = isset($_GET['quick']); // ?quick=1 проверяет только первую тысячу записей каждого вида
$wfStarted = microtime(true);

/** Читает тест-векторы один раз за запрос. */
function wf_st_vectors()
{
    static $v = null;
    if ($v === null) {
        $path = dirname(__DIR__) . '/games/wild-forest/tests/vectors/worldgen.json';
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('нет файла векторов: games/wild-forest/tests/vectors/worldgen.json');
        }
        $v = json_decode($raw, true);
        if (!is_array($v) || empty($v['worlds'])) {
            throw new RuntimeException('файл векторов не разобран: ' . json_last_error_msg());
        }
    }
    return $v;
}

/** Итог сверки: ожидается «0 расхождений из N», получено — число и первые примеры. */
function wf_st_vec_result($total, array $bad)
{
    $expected = '0 расхождений из ' . $total;
    $actual = count($bad) . ' расхождений из ' . $total;
    if ($bad) {
        $actual .= '; первые: ' . implode(' | ', array_slice($bad, 0, 3));
    }
    return [$expected, $actual];
}

function wf_st_rows(array $rows, $quick)
{
    return $quick ? array_slice($rows, 0, 1000) : $rows;
}

wf_st_test($results, 'Мир: mul32 без выхода за 64 бита', function () {
    return ['1,6,0', implode(',', [wf_wg_mul32(0xFFFFFFFF, 0xFFFFFFFF), wf_wg_mul32(2, 3), wf_wg_mul32(0, 5)])];
});
wf_st_test($results, 'Мир: mix(1) = 0x514E28B7 (murmur3 fmix32)', function () {
    return [1364076727, wf_wg_mix(1)];
});
wf_st_test($results, 'Мир: деление с округлением вниз для отрицательных', function () {
    return ['-1,-1,-2,0', implode(',', [wf_wg_fdiv(-1, 6000), wf_wg_fdiv(-6000, 6000), wf_wg_fdiv(-6001, 6000), wf_wg_fdiv(5999, 6000)])];
});
wf_st_test($results, 'Мир: целый корень на границах', function () {
    return ['1200,1199,1414213', implode(',', [wf_wg_isqrt(1440000), wf_wg_isqrt(1439999), wf_wg_isqrt(2 * 1000000 * 1000000)])];
});
wf_st_test($results, 'Мир: данные пород и биомов читаются (10 пород, 6 биомов)', function () {
    $d = wf_wg_load_data();
    return ['10 и 6', count($d['species']['species']) . ' и ' . count($d['biomes']['biomes'])];
});
wf_st_test($results, 'Мир: сид в config.php равен 1337 (как в клиенте)', function () {
    return [1337, (int) forest_config('world.seed')];
});
wf_st_test($results, 'Мир: разбор id дерева', function () {
    $ok = wf_wg_parse_tree_id('3:-2:17') === [3, -2, 17]
        && wf_wg_parse_tree_id('1:2:64') === null
        && wf_wg_parse_tree_id("1:2:3\n") === null
        && wf_wg_parse_tree_id('a:b:c') === null
        && wf_wg_tree_id(3, -2, 17) === '3:-2:17';
    return ['да', $ok ? 'да' : 'нет'];
});
wf_st_test($results, 'Мир: векторы хеша (JS и PHP совпадают)', function () use ($wfQuick) {
    $rows = wf_st_rows(wf_st_vectors()['hashes'], $wfQuick);
    $bad = [];
    foreach ($rows as $i => $r) {
        $got = wf_wg_hash($r[0], $r[1], $r[2], $r[3]);
        if ($got !== $r[4]) {
            $bad[] = '#' . $i . ' seed=' . $r[0] . ' x=' . $r[1] . ' z=' . $r[2] . ' salt=' . $r[3] . ': ждали ' . $r[4] . ', получили ' . $got;
        }
    }
    return wf_st_vec_result(count($rows), $bad);
});
wf_st_test($results, 'Мир: векторы шума и fbm', function () use ($wfQuick) {
    $rows = wf_st_rows(wf_st_vectors()['noises'], $wfQuick);
    $bad = [];
    foreach ($rows as $i => $r) {
        $n = wf_wg_noise2($r[0], $r[1], $r[2], $r[3], $r[4]);
        $f = wf_wg_fbm($r[0], $r[1], $r[2], $r[3] * 8 > 6000 ? 6000 : $r[3] * 8, $r[4]);
        if ($n !== $r[5] || $f !== $r[6]) {
            $bad[] = '#' . $i . ' seed=' . $r[0] . ' X=' . $r[1] . ' Z=' . $r[2] . ' P=' . $r[3] . ': ждали ' . $r[5] . '/' . $r[6] . ', получили ' . $n . '/' . $f;
        }
    }
    return wf_st_vec_result(count($rows), $bad);
});
foreach ([0, 1] as $wfWorldIndex) {
    wf_st_test($results, 'Мир: точки (поля, высота, биом, tier, зона, город), сид мира №' . ($wfWorldIndex + 1), function () use ($wfQuick, $wfWorldIndex) {
        $world = wf_st_vectors()['worlds'][$wfWorldIndex];
        $w = wf_wg_make((int) $world['seed']);
        $rows = wf_st_rows($world['points'], $wfQuick);
        $bad = [];
        foreach ($rows as $i => $r) {
            [$X, $Z] = $r;
            $E = wf_wg_field_e($w, $X, $Z);
            $M = wf_wg_field_m($w, $X, $Z);
            $C = wf_wg_field_c($w, $X, $Z);
            $R = wf_wg_field_r($w, $X, $Z);
            $D = wf_wg_field_d($w, $X, $Z);
            $got = [$X, $Z, $E, $M, $C, $R, $D, wf_wg_height_from_fields($w, $E, $R, $D), wf_wg_biome_from_fields($w, $E, $M, $C, $R),
                wf_wg_tier_dm($w, $X, $Z), wf_wg_zone_dm($w, $X, $Z), wf_wg_is_city($w, $X, $Z) ? 1 : 0, wf_wg_inside_world($w, $X, $Z) ? 1 : 0];
            // каждую 8-ю точку дополнительно проверяем через «полные» функции высоты и биома
            if ($i % 8 === 0 && (wf_wg_height_dm($w, $X, $Z) !== $r[7] || wf_wg_biome_dm($w, $X, $Z) !== $r[8])) {
                $bad[] = '#' . $i . ' X=' . $X . ' Z=' . $Z . ': wf_wg_height_dm/biome_dm расходятся с вектором';
            } elseif ($got !== $r) {
                $bad[] = '#' . $i . ' X=' . $X . ' Z=' . $Z . ': ждали [' . implode(',', $r) . '], получили [' . implode(',', $got) . ']';
            }
        }
        return wf_st_vec_result(count($rows), $bad);
    });
    wf_st_test($results, 'Мир: клетки (дерево, порода, размер, редкость, сокровище, булыжник, руда), сид мира №' . ($wfWorldIndex + 1), function () use ($wfQuick, $wfWorldIndex) {
        $world = wf_st_vectors()['worlds'][$wfWorldIndex];
        $w = wf_wg_make((int) $world['seed']);
        $rows = wf_st_rows($world['cells'], $wfQuick);
        $bad = [];
        foreach ($rows as $i => $r) {
            $c = wf_wg_cell($w, $r[0], $r[1], $r[2]);
            $got = [$r[0], $r[1], $r[2], $c['kind'], $c['ox'], $c['oz'], $c['species'], $c['size'], $c['rare'], $c['treasure'], $c['biome']];
            if ($got !== $r) {
                $bad[] = '#' . $i . ' клетка ' . wf_wg_tree_id($r[0], $r[1], $r[2]) . ': ждали [' . implode(',', $r) . '], получили [' . implode(',', $got) . ']';
            }
        }
        return wf_st_vec_result(count($rows), $bad);
    });
}

// 6. Появление игрока (ДЛ-10): те же места, что в JS (tests/vectors/spawn.json), проверка перемещения, уровни, запись игрока в БД
function wf_st_spawn_vectors()
{
    static $v = null;
    if ($v === null) {
        $raw = @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/spawn.json');
        $v = $raw === false ? null : json_decode($raw, true);
        if (!is_array($v) || empty($v['alone'])) {
            throw new RuntimeException('нет или не разобран games/wild-forest/tests/vectors/spawn.json');
        }
    }
    return $v;
}
wf_st_test($results, 'Появление: одиночные места совпадают с JS (122 игрока)', function () {
    $w = wf_wg_world();
    $bad = [];
    $rows = wf_st_spawn_vectors()['alone'];
    foreach ($rows as $r) {
        $spot = wf_spawn_find($w, (int) $r['id'], function () { return true; });
        $pond = $spot ? wf_spawn_pond((int) $r['id'], $spot['x'], $spot['z']) : null;
        $okSpot = $spot && $spot['x'] === $r['spot']['x'] && $spot['z'] === $r['spot']['z'] && $spot['attempt'] === $r['spot']['attempt'];
        $okPond = $pond && $pond['x'] === $r['pond']['x'] && $pond['z'] === $r['pond']['z'] && $pond['damX'] === $r['pond']['damX'] && $pond['damZ'] === $r['pond']['damZ'] && $pond['deg'] === $r['pond']['deg'];
        if (!$okSpot || !$okPond) {
            $bad[] = 'игрок ' . $r['id'] . ': ждали ' . json_encode($r['spot']) . ', получили ' . json_encode($spot);
        }
    }
    return wf_st_vec_result(count($rows), $bad);
});
wf_st_test($results, 'Появление: цепочка из 60 игроков с занятыми местами совпадает с JS', function () {
    $w = wf_wg_world();
    $spots = [];
    $cells = [];
    $gapCfg = (int) forest_config('world.spawn_min_gap_m');
    $isFree = function (int $x, int $z, int $gap) use (&$spots, &$cells) {
        foreach ($spots as $s) {
            if (abs($s['x'] - $x) <= $gap && abs($s['z'] - $z) <= $gap) {
                return false;
            }
        }
        $c = wf_spawn_cell($x, $z);
        if (isset($cells[$c])) {
            return false;
        }
        $cells[$c] = true;
        return true;
    };
    $bad = [];
    $rows = wf_st_spawn_vectors()['chain'];
    foreach ($rows as $r) {
        $spot = wf_spawn_find($w, (int) $r['id'], $isFree);
        if ($spot) {
            $spots[] = $spot;
        }
        if (!$spot || $spot['x'] !== $r['spot']['x'] || $spot['z'] !== $r['spot']['z'] || $spot['attempt'] !== $r['spot']['attempt']) {
            $bad[] = 'игрок ' . $r['id'] . ': ждали ' . json_encode($r['spot']) . ', получили ' . json_encode($spot);
        }
    }
    return wf_st_vec_result(count($rows), $bad);
});
wf_st_test($results, 'Появление: ключ клетки с отрицательными числами', function () {
    return ['0:0,-1:-1,-1:0,1:-2', implode(',', [wf_spawn_cell(0, 0), wf_spawn_cell(-1, -1), wf_spawn_cell(-150, 149), wf_spawn_cell(150, -151)])];
});
wf_st_test($results, 'Уровни: кривая 100 × l^1.6 как в JS', function () {
    $bad = [];
    $v = wf_st_spawn_vectors();
    foreach ($v['levels'] as $r) {
        if (wf_xp_to_next((int) $r['level']) !== $r['next']) {
            $bad[] = 'уровень ' . $r['level'] . ': ждали ' . $r['next'] . ', получили ' . wf_xp_to_next((int) $r['level']);
        }
    }
    foreach ($v['xps'] as $r) {
        $got = wf_level_from_xp((int) $r['xp']);
        if ($got['level'] !== $r['level'] || $got['into'] !== $r['into'] || $got['need'] !== $r['need']) {
            $bad[] = 'опыт ' . $r['xp'] . ': ждали ' . json_encode([$r['level'], $r['into'], $r['need']]) . ', получили ' . json_encode(array_values($got));
        }
    }
    return wf_st_vec_result(count($v['levels']) + count($v['xps']), $bad);
});
wf_st_test($results, 'Перемещение в sync: честный шаг проходит, телепорт отклоняется', function () {
    $ok = wf_sync_check_move(0, 0, 20, 0, 5.0)['ok'];          // 20 м за 5 с (допуск ~ 45 м)
    $far = wf_sync_check_move(0, 0, 500, 0, 5.0)['ok'];        // 500 м за 5 с
    $capped = wf_sync_check_move(0, 0, 5000, 0, 100000.0)['ok']; // долгий офлайн не даёт телепорта
    return ['да,нет,нет', ($ok ? 'да' : 'нет') . ',' . ($far ? 'да' : 'нет') . ',' . ($capped ? 'да' : 'нет')];
});
wf_st_test($results, 'Разбор позиции sync и интервалы', function () {
    $a = wf_sync_parse_pos(['x' => 10, 'z' => -5.5, 'ry' => 1.2]);
    $okParse = $a === [10.0, -5.5, 1.2] && wf_sync_parse_pos(['x' => 'a', 'z' => 1]) === null && wf_sync_parse_pos(['x' => 1e9, 'z' => 0]) === null && wf_sync_parse_pos(null) === null;
    $okNext = wf_sync_next_ms(0) === 8000 && wf_sync_next_ms(31) === 15000 && wf_sync_next_ms(0, true) === 60000;
    return ['да', ($okParse && $okNext) ? 'да' : 'нет'];
});
wf_st_test($results, 'Игрок: join создаёт поляну, пруд, плотину; повтор безопасен (тестовый id, потом уборка)', function () use ($conn) {
    $id = 2147000001;
    $clean = function () use ($conn, $id) {
        foreach (['forest_players' => 'player_id', 'forest_dams' => 'player_id', 'forest_inventory' => 'player_id', 'forest_log' => 'player_id', 'forest_chunk_changes' => 'by_player'] as $table => $col) {
            $conn->query('DELETE FROM `' . $table . '` WHERE `' . $col . '` = ' . $id);
        }
    };
    $clean();
    try {
        [$row, $created] = wf_player_join($conn, $id);
        [$row2, $created2] = wf_player_join($conn, $id);
        $full = wf_player_full_state($conn, $row2);
        $dam = $conn->query('SELECT COUNT(*) FROM `forest_dams` WHERE `player_id` = ' . $id)->fetch_row()[0];
        $notes = [];
        if (!$created || $created2) { $notes[] = 'created=' . var_export($created, true) . '/' . var_export($created2, true); }
        if (count($full['clearings']) !== 2) { $notes[] = 'поляны: ' . count($full['clearings']); }
        if (!$full['pond'] || !isset($full['pond']['dam'])) { $notes[] = 'нет пруда'; }
        if ((int) $dam !== 1) { $notes[] = 'плотин: ' . $dam; }
        if ($full['state']['coins'] !== (int) forest_config('hero.start_coins') || (float) $full['state']['energy'] !== 100.0) { $notes[] = 'стартовое состояние'; }
        $r = hypot($full['pos']['x'], $full['pos']['z']);
        if ($r < 600 || $r > 6000) { $notes[] = 'дальность ' . round($r); }
        if ($full['pos']['x'] !== $full['village']['x']) { $notes[] = 'позиция не на поляне'; }
        return ['ok', $notes ? implode('; ', $notes) : 'ok'];
    } finally {
        $clean();
    }
});

wf_st_test($results, 'Мир: время сверки векторов, секунд' . ($wfQuick ? ' (режим quick)' : ' (полный прогон)'), function () use ($wfStarted) {
    $sec = round(microtime(true) - $wfStarted, 1);
    return [(string) $sec, (string) $sec]; // информационная строка: всегда зелёная
});

$total = count($results);
$passed = count(array_filter($results, function ($r) { return $r[3]; }));
echo '<p class="' . ($passed === $total ? 'ok' : 'fail') . '">Пройдено ' . $passed . ' из ' . $total . '</p>';
echo '<table><tr><th>Тест</th><th>Ожидается</th><th>Получено</th><th>Статус</th></tr>';
foreach ($results as $r) {
    echo '<tr><td>' . wf_st_h($r[0]) . '</td><td>' . wf_st_h($r[1]) . '</td><td>' . wf_st_h($r[2]) . '</td><td class="' . ($r[3] ? 'ok' : 'fail') . '">' . ($r[3] ? 'ОК' : 'ОШИБКА') . '</td></tr>';
}
echo '</table><p>Полный прогон векторов мира занимает несколько секунд; если хостинг не успевает, добавьте к адресу <code>?quick=1</code>.</p><p>Здоровье API: <code>/api/forest/health.php</code>. Страницу после релиза (ДЛ-40) оставить только за доступом владельца.</p></body></html>';
