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

// 4д. Ящик-тайник и скупщик на поляне (ДЛ-14): чистые функции цены, лимита, вместимости и мест (tests/crate.test.mjs проверяет клиентскую часть)
require_once dirname(__DIR__) . '/api/forest/lib/crate.php';
wf_st_test($results, 'Скупщик: цена штуки 60% базовой, чужой товар не берёт', function () {
    return ['6 10.8 0', wf_buyer_unit('log') . ' ' . wf_buyer_unit('fish_small') . ' ' . wf_buyer_unit('plank')];
});
wf_st_test($results, 'Скупщик: выручка округляется вниз по итогу пачки', function () {
    return ['396 32 0', wf_buyer_total('log', 66) . ' ' . wf_buyer_total('fish_small', 3) . ' ' . wf_buyer_total('plank', 5)];
});
wf_st_test($results, 'Скупщик: сколько возьмёт под остаток лимита', function () {
    $got = [wf_buyer_max_qty('log', 100, 400), wf_buyer_max_qty('log', 100, 5), wf_buyer_max_qty('log', 100, 6), wf_buyer_max_qty('log', 3, 400), wf_buyer_max_qty('plank', 5, 400), wf_buyer_max_qty('log', 5, 0)];
    return ['66 0 1 3 0 0', implode(' ', $got)];
});
wf_st_test($results, 'Скупщик: выручка за взятое не превышает остаток лимита ни при каком количестве', function () {
    for ($left = 0; $left <= 400; $left += 7) {
        foreach (['log', 'fish_small', 'fish_big'] as $item) {
            $q = wf_buyer_max_qty($item, 1000, $left);
            if ($q > 0 && wf_buyer_total($item, $q) > $left) {
                return ['не больше ' . $left, wf_buyer_total($item, $q) . ' (' . $item . ' × ' . $q . ')'];
            }
            if (wf_buyer_total($item, $q + 1) <= $left && $q < 1000) {
                return ['взято максимум', $item . ' × ' . $q . ' при ' . $left . ': влезло бы ещё'];
            }
        }
    }
    return ['ok', 'ok'];
});
wf_st_test($results, 'Скупщик: остаток лимита по суткам (другой день сбрасывает, этот вычитает)', function () {
    $now = 1759950000; // 2025-10-09 по Омску
    $today = wf_day_key($now);
    $a = wf_buyer_left(['sold' => [$today, 150]], $now);
    $b = wf_buyer_left(['sold' => ['2000-01-01', 150]], $now);
    $c = wf_buyer_left([], $now);
    $d = wf_buyer_left(['sold' => [$today, 999]], $now);
    return ['250 400 400 0', $a . ' ' . $b . ' ' . $c . ' ' . $d];
});
wf_st_test($results, 'Сутки: граница по часовому поясу игры (Омск, полночь в 18:00 UTC)', function () {
    return ['2025-10-08 2025-10-09', wf_day_key(1759946399) . ' ' . wf_day_key(1759946400)];
});
wf_st_test($results, 'Ящик: вместимость и занятые места', function () {
    return ['5 2 0 5', wf_crate_units(['log' => 3, 'plank' => 2]) . ' ' . wf_crate_fit(100, 98, 5) . ' ' . wf_crate_fit(100, 100, 1) . ' ' . wf_crate_fit(100, 0, 5)];
});
wf_st_test($results, 'Поляна: места ящика и подводы от центра', function () {
    $s = wf_village_spots(100.0, 200.0);
    return ['103.5,197.5 96,203', $s['crate'][0] . ',' . $s['crate'][1] . ' ' . $s['buyer'][0] . ',' . $s['buyer'][1]];
});

