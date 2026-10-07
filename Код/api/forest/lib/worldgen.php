<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && @realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404); // библиотека, не эндпоинт
    exit;
}
/**
 * Дикий Лес: генерация мира на сервере (ДЛ-05).
 * Зеркало games/wild-forest/src/shared/rng.js и worldgen.js: тот же алгоритм, те же данные
 * (games/wild-forest/src/shared/species.json и biomes.json). Любое изменение делается в обеих реализациях
 * сразу и проверяется тест-векторами (страница tools/forest-selftest.php).
 *
 * Правила (раздел 6.2 плана): только целые числа, всё в беззнаковых 32 битах, деление только intdiv,
 * никаких float внутри генерации. Единицы ядра: целые дециметры (1 м = 10 дм).
 */

const WF_WG_BIOME_WATER = 0;
const WF_WG_BIOME_ROCKS = 1;
const WF_WG_BIOME_SWAMP = 2;
const WF_WG_KIND_NONE = 0;
const WF_WG_KIND_TREE = 1;
const WF_WG_KIND_BOULDER = 2;
const WF_WG_KIND_ORE = 3;
const WF_WG_CELL_DM = 40;
const WF_WG_CELLS_PER_CHUNK = 8;

// ── Хеш и шум ───────────────────────────────────────────────────────────

/** Беззнаковое 32-битное умножение без выхода за 64 бита (прямое a * b могло бы стать float). */
function wf_wg_mul32(int $a, int $b): int
{
    return (($a & 0xFFFF) * $b + ((($a >> 16) * $b) & 0xFFFF) * 65536) & 0xFFFFFFFF;
}

/** Финализатор murmur3 (fmix32). Аргумент: 0..2^32-1. */
function wf_wg_mix(int $h): int
{
    $h ^= $h >> 16;
    $h = wf_wg_mul32($h, 0x85EBCA6B);
    $h ^= $h >> 13;
    $h = wf_wg_mul32($h, 0xC2B2AE35);
    $h ^= $h >> 16;
    return $h;
}

/** Хеш (seed, x, z, salt) -> 0..2^32-1. x и z могут быть отрицательными. */
function wf_wg_hash(int $seed, int $x, int $z, int $salt): int
{
    $h = $seed & 0xFFFFFFFF;
    $h = wf_wg_mix($h ^ wf_wg_mul32($x & 0xFFFFFFFF, 0x27D4EB2D));
    $h = wf_wg_mix($h ^ wf_wg_mul32($z & 0xFFFFFFFF, 0x165667B1));
    $h = wf_wg_mix($h ^ wf_wg_mul32($salt & 0xFFFFFFFF, 0x9E3779B1));
    return $h;
}

/** Деление с округлением вниз для знакового a и положительного b. */
function wf_wg_fdiv(int $a, int $b): int
{
    $q = intdiv($a, $b);
    if (($a % $b) !== 0 && $a < 0) {
        $q--;
    }
    return $q;
}

/** Билинейный шум значений в Q16 (65536 = 1.0): 0..65535. Все промежуточные значения неотрицательны. */
function wf_wg_noise2(int $seed, int $X, int $Z, int $P, int $salt): int
{
    $gx = wf_wg_fdiv($X, $P);
    $gz = wf_wg_fdiv($Z, $P);
    $u = intdiv(($X - $gx * $P) * 65536, $P);
    $v = intdiv(($Z - $gz * $P) * 65536, $P);
    $tu = intdiv($u * $u, 65536);
    $fu = intdiv($tu * (196608 - 2 * $u), 65536);
    $tv = intdiv($v * $v, 65536);
    $fv = intdiv($tv * (196608 - 2 * $v), 65536);
    $v00 = wf_wg_hash($seed, $gx, $gz, $salt) & 0xFFFF;
    $v10 = wf_wg_hash($seed, $gx + 1, $gz, $salt) & 0xFFFF;
    $v01 = wf_wg_hash($seed, $gx, $gz + 1, $salt) & 0xFFFF;
    $v11 = wf_wg_hash($seed, $gx + 1, $gz + 1, $salt) & 0xFFFF;
    $top = intdiv($v00 * (65536 - $fu) + $v10 * $fu, 65536);
    $bot = intdiv($v01 * (65536 - $fu) + $v11 * $fu, 65536);
    return intdiv($top * (65536 - $fv) + $bot * $fv, 65536);
}

/** Многооктавный шум: периоды P, P/2, P/4, P/8, веса 8, 4, 2, 1; результат 0..65535. */
function wf_wg_fbm(int $seed, int $X, int $Z, int $P, int $salt): int
{
    static $weights = [8, 4, 2, 1];
    $sum = 0;
    for ($i = 0; $i < 4; $i++) {
        $sum += $weights[$i] * wf_wg_noise2($seed, $X, $Z, intdiv($P, 1 << $i), $salt * 8 + $i);
    }
    return intdiv($sum, 15);
}

