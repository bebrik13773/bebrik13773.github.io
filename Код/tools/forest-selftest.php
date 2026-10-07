<?php
/**
 * Дикий Лес: страница самопроверки сервера (ДЛ-03).
 * PHP в контейнере разработки не запускается, поэтому серверную часть проверяем здесь, на хостинге.
 * Доступ: пароль администратора (тот же, что в админке). Новые проверки добавляются в своих пунктах плана.
 */
require_once dirname(__DIR__) . '/api/forest/lib/common.php';

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