// 4ж. Постройки (ДЛ-16): чистые функции по тем же векторам, что и в JS (tests/build.test.mjs, tests/vectors/build.json)
require_once dirname(__DIR__) . '/api/forest/lib/build.php';
function wf_st_build_vectors()
{
    static $v = null;
    if ($v === null) {
        $v = json_decode((string) @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/build.json'), true);
        if (!is_array($v) || empty($v['places'])) {
            throw new RuntimeException('нет файла векторов построек: games/wild-forest/tests/vectors/build.json');
        }
    }
    return $v;
}
wf_st_test($results, 'Постройки: цены и монеты из config.php совпадают с каталогом JS', function () {
    $v = wf_st_build_vectors();
    $bad = [];
    foreach ($v['costs'] as $r) {
        $spec = wf_build_spec((string) $r[0]);
        $got = $spec ? wf_build_cost($spec) : null;
        $want = (array) $r[1];
        if ($got === null) {
            $bad[] = $r[0] . ': нет в конфиге';
            continue;
        }
        ksort($got);
        ksort($want);
        if ($got != $want || wf_build_coins($spec) !== (int) $r[2]) {
            $bad[] = $r[0];
        }
    }
    return ['0 расхождений из ' . count($v['costs']), count($bad) . ' расхождений из ' . count($v['costs']) . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Постройки: наклон, стадии и повороты совпадают с JS на всех векторах', function () {
    $v = wf_st_build_vectors();
    $bad = [];
    foreach ($v['slopes'] as $r) {
        if (abs(wf_build_slope_deg((array) $r[0], (float) $r[1]) - (float) $r[2]) > 1e-5) {
            $bad[] = 'наклон ' . json_encode($r[0]);
        }
    }
    foreach ($v['stages'] as $r) {
        if (wf_build_stage((int) $r[0], (int) $r[1]) !== (string) $r[2]) {
            $bad[] = 'стадия ' . $r[0] . '/' . $r[1];
        }
    }
    foreach ($v['rots'] as $r) {
        if (wf_build_rot((float) $r[0]) !== (int) $r[1]) {
            $bad[] = 'поворот ' . $r[0];
        }
    }
    foreach ($v['circles'] as $r) {
        if (wf_build_circles_overlap((float) $r[0], (float) $r[1], (float) $r[2], (float) $r[3], (float) $r[4], (float) $r[5], (float) $r[6]) !== (bool) $r[7]) {
            $bad[] = 'круги ' . json_encode($r);
        }
    }
    $n = count($v['slopes']) + count($v['stages']) + count($v['rots']) + count($v['circles']);
    return ['0 расхождений из ' . $n, count($bad) . ' расхождений из ' . $n . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Постройки: возврат ресурсов при отмене и сносе совпадает с JS', function () {
    $v = wf_st_build_vectors();
    $bad = [];
    foreach ($v['refunds'] as $r) {
        $k = wf_build_refund_k((string) $r[0], (int) $r[1], (int) $r[2]);
        if ($r[3] === null ? $k !== null : ($k === null || abs($k - (float) $r[3]) > 1e-9)) {
            $bad[] = 'доля ' . json_encode($r);
        }
    }
    foreach ($v['refund_items'] as $r) {
        $got = wf_build_refund((array) wf_build_spec((string) $r[0]), (float) $r[1]);
        $want = (array) $r[2];
        $wi = (array) $want['items'];
        $gi = $got['items'];
        ksort($wi);
        ksort($gi);
        if ($gi != $wi || $got['coins'] !== (int) $want['coins']) {
            $bad[] = 'возврат ' . $r[0] . ' x' . $r[1];
        }
    }
    $n = count($v['refunds']) + count($v['refund_items']);
    return ['0 расхождений из ' . $n, count($bad) . ' расхождений из ' . $n . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});
wf_st_test($results, 'Постройки: тапы, списание из запасов и проверка места совпадают с JS', function () {
    $v = wf_st_build_vectors();
    $bad = [];
    foreach ($v['taps'] as $r) {
        $t = wf_build_tap_plan((int) $r[0], (int) $r[1], (float) $r[2], (float) $r[3], (float) $r[4]);
        if ($t['n'] !== (int) $r[5] || $t['limit'] !== (string) $r[6]) {
            $bad[] = 'тапы ' . json_encode($r);
        }
    }
    foreach ($v['takes'] as $r) {
        $plan = wf_build_plan_take((array) $r[0], (array) $r[1]);
        if (json_encode($plan) !== json_encode($r[2])) {
            $bad[] = 'списание ' . json_encode($r[1]);
        }
    }
    foreach ($v['places'] as $r) {
        $err = wf_build_place_error((array) $r[0]);
        if ($err !== $r[1]) {
            $bad[] = 'место ' . json_encode($r[1]);
        }
    }
    $n = count($v['taps']) + count($v['takes']) + count($v['places']);
    return ['0 расхождений из ' . $n, count($bad) . ' расхождений из ' . $n . ($bad ? ' (' . implode(' | ', array_slice($bad, 0, 3)) . ')' : '')];
});

// 4з. Улучшения бобра (ДЛ-18): цены из config.php и выбор покупки (чистые функции без БД)
require_once dirname(__DIR__) . '/api/forest/lib/upgrades.php';
wf_st_test($results, 'Улучшения: цены топора, рюкзака, выносливости и фонаря из config.php', function () {
    $bad = [];
    $axe = wf_upg_spec('axe', 3);
    if ($axe === null || $axe['coins'] !== 1200 || ($axe['items']['ingot'] ?? 0) !== 5) {
        $bad[] = 'топор 3';
    }
    if (wf_upg_spec('axe', 1) !== null || wf_upg_spec('axe', 7) !== null) {
        $bad[] = 'границы топора';
    }
    $pack = wf_upg_spec('pack', 8);
    if ($pack === null || $pack['coins'] !== 12000 || $pack['items'] !== []) {
        $bad[] = 'рюкзак 8';
    }
    $lamp = wf_upg_spec('lamp', 4);
    if ($lamp === null || $lamp['coins'] !== 8000 || ($lamp['items']['ingot'] ?? 0) !== 8 || wf_upg_spec('lamp', 0) !== null) {
        $bad[] = 'фонарь';
    }
    if (wf_upg_max('axe') !== 6 || wf_upg_max('pack') !== 8 || wf_upg_max('stamina') !== 6 || wf_upg_max('lamp') !== 4) {
        $bad[] = 'максимумы';
    }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Улучшения: выбор покупки (следующий уровень, максимум, разовые вещи)', function () {
    $lv = ['axe' => 1, 'pack' => 8, 'stamina' => 1, 'lamp' => 0];
    $bad = [];
    $r = wf_upg_resolve('axe', 2, $lv, [], ['axe']);
    if (!$r['ok'] || $r['to'] !== 2 || $r['spec']['coins'] !== 400) {
        $bad[] = 'топор 2';
    }
    if (wf_upg_resolve('axe', 3, $lv, [], ['axe'])['error'] !== 'stale') {
        $bad[] = 'stale';
    }
    if (wf_upg_resolve('pack', null, $lv, [], ['axe'])['error'] !== 'max_level') {
        $bad[] = 'max_level';
    }
    if (wf_upg_resolve('lamp', null, $lv, [], ['axe'])['spec']['coins'] !== 150) {
        $bad[] = 'фонарь 1';
    }
    if (wf_upg_resolve('pickaxe', null, $lv, [], ['axe', 'pickaxe'])['error'] !== 'owned') {
        $bad[] = 'кирка есть';
    }
    if (wf_upg_resolve('pickaxe', null, $lv, [], ['a', 'b', 'c', 'd', 'e', 'f'])['error'] !== 'no_space') {
        $bad[] = 'нет слота';
    }
    if (wf_upg_resolve('boots', null, $lv, ['boots' => 1], ['axe'])['error'] !== 'owned' || !wf_upg_resolve('boots', null, $lv, [], ['axe'])['ok']) {
        $bad[] = 'сапоги';
    }
    if (wf_upg_resolve('sword', null, $lv, [], ['axe'])['error'] !== 'unknown') {
        $bad[] = 'unknown';
    }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});

// 4и. Сад (ДЛ-19): места посадки, сезон, рост по корзинам времени и засыхание (чистые функции без БД)
require_once dirname(__DIR__) . '/api/forest/lib/garden.php';
wf_st_test($results, 'Сад: места посадки и семена', function () {
    $bad = [];
    if (wf_garden_slots(1) !== 6 || wf_garden_slots(2) !== 10 || wf_garden_slots(3) !== 14) {
        $bad[] = 'места по уровням';
    }
    [$x, $z] = wf_garden_slot_pos(0.0, 0.0, 0);
    if (abs($x - 4.8) > 0.001 || abs($z) > 0.001) {
        $bad[] = 'место 0';
    }
    if (wf_garden_seed_species('seed_oak') !== 'oak' || wf_garden_seed_species('seed_rock') !== null || wf_garden_seed_species('log') !== null) {
        $bad[] = 'семена';
    }
    if (wf_garden_grow_seconds('birch') !== 3 * 86400 || wf_garden_grow_seconds('iron_oak') !== 7 * 86400) {
        $bad[] = 'дни роста';
    }
    if (wf_garden_stage(0.0) !== 0 || wf_garden_stage(0.4) !== 1 || wf_garden_stage(0.7) !== 2 || wf_garden_stage(1.0) !== 3) {
        $bad[] = 'стадии';
    }
    if (wf_garden_size(0) !== 1 || wf_garden_size(2) !== 2) {
        $bad[] = 'размер';
    }
    if (wf_pack_item_kg('seed_oak') !== 0.1) {
        $bad[] = 'вес семени';
    }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Сад: сезонный множитель роста', function () {
    $off = 6 * 3600; // Омск
    $jan = gmmktime(12, 0, 0, 1, 15, 2027);
    $apr = gmmktime(12, 0, 0, 4, 15, 2027);
    $jul = gmmktime(12, 0, 0, 7, 15, 2027);
    $oct = gmmktime(12, 0, 0, 10, 15, 2026);
    $dec = gmmktime(20, 0, 0, 11, 30, 2026); // 1 декабря по Омску
    return ['0.3|0.8|1|0.8|0.3', wf_garden_season_k($jan) . '|' . wf_garden_season_k($apr) . '|' . wf_garden_season_k($jul) . '|' . wf_garden_season_k($oct) . '|' . wf_garden_season_k($dec)];
});
wf_st_test($results, 'Сад: рост лениво (час лета, ночь в офлайне, зима медленнее)', function () {
    $t0 = gmmktime(0, 0, 0, 7, 10, 2027);
    $s = ['id' => 5, 'growth' => 0, 'grow_seconds' => 3 * 86400, 'state' => 'growing', 'last_calc' => $t0, 'watered_until' => $t0 + 100 * 86400];
    $bad = [];
    $a = wf_garden_advance($s, $t0 + 3600);
    if (abs($a['growth'] - 3600) > 1 || $a['state'] !== 'growing') {
        $bad[] = 'час лета ' . $a['growth'];
    }
    // тот же интервал кусками даёт тот же итог (ленивый расчёт не зависит от частоты обращений)
    $b = wf_garden_advance(wf_garden_advance($s, $t0 + 1000), $t0 + 3600);
    if (abs($b['growth'] - $a['growth']) > 1) {
        $bad[] = 'кусками ' . $b['growth'];
    }
    $w0 = gmmktime(0, 0, 0, 1, 10, 2027);
    $sw = ['id' => 5, 'growth' => 0, 'grow_seconds' => 3 * 86400, 'state' => 'growing', 'last_calc' => $w0, 'watered_until' => $w0 + 100 * 86400];
    $aw = wf_garden_advance($sw, $w0 + 3600);
    if (abs($aw['growth'] - 1080) > 1) {
        $bad[] = 'зима ' . $aw['growth'];
    }
    $done = wf_garden_advance($s, $t0 + 4 * 86400);
    if ($done['state'] !== 'grown' || (float) $done['growth'] !== (float) (3 * 86400)) {
        $bad[] = 'выросло ' . $done['state'];
    }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Сад: засыхание (без полива бывает, с поливом нет, бросок детерминирован)', function () {
    $t0 = gmmktime(0, 0, 0, 7, 10, 2027);
    $dry = 0;
    $dry2 = 0;
    $wetDry = 0;
    for ($id = 1; $id <= 200; $id++) {
        $s = ['id' => $id, 'growth' => 0, 'grow_seconds' => 40 * 86400, 'state' => 'growing', 'last_calc' => $t0, 'watered_until' => 0];
        $a = wf_garden_advance($s, $t0 + 12 * 86400);
        $b = wf_garden_advance($s, $t0 + 12 * 86400);
        $dry += $a['state'] === 'dry' ? 1 : 0;
        $dry2 += $b['state'] === 'dry' ? 1 : 0;
        $s['watered_until'] = $t0 + 100 * 86400;
        $wetDry += wf_garden_advance($s, $t0 + 12 * 86400)['state'] === 'dry' ? 1 : 0;
    }
    // шанс 5% в сутки за 12 суток: около 46%, допускаем широкую вилку
    $ok = $dry > 40 && $dry < 160 && $dry === $dry2 && $wetDry === 0;
    return ['ok', $ok ? 'ok' : 'засохло ' . $dry . ' из 200, повтор ' . $dry2 . ', политых ' . $wetDry];
});

// 4е. Имена из двух слов (ДЛ-15): PHP даёт те же имена, что JS (золотые векторы tests/vectors/names.json), и согласует род
require_once dirname(__DIR__) . '/api/forest/lib/names.php';
wf_st_test($results, 'Имена: векторы JS и PHP совпадают', function () {
    $raw = @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/names.json');
    $v = json_decode((string) $raw, true);
    if (!is_array($v) || empty($v['names'])) {
        throw new RuntimeException('нет файла векторов имён: games/wild-forest/tests/vectors/names.json');
    }
    $bad = [];
    foreach ($v['names'] as $row) {
        $got = wf_names_make((string) $row[0], (int) $row[1], (int) $row[2]);
        if ($got !== $row[3]) {
            $bad[] = $row[0] . ' ' . $row[1] . '/' . $row[2] . ': ' . $row[3] . ' ≠ ' . $got;
            if (count($bad) >= 5) {
                break;
            }
        }
    }
    return ['0 расхождений из ' . count($v['names']), count($bad) . ' расхождений из ' . count($v['names']) . ($bad ? ' (' . implode('; ', $bad) . ')' : '')];
});
wf_st_test($results, 'Имена: уникальность и номер при исчерпании попыток', function () {
    $first = wf_names_make('village', 5, 0);
    $second = wf_names_unique('village', 5, static function ($n) use ($first) { return $n === $first; });
    $last = wf_names_unique('village', 5, static function () { return true; }, 3);
    return [$first . '|' . wf_names_make('village', 5, 1) . '|4', $first . '|' . $second . '|' . substr($last, -1)];
});
wf_st_test($results, 'Имена: длина не больше 24 и нет пробелов по краям у 3000 имён', function () {
    for ($s = 1; $s <= 1000; $s++) {
        foreach (['village', 'character', 'mentor'] as $kind) {
            $n = wf_names_make($kind, $s * 3, $s % 4);
            if (mb_strlen($n) > 24 || trim($n) !== $n || substr_count($n, ' ') !== 1) {
                return ['ok', 'плохое имя: ' . $n];
            }
        }
    }
    return ['ok', 'ok'];
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

// 4к. Realtime (РТ-02): подписанные токены и квитанции, золотые векторы Node ↔ PHP
require_once dirname(__DIR__) . '/api/forest/lib/rt.php';
function wf_st_rt_vectors()
{
    static $v = null;
    if ($v === null) {
        $v = json_decode((string) @file_get_contents(dirname(__DIR__) . '/games/wild-forest/tests/vectors/rt.json'), true);
        if (!is_array($v)) {
            throw new RuntimeException('нет файла векторов: games/wild-forest/tests/vectors/rt.json');
        }
    }
    return $v;
}
function wf_st_rt_ring(array $keys): array
{
    $ring = [];
    foreach ($keys as $kid => $b64) {
        $ring[$kid] = (string) wf_rt_b64u_dec($b64);
    }
    return $ring;
}
wf_st_test($results, 'Realtime: sodium и настройки связки (информационно)', function () {
    $cfg = wf_rt_settings();
    $text = 'sodium=' . (wf_rt_alg_supported('ed25519') ? 'есть' : 'нет (нужен hmac)') . '; связка: ' . ($cfg['enabled'] ? 'включена, alg=' . $cfg['alg'] . ', kid=' . $cfg['sign_kid'] . ', ключей проверки=' . count($cfg['verify']) : 'выключена (' . $cfg['problem'] . ')');
    return [$text, $text];
});
wf_st_test($results, 'Realtime: лимиты в config.php совпадают с протоколом Node', function () {
    $bad = [];
    foreach (wf_st_rt_vectors()['limits'] as $name => $value) {
        if ((int) forest_config('rt.' . $name) !== (int) $value) {
            $bad[] = $name;
        }
    }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Realtime: проверка токенов по золотым векторам (все случаи)', function () {
    $v = wf_st_rt_vectors();
    $bad = [];
    $skipped = 0;
    foreach ($v['verify'] as $c) {
        if ($c['alg'] === 'ed25519' && !wf_rt_alg_supported('ed25519')) {
            $skipped++;
            continue;
        }
        $r = wf_rt_verify_token((string) $c['token'], $c['alg'], wf_st_rt_ring($c['keys']), (int) $c['now'], $c['typ']);
        $okExpected = !empty($c['expect']['ok']);
        if ($r['ok'] !== $okExpected || (!$okExpected && $r['error'] !== $c['expect']['error']) || ($okExpected && $r['payload']['pid'] !== $c['expect']['pid'])) {
            $bad[] = $c['name'];
        }
    }
    return ['0 расхождений', count($bad) ? implode('; ', array_slice($bad, 0, 5)) : '0 расхождений' . ($skipped ? ' (пропущено без sodium: ' . $skipped . ')' : '')];
});
wf_st_test($results, 'Realtime: подпись PHP совпадает с Node побайтно, JSON без экранирования', function () {
    $v = wf_st_rt_vectors();
    $bad = [];
    foreach ($v['sign'] as $c) {
        if ($c['alg'] === 'ed25519' && !wf_rt_alg_supported('ed25519')) {
            continue;
        }
        $part = wf_rt_b64u_enc((string) $c['payload_json']);
        $sig = wf_rt_sign_raw($c['alg'], (string) wf_rt_b64u_dec($c['key']), $part);
        if ($sig === null || $part . '.' . wf_rt_b64u_enc($sig) !== $c['token']) {
            $bad[] = $c['alg'];
        }
    }
    foreach ($v['encode'] as $e) {
        if (json_encode($e['array'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== $e['json']) {
            $bad[] = 'json';
        }
    }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Realtime: токен PHP проходит свою же проверку, подделка и чужой ключ нет', function () {
    $now = 1760000000;
    $bad = [];
    foreach (['hmac' => str_repeat("\x07", 32), 'ed25519' => null] as $alg => $key) {
        if (!wf_rt_alg_supported($alg)) {
            continue;
        }
        if ($alg === 'ed25519') {
            $kp = sodium_crypto_sign_keypair();
            $sign = sodium_crypto_sign_secretkey($kp);
            $verify = sodium_crypto_sign_publickey($kp);
        } else {
            $sign = $verify = $key;
        }
        $t = wf_rt_make_token($alg, 'p1', $sign, 'tok', 5, wf_rt_login_extra('Тихая Заводь', 'Мшистый Бор', 3, 4.6) + ['pid' => 99], $now, (int) forest_config('rt.token_ttl_s'));
        $ok = $t !== null && wf_rt_verify_token($t, $alg, ['p1' => $verify], $now, 'tok');
        if (!$ok || !$ok['ok'] || $ok['payload']['pid'] !== 5 || $ok['payload']['nm'] !== 'Тихая Заводь') {
            $bad[] = $alg . ': круг';
        }
        if ($t !== null) {
            [$a, $b] = explode('.', $t);
            $forged = wf_rt_b64u_enc(str_replace('"pid":5', '"pid":6', (string) wf_rt_b64u_dec($a))) . '.' . $b;
            if (wf_rt_verify_token($forged, $alg, ['p1' => $verify], $now, 'tok')['ok']) {
                $bad[] = $alg . ': подделка принята';
            }
            if (wf_rt_verify_token($t, $alg, ['p1' => $alg === 'hmac' ? str_repeat("\x08", 32) : sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())], $now, 'tok')['ok']) {
                $bad[] = $alg . ': чужой ключ принят';
            }
            if (wf_rt_verify_token($t, $alg, ['p1' => $verify], $now + 700, 'tok')['error'] !== 'expired') {
                $bad[] = $alg . ': срок';
            }
        }
    }
    return ['0 расхождений', count($bad) ? implode('; ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Realtime: проверка полей квитанции (чужой pid, rid, дальность, обмен)', function () {
    $good = ['pid' => 5, 'rid' => 'AbCdEfGh12345678', 'k' => 'cp', 'd' => ['x' => 10.5, 'z' => -3]];
    $bad = [];
    if (wf_rt_receipt_check($good, 5) !== null) { $bad[] = 'честная cp'; }
    if (wf_rt_receipt_check($good, 6) !== 'bad_token') { $bad[] = 'чужой pid'; }
    if (wf_rt_receipt_check(['rid' => 'коротко'] + $good, 5) !== 'bad_token') { $bad[] = 'rid'; }
    if (wf_rt_receipt_check(['d' => ['x' => 1e9, 'z' => 0]] + $good, 5) !== 'too_far') { $bad[] = 'дальность'; }
    if (wf_rt_receipt_check(['d' => ['x' => 'a', 'z' => 0]] + $good, 5) !== 'too_far') { $bad[] = 'не число'; }
    if (wf_rt_receipt_check(['k' => 'trade', 'd' => ['tid' => 't1', 'st' => 'done']] + $good, 5) !== null) { $bad[] = 'trade'; }
    if (wf_rt_receipt_check(['k' => 'trade', 'd' => ['st' => 'done']] + $good, 5) !== 'bad_token') { $bad[] = 'trade без tid'; }
    if (wf_rt_receipt_check(['k' => 'boom'] + $good, 5) !== 'bad_token') { $bad[] = 'вид'; }
    if (wf_rt_receipt_check(['d' => ['x' => 1, 'z' => 1, 'pad' => str_repeat('a', 2000)]] + $good, 5) !== 'bad_token') { $bad[] = 'размер'; }
    return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
});
wf_st_test($results, 'Realtime: квитанция идемпотентна по rid (регистр важен), старые чистятся (тестовые строки, потом уборка)', function () use ($conn) {
    $pid = 2147000002;
    $clean = function () use ($conn, $pid) {
        $conn->query('DELETE FROM `forest_rt_receipts` WHERE `pid` = ' . $pid);
    };
    $clean();
    try {
        $now = time();
        $p = ['pid' => $pid, 'rid' => 'AbCdEfGh12345678', 'k' => 'cp', 'iat' => $now, 'd' => ['x' => 1, 'z' => 2]];
        $bad = [];
        if (wf_rt_receipt_store($conn, $p, $now) !== 'new') { $bad[] = 'первый раз'; }
        if (wf_rt_receipt_store($conn, $p, $now) !== 'dup') { $bad[] = 'повтор'; }
        if (wf_rt_receipt_store($conn, ['rid' => 'abcdefgh12345678'] + $p, $now) !== 'new') { $bad[] = 'регистр rid'; }
        $old = ['rid' => 'OldOldOld12345678', 'iat' => $now - (int) forest_config('rt.keep_receipts_s') - 10] + $p;
        wf_rt_receipt_store($conn, $old, $now);
        wf_rt_cleanup($conn, $now);
        $left = (int) $conn->query('SELECT COUNT(*) FROM `forest_rt_receipts` WHERE `pid` = ' . $pid)->fetch_row()[0];
        if ($left !== 2) { $bad[] = 'после чистки строк ' . $left . ' вместо 2'; }
        return ['0 расхождений', count($bad) ? implode(', ', $bad) : '0 расхождений'];
    } finally {
        $clean();
    }
});
wf_st_test($results, 'Realtime: токен для ручной проверки в Node (скопируй, команда в realtime/README.md)', function () {
    $cfg = wf_rt_settings();
    if (!$cfg['enabled']) {
        $text = 'связка выключена: ' . $cfg['problem'];
        return [$text, $text];
    }
    $token = wf_rt_make_token($cfg['alg'], $cfg['sign_kid'], $cfg['sign_key'], 'selftest', 0, [], time(), (int) forest_config('rt.token_ttl_s'));
    $text = (string) $token;
    return [$text, $text];
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