/** Целая часть квадратного корня с поправкой на округление. */
function wf_wg_isqrt(int $n): int
{
    $r = (int) floor(sqrt((float) $n));
    while ($r * $r > $n) {
        $r--;
    }
    while (($r + 1) * ($r + 1) <= $n) {
        $r++;
    }
    return $r;
}

// ── Данные и генератор ──────────────────────────────────────────────────

/** Читает общие JSON (породы и биомы). Ошибка чтения бросает исключение: без них мир не построить. */
function wf_wg_load_data(): array
{
    static $data = null;
    if ($data === null) {
        $dir = dirname(__DIR__, 3) . '/games/wild-forest/src/shared/';
        $species = json_decode((string) @file_get_contents($dir . 'species.json'), true);
        $biomes = json_decode((string) @file_get_contents($dir . 'biomes.json'), true);
        if (!is_array($species) || !is_array($biomes)) {
            throw new RuntimeException('Не удалось прочитать species.json или biomes.json');
        }
        $data = ['species' => $species, 'biomes' => $biomes];
    }
    return $data;
}

/** Создаёт описание мира по сиду (аналог createWorldGen в JS). Данные можно передать для тестов. */
function wf_wg_make(int $seed, ?array $data = null): array
{
    $data = $data ?: wf_wg_load_data();
    $index = [];
    foreach ($data['species']['species'] as $i => $s) {
        $index[$s['id']] = $i;
    }
    $byCode = [];
    foreach ($data['biomes']['biomes'] as $b) {
        $list = [];
        $total = 0;
        foreach ($b['species'] as $pair) {
            $list[] = [$index[$pair[0]], (int) $pair[1]];
            $total += (int) $pair[1];
        }
        $byCode[(int) $b['code']] = [
            'density' => (int) $b['density_permille'],
            'list' => $list,
            'total' => $total,
            'rare' => $b['rare'] ? $index[$b['rare']] : -1,
        ];
    }
    return ['seed' => $seed & 0xFFFFFFFF, 'p' => $data['biomes']['params'], 'by_code' => $byCode, 'species_ids' => array_column($data['species']['species'], 'id')];
}

/** Мир с сидом из config.php (кеш на время запроса). */
function wf_wg_world(): array
{
    static $w = null;
    if ($w === null) {
        $w = wf_wg_make((int) forest_config('world.seed'));
    }
    return $w;
}

// ── Поля ────────────────────────────────────────────────────────────────

function wf_wg_field_e(array $w, int $X, int $Z): int
{
    return wf_wg_fbm($w['seed'], $X, $Z, $w['p']['periods_dm']['E'], $w['p']['salts']['E']);
}

function wf_wg_field_m(array $w, int $X, int $Z): int
{
    return wf_wg_fbm($w['seed'], $X, $Z, $w['p']['periods_dm']['M'], $w['p']['salts']['M']);
}

function wf_wg_field_c(array $w, int $X, int $Z): int
{
    return wf_wg_fbm($w['seed'], $X, $Z, $w['p']['periods_dm']['C'], $w['p']['salts']['C']);
}

function wf_wg_field_d(array $w, int $X, int $Z): int
{
    return wf_wg_fbm($w['seed'], $X, $Z, $w['p']['periods_dm']['D'], $w['p']['salts']['D']);
}

/** Русло: расстояние до «складки» 32768, чем меньше, тем ближе к реке. */
function wf_wg_field_r(array $w, int $X, int $Z): int
{
    return abs(wf_wg_fbm($w['seed'], $X, $Z, $w['p']['periods_dm']['R'], $w['p']['salts']['R']) - 32768);
}

/** Эффективное расстояние до русла: на высоких местах растёт, река плавно сходит на нет. */
function wf_wg_river_dist(array $w, int $E, int $R): int
{
    return $R + max(0, $E - $w['p']['water']['river_fade_e']);
}

/** Смесь a и b с весом num/den (значения могут быть отрицательными, сдвиг 2000 дм). */
function wf_wg_lerp_n(int $a, int $b, int $num, int $den): int
{
    return intdiv(($a + 2000) * ($den - $num) + ($b + 2000) * $num, $den) - 2000;
}

