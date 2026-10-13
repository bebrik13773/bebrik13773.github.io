<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: выбор места появления игрока (ДЛ-10). Зеркало Код/games/wild-forest/src/shared/spawn.js.
 * Чистые функции без БД: занятость места передаётся колбэком, поэтому проверяются таблицей кейсов на forest-selftest.
 * Параметры берутся из config.php (world.*, spawn.*).
 */
require_once __DIR__ . '/worldgen.php';

/** Кандидат номер $k: ['x' => , 'z' => , 'r' => ] в целых метрах. $phase: 0 обычный диапазон, 1 расширенный. */
function wf_spawn_candidate(int $playerId, int $k, int $phase = 0): array
{
    $c = forest_config('spawn');
    $s = $c['salts'];
    $ang = wf_wg_hash($playerId, $k, $phase, (int) $s['angle']) % 3600;
    $pick = wf_wg_hash($playerId, $k, $phase, (int) $s['pick']) % 100;
    $rr = wf_wg_hash($playerId, $k, $phase, (int) $s['radius']);
    if ($phase === 0) {
        $r = $pick < (int) $c['core_share']
            ? (int) $c['core_min_m'] + ($rr % ((int) $c['core_max_m'] - (int) $c['core_min_m'] + 1))
            : (int) forest_config('world.spawn_min_m') + ($rr % ((int) forest_config('world.spawn_max_m') - (int) forest_config('world.spawn_min_m') + 1));
    } else {
        $r = (int) forest_config('world.spawn_min_m') + ($rr % ((int) $c['wide_max_m'] - (int) forest_config('world.spawn_min_m') + 1));
    }
    $a = ($ang * M_PI) / 1800;

    return ['x' => (int) floor(cos($a) * $r + 0.5), 'z' => (int) floor(sin($a) * $r + 0.5), 'r' => $r];
}

/** Лес (хвойный, лиственный, берёзовый) вокруг точки, ровно, внутри мира. */
function wf_spawn_suitable(array $w, int $x, int $z, int $flatM): bool
{
    $ring = (int) forest_config('spawn.ring_m');
    if (!wf_wg_inside_world($w, $x * 10, $z * 10)) {
        return false;
    }
    $forest = static function (int $b): bool {
        return $b >= 3 && $b <= 5;
    };
    if (!$forest(wf_wg_biome_dm($w, $x * 10, $z * 10))) {
        return false;
    }
    $lo = wf_wg_height_dm($w, $x * 10, $z * 10);
    $hi = $lo;
    for ($i = 0; $i < 8; $i++) {
        $px = (int) floor($x + cos($i * M_PI / 4) * $ring);
        $pz = (int) floor($z + sin($i * M_PI / 4) * $ring);
        if (!$forest(wf_wg_biome_dm($w, $px * 10, $pz * 10))) {
            return false;
        }
        $h = wf_wg_height_dm($w, $px * 10, $pz * 10);
        $lo = min($lo, $h);
        $hi = max($hi, $h);
    }

    return $hi - $lo <= $flatM * 10;
}

/**
 * Находит место. $isFree(x, z, gap) — true, если рядом нет чужих спавнов (на сервере запрос к БД).
 * $skip(x, z) — необязательно: true пропускает кандидата (например, клетка уже занята в этой транзакции).
 * Возвращает ['x', 'z', 'attempt'] или null.
 */
function wf_spawn_find(array $w, int $playerId, callable $isFree, ?callable $skip = null): ?array
{
    $c = forest_config('spawn');
    $gap = (int) forest_config('world.spawn_min_gap_m');
    $phases = [
        [0, (int) forest_config('world.spawn_attempts'), (int) $c['flat_m']],
        [1, (int) $c['attempts_wide'], (int) $c['flat_relaxed_m']],
        [1, (int) $c['attempts_fallback'], (int) $c['flat_relaxed_m'] * 2],
    ];
    $n = 0;
    $offset = 0;
    foreach ($phases as [$phase, $count, $flat]) {
        for ($k = 0; $k < $count; $k++) {
            $n++;
            $cand = wf_spawn_candidate($playerId, $k + $offset, $phase);
            if ($skip && $skip($cand['x'], $cand['z'])) {
                continue;
            }
            if (!wf_spawn_suitable($w, $cand['x'], $cand['z'], $flat)) {
                continue;
            }
            if (!$isFree($cand['x'], $cand['z'], $gap)) {
                continue;
            }

            return ['x' => $cand['x'], 'z' => $cand['z'], 'attempt' => $n];
        }
        $offset += $count;
    }

    return null;
}

/** Пруд и плотина рядом с поляной (центр, радиус, угол, точка плотины). */
function wf_spawn_pond(int $playerId, int $x, int $z): array
{
    $c = forest_config('spawn');
    $deg = wf_wg_hash($playerId, 0, 0, (int) $c['salts']['pond']) % 360;
    $a = ($deg * M_PI) / 180;
    $dist = (int) $c['pond_dist_m'];
    $r = (int) $c['pond_radius_m'];
    $px = (int) floor($x + cos($a) * $dist + 0.5);
    $pz = (int) floor($z + sin($a) * $dist + 0.5);

    return [
        'x' => $px, 'z' => $pz, 'r' => $r, 'deg' => $deg,
        'damX' => (int) floor($px + cos($a) * $r + 0.5),
        'damZ' => (int) floor($pz + sin($a) * $r + 0.5),
    ];
}

/** Ключ клетки спавна (сетка со стороной world.spawn_min_gap_m) для уникальности в forest_chunk_changes. */
function wf_spawn_cell(int $x, int $z): string
{
    $g = (int) forest_config('world.spawn_min_gap_m');

    return wf_wg_fdiv($x, $g) . ':' . wf_wg_fdiv($z, $g);
}
