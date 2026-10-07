// Целочисленный хеш и шум значений (ДЛ-05, раздел 6.2 плана).
// Главное условие: результат ОДИНАКОВ в JS и в PHP (api/forest/lib/worldgen.php).
// Правила: только целые, всё в беззнаковых 32 битах, никаких float внутри генерации,
// деление только целочисленное (Math.floor(a / b) для неотрицательных и floorDiv для знаковых).

/** Беззнаковое 32-битное умножение (в PHP: разбиение на 16 бит, без выхода за 64 бита). */
export function mul32(a, b) {
    return Math.imul(a, b) >>> 0;
}

/** Финализатор murmur3 (fmix32): перемешивание 32-битного числа. */
export function mix(h) {
    h = (h ^ (h >>> 16)) >>> 0;
    h = mul32(h, 0x85EBCA6B);
    h = (h ^ (h >>> 13)) >>> 0;
    h = mul32(h, 0xC2B2AE35);
    h = (h ^ (h >>> 16)) >>> 0;
    return h;
}

/** Хеш (seed, x, z, salt) -> 0..2^32-1. x и z могут быть отрицательными целыми. */
export function hash(seed, x, z, salt) {
    let h = seed >>> 0;
    h = mix((h ^ mul32(x >>> 0, 0x27D4EB2D)) >>> 0);
    h = mix((h ^ mul32(z >>> 0, 0x165667B1)) >>> 0);
    h = mix((h ^ mul32(salt >>> 0, 0x9E3779B1)) >>> 0);
    return h;
}

/** Целочисленное деление с округлением вниз (для знаковых a, положительное b). */
export function floorDiv(a, b) {
    return Math.floor(a / b);
}

const Q = 65536;

/** Билинейный шум значений на решётке с периодом P (дм) в фиксированной точке Q16: 0..65535. */
export function noise2(seed, X, Z, P, salt) {
    const gx = floorDiv(X, P);
    const gz = floorDiv(Z, P);
    const u = Math.floor(((X - gx * P) * Q) / P);
    const v = Math.floor(((Z - gz * P) * Q) / P);
    // smoothstep в Q16: t = u*u/65536; f = t * (3*65536 - 2u) / 65536
    const tu = Math.floor((u * u) / Q);
    const fu = Math.floor((tu * (3 * Q - 2 * u)) / Q);
    const tv = Math.floor((v * v) / Q);
    const fv = Math.floor((tv * (3 * Q - 2 * v)) / Q);
    const v00 = hash(seed, gx, gz, salt) & 0xFFFF;
    const v10 = hash(seed, gx + 1, gz, salt) & 0xFFFF;
    const v01 = hash(seed, gx, gz + 1, salt) & 0xFFFF;
    const v11 = hash(seed, gx + 1, gz + 1, salt) & 0xFFFF;
    const top = Math.floor((v00 * (Q - fu) + v10 * fu) / Q);
    const bot = Math.floor((v01 * (Q - fu) + v11 * fu) / Q);
    return Math.floor((top * (Q - fv) + bot * fv) / Q);
}

const FBM_WEIGHTS = [8, 4, 2, 1];

/** Многооктавный шум: периоды P, P/2, P/4, P/8, веса 8,4,2,1, результат 0..65535. */
export function fbm(seed, X, Z, P, salt) {
    let sum = 0;
    for (let i = 0; i < 4; i++) {
        sum += FBM_WEIGHTS[i] * noise2(seed, X, Z, Math.floor(P / (1 << i)), salt * 8 + i);
    }
    return Math.floor(sum / 15);
}

/** Целая часть квадратного корня (с поправкой на ошибки округления). */
export function isqrt(n) {
    let r = Math.floor(Math.sqrt(n));
    while (r * r > n) r--;
    while ((r + 1) * (r + 1) <= n) r++;
    return r;
}