/** Высота в дм по готовым полям. */
function wf_wg_height_from_fields(array $w, int $E, int $R, int $D): int
{
    $P = $w['p'];
    $W = $P['water'];
    $H = intdiv($E * $P['height']['e_scale'], 65535) + $P['height']['base'] + intdiv($D * $P['height']['d_scale'], 65535);
    $lake = $E < $W['lake_e'];
    $Re = wf_wg_river_dist($w, $E, $R);
    $river = $Re < $W['river_r'];
    if ($lake || $river) {
        $bed = 1000000000;
        if ($lake) {
            $bed = min($bed, $W['level'] - $W['bed_gap'] - intdiv(($W['lake_e'] - $E) * $W['lake_depth'], $W['lake_e']));
        }
        if ($river) {
            $bed = min($bed, $W['level'] - $W['bed_gap'] - intdiv(($W['river_r'] - $Re) * $W['river_depth'], $W['river_r']));
        }
        return $bed;
    }
    if ($E < $W['lake_ramp_e']) {
        $H = wf_wg_lerp_n($H, $W['level'] + $W['bank_gap'], $W['lake_ramp_e'] - $E, $W['lake_ramp_e'] - $W['lake_e']);
    }
    if ($Re < $W['river_ramp_r']) {
        $H = wf_wg_lerp_n($H, $W['level'] + $W['bank_gap'], $W['river_ramp_r'] - $Re, $W['river_ramp_r'] - $W['river_r']);
    }
    return $H;
}

/** Код биома по готовым полям: 0 вода, 1 скалы, 2 болото, 3 хвойный, 4 лиственный, 5 берёзовая роща. */
function wf_wg_biome_from_fields(array $w, int $E, int $M, int $C, int $R): int
{
    $P = $w['p'];
    $W = $P['water'];
    $B = $P['biome'];
    if ($E < $W['lake_e'] || wf_wg_river_dist($w, $E, $R) < $W['river_r']) {
        return WF_WG_BIOME_WATER;
    }
    if ($E > $B['rocks_e']) {
        return WF_WG_BIOME_ROCKS;
    }
    if ($M > $B['swamp_m'] && $E < $B['swamp_e_max']) {
        return WF_WG_BIOME_SWAMP;
    }
    if ($C >= $B['conifer_c']) {
        return 3;
    }
    if ($C < $B['grove_c']) {
        return 5;
    }
    return 4;
}

function wf_wg_height_dm(array $w, int $X, int $Z): int
{
    return wf_wg_height_from_fields($w, wf_wg_field_e($w, $X, $Z), wf_wg_field_r($w, $X, $Z), wf_wg_field_d($w, $X, $Z));
}

function wf_wg_biome_dm(array $w, int $X, int $Z): int
{
    return wf_wg_biome_from_fields($w, wf_wg_field_e($w, $X, $Z), wf_wg_field_m($w, $X, $Z), wf_wg_field_c($w, $X, $Z), wf_wg_field_r($w, $X, $Z));
}

// ── Город, расстояния, зоны ─────────────────────────────────────────────

function wf_wg_inside_world(array $w, int $X, int $Z): bool
{
    $r = $w['p']['world']['radius_dm'];
    return abs($X) <= $r && abs($Z) <= $r;
}

/** Город в точке (0, 0): круг радиусом 120 м без деревьев. */
function wf_wg_is_city(array $w, int $X, int $Z): bool
{
    $r = $w['p']['world']['city_radius_dm'];
    return $X * $X + $Z * $Z < $r * $r;
}

function wf_wg_dist_dm(int $X, int $Z): int
{
    return wf_wg_isqrt($X * $X + $Z * $Z);
}

function wf_wg_tier_dm(array $w, int $X, int $Z): int
{
    return min($w['p']['world']['tier_max'], intdiv(wf_wg_dist_dm($X, $Z), $w['p']['world']['tier_step_dm']));
}

/** Зона: 0 опушка, 1 чаща, 2 глушь. */
function wf_wg_zone_dm(array $w, int $X, int $Z): int
{
    $d = wf_wg_dist_dm($X, $Z);
    if ($d < $w['p']['world']['zone_edge_dm']) {
        return 0;
    }
    return $d < $w['p']['world']['zone_deep_dm'] ? 1 : 2;
}

/** Шанс редкой породы (на 10 000) для ступени удалённости. */
function wf_wg_rare_chance(array $w, int $tier): int
{
    $c = $w['p']['cell'];
    return min($c['rare_cap_per10k'], intdiv($c['rare_base_per10k'] * (100 + $c['rare_tier_pct'] * $tier), 100));
}

// ── Клетка ──────────────────────────────────────────────────────────────

/**
 * Содержимое клетки (cx, cz, ci), ci = 0..63.
 * kind: 0 пусто, 1 дерево, 2 булыжник, 3 руда. ox, oz: смещение внутри клетки (дм).
 * Возвращает: kind, ox, oz, species (индекс или -1), size (0..2 или -1), rare, treasure, biome, X, Z.
 */
function wf_wg_cell(array $w, int $cx, int $cz, int $ci): array
{
    $P = $w['p'];
    $S = $P['salts'];
    $CL = $P['cell'];
    $seed = $w['seed'];
    $gx = $cx * WF_WG_CELLS_PER_CHUNK + ($ci % WF_WG_CELLS_PER_CHUNK);
    $gz = $cz * WF_WG_CELLS_PER_CHUNK + intdiv($ci, WF_WG_CELLS_PER_CHUNK);
    $X = $gx * WF_WG_CELL_DM + intdiv(WF_WG_CELL_DM, 2);
    $Z = $gz * WF_WG_CELL_DM + intdiv(WF_WG_CELL_DM, 2);
    $cell = ['kind' => WF_WG_KIND_NONE, 'ox' => 0, 'oz' => 0, 'species' => -1, 'size' => -1, 'rare' => 0, 'treasure' => 0, 'biome' => WF_WG_BIOME_WATER, 'X' => $X, 'Z' => $Z];
    if (!wf_wg_inside_world($w, $X, $Z)) {
        return $cell;
    }
    $E = wf_wg_field_e($w, $X, $Z);
    $biome = wf_wg_biome_from_fields($w, $E, wf_wg_field_m($w, $X, $Z), wf_wg_field_c($w, $X, $Z), wf_wg_field_r($w, $X, $Z));
    $cell['biome'] = $biome;
    if ($biome === WF_WG_BIOME_WATER || wf_wg_is_city($w, $X, $Z)) {
        return $cell;
    }
    $B = $w['by_code'][$biome];
    $kind = WF_WG_KIND_NONE;
    if ($E > $CL['ore_e'] && wf_wg_hash($seed, $gx, $gz, $S['ore']) % 1000 < $CL['ore_permille']) {
        $kind = WF_WG_KIND_ORE;
    } elseif (wf_wg_hash($seed, $gx, $gz, $S['tree']) % 1000 < $B['density']) {
        $kind = WF_WG_KIND_TREE;
    } else {
        $boulder = $biome === WF_WG_BIOME_ROCKS ? $CL['boulder_permille_rocks'] : $CL['boulder_permille_other'];
        if (wf_wg_hash($seed, $gx, $gz, $S['boulder']) % 1000 < $boulder) {
            $kind = WF_WG_KIND_BOULDER;
        }
    }
    if ($kind === WF_WG_KIND_NONE) {
        return $cell;
    }
    $span = $CL['offset_max_dm'] * 2 + 1;
    $cell['kind'] = $kind;
    $cell['ox'] = (wf_wg_hash($seed, $gx, $gz, $S['ox']) % $span) - $CL['offset_max_dm'];
    $cell['oz'] = (wf_wg_hash($seed, $gx, $gz, $S['oz']) % $span) - $CL['offset_max_dm'];
    if ($kind !== WF_WG_KIND_TREE) {
        return $cell;
    }
    // порода по весам биома
    $pick = wf_wg_hash($seed, $gx, $gz, $S['species']) % $B['total'];
    $last = $B['list'][count($B['list']) - 1];
    $sp = $last[0];
    foreach ($B['list'] as $pair) {
        if ($pick < $pair[1]) {
            $sp = $pair[0];
            break;
        }
        $pick -= $pair[1];
    }
    // редкая порода: шанс растёт с удалённостью от города
    if ($B['rare'] >= 0 && wf_wg_hash($seed, $gx, $gz, $S['rare']) % 10000 < wf_wg_rare_chance($w, wf_wg_tier_dm($w, $X, $Z))) {
        $sp = $B['rare'];
        $cell['rare'] = 1;
    }
    $cell['species'] = $sp;
    $sr = wf_wg_hash($seed, $gx, $gz, $S['size']) % 100;
    $cell['size'] = $sr < $CL['size_cum'][0] ? 0 : ($sr < $CL['size_cum'][1] ? 1 : 2);
    $cell['treasure'] = wf_wg_hash($seed, $gx, $gz, $S['treasure']) % 10000 < $CL['treasure_per10k'] ? 1 : 0;
    return $cell;
}

// ── Идентификаторы деревьев ─────────────────────────────────────────────

/** "cx:cz:ci" -> [cx, cz, ci] или null. Точка с \z, а не $, чтобы перевод строки в конце не проходил (как в JS). */
function wf_wg_parse_tree_id($id): ?array
{
    if (!is_string($id) || !preg_match('/^(-?\d{1,9}):(-?\d{1,9}):(\d{1,2})\z/', $id, $m)) {
        return null;
    }
    $ci = (int) $m[3];
    if ($ci > 63) {
        return null;
    }
    return [(int) $m[1], (int) $m[2], $ci];
}

function wf_wg_tree_id(int $cx, int $cz, int $ci): string
{
    return $cx . ':' . $cz . ':' . $ci;
}

/** Клетка по id дерева "cx:cz:ci" (точечная проверка на сервере, быстро). */
function wf_wg_cell_by_id(array $w, $id): ?array
{
    $p = wf_wg_parse_tree_id($id);
    return $p ? wf_wg_cell($w, $p[0], $p[1], $p[2]) : null;
}
